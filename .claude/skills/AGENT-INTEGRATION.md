# Skills Integration für Agents

Diese Dokumentation zeigt, wie die Skills von verschiedenen Agents genutzt werden können.

> **🎯 Option 1 Implementiert:** Automatisches Skill-Loading + Skill-Aware Templates
> Siehe `.claude/skills/OPTION1-IMPLEMENTATION.md` für Details

## Übersicht

| Skill | Architecture | Wireframe | Planner | Implement | Coding Standards |
|-------|-------------|-----------|---------|-----------|------------------|
| **React Best Practices** | ✅ Rendering-Architektur | ❌ | ✅ Performance-Planung | ✅ Implementation | ✅ Review |
| **Web Design Guidelines** | ✅ Accessibility-Architektur | ✅ Layout Patterns | ✅ UI-Specs | ✅ Implementation | ✅ Review |
| **Tailwind v4 Patterns** | ✅ Design System Architektur | ✅ Responsive | ✅ Design Tokens | ✅ Implementation | ✅ Review |

---

## Architecture Agent

### Wann nutzen?
- Bei technischer Konzeption (API, DB, Security)
- Für UI/Frontend Architektur-Entscheidungen

### Wie Skills nutzen?

**Im DIVERGE Phase (Webrecherche):**
```markdown
4. **Webrecherche durchführen** (Best Practices, Architecture Patterns, Tech-Vergleiche)

   **UI/Frontend Architektur:** Skills als Referenz nutzen
   - `.claude/skills/web-design/SKILL.md` - Für Accessibility-Architektur (WCAG, ARIA Patterns)
   - `.claude/skills/tailwind-v4/SKILL.md` - Für Design System Architektur (CSS-first, Tokens, Container Queries)
   - `.claude/skills/react-best-practices/SKILL.md` - Für Rendering-Architektur (Server/Client Components, Caching)
```

### Konkrete Anwendungen

| Architektur-Entscheidung | Skill | Relevante Regeln |
|-------------------------|-------|------------------|
| Server vs Client Components | React Best Practices | `server-cache-react`, `bundle-dynamic-imports` |
| API Data Fetching | React Best Practices | `async-parallel`, `async-dependencies` |
| Accessibility-Strategie | Web Design Guidelines | WCAG Patterns, ARIA, Keyboard Navigation |
| Design System Setup | Tailwind v4 Patterns | `@theme` Tokens, CSS-first config |
| Responsive Strategy | Tailwind v4 Patterns | Container Queries vs Media Queries |

---

## Wireframe Agent

### Wann nutzen?
- Bei ASCII-Wireframe Erstellung
- Für visuelle Validierung

### Wie Skills nutzen?

**Im Workflow (Schritt 2):**
```markdown
## Workflow

```
1. Template lesen: .claude/pm/templates/wireframe-template.md
2. Skills laden (für Design Patterns & Accessibility):
   - `.claude/skills/web-design/SKILL.md` - Layout patterns, Touch targets, Responsive
   - `.claude/skills/tailwind-v4/SKILL.md` - Container queries, Spacing scale
3. Discovery-Doc lesen
...
```

### Konkrete Anwendungen

| Wireframe-Aspekt | Skill | Relevante Regeln |
|-----------------|-------|------------------|
| Layout Patterns | Web Design Guidelines | Flex/Grid über JS, Safe Areas |
| Touch Targets | Web Design Guidelines | `touch-action: manipulation`, Tap targets |
| Responsive | Tailwind v4 Patterns | Container queries, Breakpoints |
| Spacing | Tailwind v4 Patterns | 4px Spacing Scale |
| Accessibility | Web Design Guidelines | Focus states, Semantic HTML |

---

## Planner Agent

### Wann nutzen?
- Bei Issue/Plan Erstellung
- Für UI-Spezifikationen

### Wie Skills nutzen?

**In der UI Anforderungen Section:**
```markdown
## UI Anforderungen

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` - Performance-Patterns (Suspense, Memoization)
- `.claude/skills/web-design/SKILL.md` - Accessibility, Forms, Animation, Responsive
- `.claude/skills/tailwind-v4/SKILL.md` - Design Tokens, Container Queries, Dark Mode

### 1. {Komponente/View 1}
...

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
```

### Konkrete Anwendungen

| Plan-Aspekt | Skill | Relevante Regeln |
|-------------|-------|------------------|
| UI Performance | React Best Practices | `rerender-memo`, `bundle-dynamic-imports` |
| Accessibility Specs | Web Design Guidelines | ARIA labels, Keyboard handlers, Focus states |
| Form Validation | Web Design Guidelines | Inline errors, Autocomplete, Labels |
| Animation Specs | Web Design Guidelines | `prefers-reduced-motion`, Performance |
| Design Tokens | Tailwind v4 Patterns | `@theme`, Semantic colors |
| Responsive Specs | Tailwind v4 Patterns | Container queries, Dark mode |

---

