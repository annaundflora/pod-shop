# PLAN-Spec Template

Template für Planner-Agent Spec/Plan Dokumente.

---

```markdown
# Slice {N}: {Kurzer Imperativ-Titel}

> **Slice {N} von {Total}** für `{Feature-Name}`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | {`slice-{N-1}-name.md` oder "—"} |
> | **Nächster:** | {`slice-{N+1}-name.md` oder "—"} |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-{NN}-{kurzer-name}` |
| **Test** | `pnpm test tests/slices/{feature-name}/slice-{NN}-{name}.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` oder `["slice-01-db", "slice-02-api"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `true` wenn Playwright-Test (`.spec.ts`), `false` für Vitest (`.test.ts`)
- **Dependencies**: Slice-IDs die VOR diesem Slice fertig sein müssen
- **Test-Strategy**: Siehe nachfolgende "Test-Strategy" Section fuer stack-spezifische Test-Commands

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected vom Slice-Writer Agent basierend auf Repo-Indikatoren.
> Diese Section wird vom Test-Writer und Test-Validator konsumiert.

| Key | Value |
|-----|-------|
| **Stack** | `{auto-detected}` |
| **Test Command** | `{unit test command}` |
| **Integration Command** | `{integration test command}` |
| **Acceptance Command** | `{acceptance test command}` |
| **Start Command** | `{app start command}` |
| **Health Endpoint** | `{health check URL}` |
| **Mocking Strategy** | `{mock_external / no_mocks / test_containers}` |

**Erklaerung:**
- **Stack**: Automatisch erkannter Tech-Stack (z.B. "python-fastapi", "typescript-nextjs")
- **Test Command**: Command fuer Unit Tests (z.B. `python -m pytest tests/unit/ -v`)
- **Integration Command**: Command fuer Integration Tests
- **Acceptance Command**: Command fuer Acceptance Tests
- **Start Command**: Command um die App zu starten (fuer Smoke Test)
- **Health Endpoint**: URL fuer Health-Check (Smoke Test)
- **Mocking Strategy**: Mocking-Ansatz fuer Tests

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | {Name} | {Pending/Ready/Done} | `slice-01-{name}.md` |
| 2 | {Name} | {Pending/Ready/Done} | `slice-02-{name}.md` |
| ... | | | |

---

## Kontext & Ziel

{Hintergrund, Problem, Zielbild. Relevante Logs/Screens/Designs verlinken.}

**Aktuelle Probleme:** (falls zutreffend)
1. ...
2. ...

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → {Section-Name}

```
{Relevanten Architektur-Ausschnitt hier einfügen - Datenfluss, Diagramm, etc.}
```

### 1. Architektur-Impact

| Layer               | Änderungen |
|---------------------|------------|
| `backend/app/...`   | ...        |
| `app/...` (Next.js) | ...        |

### 2. Datenfluss

```
{Eingabe}
  ↓
{Verarbeitung}
  ↓
{State / In-Memory}
  ↓
{Ausgabe}
```

### 3. State-Änderungen (falls State betroffen)

```python
# Neue/geänderte State-Felder
class ChatState(BaseModel):
    new_field: FieldType
```

### 4. Weitere technische Details

...

### N. API-Contracts (falls API betroffen)

**{METHOD} `/api/{endpoint}`**

**Response-Typ:** `PydanticModellName` (NICHT `dict`!)

```python
# Pydantic-Modell (Backend)
class ResponseModel(BaseModel):
    field_name: FieldType
    optional_field: OptionalFieldType | None = None
