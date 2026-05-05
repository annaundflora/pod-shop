# Slice 01: Cleanup v1-Plugin (Greenfield-Reset)

> **Slice 1 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-cleanup-v1` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (kein separates Integration-Suite — Slice ist filesystem-only) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (nur fuer Manual-QA, nicht fuer diesen Slice noetig) |
| **Health Endpoint** | `n/a` (filesystem cleanup) |
| **Mocking Strategy** | `no_mocks` (Filesystem-Asserts gegen real entfernte Pfade) |

---

## Ziel

Der gesamte v1-Plugin-Code in `wordpress/plugins/spreadconnect-pod/` und der zugehoerige Slice-Test-Stub auf Root-Level werden **vollstaendig entfernt**. Slice 02 startet auf einer leeren Plugin-Wurzel und baut das v2-Skeleton von Grund auf neu. Damit ist sichergestellt, dass keine v1-Reste (Klassen, Tests, Configs) im Build oder in der Test-Suite verbleiben.

---

## Acceptance Criteria

1) **GIVEN** ein Repository mit dem v1-Plugin unter `wordpress/plugins/spreadconnect-pod/`
   **WHEN** Slice 01 abgeschlossen ist
   **THEN** existiert das gesamte Verzeichnis `wordpress/plugins/spreadconnect-pod/` nicht mehr (vollstaendige Loeschung; Slice 02 erstellt das Verzeichnis und seine `composer.json` neu).

2) **GIVEN** der v1-Test-Stub `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php` existiert auf Root-Level
   **WHEN** Slice 01 abgeschlossen ist
   **THEN** ist diese Datei geloescht und das Verzeichnis `tests/slices/pod-shop-mvp/` enthaelt **keine v1-bezogenen Test-Dateien** mehr.

3) **GIVEN** Root-`composer.json` referenziert PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/`
   **WHEN** Slice 01 abgeschlossen ist
   **THEN** bleibt das PSR-4-Mapping in der Root-`composer.json` **unveraendert** (Slice 02 baut das Zielverzeichnis neu auf, daher darf das Mapping nicht entfernt werden).

4) **GIVEN** `composer test` wird auf Root-Level ausgefuehrt
   **WHEN** Slice 01 abgeschlossen ist
   **THEN** terminiert der Lauf mit Exit-Code `0` und Status "No tests executed" (PHPUnit 11 meldet 0 Tests, da das einzige v1-Slice-File geloescht wurde und Slice 02+ noch keine neuen Tests hinzugefuegt haben).

