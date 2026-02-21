# Slice 5: POD-Anbindung (Spreadconnect) implementieren

> **Slice 5 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-04-rechtliches-rechnungen.md` |
> | **Nächster:** | `slice-06-pinterest-tracking.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-05-pod-anbindung-spreadconnect` |
| **Test** | `php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-infrastruktur", "slice-03-warenkorb-checkout-redirect"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: PHPUnit-Test gegen das Custom WordPress Plugin (kein Playwright, kein Vitest)
- **E2E**: `false` – PHPUnit Unit/Integration Tests (kein Browser-Test)
- **Dependencies**: Slice 1 (WordPress + WooCommerce läuft), Slice 3 (WooCommerce Bestellungen mit Status "Processing" werden erstellt)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Dieser Slice ist ein Custom PHP WordPress Plugin – Stack ist `php-wordpress`. Slice 1 hat `wordpress:6.9-php8.2-apache` als Docker-Image dokumentiert.

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress` |
| **Test Command** | `php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox` |
| **Integration Command** | `php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox --verbose` |
| **Acceptance Command** | `curl -s http://localhost:8080/wp-json/spreadconnect/v1/health | grep -q "ok" && echo "Spreadconnect Plugin OK"` |
| **Start Command** | `docker compose up -d` |
| **Health Endpoint** | `http://localhost:8080/wp-json/spreadconnect/v1/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: PHP WordPress Plugin – Composer/PHPUnit für Tests, WordPress-Testfunktionen via WP Test Suite
- **Test Command**: PHPUnit mit wordpress-tests-lib oder Mocks für WP-Funktionen (`wp_remote_post`, `update_post_meta`)
- **Integration Command**: Gleicher Befehl mit ausführlicher Ausgabe
- **Acceptance Command**: WP REST Health-Check-Endpoint des Plugins
- **Start Command**: Docker Compose startet WordPress + MySQL
- **Health Endpoint**: Custom REST-Endpoint des Spreadconnect Plugins
- **Mocking Strategy**: Spreadconnect API (staging.spreadconnect.com) wird in Unit Tests durch Mocks ersetzt; echte API-Calls nur beim manuellen Acceptance-Test gegen Staging-Umgebung

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Ready | `slice-02-produktkatalog-frontend.md` |
| 3 | Warenkorb + Checkout-Redirect | Ready | `slice-03-warenkorb-checkout-redirect.md` |
| 4 | Rechtliches + Rechnungen | Pending | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Ready | `slice-05-pod-anbindung-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Pending | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice implementiert die vollständige Print-on-Demand-Automatisierung via Spreadconnect. Nach Abschluss werden neue WooCommerce-Bestellungen (Status "Processing") automatisch an die Spreadconnect API weitergeleitet, und Tracking-Nummern kommen automatisch zurück und lösen die Versandbenachrichtigung an den Kunden aus. Das Custom WordPress Plugin ist der Kern dieses Slice.

**Scope-Abgrenzung:**
- Pinterest `purchase` Event (CAPI): OUT OF SCOPE (Slice 6 – konsumiert `woocommerce_order_status_completed` Hook)
- Spreadconnect API-Zugang beantragen: MANUELLE AUFGABE (Voraussetzung für Akzeptanztest gegen Staging)
- Produkt-Sync via Spreadconnect `/articles` Endpoint: OUT OF SCOPE für MVP (Produkte werden manuell via `_spreadconnect_article_id` Custom Meta verknüpft)
- Faktur Pro Invoice: OUT OF SCOPE (Slice 4)
- WooCommerce Stock Management: Bereits in Slice 1 deaktiviert

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Server Logic: SpreadconnectOrderService + SpreadconnectTrackingService, API Design: Spreadconnect REST API, Security: Spreadconnect API Key, Rate Limiting

```
[WooCommerce Order: status → processing]
    │
    └─ add_action('woocommerce_order_status_processing', [$service, 'handleOrderProcessing'])
              │
              ├─ SpreadconnectOrderService::handleOrderProcessing($order_id)
              │       ├─ Lädt WooCommerce Order Object
              │       ├─ Erstellt SpreadconnectOrderItem[] DTOs (articleId, sizeId, quantity)
              │       ├─ wp_remote_post('https://staging.spreadconnect.com/orders', ...)
              │       │       └─ Retry: 3x mit Backoff (1s, 2s, 4s)
              │       │       └─ 429: X-RateLimit-Retry-After-Seconds Header auswerten
              │       ├─ Bei Erfolg: update_post_meta($order_id, '_spreadconnect_order_id', $id)
              │       └─ Bei Fehler nach 3 Retries: wp_mail(Admin) + error_log()
              │
              └─ SpreadconnectTrackingService
                      ├─ Option A: Webhook (POST /wp-json/spreadconnect/v1/webhook)
                      │       ├─ Empfängt Tracking-Daten von Spreadconnect
                      │       ├─ update_post_meta(_spreadconnect_tracking_number, _spreadconnect_tracking_url)
                      │       ├─ WooCommerce Order Status → "completed"
                      │       └─ WooCommerce Versandbenachrichtigungs-E-Mail auslösen
                      │
                      └─ Option B: Polling (WP Cron, fallback)
                              └─ wp_remote_get('/orders/{spreadconnect_order_id}')
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Integration` (WordPress Plugin) | `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` – Plugin-Hauptdatei |
| `Integration` (WordPress Plugin) | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php` – Bestellweiterleitung |
| `Integration` (WordPress Plugin) | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php` – Tracking-Empfang |
| `Integration` (WordPress Plugin) | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php` – HTTP-Client mit Retry |
| `Integration` (WordPress Plugin) | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php` – Admin-Einstellungen |
| `Data` (MySQL/wp_options) | `spreadconnect_api_key` – API Key in wp_options (encrypted) |
| `Data` (MySQL/wp_postmeta) | `_spreadconnect_order_id`, `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url` auf `shop_order` |
| `Data` (MySQL/wp_postmeta) | `_spreadconnect_article_id` auf `product` – manuelle Verknüpfung via WP-Admin |

