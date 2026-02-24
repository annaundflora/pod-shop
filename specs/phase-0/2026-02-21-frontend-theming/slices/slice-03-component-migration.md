# Slice 3: Component Migration (Big Bang)

> **Slice 3 von 5** für `Frontend-Architektur: Theming (P0.2)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-02-shadcn-ui-primitives.md` |
> | **Nächster:** | `slice-04-block-registry.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-component-migration` |
| **Test** | `pnpm test tests/slices/frontend-theming/slice-03-component-migration.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-theme-token-system", "slice-02-shadcn-ui-primitives"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests (`.test.ts`)
- **Dependencies**: Slice 1 (Theme Tokens + cn()) und Slice 2 (shadcn/ui Primitives) müssen fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren.
> `frontend/package.json` enthält `next`, `vitest`, `@tailwindcss/postcss` → Stack: typescript-nextjs

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/frontend-theming/slice-03-component-migration.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/frontend-theming/` |
| **Acceptance Command** | `cd frontend && pnpm test` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + React 19 + Vitest v3 + @testing-library/react
- **Test Command**: Neue Slice-3-Tests (Datei-Assertions + Component-Rendering)
- **Integration Command**: Alle Theming-Slice-Tests zusammen
- **Acceptance Command**: `pnpm test` — alle 151 bestehenden Tests PLUS neue Slice-3-Tests müssen grün sein
- **Mocking Strategy**: `no_mocks` — reine Component-Tests ohne externe Services

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Theme Token System | Done | `slice-01-theme-token-system.md` |
| 2 | shadcn/ui Primitives | Done | `slice-02-shadcn-ui-primitives.md` |
| 3 | Component Migration (Big Bang) | **Ready** | `slice-03-component-migration.md` |
| 4 | Block Registry + Page Renderer | Pending | `slice-04-block-registry.md` |
| 5 | Theme Config + Multi-Shop | Pending | `slice-05-multi-shop.md` |

---

## Kontext & Ziel

Slice 1 und 2 haben die Fundamente gelegt: Theme Token System (CSS Custom Properties), shadcn/ui Primitives (Button, Input, Card, Badge, Skeleton, Dialog, Sheet) und `cn()` Utility sind bereit.

Dieser Slice führt die "Big Bang" Migration aller 18 bestehenden Components durch. Alle hardcoded Farben werden durch semantische Tokens ersetzt, der Runtime ThemeProvider/ThemeSwitcher wird entfernt, und Font-Loading via `next/font` wird in `layout.tsx` aktiviert.

**Aktuelle Probleme:**
1. `cart-page-client.tsx`: `bg-red-50 border-red-200 text-red-800 hover:text-red-800 focus-visible:ring-red-500 text-red-600` — hardcoded Farben
2. `cart-item-row.tsx`: `hover:text-red-600 focus-visible:ring-red-500` — hardcoded Farben
3. `add-to-cart-button.tsx`: `bg-green-600 focus-visible:ring-green-500` im Erfolgs-State — hardcoded Farbe
4. `product-card.tsx`: `bg-black/50 bg-black/70` für Sold-Out Overlay — hardcoded Farben; `hover:shadow-md` statt shadow-card-hover Token
5. `mobile-menu.tsx`: `bg-black/40` für Backdrop — hardcoded Farbe; Custom Drawer-Implementierung statt shadcn/ui Sheet
6. `cookie-consent-banner.tsx`: `bg-white` statt `bg-surface` Token; `bg-white text-text-primary border border-border` hardcoded für Ablehnen-Button
7. `theme-switcher.tsx`: `bg-purple-600 bg-orange-500 bg-emerald-500` — hardcoded Farben (wird komplett ENTFERNT)
8. `layout.tsx`: ThemeProvider + ThemeSwitcher-Import + `dangerouslySetInnerHTML` Theme-Script — wird entfernt; Font-Klassen fehlen
9. 8 Dateien mit hardcoded "POD Shop" Text: `layout.tsx`, `app/page.tsx`, `warenkorb/page.tsx`, `kategorie/[slug]/page.tsx`, `produkt/[slug]/page.tsx`, `header.tsx`, `footer.tsx` → Env-Variable `NEXT_PUBLIC_SHOP_NAME` als Übergangslösung
10. `product-card-skeleton.tsx`: wird auf `<Skeleton>` Component migriert
11. 10 Dateien nutzen hardcoded `shadow-*` Tailwind-Utilities (`shadow-lg`, `shadow-xl`, `shadow-md`, `shadow-sm`) statt `shadow-[var(--shadow-card)]` Token
12. `contexts/theme-context.tsx` und `lib/theme/themes.ts` werden entfernt (Runtime-Theme nicht mehr benötigt)

**Zielbild nach diesem Slice:**
- Alle 18 Components nutzen ausschließlich Theme-Token-Klassen — kein `bg-red-*`, `bg-green-*`, `bg-black/*`
- `mobile-menu.tsx` nutzt shadcn/ui Sheet (`side="left"`) für den Drawer
- `product-card-skeleton.tsx` ist auf shadcn/ui `Skeleton` Component migriert
- `layout.tsx`: ThemeProvider entfernt, Font-Klassen auf `<html>` angewendet, Theme-Init-Script entfernt
- `theme-switcher.tsx` und `theme-context.tsx` und `lib/theme/themes.ts` gelöscht
- `header.tsx`: ThemeSwitcher-Import entfernt
- `NEXT_PUBLIC_SHOP_NAME` Env-Variable eingeführt als Brücke für hardcoded "POD Shop" Stellen
- Alle 151 bestehenden Vitest Tests bleiben grün

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Constraints, Big Bang Migration Risk

