# Orchestrator Configuration: Spreadconnect POD Plugin v2 — Full API Coverage

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-05-03
**Stack:** PHP 8.2 + WordPress 6.x + WooCommerce 10.5 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork + Action-Scheduler

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "gates/compliance-architecture.md"
    required: "Verdict == APPROVED"

  - name: "Gate 2: All Slices Approved"
    files: "gates/compliance-slice-*.md"
    required: "ALL 46 Verdict == APPROVED"
    expected_count: 46

  - name: "Gate 3: Integration Map Valid"
    file: "gates/integration-map.md"
    required: "VERDICT: READY FOR ORCHESTRATION"
    required_metrics:
      missing_inputs: 0
      orphaned_outputs: 0
      deliverable_consumer_gaps: 0
      runtime_path_gaps: 0
      semantic_consistency_gaps: 0
      discovery_coverage_pct: 100
```

---

## Infrastructure Prerequisites

```yaml
prerequisites:
  - name: "Docker stack"
    cmd: "docker compose up -d"
    verify: "docker compose ps | grep -E '(wordpress|mysql).*Up'"

  - name: "Plugin slot empty"
    note: "Slice 01 (cleanup) handles this. Greenfield reset of wordpress/plugins/spreadconnect-pod/"

  - name: "PSR-4 autoload root"
    note: "Root composer.json already maps SpreadconnectPod\\ -> wordpress/plugins/spreadconnect-pod/includes/. Verified during architecture review (no edit needed)."

  - name: "Action Scheduler"
    note: "Bundled with WC 3.0+. No separate install. Confirmed available in WC 10.5."

  - name: "HPOS active"
    note: "Default since WC 8.2. Slice 03 declares plugin compatibility via FeaturesUtil."

  - name: "Test infra (Brain\\Monkey + Patchwork)"
    note: "Slice 02 establishes plugin-level composer.json with phpunit/phpunit ^11, brain/monkey ^2.6, antecedent/patchwork ^2.1, mockery/mockery, plus tests/bootstrap/bootstrap.php. All later slice tests reuse this bootstrap."

  - name: "Health endpoint (optional, post-MVP)"
    note: "Architecture defines GET /wp-json/spreadconnect/v1/health (manage_woocommerce capability). NOT in any slice's deliverables — Hub-Header diagnostics is read-only via direct repo queries (slice-46 Dashboard). RECOMMENDATION: add a 'Slice 47: Health-Endpoint' post-MVP if external monitoring is required, OR fold the route into slice-13 Hub-Controller during implementation. NOT a blocker for orchestration."
