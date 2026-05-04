/**
 * Failed-Ops Bulk-Action handler (slice-40 AC-13 / AC-14).
 *
 * Bound via event-delegation on `document`. Reads the localised payload
 * `window.spreadconnectFailedOpsBulk` (`ajaxUrl`, `nonce`, `actions.*`,
 * `i18n.*`) emitted by `Hub\View\FailedOps::enqueueBulkAsset()`.
 *
 * Behaviour matrix:
 *   - `[data-bulk-select-all]` change   -> toggles all per-row checkboxes.
 *   - `[data-action="bulk-apply"]` click -> reads the bulk-select-value,
 *                                            collects all checkbox'd
 *                                            `data-bulk-row-id` values,
 *                                            POSTs to the matching AJAX
 *                                            action (`spreadconnect_bulk_resend_failed_op`
 *                                            or `spreadconnect_bulk_dismiss_failed_op`).
 *   - On `ok:true` (resend) -> render success banner + remove `requeued`-rows.
 *   - On `ok:false` + `code='create_order_in_selection'` -> render error banner
 *     + highlight blocked rows (CSS-class `spreadconnect-row-blocked`).
 *     Selection retained.
 *   - On empty selection -> alert with `i18n.noSelection`, no AJAX call.
 *
 * Slice-38 (`failed-ops-modal.js`) bleibt unveraendert — dieses Asset
 * registriert sich auf disjunkten Selektoren (`[data-bulk-*]`,
 * `[data-action="bulk-apply"]`, `[data-panel="bulk_outcome"]`).
 */
