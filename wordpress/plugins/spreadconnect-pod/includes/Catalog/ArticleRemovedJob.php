<?php
/**
 * Article-removed handler (Action-Scheduler hook handler).
 *
 * Consumer of the `spreadconnect/handle_article_removed` hook scheduled by
 * {@see \SpreadconnectPod\Webhook\ArticleEventHandler::handle()} when a
 * `Article.removed` webhook arrives. Reverse-looks-up the WC-Product
 * associated with the SC article-id and flips its `post_status` to `draft`
 * so historical orders that reference the product remain intact
 * (architecture.md Z. 281 + Z. 736 — `wp_delete_post` is forbidden).
 *
 * Sequence (slice-25 AC-5):
 *   1. Read `article_id` from the AS args-array.
 *   2. `get_posts()` with `meta_query` against `_spreadconnect_article_id`
 *      (mirrors {@see ProductMapper::findProductIdByArticleId()}).
 *   3. Empty result ⇒ info-log + early return (AC-6 — article never linked
 *      or already manually unlinked, no AS retry).
 *   4. `wp_update_post(['ID'=>$productId,'post_status'=>'draft'], true)`.
 *   5. `WP_Error` / `0` return ⇒ throw {@see ArticleRemovedException} so AS
 *      retries (1 m / 5 m / 15 m per architecture.md Z. 548). After 3
 *      retries slice-37 `RetryPolicyListener` records a DLQ entry.
 *   6. Happy path ⇒ `update_post_meta(_spreadconnect_sync_state,
 *      'removed_in_sc')` + `update_post_meta(_spreadconnect_last_sync,
 *      time())`. The `_spreadconnect_article_id` meta stays untouched as
 *      audit anchor (architecture.md Z. 281).
 *
 * Idempotency:
 *   - Re-runs on an already-`draft` product re-write `wp_update_post`
 *     (WC accepts the no-op flip) and re-write the sync-state meta. The
 *     job is therefore safe under double-webhook delivery and AS retries.
 *
 * Architecture references:
 *   - "Service Map" → `Catalog\ArticleRemovedJob` (Application).
 *   - "Action Scheduler — Hook Inventory" → `spreadconnect/handle_article_removed`
 *     (one-shot, Concurrency 1 per article-id, Retry 1 m / 5 m / 15 m).
 *   - "wp_*` postmeta keys" — `_spreadconnect_article_id`,
 *     `_spreadconnect_sync_state` enum (`removed_in_sc`),
 *     `_spreadconnect_last_sync`.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use WP_Error;

/**
 * Action-Scheduler handler for the `spreadconnect/handle_article_removed`
 * hook.
 *
 * Stateless per-call: the {@see self::handleStatic()} bridge instantiates a
 * fresh job instance and delegates to {@see self::handle()}. The slice-23 /
 * slice-24 collaborator-DI pattern is mirrored here even though this job
 * has no external dependencies — keeping the shape uniform makes future
 * service-map insertions (e.g. a logger adapter in slice-42) trivial.
 *
 * `final` per slice-25 constraints (no subclassing — the static bridge is
 * the extension point if a future slice needs alternate wiring).
 */
final class ArticleRemovedJob
{
	/**
	 * Postmeta key for the SC article-ID anchor (reverse-lookup target —
	 * AC-5). Mirrors the constant kept by {@see ProductMapper} and
	 * {@see SyncArticleJob}.
	 */
	private const META_ARTICLE_ID = '_spreadconnect_article_id';

	/**
	 * Postmeta key for the multi-state sync result enum
	 * (`synced`/`partial`/`error`/`removed_in_sc`).
	 */
	private const META_SYNC_STATE = '_spreadconnect_sync_state';

	/**
	 * Postmeta key for the unix-timestamp of the most-recent sync write
	 * (architecture.md Z. 287).
	 */
	private const META_LAST_SYNC = '_spreadconnect_last_sync';

	/**
	 * Sync-state value written when an SC `Article.removed` webhook lands
	 * (architecture.md Z. 292 enum).
	 */
	private const SYNC_STATE_REMOVED = 'removed_in_sc';

	/**
	 * `wc_get_logger()` source string for ArticleRemovedJob log lines
	 * (architecture.md Z. 532). Slice-42 swaps the direct `wc_get_logger`
	 * call for a `WcLoggerAdapter` but the source label stays.
	 */
	private const LOG_SOURCE = 'spreadconnect-sync-job';

	/**
	 * Static bridge for `add_action('spreadconnect/handle_article_removed', …)`.
	 *
	 * Action-Scheduler invokes the registered hook with the args-array as
	 * the first parameter. This bridge instantiates a fresh job instance
	 * and delegates to {@see self::handle()} — mirrors the
	 * {@see SyncArticleJob::handleStatic()} / {@see SyncCatalogJob::handleStatic()}
	 * convention.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['article_id'=>string]`.
	 *
	 * @throws ArticleRemovedException When `wp_update_post` fails — see
	 *                                 {@see self::handle()}.
	 */
	public static function handleStatic( array $args ): void
	{
		( new self() )->handle( $args );
	}

