<?php
/**
 * Catalog Sync sub-page renderer (Hub Section "Catalog", slice-26).
 *
 * Replaces the slice-13 routing stub with the real, fully-functional
 * Catalog UI per wireframes.md "Screen 2: Catalog Sync (Hub Sub-Page)":
 *
 *   ① "Sync now" button (`<form>` posting to `admin-ajax.php` with the
 *      `spreadconnect_sync_now` nonce; hijacked client-side by the inline
 *      poller script for the AJAX path).
 *   ③ "During Sync" panel container — visible only while a run is active.
 *   ④ Progress bar slot — populated by the poller from the
 *      `processed/total` keys of the `/sync-progress` response.
 *   ⑤ Counter slot — created/updated/skipped/error.
 *   ⑥ Live Log Tail slot — last 20 entries from
 *      `sc_sync_log_tail_{run_id}` (producer is slice-23).
 *   ⑦ Sync History table — `wp_spreadconnect_sync_history` rows DESC by
 *      `started_at`, with the empty-state for `no_history_yet`.
 *
 * Out of scope for slice-26 (per Constraints):
 *   - Cancel-sync button ⑨ — only a visual stub container, no AJAX wired.
 *   - Per-row history expansion ⑧ — aggregate counters only.
 *   - Sync-Settings collapsible ② — slice-11/45 owns the Settings page.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Catalog\SyncHistoryRepo;
use SpreadconnectPod\Hub\Ajax\SyncNow;
use SpreadconnectPod\Hub\Rest\SyncProgress;

/**
 * Stateless renderer for the Catalog sub-page.
 *
 * Final + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529). The renderer does perform DB reads through {@see SyncHistoryRepo}
 * (an essentially-stateless repo), but holds no instance state across
 * requests.
 */
final class Catalog
{
	/**
	 * Plugin text-domain for `__()` wrappers.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Polling interval (ms) — locked to 3 s per architecture.md Z. 517 +
	 * slice-26 AC-11 / Constraints "Polling-Interval 3000ms als Konstante
	 * im Inline-Script".
	 */
	private const POLL_INTERVAL_MS = 3000;

	/**
	 * Maximum number of history rows shown in the table ⑦.
	 *
	 * No pagination per slice-26 Constraints — slice-43 retention sweeps
	 * older rows.
	 */
	private const HISTORY_LIMIT = 20;

