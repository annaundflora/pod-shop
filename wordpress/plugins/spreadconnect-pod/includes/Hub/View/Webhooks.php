<?php
/**
 * Webhook-Log sub-page renderer (Hub Section "Webhooks", slice-41).
 *
 * Replaces the slice-13 routing stub with the real, fully-functional
 * Webhook-Log Audit-Trail UI per wireframes.md "Screen 4: Webhook Event Log":
 *
 *   ① Filter Bar   — Event-Type + Date-Range + HMAC-Status + Processing-Status,
 *                     plus pagination via `?paged=`.
 *   ② Retention   — read-only hint sourced from the
 *                     `spreadconnect_webhook_log_retention_days` option.
 *   ③ Event Table — `wp_spreadconnect_webhook_log` rows DESC by `received_at`
 *                     with per-row data attributes for the JS expand toggle.
 *   ④ HMAC + Proc — glyph + aria-label badges per row.
 *   ⑤ Expand     — `<button class="spreadconnect-row-toggle">` controlling a
 *                     hidden detail-row (vanilla JS toggle, no AJAX).
 *   ⑥ Detail Row — pretty-printed JSON payload + processing log + source-IP.
 *
 * Out of scope for slice-41 (per Constraints):
 *   - "Retry"-button (slice-37/38 FailedOps owns webhook-resend).
 *   - Bulk-actions / selection-checkboxes (read-only audit-trail).
 *   - Auto-refresh / live-tail (slice-42 Logs surface).
 *   - CSV-export (slice-42).
 *   - Full-text search across `payload` (not in wireframe / index / done-signal).
 *   - REST endpoint, AJAX handler, nonce — read-only section, no mutations.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Hub\Controller;
use SpreadconnectPod\Webhook\WebhookLogRepo;

/**
 * Stateless renderer for the Webhook-Log sub-page.
 *
 * Final + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529; mirrors slice-13 / slice-26 conventions). The renderer performs
 * DB reads through {@see WebhookLogRepo} (Slice 16 + slice-41 additive
 * `findFiltered` / `countFiltered`) and writes nothing — there is no
 * AJAX/REST mutation surface in this slice (slice-41 AC-14).
 */
final class Webhooks
{
	/**
	 * Plugin text-domain for `__()` / `esc_html__()` wrappers.
	 *
	 * Mirrors the constant in {@see Controller::TEXT_DOMAIN}; duplicated
	 * here to keep the view self-contained.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Pagination page-size (slice-41 AC-5 / Constraints "Page-Size als
	 * Konstante").
	 */
	private const PAGE_SIZE = 25;

	/**
	 * Asset handle for the row-expand JS (slice-41 AC-12).
	 */
	private const JS_HANDLE = 'spreadconnect-webhook-log-expand';

	/**
	 * Asset version pin. Falls back to a literal when the plugin-version
	 * constant is absent (slice-02 plugin-bootstrap defines this lazily).
	 */
	private const JS_VERSION = '1.0.0';

	/**
	 * Range-filter whitelist (slice-41 AC-3).
	 *
	 * `'all'` is the default sentinel — no `received_at` predicate is added
	 * to the WHERE clause when the active range is `'all'`.
	 *
	 * @var list<string>
	 */
	private const RANGE_WHITELIST = array( '24h', '7d', '30d', 'all' );

	/**
	 * HMAC-status filter whitelist (slice-41 AC-3).
	 *
	 * @var list<string>
	 */
	private const HMAC_WHITELIST = array( 'all', 'valid', 'invalid' );

	/**
	 * Processing-status filter whitelist (slice-41 AC-3).
	 *
	 * Mirrors the enum from architecture.md Z. 223 plus the `'all'` sentinel.
	 *
	 * @var list<string>
	 */
	private const PROC_WHITELIST = array( 'all', 'success', 'error', 'pending', 'duplicate' );

