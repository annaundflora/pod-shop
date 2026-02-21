<?php
/**
 * Plugin Name: Pinterest Conversions API
 * Description: Server-side Pinterest purchase events via Conversions API v5
 * Version: 1.0.0
 * Requires WooCommerce: true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PINTEREST_CAPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PINTEREST_CAPI_PLUGIN_DIR . 'includes/class-pinterest-capi-service.php';
require_once PINTEREST_CAPI_PLUGIN_DIR . 'includes/class-pinterest-capi-hooks.php';
require_once PINTEREST_CAPI_PLUGIN_DIR . 'admin/settings-page.php';

function pinterest_capi_init(): void {
    new Pinterest_CAPI_Hooks();
    pinterest_capi_admin_init();
}
add_action( 'plugins_loaded', 'pinterest_capi_init' );