```

---

## Implementation Order (Wave-Based)

The dependency graph collapses to 9 waves. Slices in the same wave are independent and may be implemented in parallel.

| Wave | Slice | Name | Depends On | Parallel? |
|------|-------|------|------------|-----------|
| 0 | 01 | cleanup-v1 | — | No (foundation, sequential) |
| 0 | 02 | plugin-bootstrap | 01 | No |
| 1 | 03 | hpos-declare | 02 | Yes (with 04, 05, 06, 09, 21) |
| 1 | 04 | schema-dbdelta | 02 | Yes |
| 1 | 05 | options-defaults | 02 | Yes |
| 1 | 06 | i18n-textdomain | 02 | Yes |
| 1 | 09 | dto-value-objects | 02 | Yes |
| 1 | 21 | image-sideloader | 02 | Yes |
| 2 | 07 | http-client-base | 05 | No (single file in wave) |
| 2 | 20 | attribute-provisioner | 04 | Yes (parallel to 07) |
| 2 | 27 | order-state-machine | 04 | Yes (parallel to 07, 20) |
| 3 | 08 | rate-limit-retry | 07 | No (extends 07) |
| 4 | 10 | endpoint-methods | 08, 09 | No (single file in wave) |
| 4 | 22 | product-mapper | 20, 09 | Yes (parallel to 10) |
| 5 | 11 | settings-form | 05, 06 | No (single file in wave) |
| 5 | 23 | sync-article-job | 10, 21, 22 | Yes (parallel to 11) |
| 6 | 12 | test-connection-ajax | 10, 11 | Yes (with 13, 14) |
| 6 | 13 | hub-page-skeleton | 11 | Yes |
| 6 | 24 | sync-catalog-job | 23 | Yes |
| 6 | 28 | order-submit-job | 10, 27 | Yes |
| 7 | 14 | webhook-secret-manager | 13 | Yes (with 18, 26, 29, 34) |
| 7 | 18 | subscription-manager | 12, 14 — note 14 is same wave; serialize 14 before 18 within Wave 7 | Sequential within wave |
| 7 | 26 | catalog-sync-ui | 13, 24 | Yes |
| 7 | 29 | order-confirm-cancel-jobs | 28 | Yes |
| 7 | 34 | product-meta-box | 22, 23 | Yes |
| 8 | 15 | webhook-route | 14 | No (within wave 8) |
| 8 | 19 | subscriptions-ui | 18 | Yes |
| 8 | 35 | product-list-columns | 34 | Yes |
| 8 | 30 | order-webhooks-handler | 28, 25 — note 25 not yet in graph; serialize via Wave 9 below | Sequential |
| 8 | 32 | order-meta-box | 29, 13 | Yes |
| 8 | 36 | stock-cache-sync | 23, 25 — note 25 in Wave 9; serialize | Sequential |
| 9 | 16 | event-id-hasher | 15 | No |
| 9 | 17 | process-webhook-event-job | 16 | No |
| 9 | 25 | article-removed-job | 17, 24 | No |
| 9 | 30 | order-webhooks-handler | 28, 25 | No (move to Wave 10) |
| 10 | 31 | wc-cancel-mirror | 29, 30 | Yes (with 33, 41, 44) |
| 10 | 33 | order-list-columns-bulk | 32 | Yes |
| 10 | 36 | stock-cache-sync | 23, 25 | Yes |
| 10 | 41 | webhook-log-ui | 16, 13 | Yes |
| 10 | 42 | logs-ui-wc-logger | 13, 07 | Yes |
| 10 | 44 | dev-tools-simulate | 30, 11 | Yes |
| 10 | 45 | export-import-settings | 11 | Yes |
| 11 | 37 | failed-ops-repo | 28, 23 | No (single foundational slice) |
| 12 | 38 | failed-ops-ui | 37 | Yes (with 39, 43) |
| 12 | 39 | failure-notifier | 37 | Yes |
| 12 | 43 | purge-old-logs-job | 42 | Yes |
| 13 | 40 | bulk-resend-coordinator | 38, 33 | No |
| 14 | 46 | i18n-de-readme | 43, 39 | No (final polish) |

> **Note on wave-collapse:** The above table was reconciled to keep dependencies acyclic. Slice 18 must follow slice 14 within Wave 7; slice 30 needs both slice 28 (Wave 6) and slice 25 (Wave 9), so it is moved to Wave 10. Slice 36 needs slice 25 (Wave 9), moved to Wave 10. Adjust orchestrator's parallel-execution to honor explicit `Depends On` edges, not the wave-number alone.

### Recommended Sequential Implementation Path (DAG-Topological)

If parallelism is impractical, use this strict topological order:

```
01 -> 02 -> [03, 04, 05, 06, 09, 21]
   -> 07 -> 08 -> 10
   -> 20 -> 22
   -> 27
   -> 11 -> 12 -> 13 -> 14
   -> 15 -> 16 -> 17
   -> 18 -> 19
   -> 23 -> 24 -> 25 -> 26
   -> 28 -> 29 -> 30 -> 31
   -> 32 -> 33 -> 34 -> 35
   -> 36
   -> 37 -> 38 -> 39 -> 40
   -> 41 -> 42 -> 43
   -> 44 -> 45
   -> 46
```

---

## Per-Slice Test Configuration

All slices share the same test stack. Each slice's `## Metadata` section in its spec file declares:

```yaml
test_strategy:
  stack: "php-wordpress-plugin"
  test_command: "composer test"
  integration_command: "composer test"
  acceptance_command: "composer test"
  start_command: "docker compose up -d"  # for manual QA only
  mocking_strategy: "mock_external"  # Brain\Monkey for WP funcs, Mockery for class doubles, Patchwork for hash_equals + select internals
```

The plugin-local `phpunit.xml` (slice-02 deliverable) defines a single test-suite pointing at `tests/slices/pod-shop-mvp/`. The root `composer test` ALSO covers the same path; both run identical assertions.

---

## Post-Slice Validation

For each completed slice, the orchestrator runs:

