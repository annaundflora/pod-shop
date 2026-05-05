# Slice 44: Dev-Tools (Simulate-Endpoints, nur Staging)

> **Slice 44 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-44-dev-tools-simulate` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-30-order-webhooks-handler", "slice-11-settings-form"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `current_user_can`, `check_ajax_referer`, `get_option`, `wp_send_json_success`/`error`, `wp_unslash`, `sanitize_text_field`, `__()`; `SpreadconnectClient` als Mockery-Mock fuer `simulate*()`-Methoden) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: Settings-Page mit `spreadconnect_use_staging=true` -> Dev-Tools-Section sichtbar; Klick auf `[Simulate Order Processed]` mit Test-Order-ID -> Webhook landet in Plugin und mutiert WC-Order. Mit `spreadconnect_use_staging=false`: Section unsichtbar im DOM, AJAX-Action liefert 403.) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP-Admin-API; SpreadconnectClient via Konstruktor injiziert; keine echte HTTP-I/O) |

---

## Ziel

Schliesst Slice 10 "Logs + Polish" auf der Dev-Tools-Achse: rendert eine Settings-Sub-Section "Developer Tools", die **nur** bei `spreadconnect_use_staging=true` sichtbar ist und drei Buttons (`Simulate Order Cancelled`, `Simulate Order Processed`, `Simulate Shipment Sent`) plus ein Test-Order-ID-Input-Feld bereitstellt. Der zugehoerige AJAX-Handler ruft die in Slice 10 bereitgestellten `SpreadconnectClient::simulate*()`-Wrapper, sodass SC den entsprechenden Webhook an die Plugin-Receiver-Route zustellt (Round-Trip-Verifikation des Webhook-Pipelines aus Slice 15-17 + 30). Production-Sicherheit: Server-Side-Gate auf `spreadconnect_use_staging` macht den Handler in Production zu einem 403-No-Op.

---

## Acceptance Criteria

1) **AC-Section-Render-Staging-On — Dev-Tools-Section sichtbar bei Staging-Toggle**
   **GIVEN** `get_option('spreadconnect_use_staging') === true` und ein eingeloggter User mit `manage_woocommerce`-Capability
   **WHEN** `Hub\View\SettingsDevTools::render()` von der Settings-Page (Slice 11 Section-Slot oberhalb ⑨ Footer; wireframes.md Z. 647 `staging_active`) aufgerufen wird
   **THEN** rendert die Methode die Section mit Header `'Developer Tools'`, einem Test-Order-ID-Text-Input (Name-Attr `spreadconnect_dev_test_order_id`, kein-Wert-Persist — nur Form-State), drei Buttons mit Text `'Simulate Order Cancelled'`/`'Simulate Order Processed'`/`'Simulate Shipment Sent'` und einem Status-Container (`<div id="spreadconnect-dev-tools-status">`). Das Markup enthaelt `wp_nonce_field('spreadconnect_simulate_event', '_ajax_nonce', false, false)` (oder Aequivalent ueber `wp_create_nonce` als `data-nonce`-Attribute auf den Buttons).

2) **AC-Section-Render-Staging-Off — Section ausgeblendet in Production**
   **GIVEN** `get_option('spreadconnect_use_staging') === false` (oder Option fehlt -> Default `false` aus Slice 05)
   **WHEN** `Hub\View\SettingsDevTools::render()` aufgerufen wird
   **THEN** kehrt die Methode **frueh** ohne Markup-Output zurueck (kein leerer Container, keine Heading, kein versteckter `<div style="display:none">` — DOM ist sauber leer). Verifizierbar via `ob_get_clean() === ''`.

3) **AC-Section-Render-Capability — `manage_woocommerce` erforderlich**
   **GIVEN** `spreadconnect_use_staging=true`, aber `current_user_can('manage_woocommerce') === false`
   **WHEN** `SettingsDevTools::render()` aufgerufen wird
   **THEN** kehrt die Methode ohne Markup zurueck (analog AC-2 — kein Throw, kein `wp_die`, da `Hub\View\Settings::render()` aus Slice 11 AC-9 bereits Cap-gated; Defense-in-Depth-Check). Verifizierbar wie AC-2.

4) **AC-AJAX-Action-Naming + Hook-Registration**
   **GIVEN** Plugin-Boot
   **WHEN** `Hub\Ajax\SimulateEvent::register()` aufgerufen wird (aus `Bootstrap\Plugin::init()`, analog Slice 12 Mount-Point-Konvention)
   **THEN** registriert die Methode genau **eine** `add_action('wp_ajax_spreadconnect_simulate_event', [self::class, 'handle'])`. **Kein** `wp_ajax_nopriv_*`-Hook (admin-only). Idempotent bei doppeltem `init()`-Call.

5) **AC-AJAX-Capability-Gate — `manage_woocommerce` erforderlich**
   **GIVEN** ein eingeloggter User ohne `manage_woocommerce`
   **WHEN** `Hub\Ajax\SimulateEvent::handle()` aufgerufen wird
   **THEN** terminiert der Handler mit `wp_send_json_error(['message' => __('Permission denied.', 'spreadconnect-pod')], 403)`. `simulate*()`-Methoden auf `SpreadconnectClient` werden **niemals** aufgerufen.

6) **AC-AJAX-Nonce-Gate — Nonce-Action `spreadconnect_simulate_event`**
   **GIVEN** ein User mit `manage_woocommerce`, aber ohne gueltiges `_ajax_nonce`-Token
   **WHEN** `SimulateEvent::handle()` aufgerufen wird
   **THEN** prueft `check_ajax_referer('spreadconnect_simulate_event', '_ajax_nonce', false)`; bei `false` antwortet der Handler mit HTTP 403 via `wp_send_json_error(['message' => __('Invalid nonce.', 'spreadconnect-pod')], 403)`. `simulate*()` wird nicht aufgerufen. Cap- und Nonce-Check muessen beide vor jeder Geschaeftslogik laufen; Reihenfolge ist Implementer-Wahl.

7) **AC-AJAX-Staging-Gate — Server-Side-Refusal in Production**
   **GIVEN** Cap+Nonce gueltig, aber `get_option('spreadconnect_use_staging') !== true`
   **WHEN** `SimulateEvent::handle()` aufgerufen wird
   **THEN** terminiert der Handler mit `wp_send_json_error(['message' => __('Dev tools are only available when staging mode is enabled.', 'spreadconnect-pod')], 403)`. `simulate*()` wird **niemals** aufgerufen. Dies ist die autoritative Sicherheitsschicht — UI-Hide aus AC-2 ist nur Cosmetic.

8) **AC-AJAX-Event-Whitelist — `event` Param-Validierung**
   **GIVEN** Cap+Nonce+Staging gueltig
   **WHEN** `SimulateEvent::handle()` mit `$_POST['event']` aufgerufen wird
   **THEN** akzeptiert der Handler **nur** einen der drei Werte `'order_cancelled'`, `'order_processed'`, `'shipment_sent'`. Bei jedem anderen Wert (inkl. leer/missing) -> `wp_send_json_error(['message' => __('Unknown event type.', 'spreadconnect-pod')], 400)`; kein `simulate*()`-Call.

9) **AC-AJAX-OrderId-Validation — `order_id` non-empty**
   **GIVEN** Cap+Nonce+Staging+Event gueltig
   **WHEN** `$_POST['order_id']` leer oder fehlt (nach `wp_unslash`+`sanitize_text_field`)
   **THEN** -> `wp_send_json_error(['message' => __('Test order ID is required.', 'spreadconnect-pod')], 400)`. Kein Format-Constraint (keine Regex), nur non-empty-trim — die SC-API liefert bei ungueltiger ID einen 4xx-Fehler, der dann ueber AC-11 abgefangen wird.

10) **AC-AJAX-Dispatch — Wrapper-Method-Mapping**
    **GIVEN** Cap+Nonce+Staging+Event+OrderId valide; Event-Wert `'order_processed'`; OrderId `'sc_42'`; injizierter `SpreadconnectClient`-Mock
    **WHEN** `SimulateEvent::handle()` ausgefuehrt wird
    **THEN** ruft der Handler **genau einmal** `SpreadconnectClient::simulateOrderProcessed('sc_42')` (analog `'order_cancelled'` -> `simulateOrderCancelled`, `'shipment_sent'` -> `simulateShipmentSent`; siehe Slice 10 Wrapper-Tabelle Z. 57-59). Bei Erfolg antwortet er mit `wp_send_json_success(['ok' => true, 'message' => __('Simulate triggered. Webhook should arrive within seconds — check Webhook Log.', 'spreadconnect-pod')])`.

11) **AC-AJAX-Error-Handling — Permanent vs Transient**
    **GIVEN** der `simulate*()`-Wrapper wirft entweder `SpreadconnectClientError` (4xx, z. B. 404 — Test-Order-ID nicht in SC-Staging) ODER `SpreadconnectTransientError` (5xx/network)
    **WHEN** `handle()` die Exception faengt
    **THEN** (a) bei `SpreadconnectClientError`: `wp_send_json_error(['ok' => false, 'message' => $e->getMessage()], 400)`; (b) bei `SpreadconnectTransientError`: `wp_send_json_error(['ok' => false, 'message' => $e->getMessage()], 502)`. Keine Re-Throws, kein generischer `\Throwable`-Catch, kein `try/finally`-State-Reset (Handler ist stateless analog Slice 12 Constraint).

12) **AC-i18n — Alle UI-Strings ueber `__()` mit Domain `spreadconnect-pod`**
    **GIVEN** alle User-facing-Strings in `Hub\View\SettingsDevTools::render()` und `Hub\Ajax\SimulateEvent::handle()` (Section-Header, Button-Labels, Input-Placeholder, Error-Messages, Success-Message)
    **WHEN** sie gerendert oder zurueckgegeben werden
    **THEN** sind sie konsequent durch `__()` / `esc_html__()` / `_e()` mit Domain `'spreadconnect-pod'` gewrapped (analog Slice 11 AC-10). Source-Strings auf Englisch (Discovery-Konvention).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** EIN PHPUnit-Test-File `tests/slices/pod-shop-mvp/slice-10-dev-tools-simulate.php` (Naming-Konvention nach Discovery-Slice 10). Zwei Test-Klassen koennen in einer Datei coexistieren (`SettingsDevToolsTest` + `SimulateEventAjaxTest`) oder split — Implementer-Wahl. Brain\Monkey-Mocks fuer `get_option`, `current_user_can`, `check_ajax_referer`, `wp_unslash`, `sanitize_text_field`, `wp_send_json_success`/`error`, `__()`, `esc_html__()`, `wp_create_nonce`. `SpreadconnectClient` als Mockery-Mock via Konstruktor-DI injiziert (analog Slice 30 Pattern); `wp_send_json_error`-Mocks fangen den Status-Code als zweites Arg ab.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-10-dev-tools-simulate.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class SettingsDevToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // AC-1: Section rendert bei spreadconnect_use_staging=true mit allen 3 Buttons + Input + Nonce
    public function test_render_outputs_section_when_staging_enabled(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Section rendert NICHTS bei spreadconnect_use_staging=false
    public function test_render_outputs_nothing_when_staging_disabled(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Section rendert NICHTS ohne manage_woocommerce-Capability
    public function test_render_outputs_nothing_without_manage_woocommerce(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-12: UI-Strings sind durch __() / esc_html__() mit Domain spreadconnect-pod gewrapped
    public function test_render_uses_spreadconnect_pod_text_domain(): void
    {
        $this->markTestIncomplete('AC-12');
    }
}

final class SimulateEventAjaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // AC-4: register() haengt sich an wp_ajax_spreadconnect_simulate_event (kein nopriv)
    public function test_register_hooks_admin_only_ajax_action(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: register() ist idempotent
    public function test_register_is_idempotent(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Ohne manage_woocommerce -> 403, kein simulate*()-Call
    public function test_handle_returns_403_without_capability(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Ungueltige Nonce -> 403, kein simulate*()-Call
    public function test_handle_returns_403_on_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Server-Gate: spreadconnect_use_staging=false -> 403, kein simulate*()-Call
    public function test_handle_refuses_when_staging_disabled(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Unbekannter Event-Wert -> 400 + UI-Message
    public function test_handle_rejects_unknown_event_value(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Leerer event-Param -> 400
    public function test_handle_rejects_missing_event_param(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Leere order_id -> 400
    public function test_handle_rejects_empty_order_id(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: event=order_cancelled -> simulateOrderCancelled(orderId)
    public function test_handle_dispatches_order_cancelled_to_correct_wrapper(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: event=order_processed -> simulateOrderProcessed(orderId)
    public function test_handle_dispatches_order_processed_to_correct_wrapper(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: event=shipment_sent -> simulateShipmentSent(orderId)
    public function test_handle_dispatches_shipment_sent_to_correct_wrapper(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: Erfolgreicher Dispatch -> wp_send_json_success mit ok=true
    public function test_handle_returns_success_payload_on_wrapper_ok(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: SpreadconnectClientError -> 400 mit Exception-Message
    public function test_handle_returns_400_on_client_error(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-11: SpreadconnectTransientError -> 502 mit Exception-Message
    public function test_handle_returns_502_on_transient_error(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Error-Messages sind durch __() mit Domain spreadconnect-pod gewrapped
    public function test_handle_error_messages_use_text_domain(): void
    {
        $this->markTestIncomplete('AC-12');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-10-endpoint-methods` | `SpreadconnectClient::simulateOrderCancelled(string $orderId): array`, `simulateOrderProcessed(string $orderId): array`, `simulateShipmentSent(string $orderId): array` | public methods | Slice 10 AC-6 verifiziert HTTP-Verb + Pfad + Body. Slice 44 ruft die Wrapper unveraendert auf; Exceptions werden von Slice 07 (`SpreadconnectClientError`/`TransientError`) propagiert. |
