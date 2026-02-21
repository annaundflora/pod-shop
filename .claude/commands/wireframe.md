---
description: "Erstellt Wireframes + automatische Gate 0 Compliance (max 3 Retries). Basiert auf Anthropic Fresh Context Pattern."
---

# Wireframe mit Gate 0 Compliance

Du führst den **Wireframe-Agent** mit automatischer **Gate 0 Compliance** aus.

**Input:** $ARGUMENTS

---

## Phase 1: Input-Validierung

1. Prüfe ob `$ARGUMENTS` einen Spec-Pfad enthält
2. Falls kein Argument: Suche neuestes `specs/*/discovery.md` und frage via AskUserQuestion
3. Validiere dass `discovery.md` existiert im Spec-Ordner
4. Falls nicht: STOP mit Hinweis "Zuerst /discovery ausführen"

**Spec-Pfad ermitteln:** Extrahiere den Ordnerpfad aus $ARGUMENTS oder dem gefundenen Discovery

---

## Phase 2: Wireframe-Erstellung

1. Lies die Agent-Definition: `.claude/agents/wireframe.md`
2. Übernimm die dort beschriebenen Workflows und Verhaltensweisen
3. Erstelle `wireframes.md` nach dem Template in `.claude/templates/wireframe-template.md`
4. Speichere im Spec-Ordner (gleicher Ordner wie discovery.md)

---

## Phase 3: Gate 0 Compliance Loop

Nach dem Erstellen/Fixen von wireframes.md:

```
retry_count = 0
MAX_RETRIES = 3
spec_path = [ermittelter Spec-Ordner]

LOOP:
  # Compliance Check mit FRISCHEM CONTEXT (Anthropic Pattern)
  # "Subagents get their own fresh context, completely separate from main conversation"

  compliance_result = Task(
    subagent_type: "discovery-wireframe-compliance",
    prompt: "Prüfe Compliance für: {spec_path}

    Lies:
    - {spec_path}/discovery.md
    - {spec_path}/wireframes.md

    Erstelle: {spec_path}/compliance-discovery-wireframe.md

    WICHTIG: Du bist READ-ONLY! Editiere KEINE Dateien selbst.

    Returne am Ende EXAKT dieses Format:
    VERDICT: APPROVED oder FAILED
    BLOCKING_ISSUES: [Liste falls FAILED]
    DISCOVERY_UPDATES: [JSON-Array mit Updates, siehe Agent-Definition]"
  )

  # Parse das Ergebnis

  # 1. Discovery Updates anwenden (DU führst die Edits durch, nicht der Sub-Agent!)
  IF compliance_result enthält "DISCOVERY_UPDATES:" UND Array nicht leer:
    discovery_updates = parse JSON aus DISCOVERY_UPDATES Block

    FOR each update IN discovery_updates:
      # Lies die aktuelle Discovery
      discovery_content = Read({spec_path}/discovery.md)

      # Finde die Ziel-Section und füge ein/ersetze
      IF update.add_line:
        # Finde "after" Zeile und füge danach ein
        Edit({spec_path}/discovery.md,
          old_string: update.after,
          new_string: update.after + "\n" + update.add_line
        )
      ELSE IF update.replace_line:
        Edit({spec_path}/discovery.md,
          old_string: update.replace_line,
          new_string: update.with
        )

    OUTPUT an User:
    "🔧 Discovery aktualisiert mit {len(discovery_updates)} Wireframe-Details"

  # 2. Verdict prüfen
  IF compliance_result enthält "VERDICT: APPROVED":
    OUTPUT an User:
    "✅ **Gate 0 APPROVED**

    Wireframes sind konsistent mit Discovery.
    - wireframes.md ✓
    - compliance-discovery-wireframe.md ✓
    - discovery.md aktualisiert (falls Updates nötig waren)

    **Nächster Schritt:** /architecture {spec_path}"
    STOP (Erfolg)

  IF compliance_result enthält "VERDICT: FAILED":
    retry_count++

    IF retry_count >= MAX_RETRIES:
      OUTPUT an User:
      "❌ **HARD EXIT: Gate 0 nach 3 Versuchen fehlgeschlagen**

      Blocking Issues:
      {compliance_result.blocking_issues}

      **Manuelle Korrektur erforderlich.**
      Bitte wireframes.md oder discovery.md manuell fixen und /wireframe erneut starten."
      STOP (Fehler)

    # Fix-Versuch
    OUTPUT an User:
    "⚠️ **Gate 0 FAILED** (Versuch {retry_count}/3)

    Blocking Issues: {compliance_result.blocking_issues}
    → Versuche automatischen Fix..."

    # Wireframe fixen
    Lies erneut `.claude/agents/wireframe.md`
    Lies `{spec_path}/compliance-discovery-wireframe.md` für die Issues
    Korrigiere `{spec_path}/wireframes.md` basierend auf:
    - Blocking Issues aus dem Compliance Report
    - "Required Wireframe Updates" Section
    - "Required Discovery Updates" Section (nur informativ)

    GOTO LOOP
```

---

## Output

Nach erfolgreichem Durchlauf:

```
{spec_path}/
├── discovery.md                        # Input (existiert)
├── wireframes.md                       # NEU erstellt
└── compliance-discovery-wireframe.md   # NEU erstellt (Gate 0)
```

---

## Wichtig: Fresh Context Pattern

Jeder Gate-Check läuft als **separater Sub-Agent** mit eigenem Context:
- Der Compliance-Agent sieht NICHT den Wireframe-Erstellungs-Context
- Das verhindert Confirmation Bias
- Quelle: Anthropic "Subagents get their own fresh context"
