---
name: design-review
description: Review UI code against Web Design Guidelines, React Best Practices, and Tailwind v4 Patterns. Checks accessibility, performance, and design system compliance.
---

# /design-review Command

Review UI components and pages against comprehensive design guidelines.

## Usage

```
/design-review <file-pattern>
/design-review components/file-upload.tsx
/design-review "components/**/*.tsx"
/design-review app/jobs/page.tsx
```

## Ablauf

### Phase 1: Skills Laden

Lade alle relevanten Skill-Dokumente:

1. **React Best Practices** (`.claude/skills/react-best-practices/SKILL.md`)
2. **Web Design Guidelines** (`.claude/skills/web-design/SKILL.md`)
3. **Tailwind v4 Patterns** (`.claude/skills/tailwind-v4/SKILL.md`)

### Phase 2: Dateien Analysieren

Nutze Glob um Dateien zu finden und lese sie:

```
Glob: <file-pattern>
Read: Jede gefundene Datei
```

### Phase 3: Review Durchführen

Prüfe jede Datei gegen alle drei Skills:

#### React Best Practices Check

**Critical Rules:**
- [ ] `async-parallel` - Promise.all() für unabhängige Operationen
- [ ] `bundle-dynamic-imports` - next/dynamic für heavy components
- [ ] `server-cache-react` - React.cache() für deduplication
- [ ] `async-suspense-boundaries` - Suspense für streaming

**High Priority:**
- [ ] `server-serialization` - Minimize data to client components
- [ ] `server-cache-lru` - LRU cache für cross-request caching

**Medium Priority:**
- [ ] `rerender-memo` - Memo für expensive components
- [ ] `rerender-derived-state-no-effect` - Kein derive in effects
- [ ] `rendering-content-visibility` - content-visibility für Listen

#### Web Design Guidelines Check

**Accessibility (A11y):**
- [ ] Icon-only buttons haben `aria-label`
- [ ] Form controls haben `<label>` oder `aria-label`
- [ ] Interactive elements haben keyboard handlers
- [ ] Images haben `alt` (oder `alt=""` wenn decorative)
- [ ] Decorative icons haben `aria-hidden="true"`
- [ ] Headings sind hierarchical `<h1>`–`<h6>`

**Focus States:**
- [ ] Visible focus für alle interactive elements
- [ ] Nie `outline-none` ohne replacement
- [ ] `:focus-visible` statt `:focus`

**Forms:**
- [ ] Inputs haben `autocomplete` und meaningful `name`
- [ ] Correct `type` und `inputmode`
- [ ] Nie paste blocking
- [ ] Labels sind clickable
- [ ] Inline errors next to fields
- [ ] Errors haben `role="alert"`

**Images:**
- [ ] Explicit `width` und `height`
- [ ] Below-fold: `loading="lazy"`
- [ ] Above-fold: `priority` oder `fetchpriority="high"`

**Performance:**
- [ ] Large lists (>50 items) virtualized
- [ ] Keine layout reads in render
- [ ] `preconnect` für CDN domains

**Touch & Interaction:**
- [ ] `touch-action: manipulation`
- [ ] `-webkit-tap-highlight-color` gesetzt
- [ ] `overscroll-behavior: contain` in modals

#### Tailwind v4 Patterns Check

**Configuration:**
- [ ] CSS-first config mit `@theme`
- [ ] Keine `tailwind.config.js`
- [ ] Design tokens in `@theme` block

**Colors:**
- [ ] Semantic naming (primary, secondary)
- [ ] OKLCH für Farben
- [ ] Dark mode support (`dark:` modifier)

**Layout:**
- [ ] Container queries für component-level responsive
- [ ] Flex/Grid statt JS measurement

**Best Practices:**
- [ ] Keine hardcoded Werte
- [ ] `@apply` nur für reusable components
- [ ] CSS variables für dynamic values

### Phase 4: Report Erstellen

Format:

