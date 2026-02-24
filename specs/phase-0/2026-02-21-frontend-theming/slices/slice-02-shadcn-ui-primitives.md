# Slice 2: shadcn/ui Primitives einführen

> **Slice 2 von 5** für `Frontend-Architektur: Theming (P0.2)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-theme-token-system.md` |
> | **Nächster:** | `slice-03-component-migration.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-shadcn-ui-primitives` |
| **Test** | `pnpm test tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-theme-token-system"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests mit @testing-library/react (`.test.ts`)
- **Dependencies**: Slice 1 muss fertig sein — Theme Tokens (`--color-*`, `--radius-*`, `cn()`) sind Voraussetzung

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren.
> `frontend/package.json` enthält `next`, `vitest`, `@tailwindcss/postcss` → Stack: typescript-nextjs

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/frontend-theming/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + React 19 + Vitest v3 + @testing-library/react
- **Test Command**: Unit Tests für alle shadcn/ui Primitive (Variants, States, ARIA)
- **Integration Command**: Alle Theming-Slice-Tests zusammen
- **Acceptance Command**: `pnpm build` — stellt sicher dass keine TS-Fehler und Build-Fehler entstehen
- **Mocking Strategy**: `no_mocks` — reine Component-Tests ohne externe Services

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Theme Token System | Done | `slice-01-theme-token-system.md` |
| 2 | shadcn/ui Primitives | **Ready** | `slice-02-shadcn-ui-primitives.md` |
| 3 | Component Migration (Big Bang) | Pending | `slice-03-component-migration.md` |
| 4 | Block Registry + Page Renderer | Pending | `slice-04-block-registry.md` |
| 5 | Theme Config + Multi-Shop | Pending | `slice-05-multi-shop.md` |

---

## Kontext & Ziel

Slice 1 hat das Theme Token System aufgebaut — CSS Custom Properties (`--color-primary`, `--color-error`, `--radius-button` usw.) sind jetzt als Tailwind-Utility-Klassen verfügbar, und `cn()` aus `lib/utils.ts` ist einsatzbereit.

Dieser Slice führt shadcn/ui als Component-Library ein. Die 7 Primitives (Button, Input, Card, Badge, Skeleton, Dialog, Sheet) werden als Basis-Bausteine in `components/ui/` erstellt. Sie binden alle Theme-Tokens an — keine hardcoded Farben. Slice 3 (Component Migration) wird diese Primitives verwenden, um die 18 bestehenden Components umzustellen.

**Aktuelle Probleme:**
1. Kein `components/ui/` Verzeichnis vorhanden — keine wiederverwendbaren UI-Bausteine
2. Jede Component implementiert eigene Button-/Card-/Input-Patterns inkonsistent
3. `add-to-cart-button.tsx` nutzt `bg-green-600` (hardcoded) statt `bg-success` Token
4. `product-card.tsx` nutzt `bg-black/50`, `bg-black/70` (hardcoded) statt `bg-overlay` Token
5. shadcn/ui, CVA, @radix-ui/*, lucide-react, tw-animate-css noch nicht installiert
6. Font-Loading via `next/font` noch nicht eingerichtet

**Zielbild nach diesem Slice:**
- `frontend/components/ui/` enthält 7 vollständige shadcn/ui Primitives auf Theme-Tokens
- CVA-Variants für alle Primitives — type-safe, keine hardcoded Farben
- `next/font/google` lädt Heading- und Body-Font aus `themes/default/theme.yaml`
- `lib/theme/fonts.ts` stellt Font-Klassen für `layout.tsx` bereit
- Alle `@radix-ui/*` Packages installiert (Dialog, Slot)
- `tw-animate-css` als CSS-Animation-Library installiert (ersetzt `tailwindcss-animate`)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Architecture Layers, Integrations

```
Primitive Layer: Reusable UI components (Button, Card, Input, etc.)
  → shadcn/ui + CVA variants
  → Alle Primitives nutzen CSS Custom Properties aus @theme Block
  → cn() Utility (aus Slice 1) für conditional + conflict-free class merging
  → @radix-ui/* für Accessibility (Dialog, Slot)
  → lucide-react für Icons
  → tw-animate-css für Animationen (Dialog open/close, Sheet slide-in)
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/components/ui/` | NEU: button.tsx, input.tsx, card.tsx, badge.tsx, skeleton.tsx, dialog.tsx, sheet.tsx |
| `frontend/lib/theme/fonts.ts` | NEU: next/font Instanzen (heading + body Font aus theme.yaml) |
| `frontend/app/layout.tsx` | GEÄNDERT: Font-Klassen anwenden (className auf `<html>` oder `<body>`) |
| `frontend/app/globals.css` | GEÄNDERT: `tw-animate-css` importieren, Font-CSS-Variable in `@theme` registrieren |
| `frontend/package.json` | GEÄNDERT: shadcn/ui Deps hinzufügen (CVA, @radix-ui/*, lucide-react, tw-animate-css) |

### 2. Datenfluss

```
theme.yaml (fonts.heading, fonts.body)
  ↓ (wird von fonts.ts konsumiert — statisch, build-time)
lib/theme/fonts.ts (Inter via next/font/google — statische Imports)
  ↓ .className Eigenschaft
app/layout.tsx (className auf <html>)
  ↓ CSS Variable --font-heading / --font-body in globals.css @theme
components/ui/*.tsx
  ↓ cn() + CVA Variants (Tailwind-Klassen aus @theme Tokens)
Fertig gerenderte HTML-Elemente mit Theme-Farben + Fonts
```

### 3. Package-Dependencies

| Paket | Version | Zweck | Install-Typ |
|-------|---------|-------|-------------|
| `class-variance-authority` | `^0.7.0` | CVA für type-safe Variants | dependency |
| `@radix-ui/react-dialog` | latest | Headless Dialog (ARIA) | dependency |
| `@radix-ui/react-slot` | latest | `asChild` Pattern für Button | dependency |
| `lucide-react` | `^0.575.0` | Icon-Library | dependency |
| `tw-animate-css` | latest | CSS Animations (ersetzt tailwindcss-animate) | dependency |

**Hinweis:** `clsx` und `tailwind-merge` wurden bereits in Slice 1 installiert. Sie müssen nicht nochmals installiert werden.

### 4. Font-Loading via next/font

`next/font/google` benötigt **statische Imports** — die Font-Namen können nicht dynamisch aus YAML gelesen werden. Das Build-Script aus Slice 1 liefert die Font-Namen, aber `fonts.ts` muss sie als statische Import-Calls enthalten.

Für den Default-Theme mit "Inter" als heading und body Font:

```typescript
// frontend/lib/theme/fonts.ts
import { Inter } from 'next/font/google'

export const headingFont = Inter({
  subsets: ['latin'],
  variable: '--font-heading',
  display: 'swap',
  weight: ['400', '500', '600', '700'],
})

export const bodyFont = Inter({
  subsets: ['latin'],
  variable: '--font-body',
  display: 'swap',
  weight: ['400', '500'],
})
```

**Hinweis:** In Slice 5 (Multi-Shop) wird das Build-Script `fonts.ts` automatisch aus `theme.yaml` generieren, um verschiedene Fonts pro Shop zu unterstützen. Für Slice 2 wird "Inter" hardcoded, da der Default-Theme Inter nutzt.

### 5. globals.css Erweiterung

Die tw-animate-css Library und Font-CSS-Variables müssen in `globals.css` registriert werden. Die vollständige Dateistruktur nach Slice 1 und Slice 2 gemeinsam sieht wie folgt aus (Import-Reihenfolge ist verbindlich):

```css
/* frontend/app/globals.css — vollständige Import-Reihenfolge nach Slice 1 + Slice 2 */

