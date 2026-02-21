---
name: web-design-guidelines
description: Review UI code for compliance with web interface best practices. Audits code for 100+ rules covering accessibility, performance, forms, animation, and UX. Use when reviewing UI, checking accessibility, auditing design, or implementing components.
---

# Web Design Guidelines

Comprehensive UI/UX best practices covering **accessibility, forms, animation, performance,** and **user experience**.

## When to Use

- Review my UI
- Check accessibility
- Audit design
- Review UX
- Check my site against best practices
- Implementing new components
- Code review for frontend changes

---

## 1. Accessibility (A11y)

### Icon-Only Buttons
**Rule:** Icon-only buttons need `aria-label`

❌ Bad:
```tsx
<button onClick={handleDelete}>
  <TrashIcon />
</button>
```

✅ Good:
```tsx
<button onClick={handleDelete} aria-label="Delete item">
  <TrashIcon aria-hidden="true" />
</button>
```

### Form Controls
**Rule:** Form controls need `<label>` or `aria-label`

❌ Bad:
```tsx
<input type="email" placeholder="Email" />
```

✅ Good:
```tsx
<label htmlFor="email">Email</label>
<input id="email" type="email" />

// OR
<input type="email" aria-label="Email address" />
```

### Keyboard Handlers
**Rule:** Interactive elements need keyboard handlers

```tsx
<div
  role="button"
  tabIndex={0}
  onClick={handleClick}
  onKeyDown={(e) => e.key === 'Enter' && handleClick()}
>
  Clickable
</div>
```

### Semantic HTML
**Rule:** Use semantic HTML before ARIA

| Use | Don't Use |
|-----|-----------|
| `<button>` | `<div onClick>` |
| `<a href>` | `<div onClick={navigate}>` |
| `<nav>` | `<div class="nav">` |
| `<main>` | `<div id="main">` |

### Images
**Rule:** Images need `alt` (or `alt=""` if decorative)

```tsx
// Informative image
<img src="chart.png" alt="Sales increased 25% in Q4" />

// Decorative image
<img src="decoration.png" alt="" role="presentation" />
```

### Decorative Icons
**Rule:** Decorative icons need `aria-hidden="true"`

```tsx
<InfoIcon aria-hidden="true" />
<span>Information text</span>
```

### Async Updates
**Rule:** Async updates need `aria-live`

```tsx
<div aria-live="polite" aria-atomic="true">
  {statusMessage}
</div>
```

### Headings Hierarchy
**Rule:** Headings hierarchical `<h1>`–`<h6>`

```tsx
<main>
  <h1>Page Title</h1>
  <section>
    <h2>Section Title</h2>
    <h3>Subsection</h3>
  </section>
</main>
```

### Skip Links
**Rule:** Include skip link for main content

```tsx
<a href="#main-content" className="sr-only focus:not-sr-only">
  Skip to main content
</a>
<main id="main-content">
  {/* Content */}
</main>
```

### Scroll Margin
**Rule:** `scroll-margin-top` on heading anchors

```css
h2[id] {
  scroll-margin-top: 80px; /* Account for fixed header */
}
```

---

## 2. Focus States

### Visible Focus
**Rule:** Interactive elements need visible focus

```tsx
<button className="focus-visible:ring-2 focus-visible:ring-blue-500">
  Click me
</button>
```

### Never Outline None
**Rule:** Never `outline-none` without replacement

❌ Bad:
```css
button:focus {
  outline: none;
}
```

✅ Good:
```css
button:focus-visible {
  outline: 2px solid blue;
  outline-offset: 2px;
}
```

### Use focus-visible
**Rule:** Use `:focus-visible` over `:focus`

```tsx
// focus-visible: only shows on keyboard navigation
<button className="focus-visible:ring-2">
  No ring on click, ring on keyboard
</button>
```

### Group Focus
**Rule:** Use `:focus-within` for compound controls

```tsx
<div className="focus-within:ring-2">
  <label>Search</label>
  <input type="search" />
</div>
```

---

## 3. Forms

### Autocomplete
**Rule:** Inputs need `autocomplete` and meaningful `name`

```tsx
<input
  type="email"
  name="email"
  autoComplete="email"
/>
```

### Input Types
**Rule:** Use correct `type` and `inputmode`

```tsx
<input type="tel" inputMode="tel" />
<input type="email" inputMode="email" />
<input type="url" inputMode="url" />
<input type="number" inputMode="numeric" />
```

