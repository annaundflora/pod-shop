/**
 * Spreadconnect Product-Edit meta-box client-side controller (slice-34).
 *
 * Responsibilities:
 *
 *   1. 300 ms-debounced article picker — types into the search input and
 *      POSTs to `admin-ajax.php?action=spreadconnect_search_articles`,
 *      rendering the result list in `#sc-picker-results`.
 *   2. Live margin recalculation — listens to changes on
 *      `#_regular_price`, `#_sale_price` and any variation-price input,
 *      reads the cost from `data-sc-cost` on the meta-box wrapper, and
 *      updates `#sc-margin-value` with the formatted value plus a
 *      red/yellow/green class.
 *   3. Click handlers for the four meta-box actions — link, unlink,
 *      refresh-stock, re-sync. The link/unlink/re-sync handlers reload
 *      the page on success so the meta-box reflects the new state; the
 *      refresh-stock handler patches the table in place.
 *
 * Localized object: `spreadconnectProductMetaBox = {ajaxUrl, nonce, productId}`
 *
 * Stylistically follows slice-32 order-meta-box.js — vanilla JS + jQuery
 * `.ready()` bootstrap, no build step.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.spreadconnectProductMetaBox === 'undefined' ) {
		return;
	}

	var config = window.spreadconnectProductMetaBox;

	var DEBOUNCE_MS = 300;

	// Margin thresholds (slice-34 Constraints, exact 20.0 / 40.0 cut-offs).
	var MARGIN_THRESHOLD_LOW = 20.0;
	var MARGIN_THRESHOLD_HIGH = 40.0;

	$( function () {
		var $box = $( '#spreadconnect_product_meta_box' );
		if ( $box.length === 0 ) {
			return;
		}

		bindPicker( $box );
		bindMarginRecalc( $box );
		bindActions( $box );

		// Initial margin paint so the rendered "—" is replaced as soon as
		// the page loads (the WC price input is already populated).
		recomputeMargin( $box );
	} );

	// ------------------------------------------------------------------
	// Picker
	// ------------------------------------------------------------------

	function bindPicker( $box ) {
		var $search = $box.find( '#sc-picker-search' );
		var $results = $box.find( '#sc-picker-results' );
		if ( $search.length === 0 || $results.length === 0 ) {
			return;
		}

		var debounced = debounce( function () {
			searchArticles( $search.val(), $results );
		}, DEBOUNCE_MS );

		$search.on( 'input', debounced );
		$search.on( 'focus', function () {
			if ( $results.children().length === 0 ) {
				searchArticles( $search.val(), $results );
			}
		} );

		// Click delegate for "Link this article" buttons inside picker
		// result cards (rendered dynamically by `renderPickerResults`).
		$results.on( 'click', '.sc-picker-link', function ( ev ) {
			ev.preventDefault();
			var articleId = $( this ).data( 'article-id' );
			if ( articleId ) {
				linkArticle( String( articleId ) );
			}
		} );
	}

	function searchArticles( rawValue, $results ) {
		var value = ( rawValue || '' ).toString().trim();

		$results.html( '<p class="description">' + textLoading() + '</p>' );

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'spreadconnect_search_articles',
				nonce: config.nonce,
				search: value,
			},
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					$results.html( '<p class="description">' + textNoResults() + '</p>' );
					return;
				}
				renderPickerResults( $results, response.data.items || [], response.data.total || 0 );
			} )
			.fail( function () {
				$results.html( '<p class="description">' + textPickerError() + '</p>' );
			} );
	}

	function renderPickerResults( $results, items, total ) {
		if ( ! items.length ) {
			$results.html( '<p class="description">' + textNoResults() + '</p>' );
			return;
		}

		var fragments = [];
		for ( var i = 0; i < items.length; i++ ) {
			fragments.push( renderPickerCard( items[ i ] ) );
		}

		fragments.push(
			'<p class="description sc-picker-total">' +
				escapeHtml(
					'Showing ' + items.length + ' of ' + ( total || items.length )
				) +
				'</p>'
		);

		$results.html( fragments.join( '' ) );
	}

	function renderPickerCard( item ) {
		var sizes = parseInt( item.sizes, 10 ) || 0;
		var colors = parseInt( item.colors, 10 ) || 0;
		var variantSummary = sizes + ' sizes × ' + colors + ' colors';

		var thumb = '';
		if ( item.thumbUrl ) {
			thumb =
				'<img class="sc-picker-thumb" src="' +
				escapeAttr( item.thumbUrl ) +
				'" alt="" width="48" height="48" />';
		}

		return (
			'<div class="sc-picker-card" data-article-id="' +
			escapeAttr( item.id ) +
			'">' +
			thumb +
			'<div class="sc-picker-body">' +
			'<strong class="sc-picker-title">' +
			escapeHtml( item.title || '' ) +
			'</strong>' +
			'<div class="sc-picker-meta description">' +
			escapeHtml( 'Article-ID: ' + ( item.id || '' ) + ' · ' + variantSummary ) +
			'</div>' +
			'<button type="button" class="button button-primary sc-picker-link" data-article-id="' +
			escapeAttr( item.id ) +
			'">' +
			escapeHtml( textLink() ) +
			'</button>' +
			'</div>' +
			'</div>'
		);
	}

	// ------------------------------------------------------------------
	// Margin recompute
	// ------------------------------------------------------------------

	function bindMarginRecalc( $box ) {
		// WC parent-product simple/regular price inputs.
		$( document ).on(
			'input change',
			'#_regular_price, #_sale_price',
			function () {
				recomputeMargin( $box );
			}
		);

		// WC variation price inputs (added/removed dynamically when the
		// "Variations" tab is interacted with).
		$( document ).on(
			'input change',
			'input.wc_input_price[name^="variable_regular_price"], input.wc_input_price[name^="variable_sale_price"]',
			function () {
				recomputeMargin( $box );
			}
		);
	}

	function recomputeMargin( $box ) {
		var $value = $box.find( '#sc-margin-value' );
		if ( $value.length === 0 ) {
			return;
		}

		var costRaw = $box.attr( 'data-sc-cost' );
		var cost = costRaw === undefined || costRaw === '' ? null : parseFloat( costRaw );

		var price = readActiveWcPrice( $box );

		// Reset wrapper class set.
		var $container = $value.closest( '.sc-block-cost-margin' );
		if ( $container.length === 0 ) {
			$container = $value;
		}
		$container.removeClass(
			'sc-margin-low sc-margin-mid sc-margin-high sc-margin-unknown'
		);

		if ( cost === null || isNaN( cost ) || price === null || isNaN( price ) || price <= 0 ) {
			$value.text( '—' );
			$container.addClass( 'sc-margin-unknown' );
			return;
		}

		var marginEur = price - cost;
		var marginPct = ( marginEur / price ) * 100;

		$value.text( formatMargin( marginEur, marginPct ) );

		var cls;
		if ( marginPct < MARGIN_THRESHOLD_LOW ) {
			cls = 'sc-margin-low';
		} else if ( marginPct <= MARGIN_THRESHOLD_HIGH ) {
			cls = 'sc-margin-mid';
		} else {
			cls = 'sc-margin-high';
		}
		$container.addClass( cls );
	}

	function readActiveWcPrice( $box ) {
		// Priority order: sale price (when set), regular price, the
		// initial server-rendered price (data-sc-initial-price).
		var $sale = $( '#_sale_price' );
		if ( $sale.length && $sale.val() ) {
			var sale = parseFloat( normalizeDecimal( $sale.val() ) );
			if ( ! isNaN( sale ) ) {
				return sale;
			}
		}

		var $regular = $( '#_regular_price' );
		if ( $regular.length && $regular.val() ) {
			var reg = parseFloat( normalizeDecimal( $regular.val() ) );
			if ( ! isNaN( reg ) ) {
				return reg;
			}
		}

		// Variation price fallback: first non-empty variation regular
		// price input on the page.
		var $varPrice = $(
			'input.wc_input_price[name^="variable_regular_price"]'
		).filter( function () {
			return !! $( this ).val();
		} );
		if ( $varPrice.length ) {
			var v = parseFloat( normalizeDecimal( $varPrice.first().val() ) );
			if ( ! isNaN( v ) ) {
				return v;
			}
		}

		// Initial render value (server-side resolved from `WC_Product::get_price()`).
		var $initial = $box.find( '.sc-price-value' );
		var initialAttr = $initial.attr( 'data-sc-initial-price' );
		if ( initialAttr ) {
			var init = parseFloat( normalizeDecimal( initialAttr ) );
			if ( ! isNaN( init ) ) {
				return init;
			}
		}

		return null;
	}

	function formatMargin( marginEur, marginPct ) {
		var eur = ( Math.round( marginEur * 100 ) / 100 ).toFixed( 2 );
		var pct = ( Math.round( marginPct * 10 ) / 10 ).toFixed( 1 );
		return eur + ' EUR (' + pct + '%)';
	}

	function normalizeDecimal( value ) {
		// WC may use either `.` or `,` as decimal separator depending on
		// locale (config'd via `woocommerce_price_decimal_sep`). Normalize
		// to `.` for `parseFloat`.
		return ( value || '' ).toString().replace( ',', '.' );
	}

	// ------------------------------------------------------------------
	// Action click handlers (link / unlink / refresh-stock / resync)
	// ------------------------------------------------------------------

	function bindActions( $box ) {
		$box.on( 'click', '.sc-action-unlink', function ( ev ) {
			ev.preventDefault();
			if ( ! window.confirm( textConfirmUnlink() ) ) {
				return;
			}
			unlinkArticle();
		} );

		$box.on( 'click', '.sc-action-refresh-stock', function ( ev ) {
			ev.preventDefault();
			refreshStock( $box );
		} );

		$box.on( 'click', '.sc-action-resync', function ( ev ) {
			ev.preventDefault();
			var articleId = $box.attr( 'data-sc-article-id' );
			if ( ! articleId ) {
				return;
			}
			linkArticle( String( articleId ), { force: true } );
		} );
	}

	function linkArticle( articleId, options ) {
		var force = !! ( options && options.force );
		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'spreadconnect_link_article',
				nonce: config.nonce,
				product_id: config.productId,
				article_id: articleId,
				force: force ? 1 : 0,
			},
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					window.alert( extractErrorMessage( response, textLinkError() ) );
				}
			} )
			.fail( function ( xhr ) {
				window.alert( extractErrorMessageFromXhr( xhr, textLinkError() ) );
			} );
	}

	function unlinkArticle() {
		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'spreadconnect_unlink_article',
				nonce: config.nonce,
				product_id: config.productId,
			},
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					window.alert( extractErrorMessage( response, textUnlinkError() ) );
				}
			} )
			.fail( function ( xhr ) {
				window.alert( extractErrorMessageFromXhr( xhr, textUnlinkError() ) );
			} );
	}

	function refreshStock( $box ) {
		var $btn = $box.find( '.sc-action-refresh-stock' );
		$btn.prop( 'disabled', true );

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'spreadconnect_refresh_stock',
				nonce: config.nonce,
				product_id: config.productId,
			},
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					window.alert( extractErrorMessage( response, textRefreshError() ) );
					return;
				}
				updateStockTable( $box, response.data.stock || {}, response.data.refreshed_at || 0 );
			} )
			.fail( function ( xhr ) {
				window.alert( extractErrorMessageFromXhr( xhr, textRefreshError() ) );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	function updateStockTable( $box, stockMap, refreshedAt ) {
		$box.find( '.sc-stock-table tbody tr' ).each( function () {
			var $row = $( this );
			var sku = $row.attr( 'data-sc-sku' );
			if ( ! sku || ! Object.prototype.hasOwnProperty.call( stockMap, sku ) ) {
				return;
			}
			var qty = parseInt( stockMap[ sku ], 10 );
			var $badge = $row.find( '.sc-stock-badge' );
			$badge.removeClass(
				'sc-stock-loading sc-stock-out sc-stock-low sc-stock-available'
			);

			if ( isNaN( qty ) || qty <= 0 ) {
				$badge.addClass( 'sc-stock-out' ).text( '✗ Out' );
			} else if ( qty < 10 ) {
				$badge.addClass( 'sc-stock-low' ).text( '⚠ Low (' + qty + ')' );
			} else {
				$badge.addClass( 'sc-stock-available' ).text( '● Available' );
			}
		} );

		if ( refreshedAt > 0 ) {
			var d = new Date( refreshedAt * 1000 );
			var label =
				pad2( d.getHours() ) + ':' + pad2( d.getMinutes() );
			$box.find( '.sc-stock-meta' ).text( 'Last refresh: ' + label );
		}
	}

	// ------------------------------------------------------------------
	// Utility helpers
	// ------------------------------------------------------------------

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var context = this;
			var args = arguments;
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( function () {
				timer = null;
				fn.apply( context, args );
			}, wait );
		};
	}

	function pad2( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function escapeHtml( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escapeAttr( str ) {
		return escapeHtml( str );
	}

	function extractErrorMessage( response, fallback ) {
		if (
			response &&
			response.data &&
			typeof response.data === 'object' &&
			response.data.message
		) {
			return String( response.data.message );
		}
		return fallback;
	}

	function extractErrorMessageFromXhr( xhr, fallback ) {
		try {
			var json = JSON.parse( xhr.responseText );
			return extractErrorMessage( json, fallback );
		} catch ( _e ) {
			return fallback;
		}
	}

	// String stubs — kept inline rather than threaded through wp_localize
	// so the file ships as a single asset. Translatable copies are owned
	// by the server-rendered markup.
	function textLoading() {
		return 'Loading articles…';
	}
	function textNoResults() {
		return 'No articles match your search.';
	}
	function textPickerError() {
		return 'Search failed — please try again.';
	}
	function textLink() {
		return 'Link this article';
	}
	function textConfirmUnlink() {
		return 'Unlink this product from Spreadconnect?';
	}
	function textLinkError() {
		return 'Could not link the article.';
	}
	function textUnlinkError() {
		return 'Could not unlink the article.';
	}
	function textRefreshError() {
		return 'Could not refresh stock.';
	}
} )( window.jQuery );
