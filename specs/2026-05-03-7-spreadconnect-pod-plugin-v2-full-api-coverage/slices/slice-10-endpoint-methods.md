# Slice 10: Endpoint-Wrapper-Methoden (27 typed Methods + 4 reserved)

> **Slice 10 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-10-endpoint-methods` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-08-rate-limit-retry", "slice-09-dto-value-objects"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Endpoint-Wrapper rufen einen ueber Test-Subclass-Override / Mockery-Spy abgegriffenen `request()`-Stub auf — kein Brain\Monkey-`wp_remote_*` noetig) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `n/a` (reine Wrapper-Methoden, kein Runtime-Boot noetig) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (`request()`-Aufrufe via Mockery-Partial-Mock oder Test-Subclass abgreifen — `request()` selbst wurde bereits in Slice 07/08 ge-tested und wird hier nicht erneut durch `wp_remote_*`-Aliase getrieben) |

---

## Ziel

Erweitert `SpreadconnectPod\Api\SpreadconnectClient` um **27 typed Endpoint-Wrapper-Methoden** (komplette Spreadconnect-Fulfillment-API-v2.3.9-Coverage) und **4 reservierte Out-of-Scope-Wrapper** (`throw NotImplemented`). Jeder Wrapper baut Pfad/Body korrekt aus seinen typed Argumenten, ruft die in Slice 07/08 etablierte `request()`-Methode mit dem korrekten HTTP-Verb + Pfad + Body-Shape auf und mapped die Response — wo verfuegbar — in eine DTO aus Slice 09. Dieser Slice ist die einzige Stelle, an der API-Endpunktnamen und URL-Shapes literal vorkommen; alle Konsumenten (Slice 12, 18, 23, 28-31, 36, 44) rufen ausschliesslich diese typed Methods auf.

---

## Endpoint-Inventar (Wrapper-Tabelle)

> **Quelle:** `architecture.md` -> Section "Outbound: Spreadconnect REST Endpoints (27)" Z. 87-123 (autoritative Spalten Method/Path/Request-DTO/Response-DTO). Reservierte Wrapper Z. 96-97, 100, 123. Bei Konflikten zwischen dieser Tabelle und der Architecture-Tabelle gilt die Architecture-Tabelle.

| # | Wrapper-Method | HTTP-Verb | Path-Template | Request-Body / Query | Response-Mapping |
|---|----------------|-----------|---------------|----------------------|------------------|
| 1 | `authenticate(): AuthOk` | GET | `/authentication` | — | `AuthOk::fromResponse($body)` |
| 2 | `getArticles(int $page, int $size, ?string $search = null): array` | GET | `/articles?page={p}&size={s}[&search={q}]` | — | `['items' => ArticleSummary[], 'page' => int, 'size' => int, 'total' => ?int]` (paginated wrap; items mapped via `ArticleSummary::fromResponse()`) |
| 3 | `getArticle(string $id): ArticleDetail` | GET | `/articles/{id}` | — | `ArticleDetail::fromResponse($body)` |
| 4 | `createOrder(OrderCreate $dto): array` | POST | `/orders` | `DtoMapper::camelToSnake($dto->toArray())` | raw assoc array (kein `OrderResponse`-DTO in Slice 09 -> `array` mit Keys `id`, `state`, ...; Keys snake->camel via `DtoMapper::snakeToCamel()`) |
| 5 | `getOrder(string $id): array` | GET | `/orders/{id}` | — | raw assoc array (`OrderDetail`-Shape; snake->camel via Mapper) |
| 6 | `confirmOrder(string $id): array` | POST | `/orders/{id}/confirm` | leerer Body | raw assoc array (`OrderDetail`-Shape) |
| 7 | `cancelOrder(string $id): array` | POST | `/orders/{id}/cancel` | leerer Body | raw assoc array (`OrderDetail`-Shape) |
| 8 | `getShipments(string $orderId): array` | GET | `/orders/{id}/shipments` | — | raw `Shipment[]`-Array (snake->camel via Mapper, kein DTO in Slice 09) |
| 9 | `getShippingTypes(string $orderId): array` | GET | `/orders/{id}/shippingTypes` | — | `ShippingType[]` via `array_map(ShippingType::fromResponse, $body)` |
| 10 | `setShippingType(string $orderId, string $shippingType): array` | POST | `/orders/{id}/shippingType` | `{shippingType: string}` | raw assoc array (`OrderDetail`-Shape) |
| 11 | `getSubscriptions(): array` | GET | `/subscriptions` | — | `Subscription[]` via `array_map(Subscription::fromResponse, $body)` |
| 12 | `createSubscription(string $eventType, string $callbackUrl, string $secret): Subscription` | POST | `/subscriptions` | `{eventType, callbackUrl, secret}` | `Subscription::fromResponse($body)` |
| 13 | `deleteSubscription(string $id): void` | DELETE | `/subscriptions/{id}` | — | `void` (2xx -> return; non-2xx -> bereits in `request()` als Exception) |
| 14 | `simulateOrderCancelled(string $orderId): array` | POST | `/orders/{id}/simulate/order-cancelled` | leerer Body | raw assoc array (`OrderDetail`-Shape) |
| 15 | `simulateOrderProcessed(string $orderId): array` | POST | `/orders/{id}/simulate/order-processed` | leerer Body | raw assoc array (`OrderDetail`-Shape) |
| 16 | `simulateShipmentSent(string $orderId): array` | POST | `/orders/{id}/simulate/shipment-sent` | leerer Body | raw assoc array (`Shipment`-Shape) |
| 17 | `getProductTypes(): array` | GET | `/productTypes` | — | raw `ProductTypeSummary[]`-Array (kein DTO in Slice 09) |
| 18 | `getProductType(string $id): array` | GET | `/productTypes/{id}` | — | raw assoc array (`ProductTypeDetail`-Shape) |
| 19 | `getProductTypeViews(string $id): array` | GET | `/productTypes/{id}/views` | — | raw `View[]`-Array |
| 20 | `getProductTypeSizeChart(string $id): array` | GET | `/productTypes/{id}/size-chart` | — | raw assoc array (`SizeChart`-Shape) |
| 21 | `getHotspot(string $productTypeId, string $designId): array` | GET | `/productTypes/{id}/hotspots/design/{designId}` | — | raw assoc array (`Hotspot`-Shape) |
| 22 | `createPreviews(string $productTypeId, string $designId, string $hotspotId, array $viewIds): array` | POST | `/productTypes/{id}/previews` | `{designId, hotspotId, viewIds: string[]}` | `Preview[]` via `array_map(Preview::fromArray, $body)` |
| 23 | `getStock(?string $productTypeId = null, ?array $skus = null): array` | GET | `/stock?[productTypeId={p}][&skus={comma-sep}]` | — | `StockEntry[]` via `array_map(StockEntry::fromResponse, $body)` |
| 24 | `getStockBySku(string $sku): StockEntry` | GET | `/stock/{sku}` | — | `StockEntry::fromResponse($body)` |
| 25 | `getStockByProductType(string $id): array` | GET | `/stock/productType/{id}` | — | `StockEntry[]` via `array_map(StockEntry::fromResponse, $body)` |
| 26 | (alias of #5 — entfaellt) | — | — | — | — |
| 27 | (Discovery-Zaehlung; #1-25 + reserved decken alle 27 wired Endpoints + 4 reserved) | — | — | — | — |

> **Tabelle-Hinweis:** Discovery zaehlt 27 "wired-to-callers" Endpoints. Diese werden durch Wrapper #1-#25 oben + die 4 reservierten Wrapper unten abgedeckt (#1+25=26 plus die in #2 und #23 enthaltene Multi-Param-Logik); Architecture Z. 125 dokumentiert: "27 in scope + 4 reserved/out-of-scope wrappers = 31 total wrapper methods". Die finale Method-Liste muss 1:1 die in slim-slices.md Slice-10 (Z. 248-253) genannten Method-Namen enthalten.

### Reservierte Wrapper (4 Stueck — `throw NotImplemented`)

| # | Wrapper-Method | HTTP-Verb | Path-Template | Verhalten |
|---|----------------|-----------|---------------|-----------|
| R1 | `pushArticle(...): never` | POST | `/articles` | wirft `SpreadconnectPod\Api\NotImplementedError` mit Message `"POST /articles is out of MVP scope (push-sync)"` (Architecture Z. 96) |
| R2 | `deleteArticle(string $id): never` | DELETE | `/articles/{id}` | wirft `SpreadconnectPod\Api\NotImplementedError` (Architecture Z. 97) |
| R3 | `updateOrder(string $id): never` | PUT | `/orders/{id}` | wirft `SpreadconnectPod\Api\NotImplementedError` (Architecture Z. 100) |
| R4 | `uploadDesign(...): never` | POST | `/designs/upload` | wirft `SpreadconnectPod\Api\NotImplementedError` (Architecture Z. 123) |

> **NotImplementedError:** Neue Exception-Klasse `SpreadconnectPod\Api\NotImplementedError extends \LogicException` (kein `RuntimeException` — der Aufruf ist ein Programmierfehler, nicht ein zur Laufzeit auftretender API-Fehler). KEINE Subklasse von `SpreadconnectClientError`/`SpreadconnectTransientError`, da Action Scheduler diesen Pfad weder als permanent noch als transient klassifizieren soll.

---

## Acceptance Criteria

> **Hinweise zu den ACs:** Statt 31 Einzel-ACs sind die ACs nach **Endpoint-Kategorie** gruppiert. Jede Kategorie deckt das gemeinsame Verhaltensmuster (HTTP-Verb + Pfad-Template + Body-Shape + Response-Mapping) ueber alle Methoden der Kategorie ab. Test-Writer parametrisiert die Tests pro Kategorie, sodass jede einzelne Wrapper-Method eindeutig durchlaeuft.

1) **AC-Auth — `authenticate()`**
   **GIVEN** ein Mock auf `request()`, der bei `('GET', '/authentication', null)` ein `AuthOk`-konformes Body-Array (`['ok' => true]`) zurueckgibt
   **WHEN** `SpreadconnectClient::authenticate()` aufgerufen wird
   **THEN** uebergibt der Wrapper exakt `'GET'`, `'/authentication'`, `null` an `request()`; der Returnwert ist eine `AuthOk`-Instanz; bei `request()`-Throw (`SpreadconnectClientError` Code `http_4xx`) propagiert der Wrapper die Exception unveraendert (kein Catch).

2) **AC-Articles — `getArticles()` / `getArticle()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `getArticles(2, 50)` aufgerufen wird
   **THEN** ist der `path`-Argument-String exakt `/articles?page=2&size=50` (kein `search`-Param). **AND WHEN** `getArticles(1, 25, 'shirt')` aufgerufen wird, **THEN** ist der `path` exakt `/articles?page=1&size=25&search=shirt` (`search` URL-encoded). **AND WHEN** `getArticle('art_42')` aufgerufen wird, **THEN** ist der `path` exakt `/articles/art_42`; Verb `'GET'`; Body `null`. Der `getArticle`-Returnwert ist eine `ArticleDetail`-Instanz aus `ArticleDetail::fromResponse($responseBody)`.

