<?php
/**
 * Settings sub-page Developer Tools section renderer (Slice 44).
 *
 * Renders the "Developer Tools" panel inside the Hub Settings page, which
 * exposes three Simulate buttons (`Simulate Order Cancelled`,
 * `Simulate Order Processed`, `Simulate Shipment Sent`) plus a Test-Order-ID
 * input. The buttons trigger {@see \SpreadconnectPod\Hub\Ajax\SimulateEvent}
 * which dispatches the matching `SpreadconnectClient::simulate*()` wrapper
 * (slice-10) — the SC staging account then sends the corresponding webhook
 * back into the plugin, exercising the full webhook pipeline (slice-15-17 +
 * slice-30).
 *
 * Two hard gates (defense-in-depth — the AJAX handler also enforces both):
 *   1. `get_option('spreadconnect_use_staging') === true` (Section 2);
 *   2. `current_user_can('manage_woocommerce')` (Section 3).
 *
 * Both produce a clean empty render (no stub, no hidden div) so the DOM has
 * zero footprint in production. The `Hub\View\Settings::render()` method
 * fires the `spreadconnect_settings_section_dev_tools` extension action, this
 * class hooks itself onto that action via {@see self::registerHooks()}.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Hub\Ajax\SimulateEvent;

/**
 * Stateless renderer for the Developer-Tools section of the Settings page.
 *
 * Final + only static methods because the section is stateless: every call
 * reads fresh from the `spreadconnect_use_staging` option and emits inline
 * HTML to STDOUT. There is no persistence path — the test-order-id input is
 * pure form-state (no `register_setting`, no `get_option`).
 */
