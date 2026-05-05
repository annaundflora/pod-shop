# Slice 46: i18n-de_DE.po + README + Final-Polish

> **Slice 46 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-46-i18n-de-readme` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-43-purge-old-logs-job", "slice-39-failure-notifier"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Aliases fuer `get_option`, `current_time`; `wpdb`-Stub mit `prefix='wp_'`, `prepare()`-Echo, `get_var()`/`get_results()`-Spies; statische Subclass-Stubs fuer `WebhookLogRepo`, `FailedOpsRepo`, `SyncHistoryRepo`, `SubscriptionManager`, `AdminNoticeStore`; Patchwork-Replace fuer i18n-Funktionen `__`/`esc_html__`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `define('WPLANG','de_DE')` in `wp-config.php`, Hub aufrufen, deutsche Strings; Dashboard-Cards zeigen DB-Counts) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (kein echter `wpdb`/Action-Scheduler — Brain\Monkey + wpdb-Stub; Repos via Konstruktor-Injektion mockbar) |

---

## Ziel

Schliesst Slice 10 "Logs + Polish" ab: (1) `de_DE.po` mit allen `__()`-Strings aus Slices 02-45 vollstaendig uebersetzt, (2) Plugin-`README.md` mit Setup/Features/Architecture-Verweisen, (3) `Hub\View\Dashboard::render()` ersetzt die 5 Card-Platzhalter aus Slice 13 durch echte Aggregat-Counts aus den Repos.

---

## Acceptance Criteria

1) **GIVEN** der Quellcode des Plugins (alle PHP-Dateien unter `wordpress/plugins/spreadconnect-pod/includes/` aus Slices 02-45)
   **WHEN** `wp i18n make-pot wordpress/plugins/spreadconnect-pod wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod.pot --domain=spreadconnect-pod --skip-js` (oder aequivalenter `i18n-extract`-dry-run) ausgefuehrt wird
   **THEN** sind **alle** `__()`/`_e()`/`esc_html__()`/`esc_attr__()`/`_x()`/`_n()`-Strings im Plugin-Code in `spreadconnect-pod-de_DE.po` enthalten; der Vergleich `pot vs po` liefert **0 missing strings** (msgid-Set in `.po` >= msgid-Set in `.pot`).

2) **GIVEN** `spreadconnect-pod-de_DE.po`
   **WHEN** die Datei geparsed wird
   **THEN** sind die Pflicht-Header gesetzt: `Project-Id-Version: Spreadconnect POD <Version aus Plugin-Header>`, `Language: de_DE`, `Content-Type: text/plain; charset=UTF-8`, `Plural-Forms: nplurals=2; plural=(n != 1);`, `X-Domain: spreadconnect-pod`. **Keine** `msgstr ""` (leere Uebersetzung) fuer User-facing Strings — jede `msgid` hat eine nicht-leere deutsche `msgstr`.

3) **GIVEN** die uebersetzten Strings
   **WHEN** das Glossar-konsistente Mapping geprueft wird
   **THEN** sind die fachlichen Begriffe konsistent uebersetzt nach Tabelle:
   | Englisch (msgid) | Deutsch (msgstr) |
   |---|---|
   | `Failed Operations` | `Fehlgeschlagene Operationen` |
   | `Webhooks` | `Webhooks` (unveraendert — Fachbegriff) |
   | `Subscriptions` | `Abonnements` |
   | `Catalog` | `Katalog` |
   | `Settings` | `Einstellungen` |
   | `Dashboard` | `Dashboard` (unveraendert — Fachbegriff) |
   | `Logs` | `Protokolle` |
   | `Resend` | `Erneut senden` |
   | `Dismiss` | `Verwerfen` |
   | `Mark Resolved` | `Als geloest markieren` |
   | `Sync Now` / `Jetzt synchronisieren` | `Jetzt synchronisieren` |
   Ein Test-Case verifiziert mindestens 5 dieser Mappings im `.po`-Inhalt.

4) **GIVEN** `wordpress/plugins/spreadconnect-pod/README.md` ist erstellt
   **WHEN** die Datei gelesen wird
   **THEN** enthaelt sie genau die Top-Level-Sections (H2): `## Overview`, `## Setup`, `## Features`, `## Architecture`, `## Development`, `## Troubleshooting`, `## License`. Reihenfolge wie aufgefuehrt; jede Section hat mindestens einen Absatz Inhalt (kein leerer Stub).

