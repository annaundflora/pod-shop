---
name: react-best-practices
description: React and Next.js performance optimization guidelines from Vercel Engineering. Contains 57 rules across 8 categories, prioritized by impact. Use when writing React components, implementing data fetching, reviewing code for performance, or optimizing bundle size.
---

# React Best Practices

Performance optimization guidelines with **57 rules** in **8 categories**, prioritized by impact.

## When to Use

- Writing new React components or Next.js pages
- Implementing data fetching (client or server-side)
- Reviewing code for performance issues
- Refactoring existing React/Next.js code
- Optimizing bundle size or load times

---

## Categories by Priority

| Priority | Category | Impact | Prefix |
|----------|----------|--------|--------|
| 1 | Eliminating Waterfalls | CRITICAL | `async-` |
| 2 | Bundle Size Optimization | CRITICAL | `bundle-` |
| 3 | Server-Side Performance | HIGH | `server-` |
| 4 | Client-Side Data Fetching | MEDIUM-HIGH | `client-` |
| 5 | Re-render Optimization | MEDIUM | `rerender-` |
| 6 | Rendering Performance | MEDIUM | `rendering-` |
| 7 | JavaScript Performance | LOW-MEDIUM | `js-` |
| 8 | Advanced Patterns | LOW | `advanced-` |

---

## 1. Eliminating Waterfalls (CRITICAL)

### async-defer-await
**Rule:** Move await into branches where actually used

❌ Bad:
```typescript
async function getData() {
  const user = await fetchUser();  // Blocks everything
  const posts = await fetchPosts();
  const comments = await fetchComments();
  return { user, posts, comments };
}
```

✅ Good:
```typescript
async function getData() {
  const userPromise = fetchUser();
  const postsPromise = fetchPosts();
  const commentsPromise = fetchComments();

  const [user, posts, comments] = await Promise.all([
    userPromise,
    postsPromise,
    commentsPromise
  ]);
  return { user, posts, comments };
}
```

### async-parallel
**Rule:** Use Promise.all() for independent operations

### async-dependencies
**Rule:** Use better-all for partial dependencies (start fetches early)

### async-api-routes
**Rule:** Start promises early, await late in API routes

```typescript
// Start immediately
const userPromise = fetchUser(userId);
const settingsPromise = fetchSettings(userId);

// Do other work...
validateRequest(req);

// Await at the end
const [user, settings] = await Promise.all([userPromise, settingsPromise]);
```

### async-suspense-boundaries
**Rule:** Use Suspense to stream content progressively

```tsx
<Suspense fallback={<Skeleton />}>
  <SlowComponent />
</Suspense>
```

---

## 2. Bundle Size Optimization (CRITICAL)

### bundle-barrel-imports
**Rule:** Import directly, avoid barrel files

❌ Bad:
```typescript
import { Button, Input, Card } from '@/components';  // Imports everything
```

✅ Good:
```typescript
import { Button } from '@/components/button';
import { Input } from '@/components/input';
```

### bundle-dynamic-imports
**Rule:** Use next/dynamic for heavy components

```typescript
import dynamic from 'next/dynamic';

const HeavyChart = dynamic(() => import('./HeavyChart'), {
  ssr: false,
  loading: () => <Skeleton />
});
```

### bundle-defer-third-party
**Rule:** Load analytics/logging after hydration

```typescript
import Script from 'next/script';

<Script
  src="https://analytics.com/script.js"
  strategy="afterInteractive"  // or "lazyOnload"
/>
```

### bundle-conditional
**Rule:** Load modules only when feature is activated

### bundle-preload
**Rule:** Preload on hover/focus for perceived speed

```typescript
const linkRef = useRef<HTMLAnchorElement>(null);

const onMouseEnter = () => {
  const href = linkRef.current?.href;
  if (href) {
    router.prefetch(href);
  }
};
```

---

## 3. Server-Side Performance (HIGH)

### server-auth-actions
**Rule:** Authenticate server actions like API routes

### server-cache-react
**Rule:** Use React.cache() for per-request deduplication

```typescript
import { cache } from 'react';

const getUser = cache(async (id: string) => {
  return db.user.findUnique({ where: { id } });
});

// Called multiple times, but only one DB query
const user = await getUser(id);
const sameUser = await getUser(id);
```

### server-cache-lru
**Rule:** Use LRU cache for cross-request caching

```typescript
import { LRUCache } from 'lru-cache';

const cache = new LRUCache({
  max: 500,
  ttl: 1000 * 60 * 5,  // 5 minutes
});

export async function getCachedData(key: string) {
  if (cache.has(key)) {
    return cache.get(key);
  }
  const data = await fetchData(key);
  cache.set(key, data);
  return data;
}
```