```
Constraint: 151 passing Vitest tests must stay green
Solution:
  - shadcn/ui fügt data-slot Attribute und Wrapper-Elemente hinzu
  - Tests die auf Tailwind-Klassen prüfen müssen angepasst werden
  - Tests die auf Struktur prüfen müssen angepasst werden
  - Bestehende Tests aus pod-shop-mvp Slices testen Business-Logik (nicht UI-Klassen)
    → Diese bleiben unverändert grün

Build-Time ersetzt Runtime:
  ThemeProvider (runtime localStorage + data-theme) → entfernt
  ThemeSwitcher (purple/orange/emerald buttons) → entfernt
  [data-theme] CSS Blöcke (bereits in Slice 1 entfernt) → bleibt entfernt
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/app/layout.tsx` | MODIFIZIERT: ThemeProvider entfernt, Font-Klassen hinzugefügt, Theme-Init-Script entfernt |
| `frontend/app/page.tsx` | MODIFIZIERT: "POD Shop" → `process.env.NEXT_PUBLIC_SHOP_NAME` |
| `frontend/app/warenkorb/page.tsx` | MODIFIZIERT: Hardcoded "POD Shop" im Metadata-Titel entfernt |
| `frontend/app/warenkorb/cart-page-client.tsx` | MODIFIZIERT: `bg-red-50 border-red-200 text-red-800` → `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)] border-error text-error` |
| `frontend/app/kategorie/[slug]/page.tsx` | MODIFIZIERT: "POD Shop" im Metadata-Titel |
| `frontend/app/produkt/[slug]/page.tsx` | MODIFIZIERT: "POD Shop" im Metadata-Titel |
| `frontend/components/layout/header.tsx` | MODIFIZIERT: ThemeSwitcher Import + Usage entfernt, "POD Shop" → NEXT_PUBLIC_SHOP_NAME |
| `frontend/components/layout/footer.tsx` | MODIFIZIERT: "POD Shop" → NEXT_PUBLIC_SHOP_NAME |
| `frontend/components/layout/mobile-menu.tsx` | MODIFIZIERT: Custom Drawer → shadcn/ui Sheet |
| `frontend/components/layout/cookie-consent-banner.tsx` | MODIFIZIERT: `bg-white` → `bg-surface`, Ablehnen-Button auf Button Primitive |
| `frontend/components/layout/theme-switcher.tsx` | GELÖSCHT |
| `frontend/contexts/theme-context.tsx` | GELÖSCHT |
| `frontend/lib/theme/themes.ts` | GELÖSCHT |
| `frontend/components/product/product-card.tsx` | MODIFIZIERT: `bg-black/50 bg-black/70` → `bg-overlay`, `hover:shadow-md` → `hover:shadow-[var(--shadow-card-hover)]` |
| `frontend/components/product/product-card-skeleton.tsx` | MODIFIZIERT: auf `<Skeleton>` Component migriert |
| `frontend/components/product/add-to-cart-button.tsx` | MODIFIZIERT: `bg-green-600` → `bg-success` im Erfolgs-State |
| `frontend/components/cart/cart-item-row.tsx` | MODIFIZIERT: `hover:text-red-600 focus-visible:ring-red-500` → `hover:text-error focus-visible:ring-error` |
| `frontend/components/cart/cart-summary.tsx` | MODIFIZIERT: Button auf shadcn/ui Button Primitive (optional, aber konsistent) |
| `frontend/components/cart/empty-cart.tsx` | MODIFIZIERT: Link-Button auf shadcn/ui Button mit `asChild` |
| `frontend/components/cart/quantity-stepper.tsx` | MODIFIZIERT: Buttons auf shadcn/ui Button Primitive |
| `frontend/.env.local.example` | MODIFIZIERT: `NEXT_PUBLIC_SHOP_NAME=POD Shop` hinzugefügt |

### 2. Datenfluss

```
Slice 2 Primitives (Button, Card, Sheet, Skeleton, etc.)
  ↓ Import
Migrierte Components (cart-item-row, product-card, mobile-menu, etc.)
  ↓ shadcn/ui cn() + CVA Klassen (aus Theme Tokens)
  ↓ Keine hardcoded Tailwind-Farben mehr
Gerendertes HTML mit semantischen Token-Klassen

ThemeProvider (runtime) → ENTFERNT
ThemeSwitcher (runtime) → ENTFERNT
theme-context.tsx → GELÖSCHT
lib/theme/themes.ts → GELÖSCHT

layout.tsx:
  - ThemeProvider entfernt aus Provider-Stack
  - headingFont.variable + bodyFont.variable auf <html> angewendet (aus Slice 2)
  - dangerouslySetInnerHTML Theme-Script entfernt

"POD Shop" Stellen:
  - layout.tsx metadata: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - header.tsx Logo: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - footer.tsx Copyright: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - page.tsx Hero: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - warenkorb/page.tsx metadata: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - kategorie/[slug]/page.tsx metadata: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  - produkt/[slug]/page.tsx metadata: process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
```

### 3. Farb-Migrationstabelle

| Datei | Hardcoded Klasse | Ersatz (Token) |
|-------|-----------------|----------------|
| `cart-page-client.tsx` | `bg-red-50` | `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)]` |
| `cart-page-client.tsx` | `border-red-200` | `border-error` |
| `cart-page-client.tsx` | `text-red-800` | `text-error` |
| `cart-page-client.tsx` | `text-red-600` | `text-error` |
| `cart-page-client.tsx` | `hover:text-red-800` | `hover:text-error` |
| `cart-page-client.tsx` | `focus-visible:ring-red-500` | `focus-visible:ring-error` |
| `cart-item-row.tsx` | `hover:text-red-600` | `hover:text-error` |
| `cart-item-row.tsx` | `focus-visible:ring-red-500` | `focus-visible:ring-error` |
| `add-to-cart-button.tsx` | `bg-green-600` | `bg-success` |
| `add-to-cart-button.tsx` | `focus-visible:ring-green-500` | `focus-visible:ring-success` |
| `product-card.tsx` | `bg-black/50` | `bg-overlay` |
| `product-card.tsx` | `bg-black/70` | `bg-overlay` |
| `product-card.tsx` | `hover:shadow-md` | `hover:shadow-[var(--shadow-card-hover)]` |
| `mobile-menu.tsx` | `bg-black/40` | `bg-overlay` (via Sheet) |
| `cookie-consent-banner.tsx` | `bg-white` | `bg-surface` |
| `theme-switcher.tsx` | `bg-purple-600`, `bg-orange-500`, `bg-emerald-500` | DATEI GELÖSCHT |

### 4. Shadow-Migrationstabelle

Alle `shadow-*` Tailwind-Utilities werden durch das `shadow-[var(--shadow-card)]` bzw. `shadow-[var(--shadow-card-hover)]` Token ersetzt.

| Datei | Hardcoded Klasse | Ersatz (Token) |
|-------|-----------------|----------------|
| `product-card.tsx` | `hover:shadow-md` | `hover:shadow-[var(--shadow-card-hover)]` |
| `mobile-menu.tsx` | `shadow-xl` (auf nav-Panel) | `shadow-[var(--shadow-card-hover)]` (via Sheet) |
| `cart-page-client.tsx` | `shadow-lg` (ggf.) | `shadow-[var(--shadow-card)]` |
| Andere betroffene Dateien | `shadow-sm`, `shadow-md`, `shadow-lg`, `shadow-xl` | `shadow-[var(--shadow-card)]` oder `shadow-[var(--shadow-card-hover)]` |

**Regel:** `shadow-sm` / `shadow-md` → `shadow-[var(--shadow-card)]`; `shadow-lg` / `shadow-xl` → `shadow-[var(--shadow-card-hover)]`

### 5. Test-Anpassungsstrategie

Die bestehenden 151 Tests aus `tests/slices/pod-shop-mvp/` testen primär:
- Business-Logik (`extractVariantOptions`, `findVariation`, `generateProductJsonLd`)
- Token-Manager, Checkout-Redirect
- Datei-Existenz und Inhalt (strukturelle Assertions)

Diese Tests sind **nicht betroffen** von der UI-Migration. Sie bleiben ohne Anpassung grün.