3) **AC-Orders-Lifecycle — `createOrder()` / `getOrder()` / `confirmOrder()` / `cancelOrder()`**
   **GIVEN** eine valide `OrderCreate`-DTO `$dto` (gemaess Slice 09)
   **WHEN** `createOrder($dto)` aufgerufen wird
   **THEN** ist Verb `'POST'`, Pfad `/orders`, der Body-Argument-Wert ein assoc Array, das **identisch** dem Resultat von `DtoMapper::camelToSnake($dto->toArray())` ist (snake_case Keys `external_order_reference`, `order_items`, `billing_address`, `shipping_address`, etc.). **AND** `getOrder('ord_7')` ruft `('GET', '/orders/ord_7', null)`. **AND** `confirmOrder('ord_7')` ruft `('POST', '/orders/ord_7/confirm', [])` (leerer assoc Array als Body, **nicht** `null`). **AND** `cancelOrder('ord_7')` ruft `('POST', '/orders/ord_7/cancel', [])`. Alle vier liefern den `request()`-Body als snake->camel-konvertiertes assoc Array zurueck (kein DTO-Wrap, da kein `OrderResponse`/`OrderDetail`-DTO in Slice 09 existiert).

4) **AC-Shipping — `getShipments()` / `getShippingTypes()` / `setShippingType()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `getShipments('ord_7')` aufgerufen wird
   **THEN** Verb `'GET'`, Pfad `/orders/ord_7/shipments`, Body `null`; Response wird als snake->camel-konvertiertes Array zurueckgegeben. **AND** `getShippingTypes('ord_7')` ruft `('GET', '/orders/ord_7/shippingTypes', null)`; Response-Body ist eine Liste -> `array_map(ShippingType::fromResponse, $body)` -> `ShippingType[]`. **AND** `setShippingType('ord_7', 'STANDARD')` ruft `('POST', '/orders/ord_7/shippingType', ['shippingType' => 'STANDARD'])` (Body-Key camelCase, da Path bereits camelCase ist und SC-API hier camel akzeptiert — siehe Architecture Z. 105); Returnwert ist snake->camel-konvertiertes assoc Array.

5) **AC-Subscriptions — `getSubscriptions()` / `createSubscription()` / `deleteSubscription()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `getSubscriptions()` aufgerufen wird
   **THEN** Verb `'GET'`, Pfad `/subscriptions`, Body `null`; Returnwert `Subscription[]` via `array_map(Subscription::fromResponse, $body)`. **AND** `createSubscription('Order.processed', 'https://shop.example/wp-json/spreadconnect/v1/webhook', 'sec123')` ruft `('POST', '/subscriptions', ['eventType' => 'Order.processed', 'callbackUrl' => '...', 'secret' => 'sec123'])`; Returnwert eine `Subscription`-Instanz. **AND** `deleteSubscription('sub_9')` ruft `('DELETE', '/subscriptions/sub_9', null)`; Returnwert `void` (Methodensignatur `: void`). Bei nicht-2xx propagiert die in Slice 07/08 geworfene Exception unveraendert.