5) **GIVEN** `git status` nach abgeschlossenem Slice
   **WHEN** der Working Tree inspiziert wird
   **THEN** sind alle geloeschten Pfade als `deleted:` erfasst und es existieren **keine neuen Dateien** ausserhalb der Spec-Dokumentation (Slice 01 ist rein subtraktiv).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Filesystem-Asserts in einer einzigen PHPUnit-Test-Datei. Keine Brain\Monkey-Mocks noetig. Keine Plugin-Bootstrap-Loads (das v1-Bootstrap ist ja gerade weg). Test-Writer implementiert die Assertions selbststaendig und nutzt `__DIR__ . '/../../../...'` fuer Pfad-Resolves.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-01-cleanup-v1.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class CleanupV1Test extends TestCase
{
    // AC-1: v1-Plugin-Verzeichnis vollstaendig entfernt
    public function test_v1_plugin_directory_does_not_exist(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: v1-Slice-Test-Stub entfernt
    public function test_v1_slice_test_stub_does_not_exist(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Keine weiteren v1-Test-Dateien im Slice-Verzeichnis
    public function test_no_v1_remnant_test_files_remain(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: PSR-4-Mapping in Root-composer.json bleibt unveraendert
    public function test_root_composer_psr4_mapping_preserved(): void
    {
        $this->markTestIncomplete('AC-3');
    }
}
```
</test_spec>

> **AC-4 / AC-5:** Werden nicht ueber PHPUnit gemessen, sondern ueber das Done-Signal des Orchestrators (`composer test` Exit-Code + `git status`-Inspection im Compliance-Gate). Der Test-Writer fuegt **keine** Tests fuer AC-4/AC-5 hinzu.

---

## Integration Contract

### Requires From Other Slices

Keine — Slice 01 ist die Greenfield-Vorbedingung.

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| —     | —        | —    | —          |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| Leere Plugin-Wurzel `wordpress/plugins/spreadconnect-pod/` (nicht existent) | Filesystem-Vorbedingung | `slice-02-plugin-bootstrap` | Slice 02 erstellt Verzeichnis + `spreadconnect-pod.php` + `composer.json` + `includes/Bootstrap/Plugin.php` |
| Leeres Slice-Test-Verzeichnis `tests/slices/pod-shop-mvp/` (ohne v1-Files) | Filesystem-Vorbedingung | Alle Folge-Slices ab `slice-03-hpos-declare` | Folge-Slices fuegen neue `slice-NN-*.php`-Test-Files hinzu, ohne mit v1-Stub zu kollidieren |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] **DELETE** `wordpress/plugins/spreadconnect-pod/` (komplettes Verzeichnis inkl. aller Unterverzeichnisse: `includes/`, `tests/`, `composer.json`, `composer.lock`, `phpunit.xml`, `patchwork.json`, `spreadconnect-pod.php`)
- [ ] **DELETE** `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php`
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-01-cleanup-v1.php` basierend auf den Test Skeletons oben.
> **Hinweis 2:** Slice 01 ist **subtraktiv** — es werden keine produktiven Dateien neu erstellt oder modifiziert. Die Root-`composer.json` bleibt unveraendert (siehe AC-3).
> **Hinweis 3:** Die slim-slices.md erwaehnt einen "composer.json-Skeleton fuer Slice 02". Dieser Slice loescht **trotzdem** die v1-`composer.json` vollstaendig — Slice 02 erstellt eine neue v2-`composer.json` von Grund auf (Header v2.0.0, frische PSR-4-Section, keine v1-Klassen). Es wird **kein** Skeleton uebernommen, um v1-Reste zu vermeiden.

---

## Constraints

**Scope-Grenzen:**
- Slice 01 erstellt **keine** neuen produktiven Dateien (kein `spreadconnect-pod.php`, kein `Bootstrap\Plugin`, keine `composer.json` im Plugin-Verzeichnis) — das ist Slice 02's Aufgabe.
- Slice 01 modifiziert **nicht** die Root-`composer.json`, **nicht** die Root-`phpunit.xml`, **nicht** `docker-compose.yml`, **nicht** `scripts/setup.sh`.
- Slice 01 fuehrt **kein** `composer dump-autoload` aus; die Autoload-Map regeneriert sich beim ersten `composer install` von Slice 02.
- Slice 01 entfernt **keine** anderen Plugins (z. B. `wordpress/plugins/pinterest-capi/`, MU-Plugins) — nur `spreadconnect-pod/`.

**Technische Constraints:**
- Loeschung muss **rekursiv** erfolgen (das v1-Plugin-Verzeichnis enthaelt eigene `vendor/`-Subdirs und `composer.lock`).
- Loeschung muss **idempotent** sein: Wiederholtes Ausfuehren des Slice darf nicht fehlschlagen, wenn die Pfade bereits weg sind.
- Implementer nutzt git-tracked Loeschungen (`git rm -rf` ODER `rm -rf` + `git add -A`), sodass die Aenderungen im Working Tree sichtbar werden und der Compliance-Gate sie verifizieren kann.
- **Keine** `.gitkeep`-Platzhalter in den geloeschten Verzeichnissen — Slice 02 erzeugt das Verzeichnis ohnehin neu.

**Reuse:**

Keine Reuse-Eintraege fuer diesen Slice. Slice 01 ist explizit **anti-Reuse**: das gesamte v1-Plugin (alle 4 v1-Klassen `class-spreadconnect-api-client.php`, `class-spreadconnect-order-service.php`, `class-spreadconnect-tracking-service.php`, `class-spreadconnect-settings.php` sowie `tests/`, `composer.json`, `phpunit.xml`, `patchwork.json`, `spreadconnect-pod.php`) wird verworfen. Discovery (`discovery.md` Section "Solution") und Architecture (`architecture.md` Section "Problem & Solution") begruenden den Greenfield-Reset: v1 deckt nur 2 von 27 SC-Endpoints ab, hat einen `WP_DEBUG`-Bypass im Webhook-Auth (Security-Risk) und ist nicht HPOS-kompatibel.

**Referenzen:**
- Architecture: `architecture.md` -> "Problem & Solution" (Greenfield-Begruendung) + "Scope & Boundaries" (was v2 stattdessen liefert).
- Discovery: `discovery.md` -> Slice 1 "Plugin Foundation" (Greenfield-Vorbedingung).
- Slim-Slices: `slices/slim-slices.md` -> Slice-01 + Slice-02 (Sequenz: Cleanup -> Bootstrap).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 01 (UI kommt erst ab Slice 11/13).