(function ($) {
	'use strict';

	if (!$ || typeof $ !== 'function') {
		return;
	}

	var settings = window.spreadconnectFailedOpsBulk || {};
	var i18n = settings.i18n || {};
	var actions = settings.actions || {};

	var TABLE_SELECTOR = '.spreadconnect-failed-ops__table';
	var OUTCOME_SELECTOR = '[data-panel="bulk_outcome"]';

	/**
	 * Read the currently-selected bulk-action <select> value.
	 *
	 * @returns {string}
	 */
	function readBulkAction() {
		var sel = $('#spreadconnect-failed-ops-bulk-action');
		if (sel.length === 0) {
			return '';
		}
		return (sel.val() || '').toString();
	}

	/**
	 * Collect all checked per-row IDs.
	 *
	 * @returns {Array<number>}
	 */
	function readSelectedRowIds() {
		var ids = [];
		$(TABLE_SELECTOR + ' [data-bulk-row-id]').each(function () {
			var box = $(this);
			if (!box.is(':checked')) {
				return;
			}
			var raw = box.attr('data-bulk-row-id') || '';
			var id = parseInt(raw, 10);
			if (id > 0) {
				ids.push(id);
			}
		});
		return ids;
	}

	/**
	 * Render the bulk-outcome banner inside the [data-panel="bulk_outcome"]
	 * container.
	 *
	 * @param {string} state    'success' | 'blocked'
	 * @param {string} message
	 */
	function renderBanner(state, message) {
		var panel = $(OUTCOME_SELECTOR);
		if (panel.length === 0) {
			return;
		}

		var noticeClass = state === 'blocked' ? 'notice notice-error' : 'notice notice-success';
		var stateClass = state === 'blocked' ? 'spreadconnect-bulk-blocked' : 'spreadconnect-bulk-outcome';

		var html =
			'<div class="' + noticeClass + ' ' + stateClass + '">' +
			'<p></p>' +
			'</div>';

		panel.empty().append(html);
		panel.find('p').text(message || '');
		panel.prop('hidden', false);
		panel.removeAttr('hidden');
	}

	/**
	 * Highlight (and clear previous highlights) the blocked rows.
	 *
	 * @param {Array<number>} blockedIds
	 */
	function highlightBlockedRows(blockedIds) {
		// Clear previous highlights.
		$(TABLE_SELECTOR + ' tr.spreadconnect-row-blocked').removeClass('spreadconnect-row-blocked');

		if (!blockedIds || !blockedIds.length) {
			return;
		}

		for (var i = 0; i < blockedIds.length; i++) {
			var id = blockedIds[i];
			$('tr[data-row-id="' + id + '"]').addClass('spreadconnect-row-blocked');
		}
	}

	/**
	 * Remove the rows that were successfully re-queued from the DOM.
	 *
	 * @param {object} perRow
	 */
	function removeRequeuedRows(perRow) {
		if (!perRow || typeof perRow !== 'object') {
			return;
		}
		Object.keys(perRow).forEach(function (rowId) {
			if (perRow[rowId] === 'requeued') {
				$('tr[data-row-id="' + rowId + '"]').remove();
				$('#spreadconnect-failed-ops-detail-' + rowId).remove();
			}
		});
	}

	/**
	 * Plain AJAX POST helper.
	 *
	 * @param {string}        action
	 * @param {Array<number>} ids
	 * @returns {jQuery.Deferred}
	 */
	function postAction(action, ids) {
		var payload = {
			action: action,
			_ajax_nonce: settings.nonce || '',
			failed_op_ids: ids
		};
		return $.post(settings.ajaxUrl || window.ajaxurl, payload, null, 'json');
	}

	/**
	 * Format a banner template like '%1$d of %2$d re-queued, %3$d skipped'.
	 *
	 * @param {string} tpl
	 * @param {object} values
	 * @returns {string}
	 */
	function formatBannerSuccess(tpl, queued, total, skipped) {
		var out = (tpl || '%1$d of %2$d re-queued, %3$d skipped')
			.replace('%1$d', String(queued))
			.replace('%2$d', String(total))
			.replace('%3$d', String(skipped));
		return out;
	}

	/**
	 * Resend handler.
	 */
	function applyResend(ids) {
		postAction(actions.bulkResend || 'spreadconnect_bulk_resend_failed_op', ids)
			.done(function (response) {
				if (!response || !response.success) {
					var msg = (response && response.data && response.data.message) || '';
					renderBanner('blocked', msg);
					return;
				}
				var data = response.data || {};
				var banner = data.banner;
				if (!banner) {
					banner = formatBannerSuccess(
						i18n.bannerSuccessTpl,
						data.queued || 0,
						ids.length,
						data.skipped || 0
					);
				}
				removeRequeuedRows(data.per_row || {});
				renderBanner('success', banner);
			})
			.fail(function () {
				renderBanner('blocked', i18n.noSelection || '');
			});
	}

	/**
	 * Dismiss handler.
	 */
	function applyDismiss(ids) {
		var confirmText = i18n.confirmDismiss || 'Dismiss selected entries?';
		if (window.confirm && !window.confirm(confirmText)) {
			return;
		}

		postAction(actions.bulkDismiss || 'spreadconnect_bulk_dismiss_failed_op', ids)
			.done(function (response) {
				if (!response || !response.success) {
					var data = (response && response.data) || {};
					var blockedIds = data.blocked_ids || [];
					var message = data.message || '';
					highlightBlockedRows(blockedIds);
					renderBanner('blocked', message);
					return;
				}
				var okData = response.data || {};
				var dismissed = okData.dismissed || 0;

				// Remove dismissed rows from DOM (they are no longer unresolved).
				ids.forEach(function (id) {
					$('tr[data-row-id="' + id + '"]').remove();
					$('#spreadconnect-failed-ops-detail-' + id).remove();
				});

				renderBanner(
					'success',
					formatBannerSuccess(
						i18n.bannerSuccessTpl,
						dismissed,
						ids.length,
						ids.length - dismissed
					)
				);
			})
			.fail(function () {
				renderBanner('blocked', i18n.noSelection || '');
			});
	}

	// =========================================================================
	// Wiring
	// =========================================================================

	$(function () {
		var doc = $(document);

		// Select-all toggle.
		doc.on('change', '[data-bulk-select-all]', function () {
			var checked = $(this).is(':checked');
			$(TABLE_SELECTOR + ' [data-bulk-row-id]').prop('checked', checked);
		});

		// Apply button.
		doc.on('click', '[data-action="bulk-apply"]', function (event) {
			event.preventDefault();

			var ids = readSelectedRowIds();
			if (ids.length === 0) {
				if (window.alert) {
					window.alert(i18n.noSelection || 'Please select at least one row.');
				}
				return;
			}

			var bulkAction = readBulkAction();
			if (!bulkAction) {
				if (window.alert) {
					window.alert(i18n.noSelection || 'Please select an action.');
				}
				return;
			}

			if (bulkAction === (actions.bulkResend || 'spreadconnect_bulk_resend_failed_op')) {
				applyResend(ids);
				return;
			}

			if (bulkAction === (actions.bulkDismiss || 'spreadconnect_bulk_dismiss_failed_op')) {
				applyDismiss(ids);
				return;
			}
		});
	});
}(window.jQuery));
