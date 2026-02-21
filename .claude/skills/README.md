# Skills-System für ImgClean

Agent-agnostische Skills für Claude Code, basierend auf [skills.sh](https://skills.sh) Best Practices.

## Struktur

```
.claude/skills/
├── README.md                          # Diese Datei
├── react-best-practices/
│   └── SKILL.md                       # 57 React/Next.js Performance-Regeln
├── web-design/
│   └── SKILL.md                       # 100+ UI/UX Guidelines
└── tailwind-v4/
    └── SKILL.md                       # Tailwind CSS v4 Patterns
```

## Verfügbare Skills

### 1. React Best Practices

**Quelle:** [vercel-labs/agent-skills](https://github.com/vercel-labs/agent-skills)

**Inhalt:**
- 57 Performance-Regeln in 8 Kategorien
- Priorisiert nach Impact (Critical → Low)
- Umfasst: Waterfalls, Bundle Size, Rendering, Memoization

**Wichtigste Regeln für ImgClean:**
| Regel | Kategorie | Anwendung |
|-------|-----------|-----------|
| `async-parallel` | Critical | Bild-Uploads parallelisieren |
| `bundle-dynamic-imports` | Critical | Heavy UI Components lazy laden |
| `server-cache-react` | High | React.cache() für Metadaten |
| `rerender-memo` | Medium | Image-Grid Memoization |
| `rendering-content-visibility` | Medium | Große Bild-Listen |

### 2. Web Design Guidelines

**Quelle:** [vercel-labs/agent-skills](https://github.com/vercel-labs/agent-skills)

**Inhalt:**
- 100+ Regeln für Accessibility, Forms, Animation
- Performance, Touch Interaction, Responsive Design
- Typography, Content Handling, Dark Mode

**Wichtigste Regeln für ImgClean:**
| Kategorie | Regel | Komponente |
|-----------|-------|------------|
| Accessibility | Icon buttons `aria-label` | Upload-Button, Delete-Button |
| Accessibility | Images `width`/`height` | Thumbnail-Grid |
| Forms | Inputs `autocomplete` | Job-Name Input |
| Touch | `touch-action: manipulation` | Drag-and-Drop Upload |
| Performance | Virtualization | Große Bild-Listen |

### 3. Tailwind v4 Patterns

**Inhalt:**
- CSS-first configuration mit `@theme`
- Design tokens, Semantic colors
- Container queries, Dark mode patterns
- Component templates

**Wichtigste Patterns für ImgClean:**
- `@theme` für Custom Design Tokens
- Container queries für responsive Components
- Semantic color naming (primary, secondary)
- Dark mode mit `dark:` modifier

## Nutzung

### Von Agents

Agents laden Skills dynamisch:

```markdown
## Vor der Aufgabe:
1. Lese `.claude/skills/react-best-practices/SKILL.md`
2. Lese `.claude/skills/web-design/SKILL.md`
3. Lese `.claude/skills/tailwind-v4/SKILL.md`

## Während der Aufgabe:
- Wende relevante Regeln an
- Markiere angewendete Regeln mit Kommentaren

## Nach der Aufgabe:
- Validiere gegen Skills
- Erstelle Compliance-Report
```

### Von Commands

Commands nutzen Skills für Reviews:

```bash
/design-review components/file-upload.tsx
/react-performance app/jobs/page.tsx
```

### Manuelle Nutzung

```typescript
// Beispiel: React Best Practices anwenden
const [user, posts] = await Promise.all([
  fetchUser(),      // Parallel statt sequentiell
  fetchPosts()
]);

// Beispiel: Web Design Guidelines anwenden
<button aria-label="Delete image">  // Accessibility
  <TrashIcon aria-hidden="true" />
</button>

// Beispiel: Tailwind v4 Patterns anwenden
<div className="@container">        // Container queries
  <div className="grid-cols-1 @md:grid-cols-2">
```

## Integration

### Frontend-Designer Agent

Der `.claude/agents/frontend-designer.md` Agent nutzt alle drei Skills für UI-Implementation:

1. **Skill-Laden** (Phase 1)
2. **Design-Entscheidungen** (Phase 3)
3. **Validation** (Phase 5)

### Coding Standards Guardian

Der `.claude/agents/coding-standards-guardian.md` Agent prüft Code gegen Skills:

- React Best Practices: Performance-Regeln
- Web Design Guidelines: Accessibility, Forms
- Tailwind v4 Patterns: CSS-first config, Tokens

### Implement Agent

Der `.claude/agents/implement.md` Agent orchestriert den Frontend-Designer für UI-Tasks.

## Commands

### /design-review

Prüft UI-Code gegen alle drei Skills:

```markdown
/design-review components/file-upload.tsx
```

**Prüft:**
- Accessibility (ARIA, Keyboard, Focus)
- Forms (Labels, Validation)
- Images (Dimensions, Lazy loading)
- Tailwind (Tokens, Dark mode)

### /react-performance

Analysiert React-Code für Performance-Issues:

```markdown
/react-performance "app/**/*.tsx"
```

**Prüft:**
- Waterfalls (Promise.all)
- Bundle Size (Dynamic imports)
- Re-renders (Memoization)
- Rendering (content-visibility)

## Best Practices

### Skill-basierte Entwicklung

1. **Vor dem Coden:** Relevante Skills lesen
2. **Während dem Coden:** Regeln anwenden und markieren
3. **Nach dem Coden:** Gegen Skills validieren

### Kommentar-Konvention

Markiere angewendete Regeln:

```tsx
// Rule: async-parallel (React Best Practices)
const [user, posts] = await Promise.all([fetchUser(), fetchPosts()]);

// Rule: aria-label for icon buttons (Web Design Guidelines)
<button aria-label="Delete">

// Rule: @theme tokens (Tailwind v4)
<div className="bg-surface-elevated">
```

### Review-Checklisten

**React Best Practices:**
- [ ] Keine Waterfalls
- [ ] Memo für expensive components
- [ ] Dynamic imports für heavy components
- [ ] Suspense boundaries

**Web Design Guidelines:**
- [ ] Icon buttons haben aria-label
- [ ] Form inputs haben labels
- [ ] Images haben dimensions
- [ ] Keyboard navigation
- [ ] Reduced motion

**Tailwind v4 Patterns:**
- [ ] Design tokens genutzt
- [ ] Keine hardcoded Werte
- [ ] Dark mode support
- [ ] Container queries

## Aktualisierung

Skills können aus skills.sh aktualisiert werden:

```bash
# Aktuelle Versionen von skills.sh
npx skills add vercel-labs/agent-skills
```

Oder manuell aus den GitHub-Repos:
- https://github.com/vercel-labs/agent-skills
- https://github.com/anthropics/skills

## Option 1: Automatische Integration

### Überblick

**Implementiert:** Templates mit Skill-Checks + Automatisches Skill-Loading

| Agent | Auto-Load | Skill-Checks |
|-------|-----------|--------------|
| Architecture | ✅ `skill_auto_load: true` | In Templates |
| Planner | ✅ `skill_auto_load: true` | In Templates |
| Implement | ✅ `skill_auto_load: true` | Via Frontend-Designer |
| Coding Standards | ✅ `skill_auto_load: true` | In Review-Prozess |

### Templates mit Skills

1. **`plan.md`** - `<skill_verification>` Section
2. **`ui-implementation-checklist.md`** - Vollständige Checkliste

### Nutzung

```bash
# Planner starten - Skills laden automatisch
/planner specs/phase-1/2025-01-21-feature/

# Implement starten - Skills über Frontend-Designer
/implement specs/phase-1/2025-01-21-feature/PLAN.md

# Coding Standards - Skills automatisch im Review
/coding-standards-guardian components/file-upload.tsx
```

### Vorteile

- **Kein manuelles Nachdenken:** Skills laden automatisch
- **Konsistenz:** Alle Agents nutzen dieselben Skills
- **Qualität:** Eingebaute Checklisten
- **Dokumentation:** Jede UI-Implementation hat Skill-Verweise

---

## Referenzen

- [skills.sh](https://skills.sh) - Agent Skills Directory
- [agentskills.io](https://agentskills.io) - Agent Skills Specification
- [Vercel Agent Skills](https://github.com/vercel-labs/agent-skills)
- [Anthropic Skills](https://github.com/anthropics/skills)
- **Implementation Details:** `.claude/skills/OPTION1-IMPLEMENTATION.md`