	/**
	 * Render the Webhook-Log sub-page.
	 *
	 * Wired via {@see Controller::dispatch()} when `?section=webhooks`. The
	 * Hub Controller already emits the surrounding `<div class="wrap">` and
	 * the Sidebar before delegating to this method, so the view body only
	 * needs to render the Webhook-Log-specific markup (slice-41 AC-1).
	 *
	 * Capability gate: defense-in-depth via {@see Controller::ensureCapability()}
	 * (slice-41 AC-2) — the dispatcher already ran the same check, but the
	 * read-only constraint (architecture.md Z. 484) wants a per-view gate so
	 * direct method invocation (e.g. tests, future direct callers) cannot
	 * skip the cap-check.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		Controller::ensureCapability();

		// Resolve filter values from $_GET. The helper applies the
		// AC-3 whitelist + sanitisation rules; `$filters` carries only
		// values from the per-filter whitelist after this call.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only GET with capability gate (architecture.md Z. 484).
		$filters = self::buildFilters( $_GET );

		// Pagination: AC-5 page-size = 25, paged starts at 1.
		$page   = max( 1, $filters['paged'] );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;

		// Repo-Aufruf: defense-in-depth-validierte Filter wandern erneut durch
		// die Repo-Whitelist (Constraints "Repo-Erweiterung").
		$rows  = WebhookLogRepo::findFiltered( $filters, self::PAGE_SIZE, $offset );
		$total = WebhookLogRepo::countFiltered( $filters );

		// Enqueue assets only on this section so other admin pages stay clean
		// (slice-41 AC-12: kein globaler Admin-Enqueue).
		self::enqueueAssets();

		echo '<h1 class="spreadconnect-hub__title">' . esc_html__( 'Webhook Event Log', self::TEXT_DOMAIN ) . '</h1>';

		// ② Retention hint
		self::renderRetentionHint();

		// ① Filter bar
		self::renderFilterBar( $filters );

		// ③ + ⑥ Event table OR empty/no-results panel.
		if ( array() === $rows ) {
			self::renderEmptyPanel( $filters );
			return;
		}

		echo '<table class="widefat striped spreadconnect-webhook-log__table">';
		echo '<thead><tr>';
		echo '<th scope="col" class="spreadconnect-webhook-log__col-toggle"><span class="screen-reader-text">' . esc_html__( 'Expand row', self::TEXT_DOMAIN ) . '</span></th>';
		echo '<th scope="col">' . esc_html__( 'Received', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Event-Type', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Entity', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'HMAC', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Processing', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', self::TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup helpers escape internally.
			echo self::renderRow( $row );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup helpers escape internally.
			echo self::renderDetailRow( $row );
		}
		echo '</tbody>';
		echo '</table>';

		// Pager.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup helper escapes internally.
		echo self::renderPager( $page, $total );
	}

	// =========================================================================
	// Filter helpers
	// =========================================================================

	/**
	 * Read filter values from `$_GET` and apply the slice-41 AC-3 whitelist
	 * sanitisation. Unknown / missing values fall back to defaults.
	 *
	 * Output shape:
	 *   - `event` ∈ {`'all'`, ...8 known event-types}
	 *   - `range` ∈ {`'24h'`, `'7d'`, `'30d'`, `'all'`}
	 *   - `hmac`  ∈ {`'all'`, `'valid'`, `'invalid'`}
	 *   - `proc`  ∈ {`'all'`, `'success'`, `'error'`, `'pending'`, `'duplicate'`}
	 *   - `paged` int, `>= 1`
	 *
	 * NEVER returns a literal `$_GET` value — the output is always one of
	 * the whitelist values (slice-41 AC-3 final clause).
	 *
	 * @param array<string, mixed> $get The `$_GET` superglobal (or a stub
	 *                                  carrying the same shape).
	 *
	 * @return array{event:string, range:string, hmac:string, proc:string, paged:int}
	 */
	private static function buildFilters( array $get ): array
	{
		$event = self::sanitizeFilterString( $get['event'] ?? '' );
		$range = self::sanitizeFilterString( $get['range'] ?? '' );
		$hmac  = self::sanitizeFilterString( $get['hmac'] ?? '' );
		$proc  = self::sanitizeFilterString( $get['proc'] ?? '' );

		if ( ! in_array( $event, WebhookLogRepo::EVENT_TYPES_WHITELIST, true ) ) {
			$event = 'all';
		}
		if ( ! in_array( $range, self::RANGE_WHITELIST, true ) ) {
			$range = 'all';
		}
		if ( ! in_array( $hmac, self::HMAC_WHITELIST, true ) ) {
			$hmac = 'all';
		}
		if ( ! in_array( $proc, self::PROC_WHITELIST, true ) ) {
			$proc = 'all';
		}

		$paged = isset( $get['paged'] ) ? absint( $get['paged'] ) : 0;
		if ( $paged < 1 ) {
			$paged = 1;
		}

		return array(
			'event' => $event,
			'range' => $range,
			'hmac'  => $hmac,
			'proc'  => $proc,
			'paged' => $paged,
		);
	}

