<?php
/**
 * Admin-AJAX handler for the Subscriptions-Manager "Repair All" button (Slice 19).
 *
 * Hooked at `wp_ajax_spreadconnect_repair_subscriptions`. The handler runs
 * the canonical repair sweep — `removeOrphans()` BEFORE `register()` to
 * avoid 4xx-on-duplicate-eventType conflicts (slice-19 AC-6 / Constraints) —
 * and emits a uniform `{added, removed, errors}` JSON-success body. The
 * request never re-implements the diff itself; both calls delegate to the
 * Slice-18 `SubscriptionManager` service.
 *
 * Hard gates (architecture.md Z. 484): `manage_woocommerce` capability +
 * nonce `spreadconnect_repair_subscriptions`. Either gate-miss terminates
 * via `wp_send_json_error( … , 403 )` BEFORE any service call.
 *
 * Error handling (slice-19 AC-7 / AC-8 / Constraints):
 *   - `SpreadconnectTransientError` (5xx / network) → 503.
 *   - `SpreadconnectClientError` (4xx) → 500 with `errors[]` payload. The
 *     `register()` summary's own `errors[]` array (4xx-per-event) is
 *     re-shaped as a 200-success response — that is the
 *     `repair_partial_error` wireframe state, not a transport-level fault.
 *   - Any other `\Throwable` → 500 generic.
 *
 * No inner-retry: a transient error returns immediately so the operator can
 * click [Repair All] again from the UI; Action-Scheduler is the only
 * recurring retry path (drift-check, slice-18).
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use SpreadconnectPod\Subscription\SubscriptionManager;
use Throwable;

/**
 * Stateless front-controller for the `spreadconnect_repair_subscriptions`
 * AJAX action.
 *
 * Final + only static methods — pattern-mirror of slice-12 `TestConnection`
 * and slice-14 `RegenerateSecret` for consistency with the rest of
 * `Hub\Ajax`.
 */
final class RepairSubscriptions
{
	/**
	 * Capability required to invoke the AJAX action.
	 *
	 * Mirrors slice-13 `Hub\Controller::REQUIRED_CAP`. Every Hub admin
	 * surface is gated on `manage_woocommerce`.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * AJAX action slug. Used both as the WP-AJAX hook name and the nonce
	 * action string — the View (`Hub\View\Subscriptions`) mints exactly the
	 * same literal via `wp_create_nonce()`.
	 */
	public const ACTION = 'spreadconnect_repair_subscriptions';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_wpnonce';

	/**
	 * Plugin text-domain for `__()` wrappers. Must match slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Logger source for repair-outcome log lines (slice-19 AC-12).
	 *
	 * Architecture.md Z. 398 reserves
	 * `spreadconnect-subscription-service` for the subscription lifecycle —
	 * we use it here so dashboards filter all subscription-related entries
	 * onto a single source.
	 */
	private const LOG_SOURCE = 'spreadconnect-subscription-service';

	/**
	 * Register the `wp_ajax_spreadconnect_repair_subscriptions` hook.
	 *
	 * Called from `Bootstrap\Plugin::init()` (mount-point per slice-19 AC-11).
	 * Only the authenticated variant is registered — `wp_ajax_nopriv_*` is
	 * deliberately omitted because anonymous callers must never be able to
	 * trigger a subscription rewrite.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action(
			'wp_ajax_' . self::ACTION,
			array( self::class, 'handle' )
		);
	}

	/**
	 * Handle a single `spreadconnect_repair_subscriptions` AJAX call.
	 *
	 * Terminates the request via `wp_send_json_*` — never returns a value
	 * to the caller.
	 *
	 * Hard-gates (both run before any service call):
	 *   1. `current_user_can( 'manage_woocommerce' )` → 403 on miss (AC-5).
	 *   2. `check_ajax_referer( ..., false )` → 403 on miss (AC-4).
	 *
	 * On success (gates pass), the order is mandatory (AC-6 / Constraints):
	 *   1. `SubscriptionManager::removeOrphans()` — DELETE orphans on our URL.
	 *   2. `SubscriptionManager::register()`      — POST missing entries.
	 *
	 * The registered `register()` summary's `errors[]` array is forwarded
	 * verbatim in the 200-success body (AC-7); only transport-level
	 * exceptions raise an HTTP 5xx.
	 *
	 * @return void
	 */
	public static function handle(): void
	{
		// AC-4: nonce hard-gate. `check_ajax_referer()` returns `false` on
		// missing/invalid nonce when the third arg is `false` — we shape the
		// JSON response uniformly instead of letting the helper `wp_die()`.
		if ( ! check_ajax_referer( self::ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
				),
				403
			);
			return; // Defensive — wp_send_json_* terminates, but tests stub it.
		}