### 2. Datenfluss

```
Mollie-Zahlung erfolgreich (Slice 3)
  ↓
WooCommerce Bestellung: Status "pending" → "processing"
  ↓
WordPress Hook: woocommerce_order_status_processing($order_id)
  ↓
SpreadconnectOrderService::handleOrderProcessing($order_id)
  ↓
WC_Order laden → Order Items iterieren
  ↓
Für jedes Order Item:
  product_id → get_post_meta($product_id, '_spreadconnect_article_id')
  variation → WC_Order_Item_Product::get_variation_id() → Größe ermitteln
  quantity → WC_Order_Item_Product::get_quantity()
  ↓
SpreadconnectOrderItem[] DTOs zusammenbauen
  ↓
SpreadconnectApiClient::createOrder($order_dto, $shipping_address)
  ↓
wp_remote_post('https://[BASE_URL]/orders', [
  'headers' => ['Authorization' => get_option('spreadconnect_api_key')],
  'body'    => json_encode($payload),
  'timeout' => 30,
])
  ↓
Bei HTTP 200/201: Response['orderId'] → update_post_meta($order_id, '_spreadconnect_order_id', $sc_order_id)
Bei HTTP 429:    X-RateLimit-Retry-After-Seconds Header → sleep() → Retry
Bei HTTP 5xx:    Exponential Backoff (1s, 2s, 4s) → max 3 Retries
Bei Fehler ×3:  wp_mail($admin_email, 'Spreadconnect Fehler', $details) + error_log()

---

Spreadconnect sendet Tracking (Webhook oder Cron-Polling)
  ↓
SpreadconnectTrackingService::receiveTracking($wc_order_id, $tracking_number, $tracking_url)
  ↓
update_post_meta($order_id, '_spreadconnect_tracking_number', $tracking_number)
update_post_meta($order_id, '_spreadconnect_tracking_url', $tracking_url)
  ↓
$order->update_status('completed') → löst WooCommerce E-Mail aus
  ↓
WooCommerce Versandbenachrichtigung an Kunden (mit Tracking-Link)
```

### 3. Plugin-Struktur

```
wordpress/plugins/spreadconnect-pod/
├── spreadconnect-pod.php                          – Plugin-Header + Initialisierung
├── composer.json                                  – PHPUnit als Dev-Dependency
├── includes/
│   ├── class-spreadconnect-api-client.php         – HTTP-Client (wp_remote_post + Retry)
│   ├── class-spreadconnect-order-service.php      – Bestellweiterleitung (Hook: woocommerce_order_status_processing)
│   ├── class-spreadconnect-tracking-service.php   – Tracking-Empfang (REST + Cron)
│   └── class-spreadconnect-settings.php           – Admin-Settings-Page (API Key)
└── tests/
    └── (PHPUnit Tests – lokale Test-Ausführung im Plugin-Verzeichnis)
```

### 4. SpreadconnectApiClient – HTTP-Client mit Retry

> **Quelle:** `architecture.md` → Rate Limiting: Spreadconnect: 60 Calls/Minute, X-RateLimit-Retry-After-Seconds, Timeout 30s, Retry 3x Backoff. Error Handling: Spreadconnect: Retry 3x, Admin-Notification.

**Datei:** `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php`

```php
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
     * @return array|WP_Error { orderId: string } oder WP_Error bei Misserfolg
     */
    public function create_order( array $order_payload ) {
        return $this->request_with_retry( 'POST', '/orders', $order_payload );
    }

    /**
     * Fragt eine Bestellung bei Spreadconnect ab (für Polling).
     *
     * @param string $sc_order_id Spreadconnect Order ID
     * @return array|WP_Error { orderId, status, trackingNumber, trackingUrl }
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
     * @return array|WP_Error  Dekodierte Response-Daten oder WP_Error
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
```

### 5. SpreadconnectOrderService – Bestellweiterleitung

> **Quelle:** `architecture.md` → SpreadconnectOrderService, Custom Post Meta: _spreadconnect_article_id (auf Produkt), _spreadconnect_order_id (auf Order), Validation: Spreadconnect Article ID muss auf Produkt existieren.

**Datei:** `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php`

