<?php
/**
 * Default-Options-Setter.
 *
 * Seeds the 18 explicit `spreadconnect_*` Options defined in
 * `architecture.md -> WP Options` (Z. 323-341) on plugin activation. Uses
 * `add_option()` (idempotent: writes only when the option does not yet
 * exist), so re-activating the plugin never overwrites admin-customised
 * values.
 *
 * The 19th option `spreadconnect_webhook_secret` is intentionally NOT
 * seeded here — its `auto-generated` default is produced by
 * `slice-14-webhook-secret-manager` via `random_bytes(32)`.
 *
 * Activation flow: `Bootstrap\Plugin::init()` registers
 * `register_activation_hook( $plugin_file, [ OptionsDefaults::class, 'install' ] )`
 * (slice-05 AC-4) alongside the schema-installer hook from slice-04.
 *
 * @package SpreadconnectPod\Bootstrap
 */

declare(strict_types=1);

namespace SpreadconnectPod\Bootstrap;

/**
 * Seeds plugin options on activation.
 *
 * Stateless utility — exposes only static methods. `install()` is
 * called from the activation hook; it is idempotent because
 * `add_option()` is a no-op when the option already exists. Defaults
 * originate from a single in-class table ({@see self::DEFAULTS}) which
 * mirrors the architecture spec 1:1.
 */
final class OptionsDefaults
{
	/**
	 * Discovery defaults for the 18 explicit `spreadconnect_*` options.
	 *
	 * Single source of truth for the activation seeder, mirroring
	 * `architecture.md -> WP Options` (Z. 323-341). PHP types are
	 * preserved (bool stays bool, int stays int, string stays string)
	 * so WordPress' `add_option()` serialises them correctly without
	 * any string coercion.
	 *
	 * `spreadconnect_webhook_secret` is deliberately omitted: its
	 * default is `auto-generated` (base64 of `random_bytes(32)`) and is
	 * produced by `slice-14-webhook-secret-manager`, not by a literal
	 * value here.
	 *
	 * @var array<string, string|int|bool>
	 */
	public const DEFAULTS = array(
		'spreadconnect_api_key'                     => '',
		'spreadconnect_use_staging'                 => false,
		'spreadconnect_auto_confirm'                => 'off',
		'spreadconnect_auto_confirm_minutes'        => 0,
		'spreadconnect_default_shipping_type'       => '',
		'spreadconnect_auto_cancel_mirror'          => true,
		'spreadconnect_pull_images'                 => true,
		'spreadconnect_force_repull_images'         => false,
		'spreadconnect_stock_sync_interval'         => '6h',
		'spreadconnect_low_stock_threshold'         => 10,
		'spreadconnect_live_cache_ttl_seconds'      => 300,
		'spreadconnect_notify_emails'               => '',
		'spreadconnect_notify_on_order_failure'     => true,
		'spreadconnect_notify_on_sync_failure'      => true,
		'spreadconnect_notify_on_webhook_failure'   => false,
		'spreadconnect_failed_ops_retention_days'   => 90,
		'spreadconnect_webhook_log_retention_days'  => 90,
		'spreadconnect_pt_index_etag'               => '',
	);

	/**
	 * Seed all discovery defaults exactly once.
	 *
	 * Iterates {@see self::DEFAULTS} and calls `add_option()` per key.
	 * `add_option()` is intentionally idempotent: it returns `false`
	 * (no-op) when the option already exists, which preserves any
	 * admin-customised value across re-activations (slice-05 AC-2).
	 *
	 * `update_option()` is deliberately NOT used here — re-activate
	 * must never overwrite admin changes.
	 *
	 * @return void
	 */
	public static function install(): void
	{
		foreach ( self::DEFAULTS as $key => $default ) {
			add_option( $key, $default );
		}
	}
}
