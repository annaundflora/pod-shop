<?php
/**
 * Plugin uninstall handler.
 *
 * Loaded by WordPress when the user clicks "Delete" on the plugin in
 * `wp-admin/plugins.php`. Must NOT run during deactivation.
 *
 * Slice-04 wires this file to `Bootstrap\Schema::uninstall()`, which drops
 * the three custom tables installed during activation. The constant guard
 * remains the sole entry-point check (slice-02 AC-6 + slice-04 AC-7).
 *
 * @package SpreadconnectPod
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

\SpreadconnectPod\Bootstrap\Schema::uninstall();
