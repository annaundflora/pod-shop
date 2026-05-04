<?php
/**
 * Database schema installer / uninstaller.
 *
 * Owns the three custom tables consumed by the plugin's repos:
 *   - `{$wpdb->prefix}spreadconnect_failed_ops`   (slice-37 FailedOpsRepo)
 *   - `{$wpdb->prefix}spreadconnect_webhook_log`  (slice-16 WebhookLogRepo)
 *   - `{$wpdb->prefix}spreadconnect_sync_history` (slice-23 SyncHistoryRepo)
 *
 * Activation flow: `Bootstrap\Plugin::init()` registers
 * `register_activation_hook( $plugin_file, [ Schema::class, 'install' ] )`
 * (slice-04 AC-5). Uninstall flow: `uninstall.php` calls
 * `Schema::uninstall()` after the `WP_UNINSTALL_PLUGIN` guard
 * (slice-04 AC-4 + AC-7).
 *
 * Schema spec: see architecture.md -> "Database Schema" -> "Schema Details".
 *
 * @package SpreadconnectPod\Bootstrap
 */

declare(strict_types=1);

namespace SpreadconnectPod\Bootstrap;

/**
 * Installs and removes the plugin's custom MySQL tables.
 *
 * Stateless utility — exposes only static methods. `install()` is called
 * from the activation hook; it is idempotent because `dbDelta()` performs
 * additive diffs against the live schema and never drops user data.
 * `uninstall()` is invoked from `uninstall.php` and unconditionally drops
 * all three tables (the `WP_UNINSTALL_PLUGIN` guard is the caller's
 * responsibility per slice-04 AC-7).
 */
final class Schema
{
	/**
	 * Create or update the plugin's custom tables.
	 *
	 * Loads `wp-admin/includes/upgrade.php` (where `dbDelta()` lives) and
	 * delegates the three CREATE-TABLE statements to it. `dbDelta()` is
	 * additive: re-running this method on an existing schema is a no-op
	 * for unchanged tables and only applies diffs (new columns, new
	 * indexes) for changed ones. Therefore this method is safe to invoke
	 * on every plugin activation.
	 *
	 * dbDelta formatting rules observed (per
	 * https://developer.wordpress.org/reference/functions/dbdelta/):
	 *   - Each column / KEY on its own line.
	 *   - Two spaces between `PRIMARY KEY` and the column name.
	 *   - One space between column name and column type.
	 *   - Charset/collate appended to every CREATE statement.
	 *
	 * @return void
	 */
	public static function install(): void
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix         = $wpdb->prefix;
		$charsetCollate = $wpdb->get_charset_collate();

		$failedOpsTable    = $prefix . 'spreadconnect_failed_ops';
		$webhookLogTable   = $prefix . 'spreadconnect_webhook_log';
		$syncHistoryTable  = $prefix . 'spreadconnect_sync_history';

		$failedOpsSql = "CREATE TABLE {$failedOpsTable} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  op_type VARCHAR(64) NOT NULL,
  related_entity_type VARCHAR(32) NOT NULL,
  related_entity_id VARCHAR(64) NOT NULL,
  payload LONGTEXT NOT NULL,
  error_message TEXT NULL,
  error_code VARCHAR(64) NULL,
  retries_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_attempt_at DATETIME NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'unresolved',
  PRIMARY KEY  (id),
  KEY idx_state_op_type (state, op_type),
  KEY idx_related_entity (related_entity_type, related_entity_id),
  KEY idx_created_at (created_at)
) {$charsetCollate};";

		$webhookLogSql = "CREATE TABLE {$webhookLogTable} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  event_id CHAR(64) NOT NULL,
  related_entity_type VARCHAR(32) NOT NULL,
  related_entity_id VARCHAR(64) NOT NULL,
  payload LONGTEXT NOT NULL,
  hmac_status VARCHAR(16) NOT NULL,
  processing_status VARCHAR(16) NOT NULL DEFAULT 'pending',
  processing_error TEXT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_event_id (event_id),
  KEY idx_received_at (received_at),
  KEY idx_related_entity (related_entity_type, related_entity_id, received_at),
  KEY idx_processing_status (processing_status)
) {$charsetCollate};";

		$syncHistorySql = "CREATE TABLE {$syncHistoryTable} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  trigger VARCHAR(32) NOT NULL,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  state VARCHAR(16) NOT NULL DEFAULT 'pending',
  details LONGTEXT NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_state_started_at (state, started_at),
  KEY idx_started_at (started_at)
) {$charsetCollate};";

		dbDelta( $failedOpsSql );
		dbDelta( $webhookLogSql );
		dbDelta( $syncHistorySql );
	}

	/**
	 * Drop the plugin's custom tables.
	 *
	 * Called from `uninstall.php` after the `WP_UNINSTALL_PLUGIN` guard.
	 * The constant check intentionally lives at the call site (slice-02
	 * AC-6 + slice-04 AC-7) so this method stays a pure DROP sequence
	 * usable from test setups.
	 *
	 * Table names are interpolated from `$wpdb->prefix` (no user input)
	 * so `$wpdb->prepare()` is unnecessary — and would not work anyway,
	 * because identifiers cannot be parameterised via `%s`.
	 *
	 * @return void
	 */
	public static function uninstall(): void
	{
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'spreadconnect_failed_ops',
			$wpdb->prefix . 'spreadconnect_webhook_log',
			$wpdb->prefix . 'spreadconnect_sync_history',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
