---
description: "Erstellt Architecture + automatische Gate 1 Compliance (max 3 Retries). Basiert auf Anthropic Fresh Context Pattern."
---

# Architecture mit Gate 1 Compliance

Du führst den **Architecture-Agent** mit automatischer **Gate 1 Compliance** aus.

**Input:** $ARGUMENTS

---

## Phase 1: Input-Validierung

1. Prüfe ob `$ARGUMENTS` einen Spec-Pfad enthält
2. Falls kein Argument: Suche neuestes `specs/*/discovery.md` und frage via AskUserQuestion
3. Validiere dass folgende Dateien existieren:
   - `discovery.md` → MUST exist
   - `wireframes.md` → SHOULD exist (Warning wenn fehlt)
   - `compliance-discovery-wireframe.md` → SHOULD exist (Gate 0 sollte gelaufen sein)

4. Falls `wireframes.md` fehlt:
   - Frage via AskUserQuestion: "wireframes.md fehlt. Zuerst /wireframe ausführen?"
   - Bei "Ja" → STOP mit Hinweis "/wireframe {spec_path}"
   - Bei "Nein" → Warnung ausgeben und fortfahren

**Spec-Pfad ermitteln:** Extrahiere den Ordnerpfad aus $ARGUMENTS oder dem gefundenen Discovery

---

## Phase 2: Architecture-Erstellung

1. Lies die Agent-Definition: `.claude/agents/architecture.md`
2. Übernimm die dort beschriebenen Workflows und Verhaltensweisen
3. Führe den Architecture-Workflow durch:
   - 🔍 DIVERGE: Recherche (Codebase, Web, Git-History)
   - 🎯 CONVERGE: Scope → Details → Architecture-Doc
4. Erstelle `architecture.md` nach dem Template
5. Speichere im Spec-Ordner

---

## Phase 3: Gate 1 Compliance Loop

Nach dem Erstellen/Fixen von architecture.md:

```
retry_count = 0
MAX_RETRIES = 3
spec_path = [ermittelter Spec-Ordner]

LOOP:
  # Compliance Check mit FRISCHEM CONTEXT (Anthropic Pattern)
  # "Subagents get their own fresh context, completely separate from main conversation"

  compliance_result = Task(
    subagent_type: "architecture-compliance",
    prompt: "Prüfe Architecture Compliance für: {spec_path}

    Lies:
    - {spec_path}/discovery.md
    - {spec_path}/wireframes.md
    - {spec_path}/architecture.md

    Führe durch:
    - Feature Mapping Check
    - Constraint Mapping Check
    - Realistic Data Check (KRITISCH!)
    - Completeness Check

    Erstelle: {spec_path}/compliance-architecture.md

    Returne am Ende:
    VERDICT: APPROVED oder FAILED
    BLOCKING_ISSUES: [Liste falls FAILED]
    DATA_TYPE_VERDICTS: [Liste der Datentyp-Checks]
    RECOMMENDATIONS: [Liste der Empfehlungen]"
  )

  # Parse das Ergebnis
  IF compliance_result enthält "VERDICT: APPROVED":
    OUTPUT an User:
    "✅ **Gate 1 APPROVED**

    Architecture ist konsistent mit Discovery und Wireframes.
    Realistic Data Check bestanden.
    - architecture.md ✓
    - compliance-architecture.md ✓

    **Nächster Schritt:** /planner {spec_path}"
    STOP (Erfolg)

  IF compliance_result enthält "VERDICT: FAILED":
    retry_count++

    IF retry_count >= MAX_RETRIES:
      OUTPUT an User:
      "❌ **HARD EXIT: Gate 1 nach 3 Versuchen fehlgeschlagen**

      Blocking Issues:
      {compliance_result.blocking_issues}

      Data Type Issues:
      {compliance_result.data_type_verdicts}

      **Manuelle Korrektur erforderlich.**
      Bitte architecture.md manuell fixen und /architecture erneut starten."
      STOP (Fehler)

    # Fix-Versuch
    OUTPUT an User:
    "⚠️ **Gate 1 FAILED** (Versuch {retry_count}/3)

    Blocking Issues: {compliance_result.blocking_issues}
    → Versuche automatischen Fix..."

    # Architecture fixen
    Lies erneut `.claude/agents/architecture.md`
    Lies `{spec_path}/compliance-architecture.md` für die Issues
    Korrigiere `{spec_path}/architecture.md` basierend auf:
    - Blocking Issues (Feature Mapping, Constraints)
    - Data Type Verdicts (Realistic Data Check)
    - Recommendations

    GOTO LOOP
```

---

## Output

Nach erfolgreichem Durchlauf:

```
{spec_path}/
├── discovery.md                        # Input (existiert)
├── wireframes.md                       # Input (existiert)
├── compliance-discovery-wireframe.md   # Input (Gate 0)
├── architecture.md                     # NEU erstellt
└── compliance-architecture.md          # NEU erstellt (Gate 1)
```

---

## Wichtig: Fresh Context Pattern

Jeder Gate-Check läuft als **separater Sub-Agent** mit eigenem Context:
- Der Compliance-Agent sieht NICHT den Architecture-Erstellungs-Context
- Das verhindert Confirmation Bias bei Datentyp-Entscheidungen
- Realistic Data Check basiert NUR auf Evidenz (Codebase + External APIs)
- Quelle: Anthropic "Subagents get their own fresh context"

---

## Realistic Data Check (Gate 1 Spezialität)

Der Compliance-Agent führt einen **evidenz-basierten Datentyp-Check** durch:

1. **Codebase Evidence:** Suche nach existierenden Patterns für URLs, Strings, etc.
2. **External API Analysis:** Messe echte Response-Längen von externen APIs
3. **Empfehlungs-Matrix:**
   - Externe URLs → TEXT (unbekannte Länge)
   - Presigned URLs → TEXT (können sehr lang sein)
   - OAuth Tokens → TEXT (variabel)
   - Interne UUIDs → UUID (36 chars)
   - User-Input → VARCHAR mit UI-Limit + Buffer

Dies verhindert Runtime-Fehler wie "string too long for VARCHAR(255)".
