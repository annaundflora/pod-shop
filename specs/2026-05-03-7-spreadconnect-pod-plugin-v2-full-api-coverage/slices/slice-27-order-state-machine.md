# Slice 27: Order-State-Machine (Compare-and-Set)

> **Slice 27 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-27-order-state-machine` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-04-schema-dbdelta"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC HPOS + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA optional: WP-Admin Order-Edit Meta-Box ab Slice 32) |
| **Health Endpoint** | `n/a` (Domain-Service, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey + Mockery fuer `WC_Order`-Stubs, `$wpdb->query`/`prepare`/`get_var`; keine echte DB-I/O. Patchwork redefiniert `hash_equals` falls noetig — nicht erwartet.) |

---

## Ziel

Stellt die zentrale Race-Protection-Primitive fuer Order-State-Mutationen bereit: `Order\OrderStateMachine::compareAndSet(WC_Order $order, string $expected, string $target): bool` schreibt `_spreadconnect_state` **nur dann** wenn der aktuelle Wert dem `$expected` entspricht — verhindert damit den Fall, dass ein spaeter eintreffender `Order.processed`-Webhook bereits `PROCESSED` geschrieben hat und der `OrderSubmitJob`-Erfolgspfad anschliessend mit `submitting->NEW` ueberschreiben wuerde. CAS basiert auf einem atomic-ish HPOS-Meta-Update via `$wpdb->query("UPDATE {$wpdb->prefix}wc_orders_meta SET meta_value=:target WHERE order_id=:id AND meta_key='_spreadconnect_state' AND meta_value=:expected")` und liefert `true` (1 row affected) oder `false` (0 rows). Ab Slice 28 (`OrderSubmitJob`) ist dies das einzige zulaessige Schreib-Mittel auf den State.

---

## Erlaubte States (Source of Truth: `architecture.md` -> "WC-Order Meta" -> `_spreadconnect_state`)

| State | Persistiert? | Set-by | Beschreibung |
|---|---|---|---|
| `pending` | **Nein** (= Meta nicht gesetzt) | — | Pre-Submit-Phase; vor `woocommerce_order_status_processing`-Hook. |
| `submitting` | Ja | `OrderHandler::on_processing` (Slice 28) | WC-Hook fired; `create_order`-Job enqueued, `POST /orders` in flight. |
| `NEW` | Ja | `OrderSubmitJob` 2xx-Pfad (Slice 28) — via `compareAndSet('submitting','NEW')` | SC-OrderID erhalten, Order angelegt aber nicht confirmed. |
| `CONFIRMED` | Ja | `OrderConfirmJob` (Slice 29) | `POST /orders/{id}/confirm` 2xx. |
| `PROCESSED` | Ja | Webhook `Order.processed` (Slice 30) | SC hat Produktion gestartet. **Direkt-Write** (nicht via CAS) — Webhook hat Vorrang per Discovery-Race-Tabelle. |
| `CANCELLED` | Ja | `OrderCancelMirrorJob` (Slice 31) / Webhook `Order.cancelled` (Slice 30) | SC- oder WC-Cancel synchronisiert. |
| `failed_to_submit` | Ja | `OrderSubmitJob` 4xx-Pfad (Slice 28) — via `compareAndSet('submitting','failed_to_submit')` | Permanenter Submit-Fehler; Failed-Ops-Eintrag begleitet. |
| `needs_action` | **kein Enum-Wert** | Webhook `Order.needs-action` (Slice 30) | Orthogonaler Flag via separates Meta `_spreadconnect_needs_action`; **nicht** Teil dieser CAS-Domain. |

**Domain-Validierung in dieser Slice:** `compareAndSet()` akzeptiert ausschliesslich die sechs persistenten Werte (`submitting`, `NEW`, `CONFIRMED`, `PROCESSED`, `CANCELLED`, `failed_to_submit`) als `$target` und zusaetzlich den leeren String `''` (= "kein Meta gesetzt", entspricht `pending`) als `$expected`. Andere Strings -> `InvalidArgumentException`.

---

## Acceptance Criteria

1) **GIVEN** eine WC-Order mit `_spreadconnect_state = 'submitting'`
   **WHEN** `OrderStateMachine::compareAndSet($order, 'submitting', 'NEW')` aufgerufen wird
   **THEN** liefert es `true` zurueck, fuehrt **genau einen** `$wpdb->query()`-Call mit einem `UPDATE {$wpdb->prefix}wc_orders_meta SET meta_value=...`-Statement aus, das `WHERE order_id = $order->get_id() AND meta_key = '_spreadconnect_state' AND meta_value = 'submitting'` enthaelt, und der Folgeleser via `$order->get_meta('_spreadconnect_state')` (nach `wc_get_order()`-Re-Hydration) sieht `'NEW'`.

2) **GIVEN** eine WC-Order mit `_spreadconnect_state = 'PROCESSED'` (z. B. von einem Webhook bereits geschrieben)
   **WHEN** `OrderStateMachine::compareAndSet($order, 'submitting', 'NEW')` aufgerufen wird (verspaeteter `OrderSubmitJob`-Erfolg)
   **THEN** liefert es `false`, das `UPDATE`-Statement liefert `0 rows affected`, und der State bleibt `'PROCESSED'`. Es wird **kein** `update_meta_data`/`save`-Aufruf auf der `WC_Order`-Instance ausgeloest.

3) **GIVEN** eine WC-Order **ohne** `_spreadconnect_state`-Meta (= Pre-Submit, expected `''`)
   **WHEN** `OrderStateMachine::compareAndSet($order, '', 'submitting')` aufgerufen wird
   **THEN** liefert es `true`. Implementierung muss erkennen, dass `WHERE meta_value=''` fuer einen **fehlenden** Meta-Eintrag nicht matcht — stattdessen wird ein `INSERT INTO {$wpdb->prefix}wc_orders_meta (...)` ausgefuehrt (oder `INSERT ... ON DUPLICATE KEY UPDATE` wenn UNIQUE-Index vorhanden) **conditional** auf `NOT EXISTS`-Subquery, wieder genau eine row affected. Bei bereits gesetztem Meta -> `false` (siehe AC-2 Pattern).

4) **GIVEN** ein ungueltiger `$expected`- oder `$target`-Wert (z. B. `'foo'`, `'NEEDS_ACTION'`, `null`-Cast)
   **WHEN** `OrderStateMachine::compareAndSet($order, 'submitting', 'foo')` oder `compareAndSet($order, 'invalid', 'NEW')` aufgerufen wird
   **THEN** wirft die Methode eine `InvalidArgumentException` mit Message-Praefix `OrderStateMachine: invalid state '...'`. Die Liste der akzeptierten Werte stammt aus der oben gelisteten Tabelle (sechs Persistent-States + `''` nur als `$expected`). Es wird **kein** SQL ausgefuehrt.

5) **GIVEN** eine WC-Order und ein erfolgreicher `compareAndSet(...)`-Aufruf (AC-1)
   **WHEN** der CAS commited
   **THEN** ruft die Methode zusaetzlich `$order->add_order_note( sprintf( 'Spreadconnect: state %s -> %s', $expected, $target ), false, false )` auf (private note, nicht customer-facing). Order-Note ist NICHT Teil der CAS-Atomicity, wird aber im success-pfad **nach** dem `$wpdb->query()` ausgeloest.

6) **GIVEN** eine WC-Order und einen fehlgeschlagenen CAS-Versuch (AC-2: `expected='submitting'`, aktuell `'PROCESSED'`)
   **WHEN** `compareAndSet(...)` `false` zurueckgibt
   **THEN** loggt die Methode via `wc_get_logger()` mit Source `spreadconnect-order-service` und Level `info` einen Eintrag mit Message `OrderStateMachine: CAS rejected (order_id=%d, expected=%s, target=%s, current=%s)`. Der `current`-Wert wird vorher per `get_post_meta`-aequivalentem Read (HPOS: `$order->get_meta()`) ermittelt. **Kein** `error`/`warning`-Level (es ist kein Fehler, sondern Race-Sieg eines anderen Events).

7) **GIVEN** das HPOS-Custom-Order-Tables-Feature ist aktiv (Default seit WC 8.2)
   **WHEN** `compareAndSet(...)` SQL absetzt
   **THEN** referenziert das Statement die HPOS-Tabelle `{$wpdb->prefix}wc_orders_meta` (Spalten `order_id`, `meta_key`, `meta_value`) — **nicht** `wp_postmeta`. Die Tabelle wird ueber `$wpdb->prefix` interpoliert (kein hardcoded `'wp_'`). `$wpdb->prepare()` wird fuer alle drei Parameter (`$order_id`, `$expected`, `$target`) genutzt mit `%d`/`%s`-Placeholdern.

8) **GIVEN** der `OrderStateMachine`-Service ist als Domain-Klasse markiert
   **WHEN** der Implementer Bootstrap und Container in spaeteren Slices aufbaut
   **THEN** ist `OrderStateMachine` eine `final class` mit ausschliesslich **Instance-Methoden** (kein static-only utility), per Konstruktor injizierbar mit `wpdb $wpdb` und `WC_Logger $logger` (oder Logger-Adapter ab Slice 42). In Slice 27 ist die Logger-Abhaengigkeit als Constructor-Parameter mit `?WC_Logger` typed und kann `null` sein (kein Logging dann); ab Slice 42 wird `WcLoggerAdapter` injiziert. Der Konstruktor wirft keine Exception.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey + Mockery mockt `WC_Order` (`get_id`, `get_meta`, `add_order_note`), `wpdb` (`prefix`, `prepare`, `query`, `get_var`), und `wc_get_logger`. Patchwork redefined `hash_equals` ist hier nicht noetig. Test-Writer prueft per Mockery-Argument-Matcher die SQL-Strings (Regex auf `/UPDATE\s+wp_wc_orders_meta\s+SET\s+meta_value/i` etc.) sowie `prepare`-Placeholder-Counts.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-state-machine.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    // AC-1: CAS submitting->NEW writes meta and returns true on match
    public function test_cas_submitting_to_new_succeeds_on_match(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: SQL uses HPOS table wp_wc_orders_meta with WHERE meta_value=expected
    public function test_cas_uses_hpos_table_with_where_meta_value_expected(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: CAS rejects when current state is PROCESSED (expected=submitting)
    public function test_cas_rejected_when_current_state_advanced_to_processed(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: No update_meta_data / save call on WC_Order when CAS fails
    public function test_cas_failure_does_not_call_update_meta_data(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: CAS expected='' (no meta) -> INSERT path on first transition pending->submitting
    public function test_cas_inserts_meta_when_expected_empty_and_no_row_exists(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: CAS expected='' returns false if meta already exists
    public function test_cas_with_empty_expected_returns_false_if_meta_exists(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Invalid target state throws InvalidArgumentException, no SQL executed
    public function test_invalid_target_state_throws_and_does_not_query(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Invalid expected state throws InvalidArgumentException
    public function test_invalid_expected_state_throws(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: 'needs_action' is NOT a valid state value (orthogonal flag, not enum)
    public function test_needs_action_string_is_rejected_as_state(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Successful CAS adds private order-note "state X -> Y"
    public function test_successful_cas_adds_private_order_note(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Failed CAS logs info-level entry with source spreadconnect-order-service
    public function test_failed_cas_logs_info_with_current_state(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Failed CAS does NOT log warning/error level
    public function test_failed_cas_does_not_log_warning_or_error(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: SQL uses wpdb->prefix interpolation (no hardcoded 'wp_')
    public function test_sql_uses_wpdb_prefix_interpolation(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: All three params bound via wpdb->prepare with correct placeholders
    public function test_prepare_binds_order_id_expected_target_with_placeholders(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: OrderStateMachine is final and constructor accepts wpdb + nullable logger
    public function test_class_is_final_and_constructor_accepts_optional_logger(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Constructor with null logger does not throw and CAS still works
    public function test_cas_works_without_logger_injection(): void
    {
        $this->markTestIncomplete('AC-8');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-04-schema-dbdelta` | Existenz der HPOS-Tabelle `{$wpdb->prefix}wc_orders_meta` (WC-Core, nicht Plugin-Schema) | DB-Tabelle | HPOS-Default seit WC 8.2; Plugin nutzt Tabelle nur lesend/schreibend, erstellt sie nicht. Slice 04 stellt die Plugin-eigenen Tabellen sicher; HPOS-Compat-Declare kommt aus Slice 03. |
