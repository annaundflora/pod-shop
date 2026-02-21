<?php
/**
 * Plugin Name:       Spreadconnect POD
 * Plugin URI:        https://github.com/
 * Description:       Automatische Bestellweiterleitung an Spreadconnect POD-API + Tracking-Empfang.
 * Version:           1.0.0
 * Author:            POD Shop
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * WC requires at least: 10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SPREADCONNECT_POD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPREADCONNECT_POD_VERSION', '1.0.0' );

require_once SPREADCONNECT_POD_DIR . 'includes/class-spreadconnect-api-client.php';
require_once SPREADCONNECT_POD_DIR . 'includes/class-spreadconnect-order-service.php';
require_once SPREADCONNECT_POD_DIR . 'includes/class-spreadconnect-tracking-service.php';
require_once SPREADCONNECT_POD_DIR . 'includes/class-spreadconnect-settings.php';

use SpreadconnectPod\SpreadconnectApiClient;
use SpreadconnectPod\SpreadconnectOrderService;
use SpreadconnectPod\SpreadconnectTrackingService;
use SpreadconnectPod\SpreadconnectSettings;

/**
 * Plugin initialisieren (nach WooCommerce geladen).
 */
function spreadconnect_pod_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>Spreadconnect POD: WooCommerce muss aktiviert sein.</p></div>';
        } );
        return;
    }

    $api_key     = get_option( 'spreadconnect_api_key', '' );
    $use_staging = (bool) get_option( 'spreadconnect_use_staging', true ); // Default: Staging für lokale Entwicklung

    $api_client       = new SpreadconnectApiClient( $api_key, $use_staging );
    $order_service    = new SpreadconnectOrderService( $api_client );
    $tracking_service = new SpreadconnectTrackingService( $api_client );
    $settings         = new SpreadconnectSettings();

    // Hook: Neue Bestellung → Spreadconnect
    add_action( 'woocommerce_order_status_processing', [ $order_service, 'handle_order_processing' ], 10, 1 );

    // REST Endpoints: Webhook + Health
    add_action( 'rest_api_init', [ $tracking_service, 'register_webhook_endpoint' ] );
    add_action( 'rest_api_init', [ $tracking_service, 'register_health_endpoint' ] );

    // Admin-Settings-Page
    add_action( 'admin_menu', [ $settings, 'add_settings_page' ] );
    add_action( 'admin_init', [ $settings, 'register_settings' ] );
}
add_action( 'plugins_loaded', 'spreadconnect_pod_init' );
