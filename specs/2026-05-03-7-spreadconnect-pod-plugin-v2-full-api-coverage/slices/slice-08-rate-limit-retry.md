# Slice 08: HTTP-Client Rate-Limit + Single-429-Retry

> **Slice 8 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-08-rate-limit-retry` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-07-http-client-base"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Aliases fuer `wp_remote_request`/`wp_remote_retrieve_*`; Sleep-Spy ueber injizierten Sleeper-Hook) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: Staging-Endpoint provoziert 429, Plugin loggt genau einen Retry) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey aliased `wp_remote_request` liefert sequenzierte Status/Header-Tupel; `wc_get_logger`-Stub captured Log-Calls; Sleep-Aufrufe ueber injizierbaren Closure/Stub statt `sleep()` — kein Real-Sleep im Test) |

---

## Ziel

Erweitert den in Slice 07 gebauten `SpreadconnectClient::request()` um zwei Rate-Limit-Aspekte: **proaktives** 1-Sekunden-Sleep wenn `X-RateLimit-Remaining <= 5` (vor dem naechsten Call) und **reaktives** Single-Retry bei HTTP 429 unter Beruecksichtigung des `X-RateLimit-Retry-After-Seconds`-Headers. Genau **ein** Wiederholungsversuch innerhalb des Jobs; alle weiteren Retries delegiert an Action Scheduler via `SpreadconnectTransientError`. Damit wird die Single-Retry-Layer-Architektur (Architecture Z. 644, 772) am HTTP-Client verankert.

---

## Acceptance Criteria

1) **GIVEN** `wp_remote_request` liefert HTTP 429 mit Header `X-RateLimit-Retry-After-Seconds: 2` und Body `{}`
   **WHEN** `SpreadconnectClient::request('GET', '/articles', null)` aufgerufen wird
   **THEN** wird `wp_remote_request` **genau zweimal** aufgerufen (initial + ein Retry); zwischen den Calls findet ein Sleep mit Argument `2` Sekunden statt; der zweite Call liefert 200 und `request()` returned das 2xx-Result-Array gemaess Slice-07-Shape (`['status' => 200, 'body' => array, 'headers' => array<string,string>]`).

2) **GIVEN** beide aufeinanderfolgende Calls liefern HTTP 429 (auch der Retry liefert 429)
   **WHEN** `request(...)` durchlaeuft den Retry-Pfad
   **THEN** wirft die Methode `SpreadconnectTransientError` mit Code `http_429`; `wp_remote_request` wurde **genau zweimal** aufgerufen (kein dritter Versuch); die Exception traegt `getStatusCode() === 429` und `getEndpointPath() === '/articles'` (Architecture Z. 606, 644: kein dritter Inner-Retry).

3) **GIVEN** HTTP 429 ohne `X-RateLimit-Retry-After-Seconds`-Header (oder Wert `0`/non-numerisch)
   **WHEN** der Retry vorbereitet wird
   **THEN** wird ein Default-Sleep von **1** Sekunde angewendet (Fallback), dann genau ein Retry; bei zweitem 429 -> `SpreadconnectTransientError` wie in AC-2.

4) **GIVEN** `X-RateLimit-Retry-After-Seconds: 600` (extremer Wert > erlaubtes Maximum)
   **WHEN** der Retry vorbereitet wird
   **THEN** wird der Sleep auf maximal **30** Sekunden gecapped; `wp_remote_request` wird genau zweimal aufgerufen; `WARN`-Log enthaelt Hinweis auf gecappten Wert (Architecture Z. 606: WARN level).

5) **GIVEN** ein erfolgreicher Call (HTTP 200) liefert Header `X-RateLimit-Remaining: 3`
   **WHEN** **derselbe** Client innerhalb derselben Instanz einen **zweiten** `request(...)`-Call ausfuehrt
   **THEN** wird vor dem zweiten `wp_remote_request`-Aufruf ein Sleep mit Argument `1` Sekunde ausgefuehrt (proaktive Drossel ab `<= 5`, Architecture Z. 81/513). Das **erste** `wp_remote_request` darf **keinen** Pre-Sleep ausgeloest haben (kein Vorwissen).

6) **GIVEN** der vorhergehende Call lieferte Header `X-RateLimit-Remaining: 6` (Schwelle nicht unterschritten)
   **WHEN** ein Folgecall stattfindet
   **THEN** wird **kein** proaktiver Sleep ausgefuehrt; `wp_remote_request` wird ohne vorgeschaltetes `sleep()` aufgerufen (Schwelle ist `<= 5`, nicht `< 5`).

7) **GIVEN** das vorherige Response enthielt **keinen** `X-RateLimit-Remaining`-Header (z.B. lokaler Mock, oder SC liefert Header nicht zurueck)
   **WHEN** ein Folgecall stattfindet
   **THEN** wird **kein** proaktiver Sleep ausgeloest (defensives Default — Abwesenheit des Headers ist nicht gleichbedeutend mit `0`).

8) **GIVEN** `wp_remote_request` liefert HTTP **404** (4xx, kein 429)
   **WHEN** `request(...)` ausgefuehrt wird
   **THEN** wird **kein** Retry ausgeloest (genau **ein** `wp_remote_request`-Call); das Verhalten bleibt identisch zu Slice 07 AC-6: `SpreadconnectClientError` mit Code `http_4xx` (Architecture Z. 607: 4xx -> permanent, kein AS-Retry).

9) **GIVEN** `wp_remote_request` liefert HTTP **503** (5xx)
   **WHEN** `request(...)` ausgefuehrt wird
   **THEN** wird **kein** Inner-Retry ausgeloest (genau **ein** `wp_remote_request`-Call); Verhalten bleibt identisch zu Slice 07 AC-7: `SpreadconnectTransientError` mit Code `http_5xx` (5xx-Retries delegiert an Action Scheduler, Architecture Z. 608/639).

10) **GIVEN** `wp_remote_request` liefert ein `WP_Error` (Network/Timeout) — sowohl beim ersten als auch hypothetisch beim Retry
    **WHEN** `request(...)` ausgefuehrt wird
    **THEN** wird **kein** Inner-Retry ausgeloest (genau **ein** `wp_remote_request`-Call); Verhalten bleibt identisch zu Slice 07 AC-8: `SpreadconnectTransientError` mit Code `network_error`. Inner-Retry ist **ausschliesslich** fuer 429 reserviert (Architecture Z. 644).

11) **GIVEN** der 429-Retry-Pfad wird durchlaufen (egal ob erfolgreich oder erneut 429)
    **WHEN** Logging stattfindet
    **THEN** wird **genau ein** `WARN`-Log mit Source `spreadconnect-api-client` (Slice 07 AC-9) abgesetzt, dessen Message Method + Path + `Retry-After`-Wert enthaelt; **niemals** der Bearer-Token. Beim erfolgreichen Retry (Status 200) folgt zusaetzlich der Standard-`INFO`-Log aus Slice 07 fuer den finalen 2xx (Total: 1 WARN + 1 INFO).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey aliased `wp_remote_request` mit `Functions\expect()->times(N)->andReturnValues([$call1, $call2])` fuer die Sequenz initial->retry. Sleep wird ueber injizierbaren Sleeper-Stub abgegriffen (Constructor-Injektion oder protected Method `sleep_seconds(int)` mit Override-Test-Subclass — Implementer-Wahl). `wc_get_logger()`-Stub via Mockery-Spy zaehlt WARN-Calls. Test-Writer implementiert Assertions selbststaendig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-02-client-retry.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class SpreadconnectClientRetryTest extends TestCase
{
    // AC-1: 429 + Retry-After:2 -> sleep(2) + 1 Retry, der bei 200 success returned
    public function test_429_with_retry_after_triggers_one_retry_and_returns_2xx(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: doppeltes 429 wirft SpreadconnectTransientError, kein dritter Versuch
    public function test_double_429_throws_transient_after_exactly_two_attempts(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: 429 ohne Retry-After-Header -> Default-Sleep 1s
    public function test_429_without_retry_after_uses_default_one_second_sleep(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Retry-After-Sekunden > 30 werden auf 30 gecapped
    public function test_retry_after_above_cap_is_clamped_to_max(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: X-RateLimit-Remaining <= 5 -> proactive 1s sleep vor naechstem Call
    public function test_low_rate_limit_remaining_triggers_proactive_sleep_on_next_call(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: X-RateLimit-Remaining = 6 -> kein proactive sleep
    public function test_rate_limit_remaining_above_threshold_does_not_sleep(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Header fehlt komplett -> kein proactive sleep
    public function test_missing_rate_limit_header_skips_proactive_sleep(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: 404 wirft Client-Error ohne Retry (Slice-07-Regression)
    public function test_4xx_status_throws_client_error_without_retry(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: 503 wirft Transient ohne Inner-Retry
    public function test_5xx_status_throws_transient_without_inner_retry(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: WP_Error wirft Transient ohne Inner-Retry
    public function test_wp_error_throws_transient_without_inner_retry(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: 429-Retry-Pfad emittiert WARN-Log mit Source + ohne Bearer-Leak
    public function test_429_retry_emits_single_warn_log_without_bearer_token(): void
    {
        $this->markTestIncomplete('AC-11');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-07-http-client-base` | `SpreadconnectPod\Api\SpreadconnectClient::request($method, $path, $body)` | public method | Slice 07 AC-1..AC-9 implementiert; Slice 08 erweitert die existierende Datei (Edit) und behaelt die `request()`-Signatur + Return-Shape (`['status', 'body', 'headers']`) bei. Header-Map-Keys lower-case (Slice 07 Constraint). |
