# Architecture: Component Primitive Migration + Storybook (P0.3)

**Epic:** Follows `2026-02-21-frontend-theming` (P0.2)
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- 15 UI-Patterns in 13 Dateien implementieren eigene Button/Card/Badge-Logik statt die vorhandenen shadcn/ui Primitives zu verwenden
- Duplizierte Styling-Logik: Focus-Ring, Hover-States, Disabled-States, Loading-States werden pro Component individuell implementiert
- Inkonsistente Sizes: Buttons haben unterschiedliche Padding/Height-Werte (py-2, py-2.5, py-3, py-3.5)
- Accessibility nicht einheitlich: Manche Buttons haben `aria-label`, manche nicht; `focus-visible:ring` Implementierung variiert
- Kein Storybook — Components können nicht isoliert entwickelt, getestet oder visuell verglichen werden

**Solution:**
- Composition Pattern: Custom Components aus shadcn/ui Primitives zusammenbauen statt eigene Patterns
- Alle 15 identifizierten Patterns auf shadcn/ui Primitives umstellen
- Accessibility-Audit pro Component: ARIA Labels, Keyboard Navigation, Focus Management
- Storybook 10 Setup mit Stories für alle Components

**Business Value:**
- Konsistente UI: Alle Buttons/Cards/Badges folgen demselben Design-System
- Weniger Code: Redundante Styling-Klassen entfallen
- Accessibility-Garantie: Radix UI Primitives liefern ARIA-Rollen und Keyboard-Navigation out-of-box
- Wartbarkeit: Design-Änderungen wirken automatisch auf alle Components
- Component-Katalog: Storybook als visuelle Dokumentation

---

## Scope & Boundaries

| In Scope |
|----------|
| Button Composition (11 Patterns): add-to-cart, checkout, quantity-stepper ×2, remove-item, size-selector, hamburger, error-close, gallery-thumbnails |
| Button-als-Link Composition (4 Patterns): hero CTA, empty-cart CTA, filter-chips aktiv/inaktiv |
| Card Composition (5 Patterns): product-card, cart-item-row, cart-summary, category-showcase, produkt-beschreibung |
| Badge Composition (1 Pattern): cart-icon counter |
| Accessibility-Audit: ARIA Labels, `role` Attribute, Keyboard Navigation pro Component |
| Vitest Tests: Variant-Rendering, Disabled-State, ARIA-Attribute |
| Anpassung bestehender Tests an neue Component-Struktur |
| Storybook 10 Setup mit `@storybook/nextjs-vite` Framework-Integration |
| Stories für alle Components (Primitives + Layout + Product + Cart + Category + Blocks) |

| Out of Scope |
|--------------|
| `variant-selector-color.tsx` — Runde 40x40px Color-Swatches mit dynamischem `style={{backgroundColor}}` + SVG-Strikethrough |
| Gallery-Dot-Buttons (`product-image-gallery.tsx` Dots) — 10px, `role="tab"`, zu speziell für `<Button>` |
| Footer-Links — semantisch Navigations-Links, keine Aktions-Buttons |
| `header.tsx` — Layout-Container ohne Primitive-Patterns |
| `usp-bar-block.tsx` — Simple `<ul>/<li>` mit Icons |
| Neue shadcn/ui Variants oder Primitives |
| Verhaltensänderungen — rein visuelles Refactoring |
| Storybook Interaction Tests (play functions) |
| Chromatic oder Visual Regression Testing |

---

## API Design

N/A — Reines Frontend-Refactoring. Keine API-Änderungen.

---

## Database Schema

N/A — Keine Datenbank-Änderungen.

---

## Server Logic

N/A — Keine Server-Logik betroffen. RSC-Rendering bleibt identisch, nur JSX-Output ändert sich (native `<button>` → `<Button>`, native `<div>` → `<Card>`).

---

## Security

### Input Validation & Sanitization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Component Props | TypeScript strict mode | Keine Runtime-Validation nötig — Component-Props bleiben identisch |
| dangerouslySetInnerHTML | Unverändert | `produkt/[slug]/page.tsx` Beschreibung bleibt identisch — kommt von WordPress, kein User-Input |

### Accessibility als Security-Layer