### Never Block Paste
**Rule:** Never block paste (`onPaste` + `preventDefault`)

❌ Bad:
```tsx
<input onPaste={(e) => e.preventDefault()} />
```

### Clickable Labels
**Rule:** Labels clickable (`htmlFor` or wrapping)

```tsx
{/* Option 1: htmlFor */}
<label htmlFor="username">Username</label>
<input id="username" />

{/* Option 2: Wrapping */}
<label>
  Username
  <input />
</label>
```

### Spellcheck
**Rule:** Disable spellcheck on emails, codes, usernames

```tsx
<input type="email" spellCheck={false} />
<input type="text" spellCheck={false} /> {/* For codes/usernames */}
```

### Checkbox/Radio Hit Target
**Rule:** Label + control share single hit target

```tsx
<label className="flex items-center gap-2 cursor-pointer">
  <input type="checkbox" className="cursor-pointer" />
  <span>Label text</span>
</label>
```

### Submit Button State
**Rule:** Submit button stays enabled, spinner during request

```tsx
<button type="submit" disabled={isSubmitting}>
  {isSubmitting ? (
    <>
      <Spinner />
      Saving...
    </>
  ) : (
    'Save'
  )}
</button>
```

### Inline Errors
**Rule:** Errors inline next to fields

```tsx
<div>
  <label htmlFor="email">Email</label>
  <input
    id="email"
    aria-invalid={!!error}
    aria-describedby={error ? 'email-error' : undefined}
  />
  {error && (
    <span id="email-error" className="text-red-500" role="alert">
      {error}
    </span>
  )}
</div>
```

### Focus First Error
**Rule:** Focus first error on submit

```typescript
useEffect(() => {
  if (errors.length > 0) {
    const firstError = document.querySelector('[aria-invalid="true"]');
    firstError?.focus();
  }
}, [errors]);
```

### Placeholders
**Rule:** Placeholders end with `…` and show example pattern

```tsx
<input
  placeholder="user@example.com…"
/>
```

### Autocomplete Off
**Rule:** `autocomplete="off"` on non-auth fields

```tsx
<input type="text" name="coupon" autoComplete="off" />
```

### Unsaved Changes Warning
**Rule:** Warn before navigation with unsaved changes

```tsx
useEffect(() => {
  const handleBeforeUnload = (e: BeforeUnloadEvent) => {
    if (hasUnsavedChanges) {
      e.preventDefault();
    }
  };
  window.addEventListener('beforeunload', handleBeforeUnload);
  return () => window.removeEventListener('beforeunload', handleBeforeUnload);
}, [hasUnsavedChanges]);
```

---

## 4. Animation

### Reduced Motion
**Rule:** Honor `prefers-reduced-motion`

```tsx
const prefersReducedMotion =
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches;

<motion.div
  animate={prefersReducedMotion ? {} : { opacity: 1 }}
/>
```

```css
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

### Compositor-Friendly
**Rule:** Animate `transform`/`opacity` only

✅ Compositor-friendly (GPU accelerated):
- `transform: translateX()`, `translateY()`
- `transform: scale()`
- `opacity`

❌ Layout-triggering (CPU, slow):
- `width`, `height`
- `top`, `left`, `right`, `bottom`
- `margin`, `padding`

### No Transition All
**Rule:** Never `transition: all`—list properties explicitly

❌ Bad:
```css
.element {
  transition: all 0.3s ease;
}
```

✅ Good:
```css
.element {
  transition: transform 0.3s ease, opacity 0.3s ease;
}
```

### Transform Origin
**Rule:** Set correct `transform-origin`

```css
.scale-up {
  transform: scale(1.5);
  transform-origin: center;
}
```

### SVG Transforms
**Rule:** Transforms on `<g>` wrapper with `transform-box`

```tsx
<svg>
  <g
    style={{
      transform: 'rotate(45deg)',
      transformBox: 'fill-box',
      transformOrigin: 'center',
    }}
  >
    <path />
  </g>
</svg>
```

### Interruptible Animations
**Rule:** Animations must respond to user input mid-animation

```tsx
<motion.div
  drag
  dragMomentum={false} // Stop immediately on release