/* 1. generated-theme.css MUSS vor tailwindcss stehen (Slice 1 Vorgabe) */
@import "./generated-theme.css";

/* 2. Tailwind CSS v4 Core */
@import "tailwindcss";

/* 3. tw-animate-css MUSS nach tailwindcss stehen (NEU in Slice 2) */
@import "tw-animate-css";

@theme {
  /* Bereits aus Slice 1 vorhanden: --color-*, --radius-*, --shadow-*, --min-touch-target, etc. */

  /* NEU in Slice 2: Font-CSS-Variables (für next/font Integration) */
  /* next/font setzt --font-heading / --font-body via variable-Prop auf <html>.
     Der @theme Block macht sie als Tailwind-Utility-Klassen verfügbar (font-heading, font-body).
     Das Muster --font-heading: var(--font-heading) ist in Tailwind v4 @theme korrekt:
     der @theme-Namespace und der Custom-Property-Namespace sind getrennt. */
  --font-heading: var(--font-heading);
  --font-body: var(--font-body);
}
```

**Hinweis zur Import-Reihenfolge:**
- `@import "./generated-theme.css"` — ZUERST (Slice 1 hat diese Reihenfolge festgelegt)
- `@import "tailwindcss"` — ZWEITE Position (Tailwind Core)
- `@import "tw-animate-css"` — NACH tailwindcss (tw-animate-css nutzt Tailwind-Klassen, muss nach dem Core kommen)

**Hinweis zu `--font-heading: var(--font-heading)`:** `next/font` setzt die CSS Custom Property `--font-heading` auf dem `<html>` Element (via `variable: '--font-heading'` in `fonts.ts`). Der `@theme` Block registriert dieselbe Variable im Tailwind-Namespace, sodass `font-heading` als Utility-Klasse verfügbar wird. In Tailwind v4 ist dieses Muster korrekt — `@theme` Properties und reguläre CSS Custom Properties sind verschiedene Namespaces.

### 6. Button Component

Button nutzt CVA für 5 Variants + Größen-Varianten. `asChild` Pattern via `@radix-ui/react-slot` erlaubt `<Button asChild><Link href="...">...</Link></Button>`.

```typescript
// frontend/components/ui/button.tsx
import * as React from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const buttonVariants = cva(
  // Base styles — gelten für alle Variants
  [
    'inline-flex items-center justify-center gap-2',
    'font-semibold text-sm',
    'rounded-[var(--radius-button,0.5rem)]',
    'transition-all duration-200',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary',
    'disabled:pointer-events-none disabled:opacity-50',
    '[touch-action:manipulation]',
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-white hover:bg-primary-hover shadow-sm hover:shadow-md',
        destructive: 'bg-error text-white hover:opacity-90 focus-visible:ring-error',
        outline: 'border border-border bg-transparent text-text-primary hover:bg-surface-elevated',
        ghost: 'bg-transparent text-text-primary hover:bg-surface-elevated',
        link: 'bg-transparent text-primary underline-offset-4 hover:underline p-0 h-auto',
      },
      size: {
        default: 'h-[var(--min-touch-target,2.75rem)] px-5 py-2',
        sm: 'h-8 px-3 py-1 text-xs',
        lg: 'h-12 px-8 py-3 text-base',
        icon: 'h-[var(--min-touch-target,2.75rem)] w-[var(--min-touch-target,2.75rem)]',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
  isLoading?: boolean
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, isLoading = false, children, disabled, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button'
    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        disabled={disabled || isLoading}
        aria-disabled={disabled || isLoading}
        data-slot="button"
        {...props}
      >
        {isLoading ? (
          <>
            <svg
              aria-hidden="true"
              className="animate-spin h-4 w-4"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
              />
            </svg>
            <span>{children}</span>
          </>
        ) : (
          children
        )}
      </Comp>
    )
  }
)
Button.displayName = 'Button'

export { Button, buttonVariants }
```

### 7. Input Component

Input unterstützt 2 Variants (default, error). Nutzt `data-slot="input"` für shadcn/ui Kompatibilität.

```typescript
// frontend/components/ui/input.tsx
import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const inputVariants = cva(
  [
    'flex w-full',
    'h-[var(--min-touch-target,2.75rem)] px-3 py-2',
    'rounded-[var(--radius-button,0.5rem)]',
    'border bg-surface',
    'text-sm text-text-primary',
    'placeholder:text-text-secondary',
    'transition-colors duration-200',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-0 focus-visible:ring-primary focus-visible:border-primary',
    'disabled:pointer-events-none disabled:opacity-50 disabled:cursor-not-allowed',
    'file:border-0 file:bg-transparent file:text-sm file:font-medium',
  ],
  {
    variants: {
      variant: {
        default: 'border-border',
        error: 'border-error focus-visible:ring-error',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface InputProps
  extends React.InputHTMLAttributes<HTMLInputElement>,
    VariantProps<typeof inputVariants> {
  errorMessage?: string
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, variant, type, errorMessage, id, ...props }, ref) => {
    const inputId = id
    const errorId = errorMessage && inputId ? `${inputId}-error` : undefined

    return (
      <div className="w-full" data-slot="input-wrapper">
        <input
          type={type}
          className={cn(inputVariants({ variant, className }))}
          ref={ref}
          id={inputId}
          aria-invalid={variant === 'error' || !!errorMessage}
          aria-describedby={errorId}
          data-slot="input"
          style={{ touchAction: 'manipulation' }}
          {...props}
        />
        {errorMessage && (
          <p
            id={errorId}
            className="mt-1 text-xs text-error"
            role="alert"
            data-slot="input-error"
          >
            {errorMessage}
          </p>
        )}
      </div>
    )
  }
)
Input.displayName = 'Input'

export { Input, inputVariants }
```

### 8. Card Component

Card hat 2 Variants: `default` (statisch) und `interactive` (hover-Effekt für klickbare Cards). Nutzt compound components (CardHeader, CardTitle, CardDescription, CardContent, CardFooter).

```typescript
// frontend/components/ui/card.tsx
import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const cardVariants = cva(
  [
    'rounded-[var(--radius-card,0.75rem)]',
    'border border-border',
    'bg-surface',
    'shadow-[var(--shadow-card)]',
    'overflow-hidden',
  ],
  {
    variants: {
      variant: {
        default: '',
        interactive: [
          'cursor-pointer',
          'transition-all duration-200',
          'hover:shadow-[var(--shadow-card-hover)]',
          'hover:-translate-y-0.5',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
        ],
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface CardProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof cardVariants> {}

const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, variant, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(cardVariants({ variant, className }))}
      data-slot="card"
      {...props}
    />
  )
)
Card.displayName = 'Card'

const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('flex flex-col gap-1.5 p-6', className)}
      data-slot="card-header"
      {...props}
    />
  )
)
CardHeader.displayName = 'CardHeader'

const CardTitle = React.forwardRef<HTMLHeadingElement, React.HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => (
    <h3
      ref={ref}
      className={cn('text-lg font-semibold text-text-primary leading-snug', className)}
      data-slot="card-title"
      {...props}
    />
  )
)
CardTitle.displayName = 'CardTitle'

const CardDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(
  ({ className, ...props }, ref) => (
    <p
      ref={ref}
      className={cn('text-sm text-text-secondary', className)}
      data-slot="card-description"
      {...props}
    />
  )
)
CardDescription.displayName = 'CardDescription'

const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('p-6 pt-0', className)}
      data-slot="card-content"
      {...props}
    />
  )
)
CardContent.displayName = 'CardContent'

const CardFooter = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('flex items-center p-6 pt-0', className)}
      data-slot="card-footer"
      {...props}
    />
  )
)
CardFooter.displayName = 'CardFooter'

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter, cardVariants }
```

### 9. Badge Component

Badge zeigt 4 Variants: default (primary), secondary, destructive (error), outline.

```typescript
// frontend/components/ui/badge.tsx
import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
  [
    'inline-flex items-center justify-center',
    'rounded-full px-2.5 py-0.5',
    'text-xs font-medium',
    'transition-colors duration-200',
    'border',
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-white border-transparent',
        secondary: 'bg-surface-elevated text-text-secondary border-border',
        destructive: 'bg-error text-white border-transparent',
        outline: 'bg-transparent text-text-primary border-border',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <span
      className={cn(badgeVariants({ variant, className }))}
      data-slot="badge"
      {...props}
    />
  )
}

export { Badge, badgeVariants }
```

### 10. Skeleton Component

Skeleton zeigt einen Platzhalter-Block mit Pulse-Animation. Nutzt `bg-surface-elevated` als Token.

```typescript
// frontend/components/ui/skeleton.tsx
import * as React from 'react'
import { cn } from '@/lib/utils'

export interface SkeletonProps extends React.HTMLAttributes<HTMLDivElement> {}

function Skeleton({ className, ...props }: SkeletonProps) {
  return (
    <div
      className={cn('animate-pulse rounded-md bg-surface-elevated', className)}
      aria-label="Wird geladen..."
      aria-busy="true"
      data-slot="skeleton"
      {...props}
    />
  )
}

export { Skeleton }
```

### 11. Dialog Component

Dialog nutzt `@radix-ui/react-dialog` für Accessibility (ARIA roles, Keyboard-Nav, Focus-Trap). Animationen via `tw-animate-css`.

```typescript
// frontend/components/ui/dialog.tsx
'use client'

import * as React from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { cn } from '@/lib/utils'

const Dialog = DialogPrimitive.Root
const DialogTrigger = DialogPrimitive.Trigger
const DialogPortal = DialogPrimitive.Portal
const DialogClose = DialogPrimitive.Close

const DialogOverlay = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-50 bg-overlay',
      'data-[state=open]:animate-in data-[state=closed]:animate-out',
      'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
      className
    )}
    data-slot="dialog-overlay"
    {...props}
  />
))
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName

const DialogContent = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
  <DialogPortal>
    <DialogOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        'fixed left-[50%] top-[50%] z-50 translate-x-[-50%] translate-y-[-50%]',
        'w-full max-w-lg',
        'bg-surface rounded-[var(--radius-card,0.75rem)] shadow-[var(--shadow-card-hover)]',
        'p-6',
        'data-[state=open]:animate-in data-[state=closed]:animate-out',
        'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
        'data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%]',
        'data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%]',
        'duration-200',
        className
      )}
      data-slot="dialog-content"
      {...props}
    >
      {children}
      <DialogPrimitive.Close
        className={cn(
          'absolute right-4 top-4',
          'h-[var(--min-touch-target,2.75rem)] w-[var(--min-touch-target,2.75rem)]',
          'flex items-center justify-center',
          'rounded-[var(--radius-button,0.5rem)]',
          'text-text-secondary',
          'hover:bg-surface-elevated hover:text-text-primary',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
          'transition-colors duration-200',
          'disabled:pointer-events-none'
        )}
        aria-label="Dialog schließen"
        data-slot="dialog-close"
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </DialogPrimitive.Close>
    </DialogPrimitive.Content>
  </DialogPortal>
))
DialogContent.displayName = DialogPrimitive.Content.displayName

const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn('flex flex-col gap-1.5 text-center sm:text-left mb-4', className)}
    data-slot="dialog-header"
    {...props}
  />
)
DialogHeader.displayName = 'DialogHeader'

const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn('flex flex-col-reverse sm:flex-row sm:justify-end gap-2 mt-4', className)}
    data-slot="dialog-footer"
    {...props}
  />
)
DialogFooter.displayName = 'DialogFooter'

const DialogTitle = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn('text-lg font-semibold text-text-primary leading-snug', className)}
    data-slot="dialog-title"
    {...props}
  />
))
DialogTitle.displayName = DialogPrimitive.Title.displayName

const DialogDescription = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description
    ref={ref}
    className={cn('text-sm text-text-secondary', className)}
    data-slot="dialog-description"
    {...props}
  />
))
DialogDescription.displayName = DialogPrimitive.Description.displayName

export {
  Dialog,
  DialogTrigger,
  DialogPortal,
  DialogClose,
  DialogOverlay,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
}
```

### 12. Sheet Component

Sheet ist ein Drawer von einer der 4 Seiten (top, right, bottom, left). Nutzt ebenfalls `@radix-ui/react-dialog` intern — shadcn/ui Pattern.

```typescript
// frontend/components/ui/sheet.tsx
'use client'

import * as React from 'react'
import * as SheetPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const Sheet = SheetPrimitive.Root
const SheetTrigger = SheetPrimitive.Trigger
const SheetClose = SheetPrimitive.Close
const SheetPortal = SheetPrimitive.Portal

const SheetOverlay = React.forwardRef<
  React.ComponentRef<typeof SheetPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-50 bg-overlay',
      'data-[state=open]:animate-in data-[state=closed]:animate-out',
      'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
      className
    )}
    data-slot="sheet-overlay"
    {...props}
  />
))
SheetOverlay.displayName = SheetPrimitive.Overlay.displayName

const sheetVariants = cva(
  [
    'fixed z-50',
    'bg-surface',
    'shadow-[var(--shadow-card-hover)]',
    'transition ease-in-out',
    'data-[state=open]:animate-in data-[state=closed]:animate-out',
    'data-[state=closed]:duration-300 data-[state=open]:duration-500',
  ],
  {
    variants: {
      side: {
        top: 'inset-x-0 top-0 border-b border-border data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top',
        bottom: 'inset-x-0 bottom-0 border-t border-border data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom',
        left: 'inset-y-0 left-0 h-full w-3/4 border-r border-border sm:max-w-sm data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left',
        right: 'inset-y-0 right-0 h-full w-3/4 border-l border-border sm:max-w-sm data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right',
      },
    },
    defaultVariants: {
      side: 'right',
    },
  }
)

interface SheetContentProps
  extends React.ComponentPropsWithoutRef<typeof SheetPrimitive.Content>,
    VariantProps<typeof sheetVariants> {}

const SheetContent = React.forwardRef<
  React.ComponentRef<typeof SheetPrimitive.Content>,
  SheetContentProps
>(({ side = 'right', className, children, ...props }, ref) => (
  <SheetPortal>
    <SheetOverlay />
    <SheetPrimitive.Content
      ref={ref}
      className={cn(sheetVariants({ side }), className)}
      data-slot="sheet-content"
      {...props}
    >
      <SheetPrimitive.Close
        className={cn(
          'absolute right-4 top-4',
          'h-[var(--min-touch-target,2.75rem)] w-[var(--min-touch-target,2.75rem)]',
          'flex items-center justify-center',
          'rounded-[var(--radius-button,0.5rem)]',
          'text-text-secondary',
          'hover:bg-surface-elevated hover:text-text-primary',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
          'transition-colors duration-200',
          'disabled:pointer-events-none'
        )}
        aria-label="Panel schließen"
        data-slot="sheet-close"
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </SheetPrimitive.Close>
      {children}
    </SheetPrimitive.Content>
  </SheetPortal>
))
SheetContent.displayName = SheetPrimitive.Content.displayName

const SheetHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn('flex flex-col gap-1.5 p-6', className)}
    data-slot="sheet-header"
    {...props}
  />
)
SheetHeader.displayName = 'SheetHeader'

const SheetFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn('flex flex-col-reverse sm:flex-row sm:justify-end gap-2 p-6 pt-0', className)}
    data-slot="sheet-footer"
    {...props}
  />
)
SheetFooter.displayName = 'SheetFooter'

const SheetTitle = React.forwardRef<
  React.ComponentRef<typeof SheetPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Title>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Title
    ref={ref}
    className={cn('text-lg font-semibold text-text-primary', className)}
    data-slot="sheet-title"
    {...props}
  />
))
SheetTitle.displayName = SheetPrimitive.Title.displayName

const SheetDescription = React.forwardRef<
  React.ComponentRef<typeof SheetPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Description>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Description
    ref={ref}
    className={cn('text-sm text-text-secondary', className)}
    data-slot="sheet-description"
    {...props}
  />
))
SheetDescription.displayName = SheetPrimitive.Description.displayName

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
}
```

### 13. layout.tsx Anpassung (Font-Klassen)

```typescript
// frontend/app/layout.tsx — GEÄNDERT: Font-Klassen hinzufügen
import { headingFont, bodyFont } from '@/lib/theme/fonts'

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="de" className={`${headingFont.variable} ${bodyFont.variable}`}>
      <body>
        {/* ... bestehender Inhalt ... */}
      </body>
    </html>
  )
}
```

### 14. package.json Neue Dependencies

```json
{
  "dependencies": {
    "class-variance-authority": "^0.7.0",
    "@radix-ui/react-dialog": "latest",
    "@radix-ui/react-slot": "latest",
    "lucide-react": "^0.575.0",
    "tw-animate-css": "latest"
  }
}
```

---

## UI Anforderungen

> **Hinweis:** Keine neuen UI-Screens in diesem Slice — Primitives sind Bausteine, keine Seiten.

### 1. Button — Alle Varianten und States

**Verhalten:**
- `default`: Primärfarbe aus `--color-primary`, Hover nutzt `--color-primary-hover`
- `destructive`: Fehlerfarbe `--color-error` für Lösch-Aktionen
- `outline`: Transparenter Hintergrund, sichtbarer Rand `--color-border`
- `ghost`: Kein Hintergrund, Hover zeigt `--color-surface-elevated`
- `link`: Nur Text + Unterstrich bei Hover, keine Mindesthöhe
- `isLoading=true`: Spinner-Icon + Disabled-State, kein Pointer-Cursor

**Zustände:**
- Default: Normal-Darstellung
- Hover: Dunklerer Hintergrund / Unterstrich
- Focus-visible: `ring-2 ring-primary` (nur bei Keyboard-Navigation)
- Disabled: `opacity-50`, kein Cursor
- Loading: Spinner + `opacity-50` + kein Cursor

**Accessibility:**
- `focus-visible:ring-2` — nur bei Keyboard-Navigation sichtbar
- `aria-disabled` gespiegelt vom `disabled` Prop
- `[touch-action:manipulation]` — verhindert Doppeltap-Zoom auf Mobile (Tailwind v4 Arbitrary-Property in CVA base styles)
- Mindest-Touchfläche: `h-[var(--min-touch-target,2.75rem)]` (44px)
- `asChild` Pattern: `<Button asChild><Link>...</Link></Button>` für semantisch korrekte Links

### 2. Input — Varianten und Zustände

**Verhalten:**
- `default`: Normaler Eingabefeld-Rand `--color-border`
- `error`: Roter Rand `--color-error`, Focus-Ring ebenfalls rot
- `errorMessage` Prop: Zeigt Fehlermeldung unter dem Input mit `role="alert"`, `id` für `aria-describedby`

**Zustände:**
- Default: Normaler Rand
- Focus: `ring-2 ring-primary border-primary`
- Disabled: `opacity-50`, kein Cursor
- Error: `border-error ring-error`, Fehlermeldung sichtbar

**Accessibility:**
- `aria-invalid` gesetzt wenn `variant="error"` oder `errorMessage` vorhanden
- `aria-describedby` zeigt auf Fehlermeldungs-Element
- `role="alert"` auf Fehlermeldung für Screen-Reader

### 3. Card — Varianten

**Verhalten:**
- `default`: Statische Card, kein Hover-Effekt
- `interactive`: Cursor `pointer`, `hover:shadow-[var(--shadow-card-hover)]`, leichtes `translate-y-0.5` Lift

**Compound Components:** `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`, `CardFooter`

### 4. Badge — Varianten

**Verhalten:**
- `default`: Primary-Farbe, weiße Schrift
- `secondary`: Surface-Elevated Hintergrund, sekundäre Textfarbe
- `destructive`: Error-Farbe, weiße Schrift
- `outline`: Transparenter Hintergrund, Rand und primäre Textfarbe

### 5. Skeleton

**Verhalten:** Pulse-Animation via `animate-pulse`. Größe über `className` steuerbar (z.B. `className="h-4 w-3/4"`). Bestehender `ProductCardSkeleton` in Slice 3 auf `<Skeleton>` migrieren.

### 6. Dialog

**Verhalten:**
- Öffnet zentriert im Viewport
- Overlay `bg-overlay` (halbtransparent)
- Close-Button (X) oben rechts, `aria-label="Dialog schließen"`
- Keyboard: ESC schließt, Tab trapped innerhalb Dialog
- Animation: fade-in/zoom-in beim Öffnen, fade-out/zoom-out beim Schließen (tw-animate-css)
- `overscroll-behavior: contain` verhindert Scroll-Durchfall

### 7. Sheet

**Verhalten:**
- Slide-in von rechts (default) oder einer der 4 Seiten
- Overlay `bg-overlay`
- Close-Button (X), `aria-label="Panel schließen"`
- Keyboard: ESC schließt, Focus trapped
- `left` Side: Wird in `mobile-menu.tsx` (Slice 3) verwendet

**Accessibility (alle Primitives):**
- `focus-visible:ring-2 focus-visible:ring-primary` auf allen interaktiven Elementen
- Icon-only Buttons haben `aria-label`
- Dekorative Icons haben `aria-hidden="true"`
- ARIA-Rollen werden von Radix UI automatisch gesetzt

---

## Acceptance Criteria

1) GIVEN shadcn/ui Primitives sind installiert
   WHEN `<Button variant="default">Text</Button>` gerendert wird
   THEN hat der Button `bg-primary text-white`, Mindesthöhe 2.75rem (44px) und `touch-action: manipulation`

2) GIVEN ein Button mit `variant="destructive"`
   WHEN der Button gerendert wird
   THEN nutzt er `bg-error` (nicht `bg-red-*`) und zeigt `ring-error` bei Focus-visible

3) GIVEN ein Button mit `isLoading={true}`
   WHEN der Button gerendert wird
   THEN ist ein Spinner-Icon sichtbar, der Button ist `disabled` und hat `aria-disabled="true"`

4) GIVEN ein `<Input variant="error" errorMessage="Pflichtfeld" id="email" />`
   WHEN das Element gerendert wird
   THEN ist `aria-invalid="true"` gesetzt, eine Fehlermeldung mit `role="alert"` sichtbar, und `aria-describedby` zeigt auf die Fehlermeldung

5) GIVEN eine `<Card variant="interactive">`
   WHEN über die Card gehovt wird
   THEN ändern sich Schatten (shadow-card-hover) und die Card hebt sich minimal an (`-translate-y-0.5`)

6) GIVEN ein `<Badge variant="secondary">`
   WHEN das Badge gerendert wird
   THEN nutzt es `bg-surface-elevated` und `text-text-secondary` — keine hardcoded Tailwind-Farben

7) GIVEN `<Dialog>` ist geschlossen
   WHEN `DialogTrigger` geklickt wird
   THEN öffnet der Dialog mit fade-in und zoom-in Animation, ein Overlay erscheint, und der Fokus wechselt in den Dialog

8) GIVEN ein offener `<Dialog>`
   WHEN ESC gedrückt wird
   THEN schließt der Dialog mit fade-out Animation und der Fokus kehrt zum Trigger zurück

9) GIVEN ein `<Sheet side="right">`
   WHEN `SheetTrigger` aktiviert wird
   THEN gleitet das Sheet von rechts herein mit slide-in-from-right Animation

10) GIVEN alle shadcn/ui Primitives
    WHEN `pnpm build` ausgeführt wird
    THEN schlägt der Build nicht fehl (keine TS-Fehler, keine Import-Fehler)

11) GIVEN `lib/theme/fonts.ts` existiert
    WHEN `layout.tsx` gerendert wird
    THEN sind `--font-heading` und `--font-body` CSS Custom Properties auf `<html>` gesetzt

12) GIVEN ein Button mit `variant="ghost"`
    WHEN der Button per Tab-Taste fokussiert wird
    THEN ist ein sichtbarer Focus-Ring (`ring-2 ring-primary`) vorhanden (focus-visible, nicht bei Mausklick)

---

## Testfälle

### Test-Datei

`tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts`

<test_spec>
```typescript
// tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { existsSync, readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '../../../frontend')