	/**
	 * Run the article-removed sequence.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; required key
	 *                                   `article_id` (string).
	 *
	 * @throws ArticleRemovedException When `wp_update_post` returns a
	 *                                 `WP_Error` or `0` — AS retries the
	 *                                 job per the 1 m / 5 m / 15 m policy.
	 */
	public function handle( array $args ): void
	{
		$articleId = $this->resolveArticleId( $args );

		// AC-5 / AC-6: reverse-lookup against the postmeta anchor written
		// by the slice-22 ProductMapper. `get_posts()` is preferred over
		// raw `$wpdb` so the lookup respects WP filters and stays in sync
		// with the mapper's own pre-upsert classification.
		$productId = $this->findProductIdByArticleId( $articleId );

		if ( null === $productId ) {
			$this->log(
				'info',
				sprintf(
					'ArticleRemovedJob: no WC product found article_id=%s — skipping',
					$articleId
				)
			);
			return;
		}

		// AC-5: flip the post_status to `draft`. The second argument `true`
		// to `wp_update_post` switches its return value from the
		// `0|int|false` mix to a `WP_Error|int` shape — easier to triage.
		// AC-7: re-running on an already-`draft` product is accepted by
		// WC as a no-op; the AC explicitly forbids any branch towards
		// `wp_delete_post` / `wp_trash_post`.
		$result = wp_update_post(
			array(
				'ID'          => $productId,
				'post_status' => 'draft',
			),
			true
		);

		// AC-8: WP_Error OR 0 ⇒ transient failure. AS retries; slice-37
		// records a DLQ row after the third retry.
		if ( $result instanceof WP_Error ) {
			throw new ArticleRemovedException(
				sprintf(
					'wp_update_post failed for article_id=%s product_id=%d: %s',
					$articleId,
					$productId,
					$result->get_error_message()
				)
			);
		}

		if ( 0 === $result ) {
			throw new ArticleRemovedException(
				sprintf(
					'wp_update_post failed for article_id=%s product_id=%d: %s',
					$articleId,
					$productId,
					'wp_update_post returned 0'
				)
			);
		}

		// AC-5: postmeta-update-order — sync_state first, last_sync second.
		// `_spreadconnect_article_id` is intentionally NOT touched (audit
		// anchor per architecture.md Z. 281: "meta retained for audit").
		update_post_meta( $productId, self::META_SYNC_STATE, self::SYNC_STATE_REMOVED );
		update_post_meta( $productId, self::META_LAST_SYNC, time() );

		$this->log(
			'info',
			sprintf(
				'ArticleRemovedJob: flipped product_id=%d to draft article_id=%s',
				$productId,
				$articleId
			)
		);
	}

	/**
	 * Coerce the `article_id` arg to a non-empty string.
	 *
	 * Defensive: AS args are arbitrary serialised arrays. A missing or
	 * non-string value indicates a programmer / scheduler error and must
	 * fail loudly — the producer-side validation in
	 * {@see \SpreadconnectPod\Webhook\ArticleEventHandler} already filters
	 * invalid payloads, so reaching this branch from production traffic
	 * is unexpected.
	 *
	 * @param array<string, mixed> $args
	 */
	private function resolveArticleId( array $args ): string
	{
		$articleId = $args['article_id'] ?? null;

		if ( ! is_string( $articleId ) || '' === $articleId ) {
			throw new \InvalidArgumentException(
				'ArticleRemovedJob: missing or empty "article_id" in args.'
			);
		}

		return $articleId;
	}

	/**
	 * Reverse-lookup the WC-Product associated with an SC article-ID.
	 *
	 * Mirrors {@see ProductMapper::findProductIdByArticleId()} verbatim
	 * (slice-25 Constraint: identical pattern, no raw `$wpdb`). Accepts
	 * `publish`/`draft`/`private` post statuses so an already-archived
	 * product still matches under double-webhook delivery (AC-7).
	 *
	 * @return int|null Product-ID if found, else `null`.
	 */
	private function findProductIdByArticleId( string $articleId ): ?int
	{
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => self::META_ARTICLE_ID,
				'meta_value'  => $articleId,
			)
		);

		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return null;
		}

		$first = (int) $posts[0];

		return $first > 0 ? $first : null;
	}

	/**
	 * Resolve `wc_get_logger()` and emit a single entry tagged with the
	 * `spreadconnect-sync-job` source. Defensive guards make the call a
	 * no-op when WC has not loaded yet (CLI / unit-test contexts) so the
	 * job's primary side-effects still complete cleanly.
	 */
	private function log( string $level, string $message ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}

/**
 * Raised when the slice-25 article-removed handler cannot flip the WC
 * product to `draft` — either because `wp_update_post` returned a
 * `WP_Error` (e.g. WC plugin throws on an invalid product type) or
 * because it returned `0` (update failed silently).
 *
 * Extends `\RuntimeException` (not a new base) so Action-Scheduler retry
 * logic classifies it as a transient failure — same pattern as
 * {@see ProductMapperException} (slice-22) and `SpreadconnectClientError`
 * (slice-07). After 3 retries slice-37 `RetryPolicyListener` records a
 * `wp_spreadconnect_failed_ops` row with `op_type='handle_article_removed'`.
 */
final class ArticleRemovedException extends \RuntimeException
{
}
