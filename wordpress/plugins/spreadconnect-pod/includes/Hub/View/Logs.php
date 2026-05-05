<?php
/**
 * Logs sub-page renderer (Hub Section "Logs", slice-42).
 *
 * Reads `wc-logs/spreadconnect-*-{date}-{hash}.log` files written by the
 * WC default file-handler, parses each entry into a row, applies the
 * Level / Source / Date-Range filters from `$_GET`, sorts newest-first
 * and renders the last {@see self::TAIL_LIMIT} entries above a CSV-export
 * button. The CSV-export AJAX handler ({@see Logs::handleCsvExport()})
 * shares the same loader pipeline but skips the tail-limit so the entire
 * filtered set is streamed.
 *
 * Wireframe reference: `wireframes.md` Screen 6 (Logs).
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Hub\Controller as HubController;
use SpreadconnectPod\Logging\Sources;

/**
 * Stateless renderer + CSV streamer for the Logs sub-page.
 *
 * Final + only static methods (analog Slice-13 Hub-View-Pattern,
 * architecture.md "Adapter — Admin Page" / Z. 529). The class also hosts
 * the `wp_ajax_spreadconnect_logs_csv_export` handler — slice-42's
 * Deliverables explicitly allow the CSV streamer to live in the same
 * file (Implementer-Wahl) so the two halves of the page share the
 * private filter/parse helpers without a cross-file import.
 *
 * Architecture refs:
 *   - architecture.md Z. 398 (`Logging\WcLoggerAdapter` source list).
 *   - architecture.md Z. 538 + Z. 757 (WC_Logger File-Handler).
 *   - architecture.md Z. 84 (shared `spreadconnect_admin` nonce-action).
 */
final class Logs
{
	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Tail-view row cap (slice-42 AC-5 + Constraints — hard-coded 200).
	 *
	 * The CSV-export does NOT honour this limit — the whole filtered set
	 * is streamed.
	 */
	private const TAIL_LIMIT = 200;

	/**
	 * AJAX-action slug for the `[Download CSV]` button.
	 *
	 * Slice-42 Constraints: "CSV-Export-Action-Name **exakt**
	 * `spreadconnect_logs_csv_export`".
	 */
	public const CSV_AJAX_ACTION = 'spreadconnect_logs_csv_export';

	/**
	 * Shared admin nonce-action — same string as slice-32's order-edit
	 * meta-box (architecture.md Z. 84).
	 */
	private const NONCE_ACTION = 'spreadconnect_admin';

	/**
	 * Whitelist of legal `?level=` values (PSR-3 subset that WC_Logger
	 * uses — slice-42 Constraints).
	 *
	 * @var list<string>
	 */
	private const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Whitelist of legal `?date=` values (slice-42 Constraints — no custom
	 * date-picker in MVP).
	 *
	 * @var list<string>
	 */
	private const DATE_RANGES = array( 'today', '7days', '30days' );

	/**
	 * Default date-range when the query-param is missing or invalid.
	 */
	private const DEFAULT_DATE_RANGE = 'today';

	/**
	 * Render the Logs sub-page.
	 *
	 * Wired via {@see HubController::dispatch()} when `?section=logs`.
	 *
	 * Render-order (slice-42 AC-5):
	 *   1. `<h1>` page title.
	 *   2. Filter & action bar (Level/Source/Date drop-downs + CSV button).
	 *   3. Tail-table (`Time | Level | Source | Message`) with the last
	 *      {@see self::TAIL_LIMIT} matching entries; empty-state when no
	 *      rows match.
	 *
	 * The Sidebar is rendered by {@see HubController::dispatch()} — view
	 * classes never repeat the navigation markup (slice-13 AC-10).
	 *
	 * @return void
	 */
	public static function render(): void
	{
		$filters = self::resolveFilters();
		$entries = self::loadEntries( $filters );
		$tail    = array_slice( $entries, 0, self::TAIL_LIMIT );

		echo '<h1 class="spreadconnect-hub__title">'
			. esc_html__( 'Logs', self::TEXT_DOMAIN )
			. '</h1>';

		self::renderFilterBar( $filters );
		self::renderTailTable( $tail );
	}