6) **AC-Simulate (Staging-Only) — `simulateOrderCancelled()` / `simulateOrderProcessed()` / `simulateShipmentSent()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `simulateOrderCancelled('ord_7')` aufgerufen wird
   **THEN** Verb `'POST'`, Pfad `/orders/ord_7/simulate/order-cancelled`, Body `[]`. **AND** `simulateOrderProcessed('ord_7')` ruft Pfad `/orders/ord_7/simulate/order-processed`. **AND** `simulateShipmentSent('ord_7')` ruft Pfad `/orders/ord_7/simulate/shipment-sent`. Alle drei liefern den snake->camel-konvertierten Response-Body als assoc Array. Wrapper ist **NICHT** an `spreadconnect_use_staging`-Option gegated — die Staging-Only-UI-Gating passiert in Slice 44 (`Hub\Ajax\SimulateEvent`), nicht im HTTP-Wrapper.

7) **AC-ProductTypes — `getProductTypes()` / `getProductType()` / `getProductTypeViews()` / `getProductTypeSizeChart()` / `getHotspot()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `getProductTypes()` aufgerufen wird
   **THEN** Verb `'GET'`, Pfad `/productTypes`, Body `null`. **AND** `getProductType('pt_12')` ruft `/productTypes/pt_12`. **AND** `getProductTypeViews('pt_12')` ruft `/productTypes/pt_12/views`. **AND** `getProductTypeSizeChart('pt_12')` ruft `/productTypes/pt_12/size-chart`. **AND** `getHotspot('pt_12', 'des_88')` ruft `/productTypes/pt_12/hotspots/design/des_88`. Alle liefern snake->camel-konvertierte assoc Arrays bzw. Listen (kein DTO-Wrap; Slice 09 listet keine ProductType/View/SizeChart/Hotspot-DTOs). Caching-Logik (transient `sc_pt_{id}`, ETag) ist **NICHT** Teil dieses Slices und wird in Slice 23 / 36 implementiert.

