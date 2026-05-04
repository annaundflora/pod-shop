<?php
/**
 * Settings sanitiser for the WP Settings API.
 *
 * Single source of truth for the validation rules of the 17 user-editable
 * `spreadconnect_*` options registered by `Hub\View\Settings`. The Settings
 * API invokes {@see self::sanitize()} as the `sanitize_callback` of every
 * `register_setting()` call (slice-11 AC-1) and re-uses it for the
 * post-import re-validation in slice-45.
 *
 * Cross-field rule: when `spreadconnect_default_shipping_type` is empty,
 * `spreadconnect_auto_confirm` is forced to `'off'` regardless of UI input
 * (slice-11 AC-3 / `architecture.md` Z. 326 Auto-Confirm-Gating).
 *
 * @package SpreadconnectPod\Settings
 */

declare(strict_types=1);

namespace SpreadconnectPod\Settings;

use SpreadconnectPod\Bootstrap\OptionsDefaults;

/**
 * Stateless sanitiser for the 17-key Settings form payload.
 *
 * Final + only static methods — there is nothing instance-bound about this
 * validator and no DI is required. Enum whitelists and range bounds live in
 * private constants so the class itself is the canonical reference.
 *
 * Output guarantees (slice-11 Constraints):
 *   - All 17 keys are always present in the result, even when the input
 *     omits them (missing keys fall back to {@see OptionsDefaults::DEFAULTS}).
 *   - Booleans are strict PHP `bool`, never strings (`'0'`, `'on'`, etc.).
 *   - Sanitisation is idempotent: `sanitize(sanitize($x)) === sanitize($x)`.
 */
final class SettingsValidator
{
	/**
	 * Allowed values for `spreadconnect_auto_confirm`.
	 *
	 * Mirror of the architecture-spec enum
	 * (`architecture.md` Z. 326). Values outside this list fall back to the
	 * default from {@see OptionsDefaults::DEFAULTS}.
	 *
	 * @var list<string>
	 */
	private const AUTO_CONFIRM_VALUES = array( 'off', 'immediate', 'after_minutes' );

	/**
	 * Allowed values for `spreadconnect_stock_sync_interval`.
	 *
	 * Mirror of the architecture-spec enum
	 * (`architecture.md` Z. 332). Values outside this list fall back to the
	 * default `'6h'`.
	 *
	 * @var list<string>
	 */
	private const STOCK_INTERVAL_VALUES = array( '1h', '4h', '6h', '12h', '24h' );

	/**
	 * Inclusive lower bound for `spreadconnect_live_cache_ttl_seconds`.
	 *
	 * Mirror of `architecture.md` Z. 334 — values below 60 seconds would
	 * overload the live-stock endpoint and are rejected.
	 */
	private const LIVE_CACHE_TTL_MIN = 60;

	/**
	 * Inclusive upper bound for `spreadconnect_live_cache_ttl_seconds`.
	 *
	 * Mirror of `architecture.md` Z. 334 — values above 900 seconds defeat
	 * the live-stock UX promise and are rejected.
	 */
	private const LIVE_CACHE_TTL_MAX = 900;

	/**
	 * Inclusive lower bound for the two retention-day fields.
	 *
	 * Mirror of `architecture.md` Z. 339-340.
	 */
	private const RETENTION_DAYS_MIN = 7;

	/**
	 * Inclusive upper bound for the two retention-day fields.
	 *
	 * Mirror of `architecture.md` Z. 339-340.
	 */
	private const RETENTION_DAYS_MAX = 365;

	/**
	 * The 17 option keys this validator owns.
	 *
	 * Order matches the form-fields table in slice-11 (sections ① ⑥ ⑦ ⑧).
	 * `spreadconnect_webhook_secret` (slice-14) and
	 * `spreadconnect_pt_index_etag` (system-managed, slice-23) are
	 * deliberately omitted — they are not user-editable form fields.
	 *
	 * @var list<string>
	 */
	private const MANAGED_KEYS = array(
		// ① API Connection.
		'spreadconnect_api_key',
		'spreadconnect_use_staging',
		// ⑥ Order Behavior.
		'spreadconnect_auto_confirm',
		'spreadconnect_auto_confirm_minutes',
		'spreadconnect_default_shipping_type',
		'spreadconnect_auto_cancel_mirror',
		// ⑦ Catalog Sync.
		'spreadconnect_pull_images',
		'spreadconnect_force_repull_images',
		'spreadconnect_stock_sync_interval',
		'spreadconnect_low_stock_threshold',
		'spreadconnect_live_cache_ttl_seconds',
		// ⑧ Failure Notifications.
		'spreadconnect_notify_emails',
		'spreadconnect_notify_on_order_failure',
		'spreadconnect_notify_on_sync_failure',
		'spreadconnect_notify_on_webhook_failure',
		'spreadconnect_failed_ops_retention_days',
		'spreadconnect_webhook_log_retention_days',
	);

