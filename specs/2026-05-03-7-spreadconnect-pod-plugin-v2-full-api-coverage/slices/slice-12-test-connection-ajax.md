# Slice 12: AJAX-Action `spreadconnect_test_connection`

> **Slice 12 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-12-test-connection-ajax` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-10-endpoint-methods", "slice-11-settings-form"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `current_user_can`, `check_ajax_referer`, `wp_send_json_success`, `wp_send_json_error`, `wp_unslash`, `sanitize_text_field`, `__`; Test-Subclass / Mockery-Stub fuer `SpreadconnectClient::authenticate`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check via WP-Admin: Settings-Page -> "Test This Key"-Button -> erwartete Inline-Status-Antworten gemaess State-Variations) |
| **Health Endpoint** | `n/a` (kein Runtime-Service; admin-ajax.php-Action) |
| **Mocking Strategy** | `mock_external` (kein realer SC-API-Call, kein realer `wp_remote_*` — `SpreadconnectClient::authenticate` wird gestubbt; Cap+Nonce ueber Brain\Monkey) |

---

## Ziel

Liefert den Admin-AJAX-Handler `spreadconnect_test_connection`, der ohne Speichern den im Formular eingegebenen API-Key gegen `GET /authentication` validiert und ein normalisiertes `{ok, message}`-JSON zurueckliefert. Verdrahtet das Settings-UI (Slice 11) mit dem in Slice 10 verfuegbaren `SpreadconnectClient::authenticate()` und befriedigt die UX-Forderung "Test This Key" (Wireframes ②) sowie die Hub-Header-Refresh-Path (Architecture Open-Q13). Slice 18 (Subscription-Auto-Register nach Save) baut auf demselben Verifikationspfad auf.

---

## Acceptance Criteria

> **Quelle der `{ok, message}`-Semantik:** `architecture.md` -> Z. 141 (`spreadconnect_test_connection` Action-Behavior). UX-Status-Strings ("Valid — click Save to apply" / "Invalid Key — check value or environment") aus `wireframes.md` Screen 7 ② Z. 622 + State-Variation `test_key_invalid` Z. 637.

1) **GIVEN** ein angemeldeter User ohne `manage_woocommerce`-Capability sendet `POST admin-ajax.php?action=spreadconnect_test_connection`
   **WHEN** der Handler `Hub\Ajax\TestConnection::handle()` ausgefuehrt wird
   **THEN** wird die Anfrage mit HTTP 403 (`wp_send_json_error([...], 403)` oder `wp_die` mit Status 403) abgelehnt; `SpreadconnectClient::authenticate()` wird **niemals** aufgerufen; keine Option wird gelesen oder geschrieben.

2) **GIVEN** ein User mit `manage_woocommerce` und ungueltiger oder fehlender Nonce (`_ajax_nonce` / `_wpnonce` POST-Param fehlt oder `wp_verify_nonce` failed)
   **WHEN** der Handler ausgefuehrt wird
   **THEN** prueft `check_ajax_referer('spreadconnect_test_connection', '_ajax_nonce', false)` und der Handler antwortet mit HTTP 403; `authenticate()` wird **niemals** aufgerufen. Cap-Check und Nonce-Check muessen **beide** vor jeder Geschaeftslogik laufen — ihre Reihenfolge ist Implementer-Wahl, beide sind hard-gates.

3) **GIVEN** ein User mit `manage_woocommerce`, valider Nonce und einem nicht-leeren `api_key`-POST-Body-Feld (z. B. `'api_key' => 'sk_test_unsaved_42'`)
   **WHEN** der Handler ausgefuehrt wird
   **THEN** wird ein `SpreadconnectClient` mit dem **POST-Body-Key** als `apiKeyOverride` instanziiert (Slice 07 Constructor `__construct(?string $apiKeyOverride = null)`); der Handler ruft `authenticate()` auf; **das persistierte `spreadconnect_api_key` aus `get_option` wird nicht verwendet** (auch wenn es einen anderen Wert haette). Der Body-Key wird zuvor durch `wp_unslash()` + `sanitize_text_field()` getrieben.

4) **GIVEN** `authenticate()` returned eine valide `AuthOk`-Instanz (Slice 09; `ok=true`-Shape)
   **WHEN** der Handler die Response verarbeitet
   **THEN** sendet er `wp_send_json_success(['ok' => true, 'message' => __('Valid — click Save to apply', 'spreadconnect-pod')])`; HTTP-Status 200; Content-Type `application/json`. Die Message-Source ist die `__()`-Domain `spreadconnect-pod` (Slice 06).

5) **GIVEN** `authenticate()` wirft `SpreadconnectClientError` (Slice 07; permanent / 4xx, z. B. 401 `auth_failed`)
   **WHEN** der Handler die Exception faengt
   **THEN** sendet er `wp_send_json_error(['ok' => false, 'message' => __('Invalid Key — check value or environment', 'spreadconnect-pod')])` mit HTTP-Status 200 (NICHT 4xx; das AJAX-Result selbst ist erfolgreich, der Test-Outcome ist `ok=false`). Die Exception-Message des Clients wird **nicht** roh in `message` durchgereicht (kein API-Fehlerdetail-Leak Richtung Browser).

6) **GIVEN** `authenticate()` wirft `SpreadconnectTransientError` (Slice 08; 5xx / 429 / Network)
   **WHEN** der Handler die Exception faengt
   **THEN** sendet er `wp_send_json_error(['ok' => false, 'message' => __('Connection failed — please try again', 'spreadconnect-pod')])`. **Kein** Inner-Retry-Schleife im Handler — Slice 08 hat bereits den 1x-429-Retry; weiteres Retry waere User-blocking.

7) **GIVEN** ein POST-Body mit leerem oder fehlendem `api_key`-Feld
   **WHEN** der Handler nach Cap+Nonce-Check den Key extrahiert
   **THEN** antwortet er sofort mit `wp_send_json_error(['ok' => false, 'message' => __('API Key required', 'spreadconnect-pod')])`; `authenticate()` wird **niemals** aufgerufen (kein leerer-Key-Roundtrip zur SC-API).

8) **GIVEN** der Settings-View (Slice 11) wird mit `manage_woocommerce` gerendert
   **WHEN** Section ① "API Connection" markup ausgegeben wird
   **THEN** existiert Section ② mit (a) einem Button mit eindeutigem Selector (z. B. `id="spreadconnect-test-connection"` oder `data-action="test-connection"`), (b) einem Inline-Status-Container (z. B. `id="spreadconnect-test-status"`) zur Anzeige der Erfolgs-/Fehler-Message, (c) einem im Markup eingebetteten Nonce-Token (`wp_create_nonce('spreadconnect_test_connection')`) und der `ajaxurl`-URL — entweder via lokalisiertes Script (`wp_localize_script`) oder als `data-*`-Attribute. **Kein** API-Key-Wert wird im Markup geleakt.

9) **GIVEN** der Settings-View laed sein JS-Asset
   **WHEN** der "Test This Key"-Button geklickt wird
   **THEN** sendet das JS einen `fetch`-/`jQuery.post`-Request an `ajaxurl` mit `action=spreadconnect_test_connection`, `_ajax_nonce=<token>`, `api_key=<aktueller-Wert-des-API-Key-Inputs>` (NICHT der gespeicherte Wert!). Waehrend des Requests ist der Button mit `disabled` deaktiviert (`save_in_progress`-aequivalente UX). Bei Response wird `data.message` in den Status-Container geschrieben; bei `ok=true` mit Erfolgs-CSS-Klasse, bei `ok=false` mit Error-CSS-Klasse (passend zu State-Variations `test_key_invalid` aus Wireframes Z. 637). `data.message` wird durch `textContent` (oder `.text()`) gesetzt — **niemals** `innerHTML`/`html()` (XSS-Schutz, da Server-Message uebersetzbar ist).

10) **GIVEN** der Action-Hook wird beim Plugin-Bootstrap registriert
    **WHEN** `Hub\Ajax\TestConnection::register()` aufgerufen wird (z. B. von `Bootstrap\Plugin::init()` oder `Hub\Controller`)
    **THEN** registriert sie genau **eine** `add_action('wp_ajax_spreadconnect_test_connection', [self::class, 'handle'])` — **kein** `wp_ajax_nopriv_*` (kein Public-Access; Test-Connection ist admin-only).

11) **GIVEN** der Handler hat einen API-Key entgegengenommen
    **WHEN** er ihn verarbeitet (sanitize + Client-Konstruktion + Logging-Pfad)
    **THEN** wird der Key-Wert **niemals** ge-`error_log`'t und **niemals** in der JSON-Response zurueckgegeben (weder als Echo, noch als Substring der `message`). Nur die `WcLoggerAdapter`-Source `spreadconnect-api-client` (Slice 07) erhaelt den Bearer-Header — und der ist dort bereits via Slice-07-Redaction maskiert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `current_user_can`, `check_ajax_referer`, `wp_send_json_success`, `wp_send_json_error`, `wp_die`, `wp_unslash`, `sanitize_text_field`, `__`, `wp_create_nonce`. Der `SpreadconnectClient` wird via Mockery-Mock oder Test-Subclass (Override `authenticate(): AuthOk` oder Throw) ge-stubbt. `wp_send_json_*` wird so konfiguriert, dass sie ein erwartetes Payload in einer Test-Property capturen (statt `exit`-Loops). KEIN realer HTTP-Roundtrip noetig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-12-test-connection-ajax.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class TestConnectionAjaxTest extends TestCase
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

    // AC-1: Capability-Check — manage_woocommerce fehlt -> 403, kein authenticate-Call
    public function test_handle_rejects_without_manage_woocommerce_capability(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Nonce-Check — invalid Nonce -> 403, kein authenticate-Call
    public function test_handle_rejects_with_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Cap+Nonce als hard-gates — kein authenticate selbst bei valider Nonce ohne Cap
    public function test_handle_runs_both_cap_and_nonce_checks_before_business_logic(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Handler nutzt POST-Body-Key, nicht persistierte Option
    public function test_handle_uses_post_body_api_key_not_persisted_option(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Sanitize-Pfad — wp_unslash + sanitize_text_field auf POST-Body-Key
    public function test_handle_sanitizes_post_body_api_key(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Erfolgs-Pfad — AuthOk -> wp_send_json_success mit ok=true + Valid-Message
    public function test_handle_returns_success_with_valid_message_on_authenticate_ok(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: 4xx-Pfad — SpreadconnectClientError -> wp_send_json_error mit ok=false + Invalid-Message
    public function test_handle_returns_error_with_invalid_key_message_on_client_error(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Exception-Message des Clients leakt nicht in JSON-Response
    public function test_handle_does_not_leak_client_error_message_into_response(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: 5xx/429/Network — SpreadconnectTransientError -> generische Connection-Failed-Message
    public function test_handle_returns_generic_failure_on_transient_error(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Leerer api_key im Body -> sofort error, kein authenticate-Call
    public function test_handle_rejects_empty_api_key_without_calling_authenticate(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Settings-View rendert Test-Connection-Button + Status-Container + Nonce
    public function test_settings_view_renders_test_connection_button_with_nonce(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Settings-View rendert keinen API-Key-Wert ins Markup
    public function test_settings_view_never_renders_api_key_value(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-10: register() haengt sich an wp_ajax_spreadconnect_test_connection (kein nopriv)
    public function test_register_hooks_only_authenticated_ajax_action(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: API-Key wird nicht ge-error_log't, nicht in Response geechoed
    public function test_handle_never_echoes_or_logs_api_key_value(): void
    {
        $this->markTestIncomplete('AC-11');
    }
}
```
</test_spec>

