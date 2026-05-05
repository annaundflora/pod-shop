<?php
/**
 * Catalog producer (Action-Scheduler hook handler).
 *
 * Owns the one-shot `spreadconnect/sync_catalog` workflow:
 *
 *   1. INSERT a fresh `wp_spreadconnect_sync_history` row in
 *      `state='in_progress'` and capture the auto-generated `run_id`.
 *   2. Page through `GET /articles` (slice-10 wrapper) using a 1-based
 *      cursor with hard-cap `MAX_PAGES`.
 *   3. For every article: `as_enqueue_async_action(
 *      'spreadconnect/sync_article', ['article_id'=>$id, 'run_id'=>$runId],
 *      'spreadconnect' )` (slice-23 consumer).
 *   4. After all pages: `SyncHistoryRepo::setTotal($runId, $count)` so the
 *      slice-23-side counter-increments can detect "all done" and CAS-flip
 *      the row to `state='complete'`.
 *
 * Exception handling (architecture.md "Action Scheduler — Hook Inventory"
 * + slice-24 ACs):
 *
 *   - `SpreadconnectClientError` (4xx, e.g. invalid API key) ⇒
 *     `SyncHistoryRepo::markFailed($runId, $message)`, NO enqueues already
 *     sent are unscheduled (best-effort surface to the user via the History
 *     row), exception re-thrown ⇒ AS marks the catalog-job `failed` and
 *     does NOT retry.
 *   - `SpreadconnectTransientError` (5xx / network) ⇒ row stays in
 *     `state='in_progress'`, exception re-thrown ⇒ AS retry cycle
 *     1 m / 5 m / 15 m. Per slice-24 AC-6 we tolerate double-enqueues on
 *     resume — slice-23's reverse-lookup idempotency handles them.
 *   - Pagination cap exceeded ⇒ `markFailed(…,
 *     'pagination_max_pages_exceeded')` and a `RuntimeException` is
 *     thrown to surface the safety violation.
 *
 * Architecture references:
 *   - "Service Map" → `Catalog\SyncCatalogJob` (Application).
 *   - "Action Scheduler — Hook Inventory" → `spreadconnect/sync_catalog`
 *     (Concurrency 1, fail-fast 4xx, retry 1 m / 5 m / 15 m on 5xx).
 *   - "wp_spreadconnect_sync_history" — schema + state enum.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use RuntimeException;
use SpreadconnectPod\Api\Dto\ArticleSummary;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;

/**
 * Action-Scheduler handler for the `spreadconnect/sync_catalog` hook.
 *
 * Stateful (constructor-injected dependencies, no static collaborators) so
 * the job can be tested in isolation with Mockery mocks of every external
 * boundary. The static {@see self::handleStatic()} bridge is the
 * `add_action()` callable — it instantiates production-default
 * collaborators and delegates to {@see self::handle()}.
 *
 * `final` per slice-24 constraints (no subclassing — the static bridge is
 * the extension point if a future slice needs alternate wiring).
 */
final class SyncCatalogJob
{
	/**
	 * Page size for `getArticles()`. Slice-10 AC-2 documents the contract
	 * `?page=…&size=…`; 50 keeps each page well under the SC default rate
	 * limits while still amortising the network round-trip cost.
	 */
	public const PAGE_SIZE = 50;

	/**
	 * Hard cap on the pagination loop. 200 pages × 50 items = 10 000
	 * articles — well above any realistic POD catalog. Exceeding this cap
	 * is treated as an error condition (slice-24 AC-10).
	 */
	public const MAX_PAGES = 200;

	/**
	 * Action-Scheduler group used for every enqueued
	 * `spreadconnect/sync_article` action (architecture.md "AS Hook
	 * Inventory" + slice-24 AC-2).
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * Hook name of the per-article consumer (slice-23).
	 */
	private const HOOK_SYNC_ARTICLE = 'spreadconnect/sync_article';

	/**
	 * Default `trigger` value when none is supplied via the AS args
	 * (architecture.md Z. 240 enum: `manual|webhook|scheduled|initial`).
	 */
	private const DEFAULT_TRIGGER = 'manual';

	public function __construct(
		private readonly SpreadconnectClient $client,
		private readonly SyncHistoryRepo $historyRepo,
	) {
	}

