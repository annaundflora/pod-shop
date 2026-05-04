<?php
/**
 * Plugin bootstrap entry-point.
 *
 * Wires hooks and exposes the plugin file path to follow-up slices
 * (HPOS declare in slice-03, schema/dbDelta in slice-04, options-defaults
 * in slice-05, i18n textdomain in slice-06, and beyond).
 *
 * @package SpreadconnectPod\Bootstrap
 */

declare(strict_types=1);

namespace SpreadconnectPod\Bootstrap;

/**
 * Central bootstrap for the Spreadconnect POD plugin.
 *
 * Idempotent: subsequent calls to {@see self::init()} are no-ops once the
 * skeleton has been wired the first time. Future slices will add hook
 * registrations to this class, all of which must remain idempotent under
 * the same guard.
 */
final class Plugin
{
	/**
	 * Whether {@see self::init()} has already executed.
	 */
	private static bool $initialized = false;

	/**
	 * Absolute path to the main plugin file (`spreadconnect-pod.php`).
	 *
	 * Stored on first invocation so that follow-up slices can resolve the
	 * file for `FeaturesUtil::declare_compatibility()` (slice-03),
	 * `plugin_basename()` for i18n (slice-06), `register_activation_hook()`
	 * (slice-04/05) and similar lifecycle helpers.
	 */
	private static string $pluginFile = '';

	/**
	 * Bootstrap the plugin.
	 *
	 * Called from `spreadconnect-pod.php` on file include.
	 *
	 * Idempotency: re-entrant calls return early without re-registering
	 * hooks. This protects against double-includes (e.g. a misconfigured
	 * `must-use` shim) and ensures `_doing_it_wrong` notices never fire.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file
	 *                            (typically `__FILE__` from
	 *                            `spreadconnect-pod.php`).
	 */
	public static function init( string $plugin_file ): void
	{
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		self::$pluginFile  = $plugin_file;

		// Hook registrations are intentionally empty in slice-02.
		//
		// Follow-up slices will extend this method:
		//   - slice-03: HPOS compatibility declare on `before_woocommerce_init`.
		//   - slice-04: `register_activation_hook()` -> Schema::dbDelta().
		//   - slice-05: Options defaults via activation hook.
		//   - slice-06: `plugins_loaded` -> load_plugin_textdomain().
		//   - slice-09+: REST routes, webhook controller, AS handlers, etc.
	}

	/**
	 * Return the absolute path to the main plugin file.
	 *
	 * Consumers (slice-03+) call this to obtain the value originally passed
	 * to {@see self::init()} without having to track `__FILE__` themselves.
	 */
	public static function pluginFile(): string
	{
		return self::$pluginFile;
	}
}
