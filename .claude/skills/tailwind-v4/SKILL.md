---
name: tailwind-v4-patterns
description: Tailwind CSS v4 best practices, design system patterns, and CSS-first configuration. Use when implementing UI components, configuring Tailwind, or styling with utility classes.
---

# Tailwind CSS v4 Patterns

Tailwind CSS v4 introduces a **CSS-first configuration** approach, moving away from JavaScript config files to pure CSS with `@theme` and `@import`.

## When to Use

- Configuring Tailwind v4
- Creating design tokens
- Building component styles
- Migrating from Tailwind v3
- Setting up dark mode
- Customizing the theme

---

## 0. Build Tool Integration (REQUIRED)

Tailwind v4 **requires** a build tool plugin to process `@import "tailwindcss"` and generate utility classes. Without the plugin, no utility classes are generated and all Tailwind class names have no effect.

| Build Tool | Package | Config Location |
|------------|---------|-----------------|
| Vite | `@tailwindcss/vite` | `vite.config.ts` → `plugins: [tailwindcss()]` |
| PostCSS | `@tailwindcss/postcss` | `postcss.config.js` → `plugins: { '@tailwindcss/postcss': {} }` |
| CLI | `@tailwindcss/cli` | `npx @tailwindcss/cli -i input.css -o output.css` |

Vite example:
```typescript
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [tailwindcss(), react()],
})
```

---

## 1. CSS-First Configuration

### No More tailwind.config.js
**Rule:** Use CSS-based configuration with `@theme`

❌ Old (v3):
```javascript
// tailwind.config.js
module.exports = {
  theme: {
    extend: {
      colors: {
        primary: '#3b82f6',
      },
    },
  },
}
```

✅ New (v4):
```css
/* styles.css */
@import "tailwindcss";

@theme {
  --color-primary: #3b82f6;
  --color-secondary: #64748b;

  --font-sans: ui-sans-serif, system-ui, sans-serif;
  --font-mono: ui-monospace, monospace;

  --spacing-xs: 0.25rem;
  --spacing-sm: 0.5rem;
}
```

### @theme Block
**Rule:** Define all design tokens in `@theme`

```css
@theme {
  /* Colors */
  --color-brand: oklch(0.65 0.2 250);
  --color-brand-dark: oklch(0.45 0.2 250);

  /* Typography */
  --font-heading: 'Inter', sans-serif;
  --font-body: 'Georgia', serif;

  /* Spacing */
  --spacing-18: 4.5rem;
  --spacing-88: 22rem;

  /* Breakpoints */
  --breakpoint-tablet: 640px;
  --breakpoint-desktop: 1024px;

  /* Animations */
  --animate-fade-in: fade-in 0.3s ease-out;
  --animate-slide-up: slide-up 0.4s ease-out;
}

@keyframes fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slide-up {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
```

---

## 2. Design Tokens

### Color System
**Rule:** Use OKLCH for perceptually uniform colors

```css
@theme {
  /* Primary scale */
  --color-primary-50: oklch(0.97 0.02 250);
  --color-primary-100: oklch(0.94 0.04 250);
  --color-primary-200: oklch(0.89 0.08 250);
  --color-primary-300: oklch(0.82 0.12 250);
  --color-primary-400: oklch(0.73 0.16 250);
  --color-primary-500: oklch(0.65 0.2 250);
  --color-primary-600: oklch(0.56 0.18 250);
  --color-primary-700: oklch(0.47 0.15 250);
  --color-primary-800: oklch(0.38 0.12 250);
  --color-primary-900: oklch(0.29 0.08 250);
}
```

### Semantic Colors
**Rule:** Use semantic naming for color tokens

```css
@theme {
  /* Background */
  --color-bg-primary: var(--color-white);
  --color-bg-secondary: var(--color-gray-50);
  --color-bg-tertiary: var(--color-gray-100);

  /* Text */
  --color-text-primary: var(--color-gray-900);
  --color-text-secondary: var(--color-gray-600);
  --color-text-tertiary: var(--color-gray-400);

  /* Border */
  --color-border-default: var(--color-gray-200);
  --color-border-hover: var(--color-gray-300);

  /* Status */
  --color-success: var(--color-green-500);
  --color-warning: var(--color-amber-500);
  --color-error: var(--color-red-500);
  --color-info: var(--color-blue-500);
}
```