		// AC-5: capability hard-gate. We mirror the
		// `Hub\Controller::ensureCapability()` rule directly here because
		// the helper itself `wp_die()`s — wrong shape for an AJAX response.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		try {
			// AC-6: orphan-DELETE MUST run before missing-POST. If the same
			// eventType appears as both orphan and missing (stale callback
			// URL after a domain rename), POST-first would 4xx with
			// duplicate-eventType. The slice-18 service is idempotent, so
			// re-running this pair is safe.
			$removed = SubscriptionManager::removeOrphans();

			$summary = SubscriptionManager::register();

			$added  = isset( $summary['added'] ) ? (int) $summary['added'] : 0;
			$errors = isset( $summary['errors'] ) && is_array( $summary['errors'] )
				? array_values( $summary['errors'] )
				: array();

			self::log(
				'info',
				sprintf(
					'subscription_repair_invoked added=%d removed=%d errors=%d',
					$added,
					$removed,
					count( $errors )
				)
			);

			wp_send_json_success(
				array(
					'added'   => $added,
					'removed' => (int) $removed,
					'errors'  => $errors,
				)
			);
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-8: 5xx / network / timeout. No inner-retry — the UI offers a
			// manual retry button; Action-Scheduler's drift-check sweep covers
			// the recurring path. Logger entry uses the diagnostic message
			// only; the underlying exception never carries the secret.
			self::log(
				'warning',
				sprintf(
					'subscription_repair_invoked outcome=transient_error message=%s',
					$e->getMessage()
				)
			);

			wp_send_json_error(
				array(
					'code'    => 'transient_error',
					'message' => $e->getMessage(),
				),
				503
			);
			return;
		} catch ( SpreadconnectClientError $e ) {
			// 4xx at the diff/list layer (e.g. invalid Bearer token). The
			// per-event 4xx during register() is handled inside the service
			// and surfaces via the success path's `errors[]`; this branch
			// only fires when the read or wrap-up call itself rejects.
			self::log(
				'warning',
				sprintf(
					'subscription_repair_invoked outcome=client_error message=%s',
					$e->getMessage()
				)
			);

			wp_send_json_error(
				array(
					'code'    => 'client_error',
					'message' => $e->getMessage(),
				),
				500
			);
			return;
		} catch ( Throwable $e ) {
			// Defensive net for unexpected exceptions — never leak a stack
			// trace to the client (AC-8 "kein 500-Stacktrace-Leak").
			self::log(
				'error',
				sprintf(
					'subscription_repair_invoked outcome=unexpected_error message=%s',
					$e->getMessage()
				)
			);

			wp_send_json_error(
				array(
					'code'    => 'unexpected_error',
					'message' => __( 'An unexpected error occurred.', self::TEXT_DOMAIN ),
				),
				500
			);
			return;
		}
	}

	/**
	 * Logger shim — never emits the plaintext webhook secret (slice-19 AC-12).
	 *
	 * Mirrors the {@see SpreadconnectClient::log()} contract: requires
	 * `wc_get_logger()` to be available; silently no-ops otherwise so unit
	 * tests don't depend on WC being booted. The caller is responsible for
	 * ensuring the message string never includes a secret value — slice-07
	 * + slice-14 redact at the producer side.
	 *
	 * @param string $level   PSR-3 log level (`info`, `warning`, `error`).
	 * @param string $message Log message — must not contain the secret.
	 */
	private static function log( string $level, string $message ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