5) **GIVEN** README.md `## Setup`-Section
   **WHEN** sie gelesen wird
   **THEN** enthaelt sie nummerierte Schritte fuer (a) `composer install` im Plugin-Verzeichnis, (b) Plugin-Aktivierung in WP-Admin, (c) Settings -> API-Key + Test Connection, (d) Webhook-Secret One-Time-Reveal kopieren, (e) Catalog-Sync ausloesen. Jeder Schritt verlinkt auf den passenden Abschnitt in `architecture.md` oder `discovery.md` (relativ wie `../../specs/2026-05-03-.../architecture.md#section-name`).

6) **GIVEN** README.md `## Features`-Section
   **WHEN** sie gelesen wird
   **THEN** listet sie genau die 10 Discovery-Slices (`Plugin Foundation`, `API Client + Authentication`, `Webhook Receiver + Subscriptions`, `Catalog-Sync`, `Order-Lifecycle`, `Stock-Sync`, `Hub-Page + Settings`, `Inline UX`, `Failure-Recovery`, `Logs + Polish`) als Bullet-Points mit jeweils 1-Satz-Beschreibung; **kein** Slice-Detail-Echo (Verweis statt Copy).

7) **GIVEN** README.md `## Architecture`-Section
   **WHEN** sie gelesen wird
   **THEN** enthaelt sie genau einen Markdown-Link auf die Architecture-Spec (`[Full Architecture](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md)`) plus eine Kurz-Auflistung der 5 Layer (Bootstrap, Domain, Application, Infrastructure, Adapter) mit je einem Beispiel-Service. **Kein** Schema/Flow-Diagramm-Copy.

8) **GIVEN** `Hub\View\Dashboard::render()` aus Slice 13 wird durch die Slice-46-Implementierung ersetzt
   **WHEN** ein Admin mit `manage_woocommerce` `?page=spreadconnect&section=dashboard` aufruft
   **THEN** wird **Card 1 (Connection)** gerendert mit dem Status-String aus dem `sc_health`-Transient (`ok`/`auth_failed`/`unknown`); fehlt der Transient, wird `unknown` und ein "Re-test"-Button angezeigt — **keine** Live-API-Anfrage in `render()` (`SpreadconnectClient` darf nicht aufgerufen werden, AC-9 verifiziert das).

9) **GIVEN** `Hub\View\Dashboard::render()` laeuft
   **WHEN** Card 2 (Catalog) gerendert wird
   **THEN** wird der Wert "linked products" aus `wc_get_orders`/`get_posts`-Aequivalent **NICHT** ad-hoc berechnet, sondern aus `Catalog\SyncHistoryRepo::findLatest()` (oder `findLatestComplete()`) gelesen: Card-Inhalt zeigt `created_count + updated_count` aus der **letzten** `state='complete'`-Row, sowie `started_at` als "Letzter Sync" (lokalisiertes Datums-Format via `date_i18n()`). Bei fehlender Row -> "Noch kein Sync ausgefuehrt".

10) **GIVEN** `Hub\View\Dashboard::render()` laeuft
    **WHEN** Card 3 (Orders) gerendert wird
    **THEN** wird **eine** Aggregat-Query ausgefuehrt, die Counts pro `_spreadconnect_state`-Wert (`NEW`, `CONFIRMED`, `PROCESSED`, `failed_to_submit`) fuer Orders der **letzten 30 Tage** liefert. Verwendet wird `wc_get_orders` mit `meta_query` ODER ein direkter `$wpdb->get_results`-Call gegen die HPOS-Meta-Tabelle (`wp_wc_orders_meta`). Card zeigt 4 Zahlen: "Pending: N", "Confirmed: N", "Processed: N", "Failed: N". Out-of-Scope-Fallback fuer Legacy-Orders: query nutzt WC-Service-Abstraktion `wc_get_orders` (HPOS-aware), nicht direkten `wp_postmeta`-Zugriff.

