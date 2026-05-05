# Slice 07: HTTP-Client Basis (Bearer-Auth + Base-URL-Toggle)

> **Slice 7 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-07-http-client-base` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-05-options-defaults"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Aliases fuer `wp_remote_request`/`wp_remote_retrieve_*`/`get_option`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `wp eval "..."` ruft `request('GET','/authentication')` und prueft Response-Shape) |
| **Health Endpoint** | `n/a` (kein Outbound-Call ausserhalb Tests; `GET /authentication` ist Slice-12-Job) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey aliased `wp_remote_*`-Funktionen liefern fixe Responses; `get_option` Map liefert API-Key + Staging-Toggle; `wc_get_logger`-Stub captured Log-Calls) |

---

## Ziel

Liefert die **HTTP-Transport-Basis** fuer alle 27+ Spreadconnect-Endpoint-Wrapper (Slice 10): zentralen `request($method, $path, $body)`-Kern mit Bearer-Auth aus `spreadconnect_api_key`, Base-URL-Auswahl (Prod/Staging) ueber `spreadconnect_use_staging`, JSON-Body-Encoding, Logging-Hook ohne Token-Leak und definierte Exception-Typen fuer permanente vs. transiente Fehler. **Bewusst noch ohne** Rate-Limit-/Retry-Logik (Slice 08) und ohne typed Endpoint-Methoden (Slice 10).

---

## Acceptance Criteria

1) **GIVEN** `spreadconnect_api_key = 'sk-test-123'` und `spreadconnect_use_staging = false`
   **WHEN** `SpreadconnectClient::request('GET', '/authentication', null)` aufgerufen wird
   **THEN** wird `wp_remote_request` mit URL `https://rest.spreadconnect.com/authentication`, Method `GET`, Header `Authorization: Bearer sk-test-123`, Header `Accept: application/json` und ohne Body aufgerufen (Base-URL aus `architecture.md -> Section "API Client" Z. 79-80`).

2) **GIVEN** `spreadconnect_use_staging = true` und derselbe API-Key
   **WHEN** `request('GET', '/authentication', null)` erneut aufgerufen wird
   **THEN** ist die uebergebene URL `https://staging.spreadconnect.com/authentication` — Path-Konkatenation strippt fuehrenden/abschliessenden Slash idempotent (Aufruf mit `'/authentication'` und `'authentication'` ergibt identische URL).

3) **GIVEN** ein leerer API-Key (`spreadconnect_api_key = ''`)
   **WHEN** `request(...)` aufgerufen wird
   **THEN** wirft die Methode `SpreadconnectClientError` (permanent, kein API-Call) mit Code `auth_missing` — **kein** `wp_remote_request`-Aufruf wird ausgefuehrt (Pre-Flight-Guard).

4) **GIVEN** ein POST-Aufruf `request('POST', '/orders', ['externalOrderReference' => 'WC-42', 'orderItems' => []])`
   **WHEN** der Request erzeugt wird
   **THEN** enthaelt `wp_remote_request`-Args: `method='POST'`, `headers['Content-Type']='application/json'`, `body=json_encode($payload)` (UTF-8-safe, ohne `JSON_PRETTY_PRINT`); GET-Aufrufe erhalten **keinen** `body`-Key und **keinen** `Content-Type`-Header.

5) **GIVEN** `wp_remote_request` liefert Response mit Status `200` und Body `{"ok":true}`
   **WHEN** `request(...)` zurueckkehrt
   **THEN** ist der Rueckgabewert ein Array `['status' => 200, 'body' => ['ok' => true], 'headers' => array<string,string>]` — JSON-Body ist als assoziatives Array dekodiert; Rohformat-Headers via `wp_remote_retrieve_headers()` als Map (Header-Namen lower-case, Values als String).

6) **GIVEN** `wp_remote_request` liefert HTTP-Status `404`
   **WHEN** `request(...)` returned
   **THEN** wirft die Methode `SpreadconnectClientError` (permanent) mit Code `http_4xx`, Message enthaelt Status + Path; AS-Retry wird **nicht** ausgeloest. Status-Klassifizierung folgt `architecture.md -> Section "Error Handling Strategy" Z. 603-608`: 4xx -> Client (permanent), 5xx/Network -> Transient.

7) **GIVEN** `wp_remote_request` liefert HTTP-Status `503`
   **WHEN** `request(...)` returned
   **THEN** wirft die Methode `SpreadconnectTransientError` (retryable) mit Code `http_5xx` — Action Scheduler kann den umschliessenden Job retryen.

