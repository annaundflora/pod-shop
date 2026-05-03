# Slice 15: Webhook-Receiver-Route + HMAC-Verifier

> **Slice 15 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-15-webhook-route` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-14-webhook-secret-manager"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `register_rest_route`/`add_action`/`get_option`/`__()`/`is_wp_error`/`current_time`/`error_log`; Patchwork-Replace fuer `hash_equals` zur Verifikation der constant-time-compare-Nutzung) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `curl -X POST https://shop/wp-json/spreadconnect/v1/webhook` mit gueltigem `X-SPRD-SIGNATURE` -> `200/202`; ohne Header oder mit gefakter Signatur -> `401`) |
| **Health Endpoint** | `n/a` (REST-Route selbst ist die Probe) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP REST-Funktionen; in-memory `WP_REST_Request`/`WP_REST_Response`-Stubs in Bootstrap; Patchwork fuer `hash_equals` und `error_log`) |

---

## Ziel

Liefert den Webhook-Empfang als REST-Route `POST /wp-json/spreadconnect/v1/webhook` mit HMAC-SHA256-Verifikation in `permission_callback`. Der `WebhookSignatureVerifier` ist eine pure Domain-Funktion (`hash_equals()` constant-time-compare) — kein WP-Abhaengigkeiten. Bei HMAC-Mismatch -> 401 + WARN-Log mit IP+Header-Snippet (kein Payload). Bei valider Signatur -> Stub-200-Response (Log-Insert + 202-ACK kommt in Slice 16).

---

## Acceptance Criteria

1) **GIVEN** `WebhookController::register()` wird auf `rest_api_init`-Hook aufgerufen
   **WHEN** WP den Hook feuert
   **THEN** ruft der Controller `register_rest_route('spreadconnect/v1', '/webhook', $args)` mit `methods => 'POST'`, `callback => [WebhookController::class, 'handle']`, `permission_callback => [WebhookController::class, 'authorize']` (Architecture-Referenz: `architecture.md` -> Inbound REST Routes Z. 127-131). Die Route ist `public` (kein `manage_woocommerce`-Gate — SC ist anonymer Caller, HMAC ist die Auth).

2) **GIVEN** ein eingehender Request mit Header `X-SPRD-SIGNATURE` = base64(HMAC-SHA256(rawBody, secret)) und Option `spreadconnect_webhook_secret` enthaelt denselben secret
   **WHEN** `WebhookController::authorize($request)` laeuft
   **THEN** liest der Controller via `$request->get_body()` den raw body (NICHT `get_params()` oder `get_json_params()`), ruft `WebhookSignatureVerifier::verify($rawBody, $headerValue, $secret)` und gibt `true` zurueck. Der raw body wird nicht modifiziert, nicht JSON-dekodiert und nicht im Logger emittiert.

3) **GIVEN** ein eingehender Request ohne `X-SPRD-SIGNATURE`-Header ODER mit leerem Header-Wert ODER mit nicht-base64-decodierbarem Wert
   **WHEN** `WebhookController::authorize($request)` laeuft
   **THEN** gibt der Controller `WP_Error` mit Code `'spreadconnect_webhook_unauthorized'` und HTTP-Status `401` zurueck. `WebhookSignatureVerifier::verify()` wird mit Default-Verhalten `false` aufgerufen oder gar nicht erst (Header-Pre-Check). Architecture-Referenz: `architecture.md` -> Validation Rules Z. 466.

4) **GIVEN** ein eingehender Request mit `X-SPRD-SIGNATURE`-Header, aber dem Signatur-Bytes mismatchen den HMAC ueber den raw body
   **WHEN** `WebhookSignatureVerifier::verify($rawBody, $providedSignatureBase64, $secret)` laeuft
   **THEN** dekodiert die Methode `$providedSignatureBase64` via `base64_decode(..., true)` (strict-Modus), berechnet `hash_hmac('sha256', $rawBody, $secret, true)` (raw binary), vergleicht beide ausschliesslich via `hash_equals(...)` (constant-time) und liefert `false`. Es gibt keinen frueh-exit-`return` der Length-Mismatch via `strlen()` ausserhalb von `hash_equals` umgeht (Architecture-Referenz: `architecture.md` -> Security Z. 483; Slim-Slice Done-Signal "Constant-time-compare verifiziert").