/>
```

---

## 5. Typography

### Ellipsis
**Rule:** Use `…` not `...`

```tsx
<span>Loading…</span>  // Not: Loading...
```

### Curly Quotes
**Rule:** Curly quotes `"` `"` not straight `"`

❌ Bad: `"Hello world"`
✅ Good: `"Hello world"`

### Non-Breaking Spaces
**Rule:** Non-breaking spaces for units and brands

```tsx
<span>10&nbsp;MB</span>
<span>⌘&nbsp;K</span>
<span>My&nbsp;Brand</span>
```

### Loading States
**Rule:** Loading states end with `…`

```tsx
<button disabled>
  <Spinner />
  Saving…
</button>
```

### Tabular Numbers
**Rule:** `font-variant-numeric: tabular-nums` for numbers

```tsx
<table className="tabular-nums">
  {/* Columns align perfectly */}
</table>
```

```css
.numeric {
  font-variant-numeric: tabular-nums;
}
```

### Text Wrap
**Rule:** Use `text-wrap: balance` or `text-pretty` on headings

```css
h1, h2, h3 {
  text-wrap: balance;
}
```

---

## 6. Content Handling

### Text Overflow
**Rule:** Text containers handle long content

```tsx
{/* Option 1: Truncate */}
<span className="truncate">{longText}</span>

{/* Option 2: Line clamp */}
<p className="line-clamp-3">{longText}</p>

{/* Option 3: Break words */}
<p className="break-words">{longText}</p>
```

### Flex Truncation
**Rule:** Flex children need `min-w-0` to allow text truncation

```tsx
<div className="flex">
  <span className="min-w-0 truncate">{longText}</span>
  <button>Action</button>
</div>
```

### Empty States
**Rule:** Handle empty states—don't render broken UI

```tsx
{items.length > 0 ? (
  <List items={items} />
) : (
  <EmptyState message="No items yet" />
)}
```

### Input Variations
**Rule:** Anticipate short, average, and very long inputs

```tsx
// Test with:
// - Empty string
// - "A"
// - Normal text
// - Very long text (1000+ chars)
// - Special characters
// - Unicode
```

---

## 7. Images

### Dimensions
**Rule:** `<img>` needs explicit `width` and `height`

```tsx
<img
  src="/photo.jpg"
  width={800}
  height={600}
  alt="Description"
/>
```

### Lazy Loading
**Rule:** Below-fold images: `loading="lazy"`

```tsx
{/* Above fold - eager */}
<img src="hero.jpg" loading="eager" priority />

{/* Below fold - lazy */}
<img src="gallery.jpg" loading="lazy" />
```

### Priority Images
**Rule:** Above-fold critical images: `priority`

```tsx
import Image from 'next/image';

<Image src="/hero.jpg" priority alt="Hero" />
```

---

## 8. Performance

### Virtualization
**Rule:** Large lists (>50 items): virtualize

```tsx
import { Virtualizer } from 'virtua';

<Virtualizer>
  {items.map(item => (
    <div key={item.id}>{item.name}</div>
  ))}
</Virtualizer>
```

### Content Visibility
**Rule:** Use `content-visibility: auto` for long lists

```css
.list-item {
  content-visibility: auto;
  contain-intrinsic-size: 0 100px;
}
```

### No Layout Reads in Render
**Rule:** No layout reads in render

❌ Bad:
```tsx
function Component() {
  const height = ref.current?.offsetHeight; // Triggers reflow
  return <div style={{ height }} />;
}
```

✅ Good:
```tsx
function Component() {
  const [height, setHeight] = useState(0);

  useLayoutEffect(() => {
    setHeight(ref.current?.offsetHeight);
  }, []);

  return <div style={{ height }} />;
}
```

### Batch DOM Operations
**Rule:** Batch DOM reads/writes; avoid interleaving

```tsx
// Read all, then write all
const heights = elements.map(el => el.offsetHeight);
elements.forEach((el, i) => {
  el.style.height = heights[i] + 'px';
});
```

### Uncontrolled Inputs
**Rule:** Prefer uncontrolled inputs

```tsx
// Uncontrolled - cheaper per keystroke
<input defaultValue={initialValue} />

// Controlled only when needed
<input value={value} onChange={handleChange} />
```

### Preconnect
**Rule:** Add `<link rel="preconnect">` for CDN/asset domains

```tsx
import Head from 'next/head';

<Head>
  <link rel="preconnect" href="https://cdn.example.com" />
  <link rel="dns-prefetch" href="https://cdn.example.com" />
</Head>
```

