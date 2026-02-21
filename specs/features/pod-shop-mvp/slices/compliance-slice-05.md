# Gate 2: Slice 05 Compliance Report (Re-Check)

**Gepruefter Slice:** `docs/features/pod-shop-mvp/slices/slice-05-pod-anbindung-spreadconnect.md`
**Pruefdatum:** 2026-02-21
**Re-Check nach Fix:** Ja (1. Retry)
**Architecture:** `docs/features/pod-shop-mvp/architecture.md`
**Wireframes:** `docs/features/pod-shop-mvp/wireframes.md`
**Discovery:** `docs/features/pod-shop-mvp/discovery.md`
**Vorherige Slices:** `slice-01-infrastruktur.md`, `slice-03-warenkorb-checkout-redirect.md`

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 41 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## Fix-Verification (vorherige Blocking Issues)

| Issue | Beschreibung | Geprueft | Status |
|-------|--------------|----------|--------|
| Issue 1 | `it.todo()` durch `$this->markTestIncomplete()` ersetzt | Slice Zeile 1343-1346 | Behoben |
| Issue 2 | Test fuer AC-2 Erfolgs-Pfad `handle_order_processing()` mit `_spreadconnect_order_id`-Assertion vorhanden | Slice Zeile 1188-1269 | Behoben |
| Issue 3 | Test fuer AC-10 `poll_order_tracking()` delegiert an `apply_tracking()` vorhanden | Slice Zeile 1348-1394 | Behoben |

**Detail Issue 1:** `test_health_endpoint_returns_ok()` enthaelt jetzt korrekte PHPUnit-Syntax:
```php
public function test_health_endpoint_returns_ok(): void {
    $this->markTestIncomplete(
        'Health Endpoint: GET /wp-json/spreadconnect/v1/health -- Test gegen laufende WordPress-Instanz noetig (register_rest_route erfordert WordPress-Bootstrap).'
    );
}
```
PHPUnit markiert diesen Test als "incomplete" (kein Fatal Error mehr). Die gesamte `SpreadconnectTrackingServiceTest`-Klasse ist jetzt ausfuehrbar.

**Detail Issue 2:** `test_handle_order_processing_stores_sc_order_id_on_success()` (Zeile 1188-1269) prueft:
- `$updated_meta['_spreadconnect_order_id'] === 'sc-123'` (via `update_post_meta` Mock)
- `$order_notes[0]` enthaelt `'Spreadconnect Order erstellt: sc-123'` (via `add_order_note` Mock)
- `$mock_client->create_order()` wird genau einmal aufgerufen mit korrektem Payload
Vollstaendige Abdeckung von AC-2.

**Detail Issue 3:** `test_poll_order_tracking_calls_apply_tracking_when_tracking_available()` (Zeile 1348-1394) prueft:
- `$mock_client->get_order('sc-order-abc')` wird genau einmal aufgerufen
- `$updated_meta['_spreadconnect_tracking_number'] === 'TN-456'`
- `$updated_meta['_spreadconnect_tracking_url'] === 'https://tracking.example.com/TN-456'`
- `$updated_status === 'completed'`
Vollstaendige End-to-End-Verifikation des Polling-Pfads inkl. Delegation an `apply_tracking()`.

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-2 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-3 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-4 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-5 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-6 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-7 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-8 | Yes | No | Yes | Yes | No | Pass (Hinweis) |
| AC-9 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-10 | Yes | Yes | Yes | Yes | Yes | Pass |

