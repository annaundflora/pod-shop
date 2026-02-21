---
description: "Slice-Planner mit Fresh Context Pattern. Erstellt alle Slices + Gate 2 (pro Slice) + Gate 3. Task Calls direkt im Command, max 3 Retries pro Gate."
---

# Slice Planner Command

Du führst das **Slice-Planning** mit automatischen **Gate 2 und Gate 3 Compliance Checks** aus.

**Input:** $ARGUMENTS (Spec-Pfad, z.B. `specs/2026-01-31-pin-erstellung`)

---

## Phase 1: Input-Validierung

```
1. Prüfe ob $ARGUMENTS einen Spec-Pfad enthält
2. Falls kein Argument: Suche neuestes specs/*/discovery.md und frage via AskUserQuestion
3. Validiere dass folgende Dateien existieren:

REQUIRED (Block wenn fehlt):
├── {spec_path}/discovery.md         → Feature-Anforderungen
├── {spec_path}/architecture.md      → Technische Architektur

OPTIONAL (Warning wenn fehlt):
├── {spec_path}/wireframes.md        → UI-Spezifikationen
├── {spec_path}/compliance-architecture.md  → Gate 1 Report

IF discovery.md MISSING:
  OUTPUT: "❌ STOP: discovery.md fehlt. Zuerst /discovery ausführen."
  HARD STOP

IF architecture.md MISSING:
  OUTPUT: "❌ STOP: architecture.md fehlt. Zuerst /architecture ausführen."
  HARD STOP

spec_path = [ermittelter Spec-Ordner]
```

---

## Phase 2: Setup & State Management

```
STATE_FILE = "{spec_path}/.planner-state.json"

# ─────────────────────────────────────────────────────────
# Step 1: Check for existing state (Resume Support)
# ─────────────────────────────────────────────────────────

IF EXISTS STATE_FILE:
  state = Read(STATE_FILE)

  IF state.status == "completed":
    OUTPUT: "✅ Planning bereits abgeschlossen. Für Neustart: Lösche {STATE_FILE}"
    STOP

  IF state.status == "in_progress":
    OUTPUT: "
    ═══════════════════════════════════════════════════════════
    🔄 RESUME: Fortsetzen von Slice {state.current_slice}/{state.total_slices}
    ═══════════════════════════════════════════════════════════
    Bereits approved: {state.approved_slices}
    Letzter Status: {state.last_action}
    "
    # Setze Variablen aus State
    slices = state.slices
    approved_slices = state.approved_slices
    current_index = state.current_slice_index
    SKIP to Phase 3 at current_index

# ─────────────────────────────────────────────────────────
# Step 2: Fresh Start
# ─────────────────────────────────────────────────────────

1. Erstelle slices/ Ordner falls nicht vorhanden:
   mkdir -p {spec_path}/slices

2. Lies {spec_path}/discovery.md
3. Extrahiere Slice-Liste aus Section "## Implementation Slices" oder "## Slices"
4. Falls keine Slices definiert:
   AskUserQuestion: "Keine Slices in Discovery gefunden. Wie soll das Feature aufgeteilt werden?"

# ─────────────────────────────────────────────────────────
# Step 3: Initialize State
# ─────────────────────────────────────────────────────────

state = {
  "spec_path": spec_path,
  "status": "in_progress",
  "phase": "slice_planning",
  "started_at": ISO_TIMESTAMP,
  "total_slices": len(slices),
  "current_slice_index": 0,
  "slices": [
    { "number": 1, "name": "...", "status": "pending", "retries": 0 },
    ...
  ],
  "approved_slices": [],
  "failed_slices": [],
  "last_action": "Initialized",
  "last_updated": ISO_TIMESTAMP
}

Write(STATE_FILE, JSON.stringify(state, indent=2))
OUTPUT: "📊 State initialisiert: {STATE_FILE}"
```

---

