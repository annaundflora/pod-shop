<?php
/**
 * Admin-AJAX handler for the Settings -> "Test This Key" button (Slice 12).
 *
 * Hooked at `wp_ajax_spreadconnect_test_connection`. The handler validates an
 * **unsaved** API key against the Spreadconnect `GET /authentication`
 * endpoint without persisting it to `wp_options`. Persistence stays the sole
 * responsibility of the slice-11 Settings form (`SettingsValidator::sanitize`).
 *
 * Behaviour summary (architecture.md Z. 141):
 *   - capability gate `manage_woocommerce` + nonce `spreadconnect_test_connection`,
 *   - read POST-body `api_key` (`wp_unslash` + `sanitize_text_field`),
 *   - empty key  -> `wp_send_json_error( ['ok' => false, 'message' => 'API Key required'] )`,
 *   - valid key  -> `wp_send_json_success( ['ok' => true, 'message' => 'Valid -- click Save to apply'] )`,
 *   - 4xx        -> `wp_send_json_error( ['ok' => false, 'message' => 'Invalid Key -- check value or environment'] )`,
 *   - 5xx/429    -> `wp_send_json_error( ['ok' => false, 'message' => 'Connection failed -- please try again'] )`.
 *
 * The handler is stateless: every call receives a freshly constructed
 * {@see SpreadconnectClient} with the POST-body key as `apiKeyOverride`.
 * No `sc_health` transient writes (slice-13 owns that), no logging beyond
 * what the underlying client already emits.
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;

/**
 * Stateless front-controller for the `spreadconnect_test_connection` AJAX action.
 *
 * Final + only static methods — admin-ajax handlers carry no instance state
 * across requests. Slice 13 documents `Hub\Controller::ensureCapability()` as
 * the canonical capability helper; this slice ships first against
 * `current_user_can( 'manage_woocommerce' )` directly so the action is
 * functional in isolation, and slice-13 (already merged at deliverable time)
 * provides the same gate identity downstream.
 */
final class TestConnection
{
	/**
	 * Capability required to invoke the AJAX action.
	 *
	 * Mirrors slice-11 AC-9 / slice-13 `Hub\Controller::REQUIRED_CAP` — every
	 * Hub admin surface is gated on `manage_woocommerce`. Never widened to
	 * `manage_options` (too permissive — see slice-12 Constraints).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Nonce action string. The same literal is used by both
	 * `wp_create_nonce( 'spreadconnect_test_connection' )` (in the Settings
	 * view markup) and `check_ajax_referer( 'spreadconnect_test_connection',
	 * '_ajax_nonce', false )` here. Renaming this constant requires updating
	 * the Settings view markup in the same commit.
	 */
	private const NONCE_ACTION = 'spreadconnect_test_connection';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_ajax_nonce';

	/**
	 * Plugin text-domain for `__()` wrappers. Must match slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Register the `wp_ajax_spreadconnect_test_connection` hook.
	 *
	 * Called from `Bootstrap\Plugin::init()` (mount-point per slice-12 spec).
	 * Only the authenticated variant is registered — `wp_ajax_nopriv_*` is
	 * deliberately omitted because anonymous callers must not be able to
	 * probe the configured key.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action(
			'wp_ajax_' . self::NONCE_ACTION,
			array( self::class, 'handle' )
		);
	}

	/**
	 * Handle a single `spreadconnect_test_connection` AJAX call.
	 *
	 * Terminates the request via `wp_send_json_success()` /
	 * `wp_send_json_error()` — never returns a normal value to the caller.
	 *
	 * Hard-gates (both run before any business logic):
	 *   1. `current_user_can( 'manage_woocommerce' )` -> 403 on miss.
	 *   2. `check_ajax_referer( ..., false )`         -> 403 on miss.
	 *
	 * After the gates the POST-body `api_key` is read, sanitised and used to
	 * construct a one-off {@see SpreadconnectClient}. No option is read or
	 * written; the persisted `spreadconnect_api_key` is intentionally ignored
	 * (the user is testing the value currently in the input field).
	 *
	 * @return void
	 */
	public static function handle(): void
	{
		// AC-1: capability hard-gate. Order is implementer's choice; both
		// gates must run before any business logic — we run cap first so a
		// session without WC privilege never even touches the nonce check.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return; // Defensive — wp_send_json_* terminates, but tests stub it.
		}

		// AC-2: nonce hard-gate. `false` as 3rd arg keeps the helper from
		// `wp_die()`-ing on its own so we can shape the JSON response
		// uniformly. A missing or invalid nonce returns 0/false; we coerce
		// to bool and reject.
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		// AC-3: read the unsaved key from the POST body. `wp_unslash()` undoes
		// WP's magic-quotes-style auto-slashing; `sanitize_text_field()`
		// strips control chars + collapses whitespace. We do NOT escape — the
		// key is an opaque Bearer token sent only outbound, never echoed to
		// HTML.
		$rawKey = isset( $_POST['api_key'] ) ? $_POST['api_key'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $rawKey ) ) {
			$rawKey = '';
		}

		$apiKey = sanitize_text_field( wp_unslash( $rawKey ) );

		// AC-7: empty key -> short-circuit, never spend a roundtrip on a
		// guaranteed 401. `SpreadconnectClient` would refuse the same way
		// (auth_missing pre-flight guard) but the dedicated message is
		// clearer for the user.
		if ( '' === $apiKey ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'API Key required', self::TEXT_DOMAIN ),
				)
			);
			return;
		}

		// Construct a fresh, single-use client with the POST-body key as
		// override (slice-07 constructor signature). No singleton/cache —
		// each click is a separate verification attempt.
		$client = new SpreadconnectClient( $apiKey );

		try {
			// AC-4: success path. The `AuthOk` instance itself is the success
			// signal — its body content is irrelevant for the JSON response
			// (no leak of `pointOfSaleId` / `accountId` to the client UI).
			$client->authenticate();

			wp_send_json_success(
				array(
					'ok'      => true,
					'message' => __( 'Valid -- click Save to apply', self::TEXT_DOMAIN ),
				)
			);
			return;
		} catch ( SpreadconnectClientError $e ) {
			// AC-5: 4xx / `auth_missing` / pre-flight failure. The exception
			// message can include path + status (slice-07 log format) — we
			// must not pass it through to the browser (no API-detail leak,
			// AC-11). Hard-coded UX string from wireframes.md Z. 622 / Z. 637.
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Invalid Key -- check value or environment', self::TEXT_DOMAIN ),
				)
			);
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-6: 5xx / 429 / network. No inner-retry loop — slice-08
			// already does the single 429 retry inside `request()`; another
			// retry here would just block the user's button-press longer.
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'Connection failed -- please try again', self::TEXT_DOMAIN ),
				)
			);
			return;
		}
	}
}
