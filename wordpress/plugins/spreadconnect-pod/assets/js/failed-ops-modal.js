/**
 * Failed-Ops sub-page click + modal handler (slice-38 AC-14 / AC-15).
 *
 * Bound via event-delegation on `document`. Reads the localised payload
 * `window.spreadconnectFailedOps` (`ajaxUrl`, `nonce`, `i18n.*`) emitted by
 * `Hub\View\FailedOps::enqueueAssets()`. Uses jQuery (`jQuery.post`) so
 * `wp_send_json_success / error` payloads come back as decoded JSON without
 * an extra parser pass.
 *
 * Behaviour matrix:
 *   - `[data-action="resend"]`  → POST `spreadconnect_resend_failed_op`,
 *                                   remove the row on success, alert on error.
 *   - `[data-action="dismiss"]` and `data-needs-resolution="0"`
 *                               → confirm + POST `spreadconnect_dismiss_failed_op`,
 *                                   remove the row on success.
 *   - `[data-action="dismiss"]` and `data-needs-resolution="1"`
 *                               → open the resolution modal pre-filled with
 *                                   the row id; submit triggers
 *                                   `spreadconnect_resolve_create_order`.
 *   - `[data-action="detail"]`  → toggle the inline detail-row.
 *
 * The modal radios drive the External-ID input visibility and the Confirm
 * button's `disabled` state (AC-15). Confirm POSTs the chosen resolution +
 * `external_sc_order_id?` and removes the row on success.
 */