> **Hinweis JS-AC-9:** AC-9 (JS-Behavior) wird im PHPUnit-Lauf **nicht** unit-getestet (kein JS-Test-Stack im Plugin). Verifikation erfolgt manuell als QA-Schritt im Done-Signal des Slices. Test-Writer markiert AC-9 deshalb nicht in den PHPUnit-Skeletons.

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-07-http-client-base` | `SpreadconnectClient::__construct(?string $apiKeyOverride = null)` | constructor | Override-Param ist Pflicht — Slice-12-AC-3 instanziiert mit POST-Body-Key. Slice-07-AC dokumentiert die Signatur (Z. 204). |
| `slice-07-http-client-base` | `SpreadconnectClientError`, `SpreadconnectTransientError` | exception classes | Beide werden im Handler in **separaten** `catch`-Blocks behandelt (AC-5 / AC-6). |
| `slice-10-endpoint-methods` | `SpreadconnectClient::authenticate(): AuthOk` | public method | Returnwert wird inspiziert; Exception-Pfade werden gefangen. **Kein** Re-Test der `authenticate()`-Internals (Slice-10-AC-1 deckt das). |
| `slice-09-dto-value-objects` | `Api\Dto\AuthOk` | DTO | Nur als Returntyp referenziert (kein Property-Read im Handler — die Existenz der Instanz signalisiert Erfolg). |
| `slice-11-settings-form` | `Hub\View\Settings::render()` (Markup-Output fuer Section ① + ②) | static method | Slice 11 sieht Section ② als Markup-Slot vor (Constraint "Section ② Test-Connection ... als Section-Slots/Markup-Stubs"); Slice 12 fuellt diesen Slot per **Edit** der Settings-Datei mit Button + Nonce + Status-Container + JS-Wiring. |
| `slice-11-settings-form` | i18n-Domain `spreadconnect-pod` aktiv | WP runtime state | Bereits durch Slice 06 garantiert; `__()`-Aufrufe in Handler-Messages und Settings-Markup liefern uebersetzbare Strings. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| AJAX-Action `spreadconnect_test_connection` | WP admin-ajax.php action | Settings-Page JS (slice-internal); Hub-Header-Manual-Refresh (Slice 13 Open-Q13 / Architecture Z. 844) | `POST admin-ajax.php` mit `action=spreadconnect_test_connection&_ajax_nonce=<token>&api_key=<value>` -> JSON `{success: bool, data: {ok: bool, message: string}}` |
| `SpreadconnectPod\Hub\Ajax\TestConnection::register` | static method | `Bootstrap\Plugin::init()` (Hook-Registration); `Hub\Controller` falls Slice 13 die AJAX-Hooks dort sammelt | `public static function register(): void` |
| `SpreadconnectPod\Hub\Ajax\TestConnection::handle` | static method | WP `wp_ajax_*`-Hook; **nicht** direkt aus anderem PHP-Code aufrufen | `public static function handle(): void` (terminiert via `wp_send_json_*`) |
| Settings-View Section-② Markup (Button + Status-Container + Nonce-Output) | rendered HTML in `Hub\View\Settings::render()` | Slice 13 Hub-Header-Refresh (manueller Re-Test); Slice 14 Initial-Save-Success-Panel (re-uses inline-status pattern) | Inline DOM-Selectors stabil (`#spreadconnect-test-connection`, `#spreadconnect-test-status`) — dokumentiert hier als Vertrag, NICHT geaendert in Folge-Slices ohne Migration. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/TestConnection.php` — Klasse `SpreadconnectPod\Hub\Ajax\TestConnection` mit `public static function register(): void` (haengt `wp_ajax_spreadconnect_test_connection` an `handle`) und `public static function handle(): void` (Cap+Nonce-Check, POST-Body-Key-Sanitize, `SpreadconnectClient`-Instanziierung mit `apiKeyOverride`, `authenticate()`-Call, normalisiertes `wp_send_json_success`/`wp_send_json_error` gemaess AC-4/5/6/7).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` — **Edit** (Slice 11 hat den Markup-Slot fuer Section ② vorgesehen): rendert "Test This Key"-Button + Inline-Status-Container + Nonce-Output (`wp_create_nonce('spreadconnect_test_connection')`) + Inline-JS-Handler oder Enqueue eines kleinen Inline-Scripts, das den Button mit `ajaxurl` verdrahtet (AC-8 / AC-9). KEINE Aenderung an der Slice-11-Settings-API-Registration oder am `SettingsValidator`.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-12-test-connection-ajax.php` wird vom Test-Writer-Agent erstellt, nicht hier. Falls das Inline-JS in eine eigene `assets/js/settings-test-connection.js` ausgelagert wird, ist das eine Implementer-Wahl und KEIN zusaetzliches Deliverable (entweder inline-printed im Settings-Markup oder via `wp_enqueue_script` mit `wp_localize_script` fuer `ajaxurl`+`nonce`).
> **Mount-Point:** Die `register()`-Methode muss aus `Bootstrap\Plugin::init()` (Slice 02/03) aufgerufen werden. Slice 12 fuegt diesen Aufruf zur Plugin-Bootstrap-Registry hinzu — falls Slice 13 (`Hub\Controller`) eine zentrale AJAX-Registrierung einfuehrt, kann der Mount-Point dorthin verschoben werden, **muss aber bis zum Abschluss von Slice 12 in `Bootstrap\Plugin` aktiv** sein, damit der Slice in Isolation funktionsfaehig ist.