### Typography Scale
**Rule:** Use a consistent type scale

```css
@theme {
  /* Font sizes */
  --text-xs: 0.75rem;
  --text-xs--line-height: 1rem;

  --text-sm: 0.875rem;
  --text-sm--line-height: 1.25rem;

  --text-base: 1rem;
  --text-base--line-height: 1.5rem;

  --text-lg: 1.125rem;
  --text-lg--line-height: 1.75rem;

  --text-xl: 1.25rem;
  --text-xl--line-height: 1.75rem;

  --text-2xl: 1.5rem;
  --text-2xl--line-height: 2rem;

  --text-3xl: 1.875rem;
  --text-3xl--line-height: 2.25rem;

  /* Font weights */
  --font-weight-normal: 400;
  --font-weight-medium: 500;
  --font-weight-semibold: 600;
  --font-weight-bold: 700;

  /* Letter spacing */
  --tracking-tight: -0.025em;
  --tracking-normal: 0;
  --tracking-wide: 0.025em;
}
```

### Spacing Scale
**Rule:** Use logical spacing increments

```css
@theme {
  --spacing-0: 0;
  --spacing-px: 1px;
  --spacing-0\.5: 0.125rem;
  --spacing-1: 0.25rem;
  --spacing-2: 0.5rem;
  --spacing-3: 0.75rem;
  --spacing-4: 1rem;
  --spacing-5: 1.25rem;
  --spacing-6: 1.5rem;
  --spacing-8: 2rem;
  --spacing-10: 2.5rem;
  --spacing-12: 3rem;
  --spacing-16: 4rem;
  --spacing-20: 5rem;
  --spacing-24: 6rem;
  --spacing-32: 8rem;
  --spacing-40: 10rem;
  --spacing-48: 12rem;
  --spacing-56: 14rem;
  --spacing-64: 16rem;
}
```

---

## 3. Component Patterns

### Button Component
**Pattern:** Compound classes for variants

```css
/* Base button */
.btn {
  @apply inline-flex items-center justify-center gap-2;
  @apply px-4 py-2 rounded-lg;
  @apply font-medium text-sm;
  @apply transition-colors duration-200;
  @apply focus-visible:ring-2 focus-visible:ring-offset-2;
}

/* Variants */
.btn-primary {
  @apply bg-primary-600 text-white;
  @apply hover:bg-primary-700;
  @apply focus-visible:ring-primary-500;
}

.btn-secondary {
  @apply bg-gray-100 text-gray-900;
  @apply hover:bg-gray-200;
  @apply focus-visible:ring-gray-500;
}

.btn-danger {
  @apply bg-red-600 text-white;
  @apply hover:bg-red-700;
  @apply focus-visible:ring-red-500;
}

/* Sizes */
.btn-sm {
  @apply px-3 py-1.5 text-xs;
}

.btn-lg {
  @apply px-6 py-3 text-base;
}

/* States */
.btn:disabled {
  @apply opacity-50 cursor-not-allowed;
}

.btn-loading {
  @apply cursor-wait;
}
```

### Card Component
**Pattern:** Container with consistent padding and shadow

```css
.card {
  @apply bg-white rounded-xl border border-gray-200;
  @apply shadow-sm;
}

.card-header {
  @apply px-6 py-4 border-b border-gray-200;
}

.card-body {
  @apply px-6 py-4;
}

.card-footer {
  @apply px-6 py-4 border-t border-gray-200;
}

.card-title {
  @apply text-lg font-semibold text-gray-900;
}

.card-description {
  @apply text-sm text-gray-600 mt-1;
}
```

### Form Input
**Pattern:** Consistent form styling

```css
.input {
  @apply w-full px-3 py-2 rounded-lg;
  @apply bg-white border border-gray-300;
  @apply text-gray-900 placeholder:text-gray-400;
  @apply transition-colors duration-200;
  @apply focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500;
  @apply disabled:opacity-50 disabled:cursor-not-allowed;
}

.input-error {
  @apply border-red-500 focus:ring-red-500 focus:border-red-500;
}

.label {
  @apply block text-sm font-medium text-gray-700 mb-1;
}

.helper-text {
  @apply mt-1 text-sm text-gray-500;
}

.error-text {
  @apply mt-1 text-sm text-red-600;
}
```