	/**
	 * Boolean toggle keys among the managed 17.
	 *
	 * Used by {@see self::castBool()} to coerce truthy/falsy strings
	 * (`'0'`, `'1'`, `'on'`, `''`) into strict PHP `bool` (slice-11 AC-7).
	 *
	 * @var list<string>
	 */
	private const BOOLEAN_KEYS = array(
		'spreadconnect_use_staging',
		'spreadconnect_auto_cancel_mirror',
		'spreadconnect_pull_images',
		'spreadconnect_force_repull_images',
		'spreadconnect_notify_on_order_failure',
		'spreadconnect_notify_on_sync_failure',
		'spreadconnect_notify_on_webhook_failure',
	);

	/**
	 * Sanitise a Settings-form payload.
	 *
	 * Authoritative array-shape sanitiser used by:
	 *   - slice-11 acceptance tests (AC-2..AC-8) which exercise the
	 *     full 17-key contract directly.
	 *   - slice-45 export/import re-validation, which pipes the imported
	 *     JSON through `sanitize()` before persisting.
	 *
	 * **Not** registered as a per-option `sanitize_callback` against the WP
	 * Settings API — WP invokes per-option callbacks with the *scalar*
	 * value of one option (via the `sanitize_option_{$name}` filter), so a
	 * function that requires `array $input` would fatally TypeError at
	 * runtime. The Settings API is wired through {@see self::sanitizeOne()}
	 * instead; cross-field gating is enforced by
	 * {@see self::enforceAutoConfirmGating()}.
	 *
	 * Output is **always** the full 17-key map even when `$input` is
	 * partial — missing keys default to {@see OptionsDefaults::DEFAULTS} so
	 * a partial form submit cannot delete options (slice-11 Constraints).
	 *
	 * @param array<string,mixed> $input Raw form payload (or imported JSON
	 *                                   in the slice-45 case).
	 *
	 * @return array<string,string|int|bool> 17-key sanitised result.
	 */
	public static function sanitize( array $input ): array
	{
		$defaults = OptionsDefaults::DEFAULTS;
		$out      = array();

		foreach ( self::MANAGED_KEYS as $key ) {
			$raw      = array_key_exists( $key, $input ) ? $input[ $key ] : null;
			$default  = $defaults[ $key ];
			$out[ $key ] = self::sanitizeField( $key, $raw, $default );
		}

		// Cross-field rule: Auto-Confirm-Gating (slice-11 AC-3,
		// architecture.md Z. 326). When the default shipping type is empty
		// the auto-confirm value is forced to `off` no matter what the UI
		// submitted. This is the ONLY cross-field rule.
		if ( '' === $out['spreadconnect_default_shipping_type'] ) {
			$out['spreadconnect_auto_confirm'] = 'off';
		}

		return $out;
	}

	/**
	 * Per-option `sanitize_callback` dispatcher for the WP Settings API.
	 *
	 * Hooked from {@see \SpreadconnectPod\Hub\View\Settings::registerSettings()}
	 * as the `sanitize_callback` of every `register_setting()` call. WP
	 * invokes the callback with the *scalar* value of a single option via
	 * the `sanitize_option_{$option_name}` filter — so this method runs
	 * only the per-field validation for that one key (no cross-field
	 * gating; that lives in {@see self::enforceAutoConfirmGating()}).
	 *
	 * The option name is resolved in priority order:
	 *   1. The explicit `$optionName` argument (WP 4.7+ passes the option
	 *      name as the second filter argument to `sanitize_option_*`).
	 *   2. {@see current_filter()} fallback — strips the
	 *      `sanitize_option_` prefix off the active filter name.
	 *
	 * Unknown / unmanaged keys are returned unchanged.
	 *
	 * @param mixed       $value      Raw scalar input as delivered by WP.
	 * @param string|null $optionName Option name forwarded by WP (filter
	 *                                arg 2). Falls back to
	 *                                `current_filter()` when omitted.
	 *
	 * @return mixed Sanitised scalar value of the same logical type as the
	 *               option's default (string, int or bool).
	 */
	public static function sanitizeOne( mixed $value, ?string $optionName = null ): mixed
	{
		$key = is_string( $optionName ) && '' !== $optionName
			? $optionName
			: self::resolveOptionFromCurrentFilter();

		if ( null === $key || ! in_array( $key, self::MANAGED_KEYS, true ) ) {
			// Unknown key: return as-is. The dispatcher MUST NOT swallow
			// values for options it does not own.
			return $value;
		}

		$default = OptionsDefaults::DEFAULTS[ $key ];

		return self::sanitizeField( $key, $value, $default );
	}

