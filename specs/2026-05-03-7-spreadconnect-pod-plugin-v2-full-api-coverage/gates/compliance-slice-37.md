# Gate 2: Compliance Report — Slice 37

**Geprüfter Slice:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/slices/slice-37-failed-ops-repo.md`
**Prüfdatum:** 2026-05-03

---

## Phase 2: Deterministic Checks

| Check | Status | Detail |
|-------|--------|--------|
| D-1: Metadata | PASS | Section vorhanden; Tabelle enthält ID=`slice-37-failed-ops-repo`, Test=`composer test`, E2E=`false`, Dependencies=`["slice-28-order-submit-job", "slice-23-sync-article-job"]`. |
| D-2: Test-Strategy | PASS | Alle 7 Felder vorhanden: Stack (`php-wordpress-plugin`), Test/Integration/Acceptance Command (`composer test`), Start (`docker compose up -d`), Health (`n/a`), Mocking (`mock_external`). |
| D-3: AC Format | PASS | 12 ACs; alle enthalten GIVEN/WHEN/THEN als bold-keyword Wörter. |
| D-4: Test Skeletons | PASS | Zwei `<test_spec>`-Blöcke; PHPUnit-Pattern (`public function test_`, `markTestIncomplete`, `final class … extends TestCase`). 16 Tests im Repo-File + 13 Tests im Listener-File = 29 Test-Cases vs. 12 ACs (29 ≥ 12). |
| D-5: Integration Contract | PASS | "Requires From Other Slices" mit 6 Einträgen + "Provides To Other Slices" mit 3 Einträgen vorhanden. |
| D-6: Deliverables Marker | PASS | `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` vorhanden; 3 Deliverables (FailedOpsRepo.php, RetryPolicyListener.php, Bootstrap/Plugin.php-Edit), alle mit Pfad. |
| D-7: Constraints | PASS | Section vorhanden mit Scope-Grenzen (7 Bullets), Technische Constraints (10 Bullets), Reuse-Tabelle (5 Einträge). |
| D-8: Größe | PASS | 391 Zeilen (< 400 Warnschwelle); kein Code-Block > 20 Zeilen ausserhalb der Test-Skeletons (Skeletons sind PHPUnit-Stubs mit `markTestIncomplete`, nicht Implementations-Code). |
| D-9: Anti-Bloat | PASS | Keine "## Code Examples" Section; keine ASCII-Wireframes; kein DB-`CREATE TABLE`-Block kopiert (nur referenziert via "architecture.md Z. 191-205"); keine vollständigen Type-Definitionen. Tabelle in AC-10 ist Mapping-Tabelle, nicht Type-Definition. |
| D-10: Codebase Reference | SKIP | Plugin v2 ist Greenfield (Slice 01 löscht v1, Slice 02 erstellt v2-Skeleton). `Bootstrap/Plugin.php` existiert noch nicht im Working-Tree; wird von vorgelagerten Slices (02, 04, 05, 06, 23, 28 etc.) Schritt für Schritt aufgebaut. Slice 37 referenziert nur Slice-interne Resourcen (FQCN `SpreadconnectClientError` aus Slice 07, AS-Hooks aus Slice 23/28). Codebase-Verify nicht anwendbar. |

**Phase 2 Verdict:** PASS

---

## Phase 3: LLM Content Checks

| Check | Status | Detail |
|-------|--------|--------|
| L-1: AC-Qualität | PASS | Alle 12 ACs sind testbar: konkrete Werte (`order_id=7`, `state='unresolved'`, `retries_used=3`), explizite Methodensignaturen (`FailedOpsRepo::record($args)`), präzise Hook-Namen (`action_scheduler_failed_action`). GIVEN-Vorbedingungen mit konkreten Args; WHEN als Single-Action; THEN messbar (Insert-Statements, Return-Werte, ausgeschlossene Calls via `expect()->never()`). AC-9 Mapping-Tabelle macht Hook→op_type prüfbar; AC-10 Args-Extraction-Tabelle macht Entity-Tupel prüfbar. |
| L-2: Architecture Alignment | PASS | Schema-Spalten matchen architecture.md Z. 191-205 exakt (alle 11 Spalten + 3 Indexes referenziert). 9 Plugin-AS-Hooks aus AC-9 entsprechen architecture.md Z. 542-558 (Hook-Inventory). 3-Retry-Threshold aus AC-7/AC-8 entspricht architecture.md Z. 549 (`1m/5m/15m on 5xx; permanent on 4xx`) und Z. 680 (`3-fail-then-record assertion`). `op_type='handle_webhook'`-Mapping konsistent mit architecture.md Z. 457 (Flow E). FailedOpsRepo-Verantwortlichkeit aus Service-Map Z. 388 abgedeckt. |
| L-3: Contract Konsistenz | PASS | Requires From Slice-04: Tabellen-Schema mit allen Indexes — Slice-04 in slim-slices.md (Z. 179-187) genau das Schema-Setup. Requires From Slice-07: `SpreadconnectClientError`/`SpreadconnectTransientError` als FQCN — Slice-07 (slim-slices Z. 213-221) liefert beide Klassen unverändert. Requires From Slice-23 AC-4: `SpreadconnectClientError` Re-Throw bei 4xx — verifiziert in slice-23-sync-article-job.md AC-4. Requires From Slice-28 AC-5: 4xx wird im Job mit `failed_op_pending_record`-Tag geloggt — verifiziert in slice-28-order-submit-job.md AC-5 (Zeile 63 dokumentiert exakt diese Konvention). Provides-Schnittstelle (`record(array $args): int`, `findById`, `markResolved`, `markDismissed`, `findByEntity`, `count`) ist typkompatibel mit Consumern Slice 38/39/40. |
| L-4: Deliverable-Coverage | PASS | AC-1..AC-5 → `FailedOpsRepo.php` (Deliverable 1). AC-6..AC-10, AC-12 → `RetryPolicyListener.php` (Deliverable 2). AC-11 → `Bootstrap/Plugin.php`-Edit (Deliverable 3). Test-Deliverables sind explizit out-of-scope der Slice-Deliverables (Test-Writer-Agent erstellt sie laut Hinweis nach Z. 339). Kein verwaistes Deliverable. |
| L-5: Discovery Compliance | PASS | Discovery Slice 9 "Failure-Recovery" (Z. 930) verlangt: `Custom-Table failed_ops` (✓ via Repo CRUD), `Auto-Retry-Hook in Action-Failure` (✓ via `action_scheduler_failed_action`-Listener), `3-Retry-then-Failed-Ops-Eintrag` (✓ AC-7/AC-8). Email-Notify + Persistent-Admin-Notice + UI sind explizit out-of-scope (Slice 38/39 — korrekt in Constraints abgegrenzt). User-Flow `5xx → AS-Retry → 3 Fails → DLQ-Row` und `4xx → direkte DLQ-Row, kein Retry` lückenlos abgedeckt durch AC-6 + AC-8. |
| L-6: Consumer Coverage | SKIP | Kein "MODIFY existing file" mit verändertem Public-Interface: Slice 37 fügt `Bootstrap/Plugin.php` nur eine zusätzliche Hook-Registrierung hinzu (`add_action('action_scheduler_failed_action', ...)`), die Bestehendes nicht modifiziert. Repo + Listener sind neue Klassen ohne bestehende Consumer (Consumer kommen erst in Slice 38/39/40). Consumer-Coverage-Check daher nicht anwendbar. |

---

## Blocking Issues

Keine.

---

## Verdict

**VERDICT: APPROVED**

**Blocking Issues:** 0

Relevante Pfade:
- Geprüfter Slice: `/home/dev/pod-shop/.claude/worktrees/7-spreadconnect-pod-plugin-v2-full-api-coverage/specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/slices/slice-37-failed-ops-repo.md`
- Compliance-Report: `/home/dev/pod-shop/.claude/worktrees/7-spreadconnect-pod-plugin-v2-full-api-coverage/specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/gates/compliance-slice-37.md`