| `slice-07-http-client-base` | `SpreadconnectPod\Api\SpreadconnectClientError` | exception class | Bleibt unveraendert; Slice 08 wirft sie weiterhin fuer 4xx (AC-8) ohne Retry. |
| `slice-07-http-client-base` | `SpreadconnectPod\Api\SpreadconnectTransientError` | exception class | Wird in Slice 08 zusaetzlich fuer Code `http_429` (AC-2) genutzt. Konstruktor-Signatur unveraendert. |
| WordPress core | `wp_remote_request`, `wp_remote_retrieve_response_code`, `wp_remote_retrieve_headers` | Function | In Tests via Brain\Monkey aliased; `wp_remote_retrieve_headers` muss Header-Map mit `x-ratelimit-remaining` / `x-ratelimit-retry-after-seconds` (lower-case-normalisiert in Slice 07) liefern. |
| PHP / WP core | `sleep(int)` (oder Wrapper) | Function | Wird in Tests **nicht** real ausgefuehrt — Implementer kapselt Sleep in injizierbarem Hook (z.B. `protected function sleepSeconds(int $s): void { sleep($s); }` mit Test-Subclass-Override) oder als Constructor-injizierter Closure. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectClient::request` (rate-limit-aware) | public method (unveraenderte Signatur) | `slice-10-endpoint-methods` (alle 27 Wrapper), `slice-12-test-connection-ajax`, `slice-23-sync-article-job`, `slice-28-order-submit-job`, `slice-36-stock-sync-job` | `public function request(string $method, string $path, ?array $body = null): array` — semantisch erweitert um 429-Single-Retry und `X-RateLimit-Remaining`-Awareness; Caller muessen keinen Code aendern. |
| State `lastRateLimitRemaining` (intern) | private/protected property | nur intern (kein Public-Accessor noetig) | Wird zwischen Calls innerhalb derselben Client-Instanz erhalten; bei neuer Instanz `null` (kein cross-request-Bleed). |
| `SpreadconnectTransientError` mit Code `http_429` | exception variant | `slice-23/28/29/36` (AS-Retry-Konsumer), `slice-37-failed-ops-repo` (DLQ nach 3 AS-Retries) | Code-Konvention: `http_429` (statt `http_5xx`) — erlaubt feinere Logging-Auswertung in Slice 42 Logs-UI. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` — **Edit** (kein Neu-Anlegen). Erweitert die in Slice 07 angelegte Klasse um: (a) Pre-Send-Hook der vor `wp_remote_request` `lastRateLimitRemaining <= 5` prueft und ggf. 1s sleept; (b) Post-Send-Hook der `X-RateLimit-Remaining` aus Response-Headers liest und in Instanz-State speichert; (c) Status-Code-Klassifizierung mit Sonderpfad fuer 429 -> `Retry-After`-Sleep (Default 1s, Cap 30s) + **genau** ein Retry; (d) bei zweitem 429 -> `SpreadconnectTransientError` Code `http_429`. Slice-07-Verhalten fuer 4xx/5xx/Network/2xx bleibt unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-02-client-retry.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Genau ein** Inner-Retry — und ausschliesslich fuer 429. Kein Retry fuer 5xx (delegiert an Action Scheduler), kein Retry fuer Network-Errors, kein Retry fuer 4xx.
- **Kein** exponentieller Backoff, **kein** 3x-Retry-Pattern (v1-Pattern wird bewusst nicht uebernommen — Architecture Z. 644: "No inner 3x HTTP retry"; Discovery Z. 559).
- **Kein** persistenter Cross-Request-State — `lastRateLimitRemaining` lebt in der Client-Instanz, nicht in Transient/Option. Bei neuem Worker-Process startet die Awareness bei `null` (defensiv: kein Sleep beim ersten Call).
- **Kein** globaler Rate-Limit-Throttle ueber alle Endpoints (nur die pro-Instanz-Drossel reicht; Workers laufen ohnehin sequenziell pro AS-Claim).
- **Keine** Aenderung der `request()`-Signatur oder des Return-Shapes — Slice 10 darf transparent draufbauen.
- **Kein** Settings-UI fuer Sleep-Werte (Discovery: Sleep-Werte sind hardcoded 1s/Default, 30s/Cap).
- **Keine** zusaetzlichen Exception-Klassen — `SpreadconnectTransientError` mit neuem Code `http_429` reicht.

