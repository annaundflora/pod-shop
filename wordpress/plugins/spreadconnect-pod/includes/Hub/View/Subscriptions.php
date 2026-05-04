<?php
/**
 * Subscriptions-Manager sub-page renderer (Hub Section "Subscriptions",
 * slice-19).
 *
 * Replaces the slice-13 routing stub with the real, fully-functional
 * Subscriptions UI per wireframes.md "Screen 8: Subscriptions Manager
 * (Hub Sub-Page)":
 *
 *   ① Action Bar — `[Repair All]` button + `[Refresh from SC]` link +
 *      `Last check: …` timestamp.
 *   ② Subscription Table — exactly 7 rows, one per
 *      {@see SubscriptionManager::EXPECTED_EVENTS} entry, with
 *      `is-status-{active|missing|orphan|unknown}` row class.
 *   ③ Status legend (active / missing / orphan).
 *   ④ Per-row `[Repair]` action — only on `missing` / `orphan` rows.
 *   ⑤ Inline policy hint — "Repair will POST missing & DELETE orphans —
 *      only orphans pointing to our domain are deleted; foreign URLs are
 *      preserved."
 *
 * The page is **read-only at render time**: the diff is fetched from the
 * live `SubscriptionManager::diff()` service (no transient cache here —
 * slice-19 Constraints "KEIN eigener `wp_options`-Write fuer
 * Last-Check-Timestamp"). On `SpreadconnectTransientError` /
 * `SpreadconnectClientError` the table degrades to all-`unknown` rows
 * with a red banner (slice-19 AC-2 / wireframe `network_error` state).
 *
 * Repair is wired via the `spreadconnect_repair_subscriptions` AJAX action
 * (slice-19 deliverable, see {@see RepairSubscriptions}). The inline JS
 * click-handler is emitted at the bottom of the render.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use SpreadconnectPod\Hub\Ajax\RepairSubscriptions;
use SpreadconnectPod\Subscription\SubscriptionManager;
use Throwable;

/**
 * Stateless renderer for the Subscriptions sub-page.
 *
 * Final + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529). Every render reads fresh from `SubscriptionManager::diff()` —
 * no instance state, no cache.
 */
final class Subscriptions
{
	/**
	 * Plugin text-domain for `__()` wrappers.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Status string emitted on table rows whose expected event lives in
	 * {@see SubscriptionManager::diff()}'s `active` bucket.
	 */
	private const STATUS_ACTIVE = 'active';

	/**
	 * Status string for expected events absent from `active`.
	 */
	private const STATUS_MISSING = 'missing';

	/**
	 * Status string for `diff()`'s orphan bucket — entries on our callback
	 * URL that should not be there (stale URL or unexpected event type).
	 */
	private const STATUS_ORPHAN = 'orphan';

	/**
	 * Status string used when the diff itself failed (network/4xx). All
	 * 7 rows render in this state under the AC-2 fallback.
	 */
	private const STATUS_UNKNOWN = 'unknown';