## Implement Agent

### Wann nutzen?
- Bei Feature-Implementation
- Automatisch via Sub-Agent

### Wie Skills nutzen?

**Sub-Agent Orchestrierung:**
```markdown
## Sub-Agents

| Agent | Datei | Wann aufrufen | Zweck |
|-------|-------|--------------|-------|
| **frontend-designer** | `.claude/agents/frontend-designer.md` | Bei UI-Implementation | Design-Patterns, Accessibility, Skills anwenden |

### Skills-Integration

Der **frontend-designer** Agent nutzt folgende Skills:
- **React Best Practices** (`.claude/skills/react-best-practices/SKILL.md`) - 57 Performance-Regeln
- **Web Design Guidelines** (`.claude/skills/web-design/SKILL.md`) - 100+ Accessibility-Regeln
- **Tailwind v4 Patterns** (`.claude/skills/tailwind-v4/SKILL.md`) - Design System Patterns
```

### Konkrete Anwendungen

| Implementation | Skill | Relevante Regeln |
|---------------|-------|------------------|
| Async Data Fetching | React Best Practices | `async-parallel`, `async-suspense-boundaries` |
| Component Optimization | React Best Practices | `rerender-memo`, `rerender-dependencies` |
| Heavy Components | React Best Practices | `bundle-dynamic-imports` |
| Accessibility | Web Design Guidelines | `aria-label`, Keyboard handlers, Focus states |
| Forms | Web Design Guidelines | Labels, Validation, Error handling |
| Images | Web Design Guidelines | Dimensions, Lazy loading |
| Design Tokens | Tailwind v4 Patterns | `@theme`, Semantic naming |
| Dark Mode | Tailwind v4 Patterns | `dark:` modifier, Color scheme |

---

## Coding Standards Guardian

### Wann nutzen?
- Bei Code Review
- Für Qualitäts-Checks

### Wie Skills nutzen?

**Skill-Integration Section:**
```markdown
## Skill-Integration

Vor der Analyse, lade relevante Skills als Referenz:

### Für Frontend-Code
1. **React Best Practices** (`.claude/skills/react-best-practices/SKILL.md`)
   - Prüfe gegen: Async patterns, Re-render optimization, Bundle size
   - Kritisch: `async-parallel`, `rerender-memo`, `bundle-dynamic-imports`

2. **Web Design Guidelines** (`.claude/skills/web-design/SKILL.md`)
   - Prüfe gegen: Accessibility, Forms, Images, Focus states
   - Kritisch: ARIA labels, Keyboard handlers, Image dimensions

3. **Tailwind v4 Patterns** (`.claude/skills/tailwind-v4/SKILL.md`)
   - Prüfe gegen: CSS-first config, Design tokens, Dark mode
   - Kritisch: `@theme` usage, Semantic colors, Container queries
```

### Review Checklisten

**React Best Practices Check:**
- [ ] Keine Waterfalls (Promise.all genutzt)
- [ ] Memo für expensive components
- [ ] Dynamic imports für heavy components
- [ ] Suspense boundaries

**Web Design Guidelines Check:**
- [ ] Icon buttons haben aria-label
- [ ] Form inputs haben labels
- [ ] Images haben dimensions
- [ ] Keyboard navigation
- [ ] Reduced motion

**Tailwind v4 Patterns Check:**
- [ ] Design tokens genutzt
- [ ] Keine hardcoded Werte
- [ ] Dark mode support
- [ ] Container queries

---

## Commands (für alle Agents nutzbar)

### /design-review
Prüft UI-Code gegen alle Skills:
```bash
/design-review components/file-upload.tsx
```

### /react-performance
Analysiert React-Code:
```bash
/react-performance app/jobs/page.tsx
```

---

## Zusammenfassung: Skill-Nutzung nach Phase

| Phase | Agent | Skills | Nutzung |
|-------|-------|--------|---------|
| **Konzeption** | Architecture | Alle 3 | Architektur-Entscheidungen informieren |
| **Design** | Wireframe | Web Design, Tailwind | Layout-Patterns, Accessibility |
| **Planung** | Planner | Alle 3 | UI-Specs mit konkreten Patterns |
| **Implementation** | Implement | Alle 3 (via Frontend-Designer) | Patterns anwenden |
| **Review** | Coding Standards | Alle 3 | Gegen Regeln prüfen |

---

## Empfohlener Workflow mit Skills

1. **Architecture Phase:** Skills lesen für fundierte Architektur-Entscheidungen
2. **Wireframe Phase:** Skills nutzen für konsistente Layout-Patterns
3. **Planner Phase:** Skills referenzieren in UI-Anforderungen
4. **Implement Phase:** Frontend-Designer Agent mit Skills aktivieren
5. **Review Phase:** Coding Standards Guardian prüft gegen Skills

Alle Agents greifen auf dieselben Skill-Dokumente zu - das sorgt für Konsistenz über den gesamten Entwicklungsprozess.