5) **GIVEN** `WebhookSignatureVerifier::verify` mit gueltiger Signatur
   **WHEN** der HMAC-Compare laeuft
   **THEN** liefert die Methode `true`. Im Test wird per Patchwork-Replace nachgewiesen, dass `hash_equals(...)` mit den zwei berechneten Strings genau einmal aufgerufen wurde — direkter `===`/`==`-Vergleich ist verboten und scheitert den Test.

6) **GIVEN** `WebhookSignatureVerifier::verify` wird mit leerem `$secret` (Option ist `""`, Plugin nicht initial gesetuped) aufgerufen
   **WHEN** die Methode laeuft
   **THEN** liefert sie sofort `false` ohne `hash_hmac`-Aufruf und ohne `hash_equals`-Aufruf (Defense-in-depth: ein leeres Secret darf nie als gueltig akzeptiert werden, selbst wenn der Caller leeres Secret + leere Signatur uebergibt).

7) **GIVEN** `permission_callback` lieferte `WP_Error` (HMAC-Mismatch oder fehlender Header)
   **WHEN** WP die Antwort bildet
   **THEN** schreibt der Controller in einer Companion-Methode (z. B. `WebhookController::logRejected($request)`) einen WARN-Log via `error_log(...)`-Stub (oder `WcLoggerAdapter::warn` falls Slice 42 vorhanden — hier Stub) mit Source-Marker `'spreadconnect-webhook-receiver'` und Context-Daten `{ip: $request->get_header('X-Forwarded-For') || REMOTE_ADDR, headers: array_keys($request->get_headers()), reason: 'invalid_hmac'|'missing_header'}`. Der Log enthaelt **keinen** raw body, **keine** Signatur-Bytes, **kein** Secret. Architecture-Referenz: `architecture.md` -> Data Protection Z. 493 + Failure Mode Z. 609.

8) **GIVEN** `permission_callback` lieferte `true` (gueltige HMAC)
   **WHEN** `WebhookController::handle($request)` laeuft
   **THEN** liefert der Handler in dieser Slice einen Stub-`WP_REST_Response` mit Status `200` und Body `null` (kein literal `[accepted]`-Body, kein DB-Insert, kein AS-Schedule — das sind Slice-16-Deliverables). Diese Slice etabliert ausschliesslich Auth-Layer + Route-Wiring. Slice 16 editiert `WebhookController::handle` zur vollstaendigen Empfangslogik.

9) **GIVEN** `Bootstrap\Plugin::init()` wird auf `plugins_loaded` gefeuert
   **WHEN** der Plugin-Bootstrap laeuft
   **THEN** registriert er `add_action('rest_api_init', [WebhookController::class, 'register'])` genau einmal. Doppelte Bootstrap-Calls (z. B. via Re-Activate) registrieren die Route nicht doppelt — `register_rest_route` ist seinerseits idempotent, aber der Hook wird nur ein Mal angemeldet (Mount-Point-Edit).