```markdown
# Design Review Report

**Dateien geprüft:** {N}
**Datum:** {YYYY-MM-DD}
**Status:** {🔴 Kritische Issues | 🟡 Verbesserungsbedarf | 🟢 Standards eingehalten}

---

## Zusammenfassung

| Kategorie | Status | Issues |
|-----------|--------|--------|
| React Best Practices | 🟢/🟡/🔴 | {Anzahl} |
| Accessibility | 🟢/🟡/🔴 | {Anzahl} |
| Forms | 🟢/🟡/🔴 | {Anzahl} |
| Images | 🟢/🟡/🔴 | {Anzahl} |
| Performance | 🟢/🟡/🔴 | {Anzahl} |
| Tailwind v4 | 🟢/🟡/🔴 | {Anzahl} |

---

## Kritische Issues (❌)

### K1: {Titel}

**Datei:** `{pfad/datei.tsx}` Zeile {N}
**Skill:** {React Best Practices / Web Design / Tailwind}
**Regel:** {Spezifische Regel}

**Problem:**
```tsx
{code snippet}
```

**Empfehlung:**
```tsx
{fixed code}
```

**Referenz:**
- Skill: `.claude/skills/{skill}/SKILL.md`
- Section: {Section Name}

---

## Warnings (⚠️)

### W1: {Titel}

**Datei:** `{pfad/datei.tsx}`
**Regel:** {Regel}
**Problem:** {Beschreibung}
**Empfehlung:** {Kurze Empfehlung}

---

## Best Practices Applied (✅)

| Datei | Pattern | Beschreibung |
|-------|---------|--------------|
| {file} | {pattern} | {Beschreibung} |

---

## Empfohlene Actions

1. **Kritisch:** {Konkrete Action}
2. **Warning:** {Konkrete Action}
3. **Verbesserung:** {Konkrete Action}

---

## Referenzen

- React Best Practices: `.claude/skills/react-best-practices/SKILL.md`
- Web Design Guidelines: `.claude/skills/web-design/SKILL.md`
- Tailwind v4 Patterns: `.claude/skills/tailwind-v4/SKILL.md`
```

## Regel-Details

### React Best Practices (Auszug)

**async-parallel:**
```tsx
// ❌ Bad
const user = await fetchUser();
const posts = await fetchPosts();

// ✅ Good
const [user, posts] = await Promise.all([
  fetchUser(),
  fetchPosts()
]);
```

**bundle-dynamic-imports:**
```tsx
import dynamic from 'next/dynamic';

const HeavyComponent = dynamic(() => import('./HeavyComponent'), {
  loading: () => <Skeleton />
});
```

**rerender-memo:**
```tsx
const List = memo(function List({ items }) {
  return (
    <ul>
      {items.map(item => <Item key={item.id} item={item} />)}
    </ul>
  );
});
```

### Web Design Guidelines (Auszug)

**Icon-only buttons:**
```tsx
// ❌ Bad
<button onClick={handleDelete}>
  <TrashIcon />
</button>

// ✅ Good
<button onClick={handleDelete} aria-label="Delete item">
  <TrashIcon aria-hidden="true" />
</button>
```

**Form labels:**
```tsx
// ❌ Bad
<input type="email" placeholder="Email" />

// ✅ Good
<label htmlFor="email">Email</label>
<input id="email" type="email" />
```

**Image dimensions:**
```tsx
// ❌ Bad
<img src="/photo.jpg" alt="Photo" />

// ✅ Good
<img src="/photo.jpg" alt="Photo" width={800} height={600} />
```

### Tailwind v4 Patterns (Auszug)

**@theme usage:**
```css
@theme {
  --color-primary: #3b82f6;
  --color-primary-dark: #2563eb;
}
```

**Dark mode:**
```tsx
<div className="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
```

**Container queries:**
```tsx
<div className="@container">
  <div className="grid grid-cols-1 @md:grid-cols-2 gap-4">
```

## Beispiel-Output

```markdown
# Design Review Report

**Dateien geprüft:** 3
**Datum:** 2025-01-21
**Status:** 🟡 Verbesserungsbedarf

---

## Zusammenfassung

| Kategorie | Status | Issues |
|-----------|--------|--------|
| React Best Practices | 🟡 | 2 |
| Accessibility | 🔴 | 1 |
| Forms | 🟢 | 0 |
| Images | 🟡 | 1 |
| Performance | 🟢 | 0 |
| Tailwind v4 | 🟢 | 0 |

---

## Kritische Issues (❌)

### K1: Icon Button ohne aria-label

**Datei:** `components/file-upload.tsx` Zeile 45
**Skill:** Web Design Guidelines
**Regel:** Icon-only buttons need `aria-label`

**Problem:**
```tsx
<button onClick={removeFile}>
  <XIcon className="w-4 h-4" />
</button>
```

**Empfehlung:**
```tsx
<button onClick={removeFile} aria-label="Remove file">
  <XIcon className="w-4 h-4" aria-hidden="true" />
</button>
```

---

## Warnings (⚠️)

### W1: Images ohne explicit dimensions

**Datei:** `components/image-grid.tsx` Zeile 23
**Regel:** Images need explicit `width` and `height`

**Empfehlung:** Füge width/height hinzu um CLS zu vermeiden:
```tsx
<Image src={src} alt={alt} width={400} height={300} />
```

---

## Empfohlene Actions

1. **Kritisch:** Alle Icon-Buttons mit aria-label versehen
2. **Warning:** Image dimensions hinzufügen
```
