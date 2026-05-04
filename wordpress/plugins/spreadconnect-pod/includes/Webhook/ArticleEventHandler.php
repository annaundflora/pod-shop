<?php
/**
 * Domain handler for `Article.*` webhook events (slice-25).
 *
 * Replaces the slice-17 logging-only stub with the real implementation per
 * the slice-17 Integration Contract ("`ArticleEventHandler::handle` …
 * vollstaendig ueberschrieben in Slice 25"). The class signature stays
 * intact so the {@see ProcessWebhookEventJob} dispatcher continues to
 * invoke `ArticleEventHandler::handle($payload)` without a dispatcher edit.
 *
 * Mapping (architecture.md Z. 382 + Z. 842-844):
 *   - `Article.added`   → `as_enqueue_async_action('spreadconnect/sync_article',
 *                          ['article_id'=>$id, 'run_id'=>null], 'spreadconnect')`.
 *   - `Article.updated` → identical to `Article.added` (slice-23 tolerates
 *                          mehrfach-Schedule via reverse-lookup idempotency
 *                          AC-2; `run_id=null` skips history-append per
 *                          slice-23 AC-10).
 *   - `Article.removed` → `as_enqueue_async_action(
 *                          'spreadconnect/handle_article_removed',
 *                          ['article_id'=>$id], 'spreadconnect')` — slice-25's
 *                          consumer flips the WC-Product to `draft`.
 *
 * Validation:
 *   - `data.entity.id` MUST be a non-empty string. Anything else
 *     (missing key, empty string, non-string scalar) is treated as a
 *     permanent validation failure: a warning is logged and the handler
 *     returns cleanly so the dispatcher in
 *     {@see ProcessWebhookEventJob} writes `processing_status='success'`
 *     to the webhook-log row (AS retry would not fix a malformed payload).
 *
 * No SC-API calls happen here — the handler is the producer side of the
 * webhook → catalog pipeline; the corresponding `Catalog\SyncArticleJob`
 * (slice-23) and `Catalog\ArticleRemovedJob` (slice-25 sibling) carry
 * out the actual mutations on the next AS tick.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use SpreadconnectPod\Stock\LiveStockRefresher;
use SpreadconnectPod\Stock\StockCache;
use Throwable;

/**
 * Producer for the `spreadconnect/sync_article` and
 * `spreadconnect/handle_article_removed` Action-Scheduler hooks.
 *
 * Static-only `final class` — Job-pattern (architecture.md Z. 532, single-
 * method handler). The public signature
 * (`public static function handle(array $payload): void`) is contract-bound
 * by the slice-17 dispatcher; slice-25 only swaps the body.
 */
final class ArticleEventHandler
{
	/**
	 * `wc_get_logger()` source string shared by the entire webhook
	 * receive pipeline (architecture.md Z. 398 + slice-25 Constraints
	 * "Logging-Sources").
	 */
	private const LOG_SOURCE = 'spreadconnect-webhook-receiver';

	/**
	 * Action-Scheduler group used for every enqueued action. Matches the
	 * convention introduced by slice-23 / slice-24.
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * Hook name of the per-article sync consumer (slice-23). Producer-side
	 * invocation for `Article.added` + `Article.updated`.
	 */
	private const HOOK_SYNC_ARTICLE = 'spreadconnect/sync_article';

	/**
	 * Hook name of the article-removed consumer (slice-25 sibling
	 * {@see \SpreadconnectPod\Catalog\ArticleRemovedJob}).
	 */
	private const HOOK_HANDLE_ARTICLE_REMOVED = 'spreadconnect/handle_article_removed';

	/**
	 * Webhook event-type — article-added (architecture.md Z. 175 enum).
	 */
	private const EVENT_ADDED = 'Article.added';

	/**
	 * Webhook event-type — article-updated (architecture.md Z. 175 enum).
	 */
	private const EVENT_UPDATED = 'Article.updated';

	/**
	 * Webhook event-type — article-removed (architecture.md Z. 175 enum).
	 */
	private const EVENT_REMOVED = 'Article.removed';

	/**
	 * Postmeta key for the SC article-ID anchor — used by the slice-36
	 * `Article.updated` reverse-lookup (mirrors slice-22 / slice-23 /
	 * slice-25 / `LiveStockRefresher`). Kept as a local const so this
	 * class does not reach into `Catalog\ProductMapper`'s private
	 * constants.
	 */
	private const META_ARTICLE_ID = '_spreadconnect_article_id';

