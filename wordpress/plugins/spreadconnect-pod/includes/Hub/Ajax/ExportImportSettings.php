<?php
/**
 * Admin-AJAX handlers for the Settings → Export/Import buttons (Slice 45).
 *
 * Two related actions live in this single class because they share the same
 * capability gate (`manage_woocommerce`), the same option-key universe
 * ({@see OptionsDefaults::DEFAULTS}) and a coupled UX flow (export the
 * current configuration → ship the JSON to the target site → import on
 * the other side):
 *
 *   - `wp_ajax_spreadconnect_export_settings`
 *       → Streams a `JSON_PRETTY_PRINT`-formatted JSON envelope of every
 *         non-secret `spreadconnect_*` option as an `attachment` download.
 *
 *   - `wp_ajax_spreadconnect_import_settings`
 *       → Reads the raw JSON via `$_POST['payload']`, validates the schema,
 *         filters via the {@see OptionsDefaults::DEFAULTS} whitelist,
 *         re-runs {@see SettingsValidator::sanitize()} and persists each key
 *         via `update_option()`.
 *
 * Hard gates (architecture.md Z. 484, mirror of slice-12 / slice-14 /
 * slice-19): capability check FIRST, nonce check SECOND. Either miss
 * terminates via `wp_send_json_error( …, 403 )` BEFORE any options are read
 * or written.
 *
 * Defense-in-depth secret-exclusion (architecture.md Z. 496): the two
 * `SECRET_KEYS` are filtered out at BOTH ends of the roundtrip — once at
 * export-serialise time (so the JSON file never carries them) AND once at
 * import-persist time (so a hand-edited JSON cannot smuggle them in either).
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Bootstrap\OptionsDefaults;
use SpreadconnectPod\Settings\SettingsValidator;

/**
 * Stateless front-controller for the two Export/Import AJAX actions.
 *
 * Final + only static methods — pattern-mirror of slice-12 `TestConnection`,
 * slice-14 `RegenerateSecret` and slice-19 `RepairSubscriptions` for
 * consistency with the rest of `Hub\Ajax`.
 */
final class ExportImportSettings
{
	/**
	 * Capability required to invoke either AJAX action.
	 *
	 * Mirrors slice-13 `Hub\Controller::REQUIRED_CAP`. Every Hub admin
	 * surface is gated on `manage_woocommerce`. Never widened to
	 * `manage_options` (too permissive).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * AJAX action slug for "stream the settings JSON to the browser".
	 */
	public const ACTION_EXPORT = 'spreadconnect_export_settings';

	/**
	 * AJAX action slug for "ingest a previously-exported JSON".
	 */
	public const ACTION_IMPORT = 'spreadconnect_import_settings';

	/**
	 * POST field carrying the nonce. WP-standard for admin-ajax.
	 */
	private const NONCE_FIELD = '_wpnonce';

	/**
	 * Plugin text-domain for `__()` wrappers. Must match slice-06 / slice-11.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Schema version of the export envelope. Bumped on backwards-incompatible
	 * payload changes; the importer rejects any other value with
	 * `schema_mismatch`. Slice 45 ships exactly version 1; future migrations
	 * happen in a follow-up slice.
	 *
	 * @var int
	 */
	public const EXPORT_VERSION = 1;

	/**
	 * Option keys that MUST never appear in the export JSON or be overwritten
	 * by the importer (architecture.md Z. 496 — "Settings-Export JSON
	 * excludes `spreadconnect_api_key` and `spreadconnect_webhook_secret` —
	 * Prevents secret exfil via export").
	 *
	 * Defense-in-depth: enforced at both ends of the roundtrip (see class
	 * docblock).
	 *
	 * @var list<string>
	 */
	public const SECRET_KEYS = array(
		'spreadconnect_api_key',
		'spreadconnect_webhook_secret',
	);

