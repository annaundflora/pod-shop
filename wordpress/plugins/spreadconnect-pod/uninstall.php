<?php
/**
 * Plugin uninstall handler.
 *
 * Loaded by WordPress when the user clicks "Delete" on the plugin in
 * `wp-admin/plugins.php`. Must NOT run during deactivation.
 *
 * Slice-02 ships this file as a guarded stub only — no DB mutations.
 * Schema cleanup is added in slice-04 (Bootstrap\Schema::uninstall()).
 *
 * @package SpreadconnectPod
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Schema cleanup added in Slice 04 (Bootstrap\Schema::uninstall()).