8) **GIVEN** `wp_remote_request` liefert ein `WP_Error` (Network-Failure / Timeout)
   **WHEN** `request(...)` returned
   **THEN** wirft die Methode `SpreadconnectTransientError` mit Code `network_error` — der `WP_Error::get_error_message()`-Text ist in der Exception-Message enthalten, **kein** Bearer-Token.

9) **GIVEN** `wc_get_logger()` ist verfuegbar
   **WHEN** `request(...)` einen 2xx-, 4xx- oder 5xx-Pfad durchlaeuft
   **THEN** wird **genau ein** `Logger::log($level, $message, ['source' => 'spreadconnect-api-client'])`-Call abgesetzt mit Level `info` (2xx), `error` (4xx) oder `warning` (5xx/Network). Die geloggte Message enthaelt Method + Path + Status, **niemals** den Authorization-Header oder den raw API-Key (Architecture-Constraint Z. 494: `Authorization: Bearer ***`).

10) **GIVEN** `SpreadconnectClientError` und `SpreadconnectTransientError`
    **WHEN** beide Klassen instanziiert werden
    **THEN** sind beide Subklassen von `\RuntimeException`, exposen `getCode(): string` (string-Code, nicht int), `getStatusCode(): ?int` (HTTP-Status falls verfuegbar) und `getEndpointPath(): ?string`. Slice 08 wird `SpreadconnectTransientError` fuer 429 wiederverwenden.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey aliased `wp_remote_request` mit `Functions\expect()->andReturn(...)`. `get_option`-Returns via Map. Logging-Captures via Mockery-Spy auf `wc_get_logger()`-Stub. Test-Writer implementiert Assertions selbststaendig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-07-http-client-base.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class SpreadconnectClientBaseTest extends TestCase
{
    // AC-1: Prod-Base-URL + Bearer-Header korrekt gesetzt
    public function test_request_uses_prod_base_url_and_bearer_header(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Staging-Toggle wechselt Base-URL
    public function test_staging_toggle_switches_base_url(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Path-Slash-Normalisierung idempotent
    public function test_path_slash_normalization_is_idempotent(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Leerer API-Key -> Pre-Flight-Throw, kein HTTP-Call
    public function test_empty_api_key_throws_client_error_without_http_call(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: POST-Body wird JSON-encoded mit Content-Type
    public function test_post_body_is_json_encoded_with_content_type_header(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: GET-Aufruf hat keinen Body-Key
    public function test_get_request_has_no_body_key(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: 2xx-Response wird zu strukturiertem Array dekodiert
    public function test_2xx_response_is_decoded_to_structured_array(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: 4xx wirft permanent SpreadconnectClientError
    public function test_4xx_status_throws_client_error_permanent(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: 5xx wirft retryable SpreadconnectTransientError
    public function test_5xx_status_throws_transient_error(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: WP_Error -> Transient mit network_error code
    public function test_wp_error_is_mapped_to_transient_error(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Logging mit korrekter Source + ohne Token-Leak
    public function test_logger_writes_with_source_and_redacts_authorization(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: Log-Level passt zu Status-Klasse
    public function test_log_level_matches_response_class(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Exception-API-Shape (getStatusCode, getEndpointPath, string-code)
    public function test_exception_classes_expose_status_and_path_accessors(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: Beide Exceptions extenden RuntimeException
    public function test_exception_classes_extend_runtime_exception(): void
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
| `slice-05-options-defaults` | Option `spreadconnect_api_key` (string, default `''`) | WP Option | `get_option('spreadconnect_api_key', '')` liefert leere Default-String, sodass AC-3 (Pre-Flight-Guard) deterministisch greift. |
| `slice-05-options-defaults` | Option `spreadconnect_use_staging` (bool, default `false`) | WP Option | `get_option('spreadconnect_use_staging', false)` liefert PHP-`bool` (Slice 05 schreibt Type-treu). |
| WordPress core (no slice) | `wp_remote_request`, `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`, `wp_remote_retrieve_headers`, `is_wp_error`, `WP_Error` | Function | Standard WP HTTP-API; in Tests via Brain\Monkey aliased. |
| WooCommerce core (no slice) | `wc_get_logger()` -> `WC_Logger` mit `log($level, $message, $context)` | Function/Object | In Tests via Brain\Monkey-Alias + Mockery-Spy. **Falls** `wc_get_logger` nicht existiert (test bootstrap edge case): Client darf ohne Log weiterlaufen — Logging ist Stub, keine Hard-Dependency. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Api\SpreadconnectClient::request` | public method | `slice-08-rate-limit-retry` (extends), `slice-10-endpoint-methods` (alle 27 Wrapper rufen `request`), `slice-12-test-connection-ajax` (ad-hoc Call mit unsaved Key) | `public function request(string $method, string $path, ?array $body = null): array` — Return-Shape `['status' => int, 'body' => array, 'headers' => array<string,string>]` |
| `SpreadconnectPod\Api\SpreadconnectClient::__construct` | constructor | DI-Caller (Slice 08/10/12) | `public function __construct(?string $apiKeyOverride = null)` — Override fuer Test-Connection mit unsaved Key (AC-3 prueft trotzdem auf empty); bei `null` aus `get_option` lesen. |
| `SpreadconnectPod\Api\SpreadconnectClientError` | exception class | Slice 08 (4xx-Pfad), Slice 10 (Wrapper-Catch), Slice 28-30 (Order-Jobs unterscheiden permanent/transient), Slice 37 (DLQ direkt bei permanent) | `extends \RuntimeException`; `getStatusCode(): ?int`; `getEndpointPath(): ?string` |
| `SpreadconnectPod\Api\SpreadconnectTransientError` | exception class | Slice 08 (429/5xx-Pfad), Slice 10 (Wrapper), Slice 28-30 (re-throw fuer AS-Retry), Slice 37 (DLQ erst nach 3 AS-Retries) | `extends \RuntimeException`; `getStatusCode(): ?int`; `getEndpointPath(): ?string` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` — Klasse `SpreadconnectPod\Api\SpreadconnectClient` mit `request($method, $path, $body)`-Kern, Bearer-Auth aus Option, Base-URL-Toggle, JSON-Body-Encoding, Status-Klassifizierung (2xx return / 4xx throw permanent / 5xx+Network throw transient), Logging-Stub-Calls via `wc_get_logger()` mit Source `spreadconnect-api-client`. **Keine** Retry-Logik, **keine** typed Endpoint-Methoden (Slice 08/10).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` — `final class SpreadconnectClientError extends \RuntimeException` (permanent). Konstruktor akzeptiert `(string $code, string $message, ?int $statusCode = null, ?string $endpointPath = null, ?\Throwable $previous = null)`; exposed `getStatusCode(): ?int`, `getEndpointPath(): ?string`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` — `final class SpreadconnectTransientError extends \RuntimeException` (retryable). Identische Konstruktor-Signatur und Accessors wie `SpreadconnectClientError`. Wird in Slice 08 fuer 429/`Retry-After` wiederverwendet.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-07-http-client-base.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Keine Retry-Logik** — kein 429-Retry-After-Honor, kein `X-RateLimit-Remaining`-Sleep, kein interner Wiederholungsversuch. Slice 08 erweitert die Klasse genau dafuer.
- **Keine typed Endpoint-Methoden** — `authenticate()`, `getArticles()`, `createOrder()` etc. sind Slice 10. Slice 07 liefert nur den generischen `request()`-Kern.
- **Keine DTO-Mapping** — Response wird als assoziatives Array zurueckgegeben; DTO-Konstruktion uebernimmt Slice 09/10.
- **Keine Webhook-Inbound-Logik** — Slice 15-17 (REST-Route + Verifier).
- **Keine Test-Connection-AJAX** — Slice 12 ruft `request()` aus Slice 07 auf, ist aber eigene Slice.
- **Kein Settings-UI** — Slice 11.
- **Keine `error_log`-Nutzung** — alle Log-Writes via `wc_get_logger()` mit Source `spreadconnect-api-client` (Architecture AVOID #6).
- **Keine Persistierung** — Client ist read-only HTTP-Wrapper, schreibt keine DB-Tables (Slice 04 ist parallel, nicht konsumiert).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `SpreadconnectClient` als `class` (nicht `final` — Slice 08 wird via Edit erweitern, nicht extenden; alternativ `final` mit allen Erweiterungs-Hooks als `protected` Methoden — Implementer-Entscheidung).
- HTTP-API: ausschliesslich `wp_remote_request($url, $args)` (vereinheitlicht GET/POST/PUT/DELETE) — **nicht** `wp_remote_get`/`wp_remote_post` getrennt (Architecture Z. 755 nennt `wp_remote_post`/`wp_remote_get`, einheitliches `wp_remote_request` ist semantisch identisch und vereinfacht Mocking).
- Default-Timeout: `15` Sekunden (`$args['timeout']`); explizit setzen, nicht WP-Default-5s nutzen.
- `User-Agent`-Header `'spreadconnect-pod/' . SPREADCONNECT_POD_VERSION` setzen (falls Konstante aus Slice 02 verfuegbar; sonst `'spreadconnect-pod/2.0.0'`).
- Bearer-Token-Read: `get_option('spreadconnect_api_key', '')` **pro Request** (kein In-Memory-Cache, Architecture Z. 482) — Admin-Key-Update wirkt sofort.
- Status-Klassifizierung: `>= 200 && < 300` -> success; `>= 400 && < 500` -> `SpreadconnectClientError`; `>= 500` -> `SpreadconnectTransientError`. `3xx` darf in MVP nicht vorkommen (SC redirected nicht); falls doch -> als Transient behandeln (defensive default).
- JSON-Decode: `json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)` — bei Decode-Failure einer 2xx-Response: `SpreadconnectTransientError` mit Code `invalid_json` (defensiv: SC liefert 2xx mit korruptem Body).
- Header-Map-Keys auf lower-case normalisieren (`wp_remote_retrieve_headers()` liefert Object oder Array je nach WP-Version) — Slice 08 braucht `x-ratelimit-remaining` deterministisch lookbar.
- Logging-Source-String **exakt** `'spreadconnect-api-client'` (Architecture Z. 398). Token-Redaction: in der geloggten Message darf der API-Key nicht erscheinen — entweder ueberhaupt nicht loggen oder als `Bearer ***` (vgl. Architecture Z. 494).
- Exception-Code als **string** (nicht int) — PHP `\Exception::$code` ist int, daher als separate `private string $appCode` Property + `getCode()`-Override-vermeidung; oder dedizierter Getter `getAppCode(): string` (Implementer-Wahl, Test-Skeleton AC-10 prueft Existence des string-Code-Accessors).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (aus Slice 02/05) | **Nicht editiert in Slice 07.** Wiring (`add_action('init', ...)` o.ae.) ist nicht noetig — Client wird on-demand in Slice 08/10/12 instanziiert, nicht als Singleton beim Bootstrap. |
| Architecture Tabelle `architecture.md -> Outbound: Spreadconnect REST Endpoints (27)` (Z. 87-125) | **Nicht in Slice 07 verdrahtet.** Slice 07 stellt nur die Transport-Basis bereit; die 27+4 Wrapper kommen in Slice 10. Client darf **keinen** harten Endpoint-Pfad enthalten. |
| WP-Core: `wp_remote_request` etc. | **Pflicht-Reuse** statt cURL/Guzzle (Architecture Decision Z. 755: native WP-HTTP-API fuer Brain\Monkey-Testbarkeit). |
| WooCommerce: `wc_get_logger()` | **Pflicht-Reuse** statt `error_log` (Architecture AVOID #6, Z. 757). Source-String `spreadconnect-api-client` ist final spezifiziert. |

**Referenzen:**
- Architecture: `architecture.md` -> Section "API Surface" (Z. 75-90, Outbound auth/base-URL/rate-limit-Definition); Section "Service Map" (Z. 364: SpreadconnectClient-Verantwortlichkeit); Section "Error Handling Strategy" (Z. 603-614: Status-Klassifizierung); Section "Operational Concerns" (Z. 482, 494: per-request Token-Read + Redaction); Section "Tech-Stack-Choices" (Z. 755-759: wp_remote_*, wc_get_logger).
- Discovery: `discovery.md` -> Slice 2 "API Client + Authentication" (Z. 802-820 API-Client-Outbound); Z. 86 (v1-Pattern als Referenz, **wird neu implementiert**).
- Slim-Slices: `slices/slim-slices.md` -> Slice-07-Eintrag (Z. 213-221, Done-Signal).
- Slice 05: `slices/slice-05-options-defaults.md` -> AC-1 (Default `spreadconnect_api_key=""` + `spreadconnect_use_staging=false` Type-treu).
- Slice 08 (kommend): erweitert `SpreadconnectClient::request()` um 429-Retry und `X-RateLimit-Remaining`-Awareness — Slice 07 muss die Hooks dafuer offen halten (Status-Klassifizierung als Helper-Methode oder Header-Map-Return).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 07 (kein UI).