## Phase 3: Slice Planning Loop (KRITISCH!)

**WICHTIG: DU führst die Task Calls aus, NICHT ein anderer Agent!**

```
MAX_RETRIES = 3

FOR each slice IN slices:
  retry_count = 0
  slice_path = "{spec_path}/slices/slice-{NN}-{slug}.md"

  OUTPUT: "
  ═══════════════════════════════════════════════════════════
  📋 SLICE {slice.number}/{total}: {slice.name}
  ═══════════════════════════════════════════════════════════
  "

  WHILE retry_count < MAX_RETRIES:

    # ─────────────────────────────────────────────────────────
    # Step 1: Slice erstellen mit FRISCHEM CONTEXT
    # ─────────────────────────────────────────────────────────

    IF retry_count == 0:
      # Erste Erstellung
      slice_result = Task(
        subagent_type: "slice-writer",
        description: "Write Slice {slice.number}",
        prompt: "
          Erstelle Slice {slice.number}: {slice.name}

          ## Input-Dateien (MUSS gelesen werden)
          - {spec_path}/architecture.md
          - {spec_path}/wireframes.md (falls vorhanden)
          - {spec_path}/discovery.md (für Kontext)
          - Vorherige genehmigte Slices: {approved_slices_paths}

          ## Slice-Anforderungen
          {slice.description}
          Dependencies: {slice.dependencies}

          ## Output
          Schreibe: {spec_path}/slices/slice-{NN}-{slug}.md

          ## KRITISCH - Template-Pflicht
          Lies .claude/templates/plan-spec.md und stelle sicher:
          - Metadata Section mit ID, Test, E2E, Dependencies
          - Integration Contract Section (PFLICHT!)
          - Code Examples MANDATORY Section
          - DELIVERABLES_START/END Marker
          - Alle ACs im GIVEN/WHEN/THEN Format
        "
      )
    ELSE:
      # Fix-Versuch mit Compliance-Feedback
      slice_result = Task(
        subagent_type: "slice-writer",
        description: "Fix Slice {slice.number}",
        prompt: "
          FIX Slice {slice.number}: {slice.name}

          ## Compliance-Fehler (MUSS gefixt werden)
          Lies: {spec_path}/slices/compliance-slice-{NN}.md

          ## Blocking Issues
          {blocking_issues_summary}

          ## Anweisungen
          1. Lies den Compliance-Report vollständig
          2. Fixe ALLE Blocking Issues
          3. Aktualisiere: {spec_path}/slices/slice-{NN}-{slug}.md
        "
      )

    # ─────────────────────────────────────────────────────────
    # Step 2: VALIDATION CHECKPOINT
    # ─────────────────────────────────────────────────────────

    # Prüfe ob Slice-Datei existiert
    IF NOT EXISTS {spec_path}/slices/slice-{NN}-*.md:
      OUTPUT: "❌ CHECKPOINT FAILED: Slice-Datei wurde nicht erstellt"
      retry_count++
      CONTINUE

    # ─────────────────────────────────────────────────────────
    # Step 3: Gate 2 Compliance mit FRISCHEM CONTEXT
    # ─────────────────────────────────────────────────────────

    OUTPUT: "🔍 Gate 2: Compliance Check für Slice {slice.number}..."

    compliance_result = Task(
      subagent_type: "slice-compliance",
      description: "Gate 2 Check Slice {slice.number}",
      prompt: "
        Prüfe Slice Compliance.

        ## Zu prüfender Slice
        {spec_path}/slices/slice-{NN}-{slug}.md

        ## Referenz-Dokumente
        - {spec_path}/architecture.md
        - {spec_path}/wireframes.md
        - Vorherige genehmigte Slices: {approved_slices_paths}

        ## Output
        Schreibe: {spec_path}/slices/compliance-slice-{NN}.md

        ## KRITISCH - Template-Compliance prüfen!
        Prüfe ob diese Sections existieren:
        - [ ] Metadata Section (ID, Test, E2E, Dependencies)
        - [ ] Integration Contract Section
        - [ ] DELIVERABLES_START/END Marker
        - [ ] Code Examples MANDATORY Section
        FEHLENDE SECTIONS = BLOCKING ISSUE!

        Am Ende MUSS stehen:
        VERDICT: APPROVED oder VERDICT: FAILED

        Falls FAILED, liste alle BLOCKING_ISSUES auf.
      "
    )

    # ─────────────────────────────────────────────────────────
    # Step 4: Verdict prüfen
    # ─────────────────────────────────────────────────────────

    # Lies Compliance-Report
    compliance_report = Read({spec_path}/slices/compliance-slice-{NN}.md)

    IF compliance_report CONTAINS "VERDICT: APPROVED":
      OUTPUT: "✅ Slice {slice.number} APPROVED"
      approved_slices.append(slice)

      # ─── State Update: Slice Approved ───
      state.slices[slice.index].status = "approved"
      state.approved_slices.append(slice.number)
      state.current_slice_index = slice.index + 1
      state.last_action = "Slice {slice.number} approved"
      state.last_updated = ISO_TIMESTAMP
      Write(STATE_FILE, JSON.stringify(state, indent=2))

      BREAK  # Weiter zum nächsten Slice

    IF compliance_report CONTAINS "VERDICT: FAILED":
      retry_count++

      # Blocking Issues extrahieren für nächsten Fix-Versuch
      blocking_issues_summary = extract_blocking_issues(compliance_report)

      # ─── State Update: Slice Failed (Retry) ───
      state.slices[slice.index].status = "retrying"
      state.slices[slice.index].retries = retry_count
      state.last_action = "Slice {slice.number} failed, retry {retry_count}/3"
      state.last_updated = ISO_TIMESTAMP
      Write(STATE_FILE, JSON.stringify(state, indent=2))

      IF retry_count >= MAX_RETRIES:
        # ─── State Update: Hard Stop ───
        state.status = "failed"
        state.slices[slice.index].status = "failed"
        state.failed_slices.append(slice.number)
        state.last_action = "HARD STOP: Slice {slice.number} nach 3 Versuchen"
        state.completed_at = ISO_TIMESTAMP
        Write(STATE_FILE, JSON.stringify(state, indent=2))

        OUTPUT: "
        ╔════════════════════════════════════════════════════════════╗
        ║  ❌ HARD STOP: Slice {slice.number} nach 3 Versuchen       ║
        ╠════════════════════════════════════════════════════════════╣
        ║                                                            ║
        ║  Blocking Issues:                                          ║
        ║  {blocking_issues_summary}                                 ║
        ║                                                            ║
        ║  Nächste Schritte:                                         ║
        ║  1. Manuell fixen: {slice_path}                            ║
        ║  2. /planner {spec_path} erneut starten                    ║
        ║                                                            ║
        ║  State: {STATE_FILE}                                       ║
        ╚════════════════════════════════════════════════════════════╝
        "
        HARD STOP  # Beende gesamten Planner

      OUTPUT: "⚠️ Slice {slice.number} FAILED (Versuch {retry_count}/3) → Auto-Fix..."
      # Loop continues mit Fix-Versuch
```