| Area | Mechanism | Notes |
|------|-----------|-------|
| Button ARIA | Radix UI Primitives | `aria-disabled`, `aria-pressed` automatisch via Props |
| Focus Trapping | Radix Dialog/Sheet | Bereits vorhanden in mobile-menu.tsx (Sheet) |
| Keyboard Navigation | Native `<button>` via Radix Slot | Enter/Space Activation, Tab-Order unverändert |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| `components/ui/` | shadcn/ui Primitives (Button, Card, Badge, etc.) | CVA Variants + Radix UI Primitives — **unverändert** |
| `components/{domain}/` | Composed Components (ProductCard, CartSummary, etc.) | Composition Pattern: Primitives als Bausteine + domain-spezifische Logik |
| `components/blocks/` | Block Components (Hero, CategoryShowcase, etc.) | BlockComponentProps Interface + Primitive Composition |
| `components/layout/` | Layout Components (MobileMenu, CartIcon, etc.) | Radix Sheet + Button Primitives |
| `app/` | Pages (Produkt, Warenkorb, Kategorie) | RSC Pages nutzen Composed Components |
| `.storybook/` | Storybook Config | `@storybook/nextjs-vite` Framework + Tailwind v4 via globals.css |
| `stories/` | Story Files | Co-located oder dedizierter Ordner — Stories pro Component |

### Data Flow (unverändert)

```
YAML page config → page-config.ts → registry.ts → data-loaders.ts → Block Component
                                                                        ↓
                                                              shadcn/ui Primitives (Button, Card, Badge)
                                                                        ↓
                                                              HTML Output (mit data-slot Attributen)
```