| `slice-07-http-client-base` | `SpreadconnectClientError`, `SpreadconnectTransientError` | exception classes | Beide werden im AJAX-Handler in **separaten** `catch`-Blocks behandelt (AC-11). |
| `slice-11-settings-form` | `Hub\View\Settings::render()` als Markup-Host fuer die Dev-Tools-Section | static method | Slice 11 listet Dev-Tools-Section explizit als Out-of-Scope (Constraint Z. 267). Slice 44 fuegt einen `SettingsDevTools::render()`-Call **per Edit** der Settings-View an der Position oberhalb ⑨ Footer ein (wireframes.md Z. 647 `staging_active`). |
| `slice-11-settings-form` | Option `spreadconnect_use_staging` (boolean, persistiert via `SettingsValidator::sanitize`) | WP Option | Slice 11 sanitisiert Toggle zu PHP-`bool`. Slice 44 liest via `get_option('spreadconnect_use_staging', false)`; ein truthy `bool`-Wert (`true`) aktiviert UI + AJAX. |
| `slice-30-order-webhooks-handler` | `OrderEventHandler::handle($payload)`-Pipeline | webhook-receiving stack | Slice 30 verarbeitet die SC-Webhooks, die durch die Simulate-Calls ausgeloest werden. Slice 44 hat keine Code-Abhaengigkeit auf den Handler — die Verkettung passiert **ueber das Netz** (SC -> Plugin Webhook-Receiver -> ProcessWebhookEventJob -> OrderEventHandler). Slice 44 ist Slice 30 nur als **Done-Signal-Vorbedingung** dependent (manuelle QA verifiziert den End-to-End-Round-Trip). |
| `slice-12-test-connection-ajax` | Mount-Point-Pattern (`register()` wird aus `Bootstrap\Plugin::init()` aufgerufen) | Convention | Slice 12 etabliert das Pattern; Slice 44 spiegelt es 1:1. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| AJAX-Action `spreadconnect_simulate_event` | WP admin-ajax.php action | Settings-Page JS (slice-internal — kein externer Caller) | `POST admin-ajax.php` mit `action=spreadconnect_simulate_event&_ajax_nonce=<token>&event=order_processed\|order_cancelled\|shipment_sent&order_id=<sc-order-id>` -> JSON `{success: bool, data: {ok: bool, message: string}}` |
| `SpreadconnectPod\Hub\Ajax\SimulateEvent::register` | static method | `Bootstrap\Plugin::init()` | `public static function register(): void` |
| `SpreadconnectPod\Hub\Ajax\SimulateEvent::handle` | static method | WP `wp_ajax_spreadconnect_simulate_event`-Hook; **nicht** direkt aus PHP-Code aufrufen | `public static function handle(): void` (terminiert via `wp_send_json_*`) |
| `SpreadconnectPod\Hub\View\SettingsDevTools::render` | static method | `Hub\View\Settings::render()` (Edit) | `public static function render(): void` (gibt HTML aus oder kein-Output bei Disabled-State) |
| Inline DOM-Selectors (`#spreadconnect-dev-tools-status`, Button-Selectors mit `data-event="..."`) | rendered HTML | Slice-internes JS-Wiring (analog Slice 12 Pattern); kein Folge-Slice konsumiert diese Selectors | DOM-Selectors stabil, nicht in Folge-Slices ohne Migration veraendern. |