| `slice-03-hpos-declare` | `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` registriert | Compat-Flag | Bei deaktiviertem HPOS muesste Fallback auf `wp_postmeta` greifen — **nicht** Teil dieser Slice; v2 setzt HPOS als Pflicht voraus. |
| WordPress-Core | `$wpdb`, `wc_get_order()`, `WC_Order::add_order_note()`, `WC_Order::get_meta()`, `wc_get_logger()` | WP/WC-API | Standard im Plugin-Runtime-Context. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Order\OrderStateMachine::compareAndSet` | instance method | `slice-28-order-submit-job` (`submitting->NEW`, `submitting->failed_to_submit`), `slice-29-order-confirm-cancel-jobs` (`NEW->CONFIRMED`, `NEW->CANCELLED`), `slice-31-wc-cancel-mirror` (`NEW->CANCELLED`), `slice-30-order-webhooks-handler` (Direct-Write fuer `PROCESSED` und `CANCELLED` von Webhook — kann CAS optional nutzen oder direkten `update_meta_data`-Pfad) | `public function compareAndSet( \WC_Order $order, string $expected, string $target ): bool` |
| `SpreadconnectPod\Order\OrderStateMachine` Class | Domain-Service | DI-Container ab Slice 13 (`Bootstrap\Container`); Test-Harness ab Slice 28 | Constructor: `public function __construct( \wpdb $wpdb, ?\WC_Logger $logger = null )` |
| Liste der erlaubten States (oben) | Konstanten-Set | `slice-28..32` (vermeiden String-Magie via `OrderStateMachine::STATE_NEW` etc.) | Implementer **soll** Class-Konstanten exposen: `STATE_SUBMITTING`, `STATE_NEW`, `STATE_CONFIRMED`, `STATE_PROCESSED`, `STATE_CANCELLED`, `STATE_FAILED_TO_SUBMIT`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderStateMachine.php` — Neue Klasse `SpreadconnectPod\Order\OrderStateMachine` (final), Konstruktor `(\wpdb, ?\WC_Logger=null)`, Methode `public function compareAndSet( \WC_Order $order, string $expected, string $target ): bool` mit HPOS-CAS-Update via `$wpdb->prepare()` + `$wpdb->query()` plus Class-Konstanten fuer die sechs Persistent-States.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-05-state-machine.php` basierend auf den Test Skeletons oben. **Kein** Bootstrap-Container-Wiring in dieser Slice — die Klasse wird in Slice 28 erstmals via DI aufgeloest.

---

## Constraints

**Scope-Grenzen:**
- Keine Hook-Registrierung in `Bootstrap\Plugin` — die Klasse ist passiver Domain-Service, wird ab Slice 28 von Job-Handlern genutzt.
- Kein DI-Container-Eintrag — `Bootstrap\Container` kommt erst in Slice 13; Slice 28 fuegt den Service-Eintrag hinzu.
- Kein Direct-Write-Pfad fuer `PROCESSED`/`CANCELLED` von Webhook — Webhook-Handler in Slice 30 nutzt **entweder** CAS mit `expected='*'`-Wildcard (nicht implementiert) **oder** schreibt direkt via `$order->update_meta_data()` — Entscheidung in Slice 30. Diese Slice liefert nur das CAS-Primitive.
- Keine `_spreadconnect_needs_action`-Logik — orthogonaler Flag, separater Service ab Slice 30.
- Keine OrderRepo-Abstraktion — die Klasse arbeitet direkt mit `WC_Order` + `$wpdb`. OrderRepo (falls noetig) folgt in Slice 28 oder spaeter.
- Keine Fallback-Logik fuer **deaktiviertes** HPOS (`wp_postmeta`-Pfad) — v2 setzt HPOS aktiv voraus (Slice 03 declared compat); falls ein Test/Manual-QA HPOS deaktiviert findet, ist dies ein Konfigurationsfehler ausserhalb dieser Slice.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- `final class OrderStateMachine` — keine Vererbung erwartet.
- SQL ausschliesslich via `$wpdb->prepare()` mit `%d`/`%s`-Placeholdern; **keine** String-Konkatenation von User-Input. Tabellen-Praefix immer ueber `{$this->wpdb->prefix}wc_orders_meta` interpoliert — niemals hardcoded `wp_`.
- `compareAndSet()` **liefert exakt `bool`**; kein `int`/`null`-Cast leak. Mapping: `(int)$wpdb->query(...) === 1 ? true : false`.
- AC-3 (Initial-Insert-Pfad fuer `expected=''`): Implementierung muss zwischen `expected=''` (kein Meta) und `expected='something'` (Meta vorhanden) per **vorgelagertem `SELECT meta_value`** unterscheiden, **nicht** per `INSERT IGNORE` (HPOS-Tabelle hat keinen UNIQUE-Index ueber `(order_id, meta_key)` per WC-Default — siehe WC-Doku, da Multi-Value-Meta erlaubt). Daraus folgt: vor dem `INSERT` ein `SELECT meta_value FROM ... WHERE order_id=? AND meta_key='_spreadconnect_state' LIMIT 1` — kein Treffer -> `INSERT`, sonst `false`. Dieser Read-then-Write ist **nicht** strikt atomic, wird aber durch AS-Single-Worker-Claim pro `order_id` (Architecture: "AS single-claim per order_id") abgesichert.
- Logging via `wc_get_logger()->info(...)` mit Source `spreadconnect-order-service` (siehe `architecture.md` -> `Logging\WcLoggerAdapter` Zeile 398 fuer Source-Liste). Kein `error_log()` (verboten per Architecture-Quality-Gate).
- Order-Note in AC-5: Parameter-Order von `WC_Order::add_order_note( $note, $is_customer_note=false, $added_by_user=false )` exakt einhalten — private note, kein E-Mail-Trigger.
- `InvalidArgumentException` aus PHP-Standardlib (kein Custom Exception in dieser Slice — `SpreadconnectClientError`/`SpreadconnectTransientError` sind HTTP-spezifisch und kommen in Slice 09).
- Keine `error_log`/`var_dump`/`debug_log`-Calls.
- HPOS-Tabellen-Spaltennamen exakt `order_id`, `meta_key`, `meta_value` (per WC-Core-Schema, nicht per Plugin-DBDelta).

**Reuse:**

Slice 27 baut auf existierenden Bootstrap-Strukturen aus vorherigen Slices auf — diese sind Source of Truth und werden **nicht** dupliziert:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` deckt `Order\OrderStateMachine` automatisch ab. Keine Composer-Aenderung. |
| HPOS-Tabelle `{$wpdb->prefix}wc_orders_meta` (WC-Core, durch Slice 03 aktiviert) | CAS-Target. Plugin liest/schreibt; legt die Tabelle nicht an. |
| `wc_get_logger()` (WC-Core) | Logger-Source `spreadconnect-order-service` ist in `architecture.md` -> Service-Map -> `Logging\WcLoggerAdapter` definiert; in dieser Slice direkt aufgerufen, ab Slice 42 via `WcLoggerAdapter`-Wrapper austauschbar. |
| `WC_Order::add_order_note()`, `WC_Order::get_meta()`, `WC_Order::get_id()` (WC-Core HPOS-API) | Standard-WC-API; kein Plugin-Wrapper noetig. |

