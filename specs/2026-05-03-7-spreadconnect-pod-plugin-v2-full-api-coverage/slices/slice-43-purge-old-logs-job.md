# Slice 43: Purge-Old-Logs-Recurring-Job

> **Slice 43 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-43-purge-old-logs-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-42-logs-ui-wc-logger-adapter"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork + Action-Scheduler) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Aliases fuer `as_schedule_recurring_action`, `as_next_scheduled_action`, `as_unschedule_action`, `add_action`, `get_option`, `current_time`; `wpdb`-Spy via in-memory-Stub mit `query`/`prepare`/`prefix`/`rows_affected`; `WcLoggerAdapter::info` via Brain\Monkey-Functions-Spy) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `Tools -> Scheduled Actions` zeigt `spreadconnect/purge_old_logs` als recurring/daily; manuelles Triggern loescht Rows `>retention` aus beiden Tabellen) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (kein echter `wpdb`/AS — Brain\Monkey + `wpdb`-Stub mit Spy auf `query()` zur SQL-Verifikation; AS-Funktionen als Function-Spies) |

---

## Ziel

Ergaenzt das Plugin um den daily Recurring-Job `spreadconnect/purge_old_logs`, der Rows in `wp_spreadconnect_webhook_log` und `wp_spreadconnect_failed_ops` aelter als ihre jeweilige Retention-Setting (`spreadconnect_webhook_log_retention_days` bzw. `spreadconnect_failed_ops_retention_days`, Default `90`) loescht. Schliesst Discovery-Slice 10 "Auto-Purge-Cron" ab und verhindert DB-Bloat (Architecture Risk Z. 738).

---

## Acceptance Criteria

1) **GIVEN** Plugin-Aktivierung (Hook aus `Bootstrap\Plugin::onActivate()`) wird ausgeloest
   **WHEN** der Activate-Code prueft `as_next_scheduled_action('spreadconnect/purge_old_logs')`
   **THEN** wird bei Rueckgabe `false` genau einmal `as_schedule_recurring_action(time(), DAY_IN_SECONDS, 'spreadconnect/purge_old_logs', [], 'spreadconnect')` aufgerufen; bei wiederholtem Activate (`as_next_scheduled_action` liefert Timestamp) erfolgt **kein** zweiter Schedule (idempotent).

2) **GIVEN** Plugin-Bootstrap (`Bootstrap\Plugin::init()`)
   **WHEN** der Action-Hook registriert wird
   **THEN** ist `add_action('spreadconnect/purge_old_logs', [Logging\PurgeOldLogsJob::class, 'handle'])` aktiv; Hook-Group ist `spreadconnect` (sichtbar unter `Tools -> Scheduled Actions`).

3) **GIVEN** Option `spreadconnect_webhook_log_retention_days` = `90` und Tabelle `wp_spreadconnect_webhook_log` enthaelt drei Rows mit `received_at` = `now-91d`, `now-90d`, `now-89d`
   **WHEN** `Logging\PurgeOldLogsJob::handle()` ausgefuehrt wird
   **THEN** wird genau ein DELETE auf `wp_spreadconnect_webhook_log` ausgefuehrt mit Where-Clause `received_at < {cutoff}` (cutoff = `now - 90 days`); die `now-91d`-Row wird geloescht, die `now-90d`- und `now-89d`-Rows bleiben (`<` strikt, NICHT `<=`).

4) **GIVEN** Option `spreadconnect_failed_ops_retention_days` = `90` und Tabelle `wp_spreadconnect_failed_ops` enthaelt drei Rows mit `created_at` = `now-91d`, `now-90d`, `now-89d`
   **WHEN** `handle()` ausgefuehrt wird
   **THEN** wird genau ein DELETE auf `wp_spreadconnect_failed_ops` ausgefuehrt mit Where-Clause `created_at < {cutoff}`; nur die `now-91d`-Row wird geloescht.

5) **GIVEN** beide Retention-Options sind unterschiedlich gesetzt (`webhook_log_retention_days=30`, `failed_ops_retention_days=180`)
   **WHEN** `handle()` ausgefuehrt wird
   **THEN** verwendet jedes der zwei DELETE-Statements **seinen eigenen** Cutoff (30d fuer `webhook_log`, 180d fuer `failed_ops`); kein Cross-Use der Retention-Werte.

6) **GIVEN** eine Retention-Option fehlt (`get_option` liefert `false`/`''`/`null`)
   **WHEN** `handle()` den Cutoff berechnet
   **THEN** wird der Architecture-Default `90` (architecture.md Z. 339-340) verwendet; Cutoff-Berechnung darf nicht in `0`/PHP-warning enden.