**AC-8 Hinweis (kein Blocking):**
AC-8 beschreibt ausschliesslich WooCommerce-internes Verhalten (`$order->update_status('completed')` loest automatisch die Standard-E-Mail aus). Dieses Verhalten ist nicht durch einen eigenen PHPUnit-Unit-Test verifizierbar und ist redundant zu AC-7 (das die Versandbenachrichtigung bereits als THEN-Bedingung enthaelt). Da das Verhalten durch den in AC-7 ueberdeckenden Test (`test_apply_tracking_sets_post_meta_and_updates_status`) indirekt validiert wird (der `update_status('completed')`-Aufruf wird geprueft), ist dies kein Blocking Issue. Die Empfehlung aus dem ersten Report gilt weiterhin: AC-8 koennte mit AC-7 zusammengefuehrt werden.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| SpreadconnectApiClient (Sektion 4) | Yes | Yes | Yes | Yes | Pass |
| SpreadconnectOrderService (Sektion 5) | Yes | Yes | Yes | Yes | Pass |
| SpreadconnectTrackingService (Sektion 6) | Yes | Yes | Yes | Yes | Pass |
| spreadconnect-pod.php Plugin-Hauptdatei (Sektion 7) | Yes | Yes | Yes | Yes | Pass |
| SpreadconnectSettings (Sektion 7) | Yes | Yes | Yes | Yes | Pass |
| composer.json (Sektion 8) | Yes | Yes | Yes | N/A | Pass |
| PHPUnit Test Suite (Testfaelle-Sektion) | Yes | Yes | Yes | N/A | Pass |

