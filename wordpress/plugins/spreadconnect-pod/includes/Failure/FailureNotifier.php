<?php
/**
 * FailureNotifier — best-effort email dispatcher for permanent operation
 * failures (slice-39).
 *
 * Closes the Failure-Recovery notification lane: every successful insert
 * into `wp_spreadconnect_failed_ops` (DLQ, slice-37) is followed by a
 * call to {@see self::dispatch()} which — gated per
 * `spreadconnect_notify_on_*_failure` flag — sends a plain-text mail to
 * every recipient listed in `spreadconnect_notify_emails`.
 *
 * Best-effort by design (slice-39 AC-4):
 *   - No throw out of `dispatch()` — `wp_mail` exceptions are swallowed
 *     and routed through {@see WcLoggerAdapter::error()} so the AS retry
 *     pipeline never sees a mail failure as a job failure.
 *   - Empty recipients → skip + warn-log (slice-39 AC-3); the row is
 *     still persisted to the DLQ + Admin-Notice store, only the
 *     out-of-band mail channel is dropped.
 *   - Off-flag → silent skip (slice-39 AC-2); no log entry, since the
 *     admin explicitly opted out.
 *
 * The notification severity-policy lives in {@see AdminNoticeStore} —
 * this class only owns the mail channel.
 *
 * Architecture refs:
 *   - architecture.md "Service Map" Z. 389 — `Failure\FailureNotifier`
 *     responsibility row.
 *   - architecture.md "WP Options" Z. 335-338 — `spreadconnect_notify_emails`
 *     + 3 `notify_on_*` flags + defaults.
 *   - architecture.md "Flow C" Z. 425-429 — `FailureNotifier::dispatch()`
 *     call-point after `FailedOpsRepo::record()`.
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;

/**
 * Stateless mail dispatcher for permanent op-failures.
 *
 * `final` per slice-39 Constraints. Constructor accepts an optional
 * `?\WC_Logger` override so unit tests can inject a Mockery-double sink
 * for log-line assertions; the production path goes through
 * {@see WcLoggerAdapter} which resolves `wc_get_logger()` on each call.
 *
 * Both the optional logger and the gating-flag map are injected via the
 * constructor so the public surface is `(?\WC_Logger $logger = null)` —
 * matches the slice-37 `RetryPolicyListener` shape and keeps wiring in
 * `Bootstrap\Plugin::init()` lazy-closure-friendly.
 */
final class FailureNotifier
{
	/**
	 * Op-Type → gating-Option-key map (slice-39 AC-2).
	 *
	 * Determines which `spreadconnect_notify_on_*_failure` flag a given
	 * `op_type` is gated by. Unknown op-types fall back to the order-failure
	 * flag (defensive default — `true` by slice-05 OptionsDefaults).
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_GATING_OPTION = array(
		// Order pipeline → notify_on_order_failure (default true).
		'create_order'           => 'spreadconnect_notify_on_order_failure',
		'confirm_order'          => 'spreadconnect_notify_on_order_failure',
		'cancel_order_mirror'    => 'spreadconnect_notify_on_order_failure',
		'fetch_tracking'         => 'spreadconnect_notify_on_order_failure',

		// Sync pipeline → notify_on_sync_failure (default true).
		'sync_catalog'           => 'spreadconnect_notify_on_sync_failure',
		'sync_article'           => 'spreadconnect_notify_on_sync_failure',
		'handle_article_removed' => 'spreadconnect_notify_on_sync_failure',
		'scheduled_stock_sync'   => 'spreadconnect_notify_on_sync_failure',

		// Webhook pipeline → notify_on_webhook_failure (default false).
		'handle_webhook'         => 'spreadconnect_notify_on_webhook_failure',
	);

	/**
	 * Default value used by `get_option()` when an `notify_on_*` flag is
	 * not yet seeded in the DB. Mirrors slice-05 OptionsDefaults.
	 *
	 * @var array<string, bool>
	 */
	private const NOTIFY_FLAG_DEFAULT = array(
		'spreadconnect_notify_on_order_failure'   => true,
		'spreadconnect_notify_on_sync_failure'    => true,
		'spreadconnect_notify_on_webhook_failure' => false,
	);