```php
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
     * @param WC_Order $order
     * @return array[]|WP_Error Array von SpreadconnectOrderItem DTOs: [{ articleId, sizeId, quantity }]
     */
    public function build_order_items( \WC_Order $order ) {
        $items = [];

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product_id  = $item->get_product_id();
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
     * @param WC_Order $order
     * @return array Versandadresse-Payload für Spreadconnect API
     */
    private function build_shipping_address( \WC_Order $order ): array {
        return [
            'firstName'   => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'lastName'    => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'street'      => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'street2'     => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            'city'        => $order->get_shipping_city() ?: $order->get_billing_city(),
            'postcode'    => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'country'     => $order->get_shipping_country() ?: $order->get_billing_country(),
            'email'       => $order->get_billing_email(),
            'phone'       => $order->get_billing_phone(),
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
```

### 6. SpreadconnectTrackingService – Tracking-Empfang

> **Quelle:** `architecture.md` → SpreadconnectTrackingService: Webhook ODER Polling, Custom Post Meta _spreadconnect_tracking_number + _spreadconnect_tracking_url, WooCommerce Status "completed", Versandbenachrichtigung.

**Datei:** `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php`

```php
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
     * @param WP_REST_Request $request
     * @return WP_REST_Response
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
     * @param WP_REST_Request $request
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
```

### 7. Plugin-Hauptdatei und Admin-Settings

**Datei:** `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php`

```php
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

    $api_key    = get_option( 'spreadconnect_api_key', '' );
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
```

**Datei:** `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php`

```php
<?php

namespace SpreadconnectPod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SpreadconnectSettings {

    public function add_settings_page(): void {
        add_options_page(
            'Spreadconnect POD Einstellungen',
            'Spreadconnect POD',
            'manage_options',
            'spreadconnect-pod',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'spreadconnect_pod_settings', 'spreadconnect_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'spreadconnect_pod_settings', 'spreadconnect_use_staging', [
            'sanitize_callback' => 'rest_sanitize_boolean',
        ] );
    }

    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1>Spreadconnect POD Einstellungen</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'spreadconnect_pod_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="spreadconnect_api_key"
                                   value="<?php echo esc_attr( get_option( 'spreadconnect_api_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">Spreadconnect API Key (aus dem Spreadconnect Dashboard).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Staging-Modus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="spreadconnect_use_staging" value="1"
                                       <?php checked( get_option( 'spreadconnect_use_staging', true ) ); ?> />
                                Staging API verwenden (staging.spreadconnect.com)
                            </label>
                            <p class="description">Für lokale Entwicklung und Tests. Deaktivieren für Produktion.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
```

### 8. Composer-Konfiguration (PHPUnit)

**Datei:** `wordpress/plugins/spreadconnect-pod/composer.json`

```json
{
    "name": "pod-shop/spreadconnect-pod",
    "description": "Spreadconnect POD WordPress Plugin",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "brain/monkey": "^2.6"
    },
    "autoload": {
        "psr-4": {
            "SpreadconnectPod\\": "includes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "SpreadconnectPod\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit"
    }
}
```

### 9. WooCommerce Custom Meta – Manuelle Konfiguration

> **Quelle:** `architecture.md` → Custom Post Meta: _spreadconnect_article_id (auf Produkt). WooCommerce Stock Management deaktivieren (bereits Slice 1).

| Meta Key | Post Type | Setzen via | Beschreibung |
|----------|-----------|------------|--------------|
| `_spreadconnect_article_id` | `product` | WP-Admin → Produkt bearbeiten → Erweitert → Custom Fields | Spreadconnect Article ID (z.B. `"d12345"`). Muss gesetzt sein bevor Bestellungen eingehen. |
| `_spreadconnect_order_id` | `shop_order` | Automatisch vom Plugin | Wird nach erfolgreicher API-Weiterleitung gesetzt |
| `_spreadconnect_tracking_number` | `shop_order` | Automatisch vom Plugin | Wird nach Tracking-Empfang gesetzt |
| `_spreadconnect_tracking_url` | `shop_order` | Automatisch vom Plugin | Wird nach Tracking-Empfang gesetzt |

**Custom Fields in WP-Admin aktivieren:**
- WP-Admin → Einstellungen → Bildschirm-Optionen → "Custom Fields" Checkbox aktivieren

---

## UI Anforderungen

Dieser Slice hat keine UI-Anforderungen aus den Next.js-Wireframes. Das Plugin ist rein serverseitig (PHP/WordPress). Die einzige UI ist die Admin-Settings-Page unter WP-Admin → Einstellungen → Spreadconnect POD.

Wireframes gelten nicht für Admin-Pages – diese verwenden WordPress-Standard-Admin-Styling.

---

## Acceptance Criteria

1) GIVEN ein WooCommerce-Produkt hat `_spreadconnect_article_id` als Custom Meta gesetzt
   WHEN eine neue Bestellung mit diesem Produkt den Status "processing" erhält (nach erfolgreicher Mollie-Zahlung)
   THEN sendet `SpreadconnectOrderService::handle_order_processing()` einen POST-Request an `https://staging.spreadconnect.com/orders` mit korrekten SpreadconnectOrderItem-DTOs (`articleId`, `sizeId`, `quantity`)

2) GIVEN die Spreadconnect API antwortet mit HTTP 200 und `{ "orderId": "sc-123" }`
   WHEN die Bestellung weitergeleitet wurde
   THEN ist `get_post_meta($order_id, '_spreadconnect_order_id', true)` gleich `"sc-123"` und die WooCommerce-Bestellnotiz enthält "Spreadconnect Order erstellt: sc-123"

