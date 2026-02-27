---
description: "Feature-Orchestrator mit Sub-Agent Pipeline. Implementiert Features wave-by-wave mit 4 Sub-Agent-Steps (Implementer -> Test-Writer -> Test-Validator -> Debugger), JSON-Parsing, 9 Retries und stack-agnostischer Final Validation."
---

# Orchestrate Feature Implementation

Du orchestrierst die Implementierung eines Features slice-by-slice mit Sub-Agent Pipeline.

**KRITISCHE REGELN (KEINE Ausnahmen):**
1. **Autonomer Betrieb:** Frage NIEMALS zwischen Waves oder Slices nach Bestaetigung.
2. **Exit Code ist Wahrheit:** exit_code != 0 = FEHLGESCHLAGEN. Immer.
3. **Kein direktes Bash:** Du fuehrst KEINE Tests, Lint, Type-Check oder Build direkt aus. ALLES via Sub-Agents.
4. **JSON-Parsing:** Jeder Sub-Agent-Output wird als JSON geparsed (letzter ```json``` Block). Bei Parse-Failure: HARD STOP.
5. **9 Retries:** Max 9 Debugger-Retries pro Slice. Danach HARD STOP.

**Input:** $ARGUMENTS (Spec-Pfad)

---

## Phase 1: Input-Validierung & Pre-Impl Sanity Check

```
1. Pruefe ob $ARGUMENTS einen Spec-Pfad enthaelt
2. Falls kein Argument: Suche neuestes specs/*/orchestrator-config.md

3. Validiere Required Outputs:
   REQUIRED:
   - {spec_path}/orchestrator-config.md
   - {spec_path}/slices/slice-*.md
   - {spec_path}/slices/compliance-slice-*.md (MUSS "APPROVED" enthalten)

   IF ANY REQUIRED MISSING OR NOT APPROVED:
     HARD STOP: "Planner muss zuerst laufen."

4. Parse orchestrator-config.md
```

---

## Phase 1b: Dependency Pre-Flight Check

```
# Stack-agnostische Dependency-Validierung
# Erkennt automatisch welche Package-Manager im Projekt existieren

dependency_files = {
  "package.json":        "npm install / pnpm install",
  "requirements.txt":    "pip install -r requirements.txt",
  "pyproject.toml":      "pip install -e . / poetry install",
  "go.mod":              "go mod download",
  "Cargo.toml":          "cargo check",
  "Gemfile":             "bundle install",
}

FOR each (file, install_cmd) IN dependency_files:
  locations = Glob("**/{file}", exclude=["node_modules", ".venv", "vendor"])
  FOR each location IN locations:
    dir = dirname(location)

    # Step 1: Install dependencies (catches version conflicts)
    result = Bash("{install_cmd}", cwd=dir)
    IF result.exit_code != 0:
      HARD STOP: "Dependency install failed in {dir}. Fix before implementation."

    # Step 2: Smoke-test imports (catches runtime incompatibilities)
    # Extrahiere kritische Dependencies aus Architecture Integrations-Tabelle
    # und pruefe ob sie importierbar sind
    IF file == "package.json":
      Bash("npx tsc --noEmit 2>&1 | head -20", cwd=dir)
    ELIF file in ["requirements.txt", "pyproject.toml"]:
      # Importiere jede Dependency einmal
      deps = parse_dependencies(location)
      FOR each dep IN deps:
        Bash("python -c 'import {dep}'", cwd=dir)

IF ANY check failed:
  HARD STOP: "Dependency Pre-Flight fehlgeschlagen. Behebe Konflikte vor Implementierung."
```

---

## Phase 2: Setup & State Management

```
STATE_FILE = "{spec_path}/.orchestrator-state.json"
EVIDENCE_DIR = ".claude/evidence/{feature_name}/"

# State Management mit erweiterten Feldern
state = {
  "spec_path": spec_path,
  "feature_name": feature_name,
  "status": "in_progress",
  "current_state": "pre_check",
  "current_slice_id": null,
  "retry_count": 0,
  "failed_stage": null,
  "waves": [...],
  "completed_slices": [],
  "evidence_files": []
}

# Resume Support wie bisher
IF EXISTS STATE_FILE:
  # Resume-Logik
```

---

## Helper: JSON-Parsing

```
FUNCTION parse_agent_json(agent_output):
  # Finde den LETZTEN ```json``` Block
  json_blocks = regex_find_all(agent_output, /```json\s*\n(.*?)```/s)
  IF json_blocks.length == 0:
    HARD STOP: "Agent hat keinen JSON-Output geliefert"
  last_json = json_blocks[-1]
  TRY:
    parsed = JSON.parse(last_json)
    RETURN parsed
  CATCH:
    HARD STOP: "JSON Parse Failure"
```

---

## Phase 3: Wave-Based Implementation