```

```json
// Request/Response JSON (für Dokumentation)
{
  "field_name": "...",
  "optional_field": "..."
}
```

**WICHTIG - Implementierungshinweise:**
- API gibt **Pydantic-Modell** zurück → Zugriff via `response.field_name`
- **NICHT** `.get("field_name")` verwenden (Pydantic hat keine `.get()` Methode!)
- Bei Dict-Konvertierung: `response.model_dump()` verwenden


### N+1. Externe Services/APIs

| Service | Zweck | Integration |
|---------|-------|-------------|
| `{ServiceName}` | {Kurzbeschreibung} | `{Modul/Datei}` |

**Konfiguration:**
- Environment-Variablen: `{VAR_NAME}` (via Settings)
- Timeouts: `{Wert}` (via Config)

**Error Handling:**
- Retry-Strategie: {z.B. exponential backoff, max 3 Retries}
- Fallback: {Verhalten bei Service-Ausfall}


### N+2. Abhängigkeiten

- Bestehend: ...
- Neu: ... (mit Version)

### N+3. Wiederverwendete Code-Bausteine

| Funktion | Datei | Rückgabetyp | Wichtige Hinweise |
|----------|-------|-------------|-------------------|
| `execute_query(fetch="one")` | `database/connection.py` | `List[dict]` | Ergebnis ist `[row]`, Zugriff via `result[0]` |

---

## Integrations-Checkliste (Pflicht bei Backend-Änderungen)

### 1. State-Integration
- [ ] State-Felder korrekt in `ChatState` definiert
- [ ] State-Transfer bei LangGraph Workflow-Aufruf vollständig
- [ ] Rückgabetypen explizit (Pydantic Modelle, keine dicts)

### 2. LangGraph-Integration
- [ ] Node-Transitions korrekt definiert (Command goto)
- [ ] Send API für parallele Ausführung (falls erforderlich)
- [ ] State-Updates synchronisiert

### 3. LLM-Integration
- [ ] Prompts in `backend/prompts/` abgelegt
- [ ] Prompt-Templates korrekt formatiert
- [ ] LLM-Calls mit Error-Handling ausgestattet

### 4. Datenbank-Integration (falls DB betroffen)
- [ ] Schema validiert: Tabellen/Spalten exakt wie in SQLAlchemy Models benannt
- [ ] Rückgabeformate der Query-Funktionen dokumentiert (List, Dict, Domain-Objekt)
- [ ] Datentypen explizit (UUID vs. int vs. string, Zeittypen etc.)
- [ ] Migrationstrategie definiert (neue DB vs. Schema-Update)

### 5. Utility-Funktionen
- [ ] Signaturen wiederverwendeter Funktionen geprüft
- [ ] Konsistente Patterns mit Referenzbeispielen verlinkt
- [ ] Keine Duplikation mit bestehendem Code

### 6. Feature-Aktivierung
- [ ] Klar benannt, wo das Feature im Code aktiviert/aufgerufen wird
- [ ] Trigger-Punkte im Flow beschrieben
- [ ] Flags/Parameter ggf. mit Name + Default + Rollout-Plan dokumentiert

### 7. Datenfluss-Vollständigkeit
- [ ] Objekt-Strukturen an jedem Punkt definiert
- [ ] Transformationen dokumentiert (Request → State → Response)
- [ ] Bei DB: Transformationen (DB-Row → Domain → Response) dokumentiert
- [ ] Datenquelle eindeutig (In-Memory State, LLM-Call, DB, etc.)

---

## UI Anforderungen

### Wireframe (aus wireframes.md)

> **Quelle:** `wireframes.md` → {Section-Name}

```
{Wireframe hier direkt einfügen - ASCII/Mermaid/Beschreibung aus wireframes.md kopieren}
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` - Performance-Patterns (Suspense, Memoization)
- `.claude/skills/web-design/SKILL.md` - Accessibility, Forms, Animation, Responsive
- `.claude/skills/tailwind-v4/SKILL.md` - Design Tokens, Container Queries, Dark Mode

### 1. {Komponente/View 1}

**Komponenten & Dateien:**
- `app/...` – ...

**Verhalten:**
- ...

**Zustände:**
- Loading: ...
- Error: ...
- Empty: ...

**Design Patterns (aus Skills):**
- [ ] Accessibility: aria-labels, keyboard navigation, focus states
- [ ] Animation: prefers-reduced-motion, transform/opacity only
- [ ] Responsive: Container queries vs Media queries
- [ ] Performance: virtualization for lists >50 items

### N. Accessibility
- [ ] Alle interaktiven Elemente haben focus-visible states
- [ ] Icon-only buttons haben aria-label
- [ ] Form inputs haben labels
- [ ] Images haben alt text und dimensions

---

## Acceptance Criteria

1) GIVEN ... WHEN ... THEN ...
2) GIVEN ... WHEN ... THEN ...
...

---

## Testfälle

**WICHTIG:** Tests müssen VOR der Implementierung definiert werden! Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

**Konvention:** `tests/slices/{feature-name}/{slice-id}.test.ts` (Vitest) oder `.spec.ts` (Playwright E2E)

**Für diesen Slice:** `tests/slices/{feature-name}/slice-{NN}-{name}.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/{feature-name}/slice-{NN}-{name}.test.ts
import { describe, it, expect } from 'vitest'