> **Mount-Point-Check:** `SettingsDevTools::render()` muss aus `Hub\View\Settings::render()` (Slice 11) per **Edit** aufgerufen werden, sonst ist die Section "tot". `SimulateEvent::register()` muss aus `Bootstrap\Plugin::init()` per **Edit** aufgerufen werden, sonst ist der `wp_ajax_*`-Hook nicht registriert. Beide Edits sind als Deliverables aufgenommen.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/SettingsDevTools.php` — Neue Klasse `final class SpreadconnectPod\Hub\View\SettingsDevTools` mit `public static function render(): void`. Frueh-Return bei `spreadconnect_use_staging !== true` ODER fehlender `manage_woocommerce`-Capability. Bei aktivem Staging: rendert Section-Header `'Developer Tools'`, Test-Order-ID-Input, drei Buttons (Order-Cancelled / Order-Processed / Shipment-Sent), Status-Container, Nonce-Output (`spreadconnect_simulate_event`), Inline-JS-Wiring (analog Slice 12 Pattern: `wp_print_inline_script_tag` oder Inline-Script mit `data-*`-Attributen + `ajaxurl`).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/SimulateEvent.php` — Neue Klasse `final class SpreadconnectPod\Hub\Ajax\SimulateEvent` mit `public static function register(): void` (haengt `wp_ajax_spreadconnect_simulate_event` an `handle`) und `public static function handle(): void` (Cap+Nonce+Staging-Gates -> Event-Whitelist -> OrderId-Sanitize -> `SpreadconnectClient`-Instanziierung -> `simulate*()`-Dispatch -> `wp_send_json_*`). Konstruktion der Client-Instanz inline (`new SpreadconnectClient()`); echtes DI-Wiring kommt in Slice 37/46.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` — Fuege einen Call zu `SettingsDevTools::render()` an der Position oberhalb der ⑨ Footer-Section ein (wireframes.md Z. 647). KEINE Aenderung an Slice-11-Settings-API-Registration oder `SettingsValidator`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt `SimulateEvent::register()`-Aufruf in `init()` analog Slice 12 `TestConnection::register()`-Pattern. Idempotent (Slice 02 AC-5 Pattern).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-10-dev-tools-simulate.php` wird vom Test-Writer-Agent erstellt, **nicht** im Deliverable. Falls das Inline-JS in eine eigene `assets/js/dev-tools.js` ausgelagert wird, ist das eine Implementer-Wahl und KEIN zusaetzliches Deliverable.