10) **GIVEN** der Test verwendet Patchwork-Replace fuer `hash_equals`
    **WHEN** zwei verschiedene Test-Cases (gueltig vs. ungueltig) laufen
    **THEN** ist die Test-Instrumentierung ausreichend, um die Constant-Time-Eigenschaft der Implementierung zu beweisen: `hash_equals` wird mit beiden Operanden gleicher Laenge aufgerufen (Architecture-Constraint: HMAC-SHA256 raw binary = 32 bytes; provided signature wird via `base64_decode` ebenfalls auf 32 bytes normalisiert vor dem Compare).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey fuer `register_rest_route`, `add_action`, `get_option('spreadconnect_webhook_secret', '')`, `error_log`, `is_wp_error`, `__`. Patchwork-Replace fuer `hash_equals` (in `patchwork.json` bereits gelistet — siehe codebase-scan) zur Verifikation der constant-time-compare-Nutzung. Stubs fuer `WP_REST_Request`/`WP_REST_Response`/`WP_Error` im Bootstrap (existiert aus Slice-02-bootstrap). `WebhookSecretManager::peek()` (Slice 14) wird als Test-Doppel mit `Mockery::mock` oder via `update_option`-Stub adressiert.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-15-webhook-route.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class WebhookRouteTest extends TestCase
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

    // AC-1: register() registriert die REST-Route mit POST-Method + permission_callback
    public function test_register_route_wires_post_with_permission_callback(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: authorize() liest raw body via $request->get_body() (NICHT get_json_params)
    public function test_authorize_reads_raw_body_not_parsed_json(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Fehlender X-SPRD-SIGNATURE-Header -> WP_Error 401
    public function test_authorize_rejects_request_without_signature_header(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Leerer / nicht-base64-decodierbarer Header -> WP_Error 401
    public function test_authorize_rejects_invalid_base64_signature(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Verifier nutzt hash_equals (constant-time) — direkter ===/== ist verboten
    public function test_verifier_uses_hash_equals_not_loose_compare(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: HMAC-Mismatch -> verify() returns false
    public function test_verifier_returns_false_on_hmac_mismatch(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Gueltige Signatur -> verify() returns true; hash_equals genau 1x aufgerufen
    public function test_verifier_returns_true_on_valid_signature(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Leeres Secret -> verify() returns false sofort, kein hash_hmac/hash_equals-Aufruf
    public function test_verifier_returns_false_on_empty_secret(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: HMAC-Mismatch loggt IP+Header-Keys (KEIN Payload, KEINE Signatur, KEIN Secret)
    public function test_rejected_request_logs_ip_and_header_keys_without_payload(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: handle() bei valider HMAC liefert Stub-200-Response (kein DB-Insert, kein AS-Schedule)
    public function test_handle_returns_stub_200_response_without_side_effects(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Bootstrap registriert rest_api_init-Hook genau einmal
    public function test_bootstrap_registers_rest_api_init_hook(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Patchwork-Replace fuer hash_equals beweist Constant-Time-Compare-Nutzung
    public function test_hash_equals_invoked_with_equal_length_operands(): void
    {
        $this->markTestIncomplete('AC-10');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-14-webhook-secret-manager` | `Subscription\WebhookSecretManager::peek(): string` | static method | Liefert das persistierte Webhook-Secret als Plaintext fuer den HMAC-Compare. Ist die einzige UI-erlaubte Read-Method (siehe Slice 14 AC-4). Bei leerem Secret -> verify() liefert `false` (siehe AC-6). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init` | static method | Mount-Point: `register_rest_route`-Wiring via `add_action('rest_api_init', ...)`. |
| `slice-05-options-defaults` | Option `spreadconnect_webhook_secret` (default `""`) | WP option | Read-only via `WebhookSecretManager::peek()` — diese Slice nutzt `peek()`, nicht direkt `get_option`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Webhook\WebhookController::register` | static method | `Bootstrap\Plugin` (`rest_api_init`-Hook) | `public static function register(): void` |
| `SpreadconnectPod\Webhook\WebhookController::authorize` | static method | WP REST API (via `permission_callback`) | `public static function authorize(WP_REST_Request $request): bool|WP_Error` |
| `SpreadconnectPod\Webhook\WebhookController::handle` | static method | WP REST API (via `callback`); editiert von Slice 16 | `public static function handle(WP_REST_Request $request): WP_REST_Response` |
| `SpreadconnectPod\Webhook\WebhookSignatureVerifier::verify` | static method | Slice 15 `WebhookController::authorize`; testbar isoliert | `public static function verify(string $rawBody, string $providedSignatureBase64, string $secret): bool` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookController.php` — Klasse `SpreadconnectPod\Webhook\WebhookController` mit `register(): void`, `authorize(WP_REST_Request): bool|WP_Error`, `handle(WP_REST_Request): WP_REST_Response` (Stub-200), `logRejected(WP_REST_Request, string $reason): void` (private/protected helper).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookSignatureVerifier.php` — Klasse `SpreadconnectPod\Webhook\WebhookSignatureVerifier` mit `verify(string, string, string): bool`. Pure Domain-Funktion, keine WP-Abhaengigkeiten ausser `hash_hmac`/`hash_equals`/`base64_decode`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Mount-Point: `add_action('rest_api_init', [WebhookController::class, 'register'])` in `Plugin::init()`. Bestehende Hook-Registrierungen aus Slice 02-14 bleiben unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-15-webhook-route.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `Webhook\WebhookSecretManager` (Slice 14) — diese Slice konsumiert `peek()` ohne Modifikation.

---

## Constraints

**Scope-Grenzen:**
- **Kein** `Webhook\EventIdHasher` — Slice 16.
- **Kein** `Webhook\WebhookLogRepo` / kein DB-Insert in `wp_spreadconnect_webhook_log` — Slice 16.
- **Kein** `as_enqueue_async_action('spreadconnect/process_webhook_event', ...)` — Slice 16.
- **Kein** literal `[accepted]`-Response-Body und kein 202-Status — Slice 16 (handle() liefert hier Stub-200).
- **Kein** Domain-Dispatch (`OrderEventHandler` / `ArticleEventHandler`) — Slices 25/30.
- **Kein** Subscription-Auto-Register — Slice 18.
- **Kein** Webhook-Log-UI — Slice 41.
- **Kein** Headless-Redirect-Modification — `headless-redirect.php` mu-plugin schliesst `REST_REQUEST` bereits aus (Architecture-Referenz Z. 666 + Risk Z. 744 — QA-Verify-only).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in beiden neuen Dateien.
- `WebhookController` als `final class` mit ausschliesslich `static` Methoden — Stateless-Adapter.
- `WebhookSignatureVerifier` als `final class` mit einer einzigen `static` Methode `verify()` — Pure Function, kein Logger, kein Side-Effect.
- **`hash_equals`-Pflicht**: der Vergleich der zwei HMAC-Bytes-Strings MUSS ueber `hash_equals(...)` laufen. KEIN `===`, kein `strcmp`, kein `==`. Patchwork-Replace verifiziert den Call (siehe `patchwork.json` Z. 9).
- **`base64_decode($_, true)` strict-Modus**: nicht-base64-Eingaben muessen `false` liefern -> 401, nicht eine "soft-decode" erfolgreiche Variante.
- **Secret-Lookup**: ueber `WebhookSecretManager::peek()` aus Slice 14, NICHT direkt `get_option(...)`. Single-Source-of-Truth fuer Secret-Read.
- **Raw body**: ausschliesslich via `$request->get_body()`. `get_json_params()` waere nach JSON-Decode-Round-Trip nicht byte-stabil und wuerde HMAC brechen.
- **Header-Lookup**: case-insensitive via `$request->get_header('x-sprd-signature')` (WP normalisiert intern). Architecture-Referenz: `architecture.md` Z. 83 + 599.
- **Log-Redaction**: `logRejected()` schreibt NUR `ip` (aus `X-Forwarded-For` falls vorhanden, sonst `$_SERVER['REMOTE_ADDR']`), `array_keys($request->get_headers())` und einen festen `reason`-Marker. Keine Header-Werte. Kein raw body. Kein Secret.
- **Public REST-Route**: kein `current_user_can`-Gate auf `permission_callback` — SC ist anonymer Caller, HMAC ist die Auth (Architecture-Referenz Z. 514).
- **Rate-Limiting**: keine eigene App-Layer-Begrenzung; HMAC + UNIQUE event_id (Slice 16) sind die Abuse-Barrieren (Architecture-Referenz Z. 514).
- **Stub-Response in `handle()`**: liefert `new WP_REST_Response(null, 200)`. Slice 16 ueberschreibt diese Methode komplett mit Log-Insert + 202 + `[accepted]`-Literal. Diese Slice MUSS handlable so trivial halten, dass Slice-16-Edit konfliktfrei ist.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02-14) | **Edit** — Mount-Point: `add_action('rest_api_init', ...)` in `Plugin::init()`. Bestehende Hook-Registrierungen bleiben unveraendert. |
| `SpreadconnectPod\Subscription\WebhookSecretManager::peek` (Slice 14) | **Import**, NICHT modifizieren — wird von `WebhookController::authorize()` als Secret-Source aufgerufen. |
| `wordpress/plugins/spreadconnect-pod/patchwork.json` | **Reuse** — `hash_equals` ist bereits als redefinable-internal gelistet (Z. 9). Test-Writer-Agent kann ohne Patchwork-Edit den Constant-Time-Compare-Nachweis fuehren. |
| `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php` (Slice 02) | **Reuse** — `WP_REST_Request`/`WP_REST_Response`/`WP_Error`-Stubs sollten dort liegen oder Test-Writer ergaenzt sie idempotent. Diese Slice modifiziert das Bootstrap nicht. |
| `headless-redirect.php` mu-plugin (existing) | **No-touch** — schliesst `REST_REQUEST` bereits aus. QA-Verify-only via `curl /wp-json/spreadconnect/v1/webhook` -> kein 301 (Architecture-Referenz Z. 666). |
| Architecture-Tabelle `architecture.md` -> Service Map `Webhook\WebhookController` (Z. 377) + `Webhook\WebhookSignatureVerifier` (Z. 378) + Inbound REST Z. 127-131 + Flow E (Z. 432-450) | **Single Source of Truth** fuer Klassen-FQCN, Route-Path, Auth-Verhalten. |

**Referenzen:**
- Architecture: `architecture.md` -> Inbound REST Routes (Z. 127-131); Flow E Webhook Receive (Z. 432-450); Service Map Webhook-Layer (Z. 377-378); Validation Rules `X-SPRD-SIGNATURE` (Z. 466); Security Inbound webhook (Z. 483); Data Protection Webhook payload (Z. 493); Failure Mode HMAC mismatch (Z. 609); Inbound webhook abuse barrier (Z. 514); Patchwork-Internals (Z. 810).
- Discovery: `discovery.md` -> Slice 3 "Webhook Receiver + Subscriptions" (Z. 924); HMAC-Pattern (Z. 552-553); Webhook-Endpoint-Spezifikation (Z. 597-600); Failure Mode E.2 HMAC-Mismatch (Z. 224).
- Slim-Slices: `slices/slim-slices.md` -> Slice-15-Eintrag (Z. 305-313): valide HMAC -> 200/202; ungueltig -> 401 + Log mit IP+Headers (kein Payload). Constant-time-compare verifiziert.
- Vorgaenger: `slices/slice-14-webhook-secret-manager.md` -> Provides-To `WebhookSecretManager::peek` (Slice-14-Integration-Contract) als Secret-Source.
- Folge: `slices/slim-slices.md` Slice-16 (`EventIdHasher` + `WebhookLogRepo` + 202-ACK editiert `WebhookController::handle`); Slice-17 (`ProcessWebhookEventJob`-Dispatcher); Slice-18 (`SubscriptionManager` registriert die Subscriptions auf diese Webhook-URL).