7) **GIVEN** ein Retention-Wert ausserhalb des erlaubten Bereichs (`<7` oder `>365`, vgl. architecture.md Z. 339-340 Validation-Spalte)
   **WHEN** `handle()` aufgerufen wird
   **THEN** wird der Wert auf `[7..365]` geclamped, bevor der Cutoff berechnet wird (defensiv: Settings-Validator verhindert das schon, aber Job ist robust gegen direkt-geschriebene Options).

8) **GIVEN** alle Tabellen-Bezeichner werden in SQL gebildet
   **WHEN** das DELETE-Statement zusammengesetzt wird
   **THEN** wird der Tabellenname via `$wpdb->prefix . 'spreadconnect_webhook_log'` bzw. `$wpdb->prefix . 'spreadconnect_failed_ops'` gebildet (KEIN Hardcode `wp_`); der Cutoff-Wert wird via `$wpdb->prepare(..., $cutoff)` gebunden — KEINE String-Konkatenation der Datums-Werte.

9) **GIVEN** `handle()` schliesst beide DELETEs erfolgreich ab
   **WHEN** das Resultat geloggt wird
   **THEN** wird genau ein `WcLoggerAdapter::info`-Call mit Source `spreadconnect-failure` (siehe slice-42 Source-Whitelist) abgesetzt; Context enthaelt `webhook_log_deleted` (int aus `$wpdb->rows_affected`/`query()`-Return), `failed_ops_deleted` (int), `webhook_log_cutoff` (ISO-Timestamp), `failed_ops_cutoff` (ISO-Timestamp).