### server-dedup-props
**Rule:** Avoid duplicate serialization in RSC props

### server-serialization
**Rule:** Minimize data passed to client components

❌ Bad:
```typescript
<ClientComponent user={fullUserObjectWithPassword} />
```

✅ Good:
```typescript
<ClientComponent user={pick(fullUser, ['id', 'name', 'email'])} />
```

### server-parallel-fetching
**Rule:** Restructure components to parallelize fetches

### server-after-nonblocking
**Rule:** Use after() for non-blocking operations

```typescript
import { after } from 'next/server';

export default async function Page() {
  const data = await fetchData();

  after(async () => {
    await logAnalytics(data.id);  // Non-blocking
  });

  return <View data={data} />;
}
```

---

## 4. Client-Side Data Fetching (MEDIUM-HIGH)

### client-swr-dedup
**Rule:** Use SWR/TanStack Query for automatic request deduplication

```typescript
const { data } = useSWR('/api/user', fetcher, {
  dedupingInterval: 2000,  // 2 seconds
});
```

### client-event-listeners
**Rule:** Deduplicate global event listeners

```typescript
// Use a single window listener, not per-component
useEffect(() => {
  const handler = () => setIsMobile(window.innerWidth < 768);
  window.addEventListener('resize', handler);
  return () => window.removeEventListener('resize', handler);
}, []);
```

### client-passive-event-listeners
**Rule:** Use passive listeners for scroll

```typescript
element.addEventListener('scroll', handler, { passive: true });
```

### client-localstorage-schema
**Rule:** Version and minimize localStorage data

---

## 5. Re-render Optimization (MEDIUM)

### rerender-defer-reads
**Rule:** Don't subscribe to state only used in callbacks

### rerender-memo
**Rule:** Extract expensive work into memoized components

```typescript
const ExpensiveList = memo(function ExpensiveList({ items }) {
  return (
    <ul>
      {items.map(item => (
        <ExpensiveItem key={item.id} item={item} />
      ))}
    </ul>
  );
});
```

### rerender-memo-with-default-value
**Rule:** Hoist default non-primitive props

❌ Bad:
```typescript
function Button({ items = [] }) {  // New array every render
  // ...
}
```

✅ Good:
```typescript
const DEFAULT_ITEMS: string[] = [];

function Button({ items = DEFAULT_ITEMS }) {
  // ...
}
```

### rerender-dependencies
**Rule:** Use primitive dependencies in effects

❌ Bad:
```typescript
useEffect(() => {
  fetchData(filters);
}, [filters]);  // Object, triggers every render
```

✅ Good:
```typescript
useEffect(() => {
  fetchData(filters);
}, [filters.status, filters.page]);  // Primitives
```

### rerender-derived-state
**Rule:** Subscribe to derived booleans, not raw values

```typescript
// Instead of checking array length in render
const hasItems = items.length > 0;  // Computed once
```

### rerender-derived-state-no-effect
**Rule:** Derive state during render, not effects

❌ Bad:
```typescript
const [fullName, setFullName] = useState('');
useEffect(() => {
  setFullName(`${firstName} ${lastName}`);
}, [firstName, lastName]);
```

✅ Good:
```typescript
const fullName = `${firstName} ${lastName}`;  // Derive in render
```

### rerender-functional-setstate
**Rule:** Use functional setState for stable callbacks

```typescript
setCount(prev => prev + 1);  // Always correct
```

### rerender-lazy-state-init
**Rule:** Pass function to useState for expensive values

```typescript
const [data] = useState(() => computeExpensiveData());  // Once
```

### rerender-simple-expression-in-memo
**Rule:** Avoid memo for simple primitives

❌ Bad:
```typescript
const isActive = useMemo(() => status === 'active', [status]);
```

✅ Good:
```typescript
const isActive = status === 'active';  // Simple comparison
```

### rerender-move-effect-to-event
**Rule:** Put interaction logic in event handlers

```typescript
// Instead of useEffect that responds to state changes
const handleClick = () => {
  setCount(c => c + 1);
  logAnalytics('button_click');  // In event, not effect
};
```

### rerender-transitions
**Rule:** Use startTransition for non-urgent updates

```typescript
import { useTransition } from 'react';

const [isPending, startTransition] = useTransition();

const handleFilter = (newFilter) => {
  startTransition(() => {
    setFilter(newFilter);  // Non-urgent
  });
};
```

### rerender-use-ref-transient-values
**Rule:** Use refs for transient frequent values

