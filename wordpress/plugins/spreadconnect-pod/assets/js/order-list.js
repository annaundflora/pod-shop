/**
 * Spreadconnect Order-List bulk-resend client controller (slice-33).
 *
 * Responsibilities:
 *
 *   1. When the WC bulk-action drop-down switches to
 *      `'spreadconnect_bulk_resend'`, POST the current selection of
 *      order-ids to the server-side pre-flight endpoint
 *      (`SpreadconnectOrderList.actions.preflight`).
 *
 *   2. Render the pre-flight banner above the [Apply] button:
 *
 *         "Will re-send: 2 · Will skip: 8"
 *
 *      (Wireframe Screen 12 — `bulk_action_selected_with_ineligible`,
 *      Z. 1047). When zero eligible orders are in the selection a
 *      "no-eligible" hint is rendered.
 *
 *   3. Mark ineligible rows visually `greyed` so the admin sees which
 *      rows will be skipped (CSS class `sc-row-ineligible`).
 *
 * Localized object: `SpreadconnectOrderList = {ajaxUrl, nonce, actions,
 * bulkActionSlug, i18n}`.
 *
 * Stylistically mirrors slice-32 order-meta-box.js — vanilla JS + jQuery
 * `.ready()` bootstrap, no build step. Slice 40 will extend this file
 * with the per-row outcome `[Show details ▾]` toggle.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.SpreadconnectOrderList === 'undefined' ) {
		return;
	}

	var config = window.SpreadconnectOrderList;

	$( function () {
		// Both the top and bottom bulk-actions selects are present on the
		// list table — bind once for each.
		var $bulkSelects = $( 'select[name="action"], select[name="action2"]' );
		if ( $bulkSelects.length === 0 ) {
			return;
		}

		var $banner = ensureBanner();

		$bulkSelects.on( 'change', function () {
			handleBulkSelectionChange( $bulkSelects, $banner );
		} );
	} );

	// ------------------------------------------------------------------
	// Banner element creation
	// ------------------------------------------------------------------

	function ensureBanner() {
		var existing = $( '#spreadconnect-bulk-preflight' );
		if ( existing.length > 0 ) {
			return existing;
		}

		var $banner = $( '<div/>', {
			id:    'spreadconnect-bulk-preflight',
			class: 'spreadconnect-bulk-preflight notice notice-info inline',
			style: 'display:none; margin: 6px 0;'
		} ).append( $( '<p/>' ) );

		// Insert before the bulk-actions form/wrapper if present, else
		// before the first list-table on the page.
		var $anchor = $( '.tablenav.top' ).first();
		if ( $anchor.length === 0 ) {
			$anchor = $( '.wp-list-table' ).first();
		}

		if ( $anchor.length > 0 ) {
			$anchor.before( $banner );
		} else {
			$( 'body' ).prepend( $banner );
		}

		return $banner;
	}

	// ------------------------------------------------------------------
	// Bulk-selection change handler
	// ------------------------------------------------------------------

	function handleBulkSelectionChange( $bulkSelects, $banner ) {
		var selectedAction = '';
		$bulkSelects.each( function () {
			var val = $( this ).val();
			if ( typeof val === 'string' && val !== '' && val !== '-1' ) {
				selectedAction = val;
			}
		} );

		if ( selectedAction !== config.bulkActionSlug ) {
			hideBanner( $banner );
			clearRowMarks();
			return;
		}

		var orderIds = collectSelectedOrderIds();
		if ( orderIds.length === 0 ) {
			showBannerMessage( $banner, ( config.i18n && config.i18n.preflightZero ) || '' );
			return;
		}

		showBannerMessage( $banner, ( config.i18n && config.i18n.preflightLoading ) || '' );

		fetchPreflight( orderIds )
			.done( function ( response ) {
				renderPreflightResult( $banner, response, orderIds );
			} )
			.fail( function () {
				showBannerMessage( $banner, ( config.i18n && config.i18n.preflightError ) || '' );
			} );
	}

	function collectSelectedOrderIds() {
		var ids = [];

		$( 'tbody input[type="checkbox"][name="id[]"]:checked, tbody input[type="checkbox"][name="post[]"]:checked, tbody input[type="checkbox"][name="order[]"]:checked' ).each( function () {
			var raw = $( this ).val();
			var n   = parseInt( raw, 10 );
			if ( ! isNaN( n ) && n > 0 ) {
				ids.push( n );
			}
		} );

		return ids;
	}

	// ------------------------------------------------------------------
	// AJAX
	// ------------------------------------------------------------------

	function fetchPreflight( orderIds ) {
		return $.ajax( {
			url:      config.ajaxUrl,
			method:   'POST',
			dataType: 'json',
			data:     {
				action:      config.actions.preflight,
				_ajax_nonce: config.nonce,
				order_ids:   orderIds
			}
		} );
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	function renderPreflightResult( $banner, response, orderIds ) {
		if ( ! response || response.success !== true || ! response.data ) {
			showBannerMessage( $banner, ( config.i18n && config.i18n.preflightError ) || '' );
			return;
		}

		var willResend  = parseInt( response.data.will_resend, 10 ) || 0;
		var willSkip    = parseInt( response.data.will_skip, 10 ) || 0;
		var eligibleIds = ( response.data.eligible_ids && response.data.eligible_ids.length )
			? response.data.eligible_ids.map( function ( v ) { return parseInt( v, 10 ); } )
			: [];

		if ( willResend === 0 ) {
			showBannerMessage( $banner, ( config.i18n && config.i18n.preflightZero ) || '' );
			markIneligibleRows( orderIds, eligibleIds );
			return;
		}

		var template = ( config.i18n && config.i18n.preflightSummary ) || '';
		var message  = template
			.replace( '%1$d', String( willResend ) )
			.replace( '%2$d', String( willSkip ) );

		showBannerMessage( $banner, message );
		markIneligibleRows( orderIds, eligibleIds );
	}

	function markIneligibleRows( selectedIds, eligibleIds ) {
		clearRowMarks();

		var eligibleSet = {};
		for ( var i = 0; i < eligibleIds.length; i++ ) {
			eligibleSet[ eligibleIds[ i ] ] = true;
		}

		for ( var j = 0; j < selectedIds.length; j++ ) {
			var id = selectedIds[ j ];
			if ( eligibleSet[ id ] ) {
				continue;
			}
			$( 'tbody tr' )
				.has( 'input[type="checkbox"][value="' + id + '"]:checked' )
				.addClass( 'sc-row-ineligible' );
		}
	}

	function clearRowMarks() {
		$( 'tbody tr.sc-row-ineligible' ).removeClass( 'sc-row-ineligible' );
	}

	function showBannerMessage( $banner, message ) {
		if ( ! message ) {
			hideBanner( $banner );
			return;
		}
		$banner.find( 'p' ).text( message );
		$banner.show();
	}

	function hideBanner( $banner ) {
		$banner.hide();
	}
} )( window.jQuery );