---

## Constraints

**Scope-Grenzen:**
- KEIN E2E-Test des Webhook-Round-Trips — die HTTP-Verkettung (SC sendet Webhook nach simulate-Call) ist Done-Signal-Vorbedingung, nicht PHPUnit-getestet (manuelle QA gegen SC-Staging-Account).
- KEIN UI-Block fuer Webhook-Log-Display — Slice 41 (`Hub\View\Webhooks`) zeigt das Log; Slice 44 verlinkt auf `?section=webhooks` als UX-Hint im Success-Message-Text (optional).
- KEINE Caching-Logik — Simulate-Calls sind Test-Aktionen, keine wiederholten Reads.
- KEINE Rate-Limit-Logik im Handler — analog Slice 12 (Architecture Z. 515: kein Server-Side-Rate-Limit fuer admin-ajax-Buttons).
- KEINE Logging-Calls — `SpreadconnectClient::request()` (Slice 07) loggt automatisch via `WC_Logger`-Adapter (Source `spreadconnect-api-client`).
- KEINE Test-Order-ID-Persistierung — das Input-Feld ist Form-State, nicht Setting (kein `register_setting`-Call, kein `get_option`-Read).
- KEINE Format-Validierung der Test-Order-ID (Regex/Length) — non-empty-trim reicht; SC-API-Fehler werden ueber AC-11 abgefangen.
- KEIN Echo der `simulate*()`-Response in der UI-Message — der Webhook-Round-Trip ist die Verifikation, nicht der HTTP-Response des Simulate-Calls.
- KEIN Production-Override-Switch (z. B. `define('SPREADCONNECT_FORCE_DEV_TOOLS', true)`) — Single-Source-of-Truth ist `spreadconnect_use_staging`.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in beiden neuen Files.
- `Hub\View\SettingsDevTools` und `Hub\Ajax\SimulateEvent` als `final class` mit ausschliesslich `static` Methoden (stateless Front-Controller analog Slice 11/12).
- Nonce-Action-Name: **exakt** `spreadconnect_simulate_event` (`wp_create_nonce` und `check_ajax_referer` muessen identischen String nutzen — Tests verifizieren).
- Nonce-Field-Name: `_ajax_nonce` (WP-Standard; konsistent mit Slice 12 Constraint Z. 260).
- Capability-Check: `current_user_can('manage_woocommerce')` (konsistent mit Slice 11 AC-9, Slice 12 Constraint).
- Sanitize-Pfad: `sanitize_text_field( wp_unslash( $_POST['order_id'] ?? '' ) )` und identisch fuer `event`. KEIN `esc_html` (zu aggressiv).
- Event-Whitelist als `private const EVENTS = ['order_cancelled', 'order_processed', 'shipment_sent']`; Map zur Wrapper-Method via `match`-Expression oder Lookup-Array. **Implementer-Wahl**, aber Map muss im Test fix-verified sein.
- Response-Format: **immer** `wp_send_json_success(['ok' => bool, 'message' => string])` oder `wp_send_json_error(['ok' => bool, 'message' => string], $statusCode)` — niemals nackte `echo`/`die` (analog Slice 12 Constraint Z. 264).
- Status-Codes: 403 (Cap/Nonce/Staging-Refusal), 400 (Bad Input — unknown event, empty order_id, ClientError), 502 (TransientError als Upstream-Bad-Gateway-Semantic).
- Exception-Handling: separate `catch (SpreadconnectClientError $e)` und `catch (SpreadconnectTransientError $e)` — keine generische `catch (\Throwable $t)`-Falle (analog Slice 12 Constraint Z. 265).
- Client-Instanziierung: `new \SpreadconnectPod\Api\SpreadconnectClient()` (kein Override-Param — der Handler nutzt den persistierten API-Key, im Gegensatz zu Slice 12 das mit unsaved-Key-Override arbeitet). KEIN Caching/Singleton.
- Render-Methode terminiert **ohne** `wp_die` bei Disabled-State — sie ist Inline-Markup-Producer, kein Page-Renderer (im Gegensatz zu Slice 11 `Settings::render()`).
- Server-Side-Staging-Gate (AC-7) ist autoritativ; UI-Hide (AC-2) ist Cosmetic — die beiden Layer sind beide Pflicht (Defense-in-Depth gegen Direct-AJAX-Calls aus DevTools-Console in Production).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Import + instanziieren.** Reuse der drei `simulate*()`-Wrapper-Methoden aus Slice 10 — KEINE Erweiterung, KEINE neuen Endpoint-Calls. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` + `SpreadconnectTransientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Beide werden im AJAX-Handler in separaten Catch-Klauseln behandelt. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11 + Slice 12 + Slice 14 + Slice 45 Edits) | **Edit-Target** — Slice 44 fuegt einen `SettingsDevTools::render()`-Call vor der ⑨ Footer-Section ein. KEINE Aenderung an Section-Markup, Field-Registrations oder anderen Sections. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus Slice 03/04/05/06/12/14/17/23/24/25/28/30/...) | **Edit-Target** — `SimulateEvent::register()`-Aufruf in `init()` analog vorhandener `TestConnection::register()`-Registration aus Slice 12. |
| Mount-Point-Pattern aus Slice 12 (`register()`-Static + `Bootstrap\Plugin::init()`-Edit) | **Reuse** — Slice 44 spiegelt das Pattern 1:1, kein neues Hook-Konzept. |
| Option `spreadconnect_use_staging` (Slice 05 Default + Slice 11 Sanitizer) | **Reuse** — Single-Source-of-Truth fuer Staging-State. |
| Inline-Script-Pattern aus Slice 12 (Constraint Z. 268) | **Reuse** — `wp_print_inline_script_tag` oder Inline-Script via `data-*`-Attributes statt hartcodierter Werte. |

