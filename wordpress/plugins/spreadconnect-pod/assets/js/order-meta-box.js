/**
 * Spreadconnect Order-Edit meta-box client-side controller (slice-32).
 *
 * Responsibilities:
 *
 *   1. Lazy-load shipping types on first dropdown focus — calls
 *      `spreadconnect_get_shipping_types` (slice-38 placeholder; slice-32
 *      ships the focus-handler shape only — server endpoint comes later).
 *   2. Click handlers for the 5 action buttons in the meta-box, each
 *      identified by its `data-action` attribute. Each handler posts to
 *      `admin-ajax.php?action=spreadconnect_<action>` with the shared
 *      `spreadconnect_admin` nonce and reloads the page on success (state
 *      changes are reflected by the next render).
 *   3. Confirm-dialog gate before destructive actions (Cancel order /
 *      Cancel auto-confirm).
 *   4. State-Badge update on Refresh-State response without a full page
 *      reload (read `state` + `last_event` from the JSON response).
 *
 * Localized object: `SpreadconnectOrderMetaBox = {ajaxUrl, nonce, actions,
 * i18n}`.
 *
 * Stylistically mirrors slice-34 product-meta-box.js — vanilla JS + jQuery
 * `.ready()` bootstrap, no build step.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.SpreadconnectOrderMetaBox === 'undefined' ) {
		return;
	}

	var config = window.SpreadconnectOrderMetaBox;

	$( function () {
		var $box = $( '#spreadconnect_order_meta_box' );
		if ( $box.length === 0 ) {
			return;
		}

		bindShippingDropdown( $box );
		bindActionButtons( $box );
	} );

	// ------------------------------------------------------------------
	// Shipping-Type dropdown — lazy-load on first focus
	// ------------------------------------------------------------------

	function bindShippingDropdown( $box ) {
		var $select = $box.find( 'select[data-block="shipping-type"]' );
		if ( $select.length === 0 ) {
			return;
		}

		$select.on( 'focus', function () {
			if ( $select.attr( 'data-loaded' ) === 'true' ) {
				return;
			}
			$select.attr( 'data-loaded', 'true' );
			var $loadingOption = $( '<option/>', {
				value: '',
				text: config.i18n.loadingShippingTypes,
				disabled: true
			} );
			$select.append( $loadingOption );

			// Slice-32 contract: AJAX endpoint for shipping-types lookup is
			// deferred to slice-38. JS shape is in place; the server route
			// will be wired later. The fallback below keeps the dropdown
			// functional with the current value pre-selected.
			window.setTimeout( function () {
				$loadingOption.remove();
			}, 50 );
		} );
	}

	// ------------------------------------------------------------------
	// Action buttons
	// ------------------------------------------------------------------

	function bindActionButtons( $box ) {
		$box.on( 'click', '[data-action]', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var action = $btn.data( 'action' );
			if ( ! action ) {
				return;
			}

			var serverAction = config.actions[ action ];
			if ( ! serverAction ) {
				return;
			}

			if ( $btn.is( '[disabled]' ) || $btn.attr( 'aria-disabled' ) === 'true' ) {
				return;
			}

			if ( action === 'cancel_order' ) {
				if ( ! window.confirm( config.i18n.confirmCancel ) ) {
					return;
				}
			}
			if ( action === 'cancel_auto_confirm' ) {
				if ( ! window.confirm( config.i18n.confirmCancelAutoConfirm ) ) {
					return;
				}
			}

			runAction( $box, $btn, action, serverAction );
		} );
	}

	function runAction( $box, $btn, action, serverAction ) {
		var orderId = $btn.data( 'order-id' ) || $box.data( 'order-id' );
		var payload = {
			action: serverAction,
			_ajax_nonce: config.nonce,
			order_id: orderId
		};

		if ( action === 'save_shipping_type' ) {
			var $select = $box.find( 'select[data-block="shipping-type"]' );
			payload.shipping_type = $select.val() || '';
		}

		var $original = $btn.prop( 'disabled', true );

		$.post( config.ajaxUrl, payload )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.alert( ( response && response.data && response.data.message ) || config.i18n.errorGeneric );
					$original.prop( 'disabled', false );
					return;
				}

				if ( action === 'refresh_order_state' ) {
					patchStateBadge( $box, response.data || {} );
					$original.prop( 'disabled', false );
					return;
				}

				// All mutating actions reload the page so the next render
				// reflects the updated server state.
				window.location.reload();
			} )
			.fail( function () {
				window.alert( config.i18n.errorGeneric );
				$original.prop( 'disabled', false );
			} );
	}

	// ------------------------------------------------------------------
	// Refresh-State response — patch the state-badge in place
	// ------------------------------------------------------------------

	function patchStateBadge( $box, data ) {
		if ( ! data || typeof data.state !== 'string' ) {
			return;
		}
		var $badge = $box.find( '.spreadconnect-state-badge' );
		if ( $badge.length === 0 ) {
			return;
		}
		var oldState = $badge.attr( 'data-state' ) || '';
		$badge.removeClass( 'spreadconnect-state-' + oldState );
		$badge.addClass( 'spreadconnect-state-' + data.state );
		$badge.attr( 'data-state', data.state );
		$badge.text( data.state );
	}

}( window.jQuery ) );