3) GIVEN die Spreadconnect API gibt dreimal in Folge HTTP 500 zurück
   WHEN `SpreadconnectApiClient::create_order()` aufgerufen wird
   THEN werden genau 3 Versuche mit Backoff (1s, 2s, 4s) unternommen, danach wird `WP_Error` mit Code `spreadconnect_max_retries` zurückgegeben

4) GIVEN die Spreadconnect API gibt HTTP 429 mit Header `X-RateLimit-Retry-After-Seconds: 10` zurück
   WHEN `SpreadconnectApiClient::request_with_retry()` diesen Status empfängt
   THEN wartet der Client mindestens 10 Sekunden (Wert aus Header, nicht Standard-Backoff) und versucht erneut

5) GIVEN ein Produkt hat KEINE `_spreadconnect_article_id` gesetzt
   WHEN eine Bestellung mit diesem Produkt eingereicht wird
   THEN wird die Bestellung NICHT weitergeleitet, eine Admin-Benachrichtigung per E-Mail wird ausgelöst, und die WooCommerce-Bestellnotiz enthält einen Hinweis auf die fehlende Article ID

6) GIVEN die Bestellweiterleitung nach 3 Retries fehlschlägt
   WHEN `notify_admin_on_failure()` aufgerufen wird
   THEN erhält die Admin-E-Mail-Adresse (aus `get_option('admin_email')`) eine E-Mail mit Subject `[POD Shop] Spreadconnect-Fehler: Bestellung #X` und der Fehlermeldung im Body

7) GIVEN Spreadconnect sendet einen Webhook an `POST /wp-json/spreadconnect/v1/webhook`
   WHEN der Payload `{ "wcOrderId": 42, "trackingNumber": "DE123456789", "trackingUrl": "https://..." }` enthält
   THEN werden `_spreadconnect_tracking_number` und `_spreadconnect_tracking_url` als Post Meta gesetzt, der WooCommerce-Bestellstatus wechselt auf "completed", und WooCommerce sendet automatisch die Versandbenachrichtigungs-E-Mail an den Kunden

8) GIVEN der WooCommerce-Bestellstatus wird auf "completed" gesetzt
   WHEN `$order->update_status('completed')` aufgerufen wird
   THEN versendet WooCommerce automatisch die Standard-Versandbenachrichtigungs-E-Mail (WooCommerce built-in Verhalten)

9) GIVEN das Spreadconnect Plugin ist aktiviert
   WHEN `GET /wp-json/spreadconnect/v1/health` aufgerufen wird
   THEN antwortet der Endpoint mit HTTP 200 und `{ "status": "ok", "plugin": "spreadconnect-pod" }`

10) GIVEN eine Spreadconnect Order ID ist als `_spreadconnect_order_id` gespeichert
    WHEN `SpreadconnectTrackingService::poll_order_tracking()` per WP Cron aufgerufen wird
    THEN wird `GET /orders/{sc_order_id}` gegen die Spreadconnect API gesendet, und falls `trackingNumber` in der Response vorhanden ist, wird `apply_tracking()` aufgerufen

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php`

### Unit Tests (PHPUnit + Brain\Monkey für WP-Funktionen)

<test_spec>
```php
<?php
// tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SpreadconnectPod\SpreadconnectApiClient;
use SpreadconnectPod\SpreadconnectOrderService;
use SpreadconnectPod\SpreadconnectTrackingService;

class SpreadconnectApiClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_wp_error_after_max_retries_on_500(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 500 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Internal Server Error' );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act – 3 Versuche werden unternommen, dann WP_Error (sleep wird in Tests gemockt)
        // Hinweis: sleep() im Test via Monkey\Functions mocken
        Functions\when( 'sleep' )->justReturn( null );

        $result = $client->create_order( [ 'items' => [] ] );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_max_retries', $result->get_error_code() );
    }

    public function test_returns_data_on_http_201(): void {
        // Arrange
        $mock_response_data = [ 'orderId' => 'sc-abc-123' ];
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 201 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '59' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $mock_response_data ) );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [ [ 'articleId' => 'art1', 'sizeId' => 'M', 'quantity' => 1 ] ] ] );

        // Assert
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-abc-123', $result['orderId'] );
    }

    public function test_uses_retry_after_header_on_429(): void {
        // Arrange – 429 beim ersten Versuch, 201 beim zweiten
        $call_count = 0;
        Functions\when( 'wp_remote_request' )->alias( function() use ( &$call_count ) {
            $call_count++;
            return [];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function() use ( &$call_count ) {
            return $call_count === 1 ? 429 : 201;
        } );
        Functions\when( 'wp_remote_retrieve_headers' )->alias( function() use ( &$call_count ) {
            if ( $call_count === 1 ) {
                return [ 'x-ratelimit-retry-after-seconds' => '5' ];
            }
            return [ 'x-ratelimit-remaining' => '50' ];
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function() use ( &$call_count ) {
            return $call_count === 1 ? '' : json_encode( [ 'orderId' => 'sc-retry-ok' ] );
        } );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'sleep' )->justReturn( null );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [] ] );

        // Assert – zweiter Versuch erfolgreich
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-retry-ok', $result['orderId'] );
    }

    public function test_returns_error_on_non_json_response(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '50' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not-valid-json{{' );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [] );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_json_error', $result->get_error_code() );
    }
}

class SpreadconnectOrderServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_order_items_returns_error_when_article_id_missing(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( '' ); // Keine article_id
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 42 );
        $mock_item->method( 'get_variation_id' )->willReturn( 0 );
        $mock_item->method( 'get_quantity' )->willReturn( 1 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_missing_article_id', $result->get_error_code() );
    }

    public function test_build_order_items_returns_dto_with_correct_fields(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single ) {
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );
        Functions\when( 'wc_get_product' )->alias( function( $id ) {
            $mock_variation = \Mockery::mock( \WC_Product_Variation::class );
            $mock_variation->shouldReceive( 'get_attribute' )
                ->with( 'pa_size' )->andReturn( 'L' );
            return $mock_variation;
        } );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 10 );
        $mock_item->method( 'get_variation_id' )->willReturn( 20 );
        $mock_item->method( 'get_quantity' )->willReturn( 2 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert
        $this->assertIsArray( $result );
        $this->assertCount( 1, $result );
        $this->assertEquals( 'art-shirt-001', $result[0]['articleId'] );
        $this->assertEquals( 'L', $result[0]['sizeId'] );
        $this->assertEquals( 2, $result[0]['quantity'] );
    }

    public function test_notify_admin_on_failure_sends_email_with_correct_subject(): void {
        // Arrange
        Functions\when( 'get_option' )->alias( function( $key ) {
            return $key === 'admin_email' ? 'admin@test.de' : '';
        } );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/post.php?post=99&action=edit' );

        $sent_to    = null;
        $sent_subject = null;
        Functions\when( 'wp_mail' )->alias( function( $to, $subject, $message ) use ( &$sent_to, &$sent_subject ) {
            $sent_to      = $to;
            $sent_subject = $subject;
        } );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->notify_admin_on_failure( 99, 'Netzwerkfehler nach 3 Versuchen.' );

        // Assert
        $this->assertEquals( 'admin@test.de', $sent_to );
        $this->assertStringContainsString( 'Spreadconnect-Fehler', $sent_subject );
        $this->assertStringContainsString( '99', $sent_subject );
    }

    public function test_handle_order_processing_skips_if_already_forwarded(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( 'existing-sc-order-id' ); // Bereits weitergeleitet
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->never() )->method( 'create_order' );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 55 );

        // Assert – create_order wurde nicht aufgerufen (never-Expectation oben)
        $this->assertTrue( true ); // Kein Fehler = Test bestanden
    }

    public function test_handle_order_processing_stores_sc_order_id_on_success(): void {
        // Arrange – AC-2: Erfolgs-Pfad: orderId wird als Post Meta gespeichert
        $updated_meta = [];
        $order_notes  = [];

        Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single ) {
            // Noch nicht weitergeleitet (kein bestehender SC Order ID)
            if ( $key === '_spreadconnect_order_id' ) {
                return '';
            }
            // _spreadconnect_article_id auf Produkt gesetzt
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );
        Functions\when( 'error_log' )->justReturn( null );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/' );
        Functions\when( 'wc_get_product' )->alias( function( $id ) {
            $mock_variation = \Mockery::mock( \WC_Product_Variation::class );
            $mock_variation->shouldReceive( 'get_attribute' )
                ->with( 'pa_size' )->andReturn( 'M' );
            return $mock_variation;
        } );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 100 );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 10 );
        $mock_item->method( 'get_variation_id' )->willReturn( 20 );
        $mock_item->method( 'get_quantity' )->willReturn( 1 );

        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );
        $mock_order->method( 'get_shipping_first_name' )->willReturn( 'Max' );
        $mock_order->method( 'get_shipping_last_name' )->willReturn( 'Mustermann' );
        $mock_order->method( 'get_shipping_address_1' )->willReturn( 'Musterstr. 1' );
        $mock_order->method( 'get_shipping_address_2' )->willReturn( '' );
        $mock_order->method( 'get_shipping_city' )->willReturn( 'Berlin' );
        $mock_order->method( 'get_shipping_postcode' )->willReturn( '10115' );
        $mock_order->method( 'get_shipping_country' )->willReturn( 'DE' );
        $mock_order->method( 'get_billing_email' )->willReturn( 'max@test.de' );
        $mock_order->method( 'get_billing_phone' )->willReturn( '' );
        $mock_order->method( 'get_billing_first_name' )->willReturn( 'Max' );
        $mock_order->method( 'get_billing_last_name' )->willReturn( 'Mustermann' );
        $mock_order->method( 'get_billing_address_1' )->willReturn( 'Musterstr. 1' );
        $mock_order->method( 'get_billing_address_2' )->willReturn( '' );
        $mock_order->method( 'get_billing_city' )->willReturn( 'Berlin' );
        $mock_order->method( 'get_billing_postcode' )->willReturn( '10115' );
        $mock_order->method( 'get_billing_country' )->willReturn( 'DE' );
        $mock_order->expects( $this->once() )
            ->method( 'add_order_note' )
            ->willReturnCallback( function( $note ) use ( &$order_notes ) {
                $order_notes[] = $note;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'create_order' )
            ->willReturn( [ 'orderId' => 'sc-123' ] );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 100 );

        // Assert – AC-2: _spreadconnect_order_id wurde mit 'sc-123' gespeichert
        $this->assertEquals( 'sc-123', $updated_meta['_spreadconnect_order_id'] );
        // Assert – AC-2: Order Note enthaelt 'Spreadconnect Order erstellt: sc-123'
        $this->assertCount( 1, $order_notes );
        $this->assertStringContainsString( 'Spreadconnect Order erstellt: sc-123', $order_notes[0] );
    }
}

class SpreadconnectTrackingServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_apply_tracking_sets_post_meta_and_updates_status(): void {
        // Arrange
        $updated_meta  = [];
        $updated_status = null;

        Functions\when( 'get_post_meta' )->justReturn( '' ); // Kein vorhandenes Tracking

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'esc_url' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 77 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 77, 'DE123456789', 'https://tracking.example.com/DE123456789' );

        // Assert
        $this->assertEquals( 'DE123456789', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/DE123456789', $updated_meta['_spreadconnect_tracking_url'] );
        $this->assertEquals( 'completed', $updated_status );
    }

    public function test_apply_tracking_skips_if_tracking_already_set(): void {
        // Arrange – Tracking ist bereits identisch gesetzt
        Functions\when( 'get_post_meta' )->justReturn( 'DE123456789' ); // Gleiche Tracking-Nummer

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->expects( $this->never() )->method( 'update_status' );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 77, 'DE123456789', 'https://tracking.example.com' );

        // Assert – update_status wurde nicht aufgerufen (never-Expectation)
        $this->assertTrue( true );
    }

    public function test_health_endpoint_returns_ok(): void {
        $this->markTestIncomplete(
            'Health Endpoint: GET /wp-json/spreadconnect/v1/health -- Test gegen laufende WordPress-Instanz noetig (register_rest_route erfordert WordPress-Bootstrap).'
        );
    }

    public function test_poll_order_tracking_calls_apply_tracking_when_tracking_available(): void {
        // Arrange – AC-10: poll_order_tracking() ruft get_order() auf und delegiert an apply_tracking()
        $updated_meta   = [];
        $updated_status = null;

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'get_order' )
            ->with( 'sc-order-abc' )
            ->willReturn( [ 'trackingNumber' => 'TN-456', 'trackingUrl' => 'https://tracking.example.com/TN-456' ] );

        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'esc_url_raw' )->alias( fn($v) => $v );
        Functions\when( 'esc_url' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );
        Functions\when( 'error_log' )->justReturn( null );

        // Kein vorhandenes Tracking (apply_tracking Idempotenz-Check passiert)
        Functions\when( 'get_post_meta' )->justReturn( '' );

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 42 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->poll_order_tracking( 42, 'sc-order-abc' );

        // Assert – apply_tracking() wurde aufgerufen: Post Meta fuer Tracking wurde gesetzt
        $this->assertEquals( 'TN-456', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/TN-456', $updated_meta['_spreadconnect_tracking_url'] );
        $this->assertEquals( 'completed', $updated_status );
    }
}
```
</test_spec>

### Manuelle Tests (Acceptance – gegen Spreadconnect Staging)

1. Docker Compose starten: `docker compose up -d` → WordPress auf `http://localhost:8080`
2. Plugin-Verzeichnis: `wordpress/plugins/spreadconnect-pod/` via Docker Volume gemountet prüfen
3. WP-Admin → Plugins → "Spreadconnect POD" aktivieren
4. WP-Admin → Einstellungen → Spreadconnect POD → API Key eintragen (Staging-Key von Spreadconnect Dashboard)
5. WP-Admin → Einstellungen → Spreadconnect POD → "Staging-Modus" aktiviert lassen
6. Ein WooCommerce-Produkt bearbeiten: Custom Fields → `_spreadconnect_article_id` setzen (echte Spreadconnect Article ID)
7. Testbestellung mit Mollie Sandbox (Slice 3) durchführen → Bestellung erhält Status "processing"
8. WP-Admin → WooCommerce → Bestellungen → Bestellnotiz "Spreadconnect Order erstellt: sc-XXXX" prüfen
9. WP-Admin → WooCommerce → Bestellungen → Custom Fields: `_spreadconnect_order_id` gesetzt prüfen
10. `curl http://localhost:8080/wp-json/spreadconnect/v1/health` → `{"status":"ok"}` prüfen
11. Tracking-Webhook simulieren: `curl -X POST http://localhost:8080/wp-json/spreadconnect/v1/webhook -H "Content-Type: application/json" -d '{"wcOrderId":BESTELLNUMMER,"trackingNumber":"DE999","trackingUrl":"https://tracking.test"}'`
12. WP-Admin → Bestellung prüfen: Status "Completed", Custom Fields `_spreadconnect_tracking_number` = "DE999"
13. WooCommerce-Versandbenachrichtigungs-E-Mail in WP-Admin → WooCommerce → Status → E-Mails prüfen

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] Spreadconnect Plugin aktiviert + API Key konfiguriert
- [ ] `_spreadconnect_article_id` auf Testprodukt gesetzt
- [ ] Testbestellung landet in Spreadconnect Staging Dashboard
- [ ] `_spreadconnect_order_id` wird nach Bestellweiterleitung in WooCommerce gespeichert
- [ ] Tracking-Webhook akzeptiert korrekte Payloads
- [ ] WooCommerce Status nach Tracking-Empfang auf "completed"
- [ ] Admin-E-Mail bei Fehler korrekt konfiguriert und getestet (Test-Bestellung ohne article_id)
- [ ] PHPUnit Tests laufen durch
- [ ] Kein API Key im Git-Repository

