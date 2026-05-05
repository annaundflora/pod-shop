# Slice 21: Image-Sideloader (Cron-Context-Safe)

> **Slice 21 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-21-image-sideloader` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Funktions-Mocks fuer `media_sideload_image`, `function_exists`, `is_wp_error`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: Sync-Run prueft Mediathek-Eintrag) |
| **Health Endpoint** | `n/a` (Infrastructure-Service ohne eigene Routes/Hooks) |
| **Mocking Strategy** | `mock_external` (WP-Funktionen via Brain\Monkey; Patchwork fuer `function_exists`) |

---

## Ziel

Liefert den Cron-Context-sicheren Bilder-Sideload-Service `Catalog\ImageSideloader`, der `media_sideload_image()` mit zur Laufzeit nachgeladenen Admin-Includes aufruft und so verhindert, dass der Action-Scheduler-Worker (Slice 23 `SyncArticleJob`) mit `Call to undefined function`-Fatal abbricht. Der Guard `ensureAdminIncludesLoaded()` ist idempotent — wiederholte Aufrufe innerhalb eines Worker-Prozesses verursachen keinen erneuten `require_once`-Pfad.

---

## Acceptance Criteria

1) **GIVEN** ein Cron-/AS-Worker-Prozess, in dem `media_sideload_image()` noch nicht definiert ist (`function_exists('media_sideload_image') === false`)
   **WHEN** `Catalog\ImageSideloader::ensureAdminIncludesLoaded()` aufgerufen wird
   **THEN** werden genau die drei in architecture.md ("Stack & Conventions" -> `media_sideload_image()` Zeile) genannten Files via `require_once` geladen: `ABSPATH . 'wp-admin/includes/file.php'`, `ABSPATH . 'wp-admin/includes/media.php'`, `ABSPATH . 'wp-admin/includes/image.php'`. Reihenfolge: `file.php` -> `media.php` -> `image.php` (Discovery-Snippet "Cron-Context-Includes").

2) **GIVEN** `media_sideload_image()` ist bereits definiert (`function_exists(...) === true`, z. B. wenn der Service in Admin-Context oder nach erstem Aufruf erneut betreten wird)
   **WHEN** `ensureAdminIncludesLoaded()` aufgerufen wird
   **THEN** werden **keine** `require_once`-Aufrufe ausgeloest (kein Re-Require, keine erneute Filesystem-IO). Die Methode ist ein No-Op und liefert ohne Side-Effects zurueck.

3) **GIVEN** `ensureAdminIncludesLoaded()` wurde innerhalb desselben Prozesses bereits einmal erfolgreich durchlaufen
   **WHEN** die Methode ein zweites Mal aufgerufen wird
   **THEN** ist sie **idempotent**: keine erneute `function_exists`-Pruefung schlaegt fehl, kein zweites `require_once` (durch interne Static-Property-Guard ODER `function_exists`-Check, beides zulaessig). Der zweite Aufruf hat keinen messbaren Side-Effect.

4) **GIVEN** ein gueltiger Image-URL-String (z. B. eine SC-Preview-URL aus Slice 23) und eine WC-Product-Post-ID `> 0`
   **WHEN** `Catalog\ImageSideloader::sideload(string $url, int $product_id): int|\WP_Error` aufgerufen wird und `media_sideload_image()` einen Integer (Attachment-ID) zurueckliefert
   **THEN** ruft die Methode zuerst `ensureAdminIncludesLoaded()` auf, dann `media_sideload_image($url, $product_id, null, 'id')` (Return-Mode `id` -> Attachment-ID statt HTML), und gibt die zurueckgegebene `int` Attachment-ID weiter (Wert-Identitaet, kein Casting).

5) **GIVEN** `media_sideload_image()` liefert ein `WP_Error`-Objekt (z. B. weil HTTP-Download fehlschlaegt oder das Format nicht unterstuetzt wird)
   **WHEN** `sideload($url, $product_id)` aufgerufen wird
   **THEN** wird das `WP_Error`-Objekt **unveraendert** durchgereicht (kein Re-Wrap, keine Exception). Der Aufrufer (Slice 23 `SyncArticleJob`) entscheidet ueber `partial`-State (siehe architecture.md "Failure Mode Map" -> "Image-sideload failure").

6) **GIVEN** ein leerer URL-String oder `$product_id <= 0`
   **WHEN** `sideload(...)` aufgerufen wird
   **THEN** liefert die Methode ein `WP_Error` mit `code='spreadconnect_invalid_sideload_args'` zurueck, **bevor** `ensureAdminIncludesLoaded()` oder `media_sideload_image()` aufgerufen werden (Pre-Check; verhindert unnoetige Filesystem-IO und API-Calls in WP-Core).

7) **GIVEN** der Slice ist abgeschlossen
   **WHEN** Root-`composer test` ausgefuehrt wird
   **THEN** existiert die Test-Datei `tests/slices/pod-shop-mvp/slice-04-image-sideloader.php` (siehe slim-slices.md Slice-21 Deliverables) und alle ACs sind durch korrespondierende PHPUnit-Tests gedeckt; PHPUnit-Suite terminiert mit Exit-Code `0`.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey `Functions\when()`/`Functions\expect()` fuer `function_exists`, `media_sideload_image`, `is_wp_error`. Patchwork ist bereits in `patchwork.json` registriert (REUSE #3 + EXTEND #6). Der Test-Writer muss `ABSPATH` ggf. via `define()` im Test-Bootstrap setzen, falls nicht vorhanden.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-image-sideloader.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Catalog;

use PHPUnit\Framework\TestCase;

final class ImageSideloaderTest extends TestCase
{
    // AC-1: Includes werden geladen, wenn media_sideload_image() noch nicht definiert ist
    public function test_ensure_admin_includes_loaded_requires_three_files_in_order(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Kein Re-Require, wenn media_sideload_image() bereits definiert ist
    public function test_ensure_admin_includes_loaded_is_noop_when_function_exists(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Idempotenter Zweitaufruf
    public function test_ensure_admin_includes_loaded_is_idempotent(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Erfolgsfall liefert Attachment-ID weiter
    public function test_sideload_returns_attachment_id_on_success(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: sideload ruft ensureAdminIncludesLoaded vor media_sideload_image
    public function test_sideload_calls_ensure_admin_includes_loaded_first(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: WP_Error wird unveraendert durchgereicht
    public function test_sideload_passes_wp_error_through(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Pre-Check leerer URL liefert WP_Error spreadconnect_invalid_sideload_args
    public function test_sideload_rejects_empty_url(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Pre-Check $product_id <= 0 liefert WP_Error spreadconnect_invalid_sideload_args
    public function test_sideload_rejects_non_positive_product_id(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Pre-Check umgeht ensureAdminIncludesLoaded und media_sideload_image
    public function test_sideload_pre_check_skips_includes_and_api(): void
    {
        $this->markTestIncomplete('AC-6');
    }
}
```
</test_spec>