---

## Constraints

**Scope-Grenzen:**
- KEINE Persistierung des API-Keys aus dem POST-Body — Slice 11 (`SettingsValidator::sanitize`) bleibt der einzige Persistierungspfad. Slice 12 ist read-only gegenueber `wp_options`.
- KEINE Schreibung in den `sc_health`-Transient (60s-TTL Connection-Cache laut Architecture Z. 844) — Slice 13 (Hub-Header) entscheidet, ob ein erfolgreicher Test-Connection-Roundtrip diesen Transient updatet. Slice 12 liefert nur die Action; das Transient-Wiring kommt in Slice 13.
- KEINE Subscription-Auto-Register nach Test-Erfolg — Slice 18 hookt sich an die Settings-Save-Action (nicht an Test-Connection).
- KEIN UI-Status-Feedback fuer "save_success_panel" / "auth_failed (post-save)" — diese gehoeren zum Save-Flow (Slice 11 + Slice 14), nicht zum Test-Flow.
- KEINE Logging-Spec — Test-Connection darf logged werden (`spreadconnect-api-client` Source via `request()`-internes Logging aus Slice 07), aber Slice 12 fuegt KEINE eigenen `WC_Logger`-Calls hinzu.
- KEIN Localization-File-Update (`de_DE.po`) — die `__()`-Strings landen in Slice 46.
- KEIN Rate-Limit auf den AJAX-Endpoint — Architecture Z. 515 dokumentiert: "1 call per click; frontend disables button while loading; no server-side rate-limit".

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);`.
- `Hub\Ajax\TestConnection` als `final class` mit `static`-Methoden (statless Front-Controller-Pattern fuer admin-ajax-Handler).
- Nonce-Action-Name: **exakt** `spreadconnect_test_connection` (`wp_create_nonce` und `check_ajax_referer` muessen denselben String nutzen — Tests verifizieren das).
- Nonce-Field-Name: `_ajax_nonce` (WP-Standard fuer admin-ajax). `_wpnonce` ist akzeptable Alternative; Implementer entscheidet, aber Server- und Client-Code muessen denselben Field-Namen nutzen.
- Capability-Check: `current_user_can('manage_woocommerce')` (konsistent mit Slice 11 AC-9, Slice 13 `Hub\Controller::ensureCapability`). KEIN `manage_options` (zu permissiv).
- Falls Slice 13 vor diesem Slice gemerged wird: nutze `Hub\Controller::ensureCapability()` als Helper. Falls Slice 12 vor Slice 13 gemerged wird: implementiere den Cap-Check inline und refactore in Slice 13.
- Sanitize-Pfad: `sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) )`. KEIN `esc_html` (zu aggressiv — der Key ist ein opaker Token).
- Response-Format: **immer** `wp_send_json_success(['ok' => bool, 'message' => string])` oder `wp_send_json_error(['ok' => bool, 'message' => string])` — niemals nackte `echo`/`die`. WP setzt Content-Type-Header und beendet die Response korrekt.
- Exception-Handling: separate `catch (SpreadconnectClientError $e)` und `catch (SpreadconnectTransientError $e)` — keine generische `catch (\Throwable $t)`-Falle (waere Logger-Mute).
- Client-Instanziierung: `new \SpreadconnectPod\Api\SpreadconnectClient($postBodyKey)`. KEIN Caching/Singleton — pro Test-Click eine frische Instanz mit unsaved Key (per Definition).
- KEIN `try/finally`-State-Reset — der Handler ist stateless.
- Markup-Output in Settings.php: Button als `<button type="button">` (NICHT `<input type="submit">` — sonst submitted das Form). Inline-Script via `wp_print_inline_script_tag` oder `wp_add_inline_script` (NICHT inline-`<script>` mit hartcodierten Werten — nutze `wp_localize_script` oder `data-*`-Attribute).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Import + instanziieren mit `apiKeyOverride`-Constructor-Param.** NICHT erweitern — alle benoetigten Methoden (`authenticate`) existieren bereits in Slice 10. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Catch-Klausel im Handler. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07/08) | **Wiederverwendet, unveraendert.** Separate Catch-Klausel im Handler. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) | **Edit** in dieser Slice, nicht ersetzen. Slice 11 sieht den Section-②-Markup-Slot explizit vor; Slice 12 fuellt ihn (Button + Status-Container + Nonce + JS-Wiring). KEINE Aenderungen an Settings-API-Registration, Sanitize-Logik oder anderen Sections. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) | **Edit** noetig: `TestConnection::register()` aus `init()` aufrufen, damit der `wp_ajax_*`-Hook registriert wird. Falls Slice 13 als Folge-Slice eine zentrale AJAX-Registry einfuehrt, kann der Aufruf nach Slice 13 dorthin migriert werden — bis dahin ist der Bootstrap der Mount-Point. *(Hinweis: dieser Edit ist im Deliverable nicht eigenstaendig gelistet, da er ein triviales Hook-Wiring ist; falls der Compliance-Gate ihn als separates Deliverable verlangt, hier nachfuegen.)* |

**Referenzen:**
- Architecture: `architecture.md` -> Section "Internal Admin AJAX" Z. 141 (autoritative Behavior-Definition fuer `spreadconnect_test_connection`); Z. 515 (No-Rate-Limit-Vorgabe); Z. 740 (Threat-Modell: HTTPS + Cap+Nonce + No-Logging fuer den unsaved Key); Z. 844 (Open-Q13: Hub-Header refresh-Pfad nutzt diese Action).
- Wireframes: `wireframes.md` -> Screen 7 Section ② Z. 569-571, Annotations Z. 622, State-Variations `test_key_invalid` Z. 637 + `auth_failed` Z. 638 (UX-Status-Strings + DOM-Layout-Vorlage).
- Discovery: `discovery.md` -> Z. 328 (Action [Test Connection]); Z. 469 (`connected`-State im Hub-Header); Z. 806 (`GET /authentication` Use-Case); Z. 902 (Test-Connection-Roundtrip Integration-Testfall).
- Slim-Slices: `slices/slim-slices.md` -> Slice-12-Eintrag (Z. 270-277): Done-Signal "ungueltige Nonce -> 403; gueltige Nonce -> Client wird mit POST-Body-Key aufgerufen; Response-Mapping ok=true/false".
- Vorgaenger Slice 07: `slices/slice-07-http-client-base.md` -> AC-Constructor-Override (Z. 204), `request()`-Signatur, `SpreadconnectClientError`/`TransientError`-Klassen.
- Vorgaenger Slice 10: `slices/slice-10-endpoint-methods.md` -> AC-1 (`authenticate(): AuthOk` -> GET /authentication -> AuthOk), AC-12 (Exception-Pass-Through bleibt unveraendert in Slice 12).
- Vorgaenger Slice 11: `slices/slice-11-settings-form.md` -> Constraint "Section ② Test-Connection ... als Section-Slots/Markup-Stubs vorgesehen aber NICHT mit echten Inhalten gefuellt (Slice 12/14/45 fuellen sie)" — Slice 12 erfuellt diese Markup-Anforderung.
- Folge-Slice 13: `slices/slice-13-hub-page-skeleton.md` -> `Hub\Controller::ensureCapability` als gemeinsamer Helper (Z. 236) — Slice 12 nutzt ihn falls Slice 13 zuerst gemerged ist; sonst inline.
- Folge-Slice 18: `slices/slim-slices.md` Z. 339-346 — `slice-18-subscription-manager` haengt sich an Settings-Save (NICHT an Test-Connection); Slice 12 ist NUR ad-hoc Verifikation.
