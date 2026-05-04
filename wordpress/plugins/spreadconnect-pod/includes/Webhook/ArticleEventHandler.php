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