10) **GIVEN** `$wpdb->query()` liefert `false` (DB-Fehler) fuer das webhook_log-DELETE
    **WHEN** `handle()` weiterlaeuft
    **THEN** wird der Fehler via `WcLoggerAdapter::error` (Source `spreadconnect-failure`) geloggt mit `last_error`-Context; das **zweite** DELETE (failed_ops) wird trotzdem versucht (Best-Effort, keine Exception ueber Hook-Boundary). Job-Hook hat `no retry` (Architecture Z. 556) — Wiederholung erfolgt am naechsten Tag durch das recurring Schedule.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Eine Test-Datei. Brain\Monkey-Setup mit `Functions\when('get_option')`, `Functions\expect('as_schedule_recurring_action')`/`as_next_scheduled_action`, `wpdb`-Stub-Klasse mit `prefix='wp_'`, `prepare()`-Echo, `query()`-Spy + `rows_affected`-Setter; `WcLoggerAdapter::info`/`error` als Brain\Monkey-Function-Spy (oder Test-Subclass mit Recording-Array).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-43-purge-old-logs-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class PurgeOldLogsJobTest extends TestCase
{
    // AC-1: Activate registriert Recurring-Action genau einmal
    public function test_activate_schedules_recurring_action_when_not_yet_scheduled(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Re-Activate plant nicht doppelt (Idempotenz)
    public function test_activate_is_idempotent_when_action_already_scheduled(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Action-Hook ist auf handle() registriert mit Group spreadconnect
    public function test_action_hook_is_registered_on_init(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: webhook_log DELETE mit cutoff = now-90d, strikt <
    public function test_handle_deletes_webhook_log_rows_older_than_retention(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Boundary-Row at exactly retention-cutoff bleibt erhalten
    public function test_handle_keeps_webhook_log_rows_at_or_younger_than_cutoff(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: failed_ops DELETE mit eigenem cutoff
    public function test_handle_deletes_failed_ops_rows_older_than_retention(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Zwei verschiedene Retention-Werte -> zwei verschiedene Cutoffs
    public function test_handle_uses_independent_cutoffs_per_table(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Fehlende Option faellt auf Default 90 zurueck
    public function test_handle_falls_back_to_default_retention_when_option_missing(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Out-of-range Retention wird auf [7..365] geclamped
    public function test_handle_clamps_retention_value_into_allowed_range(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: SQL nutzt $wpdb->prefix + prepare() (kein Hardcode, kein Concat)
    public function test_handle_uses_wpdb_prefix_and_prepare_for_sql_construction(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Erfolgreicher Run loggt info mit deleted-Counts + Cutoff-Timestamps
    public function test_handle_logs_info_with_deletion_counters_on_success(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: webhook_log-Fehler stoppt failed_ops-DELETE NICHT
    public function test_handle_continues_failed_ops_purge_when_webhook_log_query_fails(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: Fehler-Pfad loggt error mit last_error
    public function test_handle_logs_error_when_wpdb_query_returns_false(): void
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
| `slice-04-schema-dbdelta` | Tabellen `wp_spreadconnect_webhook_log` (mit `received_at`-Spalte + `idx_received_at`) und `wp_spreadconnect_failed_ops` (mit `created_at`-Spalte + `idx_created_at`) | DB-Schema | Architecture Z. 210, Z. 229 (beide Indexes existieren explizit "for retention purge"). |
| `slice-05-options-defaults` | Options `spreadconnect_webhook_log_retention_days` und `spreadconnect_failed_ops_retention_days` (Default `90`, Range `7..365`) | WP-Option | architecture.md Z. 339-340. |
| `slice-42-logs-ui-wc-logger-adapter` | `Logging\WcLoggerAdapter::info(string $source, string $message, array $context = []): void` und `::error(...)` | static method | Source-Whitelist enthaelt `spreadconnect-failure` (siehe slice-42 Sources-Tabelle). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::onActivate()` Activate-Hook + `Bootstrap\Plugin::init()` Bootstrap-Hook | static method | Edit fuegt Activate-Schedule + `add_action`-Wiring ein. |
| WordPress core / Action-Scheduler | `as_schedule_recurring_action`, `as_next_scheduled_action`, `add_action`, `get_option`, `current_time('mysql', true)` (oder `gmdate()`), `DAY_IN_SECONDS` | function/constant | Brain\Monkey-Aliases im Test-Bootstrap. |
| WordPress DB | `$wpdb` global mit `prefix`, `prepare()`, `query()`, `rows_affected`, `last_error` | object | wpdb-Stub im Test. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Logging\PurgeOldLogsJob::handle(): void` | static method | Action-Scheduler-Hook `spreadconnect/purge_old_logs` (recurring daily) | `public static function handle(): void` — keine Parameter (Hook ohne Args). |
| Recurring-Action-Schedule fuer `spreadconnect/purge_old_logs` (DAY_IN_SECONDS, Group `spreadconnect`) | infra registration | Hub `Tools -> Scheduled Actions` (operative Sichtbarkeit); kein direkter Code-Consumer | n/a |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Logging/PurgeOldLogsJob.php` — `final class SpreadconnectPod\Logging\PurgeOldLogsJob` mit `public static function handle(): void`. Liest beide Retention-Options (Default `90`, Clamp `7..365`), berechnet pro Tabelle einen eigenen Cutoff (`now - retention days` in UTC `Y-m-d H:i:s`), fuehrt zwei separate `$wpdb->query($wpdb->prepare(...))`-DELETEs aus (Tabellen via `$wpdb->prefix`-Konkat), loggt Erfolg/Fehler ueber `WcLoggerAdapter` (Source `spreadconnect-failure`). Best-Effort: erster DB-Fehler stoppt zweiten DELETE NICHT.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Mount-Points: (a) Activate-Hook ergaenzt um `if (false === as_next_scheduled_action('spreadconnect/purge_old_logs')) { as_schedule_recurring_action(time(), DAY_IN_SECONDS, 'spreadconnect/purge_old_logs', [], 'spreadconnect'); }` (idempotent); (b) `init()`-Body ergaenzt um `add_action('spreadconnect/purge_old_logs', [Logging\PurgeOldLogsJob::class, 'handle'])`. Bestehende Hook-Registrierungen (Slices 17/23/24/25/28/29/31/36) bleiben unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-43-purge-old-logs-job.php` aus den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Nur DB-Rows-Purge** in `wp_spreadconnect_webhook_log` und `wp_spreadconnect_failed_ops`. **Kein** Purge von `wp_spreadconnect_sync_history` (keine Retention-Setting in Discovery/Architecture; sync_history ist klein und wird manuell verwaltet).
- **Kein File-Purge** der `wc-logs/`-Files (WC-Standard 30 Tage retention via `WC_Log_Handler_File`; Slice 42 Constraint).
- **Kein** Purge von Action-Scheduler-Eintraegen (`actionscheduler_*`-Tables) — AS self-purges complete jobs nach 30 Tagen by default (Architecture Z. 738).
- **Keine UI-Anbindung** in dieser Slice (kein Manual-Purge-Button); Logs-/Webhook-/Failed-Ops-UIs zeigen nur den aktuellen Retention-Wert (Slice 41 AC-11 zeigt `spreadconnect_webhook_log_retention_days` als Hint).
- **Keine Pagination/Batch-Loop** — single DELETE-Statement pro Tabelle. Bei extrem grossen Tabellen kann das spaeter zu Batched-Delete erweitert werden; MVP nutzt simples DELETE auf indexed `received_at`/`created_at`.
- **Kein Retry-Wrapping** — Job ist `no retry` per Architecture Z. 556 (idempotent: nichts schadet, wenn am naechsten Tag erneut). Ein Tagesausfall ist akzeptabel.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in der neuen Datei.
- `Logging\PurgeOldLogsJob` als `final class` mit ausschliesslich `static` Methoden (Action-Scheduler-Hook erwartet callable).
- Action-Hook-Name **exakt** `spreadconnect/purge_old_logs` (Architecture Z. 556, slim-slices Z. 628).
- Recurring-Interval **exakt** `DAY_IN_SECONDS` (Architecture Z. 556 "Recurring (daily)"); kein Unterstunden-Polling.
- AS-Group **exakt** `spreadconnect` (alle Plugin-Hooks gemeinsame Group, Architecture Z. 558).
- Cutoff-Format MUSS UTC sein (`gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS)`); `received_at`/`created_at` werden in UTC geschrieben (WP-Convention via `current_time('mysql', true)`).
- SQL-Tabellennamen via `$wpdb->prefix . 'spreadconnect_webhook_log'` bzw. `'spreadconnect_failed_ops'`. **Kein** `wp_`-Hardcode (Multisite-/Custom-Prefix-Bruch).
- SQL-Werte (Cutoff-Datum) via `$wpdb->prepare('DELETE FROM `%i` WHERE received_at < %s', $table, $cutoff)` ODER `$wpdb->prepare("DELETE FROM `{$table}` WHERE received_at < %s", $cutoff)` (Tabellenname ist intern gebauter String, nicht User-Input — das ist akzeptabel; der Cutoff-Wert MUSS jedoch `%s`-bound sein).
- Logging ueber `WcLoggerAdapter` (Slice 42) mit Source `spreadconnect-failure`. **Keine** `error_log()`-Calls (Slice 42 AC-10 lint).
- Defensive Clamp: `max(7, min(365, (int)$value))` — auch wenn Settings-Validator das schon tut, ist der Job gegen direkt-geschriebene Options robust.
- `as_next_scheduled_action`-Check vor `as_schedule_recurring_action` ist **Pflicht** — Re-Activate darf NICHT doppelt schedulen.
- `handle()` darf KEINE Exception ueber die Hook-Boundary werfen (AS wuerde retryen, Architecture Z. 556 sagt `no retry`); DB-Fehler werden geloggt und geschluckt.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Logging/WcLoggerAdapter.php` (Slice 42) | **Import**, NICHT modifizieren — Source-string `spreadconnect-failure` aus Whitelist; `info()`/`error()`-Convenience-Methoden konsumieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + spaetere Edits) | **Edit** — Activate-Pfad + `init()`-Hook-Registrierung. Bestehende Hook-Registrierungen unveraendert. |
| Tabellen `wp_spreadconnect_webhook_log` + `wp_spreadconnect_failed_ops` (Slice 04) | **Reuse** — Schema + Indexes (`idx_received_at`, `idx_created_at`) sind explizit fuer Retention-Purge angelegt (Architecture Z. 210, Z. 229). |
| Options `spreadconnect_webhook_log_retention_days` + `spreadconnect_failed_ops_retention_days` (Slice 05) | **Reuse** — read-only via `get_option(..., 90)` (Default-Fallback gegen fehlende Initialisierung). |
| Action-Scheduler (WC-bundled) | **Reuse** — `as_schedule_recurring_action`/`as_next_scheduled_action`; keine eigene Cron-Logik. |

**Referenzen:**
- Architecture: `architecture.md` -> AS Hook Inventory `spreadconnect/purge_old_logs` (Z. 556); Risk "DB bloat" + Mitigation (Z. 738); Options `*_retention_days` (Z. 339-340); Tabellen-Indexes `idx_received_at`/`idx_created_at` "for retention purge" (Z. 210, Z. 229); AS-Group `spreadconnect` (Z. 558).
- Discovery: `discovery.md` -> Slice 10 "Logs + Polish" (Z. 308-317, Auto-Purge-Cron); Custom Action `spreadconnect/purge_old_logs` (Z. 889); Retention-Options-Defaults (Z. 680-681).
- Slim-Slices: `slices/slim-slices.md` -> Slice-43-Eintrag (Z. 627-634, Done-Signal `>90 Tage geloescht, juengere bleiben`).
- Vorgaenger Slice 42: `slices/slice-42-logs-ui-wc-logger-adapter.md` -> Source-Whitelist (Sources-Tabelle, `spreadconnect-failure`); Provides-To `WcLoggerAdapter::info/error`.
- Vorgaenger Slice 04: `slices/slice-04-schema-dbdelta.md` -> Tabellen-Definitionen + Indexes.
- Vorgaenger Slice 05: `slices/slice-05-options-defaults.md` -> Retention-Options-Defaults (Default `90`, Range `7..365`).
- Vorgaenger Slice 18: `slices/slice-18-subscription-manager.md` -> Vorbild-Pattern fuer `as_next_scheduled_action`-Idempotenz-Check vor `as_schedule_recurring_action` (AC-9 dort).