	/**
	 * Cross-field gating enforced *before* `spreadconnect_auto_confirm` is
	 * written to the database.
	 *
	 * Wired from {@see \SpreadconnectPod\Hub\View\Settings::registerSettings()}
	 * via `add_filter('pre_update_option_spreadconnect_auto_confirm', …)`.
	 * WP runs `pre_update_option_{$option_name}` filters during
	 * `update_option()` after the per-option `sanitize_callback`
	 * ({@see self::sanitizeOne()}) has produced a per-field-valid value but
	 * BEFORE the value is written to `wp_options`. That ordering is the
	 * autoritative correction for the Auto-Confirm-Gating rule (slice-11
	 * AC-3, `architecture.md` Z. 326):
	 *
	 *   1. `wp-admin/options.php` iterates options in `register_setting`
	 *      registration order. {@see \SpreadconnectPod\Hub\View\Settings::registerSettings()}
	 *      registers `spreadconnect_default_shipping_type` BEFORE
	 *      `spreadconnect_auto_confirm`, so by the time WP processes the
	 *      auto-confirm option the new shipping-type value has already been
	 *      persisted.
	 *   2. This filter therefore reads the *just-updated* shipping type via
	 *      `get_option()` and, if it collapsed to `''` (= None), overrides
	 *      `$value` with `'off'`. Otherwise the per-field-validated value
	 *      from {@see self::sanitizeOne()} passes through unchanged.
	 *
	 * The filter is idempotent: running it twice with the same input yields
	 * the same output, so a `pre_update_option` re-entrancy would be safe.
	 *
	 * @param mixed $value New value WP is about to persist for
	 *                     `spreadconnect_auto_confirm` (already
	 *                     per-field-validated by
	 *                     {@see self::sanitizeOne()}).
	 *
	 * @return mixed Either the validated `$value` unchanged, or the literal
	 *               string `'off'` when the gating rule kicks in.
	 */
	public static function gateAutoConfirmOnPreUpdate( mixed $value ): mixed
	{
		$shipping_type = get_option( 'spreadconnect_default_shipping_type', '' );

		// Empty (= None) shipping type forces auto-confirm off, mirroring
		// the array-mode gating in {@see self::sanitize()}.
		if ( ! is_string( $shipping_type ) || '' === $shipping_type ) {
			return 'off';
		}

		return $value;
	}

	/**
	 * Extract the option name from the active `sanitize_option_*` filter.
	 *
	 * Fallback path for hosts that do not forward the option-name argument
	 * to {@see self::sanitizeOne()}. Returns `null` when the active filter
	 * does not look like a `sanitize_option_*` filter.
	 */
	private static function resolveOptionFromCurrentFilter(): ?string
	{
		$filter = current_filter();
		if ( ! is_string( $filter ) || '' === $filter ) {
			return null;
		}

		$prefix = 'sanitize_option_';
		if ( ! str_starts_with( $filter, $prefix ) ) {
			return null;
		}

		$candidate = substr( $filter, strlen( $prefix ) );

		return '' === $candidate ? null : $candidate;
	}

	/**
	 * Per-key sanitisation dispatcher.
	 *
	 * Splits the validation switch out of {@see self::sanitize()} so the
	 * cross-field gating step in `sanitize()` reads top-down and stays
	 * trivially auditable.
	 *
	 * @param string                $key     Option key being sanitised.
	 * @param mixed                 $raw     Raw value from `$input` (or
	 *                                       `null` when the key was missing
	 *                                       from the form payload).
	 * @param string|int|bool       $default Fallback default for this key
	 *                                       from {@see OptionsDefaults::DEFAULTS}.
	 *
	 * @return string|int|bool Sanitised value.
	 */
	private static function sanitizeField( string $key, mixed $raw, string|int|bool $default ): string|int|bool
	{
		// Boolean toggles: missing key (= unchecked checkbox) -> false.
		if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
			return self::castBool( $raw );
		}