---

## Phase 4: Gate 3 - Integration Validation

```
OUTPUT: "
═══════════════════════════════════════════════════════════
🔗 PHASE 4: Integration Validation (Gate 3)
═══════════════════════════════════════════════════════════
"

# ─── State Update: Enter Gate 3 ───
state.phase = "gate_3_integration"
state.last_action = "Entering Gate 3"
state.last_updated = ISO_TIMESTAMP
Write(STATE_FILE, JSON.stringify(state, indent=2))

retry_count = 0
MAX_RETRIES = 3

WHILE retry_count < MAX_RETRIES:

  # ─────────────────────────────────────────────────────────
  # Gate 3: Integration Map erstellen mit FRISCHEM CONTEXT
  # ─────────────────────────────────────────────────────────

  integration_result = Task(
    subagent_type: "integration-map",
    description: "Gate 3 Integration Map",
    prompt: "
      Erstelle Integration Map für: {spec_path}

      ## Input
      - Alle Slices: {spec_path}/slices/slice-*.md
      - Alle Compliance Reports: {spec_path}/slices/compliance-slice-*.md

      ## Output (alle 3 PFLICHT)
      1. {spec_path}/integration-map.md
      2. {spec_path}/e2e-checklist.md
      3. {spec_path}/orchestrator-config.md

      ## KRITISCH
      Am Ende MUSS stehen:
      VERDICT: READY FOR ORCHESTRATION oder VERDICT: GAPS FOUND

      Falls GAPS FOUND:
      - MISSING_INPUTS: [Liste]
      - ORPHANED_OUTPUTS: [Liste]
      - AFFECTED_SLICES: [welche Slices müssen gefixt werden]
    "
  )

  # Lies Integration Map
  integration_map = Read({spec_path}/integration-map.md)

  IF integration_map CONTAINS "VERDICT: READY FOR ORCHESTRATION":
    # ─── State Update: Planning Completed Successfully ───
    state.status = "completed"
    state.phase = "completed"
    state.last_action = "Gate 3 APPROVED - Ready for Orchestration"
    state.completed_at = ISO_TIMESTAMP
    Write(STATE_FILE, JSON.stringify(state, indent=2))

    OUTPUT: "
    ╔════════════════════════════════════════════════════════════╗
    ║  ✅ GATE 3 APPROVED - Integration vollständig              ║
    ╠════════════════════════════════════════════════════════════╣
    ║                                                            ║
    ║  Erstellte Dateien:                                        ║
    ║  ✓ integration-map.md                                      ║
    ║  ✓ e2e-checklist.md                                        ║
    ║  ✓ orchestrator-config.md                                  ║
    ║                                                            ║
    ║  State: {STATE_FILE} (status: completed)                   ║
    ║                                                            ║
    ║  Nächster Schritt:                                         ║
    ║  /orchestrate {spec_path}                                  ║
    ║                                                            ║
    ╚════════════════════════════════════════════════════════════╝
    "
    STOP  # Erfolg!

  IF integration_map CONTAINS "VERDICT: GAPS FOUND":
    retry_count++

    # ─── State Update: Gate 3 Retry ───
    state.gate3_retries = retry_count
    state.last_action = "Gate 3 failed, retry {retry_count}/3"
    state.last_updated = ISO_TIMESTAMP
    Write(STATE_FILE, JSON.stringify(state, indent=2))

    IF retry_count >= MAX_RETRIES:
      # ─── State Update: Gate 3 Hard Stop ───
      state.status = "failed"
      state.last_action = "HARD STOP: Gate 3 nach 3 Versuchen"
      state.completed_at = ISO_TIMESTAMP
      Write(STATE_FILE, JSON.stringify(state, indent=2))

      OUTPUT: "
      ╔════════════════════════════════════════════════════════════╗
      ║  ❌ HARD STOP: Gate 3 nach 3 Versuchen fehlgeschlagen      ║
      ╠════════════════════════════════════════════════════════════╣
      ║                                                            ║
      ║  Gaps:                                                     ║
      ║  {gaps_summary}                                            ║
      ║                                                            ║
      ║  Betroffene Slices:                                        ║
      ║  {affected_slices}                                         ║
      ║                                                            ║
      ║  State: {STATE_FILE} (status: failed)                      ║
      ║                                                            ║
      ║  Nächste Schritte:                                         ║
      ║  1. Betroffene Slices manuell fixen                        ║
      ║  2. /planner {spec_path} erneut starten                    ║
      ║                                                            ║
      ╚════════════════════════════════════════════════════════════╝
      "
      HARD STOP

    OUTPUT: "⚠️ Gate 3 GAPS FOUND (Versuch {retry_count}/3) → Fixe betroffene Slices..."

    # Fixe betroffene Slices
    FOR each affected_slice IN gaps.affected_slices:
      Task(
        subagent_type: "slice-writer",
        description: "Fix Integration Gap in {affected_slice}",
        prompt: "
          FIX Integration Gap in Slice {affected_slice}

          Problem: {gap.description}
          Action: {gap.action}

          Lies: {spec_path}/integration-map.md für Details
          Aktualisiere: {spec_path}/slices/{affected_slice}.md
        "
      )

    # Loop continues mit Re-Validation
```