**Referenzen:**
- Architecture: `architecture.md` -> Endpoint-Tabelle Z. 109-111 (drei Simulate-Endpoints + UI-Caller "Settings -> Dev-Tools (staging only)" + Hinweis "UI hidden in production"); Z. 80 (`spreadconnect_use_staging` Toggle Doku); Z. 824 (Discovery -> Settings-Dev-Tools-Mapping Slice 10).
- Wireframes: `wireframes.md` -> Screen 7 State-Variation `staging_active` Z. 647 (Position der Dev-Tools-Section: oberhalb ⑨ Footer; nur in Staging).
- Discovery: `discovery.md` -> Slice 10 "Logs + Webhook-Log + Polish" Z. 931 (Dev-Tools-Simulate-Endpoints im Staging als Polish-Element).
- Slim-Slices: `slices/slim-slices.md` -> Slice-44-Eintrag (Z. 638-645): Done-Signal "Manuell auf Staging: Klick triggert SC-Simulate-Endpoint -> Webhook eingeht in Plugin. Production: UI-Section hidden".
- Vorgaenger Slice 10: `slices/slice-10-endpoint-methods.md` -> AC-6 (Wrapper sind nicht an `use_staging` gegated — UI-Gating in Slice 44); Wrapper-Tabelle Z. 57-59.
- Vorgaenger Slice 11: `slices/slice-11-settings-form.md` -> Constraint Z. 267 (Dev-Tools-Section explizit Out-of-Scope von Slice 11; Slice 44 fuellt Slot); AC-9 (Cap-Gate Pattern); AC-10 (i18n-Wrapper-Pattern).
- Vorgaenger Slice 12: `slices/slice-12-test-connection-ajax.md` -> Mount-Point-Pattern (Z. 241), Inline-Script-Constraint (Z. 268), Cap+Nonce-Convention (Constraint Z. 261, AC-2/3), Response-Format (Constraint Z. 264).
- Vorgaenger Slice 30: `slices/slice-30-order-webhooks-handler.md` -> AC-2/4/6 (Order.processed/cancelled/Shipment.sent-Reaktionen, die durch die Simulate-Calls ausgeloest werden); Slice 30 Done-Signal nennt Slice 44 als manueller QA-Trigger (Z. 26).
- Folge Slice 41: `slim-slices.md` Z. 605-612 (Webhook-Log-UI zeigt die durch Simulate ausgeloesten Webhook-Logs — Verifikation per Auge in QA).
