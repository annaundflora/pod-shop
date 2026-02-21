---
name: ui-implementation-checklist
description: Auto-generated checklist from Skills for UI implementation. Use for all frontend tasks.
skills:
  - react-best-practices
  - web-design
  - tailwind-v4
---

# UI Implementation Checklist (Auto-Generated from Skills)

**Source:** `.claude/skills/`

## Pre-Implementation (MANDATORY)

### 1. Load Skills
- [ ] Read: `.claude/skills/react-best-practices/SKILL.md`
- [ ] Read: `.claude/skills/web-design/SKILL.md`
- [ ] Read: `.claude/skills/tailwind-v4/SKILL.md`

### 2. Architecture Decisions
- [ ] Server vs Client Components decided
- [ ] Data Fetching Strategy (parallel vs sequential)
- [ ] State Management Approach

---

## During Implementation

### React Best Practices

**CRITICAL (Must Implement):**
- [ ] `async-parallel`: Use Promise.all() for independent operations
  ```typescript
  // GOOD
  const [user, posts] = await Promise.all([fetchUser(), fetchPosts()]);
  ```
- [ ] `bundle-dynamic-imports`: Use next/dynamic for heavy components
  ```typescript
  const HeavyChart = dynamic(() => import('./HeavyChart'));
  ```

**HIGH Priority:**
- [ ] `server-cache-react`: Use React.cache() for deduplication
  ```typescript
  const getUser = cache(async (id) => db.user.findUnique({ where: { id } }));
  ```

**MEDIUM Priority:**
- [ ] `rerender-memo`: Wrap expensive components with memo()
  ```typescript
  const List = memo(function List({ items }) { ... });
  ```
- [ ] `rendering-content-visibility`: Use content-visibility for lists >50 items
  ```css
  .list-item { content-visibility: auto; contain-intrinsic-size: 0 100px; }
  ```

### Web Design Guidelines

**Accessibility (A11y):**
- [ ] Icon-only buttons: Add `aria-label`
  ```tsx
  <button aria-label="Delete item"><TrashIcon aria-hidden="true" /></button>
  ```
- [ ] Form inputs: Use `<label>` or `aria-label`
  ```tsx
  <label htmlFor="email">Email</label>
  <input id="email" type="email" />
  ```
- [ ] Interactive elements: Add keyboard handlers
  ```tsx
  <div role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && handleClick()} />
  ```
- [ ] Images: Set `width` + `height` (prevent CLS)
  ```tsx
  <Image src="/photo.jpg" width={800} height={600} alt="Description" />
  ```
- [ ] Decorative icons: Add `aria-hidden="true"`
  ```tsx
  <InfoIcon aria-hidden="true" />
  ```

**Forms:**
- [ ] Inputs: Use `autocomplete` + meaningful `name`
  ```tsx
  <input type="email" name="email" autoComplete="email" />
  ```
- [ ] Correct `type` (`email`, `tel`, `url`, `number`)
- [ ] Labels clickable (`htmlFor` or wrapping)
- [ ] Inline errors: Next to fields with `role="alert"`
  ```tsx
  {error && <span role="alert" className="text-red-500">{error}</span>}
  ```

**Performance:**
- [ ] Images below-fold: Use `loading="lazy"`
- [ ] Large lists: Virtualize (>50 items)
  ```tsx
  <Virtualizer>{items.map(...)}</Virtualizer>
  ```

**Touch & Interaction:**
- [ ] `touch-action: manipulation` on buttons
  ```css
  button { touch-action: manipulation; }
  ```
- [ ] Tap targets minimum 44x44px

### Tailwind v4 Patterns

**Configuration:**
- [ ] `@theme` block for custom tokens
  ```css
  @theme {
    --color-primary: #3b82f6;
    --color-secondary: #64748b;
  }
  ```
- [ ] Semantic naming (primary, secondary, not blue-500)

**Implementation:**
- [ ] No hardcoded values (use tokens)
- [ ] Dark mode support with `dark:` modifier
  ```tsx
  <div className="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100" />
  ```
- [ ] Container queries for component-level responsive
  ```tsx
  <div className="@container">
    <div className="grid-cols-1 @md:grid-cols-2" />
  </div>
  ```

---

## Post-Implementation

### Self-Check
- [ ] All Critical Rules implemented
- [ ] All Accessibility Checks passed
- [ ] No hardcoded Tailwind values

### Documentation
- [ ] Applied rules documented (comments)
  ```tsx
  // Rule: async-parallel (React Best Practices)
  const [user, posts] = await Promise.all([fetchUser(), fetchPosts()]);
  ```
- [ ] Special patterns noted

---

## Quick Reference: Critical Rules for ImgClean

| Rule | Skill | Component |
|------|-------|-----------|
| `async-parallel` | React | Image upload processing |
| `bundle-dynamic-imports` | React | Heavy image preview |
| `rerender-memo` | React | Image grid, thumbnail lists |
| Icon `aria-label` | Web Design | Upload, Delete buttons |
| Image `width/height` | Web Design | Thumbnail grid |
| `touch-action` | Web Design | Drag-and-drop upload |
| `@theme` tokens | Tailwind | All components |