8) **AC-Designs/Previews — `createPreviews()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `createPreviews('pt_12', 'des_88', 'hot_3', ['view_a', 'view_b'])` aufgerufen wird
   **THEN** Verb `'POST'`, Pfad `/productTypes/pt_12/previews`, Body exakt `['designId' => 'des_88', 'hotspotId' => 'hot_3', 'viewIds' => ['view_a', 'view_b']]` (camelCase Keys; `viewIds` als list-array preserved). Returnwert `Preview[]` via `array_map(Preview::fromArray, $body)` — Slice 09 AC-7 garantiert HTTPS-Validierung in `Preview::fromArray()`, sodass invalide URLs hier durchschlagen.

9) **AC-Stock — `getStock()` / `getStockBySku()` / `getStockByProductType()`**
   **GIVEN** ein `request()`-Mock
   **WHEN** `getStock('pt_12', null)` aufgerufen wird
   **THEN** Pfad `/stock?productTypeId=pt_12`. **AND WHEN** `getStock(null, ['SKU-1', 'SKU-2'])` aufgerufen wird, **THEN** Pfad `/stock?skus=SKU-1,SKU-2` (komma-separiert, **kein** `[]`-Array-Suffix; SKUs URL-encoded). **AND WHEN** `getStock(null, null)` aufgerufen wird, **THEN** wirft der Wrapper `\InvalidArgumentException` mit Message-Substring `"productTypeId or skus required"` — die Open-Q10-Resolution (Architecture Z. 797) verbietet einen Bulk-Call ohne Filter (waere ein voller Catalog-Pull). **AND** `getStockBySku('SKU-1')` ruft Pfad `/stock/SKU-1`. **AND** `getStockByProductType('pt_12')` ruft Pfad `/stock/productType/pt_12`. Alle drei mappen Response zu `StockEntry`/`StockEntry[]`.

10) **AC-NotImplemented (4 reservierte Wrapper)**
    **GIVEN** alle 4 reservierten Wrapper (`pushArticle`, `deleteArticle`, `updateOrder`, `uploadDesign`)
    **WHEN** einer von ihnen aufgerufen wird
    **THEN** wirft die Methode **vor** jedem `request()`-Aufruf eine `SpreadconnectPod\Api\NotImplementedError` (extends `\LogicException`), und das `request()`-Mock wurde **niemals** aufgerufen. Die Message enthaelt den HTTP-Verb + Pfad-Template + Substring `"out of MVP scope"`.

11) **AC-Path-Encoding — Sonderzeichen in Path-Variablen**
    **GIVEN** ein Path-Wrapper (z.B. `getArticle($id)`, `getOrder($id)`, `getStockBySku($sku)`)
    **WHEN** der Argument einen URL-Sonderzeichen-Wert enthaelt (z.B. `getArticle('art id/42')` oder `getStockBySku('SKU 1#weird')`)
    **THEN** wird der Wert via `rawurlencode()` URL-encoded, bevor er in den Pfad eingesetzt wird (Pfad-Beispiel: `/articles/art%20id%2F42`); Verb `'GET'`. Query-Strings werden ebenso encoded (`getArticles(1, 50, 'tee shirt')` -> `?search=tee+shirt` oder `tee%20shirt` — Implementer-Wahl, beides RFC-3986-konform).

12) **AC-Exception-Pass-Through — `request()`-Errors propagieren**
    **GIVEN** das `request()`-Mock wirft jeweils `SpreadconnectClientError` (Code `http_4xx`), `SpreadconnectTransientError` (Code `http_5xx`/`http_429`/`network_error`)
    **WHEN** ein beliebiger Endpoint-Wrapper aufgerufen wird
    **THEN** propagieren ALLE Exceptions **unveraendert** (kein `try/catch` im Wrapper, kein Wrapping in andere Exception-Klassen). Test-Writer parametrisiert mit `authenticate()` als Repraesentant (1 Test pro Exception-Klasse reicht, da das Verhalten ueber alle Wrapper identisch ist).

13) **AC-No-Bearer-Leak im Body — Wrapper schreiben keine API-Key-Werte in den Body**
    **GIVEN** ein `request()`-Mock, das das `body`-Argument inspiziert
    **WHEN** ein beliebiger Wrapper mit Body-Payload aufgerufen wird (z.B. `createOrder`, `createSubscription`, `setShippingType`, `createPreviews`)
    **THEN** enthaelt das `body`-Argument **niemals** den Substring der API-Key-Option (`spreadconnect_api_key`-Wert) oder das Wort `Bearer`. Auth wird ausschliesslich von `request()` (Slice 07) im `Authorization`-Header gesetzt.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** EIN PHPUnit-Test-File `tests/slices/pod-shop-mvp/slice-02-endpoints.php` (Naming gemaess slim-slices.md Z. 252). Test-Writer nutzt eine Test-Subclass `SpreadconnectClientUnderTest extends SpreadconnectClient`, die `request($method, $path, $body)` ueberschreibt und Calls in einer `public array $calls` sammelt + einen vorab gesetzten Response-Body returned. Alternativ Mockery-Partial-Mock — Implementer-Wahl. KEINE Brain\Monkey-`wp_remote_*`-Aliase noetig (`request()` selbst ist in Slice 07/08 abgedeckt).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-02-endpoints.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class SpreadconnectClientEndpointsTest extends TestCase
{
    // AC-1: authenticate() -> GET /authentication -> AuthOk
    public function test_authenticate_calls_get_authentication_and_maps_to_auth_ok_dto(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: getArticles() / getArticle() — Query-String + Path-Param
    public function test_get_articles_builds_paginated_query_string(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_get_articles_includes_search_param_when_provided(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_get_article_calls_path_with_id_and_maps_to_article_detail(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Order-Lifecycle (create / get / confirm / cancel)
    public function test_create_order_posts_camel_to_snake_converted_body(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_get_order_calls_get_orders_id(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_confirm_order_posts_empty_body_to_confirm_path(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_cancel_order_posts_empty_body_to_cancel_path(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Shipping (getShipments / getShippingTypes / setShippingType)
    public function test_get_shipments_calls_correct_path(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_get_shipping_types_returns_shipping_type_dto_list(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_set_shipping_type_posts_camel_case_body(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Subscriptions (get / create / delete)
    public function test_get_subscriptions_returns_subscription_dto_list(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_create_subscription_posts_event_type_callback_secret(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_delete_subscription_calls_delete_path_and_returns_void(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Simulate-Endpoints (3 Methoden)
    public function test_simulate_order_cancelled_posts_to_correct_path(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    public function test_simulate_order_processed_posts_to_correct_path(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    public function test_simulate_shipment_sent_posts_to_correct_path(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: ProductTypes (5 Methoden)
    public function test_get_product_types_calls_index_path(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_get_product_type_calls_path_with_id(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_get_product_type_views_calls_views_subpath(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_get_product_type_size_chart_calls_size_chart_subpath(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_get_hotspot_calls_design_subpath(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: createPreviews
    public function test_create_previews_posts_design_hotspot_views_body(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    public function test_create_previews_returns_preview_dto_list(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Stock (3 Methoden + Filter-Validation)
    public function test_get_stock_with_product_type_filter_builds_query(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_get_stock_with_skus_filter_builds_comma_separated_query(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_get_stock_without_filter_throws_invalid_argument(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_get_stock_by_sku_calls_path_and_returns_dto(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_get_stock_by_product_type_calls_path_and_returns_list(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Reservierte Wrapper werfen NotImplementedError vor request()
    public function test_reserved_wrappers_throw_not_implemented_without_calling_request(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: rawurlencode auf Path-Variablen
    public function test_path_variables_are_rawurlencoded(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Exception-Pass-Through (Slice 07/08 Errors propagieren unveraendert)
    public function test_request_exceptions_propagate_unchanged_through_wrappers(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: Body enthaelt keine Bearer-/API-Key-Leaks
    public function test_wrapper_body_never_contains_api_key_or_bearer_substring(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-08-rate-limit-retry` | `SpreadconnectClient::request($method, $path, $body): array` | public method | Slice 07/08 ACs gruen; Signatur unveraendert; Return-Shape `['status', 'body', 'headers']`. Slice 10 nutzt **nur** den Body-Teil (`$result['body']`). |