Wenn Tests Tailwind-Klassen explizit prüfen (z.B. `expect(className).toContain('bg-red-50')`), müssen sie auf die neuen Token-Klassen umgestellt werden.

### 6. Code-Beispiel: cart-page-client.tsx (Error Alert)

Das Error-Banner verwendet jetzt semantische Token-Klassen:

```typescript
// frontend/app/warenkorb/cart-page-client.tsx — Error Alert Block
{error && (
  <div
    role="alert"
    aria-live="polite"
    className="mb-4 p-3 [background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)] border border-error text-error rounded-lg text-sm flex items-center justify-between"
  >
    <span>{error}</span>
    <button
      onClick={clearError}
      aria-label="Fehlermeldung schliessen"
      className="ml-2 text-error hover:text-error focus-visible:ring-2 focus-visible:ring-error rounded opacity-80 hover:opacity-100"
    >
      &#10005;
    </button>
  </div>
)}
```

### 7. Code-Beispiel: add-to-cart-button.tsx (Erfolgs-State)

```typescript
// frontend/components/product/add-to-cart-button.tsx — Erfolgs-State Klassen
isSuccess
  ? 'bg-success text-white focus-visible:ring-success scale-[0.98]'
  : 'bg-primary text-white hover:bg-primary-hover hover:shadow-[var(--shadow-card-hover)] focus-visible:ring-primary disabled:opacity-50'
```

### 8. Code-Beispiel: product-card.tsx (Sold-Out Overlay)

```typescript
// frontend/components/product/product-card.tsx — Sold-Out Overlay
{isSoldOut && (
  <div className="absolute inset-0 bg-overlay flex items-center justify-center">
    <span className="text-white font-medium text-sm px-3 py-1 bg-overlay rounded">
      Ausverkauft
    </span>
  </div>
)}
```

Das `<Link>`-Element erhält ebenfalls den korrekten Shadow-Token:
```typescript
<Link
  href={`/produkt/${product.slug}`}
  className="group block rounded-[var(--radius-card,0.75rem)] overflow-hidden border border-border bg-surface hover:shadow-[var(--shadow-card-hover)] hover:-translate-y-0.5 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-primary"
>
```

### 9. Code-Beispiel: product-card-skeleton.tsx (auf Skeleton migriert)

```typescript
// frontend/components/product/product-card-skeleton.tsx
import { Skeleton } from '@/components/ui/skeleton'

export function ProductCardSkeleton() {
  return (
    <div
      className="rounded-[var(--radius-card,0.75rem)] overflow-hidden border border-border bg-surface"
      aria-label="Produkt wird geladen"
    >
      <Skeleton className="aspect-square w-full rounded-none" />
      <div className="p-3 space-y-2">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-1/3" />
      </div>
    </div>
  )
}
```

### 10. Code-Beispiel: mobile-menu.tsx (auf Sheet migriert)

```typescript
// frontend/components/layout/mobile-menu.tsx
'use client'

import Link from 'next/link'
import type { ProductCategory } from '@/lib/graphql/types'
import { Sheet, SheetTrigger, SheetContent } from '@/components/ui/sheet'

interface MobileMenuProps {
  categories: ProductCategory[]
}

export function MobileMenu({ categories }: MobileMenuProps) {
  return (
    <Sheet>
      {/* Hamburger Button */}
      <SheetTrigger asChild>
        <button
          type="button"
          aria-label="Menü öffnen"
          className="p-2 min-h-[44px] min-w-[44px] flex items-center justify-center text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
        >
          <svg
            aria-hidden="true"
            className="w-6 h-6"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M4 6h16M4 12h16M4 18h16"
            />
          </svg>
        </button>
      </SheetTrigger>

      <SheetContent side="left">
        {/* Kategorie-Links */}
        <nav aria-label="Mobile Navigation" className="flex flex-col h-full">
          <div className="flex-1 overflow-y-auto py-4 mt-8">
            <p className="px-4 text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2">
              Kategorien
            </p>
            {categories.map((category) => (
              <Link
                key={category.id}
                href={`/kategorie/${category.slug}`}
                className="block px-4 py-3 text-text-primary hover:bg-surface-elevated transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
              >
                {category.name}
              </Link>
            ))}

            <hr className="my-4 border-border" />

            <p className="px-4 text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2">
              Info
            </p>
            <a
              href={`${process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'}/impressum`}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Impressum
            </a>
            <a
              href={`${process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'}/datenschutz`}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Datenschutz
            </a>
            <a
              href={`${process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'}/mein-konto`}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Mein Konto
            </a>
          </div>
        </nav>
      </SheetContent>
    </Sheet>
  )
}
```

**Hinweis:** Das Sheet-Close-Icon ist bereits in `SheetContent` (Slice 2) eingebaut — kein eigener Close-Button nötig. `SheetTrigger` hat `asChild` → der `<button>` übernimmt das aria-expanded Attribut automatisch via Radix UI.

### 11. Code-Beispiel: cookie-consent-banner.tsx

```typescript
// frontend/components/layout/cookie-consent-banner.tsx
// Migration: bg-white → bg-surface, Ablehnen-Button → shadcn/ui Button variant="outline"
// Hinweis: Detaillierte Implementierung liegt im Ermessen des Implementierungs-Agenten.
// Pflicht: bg-white entfernen, bg-surface verwenden, shadcn/ui Button nutzen.
import { Button } from '@/components/ui/button'

// Banner-Container: bg-surface statt bg-white
// Ablehnen-Button: <Button variant="outline">Nur Notwendige</Button>
// Akzeptieren-Button: <Button variant="default">Alle akzeptieren</Button>
```

### 12. Code-Beispiel: layout.tsx (ThemeProvider entfernt, Fonts aktiviert)

```typescript
// frontend/app/layout.tsx — NACH der Migration
import type { Metadata } from 'next'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import { CartProvider } from '@/contexts/cart-context'
import { Header } from '@/components/layout/header'
import { Footer } from '@/components/layout/footer'
import { CookieConsentBanner } from '@/components/layout/cookie-consent-banner'
import { PinterestTagInit } from '@/components/tracking/pinterest-tag-init'
import { headingFont, bodyFont } from '@/lib/theme/fonts'
import './globals.css'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

