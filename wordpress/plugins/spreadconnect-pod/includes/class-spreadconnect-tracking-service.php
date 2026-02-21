<?php

namespace SpreadconnectPod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SpreadconnectTrackingService {

    private SpreadconnectApiClient $api_client;

    public function __construct( SpreadconnectApiClient $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Registriert den Webhook-Endpunkt: POST /wp-json/spreadconnect/v1/webhook
     * Wird in register_rest_routes() des Plugins aufgerufen.
     */
    public function register_webhook_endpoint(): void {
        register_rest_route( 'spreadconnect/v1', '/webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => [ $this, 'verify_webhook_signature' ],
        ] );
    }

    /**
     * Registriert den Health-Check-Endpunkt: GET /wp-json/spreadconnect/v1/health
     */
    public function register_health_endpoint(): void {
        register_rest_route( 'spreadconnect/v1', '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => function() {
                return new \WP_REST_Response( [ 'status' => 'ok', 'plugin' => 'spreadconnect-pod' ], 200 );
            },
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Webhook-Handler: Empfängt Tracking-Daten von Spreadconnect.
     * Payload erwartet: { wcOrderId: int, trackingNumber: string, trackingUrl: string }
     * ODER: { orderId: string (SC Order ID), trackingNumber: string, trackingUrl: string }
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();

        // WooCommerce Order ID direkt oder via Spreadconnect Order ID ermitteln
        $wc_order_id = null;
        if ( ! empty( $body['wcOrderId'] ) ) {
            $wc_order_id = (int) $body['wcOrderId'];
        } elseif ( ! empty( $body['orderId'] ) ) {
            // SC Order ID → WC Order ID per Meta-Query
            $wc_order_id = $this->find_wc_order_by_sc_id( sanitize_text_field( $body['orderId'] ) );
        }

        if ( ! $wc_order_id ) {
            return new \WP_REST_Response( [ 'error' => 'Bestellung nicht gefunden.' ], 404 );
        }

        $tracking_number = sanitize_text_field( $body['trackingNumber'] ?? '' );
        $tracking_url    = esc_url_raw( $body['trackingUrl'] ?? '' );

        if ( ! $tracking_number ) {
            return new \WP_REST_Response( [ 'error' => 'trackingNumber fehlt.' ], 400 );
        }

        $this->apply_tracking( $wc_order_id, $tracking_number, $tracking_url );

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Verifiziert die Webhook-Signatur von Spreadconnect.
     * Implementierung abhängig von Spreadconnect API-Dokumentation.
     * Fallback: API Key als einfache Authentifizierung.
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function verify_webhook_signature( \WP_REST_Request $request ): bool {
        $api_key = get_option( 'spreadconnect_api_key', '' );
        if ( ! $api_key ) {
            return false;
        }

        // Einfache API-Key-Prüfung im Authorization-Header (falls Spreadconnect dies unterstützt)
        $auth_header = $request->get_header( 'authorization' );
        if ( $auth_header && hash_equals( $api_key, $auth_header ) ) {
            return true;
        }

        // Alternativ: Kein Webhook-Secret → nur bei lokaler Entwicklung akzeptieren
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            return true;
        }

        return false;
    }

    /**
     * Pollt den Status einer Spreadconnect-Bestellung (Fallback falls kein Webhook).
     * Wird via WP Cron aufgerufen.
     *
     * @param int    $wc_order_id   WooCommerce Order ID
     * @param string $sc_order_id   Spreadconnect Order ID
     */
    public function poll_order_tracking( int $wc_order_id, string $sc_order_id ): void {
        $result = $this->api_client->get_order( $sc_order_id );

        if ( is_wp_error( $result ) ) {
            error_log( sprintf(
                '[SpreadconnectPod] Tracking-Poll für Order %d (SC: %s) fehlgeschlagen: %s',
                $wc_order_id, $sc_order_id, $result->get_error_message()
            ) );
            return;
        }

        $tracking_number = sanitize_text_field( $result['trackingNumber'] ?? '' );
        $tracking_url    = esc_url_raw( $result['trackingUrl'] ?? '' );

        if ( $tracking_number ) {
            $this->apply_tracking( $wc_order_id, $tracking_number, $tracking_url );
        }
    }

    /**
     * Speichert Tracking-Daten und setzt Bestellstatus auf "completed".
     * Löst WooCommerce Versandbenachrichtigungs-E-Mail aus.
     *
     * @param int    $wc_order_id     WooCommerce Order ID
     * @param string $tracking_number Tracking-Nummer
     * @param string $tracking_url    Tracking-URL
     */
    public function apply_tracking( int $wc_order_id, string $tracking_number, string $tracking_url ): void {
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) {
            error_log( sprintf( '[SpreadconnectPod] apply_tracking: Order %d nicht gefunden.', $wc_order_id ) );
            return;
        }

        // Verhindere doppeltes Anwenden (falls Webhook mehrfach eintrifft)
        $existing = get_post_meta( $wc_order_id, '_spreadconnect_tracking_number', true );
        if ( $existing === $tracking_number ) {
            return;
        }

        update_post_meta( $wc_order_id, '_spreadconnect_tracking_number', $tracking_number );
        update_post_meta( $wc_order_id, '_spreadconnect_tracking_url', $tracking_url );

        // WooCommerce Order Note hinzufügen
        $order->add_order_note( sprintf(
            'Sendung verfolgen: <a href="%s" target="_blank">%s</a>',
            esc_url( $tracking_url ),
            esc_html( $tracking_number )
        ) );

        // Status auf "completed" setzen → löst WooCommerce "Bestellung abgeschlossen" E-Mail aus
        // WooCommerce sendet die Versandbenachrichtigung automatisch beim Statuswechsel zu "completed"
        $order->update_status( 'completed', 'Tracking von Spreadconnect erhalten.' );

        error_log( sprintf(
            '[SpreadconnectPod] Tracking für Order %d gesetzt: %s',
            $wc_order_id, $tracking_number
        ) );
    }

    /**
     * Findet die WooCommerce Order ID anhand der Spreadconnect Order ID.
     *
     * @param string $sc_order_id Spreadconnect Order ID
     * @return int|null WooCommerce Order ID oder null
     */
    private function find_wc_order_by_sc_id( string $sc_order_id ): ?int {
        $orders = wc_get_orders( [
            'meta_key'   => '_spreadconnect_order_id',
            'meta_value' => $sc_order_id,
            'limit'      => 1,
        ] );

        return ! empty( $orders ) ? $orders[0]->get_id() : null;
    }
}
