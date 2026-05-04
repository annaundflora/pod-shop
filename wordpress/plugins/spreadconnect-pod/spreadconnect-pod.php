<?php
/**
 * Plugin Name:       Spreadconnect POD
 * Plugin URI:        https://github.com/pod-shop/pod-shop-mvp
 * Description:       Print-on-demand fulfillment integration for WooCommerce via the Spreadconnect Fulfillment API v2.3.9 (full coverage of all 27 REST endpoints + 7 webhook events).
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            POD Shop
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spreadconnect-pod
 * Domain Path:       /languages
 *
 * @package SpreadconnectPod
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\SpreadconnectPod\Bootstrap\Plugin::init( __FILE__ );