export const metadata: Metadata = {
  title: SHOP_NAME,
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="de" className={`${headingFont.variable} ${bodyFont.variable}`}>
      <body>
        <ApolloWrapper>
          <CartProvider>
            <Header />
            <div className="max-w-7xl mx-auto px-4 py-8">
              {children}
            </div>
            <Footer />
            <CookieConsentBanner />
            <PinterestTagInit />
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
```

**Entfernt:**
- `ThemeProvider` aus `@/contexts/theme-context` — Import und Usage
- `dangerouslySetInnerHTML` Script-Block für Theme-Initialisierung
- `suppressHydrationWarning` auf `<html>` (war wegen data-theme nötig — nicht mehr nötig)
- `ThemeSwitcher` Import aus Header

### 13. Code-Beispiel: header.tsx (ThemeSwitcher entfernt)

```typescript
// frontend/components/layout/header.tsx — NACH der Migration
// ThemeSwitcher Import entfernt:
// import { ThemeSwitcher } from './theme-switcher'  ← GELÖSCHT

// "POD Shop" → Env-Variable:
<Link href="/" className="font-bold text-lg text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded">
  {process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}
</Link>

// ThemeSwitcher Usage entfernt aus:
// <div className="flex items-center gap-3">
//   <ThemeSwitcher />   ← ENTFERNT
//   <CartIcon />
// </div>
```

### 14. Code-Beispiel: NEXT_PUBLIC_SHOP_NAME Env-Variable

```bash
# frontend/.env.local.example — Neuer Eintrag
NEXT_PUBLIC_SHOP_NAME=POD Shop
```

Alle 7 Stellen mit "POD Shop" nutzen das Pattern:
```typescript
const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
```

### 15. Zu löschende Dateien

| Datei | Grund |
|-------|-------|
| `frontend/components/layout/theme-switcher.tsx` | Runtime-Theme-Switching wird entfernt |
| `frontend/contexts/theme-context.tsx` | Runtime-Theme-Context wird entfernt |
| `frontend/lib/theme/themes.ts` | Theme-IDs (modern/bold/soft) werden nicht mehr benötigt |

**Wichtig:** Diese Dateien dürfen erst gelöscht werden, nachdem alle Imports in `layout.tsx`, `header.tsx` und anderen Dateien entfernt wurden.

---

## UI Anforderungen

> **Hinweis:** Keine neuen UI-Screens — bestehende Seiten sehen visuell identisch aus.

### 1. Visuelle Gleichheit

**Verhalten nach Migration:**
- Homepage, Kategorie-Seite, Produkt-Seite, Warenkorb-Seite sehen identisch aus
- Farben sind identisch (OKLch-Tokens entsprechen den alten hardcoded Tailwind-Werten)
- Shadows sind identisch (card-shadow tokens entsprechen shadow-md/shadow-xl)
- Kein Flash of Unstyled Content (FOUC) durch Font-Loading (next/font mit `display: swap`)

**Zustände:**
- Loading: Skeleton Components nutzen `<Skeleton>` Primitive (identisches Aussehen)
- Error: Error-Banner nutzt `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)] border-error text-error` (semantisch korrekt, visuell ähnlich zu vorher; `bg-error/10` nicht verwendbar wegen `var()`-Indirection in Slice 1)
- Sold-Out: Overlay nutzt `bg-overlay` (identisch zu `bg-black/50`)
- Erfolg (Add-to-Cart): `bg-success` statt `bg-green-600` (visuell ähnlich, semantisch korrekt)

### 2. MobileMenu Sheet

**Verhalten:**
- Sheet öffnet von links (`side="left"`) — identisch zum bestehenden Drawer-Verhalten
- Radix UI Sheet hat automatischen Focus-Trap, ESC-Close, Overlay — Feature-Parität mit bisheriger Implementierung
- Close-Button ist in `SheetContent` eingebaut (X-Icon, `aria-label="Panel schließen"`)
- Keyboard-Navigation: Tab trapped im Sheet, ESC schließt

### 3. Accessibility (alle migrierten Components)

- `focus-visible:ring-2 focus-visible:ring-primary` auf allen interaktiven Elementen
- Icon-only Buttons haben `aria-label` (bleibt erhalten)
- Images haben `alt` Text (bleibt erhalten)
- Keyboard handler für interaktive Elemente (Sheet übernimmt von Radix UI)

---

## Acceptance Criteria

1) GIVEN die Migration ist abgeschlossen
   WHEN `grep -r "bg-red-\|bg-green-\|bg-black/\|bg-white\|bg-purple-\|bg-orange-\|bg-emerald-\|text-red-\|border-red-\|ring-red-\|ring-green-\|\bshadow-sm\b\|\bshadow-md\b\|\bshadow-lg\b\|\bshadow-xl\b" frontend/components frontend/app frontend/contexts` ausgeführt wird
   THEN gibt es keine Treffer mehr (alle hardcoded Farben und Shadow-Utilities wurden durch Token-Klassen ersetzt)

2) GIVEN die Migration ist abgeschlossen
   WHEN `grep -r "ThemeProvider\|ThemeSwitcher\|useTheme\|theme-context\|pod-theme\|data-theme" frontend/app frontend/components` ausgeführt wird
   THEN gibt es keine Treffer mehr (Runtime-Theme vollständig entfernt)

3) GIVEN `add-to-cart-button.tsx` ist migriert
   WHEN ein Produkt erfolgreich in den Warenkorb gelegt wird
   THEN zeigt der Button `bg-success` (nicht `bg-green-600`) im Erfolgs-State

4) GIVEN `cart-page-client.tsx` ist migriert
   WHEN ein Warenkorb-Fehler aufgetreten ist
   THEN zeigt das Fehlerbanner `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)] border-error text-error` (nicht `bg-red-50 border-red-200 text-red-800`)

5) GIVEN `product-card.tsx` ist migriert
   WHEN ein ausverkauftes Produkt angezeigt wird
   THEN verwendet das Overlay `bg-overlay` (nicht `bg-black/50`)

6) GIVEN `mobile-menu.tsx` nutzt shadcn/ui Sheet
   WHEN das Hamburger-Icon geklickt wird
   THEN öffnet sich das Sheet von links mit Overlay und Focus-Trap; ESC schließt es

7) GIVEN `product-card-skeleton.tsx` ist migriert
   WHEN eine Produktliste lädt
   THEN werden `<Skeleton>` Components aus `components/ui/skeleton` verwendet (nicht inline `animate-pulse` Divs)

8) GIVEN `layout.tsx` ist migriert
   WHEN die App gerendert wird
   THEN hat `<html>` die Font-Variable-Klassen (`${headingFont.variable} ${bodyFont.variable}`) und KEINEN `data-theme` Attribute-Setter-Script mehr

9) GIVEN `NEXT_PUBLIC_SHOP_NAME` ist gesetzt
   WHEN die App gerendert wird
   THEN erscheint der Shop-Name aus der Env-Variable in Metadata-Titeln und im Header-Logo

10) GIVEN alle Migrationen sind abgeschlossen
    WHEN `cd frontend && pnpm test` ausgeführt wird
    THEN sind alle 151 bestehenden Tests grün (+ neue Slice-3-Tests)

11) GIVEN `contexts/theme-context.tsx`, `lib/theme/themes.ts`, `components/layout/theme-switcher.tsx` sind gelöscht
    WHEN `pnpm build` ausgeführt wird
    THEN schlägt der Build nicht fehl (alle Imports wurden korrekt entfernt)

12) GIVEN `cookie-consent-banner.tsx` ist migriert
    WHEN das Cookie-Banner angezeigt wird
    THEN verwendet der Banner-Container `bg-surface` (nicht `bg-white`) und der Ablehnen-Button `bg-surface-elevated`

---

## Testfälle

### Test-Datei

`tests/slices/frontend-theming/slice-03-component-migration.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/frontend-theming/slice-03-component-migration.test.ts
import { describe, it, expect } from 'vitest'
import { existsSync, readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '../../../frontend')