**Technische Constraints:**
- PHP 8.2 strict types; `declare(strict_types=1);`.
- `Retry-After-Sekunden`-Parsing: Header-Wert via `wp_remote_retrieve_header($response, 'x-ratelimit-retry-after-seconds')` (Slice 07 normalisiert auf lower-case), `(int)`-Cast; bei `0` oder non-numeric -> Default `1`; bei `> 30` -> Cap `30`. Konstanten als `private const RETRY_AFTER_DEFAULT_SECONDS = 1; private const RETRY_AFTER_MAX_SECONDS = 30;`.
- `X-RateLimit-Remaining`-Schwelle: Konstante `private const RATE_LIMIT_PROACTIVE_THRESHOLD = 5;` — Vergleich `<=` (nicht `<`).
- Sleep-Mechanismus: **Nicht direkt `sleep()` aufrufen**, sondern eine `protected function sleepSeconds(int $seconds): void` einfuehren, die im Production-Pfad `sleep($seconds)` ausfuehrt. Tests ueberschreiben diese Methode in einer Test-Subclass oder injizieren einen Spy via Constructor-Closure (Implementer-Wahl). Hintergrund: PHPUnit-Tests duerfen keinen Real-Sleep ausloesen (Test-Suite muss in <1s laufen).
- Status-Code-Switch: `switch (true) { case $status === 429: ...; case $status >= 500: ...; case $status >= 400: ...; case $status >= 200 && $status < 300: ...; default: throw transient }` — 429-Branch **vor** generischem 4xx-Branch.
- Retry-Counter: Lokale Variable in `request()`-Methode (z.B. `$attempt = 0`); kein Klassen-Property (sonst Cross-Call-Leak).
- Logging-Source unveraendert `'spreadconnect-api-client'`. Beim Retry-Trigger genau **ein** WARN-Log via `wc_get_logger()->warning(...)` mit Message-Format `'spreadconnect-api-client: 429 on {METHOD} {PATH}; retrying after {S}s (attempt 2/2)'` — Bearer-Token darf nicht in der Message landen (Slice 07 AC-9 Reuse).
- Header-Read fuer `X-RateLimit-Remaining`: Robust gegen Header-Absent (siehe AC-7) — `wp_remote_retrieve_header()` liefert leeren String bei Fehlen; Implementer prueft `'' !== $value && is_numeric($value)` vor `(int)`-Cast.
- Idempotenz unter Test: Test darf den Client mit zwei aufeinanderfolgenden Calls antreten und die Sleep-Sequenz deterministisch beobachten — die Implementation darf **keine** Zufallswerte (z.B. Jitter) einfuehren.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07) | **Edit, nicht ersetzen.** Slice 07 Constructor + `request()`-Methodensignatur + Slice-07-Verhalten fuer 2xx/4xx/5xx/Network bleiben unveraendert. Status-Klassifizierung wird um 429-Branch und Pre/Post-Hooks erweitert; alle Slice-07-ACs muessen weiterhin gruen sein (Test-Writer fuehrt Slice-07-Tests + Slice-08-Tests in einem Lauf aus). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Wird fuer 4xx-Pfad (AC-8) ohne Retry geworfen. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Erhaelt einen zweiten Use-Case (Code `http_429`) — Konstruktor-Signatur identisch. Slice 10/28/37 differenzieren ggf. ueber den string-Code. |
| WC `wc_get_logger()` mit Source `spreadconnect-api-client` | **Pflicht-Reuse** (Slice 07 Constraint, Architecture Z. 606). |
| WP-Core `wp_remote_retrieve_header()` (Header-Lookup) | **Pflicht-Reuse** statt manueller Array-Access — kapselt Object/Array-Inkonsistenz der WP-Versionen. |

