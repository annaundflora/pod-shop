<?php
/**
 * Admin-AJAX handler for the Catalog sub-page "Sync now" button (slice-26).
 *
 * Hooked at `wp_ajax_spreadconnect_sync_now`. The handler validates the
 * caller capability + nonce, then enqueues a one-shot
 * `spreadconnect/sync_catalog` Action-Scheduler job with `trigger='manual'`.
 * The actual catalog producer (slice-24 `SyncCatalogJob`) runs out-of-band on
 * the next AS tick; this handler returns immediately with `state='queued'`.
 *
 * The optional `run_id` field in the response is reserved for the case where
 * a future implementation pre-creates the history row inline. In slice-26
 * the producer creates the row only when the worker first ticks, so the
 * field is always `null` here — the frontend poller falls back to the
 * `?run_id=` default-active-run resolution (slice-26 AC-8) until the row
 * appears.
 *
 * Behaviour summary:
 *   - cap gate `manage_woocommerce` via `Hub\Controller::ensureCapability()`,
 *   - nonce gate `spreadconnect_sync_now`,
 *   - `as_enqueue_async_action('spreadconnect/sync_catalog',
 *      ['trigger'=>'manual'], 'spreadconnect')`,
 *   - `wp_send_json_success(['run_id'=>null, 'state'=>'queued'])`.
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Hub\Controller as HubController;

/**
 * Stateless front-controller for the `spreadconnect_sync_now` AJAX action.
 *
 * Final + only static methods, mirroring the slice-12 `TestConnection`
 * pattern. The handler does not own any cross-request state — every call
 * is a fresh enqueue.
 */
final class SyncNow
{
	/**
	 * AJAX action slug + nonce-action string. Must match the
	 * `wp_create_nonce()` call site in {@see \SpreadconnectPod\Hub\View\Catalog}.
	 *
	 * Slice-26 Constraints: "Nonce-Action-Name: exakt
	 * `spreadconnect_sync_now` (`wp_create_nonce` im Catalog-Markup und
	 * `check_ajax_referer` im Handler muessen denselben String nutzen)".
	 */
	public const NONCE_ACTION = 'spreadconnect_sync_now';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_ajax_nonce';

	/**
	 * Action-Scheduler hook the producer is registered against (slice-24
	 * `Bootstrap\Plugin::init` mounts the consumer-side handler).
	 */
	private const AS_HOOK = 'spreadconnect/sync_catalog';

	/**
	 * Action-Scheduler group name. Matches slice-24 enqueue-pattern so all
	 * catalog-related actions land in the same AS group for QA filtering.
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * Plugin text-domain for `__()` wrappers.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Register the `wp_ajax_spreadconnect_sync_now` hook.
	 *
	 * Called from `Bootstrap\Plugin::init()` (mount-point per slice-26 AC-12).
	 * Only the authenticated variant is registered — no `wp_ajax_nopriv_*`
	 * because anonymous callers must not be able to enqueue catalog syncs.
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
	 * Handle a single `spreadconnect_sync_now` AJAX call.
	 *
	 * Terminates the request via `wp_send_json_success()` /
	 * `wp_send_json_error()` — never returns a normal value to the caller.
	 *
	 * Hard-gates (slice-26 AC-5):
	 *   1. `Hub\Controller::ensureCapability()` — `wp_die()` on missing cap.
	 *      Note this helper does not return on failure, so subsequent
	 *      branches only execute for capability-passed callers.
	 *   2. `check_ajax_referer( 'spreadconnect_sync_now', '_ajax_nonce',
	 *      false )` — explicit `false` 3rd arg keeps the helper from
	 *      `wp_die()`-ing so we can shape a uniform JSON 403 response.
	 *
	 * Both gates run BEFORE `as_enqueue_async_action()` so a forged or
	 * insufficiently-privileged request can never produce a real enqueue.
	 *
	 * @return void
	 */
	public static function handle(): void
	{
		// AC-5: cap gate. The shared helper terminates via `wp_die()` on
		// failure, but in tests it is patched to return a value — guard
		// defensively by re-checking the predicate so a non-terminating
		// stub cannot fall through to the enqueue.
		HubController::ensureCapability();

		// AC-5: nonce gate. `false` 3rd arg → helper returns 0/false on
		// missing/expired nonce instead of `wp_die()`-ing; we coerce to
		// bool and shape a 403 JSON response uniformly with the cap path.
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

		// AC-4: enqueue exactly once per call. Args-array uses
		// `trigger='manual'` per architecture.md Z. 142 ("Hub-Dashboard /
		// Catalog → Sync Now → trigger=manual"). The 3rd arg is the AS
		// group, kept identical to slice-24's enqueues so all catalog
		// actions appear together in the AS UI.
		as_enqueue_async_action(
			self::AS_HOOK,
			array( 'trigger' => 'manual' ),
			self::AS_GROUP
		);

		// AC-4: response shape. `run_id=null` is correct here — the
		// history row is inserted by the worker on first tick, not by the
		// enqueue path. The frontend poller falls back to the
		// active-run default-resolution until the row appears.
		wp_send_json_success(
			array(
				'run_id' => null,
				'state'  => 'queued',
			)
		);
	}
}
