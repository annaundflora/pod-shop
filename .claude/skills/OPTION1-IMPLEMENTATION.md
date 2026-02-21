# Option 1 Implementation: Skills Integration (Minimal)

**Ziel:** Templates mit Skill-Checks erweitern + Automatisches Skill-Loading

**Phasen:**
- Phase 1: Templates mit Skill-Checks
- Phase 2: Agent Frontmatter Updates
- Phase 3: Dokumentation

---

## Phase 1: Templates mit Skill-Checks

### 1.1 PLAN.md Template erweitern

**Datei:** `.claude/templates/plan.md`

**Neue Section:** `<skill_verification>`

```xml
<skill_verification>
<!-- Automatisch eingefügt für UI-Tasks -->

**React Best Practices Check:**
- [ ] async-parallel: Unabhängige Operationen mit Promise.all()
- [ ] rerender-memo: Expensive Components memoized
- [ ] bundle-dynamic-imports: Heavy Components lazy loaded
- [ ] async-suspense-boundaries: Suspense für async Content

**Web Design Guidelines Check:**
- [ ] Icon-only buttons haben aria-label
- [ ] Form inputs haben labels oder aria-label
- [ ] Images haben width/height (kein CLS)
- [ ] Keyboard handler für interaktive Elemente
- [ ] Focus-visible states für alle Buttons
- [ ] prefers-reduced-motion beachtet

**Tailwind v4 Patterns Check:**
- [ ] Design tokens aus @theme genutzt
- [ ] Keine hardcoded Werte
- [ ] Semantic color naming
- [ ] Dark mode support (dark: modifier)
- [ ] Container queries für responsive components

</skill_verification>
```

**Implementierung:**
- Füge `<skill_verification>` Section nach `<verification>` ein
- Nur für `domain: ui` oder wenn Frontend-Dateien in `files_modified`

### 1.2 Architecture Template erweitern

**Datei:** `.claude/templates/architecture-feature.md`

**Neue Section:** `## UI Architecture Decisions`

```markdown
## UI Architecture Decisions

**Based on Skills:** `.claude/skills/`

### Rendering Strategy
- [ ] Server Components vs Client Components (React Best Practices)
  - Critical: `server-cache-react` für Daten-Deduplikation
  - Critical: `bundle-dynamic-imports` für Heavy UI

### Accessibility Architecture
- [ ] WCAG Compliance Level (Web Design Guidelines)
  - ARIA Patterns für Komplexe Komponenten
  - Keyboard Navigation Flow
  - Focus Management Strategy

### Design System
- [ ] CSS Architecture (Tailwind v4 Patterns)
  - @theme Tokens für Brand/Colors
  - Container Queries für Component-Responsive
  - Dark Mode Implementation

### Performance Architecture
- [ ] Critical Rules (React Best Practices)
  | Rule | Implementation |
  |------|---------------|
  | async-parallel | Promise.all() für unabhängige Fetches |
  | rerender-memo | memo() für Listen/Grids |
  | rendering-content-visibility | Für Listen >50 Items |
```

### 1.3 Neue Datei: ui-implementation-checklist.md

**Datei:** `.claude/templates/ui-implementation-checklist.md`