	/**
	 * Register both `wp_ajax_*` actions.
	 *
	 * Called from `Bootstrap\Plugin::init()` (mount-point per slice-45
	 * Deliverable note). Only the authenticated variant is registered —
	 * anonymous callers must never be able to read or rewrite operational
	 * settings.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action(
			'wp_ajax_' . self::ACTION_EXPORT,
			array( self::class, 'handleExport' )
		);
		add_action(
			'wp_ajax_' . self::ACTION_IMPORT,
			array( self::class, 'handleImport' )
		);
	}

	/**
	 * Handle a `spreadconnect_export_settings` AJAX call.
	 *
	 * Terminates via `wp_send_json_error()` on a gate-miss or
	 * `wp_send_json_success()` (with the JSON envelope as the data payload)
	 * on success. The Content-Disposition + Content-Type headers are emitted
	 * directly so the browser presents the response as a `.json` download
	 * instead of inlining it — `wp_send_json_*` is intentionally NOT used on
	 * the success path because we need raw `JSON_PRETTY_PRINT` output and
	 * full control over the headers.
	 *
	 * Hard-gates (both run before any options are read):
	 *   1. `current_user_can( 'manage_woocommerce' )` → 403 on miss.
	 *   2. `check_admin_referer( ACTION_EXPORT, '_wpnonce', false )` → 403 on miss.
	 *
	 * Output schema (AC-2):
	 *   {
	 *     "version":     1,                       // {@see self::EXPORT_VERSION}
	 *     "exported_at": "2026-05-04T10:30:00Z",  // ISO-8601 UTC
	 *     "options":     { …17 non-secret keys… }
	 *   }
	 *
	 * @return void
	 */
	public static function handleExport(): void
	{
		// AC-3: capability hard-gate. Capability runs FIRST (Constraints
		// "Capability-Check vor Nonce-Check") so an unprivileged session
		// never even touches the nonce machinery.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
					'code'    => 'forbidden',
				),
				403
			);
			return; // Defensive — wp_send_json_* terminates, but tests stub it.
		}

		// AC-3: nonce hard-gate. `false` keeps `check_admin_referer` from
		// `wp_die()`-ing on its own so we can shape the JSON error
		// uniformly. The nonce is minted in {@see Hub\View\Settings::renderExportImportSection}
		// against `self::ACTION_EXPORT` and shipped to the browser via the
		// `data-nonce` attribute on the [Export Settings JSON] button.
		if ( ! check_admin_referer( self::ACTION_EXPORT, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
					'code'    => 'invalid_nonce',
				),
				403
			);
			return;
		}

		// AC-1 / AC-2: build the envelope. Iteration source is
		// {@see OptionsDefaults::DEFAULTS} (single source of truth for the
		// option universe — Integration Contract slice-05). Secret keys are
		// filtered out BEFORE serialisation so they never appear in the
		// payload — not even as empty strings.
		$options = array();
		foreach ( OptionsDefaults::DEFAULTS as $key => $default ) {
			if ( in_array( $key, self::SECRET_KEYS, true ) ) {
				continue; // Defense-in-depth: never serialise secrets.
			}
			// Use the per-key default as the get_option fallback so a missing
			// row in `wp_options` (e.g. fresh install) yields the canonical
			// default rather than `false`.
			$options[ $key ] = get_option( $key, $default );
		}

		$payload = array(
			'version'     => self::EXPORT_VERSION,
			'exported_at' => self::isoTimestamp(),
			'options'     => $options,
		);

		// `JSON_PRETTY_PRINT` (Constraints) makes the file human-inspectable
		// before re-import. `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
		// keeps the content readable for non-ASCII operator setups.
		$encoded = json_encode(
			$payload,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $encoded ) ) {
			// Defensive: `json_encode` should never fail for plain
			// scalar/array data, but if it does we surface a 500 rather than
			// an empty download.
			wp_send_json_error(
				array(
					'message' => __( 'Failed to serialise settings.', self::TEXT_DOMAIN ),
					'code'    => 'encode_failed',
				),
				500
			);
			return;
		}

		// AC-1: download headers. `current_time('Y-m-d_His')` ensures
		// distinct filenames per export so an operator can build up a
		// timestamped backup history without collisions.
		$timestamp = function_exists( 'current_time' )
			? (string) current_time( 'Y-m-d_His' )
			: gmdate( 'Y-m-d_His' );
		$filename  = 'spreadconnect-settings-' . $timestamp . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo $encoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON body, not HTML.

		// Mirror the WP `wp_send_json_*` termination behaviour without
		// re-encoding the body (which `wp_send_json_success` would do, and
		// would also wrap our envelope in a `{success, data}` shell that
		// breaks the contract).
		if ( ! defined( 'SPREADCONNECT_TESTS' ) ) {
			// @codeCoverageIgnoreStart
			wp_die( '', '', array( 'response' => null ) );
			// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Handle a `spreadconnect_import_settings` AJAX call.
	 *
	 * Terminates via `wp_send_json_*`. Hard gates run first; only when both
	 * pass does the parser touch `$_POST['payload']`. The flow is:
	 *
	 *   1. Cap + nonce gate     → 403 on miss.
	 *   2. JSON parse           → 400 `invalid_json` on syntax error.
	 *   3. Schema validate      → 400 `schema_mismatch` on missing keys or
	 *                             `version != EXPORT_VERSION`.
	 *   4. Whitelist filter     → drop keys not in
	 *                             {@see OptionsDefaults::DEFAULTS}.
	 *   5. Secret-key filter    → drop {@see self::SECRET_KEYS} (defense-in-
	 *                             depth — also enforced at export time).
	 *   6. Re-sanitise          → {@see SettingsValidator::sanitize()} (reuses
	 *                             slice-11 rules: enum reset, range clamp,
	 *                             auto-confirm gating).
	 *   7. Persist              → `update_option()` per surviving key.
	 *
	 * Response on success: `{imported:int, skipped_secret_keys:string[],
	 * skipped_unknown_keys:string[]}` (Constraints).
	 *
	 * @return void
	 */
	public static function handleImport(): void
	{
		// AC-3 mirror: capability hard-gate FIRST.
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
					'code'    => 'forbidden',
				),
				403
			);
			return;
		}

		// AC-3 mirror: nonce hard-gate SECOND.
		if ( ! check_admin_referer( self::ACTION_IMPORT, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
					'code'    => 'invalid_nonce',
				),
				403
			);
			return;
		}

		// AC-6: read raw JSON from `payload`. The Settings.php click-handler
		// reads the user-selected `.json` file via `FileReader.readAsText()`
		// and POSTs the resulting string under this field name (Constraints
		// "Import-Body via $_POST['payload']").
		$rawPayload = isset( $_POST['payload'] ) ? $_POST['payload'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended — nonce checked above.
		if ( ! is_string( $rawPayload ) ) {
			$rawPayload = '';
		}
		$rawPayload = (string) wp_unslash( $rawPayload );

		$decoded = json_decode( $rawPayload, true );

		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			// AC-6: invalid JSON → 400, do NOT touch any option. The check
			// also catches `null`, scalars, arrays-with-trailing-bytes etc.
			wp_send_json_error(
				array(
					'message' => __( 'Invalid JSON', self::TEXT_DOMAIN ),
					'code'    => 'invalid_json',
				),
				400
			);
			return;
		}

		// AC-7: schema validate. The envelope must carry an explicit
		// `version` integer that matches the supported one and an `options`
		// associative array. Anything else → 400.
		if ( ! array_key_exists( 'version', $decoded )
			|| (int) $decoded['version'] !== self::EXPORT_VERSION ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unsupported export schema version.', self::TEXT_DOMAIN ),
					'code'    => 'schema_mismatch',
				),
				400
			);
			return;
		}

		if ( ! array_key_exists( 'options', $decoded )
			|| ! is_array( $decoded['options'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Export envelope is missing the options block.', self::TEXT_DOMAIN ),
					'code'    => 'schema_mismatch',
				),
				400
			);
			return;
		}

		/** @var array<string,mixed> $importedOptions */
		$importedOptions = $decoded['options'];

		// AC-8: whitelist filter — keep only keys that exist in the canonical
		// defaults table. Unknown keys never reach the sanitiser (Defense-
		// in-Depth: an attacker could otherwise pollute `wp_options` with
		// arbitrary `spreadconnect_*` keys).
		$defaults             = OptionsDefaults::DEFAULTS;
		$skippedUnknownKeys   = array();
		$candidateOptions     = array();
		foreach ( $importedOptions as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $defaults ) ) {
				$skippedUnknownKeys[] = $key;
				continue;
			}
			$candidateOptions[ $key ] = $value;
		}

		// AC-4: secret-key filter — even if the JSON carries them, refuse to
		// overwrite the local site's secrets. Tracks which keys were
		// stripped so the success response can report them back.
		$skippedSecretKeys = array();
		foreach ( self::SECRET_KEYS as $secretKey ) {
			if ( array_key_exists( $secretKey, $candidateOptions ) ) {
				$skippedSecretKeys[] = $secretKey;
				unset( $candidateOptions[ $secretKey ] );
			}
		}

		// AC-5: re-sanitise via the slice-11 authoritative validator.
		// `SettingsValidator::sanitize()` always returns the FULL 17-key map
		// (filling in defaults for anything missing in `$candidateOptions`)
		// and applies enum reset, range clamp and auto-confirm gating —
		// guaranteeing that whatever lands in `wp_options` follows the same
		// rules a Settings-form save would. The map intentionally includes
		// `spreadconnect_api_key` (because the validator owns that key for
		// form-saves), so we strip it again right after to honour the
		// secret-exclusion contract.
		$sanitised = SettingsValidator::sanitize( $candidateOptions );

		foreach ( self::SECRET_KEYS as $secretKey ) {
			unset( $sanitised[ $secretKey ] );
		}

		// AC-4 / AC-10: persist. Only keys that were actually present in the
		// import (post-whitelist + post-secret-filter) get a write — we do
		// NOT echo defaults back into `wp_options` for missing keys, which
		// would silently overwrite local customisations made between
		// export and import.
		$imported = 0;
		foreach ( $sanitised as $key => $value ) {
			if ( ! array_key_exists( $key, $candidateOptions ) ) {
				continue; // Key was missing in import — keep target-site value.
			}
			update_option( $key, $value );
			++$imported;
		}

		wp_send_json_success(
			array(
				'imported'             => $imported,
				'skipped_secret_keys'  => self::SECRET_KEYS,
				'skipped_unknown_keys' => $skippedUnknownKeys,
				// `skipped_secret_keys` is the canonical contract list (AC-5)
				// regardless of whether the keys appeared in the import
				// payload — the operator should always see which keys are
				// out-of-scope. The `actually_skipped_secret_keys` debug
				// hint reports which of those keys were physically present
				// and dropped, for log diagnostics.
				'actually_skipped_secret_keys' => $skippedSecretKeys,
			)
		);
	}

	/**
	 * Produce an ISO-8601 UTC timestamp (no microseconds) for the
	 * `exported_at` envelope field.
	 *
	 * Uses `current_time()` when available (WP runtime) and falls back to
	 * `gmdate()` for unit-test bootstraps that do not load WP core.
	 */
	private static function isoTimestamp(): string
	{
		if ( function_exists( 'current_time' ) ) {
			$ts = current_time( 'timestamp', true ); // GMT.
			if ( is_int( $ts ) ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
			}
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
