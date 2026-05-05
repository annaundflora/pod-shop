/**
 * Webhook-Log row-expand toggle (slice-41 AC-13).
 *
 * Vanilla-ES2020, no build step, no jQuery, no fetch / XMLHttpRequest.
 *
 * Click on a `.spreadconnect-row-toggle` button toggles the `hidden`
 * attribute of the detail-`<tr>` referenced via `aria-controls` and
 * flips `aria-expanded` between `'true'` and `'false'`. Multiple rows
 * may stay expanded simultaneously.
 *
 * Initialisation uses event-delegation on `document` so future
 * re-renders (e.g. dynamic admin notices) keep working without a
 * second wiring pass.
 */
(function () {
	'use strict';

	/**
	 * Toggle the `hidden` attribute + `aria-expanded` for one button click.
	 *
	 * @param {HTMLButtonElement} btn
	 */
	function toggleRow( btn ) {
		var detailId = btn.getAttribute( 'aria-controls' );
		if ( ! detailId ) {
			return;
		}

		var detail = document.getElementById( detailId );
		if ( ! detail ) {
			return;
		}

		var isHidden = detail.hasAttribute( 'hidden' );

		if ( isHidden ) {
			detail.removeAttribute( 'hidden' );
			btn.setAttribute( 'aria-expanded', 'true' );
		} else {
			detail.setAttribute( 'hidden', '' );
			btn.setAttribute( 'aria-expanded', 'false' );
		}
	}

	/**
	 * Single delegated click handler bound to `document`.
	 *
	 * @param {MouseEvent} event
	 */
	function onClick( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		// Walk up the DOM until we find a `.spreadconnect-row-toggle`
		// or run out of ancestors. `closest` is supported in every
		// browser the WP-Admin matrix allows (WP 6.0+).
		var btn = target.closest( '.spreadconnect-row-toggle' );
		if ( ! btn ) {
			return;
		}

		event.preventDefault();
		toggleRow( btn );
	}

	function init() {
		document.addEventListener( 'click', onClick );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		// DOM is already parsed (script enqueued in footer with `in_footer=true`).
		init();
	}
}());