**Referenzen:**
- Architecture: `architecture.md` -> Section "API Surface" Z. 81 (Outbound rate-limiting Definition); Section "Service Map" Z. 364 (SpreadconnectClient Verantwortlichkeit "single fail-fast retry on 429"); Section "Error Handling Strategy" Z. 606 (HTTP 429 -> sleep + ONE retry); Section "Rate Limiting & Abuse Prevention" Z. 512-513; Section "Constraints & Integrations" Z. 639, 644 (Single Retry-Layer at AS only, Inner Retry only for 429); Risk-Section Z. 730 (Rate-Limit-Cascade Mitigation); Trade-off Z. 772 (Single-Retry-Layer Konsequenz).
- Discovery: `discovery.md` -> Z. 556, 559 (Rate-Limit-Awareness, 429 -> einmal Retry-After warten + ein Versuch); Z. 86 (v1-Pattern als Referenz, **wird neu implementiert**); Z. 923 Slice 2 Testability "HTTP 429 Cases via wp_remote_*-Mock".
- Slim-Slices: `slices/slim-slices.md` -> Slice-08-Eintrag (Z. 225-232, Done-Signal: 429 mit Retry-After loest genau einen Inner-Retry aus; doppeltes 429 wirft Transient; 4xx wirft Client; 5xx wirft Transient).
- Slice 07: `slices/slice-07-http-client-base.md` -> AC-6/AC-7/AC-8/AC-9 (Status-Klassifizierung + Logging) bilden die Baseline, die in Slice 08 nicht regredieren darf.
- Slice 10 (kommend): wird `request()` 27x aufrufen (alle Endpoint-Wrapper). Slice 08 muss daher die Signatur stabil halten.
- Slice 37 (kommend): Failed-Ops-Repo unterscheidet Permanent (Slice-07-`SpreadconnectClientError`) vs. Transient (`SpreadconnectTransientError` inkl. neuem `http_429`-Code) — Slice 08 fuegt nur den Code-String hinzu, nicht das Branching-Verhalten.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 08 (kein UI).