```
FOR each wave IN waves:
  FOR each slice_id IN wave.slices:

    # ── Step 1: Task(slice-implementer) → Code ──
    state.current_state = "implementing"
    Write(STATE_FILE, state)

    impl_result = Task(
      subagent_type: "slice-implementer",
      prompt: "
        Implementiere {slice_id}.
        Slice-Spec: {spec_file}
        Architecture: {architecture_file}
        Integration-Map: {integration_map_file}

        REGELN:
        1. Lies die Slice-Spec vollstaendig
        2. Implementiere NUR was dort steht
        3. Du schreibst NUR Code, KEINE Tests. Der Test-Writer Agent uebernimmt Tests.
        4. Committe mit: git add -A && git commit -m 'feat({slice_id}): ...'
      "
    )

    impl_json = parse_agent_json(impl_result)
    IF impl_json.status == "failed":
      HARD STOP: "Implementer failed: {impl_json.notes}"

    # ── Step 2: Task(test-writer) → Tests ──
    state.current_state = "writing_tests"
    Write(STATE_FILE, state)

    test_writer_result = Task(
      subagent_type: "test-writer",
      prompt: "
        Schreibe Tests fuer {slice_id}.
        Slice-Spec (ACs): {spec_file}
        Geaenderte Dateien: {impl_json.files_changed}
        Schreibe Tests gegen die Spec-ACs, nicht gegen den Code.
      "
    )

    tw_json = parse_agent_json(test_writer_result)
    IF tw_json.status == "failed":
      HARD STOP: "Test-Writer failed: Spec-Problem"
    IF tw_json.ac_coverage.total != tw_json.ac_coverage.covered:
      HARD STOP: "AC-Coverage nicht 100%. Fehlend: {tw_json.ac_coverage.missing}"

    # ── Step 3: Task(test-validator) → Validate ──
    state.current_state = "validating"
    state.retry_count = 0
    Write(STATE_FILE, state)

    validator_result = Task(
      subagent_type: "test-validator",
      prompt: "
        Validiere {slice_id}.
        Mode: slice_validation
        Test-Paths: {tw_json.test_files}
        Previous-Slice-Tests: {get_previous_test_paths(completed_slices)}
        Working-Directory: {working_dir}
      "
    )

    val_json = parse_agent_json(validator_result)

    # ── Step 4: Retry Loop (max 9x) ──
    MAX_RETRIES = 9
    WHILE val_json.overall_status == "failed" AND state.retry_count < MAX_RETRIES:
      state.retry_count += 1
      state.current_state = "auto_fixing"
      state.failed_stage = val_json.failed_stage
      Write(STATE_FILE, state)

      fix_result = Task(
        subagent_type: "debugger",
        prompt: "
          Tests fuer {slice_id} sind fehlgeschlagen.
          Failed Stage: {val_json.failed_stage}
          Error Output: {val_json.error_output}
          Slice-Spec: {spec_file}
          Geaenderte Dateien: {impl_json.files_changed}
          Fixe den Code (NICHT die Tests aufweichen!).
        "
      )

      fix_json = parse_agent_json(fix_result)
      IF fix_json.status == "unable_to_fix":
        HARD STOP: "Debugger unable to fix: {fix_json.root_cause}"

      # Re-validate
      state.current_state = "validating"
      Write(STATE_FILE, state)

      validator_result = Task(
        subagent_type: "test-validator",
        prompt: "
          Re-Validiere {slice_id} nach Fix.
          Mode: slice_validation
          Test-Paths: {tw_json.test_files}
          Previous-Slice-Tests: {get_previous_test_paths(completed_slices)}
          Working-Directory: {working_dir}
        "
      )
      val_json = parse_agent_json(validator_result)

    IF val_json.overall_status == "failed":
      HARD STOP: "9 Retries erschoepft fuer {slice_id}"

    # ── Evidence speichern ──
    state.current_state = "slice_complete"
    evidence = {
      "feature": feature_name,
      "slice": slice_id,
      "timestamp": ISO_TIMESTAMP,
      "status": "completed",
      "implementation": impl_json,  # { status, files_changed, commit_hash, notes }
      "tests": tw_json,              # { status, test_files, test_count, ac_coverage: { total, covered, missing } }
      "validation": val_json,        # { overall_status, stages: { tests, lint, typecheck }, failed_stage?, error_output? }
      "retries": state.retry_count
    }
    Write("{EVIDENCE_DIR}/{slice_id}.json", evidence)
```

---

## Phase 4: Final Validation

```
state.current_state = "final_validation"
Write(STATE_FILE, state)

final_result = Task(
  subagent_type: "test-validator",
  prompt: "
    Final Validation fuer Feature {feature_name}.
    Mode: final_validation
    Previous-Slice-Tests: {get_all_test_paths(completed_slices)}
    Working-Directory: {working_dir}
  "
)

final_json = parse_agent_json(final_result)

# Retry bei Failure (max 9x)
final_retry = 0
WHILE final_json.overall_status == "failed" AND final_retry < MAX_RETRIES:
  final_retry += 1
  fix_result = Task(subagent_type: "debugger", ...)
  fix_json = parse_agent_json(fix_result)
  IF fix_json.status == "unable_to_fix": HARD STOP
  final_result = Task(subagent_type: "test-validator", mode: final_validation, ...)
  final_json = parse_agent_json(final_result)

IF final_json.overall_status == "failed":
  HARD STOP: "Final Validation fehlgeschlagen nach 9 Retries"
```

---

## Phase 5: Completion

```
state.current_state = "feature_complete"
# Feature Evidence, Branch Info, Naechste Schritte
```