### Composition Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Domain Components (components/{domain}/)                  │
│                                                            │
│  ProductCard = Card(interactive) + CardContent + Image     │
│  CartItemRow = Card + CardContent + Button(ghost/icon)     │
│  CartSummary = Card + CardContent + CardFooter + Button    │
│  AddToCartButton = Button(default) + isLoading + className │
│  QuantityStepper = Button(outline/icon) ×2                 │
│  FilterChips = Button(default/secondary, asChild) + Link   │
│  CartIcon = Link + Badge                                   │
│  HeroBlock = Button(default, asChild) + Link               │
│  EmptyCart = Button(default, asChild) + Link                │
├──────────────────────────────────────────────────────────┤
│  UI Primitives (components/ui/)  — UNVERÄNDERT             │
│                                                            │
│  Button (6 Variants, 4 Sizes, isLoading, asChild)          │
│  Card (default, interactive) + Header/Title/Content/Footer │
│  Badge (4 Variants)                                        │
│  Skeleton, Dialog, Sheet, Input                            │
└──────────────────────────────────────────────────────────┘
```

### Error Handling Strategy

| Error Type | Handling | Notes |
|------------|----------|-------|
| Test-Bruch wegen DOM-Änderung | Test-Selector anpassen (data-testid, role, data-slot) | Erwarteter Effekt der Migration |
| `asChild` Pattern bricht mit verschachteltem Link | Radix Slot debuggen — nur 1 Child erlaubt | Bekanntes Radix-Pattern, dokumentiert |
| Build-Fehler nach Migration | `pnpm build` als Gate — alle Components müssen compilieren | CI-Gate |

---

## Migration Map

> PFLICHT: Scope enthält Migration. Jede betroffene Datei = eine Zeile.

### Slice 1: Component Primitive Composition

| Existing File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `frontend/package.json` | `@radix-ui/react-slot: "latest"`, `@radix-ui/react-dialog: "latest"`, `tw-animate-css: "latest"` | Gepinnte Versionen: `^1.2.4`, `^1.1.15`, resolved Version | Pin alle 3 `"latest"` Dependencies auf aktuell installierte Versionen. Verhindert Breaking Changes bei `pnpm install`. Erster Schritt vor jeder Component-Migration. |
| `components/product/add-to-cart-button.tsx` | Custom `<button>` mit className-Array, eigener Focus-Ring, eigener Loading-Spinner, eigener Disabled-State | `<Button>` mit `isLoading`, `variant="default"`, Success via `cn()` className | Import Button; ersetze `<button>` durch `<Button>`; entferne redundante Focus/Disabled/Touch-Styles; behalte Success-State als conditional className (`bg-success` via `cn()`); behalte `aria-live="polite"` |
| `components/cart/cart-summary.tsx` | Custom `<button>` mit `bg-primary`, eigener Focus-Ring, inline `minHeight` Style | `<Button>` mit `variant="default"`, gesamte Summary als `<Card>` + `<CardContent>` + `<CardFooter>` | Import Button, Card, CardContent, CardFooter; wrape Summary in Card; verschiebe Button + Disclaimer in CardFooter; ersetze `<button>` durch `<Button>`; entferne redundante Styles; füge `aria-label="Zur Kasse"` hinzu |
| `components/cart/quantity-stepper.tsx` | 2× custom `<button>` mit `w-8 h-8 border`, eigener Focus-Ring, inline `minWidth/minHeight` Style | 2× `<Button variant="outline" size="icon">` mit custom `className="w-8 h-8"` | Import Button; ersetze beide `<button>` durch `<Button variant="outline" size="icon">`; überschreibe Size via className (`w-8 h-8` statt default 44px); entferne redundante Border/Focus/Disabled-Styles; behalte `aria-label` |
| `components/cart/cart-item-row.tsx` | Outer `<div>` mit `border border-border rounded-lg`, inner Remove-`<button>` mit custom hover/focus | Outer `<Card>` + `<CardContent>`, Remove-Button als `<Button variant="ghost" size="icon">` | Import Card, CardContent, Button; ersetze outer div durch `<Card><CardContent className="flex gap-4 p-4">`; ersetze Remove-button durch `<Button variant="ghost" size="icon">`; entferne redundante Styles |
| `components/product/variant-selector-size.tsx` | Custom `<button>` mit className-Array, eigener Focus-Ring, conditional Selected/Unselected/Disabled Styles | `<Button>` mit `variant="default"` (selected) / `variant="outline"` (unselected), `aria-pressed` | Import Button; ersetze `<button>` durch `<Button variant={isSelected ? "default" : "outline"}>`; entferne redundante Focus/Border-Styles; behalte disabled Logic für unavailable; `aria-pressed` bereits vorhanden |
| `components/layout/mobile-menu.tsx` | Custom `<button>` als SheetTrigger mit eigener Focus-Ring und min-h/min-w Styles | `<Button variant="ghost" size="icon">` als SheetTrigger Child | Import Button; ersetze `<button>` durch `<Button variant="ghost" size="icon">`; entferne redundante min-h/min-w/focus Styles; behalte `aria-label="Menü öffnen"` |
| `app/warenkorb/cart-page-client.tsx` | Custom `<button>` für Error-Close mit `text-error hover:text-error` | `<Button variant="ghost" size="icon">` mit custom className für error-Farbe | Import Button; ersetze `<button>` durch `<Button variant="ghost" size="icon" className="text-error hover:text-error">`; entferne redundante Styles; behalte `aria-label="Fehlermeldung schliessen"` |
| `components/product/product-image-gallery.tsx` | Desktop-Thumbnails: custom `<button>` mit `w-18 h-18 border-2`, conditional Selected/Unselected Styles | Thumbnails: `<Button variant="ghost" size="icon" asChild>` mit custom className für 72px Size und Border-Logic | Import Button; ersetze Thumbnail-`<button>` durch `<Button variant="ghost" size="icon" className="w-18 h-18 ...">`; behalte conditional border/shadow Styles via `cn()`; behalte `role="tab"` + `aria-selected`; **Dots bleiben unverändert** (Out of Scope) |
| `components/blocks/hero-block.tsx` | `<Link>` mit button-artigen Classes (`bg-primary`, `rounded-[var(--radius-button)]`, Focus-Ring) | `<Button asChild><Link>` — Button-Styling vom Primitive, Link-Semantik bleibt | Import Button; wrape `<Link>` in `<Button asChild>`; entferne redundante bg/rounded/focus/touch Styles; behalte `href` und Text |
| `components/cart/empty-cart.tsx` | `<Link>` mit `bg-primary rounded-lg` und eigenen Focus-Styles | `<Button asChild><Link>` mit `variant="default"` | Import Button; wrape `<Link>` in `<Button asChild>`; entferne redundante Styles; entferne inline `touchAction` Style |
| `components/category/filter-chips.tsx` | `<Link>` mit conditional className-Array (`bg-text-primary` aktiv / `bg-surface-elevated` inaktiv), inline Focus-Ring | Aktiv: `<Button asChild className="rounded-full ..."><Link>`. Inaktiv: `<Button variant="secondary" asChild className="rounded-full ..."><Link>` | Import Button; wrape jede `<Link>` in `<Button asChild>` mit conditional Variant; überschreibe `rounded-full` via className (Button default ist `rounded-button`); behalte `aria-current="page"`; entferne redundante Focus/Touch Styles |
| `components/product/product-card.tsx` | `<Link>` mit eigenen `rounded-[var(--radius-card)]`, `border`, `hover:shadow`, `hover:-translate-y-0.5` Styles | `<Card variant="interactive" asChild><Link>` — Card-Primitive liefert alle Container-Styles | Import Card, CardContent; ersetze outer `<Link className="...">` durch `<Card variant="interactive" asChild><Link>`; verschiebe Produktinfos in `<CardContent className="p-3">`; entferne redundante border/shadow/rounded/hover Styles |
| `components/blocks/category-showcase-block.tsx` | `<Link>` mit `p-4 rounded-xl border bg-surface-elevated` Styles | `<Card variant="interactive" asChild><Link>` — Card liefert Container-Styles | Import Card; ersetze `<Link className="flex items-center gap-4 p-4 rounded-xl border ...">` durch `<Card variant="interactive" asChild><Link className="flex items-center gap-4 p-4">`; entferne redundante border/rounded Styles; behalte `touchAction` |
| `app/produkt/[slug]/page.tsx` | Beschreibungs-`<section>` mit `bg-[var(--color-surface-elevated)]`, inline `boxShadow`, `rounded-[var(--radius-card)]` | `<Card>` + `<CardHeader>` + `<CardTitle>` + `<CardContent>` | Import Card, CardHeader, CardTitle, CardContent; ersetze `<section>` durch `<Card>`; ersetze `<h2>` durch `<CardHeader><CardTitle>`; wrape prose-div in `<CardContent>`; entferne redundante bg/shadow/rounded Styles |
| `components/layout/cart-icon.tsx` | `<span>` mit `bg-primary rounded-full` + eigene Sizing/Positioning | `<Badge>` mit `variant="default"` + custom className für Positioning/Sizing | Import Badge; ersetze `<span>` Badge durch `<Badge className="absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-1 ...">`; entferne redundante bg/rounded Styles; behalte conditional Rendering (`itemCount > 0`) |

### Slice 2: Storybook Setup + Stories

| Existing/New File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `frontend/.storybook/main.ts` | **NEU** | Storybook 10 Config mit `@storybook/nextjs-vite` | Framework: `@storybook/nextjs-vite`; stories glob: `../stories/**/*.stories.@(ts|tsx)`; addons: `@storybook/addon-essentials` |
| `frontend/.storybook/preview.ts` | **NEU** | Preview Config mit globals.css Import + Tailwind v4 | `import '../app/globals.css'`; `import '../app/generated-theme.css'`; Default viewport, backgrounds |
| `frontend/stories/ui/` | **NEU** | Stories für 7 shadcn/ui Primitives | Button (6 Variants × 4 Sizes × States), Card (2 Variants), Input, Badge (4 Variants), Skeleton, Dialog, Sheet |
| `frontend/stories/layout/` | **NEU** | Stories für Layout Components | Header, Footer, MobileMenu, CartIcon, CookieConsentBanner |
| `frontend/stories/product/` | **NEU** | Stories für Product Components | ProductCard, ProductCardSkeleton, AddToCartButton, ImageGallery, VariantSelectorSize, VariantSelectorColor |
| `frontend/stories/cart/` | **NEU** | Stories für Cart Components | CartItemRow, CartSummary, QuantityStepper, EmptyCart |
| `frontend/stories/category/` | **NEU** | Stories für Category Component | FilterChips |
| `frontend/stories/blocks/` | **NEU** | Stories für Block Components | HeroBlock, ProductGridBlock, CategoryShowcaseBlock, UspBarBlock |
| `frontend/package.json` | Kein Storybook | Storybook 10 Dependencies + Scripts | `storybook`, `@storybook/nextjs-vite`, `@storybook/addon-essentials`, `@storybook/react`; Scripts: `storybook`, `build-storybook` |

---

## Constraints & Integrations

### Constraints

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| Visuell identisch nach Migration | Jedes Primitive muss exakt gleichen Output erzeugen wie Custom-Code | Button CVA Variants matchen bereits: `bg-primary text-white`, `border border-border`, `ghost`. Custom className für Abweichungen (QuantityStepper 32px, Gallery Thumbnails 72px) |
| 44px Touch Targets | shadcn/ui Button `size="default"` = 44px, `size="icon"` = 44px via `--min-touch-target` | QuantityStepper Buttons sind 32px — überschreibe via className `w-8 h-8` (physisch in Stepper-Group, Touch-Target über Padding ausreichend) |
| Filter-Chips: `rounded-full` statt `rounded-button` | Button-Primitive hat `rounded-[var(--radius-button)]` | className Override: `className="rounded-full"` — tailwind-merge von `cn()` überschreibt CVA-Base |
| `asChild` Single-Child | Radix Slot akzeptiert nur exakt 1 Kind-Element | Jede `<Button asChild>` Stelle hat genau 1 `<Link>` als Child — verifiziert in Codebase |
| Product Card: `group` CSS Klasse | Image-Zoom nutzt `group-hover:scale-105` | `<Card asChild>` rendert `<Link>` mit `className` — `group` muss via className auf Card/Link bleiben |
| Cart Badge Sizing | Aktuelles Badge ist `min-w-[1.125rem] h-[1.125rem]` — Badge-Primitive hat `px-2.5 py-0.5` | className Override für kompakte Sizing: überschreibe Padding/Size via className |
| Dependency Pinning | 3 Packages (`@radix-ui/react-slot`, `@radix-ui/react-dialog`, `tw-animate-css`) nutzen `"latest"` in package.json | Vor Migration pinnen auf aktuell installierte Versionen: `^1.2.4`, `^1.1.15`, resolved Version von tw-animate-css. Verhindert Breaking Changes durch unerwartete Major-Updates. |
| Storybook ESM-Only (v10) | Node.js 20.16+ required | Projekt nutzt Node.js 22.x — kompatibel |
| Storybook + Tailwind v4 | PostCSS-basiert, kein Plugin nötig | `globals.css` + `generated-theme.css` in preview.ts importieren — PostCSS wird automatisch verarbeitet |

### Integrations

| Area | System / Capability | Interface | Version | Notes |
|------|----------------------|-----------|---------|-------|
| UI Primitives | shadcn/ui (Button, Card, Badge) | React Components + CVA | Keine eigene Version (Copy-Paste Pattern) | Bereits in `components/ui/` vorhanden, unverändert |
| Slot Pattern | @radix-ui/react-slot | `asChild` Prop | ^1.2.4 (resolved from "latest" in package.json — **muss gepinnt werden**) | Bereits installiert, für `<Button asChild>` + `<Card asChild>` |
| Dialog/Sheet | @radix-ui/react-dialog | Sheet + Dialog Components | ^1.1.15 (resolved from "latest" in package.json — **muss gepinnt werden**) | Bereits installiert und genutzt (mobile-menu.tsx) |
| Variant Styling | class-variance-authority | `cva()` + `VariantProps` | ^0.7.0 (package.json) | Bereits installiert, nutzt shadcn/ui Primitives |
| Class Merging | tailwind-merge + clsx | `cn()` Utility | tailwind-merge ^3.5.0, clsx ^2.0.0 | Bereits installiert — kritisch für className Overrides |
| Storybook Core | storybook | CLI + Build | 10.2.x (recherchiert via npm Feb 2026, aktuell stabil) | NEU — Day-One Next.js 16 Support |
| Storybook Framework | @storybook/nextjs-vite | Vite-basiert, Next.js App Router Support | 10.2.x (recherchiert via npm Feb 2026) | NEU — empfohlen über Webpack-Variante |
| Storybook React | @storybook/react | React 19 Renderer | 10.2.x | NEU — React 19 Support |
| Storybook Addon | @storybook/addon-essentials | Controls, Actions, Viewport, Backgrounds | 10.2.x | NEU — Minimales Addon-Set |
| Frontend Framework | Next.js | App Router, RSC | ^16.1.6 (package.json) | Unverändert |
| CSS Framework | Tailwind CSS | v4 CSS-first Config | ^4.0.0 (package.json) | Unverändert — Storybook nutzt PostCSS automatisch |
| Testing | Vitest | Unit Tests | ^3.0.0 (package.json) | Unverändert — bestehende + neue Tests |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Visual Regression | 0 sichtbare Änderungen nach Migration | shadcn/ui Variants matchen existierende Styles; className Overrides für Abweichungen | Manueller visueller Vergleich aller Seiten; Storybook Stories für Side-by-Side |
| Test Stability | Alle 151+ Tests grün nach Migration | DOM-Selektoren anpassen (data-slot, role statt className); keine Verhaltensänderungen | `pnpm test` — alle Tests grün |
| Build Success | `pnpm build` erfolgreich | TypeScript strict mode fängt fehlende Props/Imports | `pnpm build` als Gate |
| Accessibility | WCAG 2.1 AA für alle interaktiven Elemente | Radix UI liefert: `aria-disabled`, Focus-Management, Keyboard-Navigation; Manuell ergänzen: `aria-pressed` (Toggle-Buttons), `aria-current` (Filter-Chips), `aria-label` (Icon-Buttons) | axe-core oder manuelle Prüfung pro Component |
| Bundle Size | Keine signifikante Vergrößerung | shadcn/ui Primitives sind bereits im Bundle (Copy-Paste, kein neues Package); Storybook nur in devDependencies | `pnpm build` Bundle-Size vergleichen |
| Storybook Startup | < 10s auf Dev-Machine | `@storybook/nextjs-vite` (Vite-basiert, schneller als Webpack) | `pnpm storybook` messen |
| Component Isolation | Jede Story rendert ohne App-Context | Mock-Daten für GraphQL-abhängige Components; CartContext Mock für Cart-Components | Jede Story rendert fehlerfrei |

### Monitoring & Observability

N/A — Kein Runtime-Monitoring nötig (einmalige Migration, kein laufender Service).

---

## Risks & Assumptions

### Assumptions

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| shadcn/ui Button CVA Variants matchen existierende Styles visuell | Verglichen: `bg-primary text-white` (default), `border border-border` (outline), `bg-transparent` (ghost) — identisch mit Custom-Code | Sichtbare UI-Änderungen → className Override pro Component nötig |
| `cn()` (tailwind-merge) überschreibt CVA-Base-Styles korrekt | tailwind-merge ^3.5.0 dokumentiert: spätere Klassen überschreiben frühere (z.B. `rounded-full` überschreibt `rounded-button`) | Filter-Chips oder andere Override-Stellen rendern falsch → explizite Styles statt Merge |
| Card `asChild` rendert `<Link>` korrekt als Card-Container | Card nutzt `<div>` ohne Radix Slot — aktuell kein `asChild` Support in Card-Primitive | Card muss um `asChild` erweitert werden (Slot Import + Comp-Pattern wie in Button) |
| Storybook 10 + Tailwind v4 + PostCSS funktioniert out-of-box | Storybook Docs bestätigen: PostCSS wird automatisch erkannt | Manuelles PostCSS-Setup in `.storybook/main.ts` nötig |
| Storybook Mock-Daten reichen für alle Stories | Components nutzen Props, nicht direkte GraphQL-Calls — Mock-Daten über Story-Args | Cart-Components brauchen CartContext-Mock → Decorator in preview.ts |
| Radix @radix-ui/react-slot "latest" in package.json resolved auf kompatible Version | **Wird in Slice 1 gefixt:** Pin auf ^1.2.4 (aktuell installiert). Gleiches für @radix-ui/react-dialog (^1.1.15) und tw-animate-css. | Falls Breaking Change vor Pinning: `pnpm install --frozen-lockfile` nutzen |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| Card hat kein `asChild` Support | High | Medium | Card-Primitive erweitern: Slot Import + `asChild` Prop (analog zu Button-Implementation) | Manuell `<Link>` in `<Card>` wrappen statt `asChild` — extra `<div>` im DOM |
| QuantityStepper 32px Buttons verletzen 44px Touch-Target Regel | Medium | Low | QuantityStepper-Buttons sind physisch 32px aber in einer Group — kombiniertes Touch-Target > 44px. Alternativ: unsichtbares Padding auf 44px erweitern | 32px belassen — Stepper-Pattern ist Industrie-Standard in kompakter Form |
| Filter-Chips `rounded-full` Override funktioniert nicht mit tailwind-merge | Low | Medium | Getestet: `cn(buttonVariants({...}), "rounded-full")` — tailwind-merge erkennt `rounded-*` Conflict und nutzt letzten Wert | Explizite `className` ohne CVA-Base für Filter-Chips |
| Storybook 10 Breaking Changes (ESM-Only) | Low | Medium | Node.js 22.x bereits ESM-kompatibel; `package.json` hat kein `"type": "module"` — Storybook CLI handled dies | Storybook 8.x als Fallback (ältere aber stabile Version) |
| Bestehende Tests brechen wegen geänderter DOM-Struktur | High | Low | Erwarteter Effekt — Tests systematisch anpassen: `data-slot="button"` statt className-Matcher, `role` statt Tag-Selektoren | Test-Selektoren einzeln fixen — kein Risiko für Produktion |
| Gallery Thumbnail `<Button asChild>` mit `<button>` + `<Image>` Verschachtelung | Medium | Low | `asChild` rendert Child direkt — `<Image>` muss in `<button>` bleiben, nicht umgekehrt. Alternative: Button ohne `asChild`, Image als Child | Thumbnail-Buttons als reguläre `<Button>` mit `<Image>` als Child (ohne asChild) |
| CartContext-Mocking in Storybook fehlerhaft | Medium | Low | Storybook Decorators: `CartContext.Provider` mit statischen Mock-Werten wrappen | Einfache Cart-Components ohne Context testen — nur Props-basiert |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| Storybook Framework | `@storybook/nextjs-vite` (nicht Webpack) | Vite-basiert: schnellere Builds, besserer DX. Day-One Next.js 16 + React 19 Support in Storybook 10. Empfohlen von Storybook-Docs. |
| Storybook Version | 10.2.x (nicht 8.x wie in Discovery angenommen) | 8.x hat kein Next.js 16 Support. 10.x ist aktuell stable mit Day-One Support. ESM-Only Breaking Change ist kein Problem (Node.js 22.x). |
| Story Location | `frontend/stories/` (dedizierter Ordner, nicht co-located) | Co-location (`*.stories.tsx` neben Components) polluted Component-Verzeichnisse. Dedizierter Ordner hält Components clean und Stories organisiert nach Domain. |
| Story Format | CSF 3 (Component Story Format) | Standard seit Storybook 7. TypeScript-first, Args-basiert, Autodocs-kompatibel. |
| Mock Strategy | Static Props + Context Decorators | Kein MSW oder GraphQL-Mocking nötig — alle Components akzeptieren Daten als Props. Cart-Components brauchen CartContext-Decorator. |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| Card `asChild` Erweiterung | Product-Card und Category-Showcase können `<Card asChild><Link>` nutzen — semantisch korrektes `<a>` statt `<div>` | Ändert shadcn/ui Primitive (eigentlich "don't touch") | Minimale Änderung: 3 Zeilen (Slot Import, asChild Prop, Comp Pattern) — identisch zum Button-Pattern |
| QuantityStepper 32px statt 44px | Kompakteres Layout, Industrie-Standard | Unter 44px Touch-Target Empfehlung | Stepper ist in Group — kombiniertes Target ausreichend. Mobile Users haben finger-distance alternatives. |
| Storybook 10.x statt 8.x | Next.js 16 Support, aktuell maintained | Discovery sagte 8.x — Abweichung | 8.x hat kein Next.js 16 Support — 10.x ist die einzige Option |
| Dedizierte `stories/` statt co-located | Clean Component-Verzeichnisse | Stories nicht neben Components sichtbar | Storybook glob `../stories/**/*.stories.tsx` — explizite Zuordnung über Dateinamen |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Keine offenen Fragen | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-24 | Codebase | Button-Primitive (`components/ui/button.tsx`): 6 Variants (default, destructive, outline, ghost, link, secondary), 4 Sizes (default, sm, lg, icon), `isLoading` Prop mit Spinner, `asChild` via Radix Slot |
| 2026-02-24 | Codebase | Card-Primitive (`components/ui/card.tsx`): 2 Variants (default, interactive), 6 Compound Components — **kein `asChild` Support** (nutzt `<div>`, nicht Radix Slot) |
| 2026-02-24 | Codebase | Badge-Primitive (`components/ui/badge.tsx`): 4 Variants (default, secondary, destructive, outline), `<span>` Element |
| 2026-02-24 | Codebase | `add-to-cart-button.tsx`: Custom `<button>` mit className-Array, eigener Loading/Success/Disabled State — Button-Primitive hat bereits `isLoading` mit identischem Spinner |
| 2026-02-24 | Codebase | `cart-summary.tsx`: Custom `<button>` mit redundanten `bg-primary`, Focus-Ring, Disabled-Styles — exakt was Button `variant="default"` liefert. Kein Card-Wrapper vorhanden. |
| 2026-02-24 | Codebase | `quantity-stepper.tsx`: 2× `<button>` mit `w-8 h-8 border` — kleiner als Button `size="icon"` (44px). Braucht className Override auf 32px. |
| 2026-02-24 | Codebase | `cart-item-row.tsx`: Outer `<div>` mit `border rounded-lg` ist exakt Card `variant="default"`. Remove-`<button>` ist exakt Button `variant="ghost" size="icon"`. |
| 2026-02-24 | Codebase | `variant-selector-size.tsx`: Toggle-Pattern mit conditional className. Maps zu Button `variant="default"` (selected) / `variant="outline"` (unselected). `aria-pressed` bereits vorhanden. |
| 2026-02-24 | Codebase | `mobile-menu.tsx`: SheetTrigger wraps custom `<button>` — kann direkt `<Button>` nutzen. Sheet/Dialog bereits aus shadcn/ui. |
| 2026-02-24 | Codebase | `cart-page-client.tsx`: Error-Close `<button>` mit `text-error` — Button `variant="ghost" size="icon"` + className Override für error-Farbe. |
| 2026-02-24 | Codebase | `product-image-gallery.tsx`: Dots (10px, `role="tab"`) — Out of Scope. Thumbnails (72px, `border-2`) — Button `variant="ghost" size="icon"` + className Override. |
| 2026-02-24 | Codebase | `hero-block.tsx`: `<Link>` mit button-Styles — klassisches `<Button asChild><Link>` Pattern. |
| 2026-02-24 | Codebase | `empty-cart.tsx`: `<Link>` mit `bg-primary rounded-lg` — `<Button asChild><Link>` Pattern. |
| 2026-02-24 | Codebase | `filter-chips.tsx`: `<Link>` mit conditional Styles (aktiv/inaktiv) — `<Button asChild>` mit Variant-Switch + `rounded-full` Override. `aria-current="page"` bereits vorhanden. |
| 2026-02-24 | Codebase | `product-card.tsx`: `<Link>` mit eigenen border/shadow/hover-Styles — exakt was Card `variant="interactive"` liefert. Braucht Card `asChild` Support. |
| 2026-02-24 | Codebase | `category-showcase-block.tsx`: `<Link>` mit `border bg-surface-elevated rounded-xl` — Card `variant="interactive"` Pattern. |
| 2026-02-24 | Codebase | `produkt/[slug]/page.tsx`: Beschreibungs-`<section>` mit `bg-surface-elevated`, inline boxShadow — Card + CardHeader + CardTitle + CardContent Pattern. |
| 2026-02-24 | Codebase | `cart-icon.tsx`: `<span>` Badge mit `bg-primary rounded-full` — Badge `variant="default"` + className Override für kompakte Sizing. |
| 2026-02-24 | Web | Storybook 10.2.x ist aktuell stable (npm, Feb 2026). Day-One Next.js 16 + React 19 Support. ESM-Only Breaking Change (Node.js 20.16+ required). |
| 2026-02-24 | Web | `@storybook/nextjs-vite` empfohlen über `@storybook/nextjs` (Webpack). Vite-basiert: schnellere Builds, besserer DX. |
| 2026-02-24 | Web | Storybook + Tailwind v4: globals.css in preview.ts importieren. PostCSS wird automatisch von Storybook verarbeitet. Kein Plugin nötig. |
| 2026-02-24 | Web | Discovery hatte Storybook 8 angenommen — 8.x hat kein Next.js 16 Support. Korrigiert auf 10.x. |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | wireframes.md fehlt. Zuerst /wireframe ausführen? | Nein, wird nicht gebraucht — technisches Refactoring ohne UI-Änderung |