11) **GIVEN** `Hub\View\Dashboard::render()` laeuft
    **WHEN** Card 4 (Webhooks) gerendert wird
    **THEN** wird (a) der Subscription-Status aus dem `sc_subscriptions_status`-Transient (oder aequivalent — Slice-18-Provides) gelesen und als `"X / 7 active"` angezeigt; (b) der **letzte** Webhook-Log-Eintrag via `Webhook\WebhookLogRepo::findLatest(): ?array` geladen und `received_at + event_type` formatiert ausgegeben. Bei fehlendem Eintrag -> "Noch kein Event empfangen". Slice-18-Provides liefert die Subscription-State-Quelle (Lazy-Cache, NICHT live-API-Call in `render()`).

12) **GIVEN** `Hub\View\Dashboard::render()` laeuft
    **WHEN** Card 5 (Failed Operations) gerendert wird
    **THEN** wird `Failure\FailedOpsRepo::countByState('unresolved'): int` aufgerufen (genau ein DB-Query auf Index `idx_state_op_type`); Card zeigt `"<N> offen"` als Zahl + Link `?page=spreadconnect&section=failed`. Wenn `N > 0` und `Failure\AdminNoticeStore::count('error') > 0`, wird zusaetzlich ein roter Banner-Hint gerendert (Severity-Verstaerker — siehe Slice 39 AC-10 fuer `count(?string $severity = null)`).

13) **GIVEN** Dashboard-Render erfolgt mit `manage_woocommerce`-User
    **WHEN** der Output gemessen wird
    **THEN** alle dynamischen Counts/Strings sind via `esc_html()` escaped, alle Links via `esc_url(admin_url(...))`, das Datum via `date_i18n(get_option('date_format'), $timestamp)` (Locale-aware). **Keine** rohen Echo-Werte. Alle Card-Titel + Labels sind `__()`-gewrapped mit Domain `'spreadconnect-pod'` (siehe AC-1 — diese Strings landen ebenfalls in der `.po`).

