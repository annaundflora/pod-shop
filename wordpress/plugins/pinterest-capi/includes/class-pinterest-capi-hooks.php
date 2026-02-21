<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pinterest_CAPI_Hooks {

    public function __construct() {
        // Order-Status: completed → async purchase-Event senden
        add_action( 'woocommerce_order_status_completed', [ $this, 'schedule_purchase_event' ] );

        // Scheduled Event Handler registrieren
        add_action( 'pinterest_send_purchase_event', [ $this, 'handle_purchase_event' ] );

        // event_id aus URL-Parameter in Order Meta speichern
        add_action( 'woocommerce_checkout_order_created', [ $this, 'save_pinterest_event_id' ] );

        // Pinterest Tag Inline-Script auf WooCommerce Checkout-Seite
        add_action( 'wp_footer', [ $this, 'maybe_fire_checkout_event' ] );
    }

    /**
     * Speichert die pinterest_event_id aus dem URL-Parameter in der Order Meta.
     * Wird aufgerufen wenn WooCommerce die Bestellung anlegt (checkout_order_created).
     *
     * @param WC_Order $order
     */
    public function save_pinterest_event_id( WC_Order $order ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $event_id = isset( $_GET['pinterest_event_id'] )
            ? sanitize_text_field( wp_unslash( $_GET['pinterest_event_id'] ) )
            : '';

        if ( ! empty( $event_id ) ) {
            update_post_meta( $order->get_id(), '_pinterest_event_id', $event_id );
        }
    }

    /**
     * Async: Scheduled Event für purchase-CAPI-Call.
     *
     * @param int $order_id
     */
    public function schedule_purchase_event( int $order_id ): void {
        wp_schedule_single_event(
            time(),
            'pinterest_send_purchase_event',
            [ $order_id ]
        );
    }

    /**
     * Führt den CAPI-Call aus (via WP Cron).
     *
     * @param int $order_id
     */
    public function handle_purchase_event( int $order_id ): void {
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );
    }

    /**
     * Feuert das checkout-Event auf der WooCommerce Checkout-Seite.
     * Prüft Cookie Consent über localStorage (Inline JS).
     */
    public function maybe_fire_checkout_event(): void {
        if ( ! is_checkout() ) {
            return;
        }

        $tag_id = esc_js( get_option( 'pinterest_capi_tag_id', '' ) );
        if ( empty( $tag_id ) ) {
            return;
        }

        ?>
        <script>
        (function() {
            try {
                var consent = localStorage.getItem('cookie-consent');
                if (consent !== 'accepted') return;
                if (typeof window.pintrk !== 'function') return;
                window.pintrk('checkout', {
                    event_id: '<?php echo esc_js( uniqid( 'checkout-', true ) ); ?>'
                });
            } catch (e) {
                // Silent fail
            }
        })();
        </script>
        <?php
    }
}