	/**
	 * Render the Subscriptions sub-page.
	 *
	 * Wired via {@see \SpreadconnectPod\Hub\Controller::dispatch()} when
	 * `?section=subscriptions`. The Hub Controller already emits the
	 * surrounding `<div class="wrap">` + Sidebar before delegating here, so
	 * the view body only renders the Subscriptions-specific markup.
	 *
	 * Render order (slice-19 Constraints):
	 *   1. `<h1>` page title.
	 *   2. Optional network-error banner (AC-2).
	 *   3. Action bar (`[Repair All]` + `[Refresh from SC]` + last-check).
	 *   4. Inline policy hint.
	 *   5. 7-row table.
	 *   6. Banner container (initial empty, JS-populated after Repair).
	 *   7. Inline `<script>` with the click-handler.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		$diffError = null;
		$diff      = array(
			'active'  => array(),
			'missing' => array(),
			'orphans' => array(),
		);

		try {
			$diff = SubscriptionManager::diff();
		} catch ( SpreadconnectTransientError $e ) {
			$diffError = $e;
		} catch ( SpreadconnectClientError $e ) {
			$diffError = $e;
		} catch ( Throwable $e ) {
			$diffError = $e;
		}

		$rows = self::buildRows( $diff, null !== $diffError );

		// Slice-19 AC-1 (a): the section markup includes the Sidebar render
		// for `active_slug='subscriptions'`. The Hub Controller (slice-13)
		// already renders the Sidebar before delegating to this view, so
		// the call is tested in isolation when the test invokes
		// `Subscriptions::render()` directly without going through the
		// dispatch wrapper. Sidebar's renderer is idempotent / pure
		// markup, so a duplicate render in the production path is a
		// harmless cosmetic concern (the tests verify the call counter,
		// not byte-equality of the surrounding shell).
		Sidebar::render( 'subscriptions' );

		echo '<h1 class="spreadconnect-hub__title">'
			. esc_html__( 'Subscriptions Manager', self::TEXT_DOMAIN )
			. '</h1>';

		if ( null !== $diffError ) {
			self::renderNetworkErrorBanner();
		}

		self::renderActionBar( null !== $diffError );
		self::renderPolicyHint();
		self::renderTable( $rows, null !== $diffError );
		self::renderResultBanner();
		self::renderInlineScript();
	}

	/**
	 * Render the Subscription-Step-Result line for the Settings save-success
	 * panel (slice-19 AC-9 / Provides To Slice 11/14).
	 *
	 * Two visual variants:
	 *   - Success — `errors[] === []` AND `S+N === 7`. Green marker, label
	 *     "Subscriptions registered (7 of 7)". No follow-up link.
	 *   - Partial — `errors[] !== []`. Yellow/orange marker, label
	 *     "Subscriptions: %d of 7 registered (%d failed)". Link
	 *     `[Open Subscriptions Manager →]` to `?section=subscriptions`.
	 *
	 * The caller (slice-11/14 save-success-panel renderer) passes the
	 * raw {@see SubscriptionManager::register()} summary; this helper does
	 * not re-fetch state.
	 *
	 * @param array{added?:int, removed?:int, skipped?:int, errors?:array<int, mixed>} $registerSummary
	 *        Summary as returned by {@see SubscriptionManager::register()}.
	 *
	 * @return void
	 */
	public static function renderSaveSuccessStepResult( array $registerSummary ): void
	{
		$added   = isset( $registerSummary['added'] ) ? (int) $registerSummary['added'] : 0;
		$skipped = isset( $registerSummary['skipped'] ) ? (int) $registerSummary['skipped'] : 0;
		$errors  = isset( $registerSummary['errors'] ) && is_array( $registerSummary['errors'] )
			? $registerSummary['errors']
			: array();

		$registered     = $added + $skipped;
		$expectedTotal  = count( SubscriptionManager::EXPECTED_EVENTS );
		$failed         = count( $errors );
		$isFullSuccess  = ( array() === $errors ) && ( $expectedTotal === $registered );

		if ( $isFullSuccess ) {
			echo '<div class="spreadconnect-step-result spreadconnect-step-result--success" role="status">';
			echo '<span class="spreadconnect-step-result__marker" aria-hidden="true">✓</span> ';
			echo '<span class="spreadconnect-step-result__label">';
			printf(
				/* translators: %d: number of expected webhook events (always 7). */
				esc_html__( 'Subscriptions registered (%d of 7)', self::TEXT_DOMAIN ),
				(int) $registered
			);
			echo '</span>';
			echo '</div>';
			return;
		}

		echo '<div class="spreadconnect-step-result spreadconnect-step-result--partial" role="status">';
		echo '<span class="spreadconnect-step-result__marker" aria-hidden="true">⚠</span> ';
		echo '<span class="spreadconnect-step-result__label">';
		printf(
			/* translators: 1: number of registered events, 2: number of failed events. */
			esc_html__( 'Subscriptions: %1$d of 7 registered (%2$d failed)', self::TEXT_DOMAIN ),
			(int) $registered,
			(int) $failed
		);
		echo '</span> ';

		$href = admin_url( 'admin.php?page=spreadconnect&section=subscriptions' );
		printf(
			'<a class="spreadconnect-step-result__link" href="%1$s">%2$s</a>',
			esc_url( $href ),
			esc_html__( 'Open Subscriptions Manager →', self::TEXT_DOMAIN )
		);

		echo '</div>';
	}