```markdown
# UI Implementation Checklist (Auto-Generated from Skills)

**Quelle:** `.claude/skills/`

## Vor der Implementation

### 1. Skills laden (MANDATORY)
- [ ] `.claude/skills/react-best-practices/SKILL.md` gelesen
- [ ] `.claude/skills/web-design/SKILL.md` gelesen
- [ ] `.claude/skills/tailwind-v4/SKILL.md` gelesen

### 2. Architektur-Entscheidungen
- [ ] Server vs Client Components entschieden
- [ ] Data Fetching Strategy (parallel vs sequential)
- [ ] State Management Approach

## Während der Implementation

### React Best Practices
**Critical:**
- [ ] `async-parallel`: Promise.all() für unabhängige Operationen
- [ ] `bundle-dynamic-imports`: next/dynamic für Heavy Components

**High:**
- [ ] `server-cache-react`: React.cache() für Deduplikation

**Medium:**
- [ ] `rerender-memo`: memo() für expensive Components
- [ ] `rendering-content-visibility`: content-visibility für Listen

### Web Design Guidelines
**Accessibility:**
- [ ] Icon-only buttons: `aria-label` gesetzt
- [ ] Form inputs: `<label>` oder `aria-label`
- [ ] Interactive elements: Keyboard handler
- [ ] Images: `width` + `height` Attribute
- [ ] Decorative icons: `aria-hidden="true"`

**Forms:**
- [ ] Inputs: `autocomplete` + meaningful `name`
- [ ] Correct `type` (`email`, `tel`, etc.)
- [ ] Labels clickable (`htmlFor` oder wrapping)
- [ ] Inline errors: Next to fields mit `role="alert"`

**Performance:**
- [ ] Images: Lazy loading below-fold
- [ ] Large lists: Virtualization (>50 items)

**Touch:**
- [ ] `touch-action: manipulation` auf Buttons
- [ ] Tap targets mindestens 44x44px

### Tailwind v4 Patterns
**Configuration:**
- [ ] `@theme` Block für Custom Tokens
- [ ] Semantic naming (primary, secondary)

**Implementation:**
- [ ] Keine hardcoded Werte
- [ ] `dark:` modifier für Dark Mode
- [ ] Container queries (`@container`, `@md:`)

## Nach der Implementation

### Self-Check
- [ ] Alle Critical Rules implementiert
- [ ] Alle Accessibility Checks passed
- [ ] Keine hardcoded Tailwind Werte

### Documentation
- [ ] Angewendete Rules dokumentiert (Kommentare)
- [ ] Besondere Patterns notiert
```

---

## Phase 2: Agent Frontmatter Updates

### 2.1 Architecture Agent

**Datei:** `.claude/agents/architecture.md`

**Änderung:** Frontmatter erweitern

```yaml
---
name: architecture
description: ...
skills:                          # NEU
  - react-best-practices
  - web-design
  - tailwind-v4
skill_auto_load: true           # NEU
---
```

**Workflow Update:**
```markdown
## Sessionstart (einmalig)

**Phase:** 🔍 DIVERGE

**Input-Prüfung:**
1. Prüfen ob `discovery.md` existiert
2. **AUTO:** Skills laden (falls `skill_auto_load: true`)
   - Lade: `.claude/skills/react-best-practices/SKILL.md`
   - Lade: `.claude/skills/web-design/SKILL.md`
   - Lade: `.claude/skills/tailwind-v4/SKILL.md`
3. Discovery lesen und fachliche Anforderungen extrahieren
...
```

### 2.2 Planner Agent

**Datei:** `.claude/agents/planner.md`

**Änderung:** Frontmatter erweitern

```yaml
---
name: planner
description: ...
skills:                          # NEU
  - react-best-practices
  - web-design
  - tailwind-v4
skill_auto_load: true           # NEU
---
```

**Workflow Update - Phase 3:**
```markdown
### Phase 3: Spec-Plan-Erstellung

**Vorgehen:**
1. Lies das Template: `.claude/templates/plan-spec.md`
2. **AUTO:** Wenn UI-Section vorhanden, füge Skill-Checks ein
   - Lade: `.claude/templates/ui-implementation-checklist.md`
   - Füge relevante Checks in `## UI Anforderungen` ein
3. Fülle alle Sections gemäß dem Template aus
```

### 2.3 Implement Agent

**Datei:** `.claude/agents/implement.md`

**Änderung:** Frontmatter erweitern

```yaml
---
name: implement
description: ...
skills:                          # NEU
  - react-best-practices
  - web-design
  - tailwind-v4
skill_auto_load: true           # NEU
---
```

**Workflow Update - Phase 1:**
```markdown
### Phase 1: Implementation