// ─── Helper ─────────────────────────────────────────────────────────────────

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function fileExists(relativePath: string): boolean {
  return existsSync(resolve(FRONTEND_ROOT, relativePath))
}

// ─── AC-1: Keine hardcoded Farben in migrierten Dateien ─────────────────────

describe('AC-1: Keine hardcoded Tailwind-Farben in Components', () => {
  const HARDCODED_PATTERN = /\b(bg|text|border|ring|from|to|via)-(red|green|blue|black|white|purple|orange|emerald|pink|yellow|gray|slate|zinc)-\d{3}\b/

  it('cart-page-client.tsx: keine hardcoded Farben (bg-red-*, border-red-*, text-red-*)', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(HARDCODED_PATTERN.test(content)).toBe(false)
  })

  it('cart-page-client.tsx: keine bg-red-50, border-red-200, text-red-800', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(content).not.toContain('bg-red-')
    expect(content).not.toContain('border-red-')
    expect(content).not.toContain('text-red-')
  })

  it('cart-page-client.tsx: nutzt color-mix() Fallback statt bg-error/10', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(content).toContain('color-mix(in_oklch,var(--color-error)_10%,transparent)')
    expect(content).toContain('border-error')
    expect(content).toContain('text-error')
  })

  it('cart-item-row.tsx: keine hardcoded red-Farben', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).not.toContain('red-600')
    expect(content).not.toContain('red-500')
  })

  it('cart-item-row.tsx: nutzt text-error und ring-error Tokens', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain('text-error')
    expect(content).toContain('ring-error')
  })

  it('add-to-cart-button.tsx: keine hardcoded bg-green-600', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).not.toContain('bg-green-')
    expect(content).not.toContain('ring-green-')
  })

  it('add-to-cart-button.tsx: nutzt bg-success Token im Erfolgs-State', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toContain('bg-success')
  })

  it('product-card.tsx: keine hardcoded bg-black/50 oder bg-black/70', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).not.toContain('bg-black/')
  })

  it('product-card.tsx: nutzt bg-overlay Token für Sold-Out Overlay', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('bg-overlay')
  })

  it('cookie-consent-banner.tsx: keine hardcoded bg-white, nutzt bg-surface und bg-surface-elevated', () => {
    const content = readFile('components/layout/cookie-consent-banner.tsx')
    expect(content).not.toContain("'bg-white'")
    expect(content).not.toContain('"bg-white"')
    // Container nutzt bg-surface, Ablehnen-Button nutzt bg-surface-elevated
    expect(content).toContain('bg-surface')
    expect(content).toContain('bg-surface-elevated')
  })

  it('cart-page-client.tsx: nutzt color-mix() Fallback fuer Error-Hintergrund (kein bg-error/10)', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    // bg-error/10 funktioniert nicht mit var()-Indirection in Tailwind v4 (@theme hat --color-error: var(--theme-color-error))
    // Daher wird der sichere color-mix() Fallback verwendet
    expect(content).toContain('color-mix(in_oklch,var(--color-error)_10%,transparent)')
  })
})

// ─── AC-4: --theme-color-error in generated-theme.css ist direkter oklch()-Wert ──

describe('AC-4: --theme-color-error in generated-theme.css ist direkter oklch()-Wert (kein var())', () => {
  it('generated-theme.css: --theme-color-error ist direkter oklch()-Wert (kein var()-Verweis)', () => {
    // globals.css hat --color-error: var(--theme-color-error) (Indirektion gemaess Slice 1)
    // Der direkte oklch()-Wert liegt in generated-theme.css unter --theme-color-error
    // Dieser Test stellt sicher dass generated-theme.css den Token korrekt enthaelt
    const cssPath = 'app/generated-theme.css'
    const content = readFile(cssPath)
    const errorTokenMatch = content.match(/--theme-color-error\s*:\s*([^;]+);/)
    expect(errorTokenMatch).not.toBeNull()
    if (errorTokenMatch) {
      const tokenValue = errorTokenMatch[1].trim()
      // Der Wert darf kein var() sein — generated-theme.css enthaelt nur direkte oklch()-Werte
      expect(tokenValue).not.toMatch(/^var\(/)
      // Der Wert muss oklch() sein
      expect(tokenValue).toMatch(/^oklch\(/)
    }
  })
})

// ─── AC-2: Runtime-Theme vollständig entfernt ────────────────────────────────

describe('AC-2: Runtime-Theme vollständig entfernt', () => {
  it('theme-switcher.tsx ist gelöscht', () => {
    expect(fileExists('components/layout/theme-switcher.tsx')).toBe(false)
  })

  it('contexts/theme-context.tsx ist gelöscht', () => {
    expect(fileExists('contexts/theme-context.tsx')).toBe(false)
  })

  it('lib/theme/themes.ts ist gelöscht', () => {
    expect(fileExists('lib/theme/themes.ts')).toBe(false)
  })

  it('layout.tsx: kein ThemeProvider Import', () => {
    const content = readFile('app/layout.tsx')
    expect(content).not.toContain('ThemeProvider')
    expect(content).not.toContain('theme-context')
  })

  it('layout.tsx: kein dangerouslySetInnerHTML Theme-Script', () => {
    const content = readFile('app/layout.tsx')
    expect(content).not.toContain('dangerouslySetInnerHTML')
    expect(content).not.toContain('pod-theme')
    expect(content).not.toContain('dataset.theme')
  })

  it('header.tsx: kein ThemeSwitcher Import', () => {
    const content = readFile('components/layout/header.tsx')
    expect(content).not.toContain('ThemeSwitcher')
    expect(content).not.toContain('theme-switcher')
  })
})

// ─── AC-8: Font-Variables in layout.tsx aktiviert ───────────────────────────

describe('AC-8: Font-Loading via next/font in layout.tsx', () => {
  it('layout.tsx: importiert headingFont und bodyFont aus lib/theme/fonts', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('headingFont')
    expect(content).toContain('bodyFont')
    expect(content).toContain('lib/theme/fonts')
  })

  it('layout.tsx: Font-Variable-Klassen sind auf <html> gesetzt', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('headingFont.variable')
    expect(content).toContain('bodyFont.variable')
  })
})

// ─── AC-9: NEXT_PUBLIC_SHOP_NAME Env-Variable ───────────────────────────────

