<?php
/**
 * Bootstrap for Spreadconnect POD PHPUnit tests.
 *
 * Loads Composer autoloader, Brain\Monkey, and provides
 * WordPress/WooCommerce stubs so tests can run without a full WP environment.
 */

// Composer autoloader (loads Brain\Monkey + plugin classes via PSR-4).
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Define ABSPATH so plugin files do not exit early.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

// ---------------------------------------------------------------
// WordPress Stubs -- minimal classes required by the plugin code.
// These are NOT feature code; they are test doubles that let the
// plugin classes instantiate without a running WordPress install.
// ---------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Minimal WP_Error stub.
     */
    class WP_Error {
        protected string $code;
        protected string $message;
        protected mixed $data;

        public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): mixed {
            return $this->data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Minimal WP_REST_Request stub.
     */
    class WP_REST_Request {
        protected array $json_params = [];
        protected array $headers = [];

        public function __construct( array $json_params = [], array $headers = [] ) {
            $this->json_params = $json_params;
            $this->headers     = $headers;
        }

        public function get_json_params(): array {
            return $this->json_params;
        }

        public function get_header( string $key ): ?string {
            return $this->headers[ strtolower( $key ) ] ?? null;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    /**
     * Minimal WP_REST_Response stub.
     */
    class WP_REST_Response {
        public array $data;
        public int $status;

        public function __construct( array $data = [], int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_status(): int {
            return $this->status;
        }

        public function get_data(): array {
            return $this->data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const CREATABLE = 'POST';
        const READABLE  = 'GET';
    }
}

// ---------------------------------------------------------------
// WooCommerce Stubs
// ---------------------------------------------------------------

if ( ! class_exists( 'WC_Order' ) ) {
    /**
     * Minimal WC_Order stub -- methods will be mocked via PHPUnit createMock().
     */
    class WC_Order {
        public function get_id(): int { return 0; }
        public function get_items(): array { return []; }
        public function get_shipping_first_name(): string { return ''; }
        public function get_shipping_last_name(): string { return ''; }
        public function get_shipping_address_1(): string { return ''; }
        public function get_shipping_address_2(): string { return ''; }
        public function get_shipping_city(): string { return ''; }
        public function get_shipping_postcode(): string { return ''; }
        public function get_shipping_country(): string { return ''; }
        public function get_billing_first_name(): string { return ''; }
        public function get_billing_last_name(): string { return ''; }
        public function get_billing_address_1(): string { return ''; }
        public function get_billing_address_2(): string { return ''; }
        public function get_billing_city(): string { return ''; }
        public function get_billing_postcode(): string { return ''; }
        public function get_billing_country(): string { return ''; }
        public function get_billing_email(): string { return ''; }
        public function get_billing_phone(): string { return ''; }
        public function add_order_note( string $note ): void {}
        public function update_status( string $status, string $note = '' ): void {}
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    class WC_Order_Item_Product {
        public function get_product_id(): int { return 0; }
        public function get_variation_id(): int { return 0; }
        public function get_quantity(): int { return 0; }
    }
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
    class WC_Product_Variation {
        public function get_attribute( string $attr ): string { return ''; }
    }
}