14) **GIVEN** `Hub\View\Dashboard::render()` wird aufgerufen
    **WHEN** keine der Repos/Transients fehlerfrei laden (z. B. Tabelle existiert temporaer nicht / Transient gewipt / Repo wirft `\Throwable`)
    **THEN** wird **kein** Fatal an die Admin-Page propagiert; jeder Card-Block ist einzeln in einen `try/catch (\Throwable $e)` gewrapped; bei Catch wird der Card-Inhalt durch "Daten nicht verfuegbar" + WC-Logger-Eintrag (`error`, Source `spreadconnect-failure`) ersetzt; die anderen Cards rendern weiter.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Drei Test-Bereiche: (a) `.po`-File-Static-Analysis (parse Header + msgid/msgstr Pairs), (b) README-File-Existenz + Section-Header-Check, (c) Dashboard-Render-Markup mit Repo-Mocks. Brain\Monkey-Setup mit `Functions\when('get_option')`/`get_transient`/`current_user_can`/`admin_url`/`date_i18n`/`esc_html`/`esc_url`/`esc_attr`/`__`/`esc_html__`. Repos werden via Konstruktor-Injektion in eine **Test-Subclass** des Dashboards uebergeben — die Production-Klasse darf weiterhin `static` bleiben (Slice-13-Pattern), aber Constructor-Wiring auf nicht-statische Wrapper-Methode mit Repo-Args ist erlaubt.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-46-i18n-readme-dashboard.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class I18nReadmeDashboardTest extends TestCase
{
    // AC-1: i18n-extract dry-run findet 0 missing strings
    public function test_po_file_contains_all_msgids_from_plugin_source(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: .po Header-Felder vollstaendig + nicht-leere msgstrs
    public function test_po_file_has_required_headers_and_no_empty_translations(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Glossar-konsistente Uebersetzungen (mind. 5 Mappings)
    public function test_po_file_uses_consistent_glossary_translations(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: README.md hat alle 7 H2-Sections in korrekter Reihenfolge
    public function test_readme_has_required_top_level_sections_in_order(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: README Setup-Section hat 5 Schritte mit Architecture-Verweisen
    public function test_readme_setup_section_lists_five_steps_with_references(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: README Features-Section listet 10 Discovery-Slices
    public function test_readme_features_section_lists_ten_discovery_slices(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: README Architecture-Section verlinkt architecture.md + listet 5 Layer
    public function test_readme_architecture_section_links_spec_and_lists_layers(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Card 1 (Connection) liest sc_health Transient, kein Live-API-Call
    public function test_dashboard_card_connection_reads_health_transient(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    public function test_dashboard_card_connection_does_not_invoke_spreadconnect_client(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Card 2 (Catalog) liest SyncHistoryRepo::findLatest()
    public function test_dashboard_card_catalog_reads_latest_sync_history_row(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_dashboard_card_catalog_renders_no_sync_yet_when_repo_empty(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Card 3 (Orders) zeigt 4 State-Counts der letzten 30 Tage
    public function test_dashboard_card_orders_renders_four_state_counts_for_30d_window(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Card 4 (Webhooks) liest Subscription-Status + letzten Webhook-Log
    public function test_dashboard_card_webhooks_renders_active_subscription_count(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    public function test_dashboard_card_webhooks_renders_latest_event_received_at(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Card 5 (FailedOps) zeigt countByState('unresolved') + Severity-Hint
    public function test_dashboard_card_failed_renders_unresolved_count_with_link(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    public function test_dashboard_card_failed_renders_severity_banner_when_error_notices_present(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: Output ist escaped + Date via date_i18n + Strings i18n-gewrapped
    public function test_dashboard_output_is_properly_escaped_and_i18n_wrapped(): void
    {
        $this->markTestIncomplete('AC-13');
    }

    // AC-14: Per-Card Throwable-Catch — andere Cards rendern weiter
    public function test_dashboard_card_isolates_throwable_and_renders_fallback(): void
    {
        $this->markTestIncomplete('AC-14');
    }

    public function test_dashboard_logs_error_when_card_repo_throws(): void
    {
        $this->markTestIncomplete('AC-14');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-06-i18n-textdomain` | `load_plugin_textdomain('spreadconnect-pod', false, '<plugin>/languages')` + Stub-`.po`-Datei | i18n-Hook + File | Slice 06 garantiert Domain-Loading; Slice 46 fuellt die `.po`. |
| `slice-13-hub-page-skeleton` | `Hub\View\Dashboard` mit `render()`-Stub + 5-Card-Markup-Geruest | static class | Slice 46 ersetzt den Inhalt der Cards, NICHT das Markup-Geruest (CSS-Klassen `spreadconnect-card spreadconnect-card--{slug}` aus Slice 13 AC-8 bleiben). |
| `slice-37-failed-ops-repo` | `Failure\FailedOpsRepo::countByState(string $state): int` | repo method | Card-5-Datenquelle. Methoden-Signatur Slice 37 Provides. |
| `slice-39-failure-notifier` | `Failure\AdminNoticeStore::count(?string $severity = null): int` | repo method | Card-5-Severity-Banner-Trigger. AC-10 in Slice 39. |
| `slice-43-purge-old-logs-job` | (transitively erforderlich fuer DB-Health, da Retention-Purge bereits laeuft) | infra | Slice 46 nutzt `webhook_log` und `failed_ops` ohne Pagination — Annahme: Tabellen sind retention-bounded. |
| `slice-16-event-id-hasher` (Slice 16 Provides) | `Webhook\WebhookLogRepo::findLatest(): ?array` (Erweiterung) | repo method | Card-4-Datenquelle. Falls Methode in Slice 16 noch nicht vorhanden ist, wird sie als Provides hier eingefuehrt — siehe Constraints "Repo-Methoden-Erweiterung". |
| `slice-24-sync-catalog-job` | `Catalog\SyncHistoryRepo::findLatest(): ?array` (oder `findLatestComplete()`) | repo method | Card-2-Datenquelle. Slice 24 Provides-Tabelle erweitert (siehe Constraints). |
| `slice-18-subscription-manager` | `sc_subscriptions_status`-Transient ODER `Subscription\SubscriptionManager::getCachedStatus(): array{active:int,total:int}` | transient/method | Card-4-Datenquelle. KEIN Live-API-Call. |
| `slice-12-test-connection-ajax` | `sc_health`-Transient mit Schema `{status: 'ok'|'auth_failed'|'unknown', checked_at: int}` | transient | Card-1-Datenquelle. Slice 12 schreibt den Transient bei Test-Connection-Click. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` | i18n file | WP-Core Locale-Loader | binary `.po` (kein PHP-API-Konsum) |
| `wordpress/plugins/spreadconnect-pod/README.md` | docs | Plugin-Verzeichnis-Browser, GitHub | Markdown |
| `Hub\View\Dashboard::render()` (final implementation) | static method | `Hub\Controller::dispatch()` (Slice 13) | unveraenderte Signatur `public static function render(): void` |

> **Final Slice** — keine weiteren Consumer-Slices.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` — Vollstaendige deutsche Uebersetzung aller `__()`-Strings aus Slices 02-45. Pflicht-Header (Project-Id-Version, Language=de_DE, Charset=UTF-8, Plural-Forms, X-Domain). Glossar-konsistente Uebersetzungen (siehe AC-3 Tabelle). Keine leeren `msgstr`-Eintraege fuer User-facing Strings. Datei ersetzt den Stub aus Slice 06.
- [ ] `wordpress/plugins/spreadconnect-pod/README.md` — Plugin-Documentation mit 7 H2-Sections (Overview, Setup, Features, Architecture, Development, Troubleshooting, License) gemaess AC-4 bis AC-7. Englisch (Plugin-Source-Language); enthaelt relative Links auf `architecture.md` und `discovery.md`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Dashboard.php` — Ersetzt die 5 Card-Platzhalter aus Slice 13 durch echte Aggregat-Reads aus den Repos/Transients (AC-8 bis AC-12). Per-Card-Throwable-Catch (AC-14). Markup-Geruest (`spreadconnect-card spreadconnect-card--{slug}`) und Card-Reihenfolge bleiben unveraendert (Slice 13 AC-8). Strings via `__()` mit Domain `spreadconnect-pod`; Output via `esc_html`/`esc_url`/`esc_attr`/`date_i18n` (AC-13).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-46-i18n-readme-dashboard.php` wird vom Test-Writer-Agent erstellt. Falls die Repos `WebhookLogRepo::findLatest`, `SyncHistoryRepo::findLatest`, `SubscriptionManager::getCachedStatus` in den Vorgaenger-Slices nicht als Provides definiert wurden, MUSS der Implementer sie als kleine read-only-Methoden in den jeweiligen Repo-Dateien ergaenzen (siehe Constraints "Repo-Methoden-Erweiterung"). Diese Erweiterungen sind reine Read-Side-Additions ohne State-Mutation.

---

## Constraints

**Scope-Grenzen:**
- KEINE neue UI-Page, kein neuer Settings-Tab — nur Dashboard-Card-Inhalte.
- KEIN Live-API-Call von `Dashboard::render()` aus (AC-8): Connection-Status kommt aus `sc_health`-Transient (gefuellt von Slice 12), nicht aus `SpreadconnectClient::authenticate()`.
- KEIN Multi-Locale-Support — nur `de_DE` ist im MVP enthalten (architecture.md Z. 779: "Single-locale at first").
- KEINE `.mo`-Datei generieren — `msgfmt` ist Build-Step (Plugin-Distribution); MVP shippt nur `.po`. WP laedt `.po` per Plural-Forms zur Runtime; falls `.mo` benoetigt, ist es ein Backlog-Item.
- KEIN README-Inhalt-Echo aus `architecture.md`/`discovery.md`/`wireframes.md` — nur Verlinkung (AC-7).
- KEIN Settings-UI fuer Locale-Switch (WP-Core uebernimmt via `WPLANG`/`get_user_locale`).
- KEINE Performance-Caching-Schicht fuer Dashboard-Counts — Card-3 (Orders 30d Window) ist akzeptierter Single-Query-Cost (`idx_state_op_type` deckt Filter, ~30-Tage-Slice ist klein). Falls > 100ms wird, Backlog: Transient-Caching.
- KEIN Filter/Search/Pagination fuer Dashboard — Cards sind Read-Only-Aggregate.
- KEIN Health-Check via REST-Route `/wp-json/spreadconnect/v1/health` (architecture.md Z. 133) — diese Route ist out-of-scope hier; Card 1 nutzt nur den Transient.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in `Dashboard.php` (bleibt aus Slice 13).
- `Hub\View\Dashboard` als `final class` mit `public static function render(): void` (Slice-13-Signatur unveraendert).
- Card-Daten-Resolution-Reihenfolge: Try Repo/Transient -> bei Throwable -> "Daten nicht verfuegbar"-Fallback + WC-Logger-Eintrag (AC-14). Pro Card EIN try/catch.
- `.po`-Format: Standard GNU-Gettext mit `msgid`/`msgstr`-Paaren, Header als erster `msgid ""`-Block. Eine Zeile `#:` mit Source-Reference pro String (auto-generated by `wp i18n make-pot`, dann manuell uebersetzt).
- README.md ist **English** (Plugin-Source-Sprache; Plugin-Header `Description` ist English laut Slice 02). Deutsche Uebersetzung der UI-Strings ist Sache der `.po`.
- README-Verweise auf `architecture.md`/`discovery.md` sind **relative paths** (`../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md`). KEINE absoluten URLs (Repo-portable).
- Card-3-Query MUSS HPOS-aware sein (`wc_get_orders` mit `meta_query` ODER `OrdersTableDataStore`). KEIN direkter `wp_postmeta`-Read fuer Order-Meta (HPOS bricht das).
- Date-Formatting via `date_i18n(get_option('date_format'), $timestamp)` — niemals `date()` (Locale-blind).
- Card-Markup unveraendert von Slice 13: `<div class="spreadconnect-card spreadconnect-card--{slug}"><h2>{title}</h2><div class="spreadconnect-card-body">{content}</div></div>`. Slice 46 fuellt nur den `.spreadconnect-card-body`-Inhalt.
- Repo-Konstruktion in `render()` inline (analog Slice 13 / 28 / 37): `$repo = new \SpreadconnectPod\Failure\FailedOpsRepo();`. KEIN DI-Container.
- `wc_get_logger()` Source `spreadconnect-failure` fuer Card-Fehler-Logs (AC-14) — analog Slice 39 Source-Whitelist.
- `i18n-extract`-Tool (AC-1): `wp i18n make-pot` erzeugt `.pot`; CI-Job vergleicht `.pot` vs `.po` (kein Test im Sinne von PHPUnit, sondern Static-Diff via PHPUnit-`file_get_contents` + Regex). Test-Datei parst BEIDE Files und liefert `array_diff` der msgid-Sets.

**Repo-Methoden-Erweiterung (falls in Vorgaenger-Slices nicht als Provides vorhanden):**

Falls die folgenden Read-Methoden in den Vorgaenger-Slices nicht als Provides definiert sind, MUSS der Implementer sie minimal hinzufuegen — die Edits gehoeren NICHT in Slice-46-Deliverables, sondern als kleine Inline-Erweiterungen der jeweiligen Repo-Files. Da der Slice-Writer hier den Implementer fragen muss: Implementer prueft Provides der Vorgaenger und ergaenzt **nur** wenn fehlend.

| Methode | Datei | Signatur | Verwendung |
|---|---|---|---|
| `Webhook\WebhookLogRepo::findLatest` | `includes/Webhook/WebhookLogRepo.php` (Slice 16) | `public function findLatest(): ?array` — Single-Row-Query mit `ORDER BY received_at DESC LIMIT 1`. | Card 4 |
| `Catalog\SyncHistoryRepo::findLatest` | `includes/Catalog/SyncHistoryRepo.php` (Slice 23/24) | `public function findLatest(): ?array` — Single-Row mit `WHERE state='complete' ORDER BY started_at DESC LIMIT 1`. | Card 2 |
| `Subscription\SubscriptionManager::getCachedStatus` | `includes/Subscription/SubscriptionManager.php` (Slice 18) | `public function getCachedStatus(): array{active:int,total:int}` — liest `sc_subscriptions_status`-Transient; bei Miss returns `['active'=>0,'total'=>7]`. | Card 4 |

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` (Slice 06 Stub) | **Replace, nicht append** — Stub ist leer, Slice 46 schreibt vollstaendige Datei. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Dashboard.php` (Slice 13) | **Edit** — Card-Body-Inhalte ersetzen; Markup-Geruest (Card-Reihenfolge, CSS-Klassen, H2-Titel) bleibt. |
| `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php` (Slice 37) | **Import**, NICHT modifizieren — `countByState('unresolved')` aus Slice 37 Provides. |
| `wordpress/plugins/spreadconnect-pod/includes/Failure/AdminNoticeStore.php` (Slice 39) | **Import**, NICHT modifizieren — `count('error')` aus Slice 39 Provides (AC-10). |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` (Slice 16) | **Import + ggf. minimaler Add** von `findLatest()` (siehe Repo-Methoden-Erweiterung). |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` (Slice 23/24) | **Import + ggf. minimaler Add** von `findLatest()`. |
| `wordpress/plugins/spreadconnect-pod/includes/Subscription/SubscriptionManager.php` (Slice 18) | **Import + ggf. minimaler Add** von `getCachedStatus()`. |
| Transient-Keys `sc_health` (Slice 12) und `sc_subscriptions_status` (Slice 18) | **Read-Only-Konsum** via `get_transient()`. |
| Discovery-Slice-Tabelle aus `slim-slices.md` Z. 1-15 (Slice-Liste) | **Single Source of Truth** fuer README `## Features`-Section (10 Discovery-Slices). |

**Referenzen:**
- Architecture: `architecture.md` -> i18n-Section (Z. 540, Z. 645, Z. 762, Z. 779) — Single-Locale, English-Source, `de_DE.po`-Translation.
- Architecture: `architecture.md` -> Operational Visibility (Z. 686) — Hub-Dashboard zeigt Connection/Subs/Failed-Ops Counts via Aggregate-Queries auf `idx_state_op_type`/`idx_state_started_at`.
- Architecture: `architecture.md` -> Failed-Ops-Index (Z. 208) — `idx_state_op_type` deckt Card-5-Query.
- Architecture: `architecture.md` -> WP Options Z. 335-340 — Retention-Defaults, Notify-Flags (READ-only fuer Dashboard).
- Discovery: `discovery.md` -> Slice 7 "Hub-Page Skeleton + Settings" Cards-Spec (Z. 247-253) — Card-Reihenfolge + Inhalt.
- Discovery: `discovery.md` -> Slice 10 "Logs + Polish" (Z. 931) — Done-Signal: i18n-Strings + de_DE.po + README.
- Slim-Slices: `slices/slim-slices.md` -> Slice-46-Eintrag (Z. 660-668).
- Vorgaenger Slice 13: `slices/slice-13-hub-page-skeleton.md` -> Dashboard-Stub-Markup + 5-Card-Reihenfolge (AC-8 dort).
- Vorgaenger Slice 39: `slices/slice-39-failure-notifier.md` -> `AdminNoticeStore::count(?string $severity = null)` (AC-10 dort) — Provides fuer Card-5-Severity-Banner.
- Vorgaenger Slice 43: `slices/slice-43-purge-old-logs-job.md` -> Retention-Purge sichert DB-Bounded-Tabellen (Annahme fuer Card-4 ohne Pagination).
- Vorgaenger Slice 06: `slices/slice-06-i18n-textdomain.md` -> Textdomain `spreadconnect-pod` + Stub-`.po`-Datei.