---

## Output

Nach erfolgreichem Durchlauf:

```
{spec_path}/
├── .planner-state.json                 # State Tracking ✓
├── discovery.md                        # Input (existiert)
├── wireframes.md                       # Input (existiert)
├── architecture.md                     # Input (existiert)
├── compliance-discovery-wireframe.md   # Gate 0 (falls vorhanden)
├── compliance-architecture.md          # Gate 1 (falls vorhanden)
├── integration-map.md                  # Gate 3 Output ✓
├── e2e-checklist.md                    # Gate 3 Output ✓
├── orchestrator-config.md              # Gate 3 Output ✓
└── slices/
    ├── slice-01-{name}.md
    ├── compliance-slice-01.md          # Gate 2 ✓
    ├── slice-02-{name}.md
    ├── compliance-slice-02.md          # Gate 2 ✓
    └── ...
```

---

## State File Format

Die `.planner-state.json` ermöglicht Resume und Audit:

```json
{
  "spec_path": "specs/2026-01-28-feature-name",
  "status": "in_progress" | "completed" | "failed",
  "phase": "slice_planning" | "gate_3_integration" | "completed",
  "started_at": "2026-01-28T10:00:00Z",
  "completed_at": "2026-01-28T12:00:00Z",
  "total_slices": 6,
  "current_slice_index": 3,
  "slices": [
    { "number": 1, "name": "db-schema", "status": "approved", "retries": 0 },
    { "number": 2, "name": "api", "status": "approved", "retries": 1 },
    { "number": 3, "name": "ui", "status": "pending", "retries": 0 }
  ],
  "approved_slices": [1, 2],
  "failed_slices": [],
  "gate3_retries": 0,
  "last_action": "Slice 2 approved",
  "last_updated": "2026-01-28T11:30:00Z"
}
```