### Alert/Toast
**Pattern:** Status components with icons

```css
.alert {
  @apply flex items-start gap-3 p-4 rounded-lg;
}

.alert-success {
  @apply bg-green-50 text-green-800 border border-green-200;
}

.alert-warning {
  @apply bg-amber-50 text-amber-800 border border-amber-200;
}

.alert-error {
  @apply bg-red-50 text-red-800 border border-red-200;
}

.alert-info {
  @apply bg-blue-50 text-blue-800 border border-blue-200;
}

.alert-icon {
  @apply flex-shrink-0 w-5 h-5 mt-0.5;
}

.alert-content {
  @apply flex-1;
}

.alert-title {
  @apply font-medium;
}

.alert-description {
  @apply text-sm mt-1 opacity-90;
}
```

---

## 4. Layout Patterns

### Container Queries
**Rule:** Use container queries for component-level responsive design

```css
@theme {
  --container-sm: 24rem;
  --container-md: 28rem;
  --container-lg: 32rem;
  --container-xl: 36rem;
}
```

```html
<!-- Container query example -->
<div class="@container">
  <div class="grid grid-cols-1 @md:grid-cols-2 @lg:grid-cols-3 gap-4">
    <div>Item 1</div>
    <div>Item 2</div>
    <div>Item 3</div>
  </div>
</div>
```

### Grid Patterns
**Rule:** Consistent grid gaps and column patterns

```css
/* Common grid patterns */
.grid-cards {
  @apply grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4;
}

.grid-form {
  @apply grid grid-cols-1 md:grid-cols-2 gap-6;
}

.grid-gallery {
  @apply grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2;
}
```

### Flex Patterns
**Rule:** Common flex layouts

```css
/* Stack */
.stack {
  @apply flex flex-col gap-4;
}

.stack-sm {
  @apply flex flex-col gap-2;
}

.stack-lg {
  @apply flex flex-col gap-6;
}

/* Inline */
.inline {
  @apply flex flex-wrap items-center gap-2;
}

/* Center */
.center {
  @apply flex items-center justify-center;
}

/* Between */
.between {
  @apply flex items-center justify-between;
}
```

---

## 5. Dark Mode

### CSS Variables Approach
**Rule:** Use CSS variables for theme switching

```css
:root {
  --color-bg: #ffffff;
  --color-text: #111827;
  --color-border: #e5e7eb;
}

.dark {
  --color-bg: #111827;
  --color-text: #f9fafb;
  --color-border: #374151;
}

body {
  background-color: var(--color-bg);
  color: var(--color-text);
}
```

### @media prefers-color-scheme
**Rule:** Support system preference

```css
@media (prefers-color-scheme: dark) {
  :root {
    --color-bg: #111827;
    --color-text: #f9fafb;
  }
}
```

### dark: Modifier
**Rule:** Use dark: modifier for dual themes

```tsx
<div className="
  bg-white dark:bg-gray-900
  text-gray-900 dark:text-gray-100
  border-gray-200 dark:border-gray-700
">
  Content
</div>
```

### Semantic Dark Mode
**Rule:** Use semantic tokens that switch automatically

```css
@theme {
  /* Light theme (default) */
  --color-surface: var(--color-white);
  --color-surface-elevated: var(--color-gray-50);
  --color-surface-sunken: var(--color-gray-100);
}

.dark {
  --color-surface: var(--color-gray-900);
  --color-surface-elevated: var(--color-gray-800);
  --color-surface-sunken: var(--color-gray-950);
}
```

---

## 6. Animation

### @starting-style
**Rule:** Use @starting-style for enter animations

```css
@theme {
  --animate-dialog-in: dialog-in 0.2s ease-out;
  --animate-dialog-out: dialog-out 0.15s ease-in;
}

.dialog {
  @apply opacity-100 scale-100;
  transition: opacity 0.2s, transform 0.2s;
}

@starting-style {
  .dialog {
    @apply opacity-0 scale-95;
  }
}

.dialog[data-state='closed'] {
  @apply opacity-0 scale-95;
}

@keyframes dialog-in {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
```

### View Transitions
**Rule:** Use view transitions for page changes

