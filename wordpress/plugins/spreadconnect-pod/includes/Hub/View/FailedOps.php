<?php
/**
 * Failed Operations sub-page renderer (Hub Section "Failed", slice-38).
 *
 * Replaces the slice-13 routing stub with the real Dead-Letter-Queue (DLQ)
 * surface for the `wp_spreadconnect_failed_ops` table written by Slice 37.
 *
 * Render-output (wireframes.md Screen 5):
 *   ① `<h1>` page title.
 *   ② Empty-state panel when {@see FailedOpsRepo::findAll()} returns `[]`.
 *   ③ `<table>` with one `<tr data-row-id data-op-type>` per unresolved row;
 *      columns: Failed-At | Op-Type | Entity | Error (truncated 80) |
 *      Retries | Actions ([Resend] [Dismiss] [Detail]).
 *   ④ Hidden Resolution-Modal container — `#spreadconnect-resolution-modal`
 *      with three radios (`resend` / `cancel_wc` / `submitted_externally`)
 *      and the conditional `external_sc_order_id` input. Shown by JS when
 *      a `create_order`-row's Dismiss is clicked.
 *
 * The view enqueues `assets/js/failed-ops-modal.js` (Asset-Handle
 * `spreadconnect-failed-ops`) with a localised payload carrying `ajaxUrl`,
 * `nonce` (action `spreadconnect_failed_ops`) and an `i18n` map. All click
 * handlers are bound by JS; no inline `onclick` attributes.
 *
 * Out of scope (slice-38 Constraints):
 *   - Bulk-Resend / Bulk-Dismiss — slice-40.
 *   - Persistent admin-notice on resolve-success — slice-39.
 *   - Pagination / advanced filters (op_type, date-range) — possible polish
 *     in slice-46. This view shows the first 50 unresolved rows.
 *   - Refund API call on `cancel_wc` — explicitly NOT done; status mutation
 *     + Order-Note only.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Failure\FailedOpsRepo;
use SpreadconnectPod\Hub\Ajax\FailedOpsActions;
use SpreadconnectPod\Hub\Controller as HubController;

/**
 * Stateless renderer for the Failed-Ops sub-page.
 *
 * Final + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529; mirrors slice-13 / slice-26 / slice-41 / slice-42 conventions).
 *
 * The view depends on {@see FailedOpsRepo} (slice-37) for read access; the
 * repo is constructed lazily inside {@see self::render()} so direct
 * invocation in unit tests can swap `$GLOBALS['wpdb']` (or stub the
 * `findAll()` call via Brain\Monkey/Mockery) without bringing the full
 * plugin bootstrap online.
 */
final class FailedOps
{
	/**
	 * Plugin text-domain (mirrors slice-13 / slice-41 / slice-42).
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Asset handle for the resolution-modal JS.
	 *
	 * Slice-38 Provides-Section: re-used by Slice 40 BulkResendCoordinator
	 * (same nonce-action) but the script-handle itself is unique to this
	 * view so other admin pages stay clean.
	 */
	public const JS_HANDLE = 'spreadconnect-failed-ops';

	/**
	 * Asset version pin.
	 */
	private const JS_VERSION = '1.0.0';

	/**
	 * AJAX nonce-action (single, shared by all three handlers from Slice 38
	 * + the Slice 40 Bulk-Resend coordinator).
	 *
	 * MUST match the literal in {@see FailedOpsActions::NONCE_ACTION}.
	 */
	public const NONCE_ACTION = 'spreadconnect_failed_ops';

	/**
	 * Page-size for the unresolved listing (slice-38 Constraints — first
	 * 50 rows; pagination is out of scope).
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Maximum displayed length of the `error_message` column, per AC-1.
	 */
	private const ERROR_TRUNCATE_LENGTH = 80;

