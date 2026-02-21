<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pinterest_CAPI_Service {

    private const API_BASE = 'https://api.pinterest.com/v5';
    private const TIMEOUT  = 10;

    /**
     * Sendet ein purchase-Event an die Pinterest Conversions API.
     * Wird async via wp_schedule_single_event aufgerufen.
     *
     * @param int $order_id WooCommerce Order ID
     */
    public function send_purchase_event( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "[Pinterest CAPI] Order {$order_id} nicht gefunden." );
            return;
        }

        $access_token  = get_option( 'pinterest_capi_access_token', '' );
        $ad_account_id = get_option( 'pinterest_capi_ad_account_id', '' );

        if ( empty( $access_token ) || empty( $ad_account_id ) ) {
            error_log( '[Pinterest CAPI] Access Token oder Ad Account ID nicht konfiguriert.' );
            return;
        }

        $event_id = get_post_meta( $order_id, '_pinterest_event_id', true );
        if ( empty( $event_id ) ) {
            // Fallback: neues UUID generieren wenn kein event_id gespeichert
            $event_id = wp_generate_uuid4();
        }

        $email      = $order->get_billing_email();
        $email_hash = hash( 'sha256', strtolower( trim( $email ) ) );

        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $items[] = [
                'product_name'     => $item->get_name(),
                'product_id'       => (string) $item->get_product_id(),
                'product_category' => $this->get_product_category( $item->get_product_id() ),
                'product_price'    => (float) $item->get_subtotal() / max( $item->get_quantity(), 1 ),
                'product_quantity' => $item->get_quantity(),
            ];
        }

        $payload = [
            'data' => [
                [
                    'event_name'       => 'purchase',
                    'event_time'       => time(),
                    'event_id'         => $event_id,
                    'event_source_url' => home_url( '/checkout' ),
                    'action_source'    => 'website',
                    'user_data'        => [
                        'em'                => [ $email_hash ],
                        'client_ip_address' => $order->get_customer_ip_address(),
                        'client_user_agent' => $order->get_customer_user_agent(),
                    ],
                    'custom_data'      => [
                        'currency'  => 'EUR',
                        'value'     => (float) $order->get_total(),
                        'contents'  => $items,
                        'num_items' => $order->get_item_count(),
                        'order_id'  => (string) $order_id,
                    ],
                ],
            ],
        ];

        $url      = self::API_BASE . "/ad_accounts/{$ad_account_id}/events";
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => "Bearer {$access_token}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => self::TIMEOUT,
        ] );

        if ( is_wp_error( $response ) ) {
            // Silent Fail: kein User-Impact, nur Logging
            error_log( '[Pinterest CAPI] WP_Error: ' . $response->get_error_message() );
            return;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            error_log( "[Pinterest CAPI] HTTP {$status_code}: {$body}" );
        }
    }

    /**
     * @param int $product_id
     * @return string Erste Produktkategorie oder leerer String
     */
    private function get_product_category( int $product_id ): string {
        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return '';
        }
        return $terms[0]->name ?? '';
    }
}