// ─── Datei-Existenz Tests ────────────────────────────────────────────────────

describe('shadcn/ui Primitives — Dateien existieren', () => {
  const uiFiles = [
    'components/ui/button.tsx',
    'components/ui/input.tsx',
    'components/ui/card.tsx',
    'components/ui/badge.tsx',
    'components/ui/skeleton.tsx',
    'components/ui/dialog.tsx',
    'components/ui/sheet.tsx',
  ]

  for (const file of uiFiles) {
    it(`should have ${file}`, () => {
      expect(existsSync(resolve(FRONTEND_ROOT, file))).toBe(true)
    })
  }

  it('should have lib/theme/fonts.ts', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'lib/theme/fonts.ts'))).toBe(true)
  })
})

// ─── Button Tests ────────────────────────────────────────────────────────────

describe('Button Component', () => {
  it('should render with default variant', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button>Klick mich</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn).toBeTruthy()
    expect(btn?.textContent).toBe('Klick mich')
  })

  it('should apply default variant classes (bg-primary)', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button variant="default">Test</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-primary')
  })

  it('should apply destructive variant (bg-error)', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button variant="destructive">Löschen</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-error')
  })

  it('should apply outline variant (border-border)', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button variant="outline">Outline</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('border-border')
  })

  it('should apply ghost variant', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button variant="ghost">Ghost</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-transparent')
  })

  it('should be disabled when isLoading=true', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button isLoading>Laden…</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn).toHaveAttribute('disabled')
    expect(btn).toHaveAttribute('aria-disabled', 'true')
  })

  it('should render spinner when isLoading=true', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button isLoading>Laden…</Button>)
    const spinner = container.querySelector('svg')
    expect(spinner).toBeTruthy()
    expect(spinner).toHaveAttribute('aria-hidden', 'true')
  })

  it('should have min touch target height class', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button>Touch</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    // h-[var(--min-touch-target,2.75rem)] in className
    expect(btn?.className).toContain('h-[var(--min-touch-target')
  })

  it('should have [touch-action:manipulation] CVA class', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button>Mobile</Button>)
    const btn = container.querySelector('[data-slot="button"]') as HTMLElement
    // [touch-action:manipulation] ist eine Tailwind v4 Arbitrary-Property-Klasse in CVA base styles.
    // Sie erzeugt touch-action: manipulation im CSS-Output (kein inline style noetig).
    expect(btn?.className).toContain('[touch-action:manipulation]')
  })

  it('should render as Slot with asChild=true', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(
      <Button asChild>
        <a href="/test">Link Button</a>
      </Button>
    )
    // asChild rendert das Kind-Element (a), nicht button
    const anchor = container.querySelector('a')
    expect(anchor).toBeTruthy()
    expect(anchor?.textContent).toBe('Link Button')
  })

  it('should have focus-visible ring class', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const { container } = render(<Button>Focus</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('focus-visible:ring-2')
  })

  it('should NOT use hardcoded Tailwind color classes (red-*, green-*)', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const variants = ['default', 'destructive', 'outline', 'ghost', 'link'] as const
    for (const variant of variants) {
      const { container } = render(<Button variant={variant}>Test</Button>)
      const btn = container.querySelector('[data-slot="button"]')
      expect(btn?.className).not.toMatch(/\b(red|green|blue|yellow|purple|pink|orange)-\d{3}\b/)
    }
  })
})

// ─── Input Tests ─────────────────────────────────────────────────────────────

describe('Input Component', () => {
  it('should render with default variant', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(<Input placeholder="Test" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input).toBeTruthy()
    expect(input?.className).toContain('border-border')
  })

  it('should apply error variant class (border-error)', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(<Input variant="error" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input?.className).toContain('border-error')
  })

  it('should have aria-invalid=true with error variant', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(<Input variant="error" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('should show errorMessage and have aria-describedby', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(
      <Input id="email" variant="error" errorMessage="Pflichtfeld" />
    )
    const errorEl = container.querySelector('[data-slot="input-error"]')
    expect(errorEl).toBeTruthy()
    expect(errorEl?.textContent).toBe('Pflichtfeld')
    expect(errorEl).toHaveAttribute('role', 'alert')

    const input = container.querySelector('[data-slot="input"]')
    expect(input).toHaveAttribute('aria-describedby', 'email-error')
  })

  it('should have min touch target height', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(<Input />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input?.className).toContain('h-[var(--min-touch-target')
  })
})

// ─── Card Tests ──────────────────────────────────────────────────────────────

describe('Card Component', () => {
  it('should render default variant with shadow-card token', async () => {
    const { Card } = await import('../../../frontend/components/ui/card')
    const { container } = render(<Card>Inhalt</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card).toBeTruthy()
    expect(card?.className).toContain('shadow-[var(--shadow-card)]')
  })

  it('should render interactive variant with hover classes', async () => {
    const { Card } = await import('../../../frontend/components/ui/card')
    const { container } = render(<Card variant="interactive">Interaktiv</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card?.className).toContain('cursor-pointer')
    expect(card?.className).toContain('hover:shadow-[var(--shadow-card-hover)]')
  })

  it('should render Card compound components', async () => {
    const { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } =
      await import('../../../frontend/components/ui/card')
    const { container } = render(
      <Card>
        <CardHeader>
          <CardTitle>Titel</CardTitle>
          <CardDescription>Beschreibung</CardDescription>
        </CardHeader>
        <CardContent>Inhalt</CardContent>
        <CardFooter>Footer</CardFooter>
      </Card>
    )
    expect(container.querySelector('[data-slot="card-header"]')).toBeTruthy()
    expect(container.querySelector('[data-slot="card-title"]')?.textContent).toBe('Titel')
    expect(container.querySelector('[data-slot="card-description"]')?.textContent).toBe('Beschreibung')
    expect(container.querySelector('[data-slot="card-content"]')?.textContent).toBe('Inhalt')
    expect(container.querySelector('[data-slot="card-footer"]')?.textContent).toBe('Footer')
  })

  it('should use --radius-card token', async () => {
    const { Card } = await import('../../../frontend/components/ui/card')
    const { container } = render(<Card>Test</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card?.className).toContain('rounded-[var(--radius-card')
  })
})