	/**
	 * Render the Failed-Ops sub-page.
	 *
	 * Wired via {@see HubController::dispatch()} when `?section=failed`. The
	 * Hub Controller already emits the surrounding `<div class="wrap">` and
	 * the Sidebar before delegating to this method (slice-13 AC-10).
	 *
	 * Capability gate: defense-in-depth via {@see HubController::ensureCapability()}
	 * — the dispatcher already ran the same check, but a per-view gate keeps
	 * direct invocation (tests, future direct callers) safe.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		HubController::ensureCapability();

		$rows = self::loadUnresolvedRows();

		self::enqueueAssets();

		echo '<h1 class="spreadconnect-hub__title">'
			. esc_html__( 'Failed Operations', self::TEXT_DOMAIN )
			. '</h1>';

		if ( array() === $rows ) {
			self::renderEmptyState();
			self::renderModalContainer();
			return;
		}

		self::renderTable( $rows );
		self::renderModalContainer();
	}

	// =========================================================================
	// Repo access
	// =========================================================================

	/**
	 * Load the first {@see self::PAGE_SIZE} unresolved rows via {@see FailedOpsRepo}.
	 *
	 * Constructed lazily so unit tests can stub the global `$wpdb` via
	 * Brain\Monkey/Mockery without booting the full plugin DI graph.
	 *
	 * @return list<array<string, mixed>> Repo-hydrated rows (payload decoded).
	 */
	private static function loadUnresolvedRows(): array
	{
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$repo = new FailedOpsRepo( $wpdb );

		return $repo->findAll(
			array( 'state' => FailedOpsRepo::STATE_UNRESOLVED ),
			self::PAGE_SIZE,
			0
		);
	}

	// =========================================================================
	// Markup helpers
	// =========================================================================

