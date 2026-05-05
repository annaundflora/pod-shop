# Slice 42: Logs-UI + WC-Logger-Adapter

> **Slice 42 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-42-logs-ui-wc-logger-adapter` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-13-hub-page-skeleton", "slice-07-http-client-base"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Aliases fuer `wc_get_logger`, `WC_Log_Handler_File::get_log_file_path`, `glob`, `file_get_contents`, `wp_upload_dir`, `__()`, `current_user_can`, `wp_create_nonce`/`check_admin_referer`, `header`/`fputcsv` via Patchwork) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: Hub `?page=spreadconnect&section=logs` rendert Tail-View; CSV-Download triggert Browser-Download) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey + Patchwork: `WC_Logger`-Spy fuer `log()`-Calls; in-memory Filesystem-Stubs fuer `wc-logs/`-File-Reads; AJAX `$_GET`/`$_POST` ueber `wp_unslash`-Mocks gespeist) |

---

## Sources (Single Source of Truth)

> Alle Plugin-Komponenten nutzen ausschliesslich diese Source-Strings beim Logging. Source-Liste ist final (Architecture Z. 398, Discovery Slice 10, Annahme #11).

| Source-String | Verwendet von |
|---|---|
| `spreadconnect-api-client` | `Api\SpreadconnectClient` (Slice 07/08/10) — Outbound-HTTP, Status, Rate-Limit-Sleeps, Retries |
| `spreadconnect-order-service` | `Order\OrderSubmitJob`, `OrderConfirmJob`, `OrderCancelJob`, `OrderStateMachine`, `OrderHandler`, `FetchTrackingJob`, `OrderCancelMirrorJob` (Slice 27-31) |
| `spreadconnect-webhook-receiver` | `Webhook\WebhookController`, `WebhookSignatureVerifier`, `EventIdHasher`, `ProcessWebhookEventJob`, Domain-Handler `OrderEventHandler`/`ArticleEventHandler` (Slice 15-17, 25, 30) |
| `spreadconnect-sync-job` | `Catalog\SyncCatalogJob`, `SyncArticleJob`, `ArticleRemovedJob`, `ImageSideloader`, `ProductMapper`, `Stock\StockSyncJob`/`LiveStockRefresher` (Slice 21-25, 36) |
| `spreadconnect-failure` | `Failure\FailedOpsRepo`, `RetryPolicyListener`, `FailureNotifier`, `BulkResendCoordinator` (Slice 37-40) |
| `spreadconnect-cli` | `Cli\Commands` (optional WP-CLI-Adapter, ggf. post-MVP — Source ist reserviert) |

---

## Ziel

Liefert (a) den **`Logging\WcLoggerAdapter`** als zentralen Logger-Wrapper ueber `wc_get_logger()` mit den 6 oben definierten Sources, der **Bearer-Tokens** (`Authorization: Bearer ...`) und **HMAC-Signaturen** (`X-SPRD-SIGNATURE: ...`) vor dem File-Write redacted, und (b) die **`Hub\View\Logs`-Page**, die `wc-logs/`-Files mit Filter (Level, Source, Date-Range) liest, die letzten 200 Entries als Tail-View rendert und CSV-Export anbietet. Schliesst Discovery-Slice 10 "Logs + WC_Logger-Adapter" funktional ab. **`error_log()`-Aufrufe in Plugin-Sources sind ab dieser Slice verboten** (Architecture AVOID #6, Z. 687).

---

## Acceptance Criteria

1) **GIVEN** `WcLoggerAdapter::log('info', $message, ['source' => 'spreadconnect-api-client', 'foo' => 'bar'])` wird aufgerufen
   **WHEN** der Adapter intern `wc_get_logger()->log()` aufruft
   **THEN** wird genau ein `Logger::log($level='info', $message, $context)`-Call abgesetzt, dessen `$context['source']` exakt einer der 6 Source-Strings aus der Sources-Tabelle ist; jeder andere Source-Wert wird mit `\InvalidArgumentException` zurueckgewiesen (Whitelist-Validation gegen Sources-Tabelle).

2) **GIVEN** eine geloggte `$message` enthaelt das Substring `Authorization: Bearer sk-test-abcdef1234567890`
   **WHEN** der Adapter `log()` ausfuehrt
   **THEN** ist die an `wc_get_logger()->log()` durchgereichte Message exakt `Authorization: Bearer ***` — der Token-Wert ist vollstaendig durch `***` ersetzt (Architecture Z. 494). Pattern matched case-insensitive den Header-Namen und alle Whitespaces dazwischen, plus den darauf folgenden non-whitespace-Token-Wert.

3) **GIVEN** eine geloggte `$message` oder ein `$context`-Wert enthaelt einen `X-SPRD-SIGNATURE: <base64>`-Header (Webhook-Receiver-Logs)
   **WHEN** der Adapter `log()` ausfuehrt
   **THEN** ist der Signatur-Wert in der finalen Message und im finalen Context durch `***` ersetzt (`X-SPRD-SIGNATURE: ***`). Redaction ist rekursiv durch das gesamte `$context`-Array (auch verschachtelte Arrays/Objects-toString).

4) **GIVEN** das `$context`-Array enthaelt einen Schluessel `api_key` oder einen Wert, der einem Bearer-Token-Pattern (40+ alphanum/`-_` Zeichen nach `Bearer`) entspricht
   **WHEN** der Adapter `log()` ausfuehrt
   **THEN** sind Wert von `api_key` und alle Bearer-Token-Match-Substrings im Context durch `***` ersetzt; der Schluessel selbst bleibt erhalten (zur Diagnose-Faehigkeit).

5) **GIVEN** der Hub-Routing-Dispatcher (Slice 13) ruft `Hub\View\Logs::render()` mit `?page=spreadconnect&section=logs`
   **WHEN** kein Filter-Query-Param gesetzt ist
   **THEN** rendert die Page (a) die Section-Sidebar (Slice 13 Helper), (b) eine Filter-Bar mit Dropdowns `Level` (Optionen: All/debug/info/warning/error), `Source` (Optionen: All + 6 Sources aus Tabelle), `Date-Range` (Optionen: Today/Last 7 days/Last 30 days), (c) einen `[Download CSV]`-Button, (d) eine Tail-Tabelle mit den letzten **200** Eintraegen (newest first), Spalten `Time | Level | Source | Message`. Layout entspricht `wireframes.md -> Screen 6`.

6) **GIVEN** `Hub\View\Logs::render()` liest `wc-logs/`-Files
   **WHEN** Files mit Source-Praefix `spreadconnect-*` und Datum innerhalb des Date-Range-Filters existieren
   **THEN** werden ausschliesslich Files gelesen, deren Filename mit einem der 6 Sources aus der Sources-Tabelle beginnt (Praefix-Match `spreadconnect-{source}-*.log`); Files anderer WC-Plugins (z. B. `fatal-errors-*.log`, `mailchimp-*.log`) werden nie gelesen oder ausgegeben.

7) **GIVEN** ein Filter `?level=error&source=spreadconnect-api-client&date=today`
   **WHEN** `Hub\View\Logs::render()` Filter aus `$_GET` liest
   **THEN** werden alle drei Filter via `sanitize_key()` (Source/Level) bzw. Whitelist-Compare gegen die Date-Range-Optionen sanitisiert; nur Eintraege mit `level === 'error'` UND `source === 'spreadconnect-api-client'` UND `timestamp` im Today-Range erscheinen in der Tail-Tabelle. Unbekannte Filter-Werte fallen auf `All`/`Today` zurueck (kein 404, keine Exception).

8) **GIVEN** keine Log-Eintraege matchen den aktuellen Filter
   **WHEN** `render()` ausgefuehrt wird
   **THEN** zeigt die Tail-Tabelle den lokalisierten Empty-State `__('No log entries match current filters.', 'spreadconnect-pod')` (wireframes.md `empty`-State, Z. 548).

9) **GIVEN** ein Admin-User klickt `[Download CSV]` mit aktiven Filtern
   **WHEN** der AJAX/Admin-Action-Handler `spreadconnect_logs_csv_export` (Hub-AJAX-Pattern aus Slice 13) ausgeloest wird
   **THEN** verifiziert der Handler `current_user_can('manage_woocommerce')` + Nonce (`spreadconnect_admin`); bei Erfolg streamt er einen CSV mit Header-Zeile `Time,Level,Source,Message` und allen Filter-matchenden Eintraegen (NICHT auf 200 limitiert), `Content-Type: text/csv`, `Content-Disposition: attachment; filename="spreadconnect-logs-YYYYMMDD-HHMMSS.csv"`. Werte werden via `fputcsv()` korrekt escaped (Komma, Quotes, Newlines im Message). Fehlende Cap/Nonce -> HTTP 403.

10) **GIVEN** ein Plugin-Source-File aus dem v2-Plugin-Tree (`wordpress/plugins/spreadconnect-pod/includes/**/*.php`)
    **WHEN** ein statischer Grep nach `error_log(` ausgefuehrt wird
    **THEN** sind **0 Matches** im Plugin-Code-Tree (`includes/`) — alle Logging-Calls laufen ueber `WcLoggerAdapter` (Architecture Z. 687, Done-Signal slim-slices.md Slice-42). Test-Helper-Files unterhalb `tests/` sind ausgenommen.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei Test-Dateien — eine fuer `WcLoggerAdapter` (Brain\Monkey-Spy auf `wc_get_logger()` + `WC_Logger::log()`), eine fuer `Hub\View\Logs` (Patchwork-Replace fuer `glob`/`file_get_contents`; Output-Buffer-Capture fuer Markup; `Functions\expect()` fuer AJAX-Cap+Nonce-Calls). AC-10 ist ein Repository-Lint-Test (`shell_exec('grep -rn ...')` oder PHP-`RecursiveDirectoryIterator` + `preg_match('/error_log\s*\(/')`).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-42-wc-logger-adapter.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class WcLoggerAdapterTest extends TestCase
{
    // AC-1: Whitelisted Source -> wc_get_logger->log Call mit identischer Source
    public function test_log_dispatches_to_wc_logger_with_whitelisted_source(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Unbekannte Source -> InvalidArgumentException, kein log-Call
    public function test_log_rejects_unknown_source_with_invalid_argument(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Bearer-Token in Message -> Bearer ***
    public function test_log_redacts_bearer_token_in_message(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Bearer-Redaction case-insensitive
    public function test_bearer_redaction_is_case_insensitive(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: X-SPRD-SIGNATURE in Message wird redacted
    public function test_log_redacts_sprd_signature_header_in_message(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: X-SPRD-SIGNATURE in nested context wird redacted
    public function test_log_redacts_sprd_signature_in_nested_context(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Context-Key 'api_key' wird auf *** gesetzt, Key bleibt
    public function test_log_redacts_api_key_context_value(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Free-form Bearer-Pattern in Context-Werten wird redacted
    public function test_log_redacts_bearer_pattern_in_context_values(): void
    {
        $this->markTestIncomplete('AC-4');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-42-logs-page.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class HubLogsPageTest extends TestCase
{
    // AC-5: Logs::render rendert Filter-Bar + 200-Row Tail + CSV-Button
    public function test_render_emits_filter_bar_and_tail_table_and_csv_button(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Source-Dropdown enthaelt All + 6 Sources aus Whitelist
    public function test_source_dropdown_lists_all_six_sources(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Es werden nur wc-logs/spreadconnect-*-Files gelesen
    public function test_render_only_reads_spreadconnect_prefixed_log_files(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Filter level/source/date werden korrekt angewendet
    public function test_render_filters_entries_by_level_source_and_date_range(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Unbekannter Filter-Wert faellt auf All/Today zurueck
    public function test_render_falls_back_for_unknown_filter_values(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Empty-State-String wird gerendert wenn keine Eintraege matchen
    public function test_render_emits_localized_empty_state_when_no_matches(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: CSV-Export ohne manage_woocommerce -> 403
    public function test_csv_export_aborts_without_manage_woocommerce(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: CSV-Export ohne valid Nonce -> 403
    public function test_csv_export_aborts_without_valid_nonce(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: CSV-Stream enthaelt Header-Zeile + alle gefilterten Rows mit korrektem Escaping
    public function test_csv_export_streams_header_and_filtered_rows(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Lint - 0 error_log()-Calls in includes/
    public function test_no_error_log_calls_in_plugin_sources(): void
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
| `slice-13-hub-page-skeleton` | `Hub\Controller`-Routing fuer `?section=logs` -> `Hub\View\Logs::render` | static method | Routing-Tabelle in Slice 13 enthaelt `logs` -> `Hub\View\Logs` (Section-Slug-Tabelle Z. 49). |
| `slice-13-hub-page-skeleton` | `Hub\View\Sidebar::render(string $active_slug)` | static method | Konsumiert vom Logs-Page-Markup als erste Render-Action. |
| `slice-13-hub-page-skeleton` | `Hub\Controller::ensureCapability(): void` | static method | Verwendet im CSV-Export-AJAX-Handler. |
| `slice-07-http-client-base` | `SpreadconnectClient` ruft Logger via Source `spreadconnect-api-client` | side-effect | AC-2 testet dass der Adapter die `Authorization: Bearer ...`-Strings, die der Client emittiert, zuverlaessig redacted. Slice 07 logt bereits Source-string-treu (AC-9 dort). |
| WordPress core | `current_user_can`, `check_admin_referer`, `wp_create_nonce`, `sanitize_key`, `sanitize_text_field`, `esc_html`, `esc_attr`, `esc_url`, `__`, `_e`, `add_action('wp_ajax_spreadconnect_logs_csv_export', ...)`, `header`, `fputcsv`, `glob`, `file_get_contents` | function/hook | Standard-WP-API; Brain\Monkey-Aliases im Test-Bootstrap. |
| WooCommerce core | `wc_get_logger(): \WC_Logger` mit `log($level, $message, $context)`-Method; `WC_Log_Handler_File`-Default-Path-Resolver | function/object | Source-string-Routing zu `wc-logs/{source}-{date}-{hash}.log` ist WC-Standard; Adapter darf den Pfad nicht hardcoden, sondern muss WC-API/WP-Upload-Dir nutzen (siehe Constraints). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Logging\WcLoggerAdapter` | infrastructure class | **Alle** Plugin-Komponenten ab Slice 07 (07/08/10/15/16/17/22-25/28-31/36-40/44) | `public static function log(string $level, string $message, array $context): void` mit `$context['source']` Pflicht; `public static function info/warning/error/debug(string $source, string $message, array $context = []): void` als Convenience-Methoden. |
| `SpreadconnectPod\Logging\WcLoggerAdapter::redact(string\|array $value): string\|array` | static helper | Optional von anderen Slices (z. B. `WebhookController`) zur Vor-Redaction von Payload-Snippets vor Persistierung in `webhook_log`. | `public static function redact($value)` — idempotent. |
| `SpreadconnectPod\Hub\View\Logs::render(): void` | static method | `Hub\Controller`-Dispatcher (Slice 13) | `public static function render(): void` — `?section=logs`-Target. |
| `SpreadconnectPod\Hub\Ajax\LogsCsvExport::handle(): void` | static method | WP-Admin-AJAX (`wp_ajax_spreadconnect_logs_csv_export`) | `public static function handle(): void` — registriert in Bootstrap; streamt CSV. |
| Source-Whitelist `Logging\Sources` | const class | Slice 43 (`PurgeOldLogsJob` darf nur Plugin-eigene Logs purgen, falls jemals File-Purge dazukommt) | `final class Sources { public const ALL = ['spreadconnect-api-client', ...]; }` — Single Source of Truth fuer alle Slices. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Logging/WcLoggerAdapter.php` — Klasse `SpreadconnectPod\Logging\WcLoggerAdapter` mit `log($level, $message, $context)`-Kern (Whitelist-Validation auf `$context['source']`, Bearer-/HMAC-/api_key-Redaction in Message + Context rekursiv, Forward an `wc_get_logger()->log()`); Convenience-Methoden `info/warning/error/debug`; `static redact()`-Helper. Source-Whitelist als `const SOURCES`-Array. Eingebettete oder dedizierte `final class Logging\Sources`-Konstanten-Klasse — Implementer-Wahl, eine Datei.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Logs.php` — Klasse `SpreadconnectPod\Hub\View\Logs` mit `static render(): void`. Liest Filter aus `$_GET` (sanitisiert + whitelisted), enumeriert `wc-logs/spreadconnect-*-*.log`-Files, parst Eintraege via WC-Logger-File-Format (`YYYY-MM-DDTHH:MM:SS+ZZ:ZZ LEVEL Message`), filtert/sortiert/limitiert auf 200, rendert Sidebar (Slice 13) + Filter-Bar + Tail-Tabelle + CSV-Button. Embedded oder dedizierte `Hub\Ajax\LogsCsvExport`-Klasse fuer den Streaming-Handler — Implementer-Wahl, eine Datei.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Mount-Point: `add_action('wp_ajax_spreadconnect_logs_csv_export', [Hub\Ajax\LogsCsvExport::class, 'handle'])` (oder Aequivalent). Ohne diesen Edit ist der CSV-Download nicht erreichbar. Logs-Page selbst wird via Slice-13-Routing dispatched — kein zusaetzlicher Hook noetig.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-42-wc-logger-adapter.php` und `tests/slices/pod-shop-mvp/slice-42-logs-page.php` aus den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Keine Auto-Refresh-JS** (Wireframe ②): MVP rendert statisches Tail mit manueller Reload — Auto-Refresh-Polling ist post-MVP-Polish (kein eigenes Slice geplant; Wireframe-State `auto_refresh_off` ist akzeptabel als Default).
- **Kein File-Purge** in dieser Slice — `wc-logs/`-File-Lifecycle ist WC-Standard (default 30 Tage retention via `WC_Log_Handler_File`); custom Plugin-Purge ist Slice 43 (`PurgeOldLogsJob` purged DB-Rows in `webhook_log`/`failed_ops`, nicht Files).
- **Kein Live-Tail-WebSocket** — File-Read ist on-Page-Load.
- **Kein Refactor** der bestehenden v1-`error_log`-Calls (v1 wird in Slice 01 komplett geloescht; AC-10-Lint laeuft gegen v2-Tree, der neu aufgebaut ist).
- **Keine Per-Source-Aktivierung in UI** — alle 6 Sources sind immer aktiv; Filter-Dropdown ist read-only-Filter, kein Toggle.
- **Keine Backfill-Migration** alter v1-Logs in das neue Format.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in beiden neuen Dateien.
- `WcLoggerAdapter` als `final class` mit ausschliesslich `static` Methoden (Stateless-Adapter).
- `Hub\View\Logs` als `final class` mit ausschliesslich `static` Methoden (analog Slice 13 Hub-View-Pattern).
- Source-Whitelist als `final class Logging\Sources`-Konstante (oder gleichwertig in Adapter): die Tabelle in dieser Spec ist Single Source of Truth — JEDE neue Source MUSS hier eingetragen werden.
- `wc-logs/`-Pfad-Resolution: ueber WC-API (`WC_Log_Handler_File::get_log_file_path($source)`) ODER `wp_upload_dir()['basedir'] . '/wc-logs/'` — **kein** hardgecodeter Pfad. Test-Mock liefert in-memory File-List.
- Redaction-Pattern Bearer: `/Authorization\s*:\s*Bearer\s+[^\s,;]+/i` -> `Authorization: Bearer ***`; freistehender Bearer-Token (ohne Header-Praefix) im Context: `/Bearer\s+[A-Za-z0-9_\-\.=]{20,}/i` -> `Bearer ***`.
- Redaction-Pattern HMAC: `/X-SPRD-SIGNATURE\s*:\s*[^\s,;]+/i` -> `X-SPRD-SIGNATURE: ***`.
- Context-Recursion: `redact()` walkt rekursiv durch Arrays, redacted alle String-Values; Objekt-Werte werden via `(string)` gecastet falls `__toString` vorhanden, sonst `print_r` mit Tiefe-Limit 3 (zur Vermeidung von Infinite-Recursion bei zyklischen Objekt-Graphen).
- CSV-Export-Action-Name **exakt** `spreadconnect_logs_csv_export` (snake_case, Plugin-Praefix); Nonce-Action-String **exakt** `spreadconnect_admin` (Architecture Z. 84, gemeinsamer Admin-Nonce).
- Tail-Limit hardcoded `200` als `private const TAIL_LIMIT = 200` in `Hub\View\Logs`. CSV-Export hat **kein** Tail-Limit (alle gefilterten Eintraege).
- Date-Range-Optionen: `today` (00:00 lokale TZ heute), `7days` (rolling 7d), `30days` (rolling 30d) — keine custom Date-Picker im MVP.
- Level-Whitelist: `debug`, `info`, `warning`, `error` (PSR-3-Subset, das WC_Logger nutzt — kein `notice`/`critical`/`alert`/`emergency` in MVP).
- WC-Logger-File-Format-Parser: WC schreibt eine Zeile pro Eintrag im Format `YYYY-MM-DDTHH:MM:SS+ZZ:ZZ LEVEL ...message...` — Parser MUSS multi-line Messages (Stack-Traces) korrekt einer Entry zuordnen (continuation-Lines beginnen NICHT mit ISO-Timestamp).
- Output-Escaping: alle dynamischen Werte (Time/Level/Source/Message) durch `esc_html()` / Attribute durch `esc_attr()` / URLs durch `esc_url()`. CSV-Export NICHT durch HTML-Escapes — `fputcsv()` macht CSV-Escaping; danach kein zusaetzlicher Encode-Layer.
- Alle User-Strings (Filter-Labels, Empty-State, CSV-Filename-Datum-Format) via `__()`/`esc_html__()` mit Domain `'spreadconnect-pod'` (Slice-06-Anschluss).
- Keine `error_log()`-Aufrufe im Adapter selbst (sonst infinite-loop-risk + AC-10-Verletzung).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` (Slice 13) | **Import**, NICHT modifizieren — Routing `?section=logs` -> `Hub\View\Logs::render` ist bereits in Slice 13 SECTIONS-Whitelist; `ensureCapability()` wird vom CSV-AJAX-Handler konsumiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Import** — `Sidebar::render('logs')` als erste Action in `Logs::render()`. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02-13) | **Edit** — Mount-Point `wp_ajax_spreadconnect_logs_csv_export`. Bestehende Hook-Registrierungen bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08) | **Nicht modifiziert in dieser Slice.** Slice 07 logt bereits via `wc_get_logger()` mit Source `spreadconnect-api-client`; Slice 42 ergaenzt nur die Redaction, der Client braucht keine Anpassung (Adapter wickelt Redaction transparent vor `WC_Logger::log()` ab). Folge-Slices 08/10/15-40 nutzen den Adapter direkt. |
| WC-Core: `wc_get_logger()` + `WC_Log_Handler_File` | **Pflicht-Reuse** statt eigener File-Writer. Architecture Z. 757 + Z. 538. |

