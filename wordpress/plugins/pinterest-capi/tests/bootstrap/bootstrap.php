<?php
/**
 * PHPUnit Bootstrap for Pinterest CAPI Plugin Tests.
 *
 * Initialises WP_Mock before any test class is loaded, so that
 * WP_Mock::getDeprecatedMethodListener() is non-null when
 * WP_Mock\Tools\TestCase::run() is called (PHP 8.2 strict return types).
 */

// Autoload Composer dependencies (WP_Mock, PHPUnit, Mockery, …)
require_once __DIR__ . '/../../vendor/autoload.php';

// Define ABSPATH so the plugin include files don't exit() early.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../../../' );
}

// Bootstrap WP_Mock — this initialises $deprecatedMethodListener before
// any test's run() method is called, fixing the PHP 8.2 TypeError.
WP_Mock::bootstrap();

// Stub WooCommerce / WordPress classes that tests mock with PHPUnit getMockBuilder().
// Without these stubs getMockBuilder() cannot configure methods on non-existent classes.
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        public function get_id(): int { return 0; }
        public function get_billing_email(): string { return ''; }
        public function get_customer_ip_address(): string { return ''; }
        public function get_customer_user_agent(): string { return ''; }
        public function get_total(): string { return '0'; }
        public function get_item_count(): int { return 0; }
        public function get_items(): array { return []; }
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function get_error_message(): string { return ''; }
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    class WC_Order_Item_Product {
        public function get_name(): string { return ''; }
        public function get_product_id(): int { return 0; }
        public function get_subtotal(): float { return 0.0; }
        public function get_quantity(): int { return 1; }
    }
}

// Load the plugin classes under test.
require_once __DIR__ . '/../../includes/class-pinterest-capi-service.php';
require_once __DIR__ . '/../../includes/class-pinterest-capi-hooks.php';