		switch ( $key ) {
			case 'spreadconnect_api_key':
				return is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : '';

			case 'spreadconnect_auto_confirm':
				$value = is_string( $raw ) ? $raw : '';
				return in_array( $value, self::AUTO_CONFIRM_VALUES, true )
					? $value
					: (string) $default;

			case 'spreadconnect_auto_confirm_minutes':
				return self::absint( $raw );

			case 'spreadconnect_default_shipping_type':
				// Any non-empty string accepted; SC `shippingType.id` list
				// is not validated against here (slice-11 Constraints —
				// the SC list is pulled in slice-12/29).
				return is_string( $raw ) ? sanitize_text_field( $raw ) : '';

			case 'spreadconnect_stock_sync_interval':
				$value = is_string( $raw ) ? $raw : '';
				return in_array( $value, self::STOCK_INTERVAL_VALUES, true )
					? $value
					: (string) $default;

			case 'spreadconnect_low_stock_threshold':
				return self::absint( $raw );

			case 'spreadconnect_live_cache_ttl_seconds':
				return self::clamp(
					self::absint( $raw ),
					self::LIVE_CACHE_TTL_MIN,
					self::LIVE_CACHE_TTL_MAX
				);

			case 'spreadconnect_notify_emails':
				return self::sanitizeEmailList( $raw );

			case 'spreadconnect_failed_ops_retention_days':
			case 'spreadconnect_webhook_log_retention_days':
				return self::clamp(
					self::absint( $raw ),
					self::RETENTION_DAYS_MIN,
					self::RETENTION_DAYS_MAX
				);
		}

		// Unreachable — every managed key is handled above. Defensive
		// fallback returns the default to keep the output 17-key complete
		// even if a future code path forgets to add a case.
		return $default;
	}

	/**
	 * Cast checkbox-like input into strict PHP `bool` (slice-11 AC-7).
	 *
	 * Truthy: `true`, `'1'`, `'on'`, `1`, `'true'`, `'yes'`.
	 * Falsy:  `false`, `'0'`, `''`, `0`, `null`, missing key.
	 *
	 * @param mixed $raw Raw checkbox value.
	 */
	private static function castBool( mixed $raw ): bool
	{
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_int( $raw ) ) {
			return 1 === $raw;
		}
		if ( is_string( $raw ) ) {
			$normalised = strtolower( trim( $raw ) );
			return in_array( $normalised, array( '1', 'on', 'true', 'yes' ), true );
		}

		return false;
	}

	/**
	 * Convert raw input to a non-negative integer.
	 *
	 * Wraps WP's `absint()` so the rest of the validator can stay
	 * type-clean: any non-numeric input collapses to `0`, negative numbers
	 * become positive (per WP convention), and the result is always `int`.
	 *
	 * @param mixed $raw Raw value (string, int, float, null …).
	 */
	private static function absint( mixed $raw ): int
	{
		if ( is_int( $raw ) ) {
			return absint( $raw );
		}
		if ( is_string( $raw ) || is_float( $raw ) ) {
			return absint( $raw );
		}

		return 0;
	}

	/**
	 * Clamp an integer into an inclusive `[min, max]` range.
	 *
	 * @param int $value Value to clamp (already non-negative via {@see self::absint()}).
	 * @param int $min   Inclusive lower bound.
	 * @param int $max   Inclusive upper bound.
	 */
	private static function clamp( int $value, int $min, int $max ): int
	{
		if ( $value < $min ) {
			return $min;
		}
		if ( $value > $max ) {
			return $max;
		}

		return $value;
	}

	/**
	 * Normalise the comma-separated email list (slice-11 AC-6).
	 *
	 * Splits on `,`, runs each token through `sanitize_email`, drops the
	 * tokens for which `sanitize_email` returns an empty string (invalid),
	 * and rejoins the survivors with `, ` (comma + space) preserving the
	 * original order. Whitespace-only input collapses to `''`.
	 *
	 * @param mixed $raw Raw form value.
	 */
	private static function sanitizeEmailList( mixed $raw ): string
	{
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$valid = array();
		foreach ( explode( ',', $raw ) as $token ) {
			$candidate = trim( $token );
			if ( '' === $candidate ) {
				continue;
			}
			$clean = sanitize_email( $candidate );
			if ( '' !== $clean ) {
				$valid[] = $clean;
			}
		}

		return implode( ', ', $valid );
	}
}