**Vor jedem Frontend-Task:**
1. **AUTO:** Skills laden
2. **AUTO:** UI Implementation Checklist laden
3. Implementiere mit Skill-Patterns
4. Checke gegen Skill-Checkliste ab
```

### 2.4 Coding Standards Guardian

**Datei:** `.claude/agents/coding-standards-guardian.md`

**Änderung:** Frontmatter erweitern

```yaml
---
name: coding-standards-guardian
description: ...
skills:                          # NEU
  - react-best-practices
  - web-design
  - tailwind-v4
skill_auto_load: true           # NEU
---
```

**Workflow Update:**
```markdown
## Workflow

1. **AUTO:** Skills laden (bei `skill_auto_load: true`)
2. **Dateien lesen** – Alle zu prüfenden Dateien einlesen
3. **Kategorisieren** – Backend vs. Frontend unterscheiden
4. **Gegen Skills prüfen** – Automatisch alle Regeln durchgehen
5. **Findings dokumentieren** – Mit Datei:Zeile und Code-Snippets
6. **Report erstellen** – Im oben definierten Format
```

---

## Phase 3: Dokumentation

### 3.1 Skills README aktualisieren

**Datei:** `.claude/skills/README.md`

Neue Section: `## Automatische Skill-Integration`

```markdown
## Automatische Skill-Integration (Option 1)

### Auto-Loading

Agents mit `skill_auto_load: true` laden Skills automatisch:

- Architecture Agent
- Planner Agent
- Implement Agent
- Coding Standards Guardian

### Skill-Aware Templates

Templates enthalten eingebaute Skill-Checks:

| Template | Skill-Checks |
|----------|--------------|
| `plan.md` | `<skill_verification>` Section |
| `architecture-feature.md` | `## UI Architecture Decisions` |
| `ui-implementation-checklist.md` | Vollständige Checkliste |

### Verwendung

**Für Developer:**
1. Agent starten
2. Skills werden automatisch geladen
3. Templates zeigen relevante Checks
4. Implementation folgt Skill-Patterns

**Für neue Features:**
1. `discovery.md` erstellen
2. Architecture/Planner nutzt Skills automatisch
3. Implement Agent folgt Skill-Checklisten
4. Coding Standards prüft gegen Skills
```

### 3.2 AGENT-INTEGRATION.md aktualisieren

Neue Section: `## Automatische Integration (Option 1)`

Referenziert die neue Implementation.

---

## Implementierungs-Reihenfolge

### Schritt 1: Templates erstellen (15min)
1. `ui-implementation-checklist.md` erstellen
2. `plan.md` erweitern
3. `architecture-feature.md` erweitern

### Schritt 2: Agent Frontmatter (10min)
1. `architecture.md` - Frontmatter + Workflow
2. `planner.md` - Frontmatter + Workflow
3. `implement.md` - Frontmatter + Workflow
4. `coding-standards-guardian.md` - Frontmatter + Workflow

### Schritt 3: Dokumentation (5min)
1. `README.md` aktualisieren
2. `AGENT-INTEGRATION.md` aktualisieren
3. Diese Datei als Referenz

---

## Erwartetes Ergebnis

**Vorher:**
- Agents müssen manuell an Skills erinnert werden
- Templates sind skill-agnostisch
- Checklisten werden vergessen

**Nachher:**
- Skills laden automatisch (Frontmatter)
- Templates haben eingebaute Checks
- Jeder UI-Task hat Skill-Validierung
- Konsistenz über alle Phasen

---

## Testing

**Test 1:** Architecture Agent starten
- Sollte automatisch Skills laden
- UI Architecture Section sollte Skill-Referenzen haben

**Test 2:** Planner mit UI-Task
- Sollte automatisch Skill-Checks einfügen
- UI Implementation Checklist sollte im Plan erscheinen

**Test 3:** Implement Agent
- Sollte Frontend-Designer mit Skills aktivieren
- Sollte gegen Checkliste validieren

**Test 4:** Coding Standards
- Sollte automatisch Skills laden
- Sollte Report mit Skill-Referenzen erstellen
