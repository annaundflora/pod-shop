<?php

namespace SpreadconnectPod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SpreadconnectApiClient {

    private string $api_key;
    private string $base_url;
    private int    $timeout = 30;
    private int    $max_retries = 3;

    public function __construct( string $api_key, bool $use_staging = false ) {
        $this->api_key  = $api_key;
        $this->base_url = $use_staging
            ? 'https://staging.spreadconnect.com'
            : 'https://api.spreadconnect.com';
    }

    /**
     * Erstellt eine Bestellung bei Spreadconnect.
     * Retry: 3x mit exponential Backoff (1s, 2s, 4s).
     * Bei HTTP 429: X-RateLimit-Retry-After-Seconds Header auswerten.
     *
     * @param array $order_payload { shippingAddress: array, items: SpreadconnectOrderItem[] }
     * @return array|\WP_Error { orderId: string } oder WP_Error bei Misserfolg
     */
    public function create_order( array $order_payload ) {
        return $this->request_with_retry( 'POST', '/orders', $order_payload );
    }

    /**
     * Fragt eine Bestellung bei Spreadconnect ab (für Polling).
     *
     * @param string $sc_order_id Spreadconnect Order ID
     * @return array|\WP_Error { orderId, status, trackingNumber, trackingUrl }
     */
    public function get_order( string $sc_order_id ) {
        return $this->request_with_retry( 'GET', '/orders/' . rawurlencode( $sc_order_id ), [] );
    }

    /**
     * Führt einen HTTP-Request mit Retry-Logik aus.
     *
     * @param string $method   HTTP-Methode (GET, POST)
     * @param string $endpoint API-Endpunkt (z.B. '/orders')
     * @param array  $body     Request-Body (wird JSON-enkodiert)
     * @return array|\WP_Error  Dekodierte Response-Daten oder WP_Error
     */
    private function request_with_retry( string $method, string $endpoint, array $body ) {
        $url            = $this->base_url . $endpoint;
        $attempt        = 0;
        $backoff_delays = [ 1, 2, 4 ]; // Sekunden: 1s, 2s, 4s

        while ( $attempt < $this->max_retries ) {
            $args = [
                'method'  => $method,
                'timeout' => $this->timeout,
                'headers' => [
                    'Authorization' => $this->api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
            ];

            if ( $method === 'POST' && ! empty( $body ) ) {
                $args['body'] = wp_json_encode( $body );
            }

            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                // Netzwerkfehler – Retry mit Backoff
                $attempt++;
                if ( $attempt < $this->max_retries ) {
                    sleep( $backoff_delays[ $attempt - 1 ] ?? 4 );
                }
                continue;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $headers     = wp_remote_retrieve_headers( $response );
            $body_raw    = wp_remote_retrieve_body( $response );

            // HTTP 429 Rate Limit – X-RateLimit-Retry-After-Seconds auswerten
            if ( $status_code === 429 ) {
                $retry_after = (int) ( $headers['x-ratelimit-retry-after-seconds'] ?? $backoff_delays[ $attempt ] ?? 4 );
                error_log( sprintf(
                    '[SpreadconnectPod] Rate Limited (429). Warte %d Sekunden. Versuch %d/%d.',
                    $retry_after, $attempt + 1, $this->max_retries
                ) );
                sleep( $retry_after );
                $attempt++;
                continue;
            }

            // Proaktives Throttling: X-RateLimit-Remaining prüfen
            $remaining = (int) ( $headers['x-ratelimit-remaining'] ?? 999 );
            if ( $remaining <= 5 ) {
                sleep( 1 ); // Kurze Pause bei niedrigem Kontingent
            }

            // Erfolg
            if ( $status_code >= 200 && $status_code < 300 ) {
                $data = json_decode( $body_raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new \WP_Error( 'spreadconnect_json_error', 'Ungültige JSON-Antwort von Spreadconnect.' );
                }
                return $data;
            }

            // HTTP 5xx – Retry mit Backoff
            if ( $status_code >= 500 ) {
                $attempt++;
                if ( $attempt < $this->max_retries ) {
                    sleep( $backoff_delays[ $attempt - 1 ] ?? 4 );
                }
                continue;
            }

            // Andere Fehler (4xx außer 429): Kein Retry
            return new \WP_Error(
                'spreadconnect_http_error',
                sprintf( 'HTTP %d von Spreadconnect API: %s', $status_code, $body_raw )
            );
        }

        return new \WP_Error(
            'spreadconnect_max_retries',
            sprintf( 'Spreadconnect API nach %d Versuchen nicht erreichbar.', $this->max_retries )
        );
    }
}