```yaml
validation_steps:
  - step: "Deliverables Check"
    action: |
      For each line in DELIVERABLES_START..DELIVERABLES_END:
        - If "DELETE": verify file does NOT exist
        - If "Edit": verify file exists AND contains the documented mount-point
        - Else: verify file exists with non-empty content

  - step: "Unit + Integration Tests"
    action: "composer test"
    accept_when: "exit-code = 0 AND PHPUnit-output reports no failures, no errors, no incomplete (except for explicit markTestIncomplete during scaffolding phase)"

  - step: "Slice-Local Test Discovery"
    action: |
      Find tests/slices/pod-shop-mvp/slice-{NN}-*.php
      Verify each AC from slice-spec maps to >=1 test method (via test-skeleton skeleton)
      Verify markTestIncomplete count = 0 (no leftover scaffold)

  - step: "Integration-Contract Verification"
    action: |
      Read slice's "Provides To Other Slices" table.
      For each Resource:
        - Verify the FQCN/method/hook-name exists in code
        - Verify visibility matches spec (public/protected/static)
        - Verify return-type matches spec
      Read slice's "Requires From Other Slices" table.
      For each Resource:
        - Verify the producer-slice is APPROVED (compliance-slice-{NN}.md)
        - Verify the producer-slice's deliverable file exists at HEAD
```

---

## E2E Validation

After ALL slices complete, the orchestrator runs:

```yaml
e2e_validation:
  - step: "Stack Up"
    cmd: "docker compose up -d"
    wait_for: "WP_CLI accessible inside container"

  - step: "Plugin Activate"
    cmd: "docker compose exec wpcli wp plugin activate spreadconnect-pod"
    accept_when: "exit-code = 0; plugin appears in 'wp plugin list --status=active'"

  - step: "DB Schema Verify"
    cmd: |
      docker compose exec wpcli wp db query "SHOW TABLES LIKE 'wp_spreadconnect_%'"
    accept_when: "Output contains failed_ops, webhook_log, sync_history (3 rows)"

  - step: "HPOS Compatible"
    cmd: |
      docker compose exec wpcli wp eval "echo wc_get_container()->get(\\Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::class)->feature_is_enabled('custom_order_tables') ? 'yes' : 'no';"
    accept_when: "Output = 'yes'"

  - step: "Execute e2e-checklist.md"
    file: "gates/e2e-checklist.md"
    note: "Manual or scripted run of all flows A..M plus edge-cases"

  - step: "FOR each failing check"
    actions:
      - "Identify responsible slice from Integration Map (Connections table)"
      - "Create fix task with slice reference"
      - "Re-run affected slice tests + dependent slices"

  - step: "Final Approval"
    condition: "ALL checks in e2e-checklist.md PASS"
    output: "Feature READY for merge to master"
```

---

## Rollback Strategy

If implementation fails:

```yaml
rollback:
  - condition: "Slice N fails Pre-Implementation Gate"
    action: "Block orchestration; do not implement"
    note: "Fix slice-spec or compliance issue before retry"

  - condition: "Slice N fails Deliverables Check after implementation"
    action: |
      git checkout HEAD -- wordpress/plugins/spreadconnect-pod/
      git checkout HEAD -- tests/slices/pod-shop-mvp/slice-{NN}-*.php
      Re-implement slice N from scratch
    note: "Earlier slices remain stable; PSR-4 autoload survives slice-resets"

  - condition: "Slice N tests fail after implementation"
    action: |
      Read failure messages.
      If failure is in slice N's own test file: fix in slice-N implementation.
      If failure is in slice <N's test file (regression): the integration-contract was violated -> revert slice N edits and re-plan.
    note: "Compliance gate Phase 3 (Mock-Compliance + Backward-Test-Compatibility) catches most of these pre-merge"

  - condition: "E2E flow fails after all slices complete"
    action: |
      Identify slice from Integration Map → Connections table
      Create fix-task with slice reference + flow-step number
      Re-run affected slice tests
      Re-run E2E flow from earliest impacted step
    note: "May require slice-spec update if root cause is contract gap (would also require re-running Gate 3 integration-map regeneration)"

  - condition: "Cycle detected during orchestration"
    action: "Abort. Re-run Gate 3 with strict cycle-check"
    note: "Current Integration Map verified acyclic. New cycle = bad slice-spec edit."
```

---

## Monitoring (During Implementation)

| Metric | Alert Threshold | Action |
|--------|-----------------|--------|
| Slice completion time | > 2× initial estimate | Pause and investigate; spec may underestimate |
| Test failures | > 0 blocking | Halt orchestration on slice-N until fixed |
| Deliverable missing after impl | Any | Halt; rollback |
| Integration test fail | Any | Halt; check Connections table |
| `composer dump-autoload` warning/error | Any | Fix PSR-4 mapping in slice's composer.json |
| Action-Scheduler queue stuck | > 100 pending after sync | Use `Tools → Scheduled Actions` to inspect; check WC-CRON setup |
| Webhook 401 rate | > 1% of incoming | Inspect HMAC-Secret-Sync between SC and WP |
| Failed-Ops growth | > 10/h | Investigate which `op_type` dominates; likely a permanent bug |