(function ($) {
	'use strict';

	if (!$ || typeof $ !== 'function') {
		return;
	}

	var settings = window.spreadconnectFailedOps || {};
	var i18n = settings.i18n || {};

	var MODAL_SELECTOR = '#spreadconnect-resolution-modal';
	var TABLE_SELECTOR = '.spreadconnect-failed-ops__table';

	/**
	 * Remove the data-row plus its associated detail-`<tr>` from the DOM.
	 *
	 * @param {string} rowId
	 */
	function removeRow(rowId) {
		$('tr[data-row-id="' + rowId + '"]').remove();
		$('#spreadconnect-failed-ops-detail-' + rowId).remove();
	}

	/**
	 * Show a transient toast/notice. Intentionally simple — admin-notice
	 * polish lives in slice-39 (`FailureNotifier`); slice-38 only needs a
	 * minimal acknowledgement.
	 *
	 * @param {string} message
	 */
	function notify(message) {
		if (!message) {
			return;
		}
		// Use `window.alert` as the lowest-common-denominator UX confirmation;
		// follow-up slices replace this with the WP admin-notice queue.
		try {
			window.alert(message);
		} catch (e) {
			// Tests stub window without an alert — silent fallback.
		}
	}

	/**
	 * Plain AJAX POST helper.
	 *
	 * @param {string} action  WP-AJAX action (e.g. `spreadconnect_resend_failed_op`).
	 * @param {object} data    Additional POST fields (merged in).
	 * @returns {jQuery.Deferred}
	 */
	function postAction(action, data) {
		var payload = $.extend(
			{},
			{
				action: action,
				nonce: settings.nonce || ''
			},
			data || {}
		);

		return $.post(settings.ajaxUrl || window.ajaxurl, payload, null, 'json');
	}

	// =========================================================================
	// Resend handler
	// =========================================================================

	function handleResend(btn) {
		var rowId = btn.attr('data-row-id') || '';
		if (!rowId) {
			return;
		}

		btn.prop('disabled', true);

		postAction('spreadconnect_resend_failed_op', { failed_op_id: rowId })
			.done(function (response) {
				if (response && response.success) {
					removeRow(rowId);
				} else {
					var message = response && response.data && response.data.message
						? response.data.message
						: '';
					notify(message);
					btn.prop('disabled', false);
				}
			})
			.fail(function () {
				btn.prop('disabled', false);
			});
	}

	// =========================================================================
	// Plain dismiss handler (AC-14 plain branch)
	// =========================================================================

	function handlePlainDismiss(btn) {
		var rowId = btn.attr('data-row-id') || '';
		if (!rowId) {
			return;
		}

		var confirmText = i18n.confirmDismiss || 'Dismiss this failed operation?';
		if (window.confirm && !window.confirm(confirmText)) {
			return;
		}

		btn.prop('disabled', true);

		postAction('spreadconnect_dismiss_failed_op', { failed_op_id: rowId })
			.done(function (response) {
				if (response && response.success) {
					removeRow(rowId);
				} else {
					var message = response && response.data && response.data.message
						? response.data.message
						: '';
					notify(message);
					btn.prop('disabled', false);
				}
			})
			.fail(function () {
				btn.prop('disabled', false);
			});
	}

	// =========================================================================
	// Resolution modal (AC-14 modal branch + AC-15)
	// =========================================================================

	function openModal(rowId) {
		var modal = $(MODAL_SELECTOR);
		if (modal.length === 0) {
			return;
		}

		// Reset state so a re-open after an earlier cancel does not carry
		// stale form values.
		modal.find('input[name="failed_op_id"]').val(rowId);
		modal.find('input[name="resolution"]').prop('checked', false);
		modal.find('input[name="external_sc_order_id"]').val('');
		modal.find('.spreadconnect-failed-ops__modal-external-id').prop('hidden', true);
		modal.find('[data-action="modal-confirm"]').prop('disabled', true);

		modal.prop('hidden', false);
	}

	function closeModal() {
		var modal = $(MODAL_SELECTOR);
		if (modal.length === 0) {
			return;
		}
		modal.prop('hidden', true);
	}

	/**
	 * Sync the External-ID input visibility + Confirm-button disabled state
	 * to the currently selected radio + the input value. AC-15.
	 */
	function syncModalState() {
		var modal = $(MODAL_SELECTOR);
		if (modal.length === 0) {
			return;
		}

		var selected = modal.find('input[name="resolution"]:checked').val() || '';
		var externalWrap = modal.find('.spreadconnect-failed-ops__modal-external-id');
		var externalInput = modal.find('input[name="external_sc_order_id"]');
		var confirmBtn = modal.find('[data-action="modal-confirm"]');

		if (selected === 'submitted_externally') {
			externalWrap.prop('hidden', false);
			var trimmed = (externalInput.val() || '').toString().replace(/^\s+|\s+$/g, '');
			confirmBtn.prop('disabled', trimmed === '');
		} else if (selected === 'resend' || selected === 'cancel_wc') {
			externalWrap.prop('hidden', true);
			confirmBtn.prop('disabled', false);
		} else {
			externalWrap.prop('hidden', true);
			confirmBtn.prop('disabled', true);
		}
	}

	function handleModalConfirm() {
		var modal = $(MODAL_SELECTOR);
		if (modal.length === 0) {
			return;
		}

		var rowId = modal.find('input[name="failed_op_id"]').val() || '';
		var resolution = modal.find('input[name="resolution"]:checked').val() || '';
		if (!rowId || !resolution) {
			return;
		}

		var data = {
			failed_op_id: rowId,
			resolution: resolution
		};

		if (resolution === 'submitted_externally') {
			var externalId = (modal.find('input[name="external_sc_order_id"]').val() || '').toString().replace(/^\s+|\s+$/g, '');
			if (externalId === '') {
				notify(i18n.manualOrderIdRequired || 'External SC-OrderID is required.');
				return;
			}
			data.external_sc_order_id = externalId;
		}

		modal.find('[data-action="modal-confirm"]').prop('disabled', true);

		postAction('spreadconnect_resolve_create_order', data)
			.done(function (response) {
				if (response && response.success) {
					removeRow(rowId);
					closeModal();
				} else {
					var message = response && response.data && response.data.message
						? response.data.message
						: '';
					notify(message);
					syncModalState();
				}
			})
			.fail(function () {
				syncModalState();
			});
	}

	// =========================================================================
	// Detail toggle
	// =========================================================================

	function handleDetailToggle(btn) {
		var rowId = btn.attr('data-row-id') || '';
		if (!rowId) {
			return;
		}

		var detail = $('#spreadconnect-failed-ops-detail-' + rowId);
		if (detail.length === 0) {
			return;
		}

		var isHidden = detail.prop('hidden');
		detail.prop('hidden', !isHidden);
		btn.attr('aria-expanded', isHidden ? 'true' : 'false');
	}

	// =========================================================================
	// Wiring
	// =========================================================================

	$(function () {
		var doc = $(document);

		// Table action buttons (event-delegation so dynamic re-renders Just Work).
		doc.on('click', TABLE_SELECTOR + ' [data-action="resend"]', function (event) {
			event.preventDefault();
			handleResend($(this));
		});

		doc.on('click', TABLE_SELECTOR + ' [data-action="dismiss"]', function (event) {
			event.preventDefault();
			var btn = $(this);
			var needsResolution = btn.attr('data-needs-resolution') === '1';
			if (needsResolution) {
				openModal(btn.attr('data-row-id') || '');
			} else {
				handlePlainDismiss(btn);
			}
		});

		doc.on('click', TABLE_SELECTOR + ' [data-action="detail"]', function (event) {
			event.preventDefault();
			handleDetailToggle($(this));
		});

		// Modal interactions.
		doc.on('change', MODAL_SELECTOR + ' input[name="resolution"]', function () {
			syncModalState();
		});

		doc.on('input', MODAL_SELECTOR + ' input[name="external_sc_order_id"]', function () {
			syncModalState();
		});

		doc.on('click', MODAL_SELECTOR + ' [data-action="modal-cancel"]', function (event) {
			event.preventDefault();
			closeModal();
		});

		doc.on('click', MODAL_SELECTOR + ' [data-action="modal-confirm"]', function (event) {
			event.preventDefault();
			handleModalConfirm();
		});
	});
}(window.jQuery));