describe('AC-9: NEXT_PUBLIC_SHOP_NAME als Shop-Name Quelle', () => {
  it('layout.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Metadata-Titel', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('header.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Logo-Text', () => {
    const content = readFile('components/layout/header.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('footer.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Copyright', () => {
    const content = readFile('components/layout/footer.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('.env.local.example: enthält NEXT_PUBLIC_SHOP_NAME', () => {
    const content = readFile('.env.local.example')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })
})

// ─── AC-7: product-card-skeleton.tsx auf Skeleton migriert ──────────────────

describe('AC-7: ProductCardSkeleton auf shadcn/ui Skeleton migriert', () => {
  it('product-card-skeleton.tsx: importiert Skeleton aus components/ui/skeleton', () => {
    const content = readFile('components/product/product-card-skeleton.tsx')
    expect(content).toContain("from '@/components/ui/skeleton'")
  })

  it('product-card-skeleton.tsx: nutzt <Skeleton> Component (nicht inline animate-pulse Divs)', () => {
    const content = readFile('components/product/product-card-skeleton.tsx')
    expect(content).toContain('<Skeleton')
  })
})

// ─── AC-6: mobile-menu.tsx auf Sheet migriert ───────────────────────────────

describe('AC-6: MobileMenu auf shadcn/ui Sheet migriert', () => {
  it('mobile-menu.tsx: importiert Sheet aus components/ui/sheet', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain("from '@/components/ui/sheet'")
  })

  it('mobile-menu.tsx: nutzt SheetTrigger und SheetContent', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain('SheetTrigger')
    expect(content).toContain('SheetContent')
  })

  it('mobile-menu.tsx: kein manuelles isOpen useState für Drawer mehr', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    // State-Management übernimmt Radix UI Sheet
    expect(content).not.toContain("useState(false)")
  })

  it('mobile-menu.tsx: keine hardcoded bg-black/* mehr', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).not.toContain('bg-black/')
  })

  it('mobile-menu.tsx: side="left" SheetContent', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain('side="left"')
  })
})

// ─── Shadow-Token Migration ──────────────────────────────────────────────────

describe('Shadow-Token Migration: keine hardcoded shadow-md/shadow-xl mehr', () => {
  it('product-card.tsx: kein hardcoded hover:shadow-md', () => {
    const content = readFile('components/product/product-card.tsx')
    // hover:shadow-md wurde ersetzt durch hover:shadow-[var(--shadow-card-hover)]
    expect(content).not.toContain('hover:shadow-md')
  })

  it('product-card.tsx: nutzt shadow-card-hover Token', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('shadow-card-hover')
  })
})

// ─── Bestehende 151 Tests bleiben grün (Smoke-Check auf Datei-Existenz) ─────

describe('Bestehende Pod-Shop-MVP Tests — Datei-Existenz (Smoke)', () => {
  const existingTestFiles = [
    'tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts',
    'tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts',
    'tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts',
    'tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts',
    'tests/slices/pod-shop-mvp/slice-06-pinterest-tracking.test.ts',
    'tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts',
  ]

  for (const testFile of existingTestFiles) {
    it(`should have ${testFile}`, () => {
      expect(fileExists(testFile)).toBe(true)
    })
  }
})

// ─── Kern-Utility-Dateien nicht gelöscht ────────────────────────────────────

describe('Kern-Dateien existieren nach Migration', () => {
  const requiredFiles = [
    'app/layout.tsx',
    'app/page.tsx',
    'app/warenkorb/cart-page-client.tsx',
    'components/cart/cart-item-row.tsx',
    'components/cart/cart-summary.tsx',
    'components/cart/empty-cart.tsx',
    'components/cart/quantity-stepper.tsx',
    'components/product/product-card.tsx',
    'components/product/product-card-skeleton.tsx',
    'components/product/add-to-cart-button.tsx',
    'components/layout/header.tsx',
    'components/layout/footer.tsx',
    'components/layout/mobile-menu.tsx',
    'components/layout/cookie-consent-banner.tsx',
    'contexts/cart-context.tsx',
    'lib/theme/fonts.ts',
  ]

  for (const file of requiredFiles) {
    it(`should still have ${file}`, () => {
      expect(fileExists(file)).toBe(true)
    })
  }
})

// ─── Keine unerwünschten hardcoded shadow-* Utilities in Kern-Components ────

describe('Shadow-Utilities: keine shadow-sm/shadow-lg/shadow-xl in migrierten Components', () => {
  const componentsToCheck = [
    'components/product/product-card.tsx',
    'components/cart/cart-item-row.tsx',
    'components/cart/cart-summary.tsx',
    'components/cart/empty-cart.tsx',
    'components/cart/quantity-stepper.tsx',
    'components/layout/cookie-consent-banner.tsx',
    'components/layout/header.tsx',
    'components/layout/footer.tsx',
    'components/layout/mobile-menu.tsx',
    'app/warenkorb/cart-page-client.tsx',
  ]

  for (const componentPath of componentsToCheck) {
    it(`${componentPath}: keine hardcoded shadow-sm/shadow-md/shadow-lg/shadow-xl`, () => {
      const content = readFile(componentPath)
      // Erlaubt: shadow-[var(--shadow-*)], nicht erlaubt: shadow-sm, shadow-md, shadow-lg, shadow-xl als standalone Klasse
      expect(content).not.toMatch(/\bshadow-(sm|md|lg|xl)\b/)
    })
  }
})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [ ] Alle 18 Components auf Token-Klassen migriert — kein `bg-red-*`, `bg-green-*`, `bg-black/*`, `bg-white`
- [ ] Runtime ThemeProvider / ThemeSwitcher entfernt — 3 Dateien gelöscht
- [ ] Font-Klassen in `layout.tsx` aktiviert
- [ ] `NEXT_PUBLIC_SHOP_NAME` in allen 7 Stellen eingeführt
- [ ] `product-card-skeleton.tsx` auf `<Skeleton>` migriert
- [ ] `mobile-menu.tsx` auf shadcn/ui `Sheet` migriert
- [ ] `pnpm build` schlägt nicht fehl
- [ ] Alle 151 bestehenden Tests bleiben grün
- [ ] Neue Slice-3-Tests grün

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Nicht relevant — Migration ändert keine async Operationen
- [x] `bundle-dynamic-imports`: Sheet/Dialog sind bereits `'use client'` in Slice 2 — kein zusätzlicher Split nötig

**High Priority:**
- [x] `server-cache-react`: Nicht relevant — Migration betrifft keine Server-Fetch-Logik
- [x] `rerender-memo`: Nicht relevant — Migration ändert keine State-Logik

**Medium Priority:**
- [x] `rerender-derived-state-no-effect`: AddToCartButton nutzt `useEffect` für Timer-Reset — bestehendes Pattern bleibt erhalten (kein Refactoring-Scope)

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only Buttons haben `aria-label` — Mobile-Menu Hamburger-Button behält `aria-label="Menü öffnen"`; Sheet-Close hat `aria-label="Panel schließen"` (aus Slice 2)
- [x] Dekorative Icons haben `aria-hidden="true"` — alle SVG-Icons beibehalten
- [x] Focus-visible states für alle interaktiven Elemente — Token-Klassen `focus-visible:ring-primary` / `focus-visible:ring-error`
- [x] Form inputs haben Labels — Cart/Quantity Stepper Buttons haben aria-label

