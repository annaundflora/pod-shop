---
name: react-performance
description: Analyze React/Next.js code for performance issues using React Best Practices skill. Checks for waterfalls, unnecessary re-renders, bundle size issues, and optimization opportunities.
---

# /react-performance Command

Analyze React components and hooks for performance optimization opportunities.

## Usage

```
/react-performance <file-pattern>
/react-performance components/image-grid.tsx
/react-performance "app/**/*.tsx"
/react-performance hooks/useJobProcessing.ts
```

## Ablauf

### Phase 1: Skill Laden

Lade React Best Practices Skill:

```
Read: .claude/skills/react-best-practices/SKILL.md
```

### Phase 2: Dateien Analysieren

Nutze Glob um Dateien zu finden und lese sie.

### Phase 3: Performance Review

Prüfe gegen alle 8 Kategorien der React Best Practices:

#### 1. Eliminating Waterfalls (CRITICAL)

**Regeln:**
- `async-defer-await` - Move await into branches
- `async-parallel` - Use Promise.all() for independent operations
- `async-dependencies` - Start fetches early
- `async-api-routes` - Start promises early, await late
- `async-suspense-boundaries` - Use Suspense for streaming

**Checks:**
- [ ] Werden unabhängige async Operationen parallelisiert?
- [ ] Werden Promises früh gestartet und spät awaited?
- [ ] Gibt es Suspense boundaries für async content?

**Beispiel Fix:**
```tsx
// ❌ Before
async function getData() {
  const user = await fetchUser();
  const posts = await fetchPosts();  // Sequential!
  return { user, posts };
}

// ✅ After
async function getData() {
  const [user, posts] = await Promise.all([
    fetchUser(),
    fetchPosts()  // Parallel!
  ]);
  return { user, posts };
}
```

#### 2. Bundle Size Optimization (CRITICAL)

**Regeln:**
- `bundle-barrel-imports` - Import directly, avoid barrel files
- `bundle-dynamic-imports` - Use next/dynamic for heavy components
- `bundle-defer-third-party` - Load analytics after hydration
- `bundle-conditional` - Load modules only when needed
- `bundle-preload` - Preload on hover/focus

**Checks:**
- [ ] Werden heavy components dynamisch importiert?
- [ ] Werden Third-Party scripts deferred geladen?
- [ ] Gibt es unnecessary barrel imports?

**Beispiel Fix:**
```tsx
// ❌ Before
import HeavyChart from './HeavyChart';  // Always loaded

// ✅ After
import dynamic from 'next/dynamic';

const HeavyChart = dynamic(() => import('./HeavyChart'), {
  loading: () => <Skeleton />
});
```

#### 3. Server-Side Performance (HIGH)

**Regeln:**
- `server-auth-actions` - Authenticate server actions
- `server-cache-react` - Use React.cache() for deduplication
- `server-cache-lru` - Use LRU cache for cross-request caching
- `server-serialization` - Minimize data to client

**Checks:**
- [ ] Werden fetch-Requests gecached?
- [ ] Ist die Daten-Serialisierung minimal?

**Beispiel Fix:**
```tsx
// ❌ Before
async function getUser(id: string) {
  return db.user.findUnique({ where: { id } });  // No caching
}

// ✅ After
import { cache } from 'react';

const getUser = cache(async (id: string) => {
  return db.user.findUnique({ where: { id } });
});
```

#### 4. Client-Side Data Fetching (MEDIUM-HIGH)

**Regeln:**
- `client-swr-dedup` - Use SWR for deduplication
- `client-event-listeners` - Deduplicate global listeners
- `client-passive-event-listeners` - Use passive listeners

**Checks:**
- [ ] Werden global event listeners dedupliziert?
- [ ] Sind scroll listeners passive?

#### 5. Re-render Optimization (MEDIUM)

**Regeln:**
- `rerender-memo` - Memoize expensive components
- `rerender-dependencies` - Use primitive dependencies
- `rerender-derived-state` - Derive during render
- `rerender-transitions` - Use startTransition
- `rerender-move-effect-to-event` - Logic in event handlers

**Checks:**
- [ ] Sind expensive components memoized?
- [ ] Sind effect dependencies primitiv?
- [ ] Wird state während render abgeleitet statt in effects?

**Beispiel Fix:**
```tsx
// ❌ Before
function List({ items }) {
  return (
    <ul>
      {items.map(item => <ExpensiveItem key={item.id} item={item} />)}
    </ul>
  );
}

// ✅ After
const List = memo(function List({ items }) {
  return (
    <ul>
      {items.map(item => <ExpensiveItem key={item.id} item={item} />)}
    </ul>
  );
});
```

#### 6. Rendering Performance (MEDIUM)