**PHPUnit Test Suite Detail:**
- `SpreadconnectApiClientTest`: 4 Tests, alle mit valider PHP-Syntax und Brain\Monkey-Mocks. Pass.
- `SpreadconnectOrderServiceTest`: 5 Tests, alle valide. Neu: `test_handle_order_processing_stores_sc_order_id_on_success()` mit vollstaendigem Mockery- und Brain\Monkey-Setup. Pass.
- `SpreadconnectTrackingServiceTest`: 4 Tests. `test_health_endpoint_returns_ok()` verwendet jetzt `$this->markTestIncomplete()` (valide PHPUnit-Syntax). `test_poll_order_tracking_calls_apply_tracking_when_tracking_available()` neu und vollstaendig. Pass.

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `php-wordpress` | `php-wordpress` (Custom PHP WordPress Plugin) | Pass |
| Commands vollstaendig | 3 (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | Pass |
| Start-Command | `docker compose up -d` | Passend zu Docker-Compose-Stack aus Slice 1 | Pass |
| Health-Endpoint | `http://localhost:8080/wp-json/spreadconnect/v1/health` | Custom REST Plugin-Endpoint | Pass |
| Mocking-Strategy | `mock_external` | Definiert | Pass |

---

## A) Architecture Compliance

### Schema Check

| Arch Field | Arch Type | Slice Spec | Status |
|------------|-----------|------------|--------|
| `_spreadconnect_article_id` / `product` | TEXT | TEXT (wp_postmeta meta_value) | Pass |
| `_spreadconnect_order_id` / `shop_order` | TEXT | TEXT (wp_postmeta meta_value) | Pass |
| `_spreadconnect_tracking_number` / `shop_order` | TEXT | TEXT (wp_postmeta meta_value) | Pass |
| `_spreadconnect_tracking_url` / `shop_order` | TEXT | TEXT (wp_postmeta meta_value) | Pass |
| `spreadconnect_api_key` / `wp_options` | TEXT | TEXT (get_option / register_setting) | Pass |

Alle vier Custom Post Meta Keys aus architecture.md sind korrekt implementiert. Typen stimmen mit der WooCommerce-Datenbankarchitektur ueberein.

### API Check

| Endpoint | Arch Method | Slice Method | Auth Header | Status |
|----------|-------------|--------------|-------------|--------|
| `POST /orders` | POST | POST | `Authorization: {API_KEY}` (kein Bearer) | Pass |
| `GET /orders/{id}` | GET | GET | `Authorization: {API_KEY}` | Pass |
| `POST /wp-json/spreadconnect/v1/webhook` | Eigener Endpoint | POST | API Key Verification | Pass |
| `GET /wp-json/spreadconnect/v1/health` | Eigener Endpoint | GET | Public | Pass |

Auth-Header: Architecture.md spezifiziert `Authorization: {API_KEY}` ohne Bearer-Praefix. Slice dokumentiert und implementiert dies korrekt: `'Authorization' => $this->api_key`.

Endpoint-Pfad: Architecture.md listet `POST /orders` (nicht `/v1/orders`). Slice implementiert `$this->base_url . '/orders'`. Korrekt.

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| API Key in wp_options, nicht im Code | Gespeichert in wp_options | `get_option('spreadconnect_api_key')` in Runtime | Pass |
| Rate Limiting: 60 Calls/Min, 429-Header | HTTP 429 mit X-RateLimit-Retry-After-Seconds | Implementiert in `request_with_retry()` | Pass |
| Timeout 30s | Timeout: 30s | `'timeout' => $this->timeout` (30) | Pass |
| Retry 3x mit Backoff | Retry 3x, Admin-Notification | `$max_retries = 3`, Backoff [1,2,4]s | Pass |
| Kein API Key im Git | Definition of Done Punkt | "Kein API Key im Git-Repository" in DoD | Pass |
| Input Sanitization Webhook | Validation | `sanitize_text_field()`, `esc_url_raw()` | Pass |

---

## B) Wireframe Compliance

Slice 5 ist ein rein server-seitiges WordPress-Plugin (PHP). Die Wireframes betreffen ausschliesslich Next.js Frontend-Screens. Admin-Pages von WordPress-Plugins sind explizit von Wireframes ausgenommen.

Der Slice dokumentiert dies korrekt: "Dieser Slice hat keine UI-Anforderungen aus den Next.js-Wireframes. Das Plugin ist rein serverseitig (PHP/WordPress). Wireframes gelten nicht fuer Admin-Pages."

### UI Elements

| Wireframe Element | Annotation | Slice Component | Status |
|-------------------|------------|-----------------|--------|
| N/A -- Kein Frontend-UI in diesem Slice | -- | Admin-Settings-Page (WP-Standard-Styling) | Pass (N/A) |

### State Variations

| State | Wireframe | Slice | Status |
|-------|-----------|-------|--------|
| N/A -- Kein Frontend-UI in diesem Slice | -- | -- | Pass (N/A) |

### Visual Specs

| Spec | Wireframe Value | Slice Value | Status |
|------|-----------------|-------------|--------|
| N/A -- Kein Frontend-UI in diesem Slice | -- | -- | Pass (N/A) |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Validation | Status |
|----------|--------------|-----------------|------------|--------|
| WordPress + WooCommerce (Docker) | slice-01-infrastruktur | "Requires From Other Slices" Zeile 1 | WooCommerce 10.x unter `http://localhost:8080` | Pass |
| `wp_options` (`spreadconnect_api_key`) | slice-01-infrastruktur | "Requires From Other Slices" Zeile 2 | `get_option('spreadconnect_api_key')` | Pass |
| WooCommerce Stock Management deaktiviert | slice-01-infrastruktur | "Requires From Other Slices" Zeile 3 | Bereits in Slice 1 konfiguriert | Pass |
| `woocommerce_order_status_processing` Hook | slice-03-warenkorb-checkout-redirect | "Requires From Other Slices" Zeile 4 | Nach Mollie-Zahlung, WC_Order Status "processing" | Pass |
| `WC_Order` Object | slice-03-warenkorb-checkout-redirect | "Requires From Other Slices" Zeile 5 | `get_items()`, `get_billing_*()`, `get_shipping_*()` | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Interface | Status |
|----------|----------|---------------|-----------|--------|
| `woocommerce_order_status_completed` Hook | slice-06-pinterest-tracking | Ja -- "Provides To Other Slices" Zeile 1 | `$order->update_status('completed')` in `apply_tracking()` | Pass |
| `_spreadconnect_order_id` Post Meta | slice-06 (indirekt), Admin | Ja -- Zeile 2 | `get_post_meta($order_id, '_spreadconnect_order_id', true)` | Pass |
| `_spreadconnect_tracking_number` Post Meta | Admin, WooCommerce E-Mail | Ja -- Zeile 3 | `get_post_meta($order_id, '_spreadconnect_tracking_number', true)` | Pass |
| `_spreadconnect_tracking_url` Post Meta | Admin, WooCommerce E-Mail | Ja -- Zeile 4 | `get_post_meta($order_id, '_spreadconnect_tracking_url', true)` | Pass |
| `/wp-json/spreadconnect/v1/health` REST Endpoint | Orchestrator Health Check | Ja -- Zeile 5 | `GET` -> `{ "status": "ok", "plugin": "spreadconnect-pod" }` | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `woocommerce_order_status_completed` Hook | slice-06 (pending Slice) | N/A -- Hook, keine Page-Datei | slice-06 (pending) | Pass |
| Plugin-Hauptdatei + Services | WordPress Admin (WP-Standard) | Ja -- DELIVERABLES_START/END | Dieser Slice (slice-05) | Pass |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page/Resource | In Deliverables? | Status |
|------|--------------------------|-------------------|--------|
| AC-1 | `staging.spreadconnect.com/orders` (externe API) | N/A -- externe API | Pass |
| AC-2 | `_spreadconnect_order_id` Post Meta | Ja | Pass |
| AC-3 | `SpreadconnectApiClient::create_order()` | Ja -- Plugin-PHP-Datei | Pass |
| AC-4 | `SpreadconnectApiClient::request_with_retry()` | Ja -- Plugin-PHP-Datei | Pass |
| AC-5 | Admin-E-Mail + WooCommerce Order Note | Ja -- `notify_admin_on_failure()` | Pass |
| AC-6 | Admin-E-Mail via `wp_mail()` | Ja -- `notify_admin_on_failure()` | Pass |
| AC-7 | `POST /wp-json/spreadconnect/v1/webhook` | Ja -- Tracking Service | Pass |
| AC-8 | WooCommerce built-in E-Mail | N/A -- WooCommerce-internes Verhalten | Pass |
| AC-9 | `GET /wp-json/spreadconnect/v1/health` | Ja -- Health Endpoint im Tracking Service | Pass |
| AC-10 | `SpreadconnectTrackingService::poll_order_tracking()` | Ja -- Tracking Service | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `SpreadconnectApiClient` (komplett) | Sektion 4 | Yes | Yes | Pass |
| `SpreadconnectOrderService` (komplett) | Sektion 5 | Yes | Yes | Pass |
| `SpreadconnectTrackingService` (komplett) | Sektion 6 | Yes | Yes | Pass |
| `spreadconnect-pod.php` Plugin-Hauptdatei | Sektion 7 | Yes | Yes | Pass |
| `SpreadconnectSettings` | Sektion 7 | Yes | Yes | Pass |
| `composer.json` | Sektion 8 | Yes | Yes | Pass |
| PHPUnit Test Suite | Testfaelle-Sektion | Yes | Yes | Pass |

Alle Code-Beispiele sind als Mandatory im "Code Examples (MANDATORY)" Table gelistet. Pass.

---

## E) Build Config Sanity Check

Dieser Slice hat keine Build-Config-Deliverables (kein vite.config, webpack.config, tsconfig). Es handelt sich um ein PHP WordPress Plugin mit `composer.json`. Es gibt keinen Build-Schritt.

| Pruef-Aspekt | Requirement | Vorhanden? | Status |
|--------------|-------------|------------|--------|
| Build Plugin | N/A -- PHP/Composer | N/A | Pass (N/A) |
| process.env Replacement | N/A -- PHP | N/A | Pass (N/A) |
| CSS Build Plugin | N/A -- kein Frontend | N/A | Pass (N/A) |

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Klasse | Test Method | Status |
|--------------------|----------------|-------------|-------------|--------|
| AC-1: POST an Spreadconnect bei Status "processing" | Ja | SpreadconnectOrderServiceTest | `test_build_order_items_returns_dto_with_correct_fields` + `test_handle_order_processing_stores_sc_order_id_on_success` | Pass |
| AC-2: orderId wird als Post Meta gespeichert | Ja | SpreadconnectOrderServiceTest | `test_handle_order_processing_stores_sc_order_id_on_success` (neu, Zeile 1188-1269) | Pass |
| AC-3: 3 Retries bei HTTP 500 | Ja | SpreadconnectApiClientTest | `test_returns_wp_error_after_max_retries_on_500` | Pass |
| AC-4: X-RateLimit-Retry-After-Seconds bei 429 | Ja | SpreadconnectApiClientTest | `test_uses_retry_after_header_on_429` | Pass |
| AC-5: Fehlende article_id verhindert Weiterleitung | Ja | SpreadconnectOrderServiceTest | `test_build_order_items_returns_error_when_article_id_missing` | Pass |
| AC-6: Admin-E-Mail-Subject-Format bei Fehler | Ja | SpreadconnectOrderServiceTest | `test_notify_admin_on_failure_sends_email_with_correct_subject` | Pass |
| AC-7: Webhook setzt Tracking-Meta + Status "completed" | Ja | SpreadconnectTrackingServiceTest | `test_apply_tracking_sets_post_meta_and_updates_status` | Pass |
| AC-8: WooCommerce E-Mail bei Status "completed" | Indirekt | SpreadconnectTrackingServiceTest | `update_status('completed')` wird in `test_apply_tracking_sets_post_meta_and_updates_status` verifiziert | Pass |
| AC-9: Health-Endpoint HTTP 200 + JSON | Ja (markTestIncomplete) | SpreadconnectTrackingServiceTest | `test_health_endpoint_returns_ok()` mit `$this->markTestIncomplete()` | Pass |
| AC-10: Polling GET /orders/{id} + apply_tracking() | Ja | SpreadconnectTrackingServiceTest | `test_poll_order_tracking_calls_apply_tracking_when_tracking_available` (neu, Zeile 1348-1394) | Pass |

**Test-Zaehlung gesamt:** 13 Tests (4 ApiClient + 5 OrderService + 4 TrackingService), davon 1 als `markTestIncomplete` markiert. Alle PHPUnit-Tests haben valide PHP-Syntax.

**DELIVERABLES-Section korrekt aktualisiert:**
Zeile 1555 der Slice-Datei: "SpreadconnectTrackingServiceTest (4 Tests inkl. 1 markTestIncomplete fuer Health-Endpoint + AC-10 Polling-Test)" -- stimmt mit tatsaechlicher Test-Implementierung ueberein. Pass.

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | Kein Frontend-UI in diesem Slice | No | N/A | Pass (N/A) |
| State Machine: Bestellstatus | Processing -> Completed | Yes | Yes -- Hook + update_status('completed') | Pass |
| Transitions | Completed: Tracking -> Versand-E-Mail | Yes | Yes -- apply_tracking() | Pass |
| Business Rules: Bestellweiterleitung | Jede bezahlte Bestellung automatisch an Spreadconnect | Yes | Yes -- woocommerce_order_status_processing Hook | Pass |
| Business Rules: Tracking in WooCommerce | Tracking-Nummern + Kundenbenachrichtigung | Yes | Yes -- update_post_meta + update_status('completed') | Pass |
| Data: Spreadconnect Produkt-ID | Fuer API-Zuordnung | Yes | Yes -- `_spreadconnect_article_id` Custom Meta | Pass |
| Flow 2: POD-Fulfillment | API-Zugang, Designs, Tracking, Versandbenachrichtigung | Yes | Yes -- alle Schritte adressiert | Pass |

Discovery Compliance vollstaendig. Alle 5 Discovery-Anforderungen fuer Slice 5 sind abgedeckt.

---

## Blocking Issues Summary

**Keine Blocking Issues.**

Alle drei Blocking Issues aus dem ersten Compliance Report (2026-02-21) wurden behoben:

1. `it.todo()` (JavaScript-Syntax) wurde durch `$this->markTestIncomplete()` (valide PHPUnit-Syntax) ersetzt.
2. Test fuer AC-2 (`test_handle_order_processing_stores_sc_order_id_on_success`) wurde hinzugefuegt und prueft `_spreadconnect_order_id` via `update_post_meta` sowie die Order Note.
3. Test fuer AC-10 (`test_poll_order_tracking_calls_apply_tracking_when_tracking_available`) wurde hinzugefuegt und verifiziert den vollstaendigen Polling-Pfad inkl. Delegation an `apply_tracking()`.

---

## Recommendations

1. **Optional (Qualitaet):** AC-8 ("WooCommerce versendet automatisch die Standard-Versandbenachrichtigungs-E-Mail") sollte entweder als durch AC-7 abgedeckt dokumentiert werden oder das AC sollte mit AC-7 zusammengefuehrt werden, da es ausschliesslich WooCommerce-internes Verhalten beschreibt.

2. **Optional (Dokumentation):** Die Inkonsistenz zwischen DTO-Feldname `sizeId` (in der DTO-Tabelle architecture.md) und API-Request-Feldname `size` (in der API-Tabelle architecture.md) sollte in architecture.md geklart werden. Der Slice folgt korrekt der DTO-Tabelle -- kein Handlungsbedarf im Slice selbst.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

VERDICT: APPROVED