**Animation & Motion:**
- [x] `prefers-reduced-motion`: Cookie-Banner nutzt `motion-safe:animate-slide-up` — bleibt erhalten
- [x] Sheet (MobileMenu) nutzt tw-animate-css von Radix UI — respektiert `prefers-reduced-motion`

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf allen relevanten Buttons — bleibt erhalten
- [x] Touch targets mindestens 44px — `min-h-[44px]` auf Hamburger-Button bleibt erhalten

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Werte — alle migrierten Farben durch Token-Klassen ersetzt
- [x] CSS Custom Property Pattern: `shadow-[var(--shadow-card-hover)]`, `rounded-[var(--radius-card,0.75rem)]`
- [x] Semantic color naming — `bg-error`, `bg-success`, `bg-overlay`, `bg-surface`

**Build Tool Integration:**
- [x] Kein `tailwind.config.ts` nötig — CSS-first bleibt erhalten
- [x] Alle Token-Klassen kommen aus `@theme` Block (Slice 1)

---

## Constraints & Hinweise

**Betrifft:**
- Alle 18 Components + 4 Page-Dateien werden modifiziert — Big Bang, kein schrittweises Vorgehen
- 3 Dateien werden gelöscht: `theme-switcher.tsx`, `theme-context.tsx`, `lib/theme/themes.ts`

**API Contract:**
- `SheetTrigger` mit `asChild` prop: Das Kind-Element übernimmt alle Radix-Trigger-Attribute. Der `<button>` muss das einzige Kind sein.
- `NEXT_PUBLIC_SHOP_NAME` ist eine Server-Side-Variable (kein `NEXT_PUBLIC_` würde Client-Side nicht verfügbar sein). Sie muss in `.env.local` und Deployment-Env gesetzt sein.
- Error-Hintergrund im Fehlerbanner verwendet den sicheren `color-mix()` Fallback statt `bg-error/10` — see unten.

**Tailwind v4 Opacity-Modifier Constraint (`bg-error/10` NICHT verwenden):**
Tailwind v4 unterstützt Opacity-Modifier (`/10`) auf CSS-Variable-basierte Farb-Tokens nur wenn der Token als direkter `oklch()` Wert im `@theme` Block registriert ist — nicht als `var()` Referenz auf einen anderen Token. Gemaess dem genehmigten Slice-1-Deliverable definiert `globals.css` im `@theme` Block:

```css
--color-error: var(--theme-color-error);
```

Das ist eine `var()`-Weiterleitungs-Referenz. Tailwind v4 kann `color-mix()` nicht auf eine Variable anwenden, die selbst eine andere Variable referenziert. Deshalb DARF `bg-error/10` in diesem Projekt NICHT verwendet werden.

**Stattdessen wird der explizite `color-mix()` Fallback verwendet:**
```
[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)]
```

Dieser Arbitrary-Value funktioniert unabhaengig von der Token-Indirection-Tiefe, da `color-mix()` direkt im Browser-CSS evaluiert wird.

Der Unit-Test in der test_spec prueft in `app/generated-theme.css` ob `--theme-color-error` ein direkter `oklch()`-Wert (kein `var()`) ist — das ist die korrekte Datei fuer diesen Check, da `generated-theme.css` vom Build-Script generiert wird und immer direkte Werte enthaelt.

**Abgrenzung:**
- `quantity-stepper.tsx` wird auf shadcn/ui `Button` migriert (konsistenter Stil), aber die Stepper-Logik bleibt unverändert
- `cart-summary.tsx` wird auf shadcn/ui `Button` migriert für den "Zur Kasse"-Button
- `empty-cart.tsx` Link-Button wird auf `<Button asChild><Link>...</Link></Button>` Pattern umgestellt
- Font-Loading (`headingFont.variable` in `layout.tsx`) war Deliverable von Slice 2 — dieser Slice aktiviert es in `layout.tsx` (falls Slice 2 es noch nicht getan hat, wird es hier definitiv gesetzt)
- Bestehende Tests aus `pod-shop-mvp/` testen Business-Logik — sie sind nicht von UI-Klassen-Änderungen betroffen und brauchen keine Anpassung

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-theme-token-system | `--color-error`, `--color-success`, `--color-overlay` | CSS Custom Properties in `@theme` | Als Tailwind-Klassen verfügbar: `bg-error`, `bg-success`, `bg-overlay`, `text-error`, `ring-error` |
| slice-01-theme-token-system | `--shadow-card`, `--shadow-card-hover` | CSS Custom Properties in `@theme` | Als inline CSS-Variable: `shadow-[var(--shadow-card)]`, `shadow-[var(--shadow-card-hover)]` |
| slice-01-theme-token-system | `--radius-card`, `--radius-button` | CSS Custom Properties in `@theme` | Als inline CSS-Variable: `rounded-[var(--radius-card,0.75rem)]` |
| slice-01-theme-token-system | `cn()` in `lib/utils.ts` | Function | `(...inputs: ClassValue[]) => string` |
| slice-02-shadcn-ui-primitives | `Sheet`, `SheetTrigger`, `SheetContent` | Components | `side="left"` Prop verfügbar, in `mobile-menu.tsx` verwendet |
| slice-02-shadcn-ui-primitives | `Button` | Component | `variant`, `size`, `asChild`, `isLoading` Props; in `cart-summary.tsx`, `empty-cart.tsx`, `quantity-stepper.tsx` |
| slice-02-shadcn-ui-primitives | `Skeleton` | Component | `className` Prop für Sizing; in `product-card-skeleton.tsx` |
| slice-02-shadcn-ui-primitives | `headingFont`, `bodyFont` | next/font Instanzen | `.variable` Prop für `<html>` className in `layout.tsx` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| Migrierte `ProductCard` | Component | slice-04 (ProductGridBlock) | Props: `{ product: ProductCardData }` — unverändert; nutzt jetzt `bg-overlay` Token |
| Migrierte `ProductCardSkeleton` | Component | slice-04 (ProductGridBlock Loading State) | Keine Props; nutzt jetzt shadcn/ui `Skeleton` intern |
| Bereinigter `layout.tsx` | RSC | slice-04 (Blocks werden in layout.tsx-Kontext gerendert) | Kein ThemeProvider mehr im Provider-Stack |
| `NEXT_PUBLIC_SHOP_NAME` | Env-Variable | slice-04 (Block-System kann Shop-Name nutzen), slice-05 (Multi-Shop) | `process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'` |

### Integration Validation Tasks