// ─── Badge Tests ─────────────────────────────────────────────────────────────

describe('Badge Component', () => {
  it('should render default variant (bg-primary)', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const { container } = render(<Badge>Neu</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-primary')
    expect(badge?.className).toContain('text-white')
  })

  it('should render secondary variant (bg-surface-elevated)', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const { container } = render(<Badge variant="secondary">Sekundär</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-surface-elevated')
  })

  it('should render destructive variant (bg-error)', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const { container } = render(<Badge variant="destructive">Fehler</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-error')
  })

  it('should render outline variant (bg-transparent)', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const { container } = render(<Badge variant="outline">Outline</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-transparent')
    expect(badge?.className).toContain('border-border')
  })

  it('should NOT use hardcoded Tailwind color classes', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const variants = ['default', 'secondary', 'destructive', 'outline'] as const
    for (const variant of variants) {
      const { container } = render(<Badge variant={variant}>Test</Badge>)
      const badge = container.querySelector('[data-slot="badge"]')
      expect(badge?.className).not.toMatch(/\b(red|green|blue|yellow|purple|pink|orange)-\d{3}\b/)
    }
  })
})

// ─── Skeleton Tests ──────────────────────────────────────────────────────────

describe('Skeleton Component', () => {
  it('should render with animate-pulse and bg-surface-elevated', async () => {
    const { Skeleton } = await import('../../../frontend/components/ui/skeleton')
    const { container } = render(<Skeleton className="h-4 w-3/4" />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton).toBeTruthy()
    expect(skeleton?.className).toContain('animate-pulse')
    expect(skeleton?.className).toContain('bg-surface-elevated')
  })

  it('should have aria-busy="true" and aria-label for screen readers', async () => {
    const { Skeleton } = await import('../../../frontend/components/ui/skeleton')
    const { container } = render(<Skeleton />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton).toHaveAttribute('aria-busy', 'true')
    expect(skeleton).toHaveAttribute('aria-label', 'Wird geladen...')
  })

  it('should accept custom className for sizing', async () => {
    const { Skeleton } = await import('../../../frontend/components/ui/skeleton')
    const { container } = render(<Skeleton className="h-12 w-full" />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton?.className).toContain('h-12')
    expect(skeleton?.className).toContain('w-full')
  })
})

// ─── Dialog Tests ────────────────────────────────────────────────────────────

describe('Dialog Component', () => {
  it('should render Dialog components without errors', async () => {
    const { Dialog, DialogTrigger, DialogContent, DialogHeader, DialogTitle, DialogDescription } =
      await import('../../../frontend/components/ui/dialog')
    expect(() =>
      render(
        <Dialog>
          <DialogTrigger>Öffnen</DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Titel</DialogTitle>
              <DialogDescription>Beschreibung</DialogDescription>
            </DialogHeader>
          </DialogContent>
        </Dialog>
      )
    ).not.toThrow()
  })

  it('should have close button with aria-label="Dialog schließen"', async () => {
    const { Dialog, DialogTrigger, DialogContent } = await import('../../../frontend/components/ui/dialog')
    const { container } = render(
      <Dialog open>
        <DialogTrigger>Öffnen</DialogTrigger>
        <DialogContent>Inhalt</DialogContent>
      </Dialog>
    )
    const closeBtn = container.querySelector('[data-slot="dialog-close"]')
    expect(closeBtn).toHaveAttribute('aria-label', 'Dialog schließen')
  })
})

// ─── Sheet Tests ─────────────────────────────────────────────────────────────

describe('Sheet Component', () => {
  it('should render Sheet components without errors', async () => {
    const { Sheet, SheetTrigger, SheetContent } = await import('../../../frontend/components/ui/sheet')
    expect(() =>
      render(
        <Sheet>
          <SheetTrigger>Öffnen</SheetTrigger>
          <SheetContent side="right">Inhalt</SheetContent>
        </Sheet>
      )
    ).not.toThrow()
  })

  it('should apply side="right" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../frontend/components/ui/sheet')
    const { container } = render(
      <Sheet open>
        <SheetContent side="right">Inhalt</SheetContent>
      </Sheet>
    )
    const content = container.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-right')
  })

  it('should apply side="left" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../frontend/components/ui/sheet')
    const { container } = render(
      <Sheet open>
        <SheetContent side="left">Inhalt</SheetContent>
      </Sheet>
    )
    const content = container.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-left')
  })

  it('should have close button with aria-label="Panel schließen"', async () => {
    const { Sheet, SheetContent } = await import('../../../frontend/components/ui/sheet')
    const { container } = render(
      <Sheet open>
        <SheetContent>Inhalt</SheetContent>
      </Sheet>
    )
    const closeBtn = container.querySelector('[data-slot="sheet-close"]')
    expect(closeBtn).toHaveAttribute('aria-label', 'Panel schließen')
  })
})

// ─── Token-Binding Tests ─────────────────────────────────────────────────────

describe('Theme Token Binding — keine hardcoded Farben', () => {
  const checkNoHardcodedColors = (className: string) => {
    expect(className).not.toMatch(/\b(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  }

  it('Button: kein hardcoded bg-red-* oder bg-green-*', async () => {
    const { Button } = await import('../../../frontend/components/ui/button')
    const variants = ['default', 'destructive', 'outline', 'ghost'] as const
    for (const variant of variants) {
      const { container } = render(<Button variant={variant}>T</Button>)
      const el = container.querySelector('[data-slot="button"]')
      checkNoHardcodedColors(el?.className ?? '')
    }
  })

  it('Input: kein hardcoded border-red-*', async () => {
    const { Input } = await import('../../../frontend/components/ui/input')
    const { container } = render(<Input variant="error" />)
    const el = container.querySelector('[data-slot="input"]')
    checkNoHardcodedColors(el?.className ?? '')
  })

  it('Badge: kein hardcoded bg-red-* oder bg-green-*', async () => {
    const { Badge } = await import('../../../frontend/components/ui/badge')
    const variants = ['default', 'secondary', 'destructive', 'outline'] as const
    for (const variant of variants) {
      const { container } = render(<Badge variant={variant}>T</Badge>)
      const el = container.querySelector('[data-slot="badge"]')
      checkNoHardcodedColors(el?.className ?? '')
    }
  })
})

// ─── Font Setup Tests ────────────────────────────────────────────────────────
// HINWEIS: next/font/google kann NICHT direkt in Vitest/JSDOM importiert werden.
// next/font erfordert den Next.js Kompilierungs-Kontext (statische Analyse zur Build-Zeit).
// Stattdessen: readFileSync-basierte Assertions prüfen den Dateiinhalt — selbes Muster wie Slice 1.

describe('Font Setup — lib/theme/fonts.ts', () => {
  const fontsPath = resolve(FRONTEND_ROOT, 'lib/theme/fonts.ts')

  it('should exist', () => {
    expect(existsSync(fontsPath)).toBe(true)
  })

  it('should contain Inter import from next/font/google', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("from 'next/font/google'")
  })

  it('headingFont should use variable "--font-heading"', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("variable: '--font-heading'")
  })

  it('bodyFont should use variable "--font-body"', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("variable: '--font-body'")
  })

  it('should export headingFont', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain('export const headingFont')
  })

  it('should export bodyFont', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain('export const bodyFont')
  })
})