**Regeln:**
- `rendering-content-visibility` - content-visibility for lists
- `rendering-hoist-jsx` - Extract static JSX
- `rendering-conditional-render` - Use ternary not &&
- `rendering-animate-svg-wrapper` - Animate wrapper not SVG

**Checks:**
- [ ] Große Listen haben content-visibility?
- [ ] Wird && für conditional rendering vermieden?

**Beispiel Fix:**
```tsx
// ❌ Before
{items.length && <List items={items} />}  // Renders "0"

// ✅ After
{items.length > 0 ? <List items={items} /> : null}
```

#### 7. JavaScript Performance (LOW-MEDIUM)

**Regeln:**
- `js-batch-dom-css` - Batch CSS changes
- `js-index-maps` - Use Map for lookups
- `js-combine-iterations` - Combine filter/map
- `js-set-map-lookups` - O(1) lookups with Set/Map

**Checks:**
- [ ] Werden DOM-Operationen gebatched?
- [ ] Werden Arrays in Maps/Set für Lookups konvertiert?

**Beispiel Fix:**
```tsx
// ❌ Before
const filtered = items.filter(x => x.active);
const mapped = filtered.map(x => x.name);  // Two iterations

// ✅ After
const result = [];
for (const item of items) {
  if (item.active) {
    result.push(item.name);  // Single iteration
  }
}
```

#### 8. Advanced Patterns (LOW)

**Regeln:**
- `advanced-event-handler-refs` - Store handlers in refs
- `advanced-init-once` - Initialize app once
- `advanced-use-latest` - useLatest for stable callbacks

### Phase 4: Report Erstellen

```markdown
# React Performance Report

**Dateien geprüft:** {N}
**Datum:** {YYYY-MM-DD}
**Overall Score:** {A/B/C/D}

---

## Priority Matrix

| Priority | Category | Issues | Status |
|----------|----------|--------|--------|
| CRITICAL | Eliminating Waterfalls | {N} | 🔴/🟡/🟢 |
| CRITICAL | Bundle Size | {N} | 🔴/🟡/🟢 |
| HIGH | Server-Side Performance | {N} | 🔴/🟡/🟢 |
| MEDIUM-HIGH | Client Data Fetching | {N} | 🔴/🟡/🟢 |
| MEDIUM | Re-render Optimization | {N} | 🔴/🟡/🟢 |
| MEDIUM | Rendering Performance | {N} | 🔴/🟡/🟢 |
| LOW-MEDIUM | JavaScript Performance | {N} | 🔴/🟡/🟢 |
| LOW | Advanced Patterns | {N} | 🔴/🟡/🟢 |

---

## Critical Issues

### Waterfall Issue in {file}

**Regel:** `async-parallel`

**Problem:** Sequential async operations
```tsx
{code}
```

**Fix:**
```tsx
{fixed code}
```

**Impact:** {Beschreibung des Performance-Gewinns}

---

## Re-render Issues

### Missing memoization

**Datei:** {file}
**Komponente:** {name}

**Problem:** Component re-renders unnecessarily

**Fix:** Wrap with memo()
```tsx
const Component = memo(function Component(props) {
  // ...
});
```

---

## Bundle Size Issues

### Static import of heavy component

**Datei:** {file}
**Komponente:** {name}

**Fix:** Use dynamic import
```tsx
const HeavyComponent = dynamic(() => import('./HeavyComponent'));
```

---

## Quick Wins

| File | Issue | Fix | Impact |
|------|-------|-----|--------|
| {file} | {issue} | {fix} | {impact} |

---

## Recommendations

1. **Immediate:** {Action}
2. **Short-term:** {Action}
3. **Long-term:** {Action}

---

## Referenz

React Best Practices: `.claude/skills/react-best-practices/SKILL.md`
```

## Scoring

**Score A (90-100%):**
- Keine Critical Issues
- Max 2 Medium Issues
- Alle Patterns korrekt angewendet

**Score B (75-89%):**
- Max 1 Critical Issue
- Max 5 Medium Issues
- Meiste Patterns korrekt

**Score C (60-74%):**
- Max 2 Critical Issues
- Einige Patterns fehlen

**Score D (<60%):**
- Mehrere Critical Issues
- Signifikante Performance-Probleme

## Tools

Nutze diese Tools zur Analyse:

1. **React DevTools Profiler**
   - Rendertimes messen
   - Unnecessary re-renders finden

2. **Next.js Bundle Analyzer**
   ```bash
   npm run analyze
   ```

3. **Lighthouse**
   - Performance score
   - Core Web Vitals

4. **Web Vitals**
   ```tsx
   import { webVitals } from 'next/web-vitals';
   ```