	/**
	 * Render the Catalog sub-page.
	 *
	 * Wired via {@see \SpreadconnectPod\Hub\Controller::dispatch()} when
	 * `?section=catalog`. The Hub Controller already emits the surrounding
	 * `<div class="wrap">` + Sidebar before delegating to this method, so
	 * the view body only needs to render the Catalog-specific markup.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		$repo = new SyncHistoryRepo();

		$activeRun  = $repo->getActiveRun();
		$activeId   = ( null !== $activeRun && isset( $activeRun['id'] ) ) ? (int) $activeRun['id'] : 0;
		$history    = $repo->getRecent( self::HISTORY_LIMIT );

		echo '<h1 class="spreadconnect-hub__title">' . esc_html__( 'Catalog Sync', self::TEXT_DOMAIN ) . '</h1>';

		self::renderControls();
		self::renderDuringSyncPanel( $activeId );
		self::renderHistoryTable( $history );
		self::renderInlineScript( $activeId );
	}

	/**
	 * Emit the top control row: ① "Sync now" button + Sync-Settings stub.
	 */
	private static function renderControls(): void
	{
		$nonce      = wp_create_nonce( SyncNow::NONCE_ACTION );
		$ajaxUrl    = admin_url( 'admin-ajax.php' );
		$buttonText = __( 'Sync now', self::TEXT_DOMAIN );

		echo '<div class="spreadconnect-catalog__controls">';
		echo '<form class="spreadconnect-catalog__sync-now-form" method="post" action="' . esc_url( $ajaxUrl ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SyncNow::NONCE_ACTION ) . '" />';
		echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr( $nonce ) . '" />';
		printf(
			'<button type="submit" class="button button-primary spreadconnect-catalog__sync-now" data-spreadconnect-action="sync-now">%s</button>',
			esc_html( $buttonText )
		);
		echo '</form>';

		// ② Sync-Settings stub — slice-11 / slice-45 own the Settings page.
		echo '<p class="spreadconnect-catalog__sync-settings-stub description">';
		echo esc_html__( 'Sync settings: see Settings → Catalog Sync.', self::TEXT_DOMAIN );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Emit the ③ "During Sync" panel container.
	 *
	 * The panel is initially hidden (`hidden` HTML attribute) and toggled
	 * visible by the inline poller as soon as `state IN ('pending',
	 * 'in_progress')`. The active `run_id` is exposed via `data-run-id`
	 * (slice-26 AC-3) — empty string when no active run exists at render
	 * time.
	 *
	 * @param int $activeId Active run id, or `0` when none.
	 */
	private static function renderDuringSyncPanel( int $activeId ): void
	{
		$dataRunId   = $activeId > 0 ? (string) $activeId : '';
		$hiddenAttr  = 0 === $activeId ? ' hidden' : '';

		printf(
			'<section class="spreadconnect-catalog__during-sync" data-run-id="%s"%s>',
			esc_attr( $dataRunId ),
			$hiddenAttr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — literal `' hidden'` or empty string.
		);

		// ⑨ Cancel-sync button stub — slice-26 Constraints: "das Markup
		// darf den Button-Slot ⑨ als sichtbaren Stub-Container vorbereiten,
		// aber kein AJAX-Handler in dieser Slice".
		echo '<div class="spreadconnect-catalog__cancel-stub">';
		echo '<button type="button" class="button" data-spreadconnect-action="cancel-sync" disabled>';
		echo esc_html__( 'Cancel sync (coming soon)', self::TEXT_DOMAIN );
		echo '</button>';
		echo '</div>';

		// ④ Progress bar slot.
		echo '<div class="spreadconnect-catalog__progress-bar" data-spreadconnect-slot="progress">';
		echo '<span class="spreadconnect-catalog__progress-label">' . esc_html__( 'Sync running', self::TEXT_DOMAIN ) . '</span>';
		echo '<progress class="spreadconnect-catalog__progress-meter" value="0" max="0" data-spreadconnect-slot="progress-meter"></progress>';
		echo '<span class="spreadconnect-catalog__progress-fraction" data-spreadconnect-slot="progress-fraction">0 / 0</span>';
		echo '</div>';

		// ⑤ Counter slot.
		echo '<div class="spreadconnect-catalog__counters" data-spreadconnect-slot="counters">';
		printf(
			'<span class="spreadconnect-catalog__counter spreadconnect-catalog__counter--created">%1$s <span data-spreadconnect-slot="counter-created">0</span></span>',
			esc_html__( 'created', self::TEXT_DOMAIN )
		);
		printf(
			'<span class="spreadconnect-catalog__counter spreadconnect-catalog__counter--updated">%1$s <span data-spreadconnect-slot="counter-updated">0</span></span>',
			esc_html__( 'updated', self::TEXT_DOMAIN )
		);
		printf(
			'<span class="spreadconnect-catalog__counter spreadconnect-catalog__counter--skipped">%1$s <span data-spreadconnect-slot="counter-skipped">0</span></span>',
			esc_html__( 'skipped', self::TEXT_DOMAIN )
		);
		printf(
			'<span class="spreadconnect-catalog__counter spreadconnect-catalog__counter--errors">%1$s <span data-spreadconnect-slot="counter-errors">0</span></span>',
			esc_html__( 'errors', self::TEXT_DOMAIN )
		);
		echo '</div>';

		// ⑥ Live Log Tail slot.
		echo '<div class="spreadconnect-catalog__log-tail">';
		echo '<h3 class="spreadconnect-catalog__log-tail-title">' . esc_html__( 'Live Log (last 20 entries)', self::TEXT_DOMAIN ) . '</h3>';
		echo '<pre class="spreadconnect-catalog__log-tail-body" data-spreadconnect-slot="log-tail" aria-live="polite"></pre>';
		echo '</div>';

		echo '</section>';
	}

	/**
	 * Emit the ⑦ Sync History table.
	 *
	 * Empty-state per wireframes.md State Variations `no_history_yet`:
	 * "No sync runs yet — click 'Sync now' to start".
	 *
	 * @param list<array<string, mixed>> $rows History rows DESC by started_at.
	 */
	private static function renderHistoryTable( array $rows ): void
	{
		echo '<h2 class="spreadconnect-catalog__history-title">' . esc_html__( 'Sync History', self::TEXT_DOMAIN ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p class="spreadconnect-catalog__history-empty description">';
			echo esc_html__( "No sync runs yet — click 'Sync now' to start.", self::TEXT_DOMAIN );
			echo '</p>';
			return;
		}

		echo '<table class="widefat striped spreadconnect-catalog__history-table">';
		echo '<thead><tr>';
		// Column order locked to wireframes.md Screen 2 ⑦ — slice-26 AC-2.
		echo '<th scope="col">' . esc_html__( 'Started', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Duration', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Trigger', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Updated', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Skipped', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Errors', self::TEXT_DOMAIN ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'State', self::TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			self::renderHistoryRow( $row );
		}
		echo '</tbody>';

		echo '</table>';
	}

	/**
	 * Emit a single `<tr>` for the history table.
	 *
	 * Cell order matches the `<thead>` ordering above (slice-26 AC-2).
	 * Every cell is `esc_html()`-wrapped before output.
	 *
	 * @param array<string, mixed> $row History row.
	 */
	private static function renderHistoryRow( array $row ): void
	{
		$startedAt  = isset( $row['started_at'] ) && is_string( $row['started_at'] ) ? $row['started_at'] : '';
		$finishedAt = isset( $row['finished_at'] ) && is_string( $row['finished_at'] ) ? $row['finished_at'] : '';
		$trigger    = isset( $row['trigger'] ) && is_string( $row['trigger'] ) ? $row['trigger'] : '';
		$state      = isset( $row['state'] ) && is_string( $row['state'] ) ? $row['state'] : '';
		$created    = isset( $row['created_count'] ) ? (int) $row['created_count'] : 0;
		$updated    = isset( $row['updated_count'] ) ? (int) $row['updated_count'] : 0;
		$skipped    = isset( $row['skipped_count'] ) ? (int) $row['skipped_count'] : 0;
		$errors     = isset( $row['error_count'] ) ? (int) $row['error_count'] : 0;

		$duration = self::formatDuration( $startedAt, $finishedAt );

		echo '<tr>';
		echo '<td>' . esc_html( $startedAt ) . '</td>';
		echo '<td>' . esc_html( $duration ) . '</td>';
		echo '<td>' . esc_html( $trigger ) . '</td>';
		echo '<td>' . esc_html( (string) $created ) . '</td>';
		echo '<td>' . esc_html( (string) $updated ) . '</td>';
		echo '<td>' . esc_html( (string) $skipped ) . '</td>';
		echo '<td>' . esc_html( (string) $errors ) . '</td>';
		echo '<td>' . esc_html( $state ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Format `finished_at - started_at` as a human-readable duration.
	 *
	 * Falls back to an em-dash when either timestamp is missing or
	 * unparseable — never throws, so the history table render is
	 * robust against half-completed rows.
	 *
	 * @param string $startedAt  MySQL DATETIME string.
	 * @param string $finishedAt MySQL DATETIME string or empty.
	 *
	 * @return string Formatted duration (e.g. "4m 12s") or "—".
	 */
	private static function formatDuration( string $startedAt, string $finishedAt ): string
	{
		if ( '' === $startedAt || '' === $finishedAt ) {
			return '—';
		}

		$startTs  = strtotime( $startedAt );
		$finishTs = strtotime( $finishedAt );

		if ( false === $startTs || false === $finishTs || $finishTs < $startTs ) {
			return '—';
		}

		$delta = $finishTs - $startTs;

		$minutes = (int) floor( $delta / 60 );
		$seconds = $delta % 60;

		if ( $minutes > 0 ) {
			return sprintf( '%dm %ds', $minutes, $seconds );
		}

		return sprintf( '%ds', $seconds );
	}

	/**
	 * Emit the inline poller script.
	 *
	 * The script:
	 *   1. Hijacks the `<form>` submit so the "Sync now" button POSTs via
	 *      `fetch()` instead of a full page reload.
	 *   2. Polls `/wp-json/spreadconnect/v1/sync-progress` every 3 s while a
	 *      run is active. Stops the timer when the response state is
	 *      `complete`, `failed`, `canceled` or `idle`.
	 *   3. Updates the progress bar, counter spans, and log-tail `<pre>`
	 *      from the JSON response.
	 *
	 * Inline rather than enqueued because:
	 *   - The script is tiny + single-use; a separate `assets/js/*.js` asset
	 *     would mean an extra HTTP request for one screen.
	 *   - The slice-26 hint allows the implementer to choose either form.
	 *
	 * @param int $activeId Active run id at render time, or `0`.
	 */
	private static function renderInlineScript( int $activeId ): void
	{
		$restUrl  = esc_url_raw( rest_url( SyncProgress::ROUTE_NAMESPACE . SyncProgress::ROUTE_PATH ) );
		$ajaxUrl  = esc_url_raw( admin_url( 'admin-ajax.php' ) );
		$nonceRest = wp_create_nonce( 'wp_rest' );

		// Build the JS bootstrap object once via wp_json_encode for safe
		// escaping. The object carries every value the poller needs so the
		// script can stay parameterised without `wp_localize_script`.
		$config = wp_json_encode(
			array(
				'restUrl'        => $restUrl,
				'ajaxUrl'        => $ajaxUrl,
				'restNonce'      => $nonceRest,
				'pollIntervalMs' => self::POLL_INTERVAL_MS,
				'initialRunId'   => $activeId > 0 ? $activeId : null,
			)
		);

		if ( ! is_string( $config ) ) {
			$config = '{}';
		}

		?>
<script type="text/javascript">
(function () {
	var config = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON-encoded above. ?>;
	var panel = document.querySelector('.spreadconnect-catalog__during-sync');
	if (!panel) {
		return;
	}
	var form = document.querySelector('.spreadconnect-catalog__sync-now-form');
	var pollTimer = null;
	var TERMINAL_STATES = ['complete', 'failed', 'canceled'];

	function setText(slot, value) {
		var el = panel.querySelector('[data-spreadconnect-slot="' + slot + '"]');
		if (el) {
			el.textContent = String(value);
		}
	}

	function applyResponse(body) {
		if (!body || typeof body !== 'object') {
			return body && body.state;
		}
		setText('counter-created', body.created || 0);
		setText('counter-updated', body.updated || 0);
		setText('counter-skipped', body.skipped || 0);
		setText('counter-errors', body.errors || 0);
		setText('progress-fraction', (body.processed || 0) + ' / ' + (body.total || 0));

		var meter = panel.querySelector('[data-spreadconnect-slot="progress-meter"]');
		if (meter) {
			meter.value = Number(body.processed || 0);
			meter.max = Number(body.total || 0);
		}

		var logEl = panel.querySelector('[data-spreadconnect-slot="log-tail"]');
		if (logEl && Array.isArray(body.last_log_lines)) {
			logEl.textContent = body.last_log_lines.join('\n');
		}

		if (body.run_id) {
			panel.setAttribute('data-run-id', String(body.run_id));
		}

		if (body.state && body.state !== 'idle') {
			panel.removeAttribute('hidden');
		}

		return body.state;
	}

	function poll() {
		var url = config.restUrl;
		var runId = panel.getAttribute('data-run-id');
		if (runId) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + 'run_id=' + encodeURIComponent(runId);
		}
		fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce, 'Accept': 'application/json' }
		}).then(function (resp) {
			if (!resp.ok) {
				return null;
			}
			return resp.json();
		}).then(function (body) {
			var state = applyResponse(body);
			if (state && TERMINAL_STATES.indexOf(state) !== -1) {
				stopPolling();
			} else if (state === 'idle' && !runId) {
				stopPolling();
			}
		}).catch(function () {
			// Swallow transient errors — next tick will retry.
		});
	}

	function startPolling() {
		if (pollTimer) {
			return;
		}
		poll();
		pollTimer = setInterval(poll, config.pollIntervalMs);
	}

	function stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var data = new FormData(form);
			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			}).then(function (resp) { return resp.json(); }).then(function (json) {
				if (json && json.success) {
					if (json.data && json.data.run_id) {
						panel.setAttribute('data-run-id', String(json.data.run_id));
					}
					panel.removeAttribute('hidden');
					startPolling();
				}
			}).catch(function () { /* silent */ });
		});
	}

	if (config.initialRunId) {
		startPolling();
	}
}());
</script>
		<?php
	}
}
