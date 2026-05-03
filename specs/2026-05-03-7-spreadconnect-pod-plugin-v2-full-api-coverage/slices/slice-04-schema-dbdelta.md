# Slice 04: Schema + dbDelta-Activator

> **Slice 4 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-schema-dbdelta` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: Plugin aktivieren -> `SHOW TABLES LIKE 'wp_spreadconnect_%'` zeigt 3 Tables) |
| **Health Endpoint** | `n/a` (Schema-Setup, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `dbDelta`, `register_activation_hook`, `$wpdb`; keine echte DB-I/O im Test) |

---

## Ziel

Errichtet die persistente Datenstruktur des Plugins: `Bootstrap\Schema` kapselt `dbDelta()`-basierte CREATE-Statements fuer drei Custom-Tables (`failed_ops`, `webhook_log`, `sync_history`) und ein passendes `Schema::uninstall()` mit `DROP TABLE`-Sequenz. Aktivierung wird ueber `register_activation_hook` (im Plugin-Bootstrap) angestossen, Cleanup ueber `uninstall.php`. Damit koennen ab Slice 16 (`WebhookLogRepo`), Slice 23 (`SyncHistoryRepo`) und Slice 37 (`FailedOpsRepo`) auf eine vorhandene, indizierte Tabellenstruktur schreiben.

---

## Acceptance Criteria

1) **GIVEN** ein neu installiertes WordPress mit aktivem `$wpdb`-Prefix (z. B. `wp_`)
   **WHEN** die Klasse `SpreadconnectPod\Bootstrap\Schema::install()` aufgerufen wird
   **THEN** ruft sie `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` und delegiert an `dbDelta()` mit **genau drei** CREATE-TABLE-Statements: `{$wpdb->prefix}spreadconnect_failed_ops`, `{$wpdb->prefix}spreadconnect_webhook_log`, `{$wpdb->prefix}spreadconnect_sync_history`. Der `$wpdb->get_charset_collate()`-Suffix ist an jedem CREATE-Statement angefuegt.

2) **GIVEN** die drei generierten CREATE-Statements
   **WHEN** sie an `dbDelta()` uebergeben werden
   **THEN** entsprechen Spalten-Definitionen, Constraints und Indexes exakt der Schema-Tabelle in `architecture.md` -> "Database Schema" -> "Schema Details" (Spalten-Typen, NOT NULL, DEFAULT-Werte, Enum-Strings) — inklusive der Pflicht-Indexes:
   - `failed_ops`: `idx_state_op_type (state, op_type)`, `idx_related_entity (related_entity_type, related_entity_id)`, `idx_created_at (created_at)`
   - `webhook_log`: `uniq_event_id (event_id) UNIQUE`, `idx_received_at (received_at)`, `idx_related_entity (related_entity_type, related_entity_id, received_at)`, `idx_processing_status (processing_status)`
   - `sync_history`: `idx_state_started_at (state, started_at)`, `idx_started_at (started_at)`

3) **GIVEN** die Methode `Schema::install()` wurde bereits einmal erfolgreich aufgerufen
   **WHEN** sie ein zweites Mal aufgerufen wird (Re-Activation des Plugins)
   **THEN** ist der Aufruf **idempotent** — `dbDelta()` wird erneut mit identischer SQL aufgerufen (kein eigener Existenz-Check noetig, dbDelta ist additive-only) und wirft keinen Fehler. Es werden weder Daten geloescht noch Indexes neu angelegt.

4) **GIVEN** die Datei `wordpress/plugins/spreadconnect-pod/uninstall.php` (aus Slice 02) wurde um `Schema::uninstall()` erweitert
   **WHEN** WordPress die Datei mit gesetztem `WP_UNINSTALL_PLUGIN` laedt
   **THEN** ruft sie `\SpreadconnectPod\Bootstrap\Schema::uninstall()` auf, das per `$wpdb->query()` **drei** `DROP TABLE IF EXISTS {$wpdb->prefix}spreadconnect_*`-Statements absetzt (eines je Custom-Table). Reihenfolge ist beliebig (keine FK-Constraints zwischen den Tabellen).

5) **GIVEN** die Klasse `SpreadconnectPod\Bootstrap\Plugin::init()` aus Slice 02
   **WHEN** Slice 04 abgeschlossen ist
   **THEN** registriert `Plugin::init()` einen `register_activation_hook( $plugin_file, [ Schema::class, 'install' ] )`-Aufruf. Der Hook wird genau einmal pro `init()`-Lauf registriert (Idempotenz aus Slice 02 AC-5 bleibt unangetastet).

6) **GIVEN** die statische Methode `Schema::install()`
   **WHEN** sie aufgerufen wird, aber `wp-admin/includes/upgrade.php` ist noch nicht geladen
   **THEN** laedt die Methode die Datei selbststaendig via `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` (analog zum Image-Sideloader-Pattern in Slice 21). Bei bereits geladener Datei findet kein Re-Require statt (Standard-`require_once`-Semantik).

7) **GIVEN** die Methode `Schema::uninstall()` wird ohne `WP_UNINSTALL_PLUGIN`-Konstante aufgerufen
   **WHEN** der Aufruf ausserhalb des WP-Uninstall-Lifecycles erfolgt
   **THEN** prueft die Methode **nicht** selbst auf den Konstanten-Guard — die Pruefung verbleibt im aufrufenden `uninstall.php` (Single Responsibility; Slice 02 AC-6 hat den Guard bereits eingerichtet). `Schema::uninstall()` ist eine reine DROP-Sequenz und kann auch von Test-Setups aufgerufen werden.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `dbDelta`, `register_activation_hook`, `ABSPATH`-Konstante, sowie das `$wpdb`-Globals (`$wpdb->prefix='wp_'`, `$wpdb->get_charset_collate()` -> `'DEFAULT CHARSET=utf8mb4'`, `$wpdb->query()`). Test-Writer arbeitet mit Brain\Monkey `Functions\expect('dbDelta')->once()->with(Mockery::on($matcher))` und kann SQL-Fragmente per Regex-Matcher auf erwartete CREATE-Statements pruefen. Patchwork redefined `require_once` ist nicht noetig — stattdessen `WP_Mock`-Style Funktions-Stubs auf `ABSPATH`-Pfad.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-schema-dbdelta.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class SchemaDbDeltaTest extends TestCase
{
    // AC-1: Schema::install() ruft dbDelta() mit 3 CREATE-Statements (failed_ops, webhook_log, sync_history)
    public function test_install_calls_dbdelta_with_three_create_statements(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Charset-Collate-Suffix wird an jedes CREATE-Statement angehaengt
    public function test_install_appends_charset_collate_to_each_create(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: failed_ops-CREATE enthaelt alle Pflicht-Indexes (idx_state_op_type, idx_related_entity, idx_created_at)
    public function test_failed_ops_create_contains_required_indexes(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: webhook_log-CREATE enthaelt UNIQUE uniq_event_id + 3 Indexes (received_at, related_entity, processing_status)
    public function test_webhook_log_create_contains_unique_and_indexes(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: sync_history-CREATE enthaelt idx_state_started_at und idx_started_at
    public function test_sync_history_create_contains_required_indexes(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Spalten-Typen entsprechen architecture.md (BIGINT UNSIGNED PK, CHAR(64), DATETIME, LONGTEXT etc.)
    public function test_column_types_match_architecture_schema(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Wiederholter Aufruf von Schema::install() ist idempotent (zwei dbDelta-Calls, keine Exception)
    public function test_install_is_idempotent_on_repeated_calls(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: uninstall.php ruft Schema::uninstall(); 3 DROP TABLE IF EXISTS Statements abgesetzt
    public function test_uninstall_drops_all_three_tables(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: DROP-Statements nutzen {$wpdb->prefix}spreadconnect_*-Praefix (kein hardcoded 'wp_')
    public function test_uninstall_uses_wpdb_prefix(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Plugin::init() registriert register_activation_hook mit [Schema::class, 'install']
    public function test_plugin_init_registers_activation_hook_for_schema_install(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: register_activation_hook wird pro Plugin::init()-Lauf nur einmal aufgerufen
    public function test_activation_hook_registered_only_once_per_init(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: install() laedt wp-admin/includes/upgrade.php via require_once
    public function test_install_requires_upgrade_php(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Schema::uninstall() prueft NICHT selbst auf WP_UNINSTALL_PLUGIN (Guard bleibt in uninstall.php)
    public function test_schema_uninstall_has_no_internal_constant_guard(): void
    {
        $this->markTestIncomplete('AC-7');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::init( string $plugin_file ): void` | static method | Erweiterbar um Hook-Registrierungen; Idempotenz-Guard aus Slice 02 AC-5 bleibt aktiv. |