	/**
	 * Process one Article webhook payload.
	 *
	 * @param array<string, mixed> $payload Decoded webhook payload (already
	 *                                      validated by the dispatcher to
	 *                                      contain a string `eventType`
	 *                                      with the `Article.` prefix).
	 *
	 * @return void
	 */
	public static function handle( array $payload ): void
	{
		$eventType = isset( $payload['eventType'] ) && is_string( $payload['eventType'] )
			? $payload['eventType']
			: '';

		// AC-4: validate `data.entity.id` as a non-empty string. The
		// entity-id is the only payload field this handler consumes; any
		// other shape is a permanent validation failure (no AS retry would
		// help). Returning cleanly tells the dispatcher to mark the
		// webhook-log row `success` — the warning log keeps the failure
		// observable in WC → Status → Logs.
		$entityId = self::extractEntityId( $payload );

		if ( null === $entityId ) {
			self::log(
				'warning',
				sprintf(
					'ArticleEventHandler: missing or invalid entity.id event_type=%s',
					$eventType
				)
			);
			return;
		}

		// AC-1 / AC-2 / AC-3: dispatch by event-type to the appropriate
		// AS hook. The three event-types are disjoint per architecture.md
		// Z. 175 so a single switch is sufficient. Unknown event-types
		// (e.g. a hypothetical `Article.something_else`) fall through to
		// the default branch which logs a warning and skips the enqueue —
		// the dispatcher's prefix-match in {@see ProcessWebhookEventJob}
		// guarantees we only see `Article.*` here.
		switch ( $eventType ) {
			case self::EVENT_ADDED:
			case self::EVENT_UPDATED:
				as_enqueue_async_action(
					self::HOOK_SYNC_ARTICLE,
					array(
						'article_id' => $entityId,
						'run_id'     => null,
					),
					self::AS_GROUP
				);

				// slice-36 AC-9: Article.updated additionally triggers an
				// inline live-stock refresh by reverse-lookup on
				// `_spreadconnect_article_id`. Skipped silently when no WC
				// product is linked (slice-23 sync_article will create the
				// product on its tick, then subsequent webhooks find a
				// match). Best-effort: any client / transient error is
				// swallowed + logged so the slice-25 contract (always
				// enqueue) stays intact (Constraint "Webhook-Edit-Idempotenz").
				if ( self::EVENT_UPDATED === $eventType ) {
					self::triggerStockRefresh( $entityId );
				}
				return;

			case self::EVENT_REMOVED:
				as_enqueue_async_action(
					self::HOOK_HANDLE_ARTICLE_REMOVED,
					array(
						'article_id' => $entityId,
					),
					self::AS_GROUP
				);
				return;

			default:
				self::log(
					'warning',
					sprintf(
						'ArticleEventHandler: unhandled Article event_type=%s — skipping',
						$eventType
					)
				);
				return;
		}
	}

	/**
	 * Extract `data.entity.id` and validate it as a non-empty string.
	 *
	 * Returns `null` whenever the path is missing, the leaf is not a
	 * string, or the leaf is the empty string — all three conditions
	 * collapse into the AC-4 "permanent validation failure" branch.
	 *
	 * @param array<string, mixed> $payload Decoded webhook payload.
	 */
	private static function extractEntityId( array $payload ): ?string
	{
		$entityId = $payload['data']['entity']['id'] ?? null;

		if ( ! is_string( $entityId ) || '' === $entityId ) {
			return null;
		}

		return $entityId;
	}

	/**
	 * Slice-36 AC-9: trigger a {@see LiveStockRefresher::refresh()} call
	 * for the WC product linked to `$articleId`, if any.
	 *
	 * Behaviour contract:
	 *   - Reverse-look-up the WC product via `_spreadconnect_article_id`.
	 *     Missing match → no-op (slice-23 sync_article will create the
	 *     product on its tick).
	 *   - On `SpreadconnectClientError` / `SpreadconnectTransientError`
	 *     (and any other `Throwable`) the call is swallowed + logged at
	 *     `warning` level. The periodic {@see \SpreadconnectPod\Stock\StockSyncJob}
	 *     remains the authoritative stock-sync path; webhook refresh is
	 *     best-effort (architecture.md Z. 719).
	 *   - Slice-25 ACs 1/2/3/4 stay green — the producer-side
	 *     `as_enqueue_async_action()` has already happened by the time we
	 *     reach this method (see {@see self::handle()}).
	 */
	private static function triggerStockRefresh( string $articleId ): void
	{
		$productId = self::findProductIdByArticleId( $articleId );

		if ( null === $productId ) {
			// Article.updated for a product we do not yet have a local copy
			// of: the slice-23 sync_article job will create it on its tick.
			return;
		}

		try {
			$refresher = new LiveStockRefresher(
				new SpreadconnectClient(),
				new StockCache()
			);
			$refresher->refresh( $productId );
		} catch ( SpreadconnectTransientError | SpreadconnectClientError $e ) {
			self::log(
				'warning',
				sprintf(
					'ArticleEventHandler: stock-refresh failed article_id=%s product_id=%d: %s',
					$articleId,
					$productId,
					$e->getMessage()
				)
			);
		} catch ( Throwable $e ) {
			// Defensive: any other failure must not propagate up — the
			// slice-25 enqueue-side has already succeeded and the dispatcher
			// must mark the webhook-log row `success`.
			self::log(
				'warning',
				sprintf(
					'ArticleEventHandler: unexpected stock-refresh failure article_id=%s product_id=%d: %s',
					$articleId,
					$productId,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Reverse-lookup the WC-Product associated with an SC article-ID.
	 *
	 * Mirrors {@see \SpreadconnectPod\Catalog\SyncArticleJob::findProductIdByArticleId()}
	 * verbatim (slice-25 / slice-36 Constraint: identical pattern, no raw
	 * `$wpdb`). Accepts `publish`/`draft`/`private` post statuses so
	 * already-archived products still match.
	 */
	private static function findProductIdByArticleId( string $articleId ): ?int
	{
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'key'     => self::META_ARTICLE_ID,
						'value'   => $articleId,
						'compare' => '=',
					),
				),
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
	 * `spreadconnect-webhook-receiver` source. Defensive guards make the
	 * call a no-op when WC has not loaded yet (CLI / unit-test contexts)
	 * so the handler's primary dispatch still completes cleanly.
	 */
	private static function log( string $level, string $message ): void
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