	/**
	 * Op-Type → human-readable subject-label map (slice-39 AC-1).
	 *
	 * The labels are wrapped in `__()` at use-time so slice-46 i18n
	 * collection picks them up via `xgettext-php`.
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_LABEL = array(
		'create_order'           => 'Order failed',
		'confirm_order'          => 'Order confirm failed',
		'cancel_order_mirror'    => 'Order cancel-mirror failed',
		'fetch_tracking'         => 'Tracking-fetch failed',
		'sync_catalog'           => 'Catalog-sync failed',
		'sync_article'           => 'Article-sync failed',
		'handle_article_removed' => 'Article-removal failed',
		'scheduled_stock_sync'   => 'Stock-sync failed',
		'handle_webhook'         => 'Webhook-processing failed',
	);

	/**
	 * Optional WC-Logger override (reserved for tests). Default-path
	 * goes through {@see WcLoggerAdapter::warning()}/error().
	 */
	private ?\WC_Logger $logger;

	/**
	 * @param \WC_Logger|null $logger Optional logger override.
	 */
	public function __construct( ?\WC_Logger $logger = null )
	{
		$this->logger = $logger;
	}

	/**
	 * Dispatch a notification mail for one Failed-Op row.
	 *
	 * Returns:
	 *   - `true`  on a successful `wp_mail()` invocation;
	 *   - `false` on any of the off-paths (flag off, empty recipients,
	 *     wp_mail throw, wp_mail false return).
	 *
	 * The method NEVER throws — slice-39 AC-4 is explicit: a defective
	 * `phpmailer_init` hook or `wp_mail` filter cannot be allowed to
	 * derail the AS retry-policy pipeline that triggered us.
	 *
	 * @param array<string, mixed> $failedOpRow Row as returned by
	 *                                          {@see FailedOpsRepo::findById()}
	 *                                          (id + op_type + entity tuple
	 *                                          + error message/code +
	 *                                          created_at).
	 *
	 * @return bool `true` when the mail was dispatched, `false` otherwise.
	 */
	public function dispatch( array $failedOpRow ): bool
	{
		try {
			return $this->dispatchInternal( $failedOpRow );
		} catch ( \Throwable $e ) {
			WcLoggerAdapter::error(
				Sources::FAILURE,
				sprintf( 'wp_mail dispatch failed: %s', $e->getMessage() ),
				array(
					'failed_op_id'      => isset( $failedOpRow['id'] ) ? (int) $failedOpRow['id'] : 0,
					'op_type'           => isset( $failedOpRow['op_type'] ) ? (string) $failedOpRow['op_type'] : '',
					'exception'         => get_class( $e ),
					'exception_message' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Internal dispatch worker — separated from the public entry-point so
	 * the outer `try/catch \Throwable` body stays minimal and the unit-tests
	 * can assert wp_mail-throw-swallow directly via `dispatch()`.
	 *
	 * @param array<string, mixed> $row
	 */
	private function dispatchInternal( array $row ): bool
	{
		$opType = isset( $row['op_type'] ) ? (string) $row['op_type'] : '';

		// AC-2: per-op-type gating flag.
		if ( ! $this->isGatedOn( $opType ) ) {
			return false;
		}

		// AC-3: empty recipients → skip + warn-log.
		$recipients = $this->resolveRecipients();
		if ( array() === $recipients ) {
			WcLoggerAdapter::warning(
				Sources::FAILURE,
				'no notification recipients configured — skipping wp_mail dispatch',
				array(
					'failed_op_id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'op_type'      => $opType,
				)
			);
			return false;
		}

		$subject = $this->buildSubject( $row );
		$message = $this->buildMessage( $row );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$result = wp_mail( $recipients, $subject, $message, $headers );

		return (bool) $result;
	}

	/**
	 * Whether the gating-flag for `$opType` is `true`.
	 *
	 * Unknown op-types fall back to the order-failure flag (defensive
	 * default) — they should never reach the notifier in production but
	 * the gate stays `true`-by-default so a missing entry in
	 * {@see self::OP_TYPE_GATING_OPTION} does not silently swallow a
	 * notification.
	 */
	private function isGatedOn( string $opType ): bool
	{
		$optionKey = self::OP_TYPE_GATING_OPTION[ $opType ]
			?? 'spreadconnect_notify_on_order_failure';

		$default = self::NOTIFY_FLAG_DEFAULT[ $optionKey ] ?? true;

		$raw = get_option( $optionKey, $default );

		// Slice-11 SettingsValidator already casts to strict bool; the
		// extra coercion here is defensive — a hand-edited DB row with a
		// stringy `'1'` / `'0'` should still gate correctly.
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		if ( is_string( $raw ) ) {
			$lower = strtolower( $raw );
			if ( '' === $lower || '0' === $lower || 'false' === $lower || 'no' === $lower || 'off' === $lower ) {
				return false;
			}
			return true;
		}

		return (bool) $raw;
	}

	/**
	 * Resolve `spreadconnect_notify_emails` into a clean list of valid
	 * email addresses (slice-39 AC-1 + Constraints).
	 *
	 * Defensive double-filter: slice-11 `SettingsValidator::sanitizeEmailList`
	 * already runs `sanitize_email()` on every CSV-token before persistence,
	 * but the notifier re-applies `is_email()` so a hand-edited DB value
	 * cannot inject a malformed `To:` header into `wp_mail`.
	 *
	 * @return list<string> Trimmed valid emails in original CSV order.
	 */
	private function resolveRecipients(): array
	{
		$raw = get_option( 'spreadconnect_notify_emails', '' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$tokens   = explode( ',', $raw );
		$trimmed  = array_map( 'trim', $tokens );
		$nonEmpty = array_filter( $trimmed, static fn ( string $candidate ): bool => '' !== $candidate );

		// `is_email()` returns the email on success or `false` on rejection.
		$valid = array();
		foreach ( $nonEmpty as $candidate ) {
			if ( function_exists( 'is_email' ) ) {
				$check = is_email( $candidate );
				if ( false === $check || ! is_string( $check ) ) {
					continue;
				}
			}
			$valid[] = $candidate;
		}

		return array_values( $valid );
	}

	/**
	 * Build the `wp_mail` subject line (slice-39 AC-1).
	 *
	 * Format: `'[Spreadconnect] {op-type-label} — #{entity-id}'`.
	 * The translatable strings are passed through `__()` for slice-46
	 * i18n collection.
	 *
	 * @param array<string, mixed> $row
	 */
	private function buildSubject( array $row ): string
	{
		$opType   = isset( $row['op_type'] ) ? (string) $row['op_type'] : '';
		$entityId = isset( $row['related_entity_id'] ) ? (string) $row['related_entity_id'] : '';

		$labelKey = self::OP_TYPE_LABEL[ $opType ] ?? 'Operation failed';
		$label    = __( $labelKey, 'spreadconnect-pod' );

		$entityRef = '' !== $entityId ? '#' . $entityId : '#?';

		return sprintf( '[Spreadconnect] %s — %s', $label, $entityRef );
	}

	/**
	 * Build the plain-text mail body (slice-39 AC-1).
	 *
	 * Includes:
	 *   - The op-type label + entity reference (subject-mirror).
	 *   - `error_message`, `error_code`, `created_at` columns from the row.
	 *   - A Hub-deeplink URL pointing at the Failed-Ops sub-page,
	 *     pre-filtered to highlight this row.
	 *
	 * No HTML: keeps the PHPMailer `phpmailer_init`-hook surface minimal
	 * and avoids escaping concerns inside the template.
	 *
	 * @param array<string, mixed> $row
	 */
	private function buildMessage( array $row ): string
	{
		$opType    = isset( $row['op_type'] ) ? (string) $row['op_type'] : '';
		$entityId  = isset( $row['related_entity_id'] ) ? (string) $row['related_entity_id'] : '';
		$errorMsg  = isset( $row['error_message'] ) ? (string) $row['error_message'] : '';
		$errorCode = isset( $row['error_code'] ) ? (string) $row['error_code'] : '';
		$createdAt = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		$rowId     = isset( $row['id'] ) ? (int) $row['id'] : 0;

		$labelKey = self::OP_TYPE_LABEL[ $opType ] ?? 'Operation failed';
		$label    = __( $labelKey, 'spreadconnect-pod' );

		$entityRef = '' !== $entityId ? '#' . $entityId : '#?';

		$deeplink = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=spreadconnect&section=failed&highlight=' . $rowId )
			: 'admin.php?page=spreadconnect&section=failed&highlight=' . $rowId;

		$lines = array(
			sprintf( '[Spreadconnect] %s — %s', $label, $entityRef ),
			'',
			__( 'A permanent failure was recorded in the Failed-Ops queue.', 'spreadconnect-pod' ),
			'',
			sprintf( '%s: %s', __( 'Op-Type', 'spreadconnect-pod' ), $opType ),
			sprintf( '%s: %s', __( 'Entity', 'spreadconnect-pod' ), $entityRef ),
			sprintf( '%s: %s', __( 'Error message', 'spreadconnect-pod' ), $errorMsg ),
			sprintf( '%s: %s', __( 'Error code', 'spreadconnect-pod' ), $errorCode ),
			sprintf( '%s: %s', __( 'Created at (UTC)', 'spreadconnect-pod' ), $createdAt ),
			'',
			__( 'Open the Failed-Ops sub-page to inspect or resolve:', 'spreadconnect-pod' ),
			$deeplink,
		);

		return implode( "\n", $lines );
	}
}