### Font Preload
**Rule:** Critical fonts: `<link rel="preload" as="font">`

```tsx
<Head>
  <link
    rel="preload"
    href="/fonts/inter.woff2"
    as="font"
    type="font/woff2"
    crossOrigin="anonymous"
  />
</Head>
```

```css
@font-face {
  font-family: 'Inter';
  src: url('/fonts/inter.woff2') format('woff2');
  font-display: swap;
}
```

---

## 9. Navigation & State

### URL Reflects State
**Rule:** URL reflects state—filters, tabs, pagination

```tsx
// Use nuqs or similar for URL state
const [filter, setFilter] = useQueryState('filter');

// URL: /page?filter=active
```

### Real Links
**Rule:** Links use `<a>`/`<Link>` (not onClick)

❌ Bad:
```tsx
<div onClick={() => router.push('/page')}>
  Go to page
</div>
```

✅ Good:
```tsx
<Link href="/page">Go to page</Link>
```

### Deep Linking
**Rule:** Deep-link all stateful UI

```tsx
// If it uses useState, consider URL sync
const [tab, setTab] = useQueryState('tab');
const [page, setPage] = useQueryState('page', parseAsInteger);
```

### Destructive Actions
**Rule:** Destructive actions need confirmation

```tsx
const handleDelete = () => {
  if (confirm('Are you sure? This cannot be undone.')) {
    deleteItem();
  }
};

// Or with modal
const [showConfirm, setShowConfirm] = useState(false);
```

---

## 10. Touch & Interaction

### Touch Action
**Rule:** `touch-action: manipulation` prevents double-tap zoom

```css
button, a {
  touch-action: manipulation;
}
```

### Tap Highlight
**Rule:** `-webkit-tap-highlight-color` set intentionally

```css
/* Remove or customize */
button {
  -webkit-tap-highlight-color: transparent;
}

/* Or with visible feedback */
button {
  -webkit-tap-highlight-color: rgba(0, 0, 0, 0.1);
}
```

### Overscroll Behavior
**Rule:** `overscroll-behavior: contain` in modals/drawers

```css
.modal {
  overscroll-behavior: contain;
}
```

### Drag Disable Selection
**Rule:** During drag: disable text selection, `inert` on dragged elements

```tsx
<div
  draggable
  style={{
    userSelect: 'none',
    WebkitUserSelect: 'none',
  }}
>
  Draggable content
</div>
```

### AutoFocus
**Rule:** `autoFocus` sparingly—desktop only

```tsx
// Desktop only
<input autoFocus={!isMobile} />

// Single primary input
<SearchInput autoFocus />
```

---

## 11. Safe Areas & Layout

### Safe Area Insets
**Rule:** Full-bleed layouts need `env(safe-area-inset-*)`

```css
.safe-bottom {
  padding-bottom: env(safe-area-inset-bottom);
}

.safe-top {
  padding-top: env(safe-area-inset-top);
}
```

### Avoid Unwanted Scrollbars
**Rule:** Fix content overflow

```css
.container {
  overflow-x: hidden;
}
```

### Flex/Grid Over JS
**Rule:** Flex/grid over JS measurement for layout

❌ Bad:
```tsx
const [width, setWidth] = useState(0);
useEffect(() => {
  setWidth(ref.current.offsetWidth / 3);
}, []);
```

✅ Good:
```tsx
// CSS-only
<div className="grid grid-cols-3">
```

---

## 12. Dark Mode & Theming

### Color Scheme
**Rule:** `color-scheme: dark` on `<html>` for dark themes

```tsx
<html className="dark" style={{ colorScheme: 'dark' }}>
```

### Theme Color Meta
**Rule:** `<meta name="theme-color">` matches page background

```tsx
<Head>
  <meta name="theme-color" content="#000000" />
</Head>
```

### Native Select Styling
**Rule:** Native `<select>`: explicit `background-color` and `color`

```css
select {
  background-color: white;
  color: black;
}

.dark select {
  background-color: black;
  color: white;
}
```

---

## 13. Locale & i18n

### Date/Time Format
**Rule:** Use `Intl.DateTimeFormat` not hardcoded formats

```tsx
const formatter = new Intl.DateTimeFormat('de-DE', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

formatter.format(new Date()); // "24.12.2024, 14:30"
```