	/**
	 * Static bridge for `add_action('spreadconnect/sync_catalog', …)`.
	 *
	 * Mirrors the slice-23 SyncArticleJob pattern: AS calls this method
	 * with the args-array as the first parameter; the bridge instantiates
	 * the production-default collaborator chain and delegates to
	 * {@see self::handle()}.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['trigger'=>string]`.
	 */
	public static function handleStatic( array $args ): void
	{
		$job = new self(
			new SpreadconnectClient(),
			new SyncHistoryRepo()
		);

		$job->handle( $args );
	}

	/**
	 * Run the catalog-sync producer.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; optional key
	 *                                   `trigger` (string), one of
	 *                                   `manual|webhook|scheduled|initial`.
	 */
	public function handle( array $args ): void
	{
		$trigger = $this->resolveTrigger( $args );

		// AC-1: every catalog-run starts with exactly one INSERT into
		// wp_spreadconnect_sync_history (state='in_progress').
		$runId = $this->historyRepo->startRun( $trigger );

		try {
			$totalEnqueued = $this->paginateAndEnqueue( $runId );
		} catch ( SpreadconnectClientError $e ) {
			// AC-5: 4xx ⇒ mark row failed, no retry, re-throw so AS marks
			// the catalog-job as failed too.
			$this->historyRepo->markFailed( $runId, $e->getMessage() );
			throw $e;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-6: 5xx ⇒ keep row state, re-throw so AS retries. Pagination
			// will re-start from page 1 on retry; double-enqueues are
			// tolerated by slice-23 (reverse-lookup idempotency).
			throw $e;
		}

		// AC-3: persist the total so the per-article workers can detect
		// the "all done" condition and CAS-flip the row to complete.
		$this->historyRepo->setTotal( $runId, $totalEnqueued );

		// AC-4: empty catalog ⇒ short-circuit to state='complete' here
		// (no per-article workers will ever increment the counters).
		if ( 0 === $totalEnqueued ) {
			$this->historyRepo->markComplete( $runId );
		}
	}

	/**
	 * Paginate `getArticles()` and enqueue one `sync_article` action per
	 * returned `ArticleSummary`. Returns the total number of articles
	 * enqueued.
	 *
	 * Termination:
	 *   - `count($items) === 0` ⇒ end-sentinel page, terminate.
	 *   - `count($items) < PAGE_SIZE` ⇒ partial last page, terminate after
	 *     enqueueing the remainder.
	 *   - `$page > MAX_PAGES` ⇒ safety cap, mark row failed and throw.
	 */
	private function paginateAndEnqueue( int $runId ): int
	{
		$page          = 1;
		$totalEnqueued = 0;

		while ( true ) {
			if ( $page > self::MAX_PAGES ) {
				$this->historyRepo->markFailed( $runId, 'pagination_max_pages_exceeded' );
				throw new RuntimeException(
					sprintf(
						'SyncCatalogJob: pagination max-pages cap (%d) exceeded for run_id=%d.',
						self::MAX_PAGES,
						$runId
					)
				);
			}

			$response = $this->client->getArticles( $page, self::PAGE_SIZE );
			$items    = isset( $response['items'] ) && is_array( $response['items'] )
				? $response['items']
				: array();
			$count    = count( $items );

			foreach ( $items as $summary ) {
				if ( ! $summary instanceof ArticleSummary ) {
					continue;
				}

				$articleId = $summary->id;
				if ( '' === $articleId ) {
					continue;
				}

				as_enqueue_async_action(
					self::HOOK_SYNC_ARTICLE,
					array(
						'article_id' => $articleId,
						'run_id'     => $runId,
					),
					self::AS_GROUP
				);

				++$totalEnqueued;
			}

			if ( 0 === $count || $count < self::PAGE_SIZE ) {
				break;
			}

			++$page;
		}

		return $totalEnqueued;
	}

	/**
	 * Coerce the `trigger` arg to a non-empty string, defaulting to
	 * `manual` per architecture.md Z. 240.
	 *
	 * No enum validation here — the producer (slice-26 AJAX, slice-25
	 * webhook) is responsible for supplying a valid value.
	 *
	 * @param array<string, mixed> $args
	 */
	private function resolveTrigger( array $args ): string
	{
		$trigger = $args['trigger'] ?? self::DEFAULT_TRIGGER;

		if ( ! is_string( $trigger ) || '' === $trigger ) {
			return self::DEFAULT_TRIGGER;
		}

		return $trigger;
	}
}