| `slice-02-plugin-bootstrap` | `Plugin::pluginFile(): string` | static getter | Liefert den `__FILE__`-Pfad fuer `register_activation_hook`. |
| `slice-02-plugin-bootstrap` | `wordpress/plugins/spreadconnect-pod/uninstall.php` | WP-Lifecycle-Stub | Datei existiert mit `WP_UNINSTALL_PLUGIN`-Guard und leerem Body, in den `Schema::uninstall()` eingefuegt wird. |
| WordPress-Core | `dbDelta()`, `$wpdb`, `register_activation_hook()` | WP-API | Standard-WP-Funktionen; im Plugin-Activate-Kontext verfuegbar. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Bootstrap\Schema::install` | static method | `slice-05-options-defaults` (Activate-Hook erweitert), `slice-20-attribute-provisioner` (Activate-Hook), `slice-43-purge-old-logs-job` | `public static function install(): void` |
| `SpreadconnectPod\Bootstrap\Schema::uninstall` | static method | `uninstall.php` | `public static function uninstall(): void` |
| Tabelle `{$wpdb->prefix}spreadconnect_failed_ops` | DB-Tabelle | `slice-37-failed-ops-repo` | Schema laut `architecture.md` -> "Database Schema" -> `wp_spreadconnect_failed_ops`. |
| Tabelle `{$wpdb->prefix}spreadconnect_webhook_log` | DB-Tabelle | `slice-16-event-id-hasher` (via `WebhookLogRepo`), `slice-41-webhook-log-ui` | Schema laut `architecture.md` -> `wp_spreadconnect_webhook_log` (UNIQUE `event_id`). |
| Tabelle `{$wpdb->prefix}spreadconnect_sync_history` | DB-Tabelle | `slice-23-sync-article-job` (via `SyncHistoryRepo`), `slice-24-sync-catalog-job`, `slice-26-catalog-sync-ui-progress` | Schema laut `architecture.md` -> `wp_spreadconnect_sync_history`. |
| `Plugin::init()` Activate-Hook-Registry | erweiterte Hook-Liste | `slice-05-options-defaults` (fuegt `OptionsDefaults::install` hinzu), `slice-20-attribute-provisioner` (fuegt `AttributeProvisioner::ensure` hinzu) | Activate-Hook ist jetzt vorhanden; weitere Slices haengen Callbacks an oder rufen Schema::install() in eigenen Activate-Listenern auf. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Schema.php` — Neue Klasse `SpreadconnectPod\Bootstrap\Schema` mit `public static function install(): void` (3x CREATE TABLE via `dbDelta`) und `public static function uninstall(): void` (3x `DROP TABLE IF EXISTS`).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — `Plugin::init()` registriert zusaetzlich `register_activation_hook( $plugin_file, [ Schema::class, 'install' ] )` (Idempotenz-Guard aus Slice 02 unveraendert).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/uninstall.php` — Body ergaenzt Aufruf `\SpreadconnectPod\Bootstrap\Schema::uninstall();` nach dem `WP_UNINSTALL_PLUGIN`-Guard aus Slice 02.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-04-schema-dbdelta.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- Keine Default-Options-Setter — `slice-05-options-defaults`.
- Keine Attribute-Taxonomien (`pa_groesse`/`pa_farbe`) — `slice-20-attribute-provisioner`.
- Keine Repos (kein `FailedOpsRepo`, `WebhookLogRepo`, `SyncHistoryRepo`) — diese kommen in Slice 16/24/37.
- Keine Migration-Versionierung (`db_version`-Option, ALTER-Pfade) — Schema gilt als initial bei v2.0.0; spaetere ALTERs koennen `dbDelta` direkt anhaengen.
- Keine Daten-Inserts (Seeding) — Tabellen werden leer angelegt.
- Kein Drop-on-Deactivate — nur Drop bei Uninstall (WP-Plugin-Konvention).
- Keine FK-Constraints zwischen Tabellen — bewusst entkoppelt (vgl. `architecture.md` -> "Relationships" -> "no FKs per WP convention").

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- `Schema` als `final class` markieren; nur statische Methoden (kein State, kein DI).
- SQL-Statements als Heredoc (`<<<SQL`) mit `{$wpdb->prefix}`-Interpolation; **niemals** hardcoded `wp_`-Praefix.
- `dbDelta` erwartet bestimmte Formatierung (siehe https://developer.wordpress.org/reference/functions/dbdelta/): zwei Spaces zwischen `PRIMARY KEY` und Spaltenname, ein Space zwischen Spalten und Typen, Keys auf separater Zeile. Implementierer muss diese Konvention beachten — sonst ist Schema-Diffing unzuverlaessig.
- Index-Namen exakt nach `architecture.md` -> "Database Schema" (z. B. `idx_state_op_type`, `uniq_event_id`) — sind in Slices 16/37 Bestandteil der Index-Hint-Tests.
- `Schema::uninstall()` nutzt `$wpdb->query()` mit `'DROP TABLE IF EXISTS '` (nicht `prepare()`, da Tabellennamen nicht ueber `%s` parametrisierbar sind und der Praefix aus `$wpdb` stammt — kein User-Input).
- Hook-Registrierung in `Plugin::init()` darf die Idempotenz aus Slice 02 AC-5 **nicht** brechen: bei Re-Entry in `init()` wird `register_activation_hook` nicht erneut aufgerufen (Static-Property-Guard).
- Keine `error_log`/`var_dump`-Calls; Logging kommt erst mit Slice 42 (`WcLoggerAdapter`). In dieser Slice ist Schema-Setup bewusst stumm (Activate-Hook-Kontext, vor Logger-Init).

**Reuse:**

Slice 04 erweitert direkt zwei bereits in Slice 02 angelegte Dateien — diese sind Source of Truth und werden **nicht** neu gebaut:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) | Edit: `Plugin::init()` um `register_activation_hook(...)` ergaenzen. Idempotenz-Guard und `pluginFile()`-Getter bleiben unveraendert (Slice 02 AC-4, AC-5). |
| `wordpress/plugins/spreadconnect-pod/uninstall.php` (Slice 02) | Edit: leerer Body wird durch `\SpreadconnectPod\Bootstrap\Schema::uninstall();` ersetzt. `WP_UNINSTALL_PLUGIN`-Guard bleibt unveraendert (Slice 02 AC-6). |
| `composer.json` (Root) | PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` aus Slice 02 wird unveraendert weiterverwendet. Keine neue Composer-Konfiguration. |

**Referenzen:**
- Architecture: `architecture.md` -> "Database Schema" -> "Schema Details" (Spalten, Constraints, Indexes der drei Tabellen) und "Relationships" (Begruendung "no FKs per WP convention").
- Architecture: `architecture.md` -> "Service Map" Zeile `Bootstrap\Plugin` (Activation-Hook-Verantwortlichkeit) und Risk-Tabelle ("dbDelta schema upgrade across versions misaligns indexes").
- Discovery: `discovery.md` -> Slice 1 "Plugin Foundation" (Activate-Hook erstellt Tables; Uninstall droppt Tables).
- Slim-Slices: `slices/slim-slices.md` -> Slice-04-Eintrag (Done-Signal: `Schema::install()` ruft `dbDelta` mit erwarteten 3 CREATE-Statements; `uninstall.php` droppt alle 3 Tables).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 04 (Schema-Setup ist UI-fern; UI fuer FailedOps/Webhooks/Sync kommt in Slice 38/41/26).