**Status Values:**
| Status | Bedeutung |
|--------|-----------|
| `in_progress` | Planning läuft, Resume möglich |
| `completed` | Alle Slices + Gate 3 approved |
| `failed` | HARD STOP nach 3 Retries |

**Resume:** Bei `status: in_progress` setzt der Planner bei `current_slice_index` fort.

---

## Wichtig: Fresh Context Pattern

**KRITISCH:** Jeder Task Call startet einen Sub-Agent mit FRISCHEM Context:

| Task Call | Warum Fresh Context |
|-----------|---------------------|
| `slice-writer` | Sieht nicht was vorher passiert ist, keine Vorurteile |
| `slice-compliance` | Objektive Prüfung ohne Planungs-Bias |
| `integration-map` | Sieht alle Slices neutral, keine Favoriten |

**Das verhindert:**
- Confirmation Bias (eigene Fehler nicht sehen)
- Context Pollution (zu viel irrelevante Info)
- Scope Creep (Abweichungen werden erkannt)

**Quelle:** Anthropic "Subagents get their own fresh context, completely separate from main conversation"

---

## Referenzen

- Slice Writer: `.claude/agents/slice-writer.md`
- Slice Compliance: `.claude/agents/slice-compliance.md`
- Integration Map: `.claude/agents/integration-map.md`
- Slice Template: `.claude/templates/plan-spec.md`