	/**
	 * Render the empty-state panel (wireframes.md Screen 5 -> State `empty`).
	 */
	private static function renderEmptyState(): void
	{
		echo '<div class="spreadconnect-failed-ops__empty notice notice-success">';
		echo '<p>' . esc_html__(
			'No failed operations — all systems running smoothly.',
			self::TEXT_DOMAIN
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the unresolved-row table (AC-1 + AC-2).
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private static function renderTable( array $rows ): void
	{
		echo '<table class="widefat striped spreadconnect-failed-ops__table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Failed At', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Op-Type', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Entity', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Retries', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', self::TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup helper escapes internally.
			echo self::renderRow( $row );
		}
		echo '</tbody>';

		echo '</table>';
	}

	/**
	 * Render one `<tr>` for an unresolved row.
	 *
	 * Per AC-2:
	 *   - `data-row-id` carries the failed-op id.
	 *   - `data-op-type` carries the op-type literal.
	 *   - The Dismiss button carries `data-needs-resolution="1"` for
	 *     `op_type='create_order'`, `0` otherwise.
	 *   - Resend / Detail buttons are present on every row.
	 *   - NO inline `onclick` — JS asset binds handlers via event-delegation.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function renderRow( array $row ): string
	{
		$id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$opType      = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';
		$entityType  = isset( $row['related_entity_type'] ) && is_string( $row['related_entity_type'] ) ? $row['related_entity_type'] : '';
		$entityId    = isset( $row['related_entity_id'] ) && is_string( $row['related_entity_id'] ) ? $row['related_entity_id'] : '';
		$errorMsg    = isset( $row['error_message'] ) && is_string( $row['error_message'] ) ? $row['error_message'] : '';
		$retries     = isset( $row['retries_used'] ) ? (int) $row['retries_used'] : 0;
		$createdAt   = isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? $row['created_at'] : '';
		$payload     = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

		$needsResolution = ( 'create_order' === $opType ) ? '1' : '0';

		$html  = sprintf(
			'<tr class="spreadconnect-failed-ops__row" data-row-id="%1$s" data-op-type="%2$s">',
			esc_attr( (string) $id ),
			esc_attr( $opType )
		);

		// Failed-At column.
		$html .= '<td class="spreadconnect-failed-ops__cell-time">' . esc_html( $createdAt ) . '</td>';

		// Op-Type column.
		$html .= '<td class="spreadconnect-failed-ops__cell-op-type">' . esc_html( $opType ) . '</td>';

		// Entity column — `<type> <id>` plain text (linkage to WC-order detail
		// is out of scope; slice-46 polish if anything).
		$entityLabel = '' === $entityType && '' === $entityId
			? '—'
			: trim( $entityType . ' ' . $entityId );
		$html       .= '<td class="spreadconnect-failed-ops__cell-entity">' . esc_html( $entityLabel ) . '</td>';

		// Error column — truncate to 80 chars per AC-1, full string in `title`.
		$html .= '<td class="spreadconnect-failed-ops__cell-error" title="' . esc_attr( $errorMsg ) . '">'
			. esc_html( self::truncate( $errorMsg, self::ERROR_TRUNCATE_LENGTH ) )
			. '</td>';

		// Retries column.
		$html .= '<td class="spreadconnect-failed-ops__cell-retries">' . esc_html( (string) $retries ) . '</td>';

		// Action buttons + (initially hidden) inline detail toggle target.
		$html .= '<td class="spreadconnect-failed-ops__cell-actions">';
		$html .= sprintf(
			'<button type="button" class="button button-primary spreadconnect-failed-ops__action" data-action="resend" data-row-id="%1$s">%2$s</button> ',
			esc_attr( (string) $id ),
			esc_html__( 'Resend', self::TEXT_DOMAIN )
		);
		$html .= sprintf(
			'<button type="button" class="button spreadconnect-failed-ops__action" data-action="dismiss" data-row-id="%1$s" data-op-type="%2$s" data-needs-resolution="%3$s">%4$s</button> ',
			esc_attr( (string) $id ),
			esc_attr( $opType ),
			esc_attr( $needsResolution ),
			esc_html__( 'Dismiss', self::TEXT_DOMAIN )
		);
		$html .= sprintf(
			'<button type="button" class="button-link spreadconnect-failed-ops__action" data-action="detail" data-row-id="%1$s" aria-controls="spreadconnect-failed-ops-detail-%1$s" aria-expanded="false">%2$s</button>',
			esc_attr( (string) $id ),
			esc_html__( 'View Detail', self::TEXT_DOMAIN )
		);
		$html .= '</td>';

		$html .= '</tr>';

		// Inline detail row (initially hidden) — pretty-printed payload only,
		// no AS-log lookup (out of scope, slice-42 owns the full per-retry log).
		$html .= sprintf(
			'<tr class="spreadconnect-failed-ops__detail-row" id="spreadconnect-failed-ops-detail-%1$s" hidden>',
			esc_attr( (string) $id )
		);
		$html .= '<td colspan="6">';
		$html .= '<pre class="spreadconnect-failed-ops__payload">' . esc_html( self::prettyPrintPayload( $payload ) ) . '</pre>';
		$html .= '</td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * Render the resolution-modal container (AC-14 + AC-15).
	 *
	 * Markup is initially hidden via the `hidden` attribute. JS asset toggles
	 * visibility on `create_order`-Dismiss clicks and gates the
	 * `submitted_externally`-input + Confirm button.
	 */
	private static function renderModalContainer(): void
	{
		echo '<div id="spreadconnect-resolution-modal" class="spreadconnect-failed-ops__modal" hidden role="dialog" aria-modal="true" aria-labelledby="spreadconnect-resolution-modal-title">';
		echo '<div class="spreadconnect-failed-ops__modal-backdrop"></div>';
		echo '<div class="spreadconnect-failed-ops__modal-dialog">';

		echo '<h2 id="spreadconnect-resolution-modal-title" class="spreadconnect-failed-ops__modal-title">'
			. esc_html__( 'Resolve failed order', self::TEXT_DOMAIN )
			. '</h2>';

		echo '<p class="spreadconnect-failed-ops__modal-help">'
			. esc_html__( 'Choose one of the three resolution paths below before dismissing.', self::TEXT_DOMAIN )
			. '</p>';

		echo '<form class="spreadconnect-failed-ops__modal-form">';
		echo '<input type="hidden" name="failed_op_id" value="" />';

		// Three radios — values match the AJAX whitelist in
		// FailedOpsActions::resolve() (AC-12).
		echo '<fieldset class="spreadconnect-failed-ops__modal-choices">';
		echo '<legend class="screen-reader-text">'
			. esc_html__( 'Resolution choice', self::TEXT_DOMAIN )
			. '</legend>';

		printf(
			'<label class="spreadconnect-failed-ops__modal-choice"><input type="radio" name="resolution" value="resend" /> %s</label>',
			esc_html__( 'Re-send to Spreadconnect', self::TEXT_DOMAIN )
		);
		printf(
			'<label class="spreadconnect-failed-ops__modal-choice"><input type="radio" name="resolution" value="cancel_wc" /> %s</label>',
			esc_html__( 'Cancel WooCommerce order', self::TEXT_DOMAIN )
		);
		printf(
			'<label class="spreadconnect-failed-ops__modal-choice"><input type="radio" name="resolution" value="submitted_externally" /> %s</label>',
			esc_html__( 'Submitted externally — record SC-OrderID', self::TEXT_DOMAIN )
		);

		echo '</fieldset>';

		// External-ID input — initially hidden, conditionally shown by JS when
		// the `submitted_externally` radio is selected (AC-15).
		echo '<div class="spreadconnect-failed-ops__modal-external-id" hidden>';
		echo '<label>';
		echo '<span>' . esc_html__( 'External SC-OrderID', self::TEXT_DOMAIN ) . '</span>';
		echo '<input type="text" name="external_sc_order_id" value="" autocomplete="off" />';
		echo '</label>';
		echo '</div>';

		echo '<div class="spreadconnect-failed-ops__modal-actions">';
		printf(
			'<button type="button" class="button" data-action="modal-cancel">%s</button> ',
			esc_html__( 'Cancel', self::TEXT_DOMAIN )
		);
		printf(
			'<button type="button" class="button button-primary" data-action="modal-confirm" disabled>%s</button>',
			esc_html__( 'Confirm Resolution', self::TEXT_DOMAIN )
		);
		echo '</div>';

		echo '</form>';

		echo '</div>'; // .spreadconnect-failed-ops__modal-dialog
		echo '</div>'; // #spreadconnect-resolution-modal
	}

	// =========================================================================
	// Asset registration
	// =========================================================================

	/**
	 * Register + enqueue the modal JS asset (AC-3) with a localised payload.
	 *
	 * Localisation keys (all values are JS-side strings):
	 *   - `ajaxUrl`           — `admin_url('admin-ajax.php')`.
	 *   - `nonce`             — `wp_create_nonce('spreadconnect_failed_ops')`.
	 *   - `i18n.confirmDismiss`        — confirmation text for plain Dismiss.
	 *   - `i18n.confirmResolution`     — confirmation text for resolve flow.
	 *   - `i18n.manualOrderIdRequired` — error when external-ID empty.
	 *
	 * Dependency `['jquery']` per slice-38 AC-3.
	 *
	 * @return void
	 */
	public static function enqueueAssets(): void
	{
		$pluginDir = dirname( __DIR__, 3 );
		$jsRelPath = 'assets/js/failed-ops-modal.js';
		$jsUrl     = plugins_url( $jsRelPath, $pluginDir . '/spreadconnect-pod.php' );

		wp_register_script(
			self::JS_HANDLE,
			$jsUrl,
			array( 'jquery' ),
			self::JS_VERSION,
			true
		);

		wp_localize_script(
			self::JS_HANDLE,
			'spreadconnectFailedOps',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'confirmDismiss'        => __(
						'Dismiss this failed operation?',
						self::TEXT_DOMAIN
					),
					'confirmResolution'     => __(
						'Confirm the chosen resolution?',
						self::TEXT_DOMAIN
					),
					'manualOrderIdRequired' => __(
						'External SC-OrderID is required for this resolution.',
						self::TEXT_DOMAIN
					),
				),
			)
		);

		wp_enqueue_script( self::JS_HANDLE );
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * UTF-8-safe truncate to `$max` characters with an ellipsis suffix when
	 * the source exceeds the budget.
	 */
	private static function truncate( string $value, int $max ): string
	{
		if ( $max <= 0 ) {
			return '';
		}

		// `mb_strlen` falls back gracefully on hosts without mbstring.
		$length = function_exists( 'mb_strlen' )
			? (int) mb_strlen( $value, 'UTF-8' )
			: strlen( $value );

		if ( $length <= $max ) {
			return $value;
		}

		if ( function_exists( 'mb_substr' ) ) {
			return rtrim( (string) mb_substr( $value, 0, $max, 'UTF-8' ) ) . '…';
		}

		return rtrim( substr( $value, 0, $max ) ) . '…';
	}

	/**
	 * Pretty-print the payload array for the inline detail row.
	 */
	private static function prettyPrintPayload( array $payload ): string
	{
		if ( array() === $payload ) {
			return '{}';
		}

		$encoded = wp_json_encode(
			$payload,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $encoded ) ? $encoded : '{}';
	}
}