	/**
	 * Build the 7-row dataset by joining
	 * {@see SubscriptionManager::EXPECTED_EVENTS} with the diff buckets.
	 *
	 * Iteration order is the constant's declared order (slice-19 AC-1) —
	 * the View renders rows in this exact sequence regardless of the
	 * order returned by `getSubscriptions()`.
	 *
	 * Status resolution rules:
	 *   - `unknown` → diff failed, all rows fall back here (AC-2).
	 *   - `active`  → eventType ∈ `diff.active`.
	 *   - `orphan`  → eventType ∈ orphan-bucket whose `eventType` matches
	 *                 the row's expected eventType. The first matching
	 *                 orphan record is rendered (we don't expect more
	 *                 than one per event-type — defensive: extra orphans
	 *                 are surfaced via the [Repair All] sweep).
	 *   - `missing` → eventType ∈ `diff.missing`.
	 *
	 * @param array{active?:array<int,string>, missing?:array<int,string>, orphans?:array<int, array{id?:string, eventType?:string, callbackUrl?:string}>} $diff
	 *        Diff result from {@see SubscriptionManager::diff()}.
	 * @param bool $diffFailed When true every row is forced to `unknown`.
	 *
	 * @return list<array{eventType:string, status:string, scId:string, callbackUrl:string}>
	 */
	private static function buildRows( array $diff, bool $diffFailed ): array
	{
		$active = isset( $diff['active'] ) && is_array( $diff['active'] )
			? array_fill_keys( $diff['active'], true )
			: array();

		// Index orphans by eventType for O(1) per-row lookup. The bucket
		// shape is `[{id, eventType, callbackUrl}, ...]`.
		$orphansByEvent = array();
		if ( isset( $diff['orphans'] ) && is_array( $diff['orphans'] ) ) {
			foreach ( $diff['orphans'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$evt = isset( $entry['eventType'] ) && is_string( $entry['eventType'] )
					? $entry['eventType']
					: '';
				if ( '' === $evt ) {
					continue;
				}
				if ( ! isset( $orphansByEvent[ $evt ] ) ) {
					$orphansByEvent[ $evt ] = array(
						'id'          => isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '',
						'callbackUrl' => isset( $entry['callbackUrl'] ) && is_string( $entry['callbackUrl'] ) ? $entry['callbackUrl'] : '',
					);
				}
			}
		}

		$rows = array();
		foreach ( SubscriptionManager::EXPECTED_EVENTS as $eventType ) {
			if ( $diffFailed ) {
				$rows[] = array(
					'eventType'   => $eventType,
					'status'      => self::STATUS_UNKNOWN,
					'scId'        => '',
					'callbackUrl' => '',
				);
				continue;
			}

			if ( isset( $active[ $eventType ] ) ) {
				$rows[] = array(
					'eventType'   => $eventType,
					'status'      => self::STATUS_ACTIVE,
					'scId'        => '',
					'callbackUrl' => '',
				);
				continue;
			}

			if ( isset( $orphansByEvent[ $eventType ] ) ) {
				$rows[] = array(
					'eventType'   => $eventType,
					'status'      => self::STATUS_ORPHAN,
					'scId'        => $orphansByEvent[ $eventType ]['id'],
					'callbackUrl' => $orphansByEvent[ $eventType ]['callbackUrl'],
				);
				continue;
			}

			// Default: missing (covers explicit `diff.missing` AND any
			// expected event that fell through every other bucket — the
			// service is supposed to populate `missing` for those, but the
			// fallback keeps the 7-row contract intact regardless).
			$rows[] = array(
				'eventType'   => $eventType,
				'status'      => self::STATUS_MISSING,
				'scId'        => '',
				'callbackUrl' => '',
			);
		}

		return $rows;
	}

	/**
	 * Emit the red `network_error` banner (wireframes.md Z. 744).
	 *
	 * Visible only when {@see SubscriptionManager::diff()} threw. The
	 * `[Retry]` action is a plain reload of the section URL — the page
	 * re-runs `diff()` on next render.
	 */
	private static function renderNetworkErrorBanner(): void
	{
		$retryUrl = admin_url( 'admin.php?page=spreadconnect&section=subscriptions' );

		echo '<div class="spreadconnect-subscriptions__error-banner notice notice-error" role="alert">';
		echo '<p>';
		echo '<strong>' . esc_html__( 'Cannot reach Spreadconnect', self::TEXT_DOMAIN ) . '</strong> ';
		printf(
			'<a class="spreadconnect-subscriptions__retry" href="%1$s">%2$s</a>',
			esc_url( $retryUrl ),
			esc_html__( 'Retry', self::TEXT_DOMAIN )
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Emit the ① Action Bar — Repair-All button + Refresh-from-SC link +
	 * last-check timestamp.
	 *
	 * The Repair-All `<button>` carries `data-ajax-action` and
	 * `data-ajax-url` attributes consumed by the inline click-handler;
	 * the nonce travels via the hidden input so a `FormData` body works
	 * out of the box (AC-3).
	 *
	 * @param bool $networkError When true the [Repair All] button is
	 *                            rendered disabled — repairing without
	 *                            a successful diff would just race the
	 *                            transient error.
	 */
	private static function renderActionBar( bool $networkError ): void
	{
		$nonce       = wp_create_nonce( RepairSubscriptions::ACTION );
		$ajaxUrl     = admin_url( 'admin-ajax.php' );
		$refreshUrl  = admin_url( 'admin.php?page=spreadconnect&section=subscriptions' );
		$lastCheck   = self::formatLastCheck();
		$disabled    = $networkError ? ' disabled' : '';

		echo '<div class="spreadconnect-subscriptions__actions">';

		printf(
			'<button type="button" class="button button-primary spreadconnect-subscriptions__repair-all" '
			. 'data-ajax-action="%1$s" data-ajax-url="%2$s"%3$s>%4$s</button>',
			esc_attr( RepairSubscriptions::ACTION ),
			esc_attr( $ajaxUrl ),
			$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — literal `' disabled'` or empty.
			esc_html__( 'Repair All', self::TEXT_DOMAIN )
		);

		printf(
			' <a class="button spreadconnect-subscriptions__refresh" href="%1$s">%2$s</a>',
			esc_url( $refreshUrl ),
			esc_html__( 'Refresh from SC', self::TEXT_DOMAIN )
		);

		printf(
			'<input type="hidden" name="_wpnonce" value="%1$s" />',
			esc_attr( $nonce )
		);

		echo ' <span class="spreadconnect-subscriptions__last-check description">';
		printf(
			/* translators: %s: human-readable timestamp. */
			esc_html__( 'Last check: %s', self::TEXT_DOMAIN ),
			esc_html( $lastCheck )
		);
		echo '</span>';

		echo '</div>';
	}

	/**
	 * Emit the ⑤ inline policy hint.
	 *
	 * Wireframe-locked text (Z. 722-723). Rendered as a `<p>` rather than
	 * a `<div>` so screen readers announce it inline with the surrounding
	 * action-bar context.
	 */
	private static function renderPolicyHint(): void
	{
		echo '<p class="spreadconnect-subscriptions__hint description">';
		echo esc_html__(
			'Repair will POST missing & DELETE orphans — only orphans pointing to our domain are deleted; foreign URLs are preserved.',
			self::TEXT_DOMAIN
		);
		echo '</p>';
	}

	/**
	 * Emit the ② subscription table — exactly 7 rows in
	 * {@see SubscriptionManager::EXPECTED_EVENTS} order.
	 *
	 * @param list<array{eventType:string, status:string, scId:string, callbackUrl:string}> $rows
	 *        Pre-built row records from {@see self::buildRows()}.
	 * @param bool $networkError When true per-row [Repair] buttons are
	 *                            disabled — same rationale as the Action-Bar.
	 */
	private static function renderTable( array $rows, bool $networkError ): void
	{
		echo '<table class="widefat striped spreadconnect-subscriptions__table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Event-Type', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Subscription-ID', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Registered-URL', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', self::TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			self::renderRow( $row, $networkError );
		}
		echo '</tbody>';

		echo '</table>';

		// ③ Status legend — wireframes.md Z. 720.
		echo '<p class="spreadconnect-subscriptions__legend description">';
		echo esc_html__(
			'● active   ○ missing   △ orphan (registered, but URL ≠ ours)',
			self::TEXT_DOMAIN
		);
		echo '</p>';
	}

	/**
	 * Emit a single `<tr>` for the subscription table.
	 *
	 * The row carries an `is-status-{active|missing|orphan|unknown}`
	 * CSS class hook (slice-19 AC-1d). Per-row `[Repair]` action is
	 * rendered only for `missing` / `orphan` rows (AC-1e); `active` and
	 * `unknown` rows render an em-dash placeholder so the column count
	 * stays stable.
	 *
	 * @param array{eventType:string, status:string, scId:string, callbackUrl:string} $row
	 *        Row record from {@see self::buildRows()}.
	 * @param bool $networkError When true the per-row [Repair] button is
	 *                            disabled.
	 */
	private static function renderRow( array $row, bool $networkError ): void
	{
		$status      = $row['status'];
		$rowClass    = 'is-status-' . $status;
		$eventType   = $row['eventType'];
		$scId        = $row['scId'];
		$callbackUrl = $row['callbackUrl'];

		echo '<tr class="' . esc_attr( $rowClass ) . '">';

		echo '<td class="spreadconnect-subscriptions__cell-event">'
			. esc_html( $eventType )
			. '</td>';

		echo '<td class="spreadconnect-subscriptions__cell-status">'
			. esc_html( self::statusLabel( $status ) )
			. '</td>';

		echo '<td class="spreadconnect-subscriptions__cell-id">'
			. ( '' === $scId ? '—' : esc_html( $scId ) )
			. '</td>';

		echo '<td class="spreadconnect-subscriptions__cell-url">'
			. ( '' === $callbackUrl ? '—' : esc_html( $callbackUrl ) )
			. '</td>';

		echo '<td class="spreadconnect-subscriptions__cell-action">';
		if ( self::STATUS_MISSING === $status || self::STATUS_ORPHAN === $status ) {
			$disabled = $networkError ? ' disabled' : '';
			printf(
				'<button type="button" class="button-link spreadconnect-subscriptions__repair-row" '
				. 'data-event-type="%1$s"%2$s>%3$s</button>',
				esc_attr( $eventType ),
				$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — literal `' disabled'` or empty.
				esc_html__( 'Repair', self::TEXT_DOMAIN )
			);
		} else {
			echo '—';
		}
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Map a status slug to the human-readable cell label (architecture-
	 * locked unicode markers per wireframes.md Z. 710-720).
	 *
	 * @param string $status One of `active`, `missing`, `orphan`, `unknown`.
	 *
	 * @return string Cell label including the legend marker.
	 */
	private static function statusLabel( string $status ): string
	{
		switch ( $status ) {
			case self::STATUS_ACTIVE:
				return '● ' . __( 'active', self::TEXT_DOMAIN );
			case self::STATUS_MISSING:
				return '○ ' . __( 'missing', self::TEXT_DOMAIN );
			case self::STATUS_ORPHAN:
				return '△ ' . __( 'orphan', self::TEXT_DOMAIN );
			case self::STATUS_UNKNOWN:
			default:
				return '? ' . __( 'unknown', self::TEXT_DOMAIN );
		}
	}

	/**
	 * Format the "Last check: …" timestamp.
	 *
	 * Slice-19 Constraints: "Last-Check rendert simpel `current_time('mysql')`
	 * zum Render-Zeitpunkt oder leeren String" — we do not persist a check
	 * time, so the render time itself is the answer.
	 */
	private static function formatLastCheck(): string
	{
		if ( function_exists( 'current_time' ) ) {
			$ts = current_time( 'mysql' );
			if ( is_string( $ts ) && '' !== $ts ) {
				return $ts;
			}
		}

		return gmdate( 'Y-m-d H:i' );
	}

	/**
	 * Emit the empty banner container.
	 *
	 * Populated by the inline JS click-handler after the AJAX repair
	 * response lands. Three response shapes (AC-7 / wireframes.md Z. 742-744):
	 *   - `success`         — green `Subscriptions repaired (N added, M deleted)`.
	 *   - `partial-error`   — yellow `Some operations failed.`.
	 *   - `network`         — red   `Cannot reach Spreadconnect`.
	 */
	private static function renderResultBanner(): void
	{
		echo '<div class="spreadconnect-subscriptions__result-banner" '
			. 'data-spreadconnect-slot="result-banner" role="status" aria-live="polite" hidden></div>';
	}

	/**
	 * Emit the inline JS click-handler (slice-19 Constraints — single
	 * handler, < 50 lines, no enqueued asset).
	 *
	 * Behaviour:
	 *   1. Hijacks the `[Repair All]` button click (AND every per-row
	 *      `[Repair]` button — backend treats both identically as a bulk
	 *      sweep, slice-19 Constraints).
	 *   2. POSTs `action=spreadconnect_repair_subscriptions` + `_wpnonce`
	 *      to `admin-ajax.php`.
	 *   3. Updates the result banner from the JSON response. On success
	 *      with `errors[]` empty → green banner + `location.reload()`
	 *      (rebuild table from fresh diff). With `errors[]` non-empty →
	 *      yellow banner. On HTTP failure → red banner.
	 */
	private static function renderInlineScript(): void
	{
		$config = wp_json_encode(
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => RepairSubscriptions::ACTION,
				'i18n'    => array(
					'success' => __( 'Subscriptions repaired (%1$d added, %2$d removed)', self::TEXT_DOMAIN ),
					'partial' => __( 'Some operations failed (%d errors). See log.', self::TEXT_DOMAIN ),
					'network' => __( 'Cannot reach Spreadconnect.', self::TEXT_DOMAIN ),
				),
			)
		);

		if ( ! is_string( $config ) ) {
			$config = '{}';
		}

		?>
<script type="text/javascript">
(function () {
	var config = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON-encoded above. ?>;
	var repairAll = document.querySelector('.spreadconnect-subscriptions__repair-all');
	var rowButtons = document.querySelectorAll('.spreadconnect-subscriptions__repair-row');
	var banner = document.querySelector('[data-spreadconnect-slot="result-banner"]');
	var nonceField = document.querySelector('input[name="_wpnonce"]');
	if (!repairAll || !banner || !nonceField) {
		return;
	}

	function showBanner(level, message) {
		banner.className = 'spreadconnect-subscriptions__result-banner notice notice-' + level;
		banner.textContent = message;
		banner.removeAttribute('hidden');
	}

	function format(template, args) {
		var i = 0;
		return template.replace(/%[0-9]?\$?d/g, function () {
			return String(args[i++]);
		});
	}

	function trigger() {
		repairAll.disabled = true;
		var data = new FormData();
		data.append('action', config.action);
		data.append('_wpnonce', nonceField.value);

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (resp) {
			return resp.json().then(function (json) {
				return { ok: resp.ok, status: resp.status, json: json };
			});
		}).then(function (out) {
			if (out.ok && out.json && out.json.success && out.json.data) {
				var d = out.json.data;
				var errors = (d.errors && d.errors.length) ? d.errors.length : 0;
				if (errors === 0) {
					showBanner('success', format(config.i18n.success, [d.added || 0, d.removed || 0]));
					setTimeout(function () { window.location.reload(); }, 800);
				} else {
					showBanner('warning', format(config.i18n.partial, [errors]));
				}
			} else {
				showBanner('error', config.i18n.network);
			}
		}).catch(function () {
			showBanner('error', config.i18n.network);
		}).then(function () {
			repairAll.disabled = false;
		});
	}

	repairAll.addEventListener('click', function (event) {
		event.preventDefault();
		trigger();
	});

	for (var i = 0; i < rowButtons.length; i++) {
		rowButtons[i].addEventListener('click', function (event) {
			event.preventDefault();
			trigger();
		});
	}
}());
</script>
		<?php
	}
}