> **AC-7:** Wird vom Orchestrator ueber den Exit-Code von `composer test` validiert; kein eigener PHPUnit-Test noetig.

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | PSR-4-Autoload `SpreadconnectPod\Catalog\` -> `includes/Catalog/` | Composer-Konfiguration | Klasse `SpreadconnectPod\Catalog\ImageSideloader` ist via Root-`vendor/autoload.php` resolvable. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::pluginFile()` (existiert) | static getter | Nicht direkt konsumiert, aber Bootstrap-Skeleton wird vorausgesetzt fuer Test-Bootstrap-Konstanten (`ABSPATH`). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Catalog\ImageSideloader::ensureAdminIncludesLoaded` | static method (oder instance, beides zulaessig — siehe Constraints) | `slice-23-sync-article-job` | `public static function ensureAdminIncludesLoaded(): void` |
| `Catalog\ImageSideloader::sideload` | method | `slice-23-sync-article-job` (Per-Article-Image-Pull), `slice-22-product-mapper` (Featured-Image-Set, optional) | `public function sideload(string $url, int $product_id): int\|\WP_Error` |

> **Hinweis Consumer-Wiring:** `Catalog\SyncArticleJob` (Slice 23) instantiiert `ImageSideloader` und ruft `sideload()` pro Preview-URL auf. Der Mount-Point liegt in Slice 23 (Job-Sequenz: `getProductType` -> `createPreviews` -> `sideload` -> `ProductMapper::upsert`). Slice 21 liefert ausschliesslich die Service-Klasse; Wiring ist NICHT Teil dieses Slices.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/ImageSideloader.php` — `final class ImageSideloader` mit `ensureAdminIncludesLoaded(): void` (idempotenter Guard via Static-Property ODER `function_exists`) und `sideload(string $url, int $product_id): int|\WP_Error` (Pre-Check + Includes-Load + `media_sideload_image`-Wrapper).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-04-image-sideloader.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- Keine WC-Product-Featured-Image- oder Gallery-Logik — `set_image_id()`/`set_gallery_image_ids()` gehoeren zu `slice-22-product-mapper`.
- Keine Preview-URL-Beschaffung (`POST /productTypes/{id}/previews`) — gehoert zu `slice-10-endpoint-methods` (Wrapper) und `slice-23-sync-article-job` (Aufruf-Sequenz).
- Keine Action-Scheduler-Hook-Registrierung — `ImageSideloader` wird **synchron** vom `SyncArticleJob` aufgerufen, nicht selbst als Hook registriert.
- Keine `sync_history`-Schreibweisen — `partial`-State-Mapping bei Image-Failure ist Job-Verantwortung (Slice 23).
- Keine "Force re-pull"-Logik — Re-Sync-Verhalten (siehe Discovery "Bilder-Sideload nur beim ersten Sync pro Article") wird im Caller (Slice 23) implementiert.
- Kein Logging-Adapter-Wiring — `WcLoggerAdapter` (Slice 42) ist noch nicht verfuegbar; bei Bedarf `error_log`-Stub erlaubt, MUSS aber in Slice 42 ersetzt werden (FIXME-Kommentar erlaubt).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- Klasse `final class ImageSideloader` (nicht erweiterbar; Service-Wiring kommt in Folge-Slices via `Bootstrap\Container`).
- Method-Signaturen exakt wie in Integration Contract dokumentiert (Return-Types Pflicht).
- Pre-Check (AC-6) MUSS **vor** `ensureAdminIncludesLoaded()` erfolgen, damit ungueltige Args keine Filesystem-IO ausloesen.
- `media_sideload_image()` MUSS mit Return-Mode `'id'` aufgerufen werden (vierter Parameter `'id'`), sodass die Funktion eine Integer-Attachment-ID statt HTML-Markup zurueckliefert (siehe architecture.md References-Zeile zum WP Codex).
- `function_exists`-Check (AC-2) ODER Static-Property-Guard (AC-3) — der Implementer waehlt; beide muessen Idempotenz gewaehrleisten. Wenn Static-Property gewaehlt wird, MUSS sie privat und non-resetbar sein (kein public Setter).
- Keine `try/catch` um `require_once` — Fatal Error bei fehlendem WP-Core-File ist akzeptabel (deutet auf kaputtes WP-Setup hin und gehoert nicht in den Service-Layer).
- Klasse darf instantiierbar sein (`new ImageSideloader()`) ODER nur statische Methoden anbieten — beides ist mit Slice 23-Aufruf-Pattern kompatibel; Implementer waehlt nach Konvention der bereits in Slice 02-20 etablierten Klassen.