- [ ] `text-error`, `border-error`, `ring-error`, `bg-success`, `bg-overlay` als Tailwind-Klassen verfügbar (Slice 1 `@theme` Block hat Token registriert). Hinweis: `bg-error/10` NICHT verwenden — stattdessen `color-mix()` Fallback (siehe Constraints-Section)
- [ ] `bg-success` als Tailwind-Klasse verfügbar (Slice 1 `@theme` Block hat `--color-success` registriert)
- [ ] `bg-overlay` als Tailwind-Klasse verfügbar (Slice 1 `@theme` Block hat `--color-overlay` registriert)
- [ ] `Sheet` Component mit `side="left"` korrekt von Slice 2 bereitgestellt
- [ ] `Skeleton` Component aus Slice 2 importierbar in `product-card-skeleton.tsx`
- [ ] `headingFont.variable` und `bodyFont.variable` aus `lib/theme/fonts.ts` (Slice 2 Deliverable) nutzbar in `layout.tsx`
- [ ] Keine TypeScript-Fehler durch gelöschte Theme-Dateien (`theme-context.tsx`, `themes.ts`)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.
> Abweichung nur mit expliziter Begründung im Commit erlaubt.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `cart-page-client.tsx` Error Alert Block | Abschnitt 6 | YES | `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)]` border-error text-error statt bg-red-50 (kein bg-error/10 wegen var()-Indirection) |
| `add-to-cart-button.tsx` Erfolgs-State | Abschnitt 7 | YES | bg-success statt bg-green-600 |
| `product-card.tsx` Sold-Out Overlay | Abschnitt 8 | YES | bg-overlay statt bg-black/50; shadow-[var(--shadow-card-hover)] |
| `product-card-skeleton.tsx` | Abschnitt 9 | YES | Skeleton Component aus ui/skeleton |
| `mobile-menu.tsx` | Abschnitt 10 | YES | Sheet side="left", SheetTrigger asChild, kein useState(false) |
| `cookie-consent-banner.tsx` | Abschnitt 11 | YES | bg-surface statt bg-white; bg-surface-elevated für Ablehnen-Button |
| `layout.tsx` | Abschnitt 12 | YES | ThemeProvider entfernt; headingFont.variable bodyFont.variable auf html |
| `header.tsx` | Abschnitt 13 | YES | ThemeSwitcher entfernt; NEXT_PUBLIC_SHOP_NAME |
| `.env.local.example` NEXT_PUBLIC_SHOP_NAME | Abschnitt 14 | YES | Neuer Eintrag mit NEXT_PUBLIC_SHOP_NAME=POD Shop |

---

## Links

- Design/Spec: `specs/phase-0/2026-02-21-frontend-theming/architecture.md`
- Discovery: `specs/phase-0/2026-02-21-frontend-theming/discovery.md`
- Dependency Slice 1: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-01-theme-token-system.md`
- Dependency Slice 2: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-02-shadcn-ui-primitives.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend — Modifizierte Dateien
- [ ] `frontend/app/layout.tsx` — MODIFIZIERT: ThemeProvider + Theme-Script entfernt; headingFont.variable + bodyFont.variable auf <html>; NEXT_PUBLIC_SHOP_NAME für Metadata-Titel
- [ ] `frontend/app/page.tsx` — MODIFIZIERT: "POD Shop" hardcoded → process.env.NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/app/warenkorb/page.tsx` — MODIFIZIERT: Metadata-Titel nutzt NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/app/warenkorb/cart-page-client.tsx` — MODIFIZIERT: bg-red-*/border-red-*/text-red-* → `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)]`, border-error, text-error (kein bg-error/10 wegen var()-Indirection in Slice 1)
- [ ] `frontend/app/kategorie/[slug]/page.tsx` — MODIFIZIERT: Metadata-Titel nutzt NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/app/produkt/[slug]/page.tsx` — MODIFIZIERT: Metadata-Titel nutzt NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/components/layout/header.tsx` — MODIFIZIERT: ThemeSwitcher entfernt; "POD Shop" → NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/components/layout/footer.tsx` — MODIFIZIERT: "POD Shop" → NEXT_PUBLIC_SHOP_NAME
- [ ] `frontend/components/layout/mobile-menu.tsx` — MODIFIZIERT: Custom Drawer ersetzt durch Sheet side="left"; bg-black/40 → bg-overlay (via Sheet); kein useState für isOpen
- [ ] `frontend/components/layout/cookie-consent-banner.tsx` — MODIFIZIERT: bg-white → bg-surface; Ablehnen-Button bg-surface-elevated; shadow-[var(--shadow-card-hover)]
- [ ] `frontend/components/product/product-card.tsx` — MODIFIZIERT: bg-black/50 und bg-black/70 → bg-overlay; hover:shadow-md → hover:shadow-[var(--shadow-card-hover)]
- [ ] `frontend/components/product/product-card-skeleton.tsx` — MODIFIZIERT: auf shadcn/ui Skeleton Component migriert
- [ ] `frontend/components/product/add-to-cart-button.tsx` — MODIFIZIERT: bg-green-600 → bg-success; ring-green-500 → ring-success
- [ ] `frontend/components/cart/cart-item-row.tsx` — MODIFIZIERT: hover:text-red-600 → hover:text-error; ring-red-500 → ring-error
- [ ] `frontend/components/cart/cart-summary.tsx` — MODIFIZIERT: Zur-Kasse-Button auf shadcn/ui Button Primitive migriert
- [ ] `frontend/components/cart/empty-cart.tsx` — MODIFIZIERT: Link-Button auf Button asChild Pattern migriert
- [ ] `frontend/components/cart/quantity-stepper.tsx` — MODIFIZIERT: Stepper-Buttons auf shadcn/ui Button Primitive migriert
- [ ] `frontend/.env.local.example` — MODIFIZIERT: NEXT_PUBLIC_SHOP_NAME=POD Shop hinzugefügt

### Frontend — Gelöschte Dateien
- [ ] `frontend/components/layout/theme-switcher.tsx` — GELÖSCHT: Runtime-Theme-Switching entfernt
- [ ] `frontend/contexts/theme-context.tsx` — GELÖSCHT: ThemeProvider und useTheme entfernt
- [ ] `frontend/lib/theme/themes.ts` — GELÖSCHT: THEMES Array und ThemeId Typ entfernt

### Tests
- [ ] `frontend/tests/slices/frontend-theming/slice-03-component-migration.test.ts` — Unit Tests: Datei-Inhalt-Assertions für alle migrierten Components (keine hardcoded Farben), gelöschte Dateien, Font-Loading, NEXT_PUBLIC_SHOP_NAME, MobileMenu Sheet-Migration, ProductCardSkeleton Migration
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- "GELÖSCHT" bedeutet: Die Datei muss nach der Migration nicht mehr existieren — `existsSync()` gibt `false` zurück
- Die Migration ist eine atomare Änderung — alle 18 Components müssen in einem Durchgang migriert werden
- Zuerst alle Imports der zu löschenden Dateien entfernen, DANN die Dateien löschen
- `pnpm test` muss nach der Migration alle 151 bestehenden Tests PLUS neue Slice-3-Tests grün zeigen