**Referenzen:**
- Architecture: `architecture.md` -> Service Map "`Logging\WcLoggerAdapter`" (Z. 398, Source-Liste); "Module Layers" (Z. 538, Adapter-Pattern); "Operational Concerns" (Z. 494, Bearer-Redaction-Mandat); "Tech-Stack-Choices" (Z. 757, `wc_get_logger` ueber `error_log`); "Quality Goals" (Z. 687, `error_log` banned in v2 lint).
- Wireframes: `wireframes.md` -> Screen 6 "Logs" (Z. 502-541) + State-Variations (Z. 545-551).
- Discovery: `discovery.md` -> Slice 10 "Logs + Polish" (Z. 308-317, Layout + Source-Liste); REUSE-Pattern "WC_Logger" (Z. 80, 107).
- Slim-Slices: `slices/slim-slices.md` -> Slice-42-Eintrag (Z. 616-623, Done-Signal); Annahme #11 (Z. 842, Source-Liste-Final).
- Vorgaenger Slice 13: `slices/slice-13-hub-page-skeleton.md` -> Section-Slug-Tabelle (Z. 49, `logs` -> `Hub\View\Logs`); Provides-To `Hub\Controller::ensureCapability` + `Hub\View\Sidebar::render`.
- Vorgaenger Slice 07: `slices/slice-07-http-client-base.md` -> AC-9 (Logging mit Source `spreadconnect-api-client` + Token-Redaction-Mandat); Provides-To `SpreadconnectClient::request` (loggt taggable).
- Folge-Slice 43 `PurgeOldLogsJob` purged DB-Rows in `webhook_log`/`failed_ops` ueber Retention-Setting; nutzt `Logging\Sources` als Whitelist falls Plugin-File-Purge spaeter dazukommt.