```typescript
const mousePos = useRef({ x: 0, y: 0 });

useEffect(() => {
  const handler = (e) => {
    mousePos.current = { x: e.clientX, y: e.clientY };
  };
  window.addEventListener('mousemove', handler);
  return () => window.removeEventListener('mousemove', handler);
}, []);
```

---

## 6. Rendering Performance (MEDIUM)

### rendering-animate-svg-wrapper
**Rule:** Animate div wrapper, not SVG element

❌ Bad:
```tsx
<svg style={{ transform: 'scale(1.5)' }}>  // Slow
```

✅ Good:
```tsx
<div style={{ transform: 'scale(1.5)' }}>
  <svg>  // Unchanged
```

### rendering-content-visibility
**Rule:** Use content-visibility for long lists

```css
.list-item {
  content-visibility: auto;
  contain-intrinsic-size: 0 100px;
}
```

### rendering-hoist-jsx
**Rule:** Extract static JSX outside components

```typescript
const StaticIcon = <svg>...</svg>;  // Defined once

function Button() {
  return <button>{StaticIcon}</button>;  // Reused
}
```

### rendering-svg-precision
**Rule:** Reduce SVG coordinate precision

```typescript
// Round to 2 decimal places
const d = `M ${x.toFixed(2)} ${y.toFixed(2)}`;
```

### rendering-hydration-no-flicker
**Rule:** Use inline script for client-only data

### rendering-hydration-suppress-warning
**Rule:** Suppress expected mismatches

```tsx
<span suppressHydrationWarning>
  {new Date().toLocaleString()}
</span>
```

### rendering-activity
**Rule:** Use Activity component for show/hide

### rendering-conditional-render
**Rule:** Use ternary, not && for conditionals

❌ Bad:
```tsx
{items.length && <List items={items} />}  // Renders "0"
```

✅ Good:
```tsx
{items.length > 0 ? <List items={items} /> : null}
```

### rendering-usetransition-loading
**Rule:** Prefer useTransition for loading state

---

## 7. JavaScript Performance (LOW-MEDIUM)

### js-batch-dom-css
**Rule:** Group CSS changes via classes or cssText

❌ Bad:
```typescript
element.style.width = '100px';
element.style.height = '100px';
element.style.margin = '10px';  // 3 reflows
```

✅ Good:
```typescript
element.className = 'size-100 m-10';  // 1 reflow
```

### js-index-maps
**Rule:** Build Map for repeated lookups

```typescript
const userMap = new Map(users.map(u => [u.id, u]));

// O(1) lookup instead of O(n) find
const user = userMap.get(id);
```

### js-cache-property-access
**Rule:** Cache object properties in loops

```typescript
for (let i = 0, len = array.length; i < len; i++) {
  // len cached, not accessed every iteration
}
```

### js-cache-function-results
**Rule:** Cache function results in module-level Map

### js-cache-storage
**Rule:** Cache localStorage/sessionStorage reads

### js-combine-iterations
**Rule:** Combine multiple filter/map into one loop

❌ Bad:
```typescript
const filtered = items.filter(x => x.active);
const mapped = filtered.map(x => x.name);
```

✅ Good:
```typescript
const result: string[] = [];
for (const item of items) {
  if (item.active) {
    result.push(item.name);
  }
}
```

### js-length-check-first
**Rule:** Check array length before expensive comparison

### js-early-exit
**Rule:** Return early from functions

### js-hoist-regexp
**Rule:** Hoist RegExp creation outside loops

### js-min-max-loop
**Rule:** Use loop for min/max instead of sort

### js-set-map-lookups
**Rule:** Use Set/Map for O(1) lookups

```typescript
const validIds = new Set(ids);  // O(n) once

if (validIds.has(id)) {  // O(1) lookup
  // ...
}
```

### js-tosorted-immutable
**Rule:** Use toSorted() for immutability

```typescript
const sorted = items.toSorted((a, b) => a - b);  // New array
```

---

## 8. Advanced Patterns (LOW)

### advanced-event-handler-refs
**Rule:** Store event handlers in refs

### advanced-init-once
**Rule:** Initialize app once per app load

### advanced-use-latest
**Rule:** useLatest for stable callback refs

---

## Quick Reference: Critical Rules for ImgClean

| Rule | Apply to |
|------|----------|
| `async-parallel` | Image upload processing |
| `bundle-dynamic-imports` | Heavy image preview components |
| `server-cache-react` | Metadata profile fetching |
| `rerender-memo` | Image grid, thumbnail lists |
| `rendering-content-visibility` | Large image galleries |
| `js-set-map-lookups` | Image ID lookups |

---

## Sources

- [Vercel React Best Practices](https://github.com/vercel-labs/agent-skills)
- [React Performance Optimization](https://react.dev/learn/render-and-commit)
