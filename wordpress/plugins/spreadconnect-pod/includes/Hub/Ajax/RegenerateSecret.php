<?php
/**
 * Admin-AJAX handlers for the webhook-secret one-time-reveal lifecycle (Slice 14).
 *
 * Two related actions live in this single class because they share the same
 * nonce, the same capability gate, and a single coupled UX flow (regenerate
 * the secret -> show it once -> user clicks `[Done]` -> permanently
 * UI-lock the panel):
 *
 *   - `wp_ajax_spreadconnect_regenerate_secret`
 *       -> `WebhookSecretManager::regenerate()` -> reveal payload (plaintext).
 *
 *   - `wp_ajax_spreadconnect_acknowledge_initial_reveal`
 *       -> writes `spreadconnect_webhook_secret_revealed_at = time()` and
 *          deletes the short-lived `spreadconnect_initial_secret_reveal`
 *          transient. Idempotent: subsequent calls become no-ops because
 *          the lock flag is one-way (slice-14 Constraints).
 *
 * Hard gates (architecture.md Z. 484): `manage_woocommerce` capability via
 * `Hub\Controller::ensureCapability()` (slice-13 provides) + nonce
 * `spreadconnect_secret_action` shared by both sub-actions (single source
 * of truth for the JS client). On any gate-miss the handler terminates via
 * `wp_send_json_error([...], 403)` BEFORE any secret is generated.
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Hub\Controller as HubController;
use SpreadconnectPod\Subscription\WebhookSecretManager;

/**
 * Stateless front-controller for the two webhook-secret AJAX actions.
 *
 * Final + only static methods — pattern-mirror of slice-12 `TestConnection`
 * for consistency with the rest of `Hub\Ajax`.
 */
final class RegenerateSecret
{
	/**
	 * AJAX action slug for "regenerate the HMAC secret now".
	 */
	private const ACTION_REGENERATE = 'spreadconnect_regenerate_secret';

	/**
	 * AJAX action slug for "user clicked [Done] on the initial reveal panel".
	 */
	private const ACTION_ACKNOWLEDGE = 'spreadconnect_acknowledge_initial_reveal';

	/**
	 * Shared nonce action — single source of truth for both sub-actions
	 * (slice-14 Constraints "Capability + Nonce"). The Settings-view markup
	 * mints exactly one `wp_create_nonce( 'spreadconnect_secret_action' )`
	 * and hands the same value to both buttons.
	 */
	private const NONCE_ACTION = 'spreadconnect_secret_action';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_ajax_nonce';

	/**
	 * Transient that carries the freshly-minted plaintext from the AJAX
	 * regenerate-handler to the next Settings-page render. Five-minute TTL
	 * mirrors the slice-14 Constraints — kept short because the plaintext
	 * lives in `wp_options` (object cache + DB), not just in memory.
	 */
	private const TRANSIENT_INITIAL_REVEAL = 'spreadconnect_initial_secret_reveal';

	/**
	 * Plugin text-domain for `__()` wrappers. Must match slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Register both `wp_ajax_*` actions.
	 *
	 * Called from `Bootstrap\Plugin::init()`. Only the authenticated variant
	 * is registered — anonymous callers must never be able to rotate the
	 * secret or flip the reveal-lock.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action(
			'wp_ajax_' . self::ACTION_REGENERATE,
			array( self::class, 'handleRegenerate' )
		);
		add_action(
			'wp_ajax_' . self::ACTION_ACKNOWLEDGE,
			array( self::class, 'handleAcknowledge' )
		);
	}

	/**
	 * Handle a `spreadconnect_regenerate_secret` AJAX call.
	 *
	 * Terminates via `wp_send_json_*`. Hard gates run first; only when both
	 * pass does the manager actually rotate the secret. The plaintext is
	 * present **once** in the success response and nowhere else (no log
	 * line, no second read, no transient leakage).
	 *
	 * @return void
	 */
	public static function handleRegenerate(): void
	{
		// AC-5: capability hard-gate via slice-13 helper. The helper itself
		// `wp_die()`s on a missing cap, which is the wrong shape for an AJAX
		// response — so we mirror its check here for an early
		// `wp_send_json_error` and skip the helper for this branch only.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return; // Defensive — wp_send_json_* terminates, but tests stub it.
		}

		// AC-5: nonce hard-gate. `false` keeps `check_ajax_referer` from
		// `wp_die()`-ing so we can shape the JSON error uniformly.
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

		// AC-6: rotate + respond with the plaintext exactly once.
		$payload = WebhookSecretManager::regenerate();

		wp_send_json_success(
			array(
				'secret'       => $payload['secret'],
				'generated_at' => $payload['generated_at'],
				'is_initial'   => $payload['is_initial'],
			)
		);
	}

	/**
	 * Handle a `spreadconnect_acknowledge_initial_reveal` AJAX call.
	 *
	 * Writes `spreadconnect_webhook_secret_revealed_at = time()` (one-way
	 * UI lock, slice-14 AC-8) and deletes the
	 * `spreadconnect_initial_secret_reveal` transient. Re-runs are
	 * idempotent: a second call sees a non-zero revealed_at and a
	 * non-existent transient, both branches no-op.
	 *
	 * @return void
	 */
	public static function handleAcknowledge(): void
	{
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

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

		update_option( WebhookSecretManager::OPTION_REVEALED_AT, (int) time() );
		delete_transient( self::TRANSIENT_INITIAL_REVEAL );

		wp_send_json_success(
			array(
				'ok' => true,
			)
		);
	}
}