describe('{Feature/Slice Name}', () => {
  it('should {erwartetes Verhalten 1}', async () => {
    // Arrange
    // Act
    // Assert
  })

  it('should {erwartetes Verhalten 2}', async () => {
    // ...
  })
})
```
</test_spec>

### E2E Tests (Playwright)

<test_spec>
```typescript
// tests/slices/{feature-name}/slice-{NN}-{name}.spec.ts
import { test, expect } from '@playwright/test'

test.describe('{Feature/Slice Name}', () => {
  test('{User Story / Akzeptanzkriterium}', async ({ page }) => {
    // GIVEN
    // WHEN  
    // THEN
  })
})
```
</test_spec>

### Manuelle Tests (falls automatisiert nicht möglich)

1. ... → ...

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] Telemetrie/Logging definiert (falls sinnvoll)
- [ ] Sicherheits-/Privacy-Aspekte bedacht
- [ ] UX/Copy final (falls UI)
- [ ] Rollout-/Rollback-Plan notiert (Flags/Migration)

---

## Skill Verification (UI-Implementation)

> **Wichtig:** Bei UI-Implementationen müssen alle relevanten Skills validiert werden.

### React Best Practices Verification

**Critical Priority:**
- [ ] `async-parallel`: Promise.all für unabhängige async Operationen
- [ ] `bundle-dynamic-imports`: Heavy Components mit `next/dynamic`

**High Priority:**
- [ ] `server-cache-react`: React.cache() für wiederholte Server-Calls
- [ ] `async-suspense-boundaries`: Jede async Operation in Suspense

**Medium Priority:**
- [ ] `rerender-memo`: Memo für expensive Components (Image-Grids, Listen)
- [ ] `rerender-dependencies`: Dependencies korrekt in useEffect/useMemo
- [ ] `rendering-content-visibility`: content-visibility für große Listen

### Web Design Guidelines Verification

**Accessibility:**
- [ ] Icon-only buttons haben `aria-label`
- [ ] Form inputs haben assoziierte Labels
- [ ] Images haben `width`/`height` (kein CLS)
- [ ] Keyboard handler für interaktive Elemente
- [ ] Focus-visible states für alle interaktiven Elemente

**Animation & Motion:**
- [ ] `prefers-reduced-motion` beachtet
- [ ] Nur transform/opacity für Animationen

**Touch & Mobile:**
- [ ] `touch-action: manipulation` auf Touch-Elementen
- [ ] Touch targets mindestens 44x44px

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [ ] Keine hardcoded Werte (px, hex colors)
- [ ] `@theme` Tokens für Custom-Designs
- [ ] Semantic color naming (primary, secondary, surface)

**Responsive:**
- [ ] Container queries (`@container`, `@md:`) statt Media Queries wo passend
- [ ] Mobile-first Ansatz

**Dark Mode:**
- [ ] `dark:` Modifier für Dark Mode Support
- [ ] Color scheme berücksichtigt

---

## Constraints & Hinweise

**Betrifft:**
- ...

**API Contract:**
- ...

**Abgrenzung:**
- ...

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| Slice X | `functionName()` | Function | Returns `TypeName` |
| Slice Y | `ComponentName` | Component | Props: `{...}` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `exportedFunction()` | Function | Slice Z | `(params) => ReturnType` |
| `ExportedComponent` | Component | page.tsx | Props interface defined |

### Integration Validation Tasks

- [ ] Dependency X verfügbar und getestet
- [ ] Output Y wird von Consumer Z korrekt konsumiert
- [ ] Schnittstellen-Types sind kompatibel

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.
> Abweichung nur mit expliziter Begründung im Commit erlaubt.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `ComponentName` | Section X | YES | Muss exakt so implementiert werden |
| `hookName` | Section Y | YES | Interface muss übereinstimmen |

---

## Links

- Design/Spec: ...
- Vorherige Tickets/PRs: ...
- Referenz-Implementierungen: ...

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Backend
- [ ] `backend/app/...` – Beschreibung

### Frontend
- [ ] `app/...` – Beschreibung
- [ ] `components/...` – Beschreibung

### Tests
- [ ] Unit Tests vorhanden
- [ ] Integration Tests vorhanden
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
```

---

## Verwendung

1. **Vor dem Write:** Lies dieses Template
2. **Während des Write:** Befolge strikt die Template-Struktur
3. **Nach dem Write:** Prüfe das Dokument gegen das Template

## Prinzipien

- **No prose, only lists and tables** (außer bei Kontext/Ziel)
- **Strict order** - brain knows where to find things
- **Everything included = MUST** (no prioritization needed)