---

## Constraints & Hinweise

**PHP-Version:**
- Plugin benötigt PHP 8.2+ (WordPress Docker Image: `wordpress:6.9-php8.2-apache` aus Slice 1)

**WooCommerce API:**
- `wc_get_order()` gibt `WC_Order` zurück – kein `get_post()` verwenden
- `$order->update_status('completed')` löst automatisch WooCommerce "Order Completed" E-Mail aus
- WooCommerce Custom Fields in WP-Admin: "Screen Options" → Custom Fields aktivieren

**Spreadconnect API:**
- Base URL Produktion: `api.spreadconnect.com`
- Base URL Staging: `staging.spreadconnect.com`
- Auth-Header: `Authorization: {API_KEY}` (kein Bearer-Präfix, gemäß architecture.md)
- Endpoint: `POST /orders` (nicht `/v1/orders` – aus architecture.md)

**Silent Fail:**
- Spreadconnect-Fehler sind KEIN Silent Fail (im Gegensatz zu Pinterest CAPI)
- Bei Fehler: Admin-E-Mail + error_log() + WooCommerce Order Note – immer

**Idempotenz:**
- `handle_order_processing` prüft `_spreadconnect_order_id` vor dem API-Call → doppelter Hook-Aufruf sicher
- `apply_tracking` prüft ob Tracking bereits identisch gesetzt ist → doppelter Webhook-Empfang sicher

**API Contract:**
- `SpreadconnectOrderItem` DTO: `{ articleId: String, sizeId: String, quantity: Int }` (aus architecture.md)
- Spreadconnect `orderId` in Response → `_spreadconnect_order_id` in wp_postmeta
- Spreadconnect `trackingNumber` + `trackingUrl` in GET Response → wp_postmeta

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-infrastruktur | WordPress + WooCommerce | Infrastructure | WooCommerce 10.x läuft unter `http://localhost:8080`; `wp_remote_post()`, `wp_mail()`, `update_post_meta()` verfügbar |
| slice-01-infrastruktur | `wp_options` (`spreadconnect_api_key`) | DB Setting | Plugin liest API Key via `get_option('spreadconnect_api_key')` |
| slice-01-infrastruktur | WooCommerce Stock Management deaktiviert | Configuration | Bereits in Slice 1 konfiguriert (POD = immer verfügbar) |
| slice-03-warenkorb-checkout-redirect | WooCommerce Bestellsystem | WordPress Hook | `woocommerce_order_status_processing` Hook wird ausgelöst nach Mollie-Zahlung; WC_Order mit Status "processing" existiert |
| slice-03-warenkorb-checkout-redirect | `WC_Order` Object | PHP Class | `$order->get_items()`, `$order->get_billing_*()`, `$order->get_shipping_*()` verfügbar |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `woocommerce_order_status_completed` | WordPress Hook | slice-06-pinterest-tracking | `$order->update_status('completed')` in `apply_tracking()` triggert diesen Hook → Slice 6 hängt Pinterest CAPI Purchase Event daran |
| `_spreadconnect_order_id` | Post Meta (shop_order) | slice-06 (indirekt), Admin | `get_post_meta($order_id, '_spreadconnect_order_id', true)` → Spreadconnect Order ID |
| `_spreadconnect_tracking_number` | Post Meta (shop_order) | Admin, WooCommerce E-Mail | `get_post_meta($order_id, '_spreadconnect_tracking_number', true)` |
| `_spreadconnect_tracking_url` | Post Meta (shop_order) | Admin, WooCommerce E-Mail | `get_post_meta($order_id, '_spreadconnect_tracking_url', true)` |
| `/wp-json/spreadconnect/v1/health` | REST Endpoint | Orchestrator Health Check | `GET` → `{ "status": "ok", "plugin": "spreadconnect-pod" }` |

### Integration Validation Tasks