| `slice-08-rate-limit-retry` | `SpreadconnectClientError` (`http_4xx`) und `SpreadconnectTransientError` (`http_429`/`http_5xx`/`network_error`) | exception classes | Wrapper catchen sie **nicht**; Tests verifizieren Pass-Through. |
| `slice-09-dto-value-objects` | `OrderCreate` (mit `toArray(): array`-Methode), `OrderItem`, `Address`, `Money` | DTO-Klassen | `toArray()` muss in Slice 09 vorhanden sein — Slice 10 ruft sie auf, um den Body-Payload zu serialisieren. **Hinweis fuer Slice-09-Compliance:** Falls `toArray()` dort fehlt, wird ein Mini-Update-Slice benoetigt; alternativ liefert der Wrapper das DTO direkt an `DtoMapper::camelToSnake()` per Reflection. Implementer entscheidet. |
| `slice-09-dto-value-objects` | `ArticleDetail`, `ArticleSummary`, `ShippingType`, `Subscription`, `StockEntry`, `Preview`, `AuthOk` mit `fromResponse()`/`fromArray()` | DTO-Factories | Wrapper rufen sie auf; bei Validation-Fail propagiert `\InvalidArgumentException` unveraendert. |
| `slice-09-dto-value-objects` | `DtoMapper::snakeToCamel()` / `camelToSnake()` | Helper-Klasse | Wird fuer Body-Serialisierung (camelToSnake) und untyped Response-Mapping (snakeToCamel) genutzt. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectClient::authenticate(): AuthOk` | public method | `slice-12-test-connection-ajax` | `public function authenticate(): AuthOk` |
| `SpreadconnectClient::getArticles()` / `getArticle()` | public methods | `slice-23-sync-article-job`, `slice-24-sync-catalog-job`, `slice-34-product-meta-box` (article-picker) | siehe Wrapper-Tabelle |
| `SpreadconnectClient::createOrder()` / `getOrder()` / `confirmOrder()` / `cancelOrder()` / `getShipments()` | public methods | `slice-28-order-submit-job`, `slice-29-order-confirm-cancel-jobs`, `slice-30-order-webhooks-handler`, `slice-32-order-meta-box` | siehe Wrapper-Tabelle |
| `SpreadconnectClient::getShippingTypes()` / `setShippingType()` | public methods | `slice-29-order-confirm-cancel-jobs` (Pre-Check), `slice-32-order-meta-box` | siehe Wrapper-Tabelle |
| `SpreadconnectClient::getSubscriptions()` / `createSubscription()` / `deleteSubscription()` | public methods | `slice-18-subscription-manager`, `slice-19-subscriptions-ui` | siehe Wrapper-Tabelle |
| `SpreadconnectClient::simulate*()` (3 Methoden) | public methods | `slice-44-dev-tools-simulate-endpoints` | siehe Wrapper-Tabelle |
| `SpreadconnectClient::getProductType*()` (5 Methoden) | public methods | `slice-23-sync-article-job` | siehe Wrapper-Tabelle |
| `SpreadconnectClient::createPreviews()` | public method | `slice-23-sync-article-job` (Image-Pull) | siehe Wrapper-Tabelle |
| `SpreadconnectClient::getStock*()` (3 Methoden) | public methods | `slice-36-stock-cache`, AJAX `spreadconnect_refresh_stock` | siehe Wrapper-Tabelle |
| `SpreadconnectPod\Api\NotImplementedError` | exception class (extends `\LogicException`) | nur intern | `class NotImplementedError extends \LogicException` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` — **Edit** (kein Neu-Anlegen). Hinzufuegen aller 27 typed Endpoint-Wrapper-Methoden + 4 reservierte `throw NotImplementedError`-Wrapper. Slice 07/08 Konstruktor und `request()`-Methode bleiben unveraendert.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/NotImplementedError.php` — Neue Exception-Klasse `extends \LogicException`. Genutzt nur fuer die 4 reservierten Wrapper.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-02-endpoints.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEINE Aenderung der Slice-07/08-Logik in `request()` — nur neue public Wrapper-Methoden hinzufuegen.
- KEINE Caching-Logik in den Wrappern (Transients fuer ProductTypes/Stock kommen in Slice 23/36; ETag-Behandlung fuer `GET /productTypes` ebenfalls Slice 23).
- KEINE Settings-Gating im Wrapper (z.B. `simulate*()` ist NICHT an `spreadconnect_use_staging` gekoppelt — UI-Gating in Slice 44).
- KEINE Domain-Validierung im Wrapper (z.B. `confirmOrder` prueft NICHT, ob Shipping-Type gesetzt ist — das ist Pre-Check in Slice 29).
- KEINE State-Machine-Mutationen im Wrapper (Order-State `submitting`/`NEW`/`CONFIRMED` setzt der jeweilige Job in Slice 28/29).
- KEINE WC-Order-Meta-Writes (`_spreadconnect_*`-Postmeta) im Wrapper.
- KEINE Action-Scheduler-Calls im Wrapper.
- KEINE Logging-Calls in den Wrappern (Logging passiert in `request()` aus Slice 07; die Wrapper sind reine Adaptoren).
- KEINE Re-Implementation der DTOs (kommt aus Slice 09).
- KEINE neuen Exception-Klassen ausser `NotImplementedError`.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);`.
- Methoden-Sichtbarkeit: alle Endpoint-Wrapper `public`.
- Methoden-Naming: exakt die in slim-slices.md Slice-10 (Z. 248-253) genannten Namen — `authenticate`, `getArticles`, `getArticle`, `createOrder`, `getOrder`, `confirmOrder`, `cancelOrder`, `getShipments`, `getShippingTypes`, `setShippingType`, `getSubscriptions`, `createSubscription`, `deleteSubscription`, `simulateOrderCancelled`, `simulateOrderProcessed`, `simulateShipmentSent`, `getProductTypes`, `getProductType`, `getProductTypeViews`, `getProductTypeSizeChart`, `getHotspot`, `createPreviews`, `getStock`, `getStockBySku`, `getStockByProductType`. Reservierte: `pushArticle`, `deleteArticle`, `updateOrder`, `uploadDesign` (Naming intentionell, klar als "out-of-scope" lesbar).
- Path-Building: Helper `private function buildPath(string $template, array $vars): string` der `{name}`-Platzhalter via `rawurlencode()` ersetzt. KEIN `sprintf` mit roher Konkatenation.
- Query-String-Building: Helper `private function buildQuery(array $params): string` der `null`-Werte filtert und `http_build_query($params, '', '&', PHP_QUERY_RFC3986)` aufruft. Bei `skus`-Array: explizit `implode(',', array_map('rawurlencode', $skus))` (komma-separiert, KEIN `&skus[]=`-Repeat). Empty Query -> kein `?`-Suffix.
- Body-Serialisierung fuer Request-DTOs: `DtoMapper::camelToSnake($dto->toArray())` (siehe AC-3). Bei einfachen Body-Wrappern (z.B. `setShippingType`, `createSubscription`, `createPreviews`) wird der Body als assoc-Array literal gebaut und **ohne** Mapper-Call uebergeben — die SC-API akzeptiert hier camelCase-Keys gemaess Architecture (Z. 105 / 107 / 119).
- Response-Mapping: `request()`-Returnwert ist `['status', 'body', 'headers']`; der Wrapper extrahiert `$result['body']` (assoc Array, bereits json_decoded in Slice 07) und ruft die DTO-Factory bzw. `DtoMapper::snakeToCamel()`. Bei list-Responses: `array_map([Subscription::class, 'fromResponse'], $items)`.
- `void`-Return fuer `deleteSubscription` (DELETE-Endpoint liefert kein meaningful Body).
- `NotImplementedError` extends `\LogicException` (NICHT `\RuntimeException`, NICHT `SpreadconnectClientError`/`TransientError`). Constructor-Message-Pattern: `"$verb $path is out of MVP scope"`.
- Reservierte Wrapper haben **keine** funktionsgleiche public Method-Signatur mit `?` oder `void` — sie returnen `never`-Type (PHP 8.1+) und werfen sofort.
- KEINE Verwendung von `wp_remote_*` direkt in einem Wrapper — alles via `request()`.
- KEINE `static`-Properties im Client (Per-Instanz-State bleibt aus Slice 08 unveraendert).
- Method-Reihenfolge in der Datei: gruppiert nach Kategorie (Auth, Articles, Orders, Shipping, Subscriptions, Simulate, ProductTypes, Designs, Stock, Reserved) — verbessert Lesbarkeit; PSR-12.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08) | **Edit, nicht ersetzen.** Slice 07/08 Konstruktor + `request()`-Methode + Sleep-Hook + Header-State (`lastRateLimitRemaining`) bleiben unveraendert. Wrapper-Methoden werden hinzugefuegt. Alle Slice-07/08-ACs muessen weiterhin gruen sein (Test-Writer fuehrt Slice-07/08-Tests + Slice-10-Tests in einem Lauf aus). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Wird vom `request()`-Pfad (4xx) geworfen und transparent durch alle Wrapper propagiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07/08) | **Wiederverwendet, unveraendert.** Wird vom `request()`-Pfad (5xx/429/network) geworfen und transparent durch alle Wrapper propagiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/*` (Slice 09) | **Wiederverwendet, unveraendert.** 13 DTOs + `DtoMapper`. Wrapper rufen die `fromResponse()`/`fromArray()`-Factories und den Mapper auf. |

Hinweis: Es gibt KEINE v1-Wrapper-Methoden, die uebernommen werden — Slice 01 hat das v1-Plugin geloescht. Slice 10 ist ein Greenfield-Endpoint-Layer.

**Referenzen:**
- Architecture: `architecture.md` -> Section "Outbound: Spreadconnect REST Endpoints (27)" Z. 87-125 — autoritative Quelle fuer Method/Path/Request-DTO/Response-DTO. **Bei Konflikten zwischen dieser Spec und der Tabelle gilt die Tabelle.**
- Architecture: `architecture.md` -> Service-Map-Zeile `Api\SpreadconnectClient` Z. 364 (Verantwortlichkeit "Outbound HTTP wrapper. Bearer auth, base-URL toggle, single fail-fast retry on 429, X-RateLimit-* honor.").
- Architecture: `architecture.md` -> Open-Question-Resolutions Z. 796 (Q9: server-side `?search=` filter), Z. 797 (Q10: bulk `/stock` with filter, per-SKU loops verboten), Z. 798 (Q11: inline `shippingType` in `OrderCreate`).
- Architecture: `architecture.md` -> Section "Data Transfer Objects (DTOs)" Z. 159-176 — definiert welche DTOs existieren (= welche Wrapper DTO-Returns vs. raw-array-Returns haben).
- Discovery: `discovery.md` -> Slice 2 "API Client + Authentication" (Z. 923, "Methods für alle 27 Endpoints typed (Request/Response-DTOs)"); Z. 16 (v1 deckt nur 2 von 27 Endpoints — Slice 10 schliesst diese Luecke).
- Slim-Slices: `slices/slim-slices.md` -> Slice-10-Eintrag (Z. 248-255, Done-Signal: pro Methode korrekter HTTP-Verb + Path + Body-Shape an `request()`; Response zur korrekten DTO mapped).
- Slice 07: `slices/slice-07-http-client-base.md` -> `request()`-Signatur und Logging-Source.
- Slice 08: `slices/slice-08-rate-limit-retry.md` -> Erweiterung um 429-Retry und `X-RateLimit-Remaining`-Awareness; Slice 10 baut transparent darauf.
- Slice 09: `slices/slice-09-dto-value-objects.md` -> alle 13 DTOs + `DtoMapper`. **AC-Konsistenz:** Slice-09-AC-2 verlangt `fromArray()`/`fromResponse()`-Factories; Slice-10-AC-Mapping ruft diese.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 10 (kein UI; Caller-UIs entstehen ab Slice 11/19/26/32/34/44).