// ─── package.json Dependencies Tests ────────────────────────────────────────

describe('package.json — Neue Dependencies vorhanden', () => {
  const pkg = JSON.parse(
    require('fs').readFileSync(resolve(FRONTEND_ROOT, 'package.json'), 'utf-8')
  )
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies }

  it('should have class-variance-authority', () => {
    expect(allDeps['class-variance-authority']).toBeDefined()
  })

  it('should have @radix-ui/react-dialog', () => {
    expect(allDeps['@radix-ui/react-dialog']).toBeDefined()
  })

  it('should have @radix-ui/react-slot', () => {
    expect(allDeps['@radix-ui/react-slot']).toBeDefined()
  })

  it('should have lucide-react', () => {
    expect(allDeps['lucide-react']).toBeDefined()
  })

  it('should have tw-animate-css', () => {
    expect(allDeps['tw-animate-css']).toBeDefined()
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-theme-token-system | `cn()` in `lib/utils.ts` | Function | `(...inputs: ClassValue[]) => string` — wird in allen Primitives importiert |
| slice-01-theme-token-system | `--color-primary`, `--color-error`, `--color-success`, `--color-warning`, `--color-overlay` | CSS Custom Properties in `@theme` | Als Tailwind-Klassen verfügbar: `bg-primary`, `bg-error`, `bg-overlay` |
| slice-01-theme-token-system | `--radius-card`, `--radius-button` | CSS Custom Properties in `@theme` | Als inline CSS-Variable verwendet: `rounded-[var(--radius-button)]` |
| slice-01-theme-token-system | `--shadow-card`, `--shadow-card-hover` | CSS Custom Properties in `@theme` | Als inline CSS-Variable: `shadow-[var(--shadow-card)]` |
| slice-01-theme-token-system | `--min-touch-target` | CSS Custom Property in `@theme` | `h-[var(--min-touch-target,2.75rem)]` in Button + Input |
| slice-01-theme-token-system | `--color-surface-elevated`, `--color-border`, `--color-text-primary`, `--color-text-secondary` | CSS Custom Properties | Als Tailwind-Klassen: `bg-surface-elevated`, `border-border`, `text-text-primary` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Button` | Component | slice-03 (alle Component-Migrationen), slice-04 (Blocks) | Props: `ButtonProps` mit `variant`, `size`, `asChild`, `isLoading` |
| `Input` | Component | slice-03 (Migration cart, forms), slice-04 (Blocks) | Props: `InputProps` mit `variant`, `errorMessage` |
| `Card` | Component | slice-03 (product-card.tsx), slice-04 (ProductGridBlock) | Props: `CardProps` mit `variant`; Sub-Components: `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`, `CardFooter` |
| `Badge` | Component | slice-03 (product/category Components), slice-04 (Blocks) | Props: `BadgeProps` mit `variant` |
| `Skeleton` | Component | slice-03 (ersetzt product-card-skeleton.tsx), slice-04 (Block Loading States) | Props: `SkeletonProps` mit `className` für Sizing |
| `Dialog` | Component | slice-03 (overlays), slice-04 (falls Dialoge in Blocks) | Sub-Components: `Dialog`, `DialogTrigger`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose` |
| `Sheet` | Component | slice-03 (mobile-menu.tsx → Sheet), slice-04 (Cart-Drawer) | Sub-Components: `Sheet`, `SheetTrigger`, `SheetContent (side)`, `SheetHeader`, `SheetTitle`, `SheetDescription`, `SheetFooter`, `SheetClose` |
| `headingFont`, `bodyFont` | next/font Instanzen | `app/layout.tsx` | `.variable` CSS Custom Property für HTML-Element |
| `buttonVariants` | CVA Function | slice-03 (kann als asChild Wrapper genutzt werden) | `(opts) => string` |

### Integration Validation Tasks

- [ ] `cn()` aus `lib/utils.ts` (Slice 1 Deliverable) ist in allen Primitives importierbar
- [ ] Tailwind-Klassen `bg-primary`, `bg-error`, `bg-surface-elevated`, `border-border` sind verfügbar (Slice 1 hat `@theme` Block konfiguriert)
- [ ] `--min-touch-target` Token ist in `@theme` registriert (Slice 1) — Button + Input nutzen ihn
- [ ] `headingFont.variable` und `bodyFont.variable` sind in `layout.tsx` auf `<html>` gesetzt
- [ ] `tw-animate-css` ist in `globals.css` importiert — Dialog/Sheet Animationen funktionieren

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `button.tsx` | Abschnitt 6 | YES | CVA variants, asChild, isLoading, data-slot="button", touch-action |
| `input.tsx` | Abschnitt 7 | YES | CVA error variant, errorMessage, aria-invalid, aria-describedby, data-slot |
| `card.tsx` | Abschnitt 8 | YES | CVA interactive variant, compound components, data-slot attrs, --radius-card, --shadow-card |
| `badge.tsx` | Abschnitt 9 | YES | CVA 4 variants, keine hardcoded Farben, data-slot="badge" |
| `skeleton.tsx` | Abschnitt 10 | YES | animate-pulse, bg-surface-elevated, aria-busy, aria-label, data-slot |
| `dialog.tsx` | Abschnitt 11 | YES | @radix-ui/react-dialog, X-Button mit aria-label, overlay bg-overlay, tw-animate-css Klassen |
| `sheet.tsx` | Abschnitt 12 | YES | @radix-ui/react-dialog, 4 side-Varianten (CVA), X-Button mit aria-label, tw-animate-css |
| `lib/theme/fonts.ts` | Abschnitt 4 | YES | Inter via next/font/google, variable: '--font-heading' / '--font-body' |
| `app/layout.tsx` (Font-Integration) | Abschnitt 13 | YES | headingFont.variable + bodyFont.variable auf <html> className |
| `package.json` (neue Deps) | Abschnitt 14 | YES | class-variance-authority, @radix-ui/react-dialog, @radix-ui/react-slot, lucide-react, tw-animate-css |

---

## Constraints & Hinweise

**Betrifft:**
- `frontend/components/ui/` — neues Verzeichnis, alle Dateien neu
- `frontend/lib/theme/fonts.ts` — neue Datei
- `frontend/app/layout.tsx` — bestehende Datei wird modifiziert (Font-Klassen)
- `frontend/app/globals.css` — bestehende Datei wird modifiziert (`@import "tw-animate-css"`, `--font-*` in `@theme`)
- `frontend/package.json` — neue Dependencies

**API Contract:**
- Alle Primitives exportieren `data-slot` Attribute (shadcn/ui Convention) — Slice 3 Tests können darüber selektieren
- `cn()` aus `lib/utils.ts` (nicht `@/lib/utils` in Code-Beispielen — verwende `@/lib/utils` mit Path-Alias)
- Dialog und Sheet haben `'use client'` Direktive — werden nicht als RSC verwendet
- `asChild` Pattern bei Button ermöglicht semantisch korrekte Link-Buttons ohne ARIA-Tricks

**Abgrenzung:**
- Dieser Slice **erstellt** die Primitives — er migriert KEINE bestehenden Components (das ist Slice 3)
- `product-card-skeleton.tsx` wird in Slice 3 auf `<Skeleton>` umgestellt — bleibt in Slice 2 unberührt
- Der `MobileMenu` wird in Slice 3 auf `<Sheet side="left">` umgestellt
- `next/font` statische Import-Limitation: Falls in Slice 5 ein anderer Font gewählt wird, muss `fonts.ts` neu generiert werden. Das Build-Script aus Slice 1 wird in Slice 5 um Font-Generierung erweitert.
- `tw-animate-css` ersetzt `tailwindcss-animate` — falls `tailwindcss-animate` noch in `package.json` steht, muss es entfernt werden um Konflikte zu vermeiden.

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [ ] Alle 7 Primitives in `components/ui/` erstellt
- [ ] Alle Primitives nutzen nur Theme-Token-Klassen (`bg-primary`, `bg-error`, etc.) — kein `bg-red-*` oder `bg-green-*`
- [ ] `lib/theme/fonts.ts` erstellt, Font-Klassen in `layout.tsx` angewendet
- [ ] `tw-animate-css` importiert in `globals.css`
- [ ] `pnpm build` läuft durch ohne Fehler
- [ ] Alle Tests in `slice-02-shadcn-ui-primitives.test.ts` grün

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Nicht relevant — keine async Operationen in Primitives
- [x] `bundle-dynamic-imports`: Dialog/Sheet sind `'use client'` — kein `next/dynamic` nötig, da shadcn/ui Overhead minimal ist

**High Priority:**
- [x] `server-cache-react`: Nicht relevant — Primitives sind UI-Components ohne Data Fetching
- [x] `async-suspense-boundaries`: Nicht relevant — Skeleton-Component übernimmt Loading-State

**Medium Priority:**
- [x] `rerender-memo`: Primitives sind forwardRef-Components, ausreichend optimiert für Praxis
- [x] `rerender-dependencies`: Nicht relevant — keine Effects in Primitives

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only Buttons haben `aria-label` — Dialog X-Button: `aria-label="Dialog schließen"`, Sheet X-Button: `aria-label="Panel schließen"`
- [x] Dekorative Icons haben `aria-hidden="true"` — X-Icons in Dialog/Sheet, Spinner in Button
- [x] Focus-visible states für alle interaktiven Elemente — `focus-visible:ring-2 focus-visible:ring-primary` auf Button, Input, Dialog-Close, Sheet-Close
- [x] Input: `aria-invalid` + `aria-describedby` für Error-State

**Animation & Motion:**
- [x] `prefers-reduced-motion`: tw-animate-css respektiert `prefers-reduced-motion` automatisch
- [x] Nur transform/opacity für Animationen: Dialog zoom-in/fade-in, Sheet slide-in (Transform-basiert)

**Touch & Mobile:**
- [x] `[touch-action:manipulation]` auf Button via CVA base styles (Tailwind v4 Arbitrary Property); Input via inline style
- [x] Touch targets mindestens 44x44px: `h-[var(--min-touch-target,2.75rem)]` auf Button default + icon size, Input

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Werte — alle Farben via Token-Klassen (`bg-primary`, `bg-error`, `bg-surface-elevated`, etc.)
- [x] CSS Custom Properties für Radius/Shadow: `rounded-[var(--radius-button)]`, `shadow-[var(--shadow-card)]`
- [x] Semantic color naming — `bg-error`, `bg-success`, `text-text-secondary`, `bg-surface-elevated`

**Build Tool Integration:**
- [x] Tailwind v4 CSS-first — kein `tailwind.config.ts`, alle Tokens kommen aus `@theme` Block (Slice 1)
- [x] `tw-animate-css` als `@import` in `globals.css` — kein Plugin nötig (ersetzt tailwindcss-animate)

---

## Links

- Design/Spec: `specs/phase-0/2026-02-21-frontend-theming/architecture.md`
- Discovery: `specs/phase-0/2026-02-21-frontend-theming/discovery.md`
- Dependency: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-01-theme-token-system.md`
- shadcn/ui Tailwind v4 Docs: https://ui.shadcn.com/docs/tailwind-v4
- Radix UI Dialog: https://www.radix-ui.com/docs/primitives/components/dialog
- CVA Docs: https://cva.style/docs

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert.**

<!-- DELIVERABLES_START -->
### Frontend — Neue Dateien
- [ ] `frontend/components/ui/button.tsx` — Button Component: 5 Variants (default, destructive, outline, ghost, link), 4 Sizes, asChild Pattern via @radix-ui/react-slot, isLoading State mit Spinner, CVA, data-slot="button"
- [ ] `frontend/components/ui/input.tsx` — Input Component: 2 Variants (default, error), errorMessage Prop mit aria-describedby, aria-invalid, data-slot="input", min-touch-target
- [ ] `frontend/components/ui/card.tsx` — Card Component: 2 Variants (default, interactive), Compound Components (CardHeader, CardTitle, CardDescription, CardContent, CardFooter), data-slot attrs, --radius-card, --shadow-card
- [ ] `frontend/components/ui/badge.tsx` — Badge Component: 4 Variants (default, secondary, destructive, outline), alle Token-basiert, data-slot="badge"
- [ ] `frontend/components/ui/skeleton.tsx` — Skeleton Component: animate-pulse, bg-surface-elevated, aria-busy, aria-label, data-slot="skeleton"
- [ ] `frontend/components/ui/dialog.tsx` — Dialog Component: @radix-ui/react-dialog, overlay bg-overlay, X-Button aria-label, tw-animate-css Animationen, data-slot attrs
- [ ] `frontend/components/ui/sheet.tsx` — Sheet Component: @radix-ui/react-dialog, 4 side-Varianten (CVA), X-Button aria-label, tw-animate-css Animationen, data-slot attrs
- [ ] `frontend/lib/theme/fonts.ts` — next/font/google Inter Instanzen mit variable: '--font-heading' / '--font-body'

### Frontend — Geänderte Dateien
- [ ] `frontend/app/layout.tsx` — MODIFIZIERT: headingFont.variable + bodyFont.variable auf <html> className hinzugefügt
- [ ] `frontend/app/globals.css` — MODIFIZIERT: @import "tw-animate-css" hinzugefügt; --font-heading / --font-body in @theme Block registriert
- [ ] `frontend/package.json` — MODIFIZIERT: class-variance-authority, @radix-ui/react-dialog, @radix-ui/react-slot, lucide-react, tw-animate-css als neue Dependencies

### Tests
- [ ] `frontend/tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts` — Unit Tests: Datei-Existenz, alle 7 Primitives Variants/States/ARIA, Token-Binding (keine hardcoded Farben), Font-Setup
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- `components/ui/` Dateien können direkt erstellt werden — kein shadcn CLI nötig (copy-paste Pattern)
- Alle Tailwind-Klassen die Token-Farben referenzieren (`bg-primary`, `bg-error`, etc.) setzen voraus, dass Slice 1 implementiert wurde und `generated-theme.css` generiert ist
- Dialog und Sheet benötigen `'use client'` — sie sind Client Components
- `next/font` benötigt keine `'use client'` Direktive in `fonts.ts` — kann im Server-Kontext geladen werden