- [ ] `woocommerce_order_status_processing` Hook feuert nach Mollie-Testzahlung (Slice 3)
- [ ] `_spreadconnect_order_id` wird nach API-Aufruf in wp_postmeta gespeichert
- [ ] `$order->update_status('completed')` in `apply_tracking()` triggert `woocommerce_order_status_completed` Hook (Slice 6 dependency)
- [ ] Health-Endpoint `GET /wp-json/spreadconnect/v1/health` antwortet mit HTTP 200

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prüft jedes Beispiel.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `SpreadconnectApiClient::request_with_retry()` | Sektion 4 | YES | Retry-Logik: 3x, Backoff 1s/2s/4s, 429-Header-Auswertung, X-RateLimit-Remaining |
| `SpreadconnectApiClient::create_order()` | Sektion 4 | YES | POST /orders, Auth-Header, 30s Timeout |
| `SpreadconnectApiClient::get_order()` | Sektion 4 | YES | GET /orders/{id} für Polling |
| `SpreadconnectOrderService::handle_order_processing()` | Sektion 5 | YES | Idempotenz-Check, build_order_items, api_client->create_order, update_post_meta, notify_admin |
| `SpreadconnectOrderService::build_order_items()` | Sektion 5 | YES | _spreadconnect_article_id aus post_meta, Größe aus Variation-Attribut, SpreadconnectOrderItem DTO |
| `SpreadconnectOrderService::notify_admin_on_failure()` | Sektion 5 | YES | wp_mail() mit korrektem Subject-Format `[POD Shop] Spreadconnect-Fehler: Bestellung #X` |
| `SpreadconnectTrackingService::handle_webhook()` | Sektion 6 | YES | REST-Endpoint-Handler, wcOrderId ODER SC-orderId-Lookup, apply_tracking() |
| `SpreadconnectTrackingService::apply_tracking()` | Sektion 6 | YES | update_post_meta (tracking_number + tracking_url), order->add_order_note, order->update_status('completed') |
| `SpreadconnectTrackingService::register_webhook_endpoint()` | Sektion 6 | YES | `register_rest_route('spreadconnect/v1', '/webhook', ...)` |
| `SpreadconnectTrackingService::register_health_endpoint()` | Sektion 6 | YES | `register_rest_route('spreadconnect/v1', '/health', ...)` |
| `spreadconnect-pod.php` Plugin-Hauptdatei | Sektion 7 | YES | Plugin-Header, `plugins_loaded` Hook, DI-Setup, Hook-Registrierung |
| `SpreadconnectSettings::render_settings_page()` | Sektion 7 | YES | API Key (type=password), Staging-Checkbox |
| `composer.json` | Sektion 8 | YES | PHPUnit ^11.0 + brain/monkey ^2.6 als Dev-Dependencies |

---

## Links

- Spreadconnect API Docs: Verfügbar nach Beantragung des API-Zugangs im Spreadconnect-Dashboard
- Spreadconnect Staging: `https://staging.spreadconnect.com`
- WordPress `wp_remote_post()` Docs: https://developer.wordpress.org/reference/functions/wp_remote_post/
- WordPress `register_rest_route()` Docs: https://developer.wordpress.org/reference/functions/register_rest_route/
- Brain\Monkey (WP-Mocking): https://github.com/Brain-WP/BrainMonkey
- PHPUnit 11 Docs: https://phpunit.de/documentation.html
- architecture.md: `docs/features/pod-shop-mvp/architecture.md`
- discovery.md: `docs/features/pod-shop-mvp/discovery.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Plugin-Dateien (WordPress)

- [ ] `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` – Plugin-Header + Initialisierung (plugins_loaded Hook, DI-Setup)
- [ ] `wordpress/plugins/spreadconnect-pod/composer.json` – PHPUnit ^11.0 + brain/monkey ^2.6 als Dev-Dependencies
- [ ] `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php` – HTTP-Client mit Retry (3x, Backoff 1s/2s/4s), 429-Header-Auswertung, X-RateLimit-Remaining
- [ ] `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php` – Bestellweiterleitung (Hook: woocommerce_order_status_processing), build_order_items, notify_admin_on_failure
- [ ] `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php` – Webhook-Handler + Polling + apply_tracking (update_status completed)
- [ ] `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php` – Admin-Settings-Page (API Key, Staging-Toggle)

### WordPress / WooCommerce Konfiguration (manuell)

- [ ] Plugin "Spreadconnect POD" im WP-Admin aktiviert
- [ ] API Key (Staging) im WP-Admin → Einstellungen → Spreadconnect POD eingetragen
- [ ] Staging-Modus aktiviert (für lokale Entwicklung)
- [ ] Mind. ein Testprodukt mit `_spreadconnect_article_id` Custom Meta ausgestattet
- [ ] Custom Fields in WP-Admin → Screen Options für Produktbearbeitung aktiviert

### Tests

- [ ] `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php` – PHPUnit Tests: SpreadconnectApiClientTest (4 Tests), SpreadconnectOrderServiceTest (5 Tests inkl. Erfolgs-Pfad AC-2), SpreadconnectTrackingServiceTest (4 Tests inkl. 1 markTestIncomplete fuer Health-Endpoint + AC-10 Polling-Test)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle PHP-Dateien liegen im Docker-Volume-Mount `./wordpress/plugins/` (aus Slice 1 `docker-compose.yml`)
- `composer install` muss im Plugin-Verzeichnis ausgeführt werden um PHPUnit zu installieren
- Brain\Monkey wird für WP-Funktionen-Mocking in Tests benötigt (`wp_remote_request`, `wp_mail`, `update_post_meta`, `get_option`, `wc_get_order`)
- `apply_tracking()` muss Idempotenz-Check enthalten (gleiche Tracking-Nummer nicht erneut setzen)
- `handle_order_processing()` muss Idempotenz-Check enthalten (`_spreadconnect_order_id` prüfen bevor API-Aufruf)
- Slice 6 (Pinterest Tracking) hängt seinen CAPI Purchase Event an `woocommerce_order_status_completed` – dieser wird durch `$order->update_status('completed')` in `apply_tracking()` ausgelöst