### Number/Currency Format
**Rule:** Use `Intl.NumberFormat` not hardcoded formats

```tsx
const numberFormatter = new Intl.NumberFormat('de-DE');
numberFormatter.format(1234567); // "1.234.567"

const currencyFormatter = new Intl.NumberFormat('de-DE', {
  style: 'currency',
  currency: 'EUR',
});
currencyFormatter.format(99.99); // "99,99 €"
```

### Language Detection
**Rule:** Detect via `Accept-Language` / `navigator.languages`, not IP

```tsx
const locale = navigator.languages[0] || 'en-US';
```

---

## 14. Hydration Safety

### Controlled Inputs
**Rule:** Inputs with `value` need `onChange` (or use `defaultValue`)

```tsx
// Controlled
<input value={value} onChange={handleChange} />

// Uncontrolled (safer for SSR)
<input defaultValue={initialValue} />
```

### Date/Time Guard
**Rule:** Guard against hydration mismatch for dates

```tsx
const [mounted, setMounted] = useState(false);
useEffect(() => setMounted(true), []);

return (
  <span suppressHydrationWarning>
    {mounted ? formatDate(date) : '--'}
  </span>
);
```

### Suppress Hydration Warning
**Rule:** Only where truly needed

```tsx
<span suppressHydrationWarning>
  {new Date().toLocaleString()}
</span>
```

---

## 15. Hover & Interactive States

### Hover States
**Rule:** Buttons/links need `hover:` state

```tsx
<button className="hover:bg-blue-600 transition-colors">
  Hover me
</button>
```

### Interactive Contrast
**Rule:** Interactive states increase contrast

```css
button {
  background: blue;
}

button:hover {
  background: darkblue;
}

button:active {
  background: darkerblue;
}
```

---

## 16. Content & Copy

### Active Voice
**Rule:** Active voice: "Install the CLI" not "The CLI will be installed"

### Title Case
**Rule:** Title Case for headings/buttons (Chicago style)

✅ Good:
- "Save Changes"
- "Delete Account"
- "Add New Item"

### Numerals for Counts
**Rule:** Numerals for counts: "8 deployments" not "eight"

### Specific Labels
**Rule:** Specific button labels: "Save API Key" not "Continue"

### Error Messages
**Rule:** Error messages include fix/next step

❌ Bad: "Error occurred"
✅ Good: "Failed to save: Check your connection and try again"

### Second Person
**Rule:** Second person; avoid first person

❌ Bad: "We couldn't find your account"
✅ Good: "Your account couldn't be found"

### Ampersand
**Rule:** `&` over "and" where space-constrained

```tsx
<button>Save & Close</button>
```

---

## 17. Anti-patterns (Flag These)

| Anti-pattern | Why It's Bad | Solution |
|--------------|--------------|----------|
| `user-scalable=no` | Breaks accessibility | Remove it |
| `onPaste` with `preventDefault` | Blocks password managers | Don't prevent paste |
| `transition: all` | Performance issue | List properties explicitly |
| `outline-none` without replacement | Breaks keyboard nav | Use focus-visible |
| Inline `onClick` navigation without `<a>` | Breaks Cmd+click | Use `<Link>` |
| `<div>` with click handlers | Not accessible | Use `<button>` |
| Images without dimensions | Causes CLS | Add width/height |
| Large arrays `.map()` without virtualization | Slow performance | Use virtualization |
| Form inputs without labels | Not accessible | Add labels |
| Icon buttons without `aria-label` | Screen readers can't read | Add aria-label |
| Hardcoded date/number formats | Breaks i18n | Use `Intl.*` |
| `autoFocus` without clear justification | Mobile UX issue | Remove or condition |

---

## Quick Reference: ImgClean Critical Rules

| Category | Critical Rules |
|----------|---------------|
| **Accessibility** | Icon buttons `aria-label`, Image `alt`, Keyboard handlers |
| **Forms** | Input labels, Inline errors, Unsaved changes warning |
| **Images** | Explicit dimensions, Lazy loading below fold |
| **Performance** | Virtualize large lists, Preconnect to R2 CDN |
| **Touch** | `touch-action: manipulation`, Disable text selection on drag |

---

## Sources

- [Vercel Web Interface Guidelines](https://github.com/vercel-labs/agent-skills)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [Inclusive Design Principles](https://inclusivedesignprinciples.org/)