**Reuse:**

Slice 21 nutzt bestehende Bausteine; **keine** Neuimplementierung von Mocking-Harness oder Plugin-Skeleton:

| Existing File | Usage in this Slice |
|---|---|
| `composer.json` (Root) | Existierender Brain\Monkey 2.6 + PHPUnit 11 + Patchwork-Stack (REUSE #3 in architecture.md) — Test-Writer nutzt diese Harness unveraendert. |
| `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` | Plugin-Bootstrap aus Slice 02 — `ImageSideloader.php` wird unter `includes/Catalog/` abgelegt und ist via PSR-4 autoloadbar. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` | Plugin-Klasse aus Slice 02 — wird in diesem Slice **nicht** geaendert (kein neuer Hook in Bootstrap; Wiring kommt in Slice 23). |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Catalog\ImageSideloader` ("Wraps `media_sideload_image()` with admin-includes loaded"); "Stack & Conventions" -> `media_sideload_image()`-Zeile (Admin-Includes-Pflicht); "Constraint -> Implication -> Solution" Tabelle Zeile `media_sideload_image() requires admin includes` (zentrale Loesung in `ensureAdminIncludesLoaded()`); "Failure Mode Map" Zeile "Image-sideload failure" (Caller-Verantwortung fuer `partial`-State).
- Discovery: `discovery.md` -> Slice 4 "Catalog-Sync" -> "Cron-Context-Includes fuer `media_sideload_image()`" (Code-Snippet mit den drei `require_once`-Pfaden).
- Slim-Slices: `slices/slim-slices.md` -> Slice-21-Eintrag (Done-Signal: ohne Definition -> Includes geladen; mit Definition -> kein Re-Require; Failure -> WP_Error returned).
- Wireframes: `wireframes.md` — **nicht relevant** (Infrastructure-Service ohne UI-Touch).
