<?php

namespace SpreadconnectPod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SpreadconnectOrderService {

    private SpreadconnectApiClient $api_client;

    public function __construct( SpreadconnectApiClient $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Hook: woocommerce_order_status_processing
     * Wird aufgerufen wenn eine Bestellung den Status "processing" erhält.
     *
     * @param int $order_id WooCommerce Order ID
     */
    public function handle_order_processing( int $order_id ): void {
        // Verhindere doppelte Weiterleitung falls bereits eine Spreadconnect Order ID existiert
        if ( get_post_meta( $order_id, '_spreadconnect_order_id', true ) ) {
            error_log( sprintf( '[SpreadconnectPod] Order %d bereits weitergeleitet. Überspringe.', $order_id ) );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( sprintf( '[SpreadconnectPod] Order %d nicht gefunden.', $order_id ) );
            $this->notify_admin_on_failure( $order_id, 'WooCommerce Order nicht gefunden.' );
            return;
        }

        // Bestellpositionen in Spreadconnect-DTOs umwandeln
        $items   = $this->build_order_items( $order );
        $address = $this->build_shipping_address( $order );

        if ( is_wp_error( $items ) ) {
            error_log( sprintf( '[SpreadconnectPod] Order %d: Fehler bei DTOs: %s', $order_id, $items->get_error_message() ) );
            $this->notify_admin_on_failure( $order_id, $items->get_error_message() );
            $order->add_order_note( 'Spreadconnect: Weiterleitung fehlgeschlagen – ' . esc_html( $items->get_error_message() ) );
            return;
        }

        $payload = [
            'shippingAddress' => $address,
            'items'           => $items,
        ];

        $result = $this->api_client->create_order( $payload );

        if ( is_wp_error( $result ) ) {
            $error_msg = $result->get_error_message();
            error_log( sprintf( '[SpreadconnectPod] Order %d: API-Fehler nach Retries: %s', $order_id, $error_msg ) );
            $this->notify_admin_on_failure( $order_id, $error_msg );
            $order->add_order_note( 'Spreadconnect: Weiterleitung fehlgeschlagen – ' . esc_html( $error_msg ) );
            return;
        }

        $sc_order_id = $result['orderId'] ?? null;
        if ( ! $sc_order_id ) {
            $this->notify_admin_on_failure( $order_id, 'Spreadconnect hat keine orderId zurückgegeben.' );
            return;
        }

        update_post_meta( $order_id, '_spreadconnect_order_id', sanitize_text_field( $sc_order_id ) );
        $order->add_order_note( 'Spreadconnect Order erstellt: ' . esc_html( $sc_order_id ) );
        error_log( sprintf( '[SpreadconnectPod] Order %d erfolgreich weitergeleitet. SC Order ID: %s', $order_id, $sc_order_id ) );
    }

    /**
     * Baut die SpreadconnectOrderItem DTOs aus den WooCommerce Order Items.
     *
     * @param \WC_Order $order
     * @return array[]|\WP_Error Array von SpreadconnectOrderItem DTOs: [{ articleId, sizeId, quantity }]
     */
    public function build_order_items( \WC_Order $order ) {
        $items = [];

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();

            // _spreadconnect_article_id vom Produkt lesen
            // Bei Variationen: erst Variation prüfen, dann Parent-Produkt
            $article_id = '';
            if ( $variation_id ) {
                $article_id = get_post_meta( $variation_id, '_spreadconnect_article_id', true );
            }
            if ( ! $article_id ) {
                $article_id = get_post_meta( $product_id, '_spreadconnect_article_id', true );
            }

            if ( ! $article_id ) {
                return new \WP_Error(
                    'spreadconnect_missing_article_id',
                    sprintf(
                        'Produkt ID %d hat keine _spreadconnect_article_id. Bitte im WP-Admin setzen.',
                        $product_id
                    )
                );
            }

            // Größe aus Variation-Attributen ermitteln
            $size_id = '';
            if ( $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( $variation ) {
                    // Attribut 'size' oder 'pa_size' oder 'groesse' auslesen
                    $size_id = $variation->get_attribute( 'pa_size' )
                        ?: $variation->get_attribute( 'size' )
                        ?: $variation->get_attribute( 'groesse' )
                        ?: '';
                }
            }

            $items[] = [
                'articleId' => sanitize_text_field( $article_id ),
                'sizeId'    => sanitize_text_field( $size_id ),
                'quantity'  => (int) $item->get_quantity(),
            ];
        }

        return $items;
    }

    /**
     * Erstellt die Versandadresse aus der WooCommerce Order.
     *
     * @param \WC_Order $order
     * @return array Versandadresse-Payload für Spreadconnect API
     */
    private function build_shipping_address( \WC_Order $order ): array {
        return [
            'firstName' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'lastName'  => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'street'    => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'street2'   => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            'city'      => $order->get_shipping_city() ?: $order->get_billing_city(),
            'postcode'  => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'country'   => $order->get_shipping_country() ?: $order->get_billing_country(),
            'email'     => $order->get_billing_email(),
            'phone'     => $order->get_billing_phone(),
        ];
    }

    /**
     * Benachrichtigt den Admin per E-Mail bei einem nicht-behebbaren Fehler.
     *
     * @param int    $order_id  WooCommerce Order ID
     * @param string $error_msg Fehlermeldung
     */
    public function notify_admin_on_failure( int $order_id, string $error_msg ): void {
        $admin_email = get_option( 'admin_email' );
        $subject     = sprintf( '[POD Shop] Spreadconnect-Fehler: Bestellung #%d', $order_id );
        $message     = sprintf(
            "Die Bestellung #%d konnte NICHT an Spreadconnect weitergeleitet werden.\n\nFehler: %s\n\nBitte manuell im Spreadconnect-Dashboard nachbearbeiten:\nhttps://www.spreadconnect.com\n\nWooCommerce Bestellung: %s",
            $order_id,
            $error_msg,
            admin_url( 'post.php?post=' . $order_id . '&action=edit' )
        );

        wp_mail( $admin_email, $subject, $message );
    }
}