---

## Notes for Orchestrator

1. **PSR-4 autoload**: Root `composer.json` maps `SpreadconnectPod\` to `wordpress/plugins/spreadconnect-pod/includes/`. Slice 01 deletes the directory but leaves the mapping intact — slice 02 recreates the directory and adds a plugin-local `composer.json` (additive, the plugin's own `composer install` populates `vendor/`). Run `composer dump-autoload` at root once after slice 02.

2. **Plugin-local PHPUnit**: Slice 02 establishes `wordpress/plugins/spreadconnect-pod/phpunit.xml` and `tests/bootstrap/bootstrap.php` (with WP-stubs for `WP_REST_Request`, `WP_REST_Response`, `WP_Error`, etc.). All later slice tests reuse this bootstrap via Brain\Monkey + Patchwork. Root `composer test` aggregates both root-level and plugin-level test paths.

3. **Patchwork redefinitions**: Slice 02 establishes `wordpress/plugins/spreadconnect-pod/patchwork.json` listing the function-redefinitions needed across slices: `hash_equals` (slice 15), and any other internal PHP functions required for constant-time-compare or rate-limit-deterministic-test reproduction.

4. **AS-action group**: All AS-actions use group string `'spreadconnect'` for unified queue management (per Architecture Z. 558).

5. **Logger sources**: Use exactly the 6 source-strings: `spreadconnect-api-client`, `spreadconnect-order-service`, `spreadconnect-webhook-receiver`, `spreadconnect-sync-job`, `spreadconnect-failure`, `spreadconnect-cli`. Slice 42 builds the WcLoggerAdapter on top, but earlier slices may use `wc_get_logger()->info(..., ['source' => ...])` directly with the same source-strings — refactor to adapter is non-breaking.

6. **Cron-context safety**: Slice 21's `ImageSideloader::ensureAdminIncludesLoaded()` is the canonical pattern. Slice 23 calls it before any `media_sideload_image()` invocation. No other slice should re-implement this guard.

7. **Bootstrap edits accumulate**: `includes/Bootstrap/Plugin.php` is modified by 17 slices (each adds an `add_action(...)` registration in `init()`). Orchestrator should use a strict line-append discipline: each slice's edit appends to the bottom of the registration block, never reorders or removes prior registrations.

8. **Settings View edits accumulate**: `includes/Hub/View/Settings.php` is modified by 5 slices (11 creates; 12, 14, 44, 45 add sections). Each edit appends a non-overlapping section. Orchestrator should verify section-keys are unique.

9. **Compliance-Gate pass**: All 46 slices have `VERDICT: APPROVED` in `compliance-slice-*.md`. No re-run of Gate 2 is required during orchestration unless a slice-spec is edited mid-flight.

10. **Greenfield**: Slice 01 is purely subtractive. Architecture explicitly approved this Greenfield reset (no migration of v1 data). Project uses no live SC-data on production yet, so no real-world data loss.

11. **Test File Naming Convention (PHPUnit 11 compatibility):**
    Test files MUST be named `Slice<NN><PascalCaseTopic>Test.php` with the class declared as `final class Slice<NN><PascalCaseTopic>Test extends TestCase`. PHPUnit 11.5.55 derives the class name from the filename basename, so the legacy `slice-NN-<topic>.php` naming produces an invalid PHP class name (hyphens). The slice-NN prefix is preserved in the new convention so test files still sort by slice order in the directory listing.

    Examples:
    - Slice 01 cleanup: `Slice01CleanupV1Test.php` / `Slice01CleanupV1Test`
    - Slice 02 plugin-bootstrap: `Slice02PluginBootstrapTest.php` / `Slice02PluginBootstrapTest`
    - Slice 23 sync-article-job: `Slice23SyncArticleJobTest.php` / `Slice23SyncArticleJobTest`

    The slice-spec `Test Skeletons` sections retain the old naming for documentation purposes; the test-writer agent applies the new convention when materializing the file.

---

## Final Verdict

**VERDICT: READY FOR ORCHESTRATION**

- 46/46 slices APPROVED
- 0 missing inputs
- 0 orphaned outputs
- 0 deliverable-consumer gaps
- 0 runtime-path gaps
- 0 semantic-consistency gaps
- 100% Discovery coverage

The dependency graph is acyclic. Implementation may begin at slice 01.