**Referenzen:**
- Architecture: `architecture.md` -> "Race Protection" (Discovery-Race-Tabelle Zeile 613) — definiert das `submitting->NEW` CAS-Verhalten gegen `Order.processed`-Webhook-Race.
- Architecture: `architecture.md` -> "WC-Order Meta (HPOS)" Zeile 305-313 — autoritative Liste der `_spreadconnect_state`-Enum-Werte.
- Architecture: `architecture.md` -> "Service Map" Zeile 370 (`Order\OrderStateMachine`) — Layer (Domain), Inputs/Outputs, Side-Effects.
- Architecture: `architecture.md` -> "Constraints" Zeile 642 — HPOS-Meta-Update via `UPDATE wp_wc_orders_meta SET ... WHERE meta_value=:expected`-Pattern.
- Architecture: `architecture.md` -> "Risks & Mitigation" Zeile 731 — Risk "HPOS meta write race between webhook handler and outbound submit", Mitigation `compareAndSet('submitting','NEW')`.
- Discovery: `discovery.md` -> Slice 5 "Order-Lifecycle" -> "Race Protection" (Zeile 607-619) — Pragma "Last-Write-Wins + Compare-and-Set", Implementierungs-Pattern Zeile 619.
- Slim-Slices: `slices/slim-slices.md` -> Slice-27-Eintrag (Zeilen 442-449) — Done-Signal: `expected='submitting'` + State `'PROCESSED'` -> `false`, kein Write; bei Match -> Write erfolgreich.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 27 (Domain-Service ohne UI; Order-Edit Meta-Box-Anzeige der States kommt in Slice 32).