	/**
	 * `sanitize_text_field( wp_unslash( ... ) )` shortcut — slice-41 AC-3.
	 *
	 * @param mixed $value Raw `$_GET` value (typically `string`).
	 */
	private static function sanitizeFilterString( $value ): string
	{
		if ( ! is_string( $value ) ) {
			return '';
		}

		return (string) sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Determine if any filter is active (= deviates from its default).
	 *
	 * Used by {@see self::renderEmptyPanel()} to differentiate the
	 * `empty`-state ("no rows received yet") from the
	 * `filter_no_results`-state ("rows exist but none match").
	 *
	 * @param array{event:string, range:string, hmac:string, proc:string, paged:int} $filters
	 */
	private static function hasActiveFilters( array $filters ): bool
	{
		return 'all' !== $filters['event']
			|| 'all' !== $filters['range']
			|| 'all' !== $filters['hmac']
			|| 'all' !== $filters['proc'];
	}

	// =========================================================================
	// Markup helpers
	// =========================================================================

	/**
	 * Emit the retention hint (wireframes.md Screen 4 ②).
	 *
	 * Reads the `spreadconnect_webhook_log_retention_days` option (slice-05;
	 * default 90) and links the word "Settings" to the Settings sub-page.
	 */
	private static function renderRetentionHint(): void
	{
		$retentionDays = (int) get_option( 'spreadconnect_webhook_log_retention_days', 90 );
		$settingsUrl   = admin_url( 'admin.php?page=' . Controller::MENU_SLUG . '&section=settings' );

		printf(
			'<p class="spreadconnect-webhook-log__retention description">%s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of days */
					__( 'Retention: %d days (configurable in', self::TEXT_DOMAIN ),
					$retentionDays
				)
			),
			esc_url( $settingsUrl ),
			esc_html__( 'Settings', self::TEXT_DOMAIN ) . ').'
		);
	}

	/**
	 * Emit the filter bar (wireframes.md Screen 4 ①).
	 *
	 * Renders a `<form method="get">` carrying hidden `page=spreadconnect`
	 * + `section=webhooks` plus four `<select>` controls and a Submit
	 * button. The form posts back to the same admin URL — pure read-only,
	 * no nonce (slice-41 AC-14).
	 *
	 * @param array{event:string, range:string, hmac:string, proc:string, paged:int} $filters
	 */
	private static function renderFilterBar( array $filters ): void
	{
		$adminUrl = admin_url( 'admin.php' );

		echo '<form method="get" action="' . esc_url( $adminUrl ) . '" class="spreadconnect-webhook-log__filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Controller::MENU_SLUG ) . '" />';
		echo '<input type="hidden" name="section" value="webhooks" />';

		// Event filter.
		self::renderFilterSelect(
			'event',
			$filters['event'],
			__( 'Event', self::TEXT_DOMAIN ),
			array(
				'all'                => __( 'All events', self::TEXT_DOMAIN ),
				'Article.added'      => 'Article.added',
				'Article.updated'    => 'Article.updated',
				'Article.removed'    => 'Article.removed',
				'Order.processed'    => 'Order.processed',
				'Order.cancelled'    => 'Order.cancelled',
				'Order.needs-action' => 'Order.needs-action',
				'Shipment.sent'      => 'Shipment.sent',
			)
		);

		// Range filter.
		self::renderFilterSelect(
			'range',
			$filters['range'],
			__( 'Date', self::TEXT_DOMAIN ),
			array(
				'24h' => __( 'Last 24 hours', self::TEXT_DOMAIN ),
				'7d'  => __( 'Last 7 days', self::TEXT_DOMAIN ),
				'30d' => __( 'Last 30 days', self::TEXT_DOMAIN ),
				'all' => __( 'All time', self::TEXT_DOMAIN ),
			)
		);

		// HMAC filter.
		self::renderFilterSelect(
			'hmac',
			$filters['hmac'],
			__( 'HMAC', self::TEXT_DOMAIN ),
			array(
				'all'     => __( 'All', self::TEXT_DOMAIN ),
				'valid'   => __( 'Valid', self::TEXT_DOMAIN ),
				'invalid' => __( 'Invalid', self::TEXT_DOMAIN ),
			)
		);

		// Processing filter.
		self::renderFilterSelect(
			'proc',
			$filters['proc'],
			__( 'Processing', self::TEXT_DOMAIN ),
			array(
				'all'       => __( 'All', self::TEXT_DOMAIN ),
				'success'   => __( 'Success', self::TEXT_DOMAIN ),
				'error'     => __( 'Error', self::TEXT_DOMAIN ),
				'pending'   => __( 'Pending', self::TEXT_DOMAIN ),
				'duplicate' => __( 'Duplicate', self::TEXT_DOMAIN ),
			)
		);

		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html__( 'Filter', self::TEXT_DOMAIN )
		);

		echo '</form>';
	}

	/**
	 * Render one `<label>` + `<select>` pair for the filter bar.
	 *
	 * @param string                $name     Form-field name (`event`, `range`, …).
	 * @param string                $current  Currently selected value.
	 * @param string                $label    Translated label.
	 * @param array<string, string> $choices  `value => label` pairs (label
	 *                                        already translated for non-event
	 *                                        choices; event-type values pass
	 *                                        through as-is).
	 */
	private static function renderFilterSelect(
		string $name,
		string $current,
		string $label,
		array $choices
	): void {
		printf(
			'<label class="spreadconnect-webhook-log__filter-label" for="spreadconnect-filter-%1$s">%2$s</label>',
			esc_attr( $name ),
			esc_html( $label )
		);
		printf(
			'<select id="spreadconnect-filter-%1$s" name="%1$s" class="spreadconnect-webhook-log__filter-select">',
			esc_attr( $name )
		);
		foreach ( $choices as $value => $text ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				$current === $value ? ' selected="selected"' : '',
				esc_html( (string) $text )
			);
		}
		echo '</select>';
	}

	/**
	 * Empty / filter-no-results panel (wireframes.md Z. 389 + Z. 393).
	 *
	 * Differentiates by the `hasActiveFilters` heuristic: any non-default
	 * filter value flips the message into the "no results" variant with a
	 * `[Reset filters]` link (slice-41 AC-6).
	 *
	 * @param array{event:string, range:string, hmac:string, proc:string, paged:int} $filters
	 */
	private static function renderEmptyPanel( array $filters ): void
	{
		echo '<div class="spreadconnect-webhook-log__empty notice notice-info">';

		if ( self::hasActiveFilters( $filters ) ) {
			$resetUrl = admin_url( 'admin.php?page=' . Controller::MENU_SLUG . '&section=webhooks' );
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'No events match the current filters.', self::TEXT_DOMAIN ),
				esc_url( $resetUrl ),
				esc_html__( 'Reset filters', self::TEXT_DOMAIN )
			);
		} else {
			echo '<p>';
			echo esc_html__(
				'No webhook events received yet — confirm that subscriptions are active in [Subscriptions].',
				self::TEXT_DOMAIN
			);
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render the visible `<tr>` for one log row (wireframes.md Screen 4 ③).
	 *
	 * Column order is locked to the wireframe (slice-41 AC-7):
	 *   toggle | received_at | event_type | entity | hmac | proc | action.
	 *
	 * State CSS-classes (slice-41 AC-7):
	 *   - `is-hmac-failed`   (hmac_status == 'invalid')
	 *   - `is-proc-error`    (processing_status == 'error')
	 *   - `is-unknown-event` (event_type == '_unknown')
	 *
	 * @param array<string, mixed> $row One assoc-row from {@see WebhookLogRepo::findFiltered()}.
	 *
	 * @return string The fully-escaped `<tr>` markup.
	 */
	private static function renderRow( array $row ): string
	{
		$id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$eventType   = isset( $row['event_type'] ) && is_string( $row['event_type'] ) ? $row['event_type'] : '';
		$receivedAt  = isset( $row['received_at'] ) && is_string( $row['received_at'] ) ? $row['received_at'] : '';
		$hmacStatus  = isset( $row['hmac_status'] ) && is_string( $row['hmac_status'] ) ? $row['hmac_status'] : '';
		$procStatus  = isset( $row['processing_status'] ) && is_string( $row['processing_status'] ) ? $row['processing_status'] : '';
		$entityType  = isset( $row['related_entity_type'] ) && is_string( $row['related_entity_type'] ) ? $row['related_entity_type'] : '';
		$entityId    = isset( $row['related_entity_id'] ) && is_string( $row['related_entity_id'] ) ? $row['related_entity_id'] : '';

		$classes = array( 'spreadconnect-webhook-log__row' );
		if ( 'invalid' === $hmacStatus ) {
			$classes[] = 'is-hmac-failed';
		}
		if ( 'error' === $procStatus ) {
			$classes[] = 'is-proc-error';
		}
		if ( '_unknown' === $eventType ) {
			$classes[] = 'is-unknown-event';
		}

		$detailId = 'spreadconnect-row-detail-' . $id;

		$html  = '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '" data-log-id="' . esc_attr( (string) $id ) . '">';
		// Toggle button cell.
		$html .= '<td class="spreadconnect-webhook-log__cell-toggle">';
		$html .= sprintf(
			'<button type="button" class="spreadconnect-row-toggle" aria-expanded="false" aria-controls="%1$s"><span aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></button>',
			esc_attr( $detailId ),
			esc_html( "\xE2\x96\xB6" ), // U+25B6 BLACK RIGHT-POINTING TRIANGLE.
			esc_html__( 'Toggle row details', self::TEXT_DOMAIN )
		);
		$html .= '</td>';

		// Received-at cell — wp_date() respects the WP timezone setting.
		$html .= '<td class="spreadconnect-webhook-log__cell-received">' . esc_html( self::formatReceivedAt( $receivedAt ) ) . '</td>';

		// Event-type cell.
		$html .= '<td class="spreadconnect-webhook-log__cell-event">' . esc_html( self::formatEventType( $eventType ) ) . '</td>';

		// Entity cell — outbound link only when the event is `Order.*` AND
		// the related-entity-id is non-empty (slice-41 AC-7).
		$html .= '<td class="spreadconnect-webhook-log__cell-entity">' . self::renderEntityCell( $eventType, $entityType, $entityId ) . '</td>';

		// HMAC cell.
		$html .= '<td class="spreadconnect-webhook-log__cell-hmac">' . self::renderHmacBadge( $hmacStatus ) . '</td>';

		// Processing cell.
		$html .= '<td class="spreadconnect-webhook-log__cell-proc">' . self::renderProcBadge( $procStatus ) . '</td>';

		// Action cell — explicitly empty (slice-41 Constraints: kein Retry-Button).
		$html .= '<td class="spreadconnect-webhook-log__cell-action"></td>';

		$html .= '</tr>';

		return $html;
	}

	/**
	 * Render the hidden detail-`<tr>` for one row (wireframes.md Screen 4 ⑥).
	 *
	 * Layout: pretty-printed JSON payload + processing-log block (status,
	 * error, received_at) + optional source-IP. When `hmac_status='invalid'`
	 * the payload is replaced with a security-hint (slice-41 AC-9 +
	 * architecture.md Z. 493).
	 *
	 * @param array<string, mixed> $row Assoc-row.
	 */
	private static function renderDetailRow( array $row ): string
	{
		$id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$hmacStatus  = isset( $row['hmac_status'] ) && is_string( $row['hmac_status'] ) ? $row['hmac_status'] : '';
		$procStatus  = isset( $row['processing_status'] ) && is_string( $row['processing_status'] ) ? $row['processing_status'] : '';
		$procError   = isset( $row['processing_error'] ) && is_string( $row['processing_error'] ) ? $row['processing_error'] : '';
		$receivedAt  = isset( $row['received_at'] ) && is_string( $row['received_at'] ) ? $row['received_at'] : '';
		$payloadRaw  = isset( $row['payload'] ) && is_string( $row['payload'] ) ? $row['payload'] : '';

		$detailId = 'spreadconnect-row-detail-' . $id;

		$html  = '<tr id="' . esc_attr( $detailId ) . '" class="spreadconnect-webhook-log__detail-row" hidden>';
		$html .= '<td colspan="7">';
		$html .= '<div class="spreadconnect-webhook-log__detail">';

		// Payload section. Hidden when HMAC is invalid (security constraint).
		$html .= '<h3 class="spreadconnect-webhook-log__detail-title">' . esc_html__( 'Payload', self::TEXT_DOMAIN ) . '</h3>';
		if ( 'invalid' === $hmacStatus ) {
			$html .= '<p class="spreadconnect-webhook-log__detail-payload-hidden description">';
			$html .= esc_html__( 'Payload not stored (HMAC invalid — only header/IP logged).', self::TEXT_DOMAIN );
			$html .= '</p>';
		} else {
			$html .= '<pre class="spreadconnect-payload-json">' . esc_html( self::prettyPrintPayload( $payloadRaw ) ) . '</pre>';
		}

		// Processing-log block.
		$html .= '<h3 class="spreadconnect-webhook-log__detail-title">' . esc_html__( 'Processing log', self::TEXT_DOMAIN ) . '</h3>';
		$html .= '<ul class="spreadconnect-webhook-log__detail-processing">';
		$html .= '<li><strong>' . esc_html__( 'Received at:', self::TEXT_DOMAIN ) . '</strong> ' . esc_html( self::formatReceivedAt( $receivedAt ) ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Status:', self::TEXT_DOMAIN ) . '</strong> ' . esc_html( $procStatus ) . '</li>';
		if ( '' !== $procError ) {
			$html .= '<li><strong>' . esc_html__( 'Error:', self::TEXT_DOMAIN ) . '</strong> ' . esc_html( $procError ) . '</li>';
		}
		$html .= '</ul>';

		// Optional source-IP — only when present in the decoded payload.
		$sourceIp = self::extractSourceIp( $payloadRaw );
		if ( '' !== $sourceIp ) {
			$html .= '<p class="spreadconnect-webhook-log__detail-ip"><strong>' . esc_html__( 'Source IP:', self::TEXT_DOMAIN ) . '</strong> ' . esc_html( $sourceIp ) . '</p>';
		}

		$html .= '</div>';
		$html .= '</td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * Render the pager (slice-41 AC-5).
	 *
	 * Layout: `[Prev] Page X of N [Next]`. Prev is disabled on `page=1`;
	 * Next is disabled when the current page covers the last row
	 * (`offset + page_size >= total`). No WP-pagination helpers are used
	 * (slice-41 AC-5: "kein WP-Paginierungs-Helper").
	 *
	 * @param int $page  Current 1-based page number.
	 * @param int $total Total row count (from {@see WebhookLogRepo::countFiltered()}).
	 */
	private static function renderPager( int $page, int $total ): string
	{
		$totalPages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$prevPage   = max( 1, $page - 1 );
		$nextPage   = min( $totalPages, $page + 1 );

		$prevDisabled = ( $page <= 1 );
		$nextDisabled = ( $page >= $totalPages );

		$baseUrl = admin_url( 'admin.php?page=' . Controller::MENU_SLUG . '&section=webhooks' );

		// Carry the four current filter values into the pager links so the
		// next-page request keeps the same query.
		$filters = self::buildFilters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queryArgs = array(
			'event' => $filters['event'],
			'range' => $filters['range'],
			'hmac'  => $filters['hmac'],
			'proc'  => $filters['proc'],
		);

		$prevUrl = add_query_arg( array_merge( $queryArgs, array( 'paged' => $prevPage ) ), $baseUrl );
		$nextUrl = add_query_arg( array_merge( $queryArgs, array( 'paged' => $nextPage ) ), $baseUrl );

		$html  = '<nav class="spreadconnect-webhook-log__pager" aria-label="' . esc_attr__( 'Webhook log pagination', self::TEXT_DOMAIN ) . '">';

		if ( $prevDisabled ) {
			$html .= '<span class="button disabled" aria-disabled="true">' . esc_html__( 'Previous', self::TEXT_DOMAIN ) . '</span>';
		} else {
			$html .= '<a class="button" href="' . esc_url( $prevUrl ) . '">' . esc_html__( 'Previous', self::TEXT_DOMAIN ) . '</a>';
		}

		$html .= ' <span class="spreadconnect-webhook-log__pager-counter">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages */
				__( 'Page %1$d of %2$d', self::TEXT_DOMAIN ),
				$page,
				$totalPages
			)
		) . '</span> ';

		if ( $nextDisabled ) {
			$html .= '<span class="button disabled" aria-disabled="true">' . esc_html__( 'Next', self::TEXT_DOMAIN ) . '</span>';
		} else {
			$html .= '<a class="button" href="' . esc_url( $nextUrl ) . '">' . esc_html__( 'Next', self::TEXT_DOMAIN ) . '</a>';
		}

		$html .= '</nav>';

		return $html;
	}

	// =========================================================================
	// Cell helpers
	// =========================================================================

	/**
	 * Format the received-at column using `wp_date()` (respects timezone).
	 */
	private static function formatReceivedAt( string $mysqlDatetime ): string
	{
		if ( '' === $mysqlDatetime ) {
			return '';
		}

		$ts = strtotime( $mysqlDatetime . ' UTC' );
		if ( false === $ts ) {
			$ts = strtotime( $mysqlDatetime );
			if ( false === $ts ) {
				return $mysqlDatetime;
			}
		}

		if ( function_exists( 'wp_date' ) ) {
			$format = 'Y-m-d H:i:s';
			if ( function_exists( 'get_option' ) ) {
				$dateFormat = (string) get_option( 'date_format', 'Y-m-d' );
				$timeFormat = (string) get_option( 'time_format', 'H:i' );
				$format     = $dateFormat . ' ' . $timeFormat;
			}
			$out = wp_date( $format, $ts );
			if ( is_string( $out ) ) {
				return $out;
			}
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Display label for the event-type column.
	 *
	 * `'_unknown'` (the WebhookController stores this for non-routable events)
	 * is rendered as a localised `(unknown)` placeholder per wireframes.md
	 * Z. 392.
	 */
	private static function formatEventType( string $eventType ): string
	{
		if ( '_unknown' === $eventType || '' === $eventType ) {
			return __( '(unknown)', self::TEXT_DOMAIN );
		}

		return $eventType;
	}

	/**
	 * Render the entity cell. Outbound link with `↗` glyph for `Order.*`
	 * events with a non-empty `related_entity_id`; plain text otherwise.
	 *
	 * Slice-41 AC-7: KEIN Outbound-Link bei `event_type='_unknown'` (handled
	 * by the entity-id check below — `_unknown` rows have empty
	 * `related_entity_id`).
	 */
	private static function renderEntityCell(
		string $eventType,
		string $entityType,
		string $entityId
	): string {
		if ( '' === $entityId ) {
			return '<span class="spreadconnect-webhook-log__entity-empty">&mdash;</span>';
		}

		$isOrderEvent = ( 0 === strpos( $eventType, 'Order.' ) || 'Shipment.sent' === $eventType );

		if ( $isOrderEvent && WebhookLogRepo::ENTITY_TYPE_ORDER === $entityType ) {
			$label = sprintf( 'Order %s', $entityId );
			// External SC-backend marker via `↗` glyph; we do NOT generate a
			// real outbound URL because the SC-backend URL pattern is not
			// stable across environments — wireframes.md just shows the icon.
			return sprintf(
				'<span class="spreadconnect-webhook-log__entity-link">%s <span aria-hidden="true">%s</span></span>',
				esc_html( $label ),
				esc_html( "\xE2\x86\x97" ) // U+2197 NORTH EAST ARROW.
			);
		}

		// Article and other events — plain-text identifier.
		return sprintf(
			'<span class="spreadconnect-webhook-log__entity-text">%s</span>',
			esc_html( sprintf( '%s %s', $entityType, $entityId ) )
		);
	}

	/**
	 * Render the HMAC-status badge (✓ / ✗) with an aria-label.
	 */
	private static function renderHmacBadge( string $status ): string
	{
		if ( 'valid' === $status ) {
			return sprintf(
				'<span class="spreadconnect-webhook-log__hmac is-valid" aria-label="%s">%s</span>',
				esc_attr__( 'HMAC valid', self::TEXT_DOMAIN ),
				esc_html( "\xE2\x9C\x93" ) // U+2713 CHECK MARK.
			);
		}

		if ( 'invalid' === $status ) {
			return sprintf(
				'<span class="spreadconnect-webhook-log__hmac is-invalid" aria-label="%s">%s</span>',
				esc_attr__( 'HMAC invalid', self::TEXT_DOMAIN ),
				esc_html( "\xE2\x9C\x97" ) // U+2717 BALLOT X.
			);
		}

		return sprintf(
			'<span class="spreadconnect-webhook-log__hmac">%s</span>',
			esc_html( $status )
		);
	}

	/**
	 * Render the processing-status badge.
	 */
	private static function renderProcBadge( string $status ): string
	{
		$labels = array(
			'success'   => array( 'is-success', "\xE2\x9C\x93", __( 'OK', self::TEXT_DOMAIN ) ),
			'error'     => array( 'is-error', "\xE2\x9A\xA0", __( 'Error', self::TEXT_DOMAIN ) ),
			'pending'   => array( 'is-pending', '', __( 'Pending', self::TEXT_DOMAIN ) ),
			'duplicate' => array( 'is-duplicate', '', __( 'Duplicate', self::TEXT_DOMAIN ) ),
		);

		if ( ! isset( $labels[ $status ] ) ) {
			return sprintf(
				'<span class="spreadconnect-webhook-log__proc">%s</span>',
				esc_html( $status )
			);
		}

		[ $cssClass, $glyph, $text ] = $labels[ $status ];

		if ( '' === $glyph ) {
			return sprintf(
				'<span class="spreadconnect-webhook-log__proc %s">%s</span>',
				esc_attr( $cssClass ),
				esc_html( $text )
			);
		}

		return sprintf(
			'<span class="spreadconnect-webhook-log__proc %s"><span aria-hidden="true">%s</span> %s</span>',
			esc_attr( $cssClass ),
			esc_html( $glyph ),
			esc_html( $text )
		);
	}

	/**
	 * Pretty-print the JSON payload using the architecture.md Z. 282
	 * convention (`JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`).
	 */
	private static function prettyPrintPayload( string $payloadJson ): string
	{
		if ( '' === $payloadJson ) {
			return '';
		}

		$decoded = json_decode( $payloadJson, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			// Cannot pretty-print invalid JSON; return the raw body so the
			// admin can still inspect it (escaped on output).
			return $payloadJson;
		}

		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		return is_string( $pretty ) ? $pretty : $payloadJson;
	}

	/**
	 * Extract a source-IP marker from the decoded payload. The webhook
	 * receiver (slice-15) writes `_source_ip` into the re-encoded payload
	 * for audit purposes; consumers who do not need it simply ignore an
	 * empty string.
	 */
	private static function extractSourceIp( string $payloadJson ): string
	{
		if ( '' === $payloadJson ) {
			return '';
		}

		$decoded = json_decode( $payloadJson, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$ip = $decoded['_source_ip'] ?? '';

		return is_string( $ip ) ? $ip : '';
	}

	// =========================================================================
	// Asset registration
	// =========================================================================

	/**
	 * Register + enqueue the row-expand JS asset (slice-41 AC-12).
	 *
	 * The asset enqueues only when the active section is `webhooks` (the
	 * caller — {@see self::render()} — is itself only invoked on that
	 * section, so the asset is automatically section-scoped). No jQuery
	 * dependency, version-pinned to the plugin-version literal.
	 *
	 * @return void
	 */
	public static function enqueueAssets(): void
	{
		$pluginDir = dirname( __DIR__, 3 );
		$jsRelPath = 'assets/js/webhook-log-expand.js';
		$jsUrl     = plugins_url( $jsRelPath, $pluginDir . '/spreadconnect-pod.php' );

		wp_register_script(
			self::JS_HANDLE,
			$jsUrl,
			array(),
			self::JS_VERSION,
			true
		);
		wp_enqueue_script( self::JS_HANDLE );
	}
}
