<?php
/**
 * Admin-AJAX handler for the Settings -> "Developer Tools" Simulate buttons (Slice 44).
 *
 * Hooked at `wp_ajax_spreadconnect_simulate_event`. The handler receives one
 * of three event types (`order_cancelled`, `order_processed`, `shipment_sent`)
 * plus a Spreadconnect test-order-id, and dispatches the matching
 * `SpreadconnectClient::simulate*()` wrapper from slice-10. The wrapper hits
 * the SC staging Simulate endpoint, which causes SC to send the corresponding
 * webhook back into the plugin's webhook receiver — verifying the webhook
 * pipeline (slice-15/16/17 + 30) end-to-end.
 *
 * Defense-in-depth gating (architecture.md Z. 109-111, Z. 80):
 *   1. capability gate `manage_woocommerce` -> 403 on miss,
 *   2. nonce gate `spreadconnect_simulate_event` -> 403 on miss,
 *   3. server-side staging gate `get_option('spreadconnect_use_staging')`
 *      -> 403 in production. UI-hide is cosmetic only — this is the
 *      authoritative refusal layer.
 *
 * The handler is stateless; each call instantiates a fresh
 * {@see SpreadconnectClient} (uses the persisted API key, no override). No
 * logging here — the underlying client emits structured logs for every
 * outbound request.
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;

/**
 * Stateless front-controller for the `spreadconnect_simulate_event` AJAX action.
 *
 * Final + only static methods — admin-ajax handlers carry no instance state
 * across requests. Mount-point is `Bootstrap\Plugin::init()` per the slice-12
 * convention.
 */
final class SimulateEvent
{
	/**
	 * Capability required to invoke the AJAX action.
	 *
	 * Matches slice-11 AC-9 / slice-12 / slice-13 — every Hub admin surface is
	 * gated on `manage_woocommerce`.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Nonce action string. Identical literal must be used by both
	 * `wp_create_nonce( 'spreadconnect_simulate_event' )` (in the
	 * `SettingsDevTools::render()` markup) and `check_ajax_referer()` here.
	 */
	public const NONCE_ACTION = 'spreadconnect_simulate_event';

	/**
	 * AJAX action name, matching the `wp_ajax_*` hook suffix.
	 */
	public const AJAX_ACTION = 'spreadconnect_simulate_event';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_ajax_nonce';

	/**
	 * Plugin text-domain for `__()` wrappers. Matches slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Whitelist of accepted event values mapped to their wrapper-method names.
	 *
	 * Keys are the values accepted in `$_POST['event']`; values are the
	 * matching public method names on {@see SpreadconnectClient}. Any other
	 * `$_POST['event']` value is rejected with HTTP 400.
	 */
	private const EVENT_METHOD_MAP = array(
		'order_cancelled' => 'simulateOrderCancelled',
		'order_processed' => 'simulateOrderProcessed',
		'shipment_sent'   => 'simulateShipmentSent',
	);

	/**
	 * Register the `wp_ajax_spreadconnect_simulate_event` hook.
	 *
	 * Called from `Bootstrap\Plugin::init()` (mount-point per slice-12 spec).
	 * Only the authenticated variant is registered — `wp_ajax_nopriv_*` is
	 * deliberately omitted because anonymous callers must never trigger
	 * outbound Simulate calls.
	 *
	 * `add_action` de-duplicates identical callable/priority pairs so a
	 * re-entrant `init()` keeps the hook count at 1.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action(
			'wp_ajax_' . self::AJAX_ACTION,
			array( self::class, 'handle' )
		);
	}

	/**
	 * Handle a single `spreadconnect_simulate_event` AJAX call.
	 *
	 * Terminates the request via `wp_send_json_success()` /
	 * `wp_send_json_error()` — never returns a normal value to the caller.
	 *
	 * Hard-gate ordering (all three run before any business logic):
	 *   1. `current_user_can( 'manage_woocommerce' )` -> 403 on miss,
	 *   2. `check_ajax_referer( ..., false )`         -> 403 on miss,
	 *   3. `get_option( 'spreadconnect_use_staging' ) === true` -> 403 in prod.
	 *
	 * @return void
	 */
	public static function handle(): void
	{
		// AC-5: capability hard-gate.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Permission denied.', self::TEXT_DOMAIN ),
				),
				403
			);
			return; // Defensive — wp_send_json_* terminates, but tests stub it.
		}

		// AC-6: nonce hard-gate. `false` as 3rd arg keeps the helper from
		// `wp_die()`-ing on its own so we can shape the JSON response uniformly.
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Invalid nonce.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		// AC-7: server-side staging gate. UI-hide (AC-2) is cosmetic — this is
		// the authoritative refusal layer against direct AJAX calls from the
		// browser DevTools console in production.
		if ( true !== get_option( 'spreadconnect_use_staging', false ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Dev tools are only available when staging mode is enabled.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		// AC-8: event-whitelist validation. `wp_unslash` undoes auto-slashing;
		// `sanitize_text_field` strips control chars + collapses whitespace.
		$rawEvent = isset( $_POST['event'] ) ? $_POST['event'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $rawEvent ) ) {
			$rawEvent = '';
		}
		$event = sanitize_text_field( wp_unslash( $rawEvent ) );

		if ( ! isset( self::EVENT_METHOD_MAP[ $event ] ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Unknown event type.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		// AC-9: order_id non-empty check (after sanitize+trim). No format/regex
		// — SC API responds 4xx on malformed IDs, which AC-11 surfaces as 400.
		$rawOrderId = isset( $_POST['order_id'] ) ? $_POST['order_id'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $rawOrderId ) ) {
			$rawOrderId = '';
		}
		$orderId = trim( sanitize_text_field( wp_unslash( $rawOrderId ) ) );

		if ( '' === $orderId ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Test order ID is required.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		// AC-10: dispatch to the matching wrapper. Construct a fresh client —
		// no singleton/cache. The persisted API key is used (no override).
		$client = new SpreadconnectClient();
		$method = self::EVENT_METHOD_MAP[ $event ];

		try {
			$client->{$method}( $orderId );

			wp_send_json_success(
				array(
					'ok'      => true,
					'message' => __( 'Simulate triggered. Webhook should arrive within seconds — check Webhook Log.', self::TEXT_DOMAIN ),
				)
			);
			return;
		} catch ( SpreadconnectClientError $e ) {
			// AC-11(a): 4xx -> 400 with the exception message. The wrapper's
			// own message already describes the SC-side rejection (e.g. unknown
			// order id), so passing it through gives the dev a useful hint.
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => $e->getMessage(),
				),
				400
			);
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-11(b): 5xx / 429 / network -> 502 (Bad-Gateway-semantic).
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => $e->getMessage(),
				),
				502
			);
			return;
		}
	}
}