final class SettingsDevTools
{
	/**
	 * Plugin text-domain for `__()` wrappers. Matches slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Capability required to see the Developer-Tools section.
	 *
	 * Mirrors slice-11 AC-9 / slice-12 / slice-13 / slice-14. The check here
	 * is defense-in-depth: `Hub\View\Settings::render()` already rejects
	 * non-admin users before this section ever runs. Re-checking lets this
	 * section be hooked into other admin surfaces in the future without
	 * dropping the cap-gate.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Render the Developer-Tools section.
	 *
	 * Hooked onto `spreadconnect_settings_section_dev_tools` from
	 * {@see self::registerHooks()}. Emits NOTHING when:
	 *   - the staging toggle is off (AC-2);
	 *   - the current user lacks `manage_woocommerce` (AC-3).
	 *
	 * Both early-returns produce an empty render — no stub markup, no hidden
	 * `<div style="display:none">`. The DOM is genuinely empty so a curious
	 * source-view confirms nothing leaks to production HTML.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		// AC-2: staging-toggle gate. The render is purely cosmetic — the
		// authoritative refusal lives in
		// {@see \SpreadconnectPod\Hub\Ajax\SimulateEvent::handle()}.
		if ( true !== get_option( 'spreadconnect_use_staging', false ) ) {
			return;
		}

		// AC-3: capability defense-in-depth. The Settings page already
		// hard-gates on `manage_woocommerce`; re-checking lets this section
		// be safely re-mounted on a future admin surface.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		$nonce = wp_create_nonce( SimulateEvent::NONCE_ACTION );

		echo '<h2 class="title">' . esc_html__( 'Developer Tools', self::TEXT_DOMAIN ) . '</h2>';
		echo '<p>' . esc_html__(
			'Trigger Spreadconnect staging-only Simulate endpoints to verify the webhook pipeline. Only available while the staging toggle is enabled.',
			self::TEXT_DOMAIN
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( 'spreadconnect_dev_test_order_id' ) . '">'
			. esc_html__( 'Test Order ID', self::TEXT_DOMAIN )
			. '</label></th>';
		echo '<td>';
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
			esc_attr( 'spreadconnect_dev_test_order_id' ),
			esc_attr__( 'Spreadconnect order ID (e.g. sc_42)', self::TEXT_DOMAIN )
		);
		echo '<p class="description">' . esc_html__(
			'The Spreadconnect-side order ID to target with the Simulate call. Not persisted — form-state only.',
			self::TEXT_DOMAIN
		) . '</p>';
		echo '</td>';
		echo '</tr>';
		echo '</tbody></table>';

		echo '<p>';
		printf(
			'<button type="button" class="button" id="%1$s" data-event="%2$s" data-nonce="%3$s">%4$s</button> ',
			esc_attr( 'spreadconnect-simulate-order-cancelled' ),
			esc_attr( 'order_cancelled' ),
			esc_attr( $nonce ),
			esc_html__( 'Simulate Order Cancelled', self::TEXT_DOMAIN )
		);
		printf(
			'<button type="button" class="button" id="%1$s" data-event="%2$s" data-nonce="%3$s">%4$s</button> ',
			esc_attr( 'spreadconnect-simulate-order-processed' ),
			esc_attr( 'order_processed' ),
			esc_attr( $nonce ),
			esc_html__( 'Simulate Order Processed', self::TEXT_DOMAIN )
		);
		printf(
			'<button type="button" class="button" id="%1$s" data-event="%2$s" data-nonce="%3$s">%4$s</button>',
			esc_attr( 'spreadconnect-simulate-shipment-sent' ),
			esc_attr( 'shipment_sent' ),
			esc_attr( $nonce ),
			esc_html__( 'Simulate Shipment Sent', self::TEXT_DOMAIN )
		);
		echo '</p>';

		printf(
			'<div id="%1$s" class="spreadconnect-dev-tools-status" role="status" aria-live="polite"></div>',
			esc_attr( 'spreadconnect-dev-tools-status' )
		);

		// Inline script — same pattern as slice-12 Test-Connection markup. The
		// payload is tiny and self-contained; the `ajaxurl` global is always
		// defined by WP core in admin pages. We read the order-id input value
		// at click-time so the user can type a new id between clicks. Server-
		// supplied messages are inserted via `textContent` (NEVER `innerHTML`)
		// so a translation file containing `<` / `&` cannot become an XSS
		// vector.
		$inlineJs = <<<'JS'
(function () {
	var status = document.getElementById('spreadconnect-dev-tools-status');
	var input = document.getElementById('spreadconnect_dev_test_order_id');
	var buttons = document.querySelectorAll(
		'#spreadconnect-simulate-order-cancelled,'
		+ '#spreadconnect-simulate-order-processed,'
		+ '#spreadconnect-simulate-shipment-sent'
	);
	if (!buttons || buttons.length === 0) { return; }
	Array.prototype.forEach.call(buttons, function (btn) {
		btn.addEventListener('click', function () {
			if (btn.disabled) { return; }
			btn.disabled = true;
			if (status) {
				status.textContent = '';
				status.className = 'spreadconnect-dev-tools-status spreadconnect-dev-tools-status--pending';
			}
			var body = new URLSearchParams();
			body.append('action', 'spreadconnect_simulate_event');
			body.append('_ajax_nonce', btn.getAttribute('data-nonce') || '');
			body.append('event', btn.getAttribute('data-event') || '');
			body.append('order_id', input ? input.value : '');
			fetch(window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (resp) {
				return resp.json().catch(function () { return null; });
			}).then(function (json) {
				var data = json && json.data ? json.data : { ok: false, message: '' };
				if (status) {
					status.textContent = data.message || '';
					status.className = 'spreadconnect-dev-tools-status ' + (data.ok ? 'spreadconnect-dev-tools-status--ok' : 'spreadconnect-dev-tools-status--error');
				}
			}).catch(function () {
				if (status) {
					status.textContent = '';
					status.className = 'spreadconnect-dev-tools-status spreadconnect-dev-tools-status--error';
				}
			}).finally(function () {
				btn.disabled = false;
			});
		});
	});
})();
JS;

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $inlineJs );
		} else {
			echo '<script>' . $inlineJs . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