	/**
	 * Stream a CSV download with all filter-matching entries.
	 *
	 * Wired via `add_action('wp_ajax_spreadconnect_logs_csv_export', …)` in
	 * `Bootstrap\Plugin::init()`. Hard gates run BEFORE any header is
	 * emitted so a 403 reply stays a clean JSON / `wp_die` response.
	 *
	 * Streaming format (slice-42 AC-9):
	 *   - Header line `Time,Level,Source,Message`.
	 *   - One row per filter-matching entry — NO 200 row cap.
	 *   - `Content-Type: text/csv`,
	 *     `Content-Disposition: attachment; filename="spreadconnect-logs-YYYYMMDD-HHMMSS.csv"`.
	 *   - `fputcsv()` handles quoting / escaping; no extra HTML encoding.
	 *
	 * Terminates via `exit` after streaming so WP's later admin-ajax
	 * teardown does not append a stray `0` to the body.
	 *
	 * @return void
	 */
	public static function handleCsvExport(): void
	{
		// AC-9: Cap-gate first. `ensureCapability()` `wp_die()`s on
		// failure; the explicit guard below keeps a non-terminating test
		// stub from falling through.
		HubController::ensureCapability();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::abort403();
			return;
		}

		// AC-9: nonce-gate. `false` 3rd arg → helper returns `false`
		// instead of `wp_die()`-ing so we can shape a uniform 403.
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce', false ) ) {
			self::abort403();
			return;
		}

		$filters = self::resolveFilters();
		$entries = self::loadEntries( $filters );

		$timestamp = function_exists( 'current_time' )
			? (string) current_time( 'YmdHis' )
			: gmdate( 'YmdHis' );
		$filename  = 'spreadconnect-logs-' . $timestamp . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array( 'Time', 'Level', 'Source', 'Message' ) );

		foreach ( $entries as $entry ) {
			fputcsv(
				$out,
				array(
					$entry['time'],
					$entry['level'],
					$entry['source'],
					$entry['message'],
				)
			);
		}

		fclose( $out );
		exit;
	}

	// ------------------------------------------------------------------
	// Filter resolution
	// ------------------------------------------------------------------

	/**
	 * Read `?level=`, `?source=`, `?date=` from `$_GET` and project them
	 * onto the three whitelists.
	 *
	 * Unknown / missing values fall back to the safe default (`All`,
	 * `All`, `today`) — slice-42 AC-7 forbids 404/exception on a bad
	 * filter value.
	 *
	 * @return array{level: string, source: string, date: string} The
	 *         canonical filter triple — `level`/`source` is the empty
	 *         string when "All" is selected.
	 */
	private static function resolveFilters(): array
	{
		$level  = '';
		$source = '';
		$date   = self::DEFAULT_DATE_RANGE;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['level'] ) && is_string( $_GET['level'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_GET['level'] ) );
			if ( in_array( $candidate, self::LEVELS, true ) ) {
				$level = $candidate;
			}
		}

		if ( isset( $_GET['source'] ) && is_string( $_GET['source'] ) ) {
			// `sanitize_text_field` keeps the dashes that the source-string
			// uses (`spreadconnect-api-client`); `sanitize_key` would
			// strip them.
			$candidate = sanitize_text_field( wp_unslash( $_GET['source'] ) );
			if ( in_array( $candidate, Sources::ALL, true ) ) {
				$source = $candidate;
			}
		}

		if ( isset( $_GET['date'] ) && is_string( $_GET['date'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_GET['date'] ) );
			if ( in_array( $candidate, self::DATE_RANGES, true ) ) {
				$date = $candidate;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'level'  => $level,
			'source' => $source,
			'date'   => $date,
		);
	}

	/**
	 * Convert a date-range slug into a Unix-timestamp lower-bound.
	 *
	 * - `today`  → 00:00 of today (local TZ via `current_time('timestamp')`).
	 * - `7days`  → now - 7d (rolling).
	 * - `30days` → now - 30d (rolling).
	 *
	 * Tests stub `current_time` via Brain\Monkey; production uses the WP
	 * helper so DST and site-TZ are honoured.
	 *
	 * @param string $range One of {@see self::DATE_RANGES}.
	 *
	 * @return int Unix timestamp; entries `>= $minTimestamp` pass.
	 */
	private static function rangeStart( string $range ): int
	{
		$now = function_exists( 'current_time' )
			? (int) current_time( 'timestamp' )
			: time();

		switch ( $range ) {
			case '7days':
				return $now - ( 7 * DAY_IN_SECONDS );
			case '30days':
				return $now - ( 30 * DAY_IN_SECONDS );
			case 'today':
			default:
				$start = strtotime( 'today', $now );
				return false === $start ? $now : $start;
		}
	}

	// ------------------------------------------------------------------
	// File loading + parsing
	// ------------------------------------------------------------------

	/**
	 * Load and filter all matching log entries, newest first.
	 *
	 * Algorithm:
	 *   1. Resolve `wc-logs/` directory via `wp_upload_dir()`.
	 *   2. `glob()` the six `spreadconnect-{source}-*.log` patterns —
	 *      slice-42 AC-6 requires that no other plugin's log files are
	 *      ever read.
	 *   3. Parse each file line-by-line; multi-line continuations (stack
	 *      traces) are appended to the previous entry's message.
	 *   4. Apply the level / source / date-range filters in-process.
	 *   5. Sort descending by timestamp; return the full list (callers
	 *      slice the head themselves).
	 *
	 * @param array{level: string, source: string, date: string} $filters
	 *
	 * @return list<array{time: string, level: string, source: string,
	 *                    message: string, ts: int}>
	 */
	private static function loadEntries( array $filters ): array
	{
		$dir = self::resolveLogsDirectory();
		if ( '' === $dir ) {
			return array();
		}

		$rangeStart = self::rangeStart( $filters['date'] );

		// AC-6: slice-42's source whitelist is the only set of filename
		// prefixes we ever expand — `fatal-errors-*.log` etc. are skipped
		// entirely. If a `?source=` filter is set, we restrict the prefix
		// list to that single source so we don't even open files we know
		// will be filtered out downstream.
		$sourcesToScan = '' !== $filters['source']
			? array( $filters['source'] )
			: Sources::ALL;

		$entries = array();
		foreach ( $sourcesToScan as $sourceName ) {
			$pattern = rtrim( $dir, '/\\' ) . '/' . $sourceName . '-*.log';
			$files   = glob( $pattern );
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$rows = self::parseLogFile( (string) $file, $sourceName );
				foreach ( $rows as $row ) {
					if ( '' !== $filters['level'] && $row['level'] !== $filters['level'] ) {
						continue;
					}
					if ( $row['ts'] < $rangeStart ) {
						continue;
					}
					$entries[] = $row;
				}
			}
		}

		// Newest first.
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $b['ts'] <=> $a['ts'];
			}
		);

		return $entries;
	}

	/**
	 * Resolve the `wc-logs/` directory.
	 *
	 * Slice-42 Constraints forbid a hard-coded path. We try
	 * `WC_Log_Handler_File::get_log_file_path()` first (returns a per-source
	 * absolute path; the parent dir is the canonical `wc-logs/` location),
	 * then fall back to `wp_upload_dir()['basedir'] . '/wc-logs/'`. If
	 * neither helper is available, return the empty string and let the
	 * loader treat it as "no entries".
	 */
	private static function resolveLogsDirectory(): string
	{
		if ( method_exists( '\WC_Log_Handler_File', 'get_log_file_path' ) ) {
			$probe = \WC_Log_Handler_File::get_log_file_path( Sources::API_CLIENT );
			if ( is_string( $probe ) && '' !== $probe ) {
				$dir = dirname( $probe );
				if ( '' !== $dir && '.' !== $dir ) {
					return $dir;
				}
			}
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ) {
				return rtrim( $uploads['basedir'], '/\\' ) . '/wc-logs';
			}
		}

		return '';
	}

	/**
	 * Parse a single WC log file into entry-rows.
	 *
	 * WC_Logger writes one line per entry in the format
	 * `YYYY-MM-DDTHH:MM:SS+ZZ:ZZ LEVEL message`. Multi-line messages
	 * (stack traces) emit continuation-lines that do NOT begin with an
	 * ISO-timestamp; the parser appends those to the previous entry's
	 * `message` field via `\n` so the tail-table renders the trace as a
	 * single row.
	 *
	 * @param string $file        Absolute path to a `wc-logs/*.log` file.
	 * @param string $sourceLabel Source-string assigned to every parsed
	 *                            row (the filename's source prefix).
	 *
	 * @return list<array{time: string, level: string, source: string,
	 *                    message: string, ts: int}>
	 */
	private static function parseLogFile( string $file, string $sourceLabel ): array
	{
		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return array();
		}

		$lines   = preg_split( "/\r\n|\n|\r/", $contents );
		$entries = array();
		$current = null;

		// ISO-8601 prefix WC writes — `YYYY-MM-DDTHH:MM:SS+ZZ:ZZ` —
		// followed by a space, an upper-case level word and the rest of
		// the message. Captured groups: 1=ts string, 2=level, 3=message.
		$regex = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z))\s+([A-Z]+)\s+(.*)$/';

		if ( ! is_array( $lines ) ) {
			return array();
		}

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				if ( null !== $current ) {
					$current['message'] .= "\n";
				}
				continue;
			}

			$matches = array();
			if ( preg_match( $regex, $line, $matches ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}
				$ts = strtotime( $matches[1] );
				if ( false === $ts ) {
					$ts = 0;
				}
				$current = array(
					'time'    => $matches[1],
					'level'   => strtolower( $matches[2] ),
					'source'  => $sourceLabel,
					'message' => $matches[3],
					'ts'      => $ts,
				);
				continue;
			}

			// Continuation line (stack-trace) — append to the previous
			// entry's message. If we somehow see a continuation with no
			// preceding header, drop it silently; keeping it would mean
			// inventing a fake timestamp.
			if ( null !== $current ) {
				$current['message'] .= "\n" . $line;
			}
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		return $entries;
	}

	// ------------------------------------------------------------------
	// Markup helpers
	// ------------------------------------------------------------------

	/**
	 * Render the filter & action bar (① in wireframes.md Screen 6).
	 *
	 * Three drop-downs (Level / Source / Date-Range) wrapped in a `<form
	 * method="get">` so Submit triggers a fresh page load with the new
	 * filter values; the hidden `page=spreadconnect&section=logs` inputs
	 * keep the URL on the Logs sub-page. The CSV-export button is a
	 * POST link wired against the AJAX action; the nonce is minted with
	 * `wp_create_nonce( 'spreadconnect_admin' )` so the streamer's
	 * `check_ajax_referer` passes.
	 *
	 * @param array{level: string, source: string, date: string} $filters
	 */
	private static function renderFilterBar( array $filters ): void
	{
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$csvUrl = admin_url(
			'admin-ajax.php?action=' . self::CSV_AJAX_ACTION
			. '&_ajax_nonce=' . rawurlencode( $nonce )
			. '&level=' . rawurlencode( $filters['level'] )
			. '&source=' . rawurlencode( $filters['source'] )
			. '&date=' . rawurlencode( $filters['date'] )
		);

		echo '<div class="spreadconnect-logs__filters">';

		echo '<form method="get" action="" class="spreadconnect-logs__filter-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubController::MENU_SLUG ) . '" />';
		echo '<input type="hidden" name="section" value="logs" />';

		// Level drop-down.
		echo '<label class="spreadconnect-logs__filter-label">';
		echo '<span>' . esc_html__( 'Level', self::TEXT_DOMAIN ) . '</span>';
		echo '<select name="level">';
		echo '<option value=""' . selected( '', $filters['level'], false ) . '>'
			. esc_html__( 'All', self::TEXT_DOMAIN ) . '</option>';
		foreach ( self::LEVELS as $levelOption ) {
			echo '<option value="' . esc_attr( $levelOption ) . '"'
				. selected( $levelOption, $filters['level'], false ) . '>'
				. esc_html( $levelOption ) . '</option>';
		}
		echo '</select>';
		echo '</label>';

		// Source drop-down — six entries from {@see Sources::ALL} + "All".
		echo '<label class="spreadconnect-logs__filter-label">';
		echo '<span>' . esc_html__( 'Source', self::TEXT_DOMAIN ) . '</span>';
		echo '<select name="source">';
		echo '<option value=""' . selected( '', $filters['source'], false ) . '>'
			. esc_html__( 'All', self::TEXT_DOMAIN ) . '</option>';
		foreach ( Sources::ALL as $sourceOption ) {
			echo '<option value="' . esc_attr( $sourceOption ) . '"'
				. selected( $sourceOption, $filters['source'], false ) . '>'
				. esc_html( $sourceOption ) . '</option>';
		}
		echo '</select>';
		echo '</label>';

		// Date-range drop-down.
		echo '<label class="spreadconnect-logs__filter-label">';
		echo '<span>' . esc_html__( 'Date', self::TEXT_DOMAIN ) . '</span>';
		echo '<select name="date">';
		$dateLabels = array(
			'today'  => __( 'Today', self::TEXT_DOMAIN ),
			'7days'  => __( 'Last 7 days', self::TEXT_DOMAIN ),
			'30days' => __( 'Last 30 days', self::TEXT_DOMAIN ),
		);
		foreach ( self::DATE_RANGES as $rangeOption ) {
			echo '<option value="' . esc_attr( $rangeOption ) . '"'
				. selected( $rangeOption, $filters['date'], false ) . '>'
				. esc_html( $dateLabels[ $rangeOption ] ) . '</option>';
		}
		echo '</select>';
		echo '</label>';

		echo '<button type="submit" class="button">'
			. esc_html__( 'Apply', self::TEXT_DOMAIN )
			. '</button>';

		echo '</form>';

		echo '<a class="button button-secondary spreadconnect-logs__csv-button" href="'
			. esc_url( $csvUrl ) . '">'
			. esc_html__( 'Download CSV', self::TEXT_DOMAIN )
			. '</a>';

		echo '</div>'; // .spreadconnect-logs__filters
	}

	/**
	 * Render the tail table (③ in wireframes.md Screen 6).
	 *
	 * Empty-state per slice-42 AC-8.
	 *
	 * @param list<array{time: string, level: string, source: string,
	 *                   message: string, ts: int}> $entries
	 */
	private static function renderTailTable( array $entries ): void
	{
		echo '<div class="spreadconnect-logs__tail">';

		if ( empty( $entries ) ) {
			echo '<p class="spreadconnect-logs__empty">'
				. esc_html__( 'No log entries match current filters.', self::TEXT_DOMAIN )
				. '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped spreadconnect-logs__table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', self::TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'Level', self::TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'Source', self::TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'Message', self::TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $entries as $entry ) {
			echo '<tr class="spreadconnect-logs__row spreadconnect-logs__row--'
				. esc_attr( $entry['level'] ) . '">';
			echo '<td>' . esc_html( $entry['time'] ) . '</td>';
			echo '<td>' . esc_html( $entry['level'] ) . '</td>';
			echo '<td>' . esc_html( $entry['source'] ) . '</td>';
			echo '<td><pre class="spreadconnect-logs__message">'
				. esc_html( $entry['message'] ) . '</pre></td>';
			echo '</tr>';
		}
		echo '</tbody>';

		echo '</table>';
		echo '</div>'; // .spreadconnect-logs__tail
	}

	/**
	 * Emit a clean HTTP 403 + textual reason and terminate the request.
	 *
	 * Used by both the cap-gate and the nonce-gate paths in
	 * {@see self::handleCsvExport()} so a forged or expired request never
	 * reaches the file-glob phase.
	 */
	private static function abort403(): void
	{
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Forbidden';
		exit;
	}
}