```css
@theme {
  --animate-fade: fade 0.3s ease;
}

::view-transition-old(root) {
  animation: fade-out 0.3s ease;
}

::view-transition-new(root) {
  animation: fade-in 0.3s ease;
}
```

### Micro-interactions
**Rule:** Subtle feedback for interactions

```css
/* Button press */
.btn {
  @apply active:scale-95 transition-transform;
}

/* Card hover */
.card {
  @apply hover:shadow-md hover:-translate-y-0.5 transition-all duration-200;
}

/* Input focus */
.input {
  @apply transition-shadow duration-200;
}

/* Link underline */
.link {
  @apply relative after:absolute after:bottom-0 after:left-0 after:w-full after:h-px after:bg-current;
  @apply after:scale-x-0 hover:after:scale-x-100 after:transition-transform;
}
```

---

## 7. Best Practices

### Avoid @apply for Simple Cases
**Rule:** Use utilities directly when possible

❌ Bad:
```css
.card {
  @apply p-4 bg-white rounded-lg;
}
```

✅ Good:
```html
<div class="p-4 bg-white rounded-lg">
  <!-- Content -->
</div>
```

✅ Better (for repeated patterns):
```css
/* Only use @apply for truly reusable components */
.btn {
  @apply px-4 py-2 bg-blue-600 text-white rounded-lg;
  @apply hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500;
}
```

### Custom Properties for Dynamic Values
**Rule:** Use CSS variables for values that change

```css
.progress-bar {
  width: var(--progress, 0%);
  transition: width 0.3s ease;
}
```

```tsx
<div className="progress-bar" style={{ '--progress': '75%' }} />
```

### Layer Organization
**Rule:** Organize styles with @layer

```css
@layer base {
  body {
    @apply antialiased text-gray-900 bg-white;
  }

  h1, h2, h3, h4, h5, h6 {
    @apply font-semibold tracking-tight;
  }
}

@layer components {
  .btn { /* ... */ }
  .card { /* ... */ }
}

@layer utilities {
  .text-balance {
    text-wrap: balance;
  }
}
```

### Import Order
**Rule:** Correct import order matters

```css
/* 1. Tailwind */
@import "tailwindcss";

/* 2. Theme configuration */
@theme {
  /* Custom tokens */
}

/* 3. Base styles */
@layer base {
  /* ... */
}

/* 4. Components */
@layer components {
  /* ... */
}

/* 5. Utilities */
@layer utilities {
  /* ... */
}
```

---

## 8. Migration from v3

### Key Changes

| v3 | v4 |
|----|-----|
| `tailwind.config.js` | `@theme` in CSS |
| `content` array | Auto-detected |
| `plugins` | CSS-based |
| `corePlugins` | `@layer` |
| `presets` | `@import` |

### Step-by-Step Migration

1. **Remove config file:**
   ```bash
   rm tailwind.config.js
   ```

2. **Update CSS file:**
   ```css
   @import "tailwindcss";

   @theme {
     /* Move your theme extensions here */
   }
   ```

3. **Update package.json:**
   ```bash
   npm install tailwindcss@next
   ```

4. **Update build scripts:**
   ```json
   {
     "scripts": {
       "build:css": "tailwindcss -i ./src/styles.css -o ./dist/styles.css"
     }
   }
   ```

---

## Quick Reference: ImgClean Patterns

### Color Tokens
```css
@theme {
  --color-brand: oklch(0.65 0.2 250);
  --color-bg: var(--color-white);
  --color-text: var(--color-gray-900);
}
```

### Component Classes
```css
.btn-primary {
  @apply px-4 py-2 bg-brand text-white rounded-lg;
  @apply hover:opacity-90 focus-visible:ring-2;
}

.card {
  @apply bg-white rounded-xl border shadow-sm;
}
```

### Dark Mode
```tsx
<div className="bg-white dark:bg-gray-900">
```

### Animation
```css
@starting-style {
  .dialog { @apply opacity-0 scale-95; }
}
```

---

## Sources

- [Tailwind CSS v4 Documentation](https://tailwindcss.com/docs/v4-beta)
- [CSS First Configuration](https://tailwindcss.com/docs/v4-beta#css-first-configuration)
- [Tailwind v4 Beta Blog Post](https://tailwindcss.com/blog/tailwindcss-v4-beta)
