# Feature: Component Primitive Migration + Storybook (P0.3)

**Epic:** Follows `2026-02-21-frontend-theming` (P0.2)
**Status:** Ready
**Wireframes:** Nicht nötig (Refactoring-Feature, keine UI-Änderung)

---

## Problem & Solution

**Problem:**
- 15 UI-Patterns in 13 Dateien implementieren eigene Button/Card/Badge-Logik statt die vorhandenen shadcn/ui Primitives zu verwenden
- Duplizierte Styling-Logik: Focus-Ring, Hover-States, Disabled-States, Loading-States werden pro Component individuell implementiert
- Inkonsistente Sizes: Buttons haben unterschiedliche Padding/Height-Werte (py-2, py-2.5, py-3, py-3.5)
- Accessibility nicht einheitlich: Manche Buttons haben `aria-label`, manche nicht; `focus-visible:ring` Implementierung variiert
- shadcn/ui Primitives (Button, Card, Badge) wurden in Slice 2 eingeführt aber in Slice 3 nur für 5 von 20 Stellen übernommen
- Kein Storybook — Components können nicht isoliert entwickelt, getestet oder visuell verglichen werden
- Kein Component-Katalog für Entwickler oder Stakeholder

**Solution:**
- **Composition Pattern** (Best Practice: Vercel Academy / shadcn/ui Docs): Custom Components aus shadcn/ui Primitives zusammenbauen statt eigene Patterns zu implementieren
- Alle identifizierten Patterns auf shadcn/ui Primitives (`<Button>`, `<Card>`, `<Badge>`) umstellen — sowohl Leaf-Elements (einzelne Buttons) als auch Container-Strukturen (Card-Wrapper)
- Bestehende shadcn/ui Variants (default, outline, ghost, destructive, link, secondary) nutzen — keine neuen Variants
- Accessibility-Audit pro Component: ARIA Labels, Keyboard Navigation, Focus Management
- Vitest Tests für Primitive-Nutzung (korrekte Variants, States, ARIA-Attribute)
- Storybook einrichten mit Stories für alle Components (Primitives + Layout + Product + Cart + Category + Blocks)

**Business Value:**
- Konsistente UI: Alle Buttons/Cards/Badges folgen demselben Design-System
- Weniger Code: Redundante Styling-Klassen entfallen (~30% weniger className-Strings pro migriertem Element)
- Accessibility-Garantie: Radix UI Primitives (unter shadcn/ui) liefern ARIA-Rollen und Keyboard-Navigation out-of-box
- Wartbarkeit: Design-Änderungen (Radius, Shadow, Focus-Ring) wirken automatisch auf alle Components
- Component-Katalog: Storybook als visuelle Dokumentation aller Components mit allen Variants und States
- Entwickler-Produktivität: Components isoliert entwickeln und testen ohne App starten zu müssen

---

## Scope & Boundaries

| In Scope |
|----------|
| **Composition Pattern:** Custom Components aus shadcn/ui Primitives zusammenbauen (Vercel Academy Best Practice) |
| **Button Composition (11 Patterns):** `add-to-cart-button` → `<Button isLoading>`, `cart-summary` Checkout → `<Button>`, `quantity-stepper` ×2 → `<Button size="icon">`, `cart-item-row` Remove → `<Button variant="ghost" size="icon">`, `variant-selector-size` → `<Button variant="default"/"outline">`, `mobile-menu` Hamburger → `<Button variant="ghost" size="icon">`, `cart-page-client` Error-Close → `<Button variant="ghost" size="icon">`, `gallery-thumbnails` → `<Button variant="ghost" size="icon" asChild>` |
| **Button-als-Link Composition (4 Patterns):** `hero-block` CTA → `<Button asChild><Link>`, `empty-cart` CTA → `<Button asChild><Link>`, `filter-chips` aktiv → `<Button asChild><Link>`, `filter-chips` inaktiv → `<Button variant="secondary" asChild><Link>` |
| **Card Composition (4 Patterns):** `product-card` → `<Card>` + `<CardContent>` Wrapper, `cart-item-row` → `<Card>` + `<CardContent>` Container, `category-showcase-block` → `<Card variant="interactive">`, `produkt/[slug]/page.tsx` Beschreibung → `<Card>` + `<CardHeader>` + `<CardContent>` |
| **Badge Composition (1 Pattern):** `cart-icon` Counter → `<Badge>` |
| **Card + Button Composition (1 Pattern):** `cart-summary` → `<Card>` Wrapper + `<Button>` Checkout |
| Accessibility-Audit: ARIA Labels, `role` Attribute, Keyboard Navigation pro Component |
| Vitest Tests: Variant-Rendering, Disabled-State, ARIA-Attribute, Click-Handler |
| Anpassung bestehender Tests an neue Component-Struktur (shadcn/ui wrapper elements) |
| Storybook 8 Setup mit Next.js 16 Framework-Integration |
| Stories für alle 7 shadcn/ui Primitives (Button, Card, Input, Badge, Skeleton, Dialog, Sheet) mit allen Variants und States |
| Stories für alle Layout Components (Header, Footer, Mobile Menu, Cart Icon, Cookie Consent Banner) |
| Stories für alle Product Components (Product Card, Add-to-Cart Button, Image Gallery, Variant Selectors) |
| Stories für alle Cart Components (Cart Item Row, Cart Summary, Quantity Stepper, Empty Cart) |
| Stories für Category Components (Filter Chips) |
| Stories für alle Block Components (Hero Block, Product Grid Block, Category Showcase Block, USP Bar Block) |
| Storybook Tailwind v4 Integration (PostCSS + globals.css Import) |
| Storybook `pnpm storybook` Script in package.json |

| Out of Scope |
|--------------|
| `variant-selector-color.tsx` — Runde 40x40px Color-Swatches mit dynamischem `style={{backgroundColor: hex}}` + SVG-Strikethrough. Zu domain-spezifisch für Button-Primitive. |
| Gallery-Dot-Buttons (`product-image-gallery.tsx` Dots) — 10px, semantisch `role="tab"`, zu speziell für `<Button>` |
| Footer-Links (`footer.tsx`) — semantisch Navigations-Links (`<a>`), keine Aktions-Buttons |
| `header.tsx` — Layout-Container ohne Primitive-Patterns (Children sind bereits eigene Components) |
| `usp-bar-block.tsx` — Simple `<ul>/<li>` mit Icons, kein Primitive-Pattern |
| Neue shadcn/ui Variants (kein `success`, `chip`, `swatch`) — Standard-Variants + className reicht |
| Neue shadcn/ui Primitives (kein `Toggle`, `ToggleGroup`, `RadioGroup`) — kommt ggf. später |
| Verhaltensänderungen — rein visuelles Refactoring, identisches Verhalten |
| Neue Features oder UI-Elemente |
| Storybook Interaction Tests (play functions) — nur visuelle Stories |
| Chromatic oder Visual Regression Testing — nur lokales Storybook |
| Storybook Addons (a11y, design, etc.) — minimales Setup, Addons später |

---

## Current State Reference

> Bestehende Funktionalität die wiederverwendet wird (unverändert).

- **shadcn/ui Primitives** in `frontend/components/ui/`: Button (6 Variants, 4 Sizes, `isLoading`, `asChild`), Card (6 Compound Components, 2 Variants), Input (2 Variants), Badge (4 Variants), Skeleton, Dialog, Sheet
- **`cn()` Utility** in `frontend/lib/utils.ts`: clsx + tailwind-merge für Class-Merging
- **CVA (class-variance-authority)** für type-safe Variant-Definitionen
- **Theme Tokens** in `globals.css` @theme Block: `--color-primary`, `--color-error`, `--color-success`, `--color-overlay`, `--shadow-card`, `--radius-button`, etc.
- **Bereits migriert:** `mobile-menu.tsx` → Sheet, `cookie-consent-banner.tsx` → Button, `product-card-skeleton.tsx` → Skeleton, `hero-block.tsx` → Skeleton, `category-showcase-block.tsx` → Skeleton
- **Farb-Migration:** 100% erledigt — keine hardcoded Tailwind-Colors mehr, alle Components nutzen semantische Tokens
- **151 Vitest Tests** grün (Stand nach Theming Phase 1)
- **Kein Storybook** — noch nicht eingerichtet
- **PostCSS Config** mit `@tailwindcss/postcss` — wird von Storybook automatisch erkannt

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|----------------------|
| `<Button>` Primitive | `components/ui/button.tsx` | Ersetzt 10 custom `<button>` + 1 Thumbnail-Button |
| `<Button asChild>` | `components/ui/button.tsx` | Wraps `<Link>` Elemente die wie Buttons aussehen (Hero CTA, Empty Cart CTA, Filter Chips) |
| `<Card>` Compound | `components/ui/card.tsx` | Ersetzt 3 custom Card-Patterns (Cart Item Row, Category Showcase, Produkt-Beschreibung) |
| `<Badge>` | `components/ui/badge.tsx` | Ersetzt 1 custom Badge (Cart Icon Counter) |
| `cn()` Utility | `lib/utils.ts` | Conditional Classes in migrierten Components |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| **Composition Pattern** | Custom Components nutzen shadcn/ui Primitives als Bausteine: `ProductCard` = `Card` + domain-spezifische Logik. Zwei Ebenen: `components/ui/` (Primitives) → `components/{domain}/` (Composed Components) | Vercel Academy / shadcn/ui Best Practice: "Composition over Inheritance" |
| `<Button>` mit Success-State via className | `add-to-cart-button.tsx` wechselt nach Erfolg von `bg-primary` zu `bg-success` — kein neuer Variant, sondern conditional className über `cn()` | Success-State ist spezifisch für Add-to-Cart, kein generisches Pattern |
| `<Button>` als Toggle via Variant-Switch | `variant-selector-size.tsx` wechselt zwischen `variant="default"` (selected) und `variant="outline"` (unselected) | Toggle-Verhalten über Variant-Prop statt custom className |
| `<Card>` als Composed Wrapper | `product-card.tsx`, `cart-item-row.tsx`, `cart-summary.tsx` nutzen `Card` + `CardContent` als Container statt eigener border/shadow/rounded-Styles | Card-Primitive liefert konsistente Borders, Shadows, Radii aus Theme Tokens |
| `<Card variant="interactive" asChild>` | `category-showcase-block.tsx` nutzt Card als klickbaren Link mit Hover-Lift | Interactive-Variant ist bereits in Card definiert |

---

## User Flow

### Flow 1: Endnutzer-Perspektive (keine Änderung)

1. Alle Seiten sehen visuell identisch aus
2. Alle Buttons reagieren identisch auf Klick, Hover, Focus, Disabled
3. Alle Cards haben identische Borders, Shadows, Radii
4. Keyboard-Navigation funktioniert identisch (oder besser durch Radix UI Primitives)

### Flow 2: Entwickler-Migration (einmalig)

1. Component-Datei öffnen
2. Custom `<button>` identifizieren → durch `<Button variant="..." size="...">` ersetzen
3. Custom Card-Container identifizieren → durch `<Card><CardContent>...</CardContent></Card>` ersetzen
4. Custom Badge identifizieren → durch `<Badge>` ersetzen
5. `import { Button } from '@/components/ui/button'` hinzufügen
6. Redundante className-Strings entfernen (Focus-Ring, Hover, Disabled werden vom Primitive übernommen)
7. ARIA-Attribute prüfen und ggf. ergänzen
8. Bestehende Tests anpassen (neue wrapper elements, data-slot Attribute)
9. Neue Tests für Primitive-Nutzung schreiben

**Error Paths:**
- Test bricht wegen geänderter DOM-Struktur → Test-Selector anpassen (data-testid oder role statt className)
- Button-Größe stimmt nicht → `size` Prop oder custom className anpassen
- `asChild` Pattern funktioniert nicht mit verschachteltem `<Link>` → Radix `<Slot>` debuggen

---

## UI Layout & Context

### Kein neuer Screen

Dieses Feature ändert keine sichtbare UI. Alle Seiten (Homepage, Kategorie, Produkt, Warenkorb) sehen visuell identisch aus — aber:
- `<button>` Elemente werden durch `<Button>` ersetzt (erzeugt ggf. zusätzliches `<button>` wrapper oder ändert className-Output)
- Card-Container bekommen `data-slot="card"`, `data-slot="card-content"` Attribute
- Badge bekommt `data-slot="badge"` Attribut

---

## UI Components & States

### Zu migrierende Button-Patterns

| Element | File | Lines | Current | Target Variant | Target Size | States | ARIA |
|---------|------|-------|---------|---------------|-------------|--------|------|
| Add-to-Cart | `product/add-to-cart-button.tsx` | 55-94 | `<button>` mit custom classes | `default` | `default` | default, hover, focus, disabled, loading, success (className) | `aria-label`: vorhanden |
| Checkout | `cart/cart-summary.tsx` | 40-48 | `<button>` mit `bg-primary` | `default` | `default` | default, hover, focus, disabled, loading | Prüfen: `aria-label` fehlt |
| Quantity Minus | `cart/quantity-stepper.tsx` | 16-24 | `<button>` mit `border` | `outline` | `icon` | default, hover, focus, disabled | `aria-label="Menge verringern"` |
| Quantity Plus | `cart/quantity-stepper.tsx` | 34-42 | `<button>` mit `border` | `outline` | `icon` | default, hover, focus, disabled | `aria-label="Menge erhöhen"` |
| Remove Item | `cart/cart-item-row.tsx` | 46-56 | `<button>` mit `text-secondary` | `ghost` | `icon` | default, hover (text-error), focus, disabled | `aria-label` prüfen |
| Size Selector | `product/variant-selector-size.tsx` | 27-45 | `<button>` mit conditional classes | `default` (selected) / `outline` (unselected) | `default` | default, hover, focus, selected, disabled (unavailable) | `aria-pressed` oder `aria-selected` hinzufügen |
| Color Selector | `product/variant-selector-color.tsx` | 30-64 | `<button>` mit `style={{backgroundColor}}` | `outline` | `icon` | default, hover, focus, selected (ring), disabled | `aria-label={colorName}`, `aria-pressed` hinzufügen |
| Hamburger Menu | `layout/mobile-menu.tsx` | 18-37 | `<button>` mit custom SVG | `ghost` | `icon` | default, hover, focus | `aria-label="Menü öffnen"`: vorhanden |
| Error Close | `warenkorb/cart-page-client.tsx` | 29-35 | `<button>` mit `text-error` | `ghost` | `icon` | default, hover | `aria-label="Fehlermeldung schliessen"`: vorhanden |
| Gallery Thumbnails | `product/product-image-gallery.tsx` | 101-122 | `<button>` mit `<Image>` | `ghost` | `icon` (custom size) | default, hover, focus, selected (ring) | `role="tab"`, `aria-selected`: vorhanden |

### Zu migrierende Button-als-Link-Patterns

| Element | File | Lines | Current | Target Variant | `asChild` | States | ARIA |
|---------|------|-------|---------|---------------|-----------|--------|------|
| Hero CTA | `blocks/hero-block.tsx` | 52-58 | `<Link>` mit button-classes | `default` | Ja, wraps `<Link>` | default, hover, focus | Prüfen |
| Empty Cart CTA | `cart/empty-cart.tsx` | 7-14 | `<Link>` mit `bg-primary` | `default` | Ja, wraps `<Link>` | default, hover, focus | Prüfen |
| Filter Chip (aktiv) | `category/filter-chips.tsx` | 17-28 | `<Link>` mit `bg-text-primary` | `default` | Ja, wraps `<Link>` | active | Prüfen: `aria-current="page"` hinzufügen |
| Filter Chip (inaktiv) | `category/filter-chips.tsx` | 30-42 | `<Link>` mit `bg-surface-elevated` | `secondary` | Ja, wraps `<Link>` | default, hover | -- |

### Zu migrierende Card-Composition-Patterns

| Element | File | Lines | Current | Target Variant | Compound Components | States |
|---------|------|-------|---------|---------------|---------------------|--------|
| Product Card | `product/product-card.tsx` | ganze Datei | `<Link>` mit custom border/shadow/rounded | `interactive` | `Card` mit `asChild` wrapping `<Link>` + `CardContent` für Info-Bereich | default, hover (scale image + lift) |
| Cart Item Row | `cart/cart-item-row.tsx` | 16-69 | `<div>` mit border+rounded | `default` | `Card` + `CardContent` | default |
| Cart Summary | `cart/cart-summary.tsx` | ganze Datei | `<div>` ohne Container-Styling | `default` | `Card` + `CardContent` + `CardFooter` (für Button + Disclaimer) | default |
| Category Showcase Item | `blocks/category-showcase-block.tsx` | 29-53 | `<Link>` mit border+bg | `interactive` | `Card` mit `asChild` wrapping `<Link>` | default, hover (lift) |
| Produkt-Beschreibung | `produkt/[slug]/page.tsx` | 110-121 | `<section>` mit bg+shadow | `default` | `Card` + `CardHeader` + `CardTitle` + `CardContent` | default |

### Zu migrierendes Badge-Pattern

| Element | File | Lines | Current | Target Variant | States |
|---------|------|-------|---------|---------------|--------|
| Cart Counter | `layout/cart-icon.tsx` | 34-41 | `<span>` mit `bg-primary rounded-full` | `default` | visible (count > 0), hidden (count === 0) |

---

## Feature State Machine

### Migration State Machine (Entwickler-Perspektive)

| State | Beschreibung | Available Actions |
|-------|-------------|-------------------|
| `pre_migration` | Alle Components nutzen custom Patterns | Start Migration |
| `migrating` | Components werden umgestellt | Migrate next pattern |
| `tests_adapting` | Bestehende Tests werden angepasst | Fix next test |
| `tests_writing` | Neue Tests werden geschrieben | Write next test |
| `accessibility_audit` | ARIA Labels + Keyboard Nav prüfen | Fix next issue |
| `validation` | Alle Tests grün, visueller Vergleich | Approve |
| `complete` | Migration abgeschlossen | -- |

### Transitions

| Current State | Trigger | Next State | Business Rules |
|---------------|---------|------------|----------------|
| `pre_migration` | Entwickler startet | `migrating` | -- |
| `migrating` | Alle 15 Patterns umgestellt | `tests_adapting` | Kein Pattern darf custom `<button>` mit Button-Styling nutzen (außer Gallery-Dots) |
| `tests_adapting` | Alle bestehenden Tests angepasst | `tests_writing` | Alle 151+ bestehenden Tests müssen grün sein |
| `tests_writing` | Neue Tests geschrieben | `accessibility_audit` | Mind. 1 Test pro migriertem Pattern |
| `accessibility_audit` | Alle ARIA Issues gefixt | `validation` | Keyboard-Tab durch alle Buttons muss funktionieren |
| `validation` | Visuell identisch + Tests grün | `complete` | `pnpm build` erfolgreich |

### Runtime Component States (keine Änderung)

Alle Runtime-States (hover, focus, disabled, loading, success) bleiben identisch. Die Implementierung wechselt von custom CSS-Klassen zu shadcn/ui CVA Variants — der sichtbare Output ist identisch.

---

## Business Rules

- **Visuell identisch:** Nach der Migration darf sich kein sichtbares UI-Element verändern (Farbe, Größe, Position, Animation)
- **Button Touch Targets:** Alle Buttons müssen weiterhin min 44px (2.75rem) Touch-Target haben — shadcn/ui `size="default"` = 44px, `size="icon"` = 44px
- **asChild Pattern:** Links die wie Buttons aussehen (`<Link>`) müssen `asChild` nutzen — das Kind-Element wird zum rendered Element (semantisch `<a>`, styled wie `<Button>`)
- **Keine neuen Variants:** Nur bestehende shadcn/ui Variants nutzen. Spezielle Styling-Bedürfnisse über `className` Prop lösen
- **Gallery-Dots Ausnahme:** `product-image-gallery.tsx` Dot-Buttons (10px) bleiben als native `<button>` — zu klein für `<Button>`
- **Footer-Links Ausnahme:** `footer.tsx` Links bleiben als native `<a>` / `<Link>` — semantisch Navigations-Links
- **Test-Stabilität:** Alle bestehenden 151+ Tests müssen nach Migration grün sein
- **Accessibility-Mindeststandard:** Jeder interaktive Button braucht: `aria-label` (wenn kein sichtbarer Text), `focus-visible` Ring, Keyboard-Aktivierbarkeit (Enter/Space)
- **Storybook Stories:** Jede Component braucht mindestens eine Default-Story. Primitives brauchen Stories für alle Variants und States.
- **Storybook Theme Tokens:** Stories müssen die generierten Theme Tokens nutzen (globals.css Import) — keine hardcoded Farben in Stories
- **Storybook Mock Data:** Components die GraphQL-Daten brauchen nutzen statische Mock-Daten in den Stories — kein echtes GraphQL
- **Storybook als Dokumentation:** Story-Titel folgen dem Pattern `{Category}/{Component}` (z.B. `UI Primitives/Button`, `Cart/Cart Summary`)

---

## Data

Kein neues Datenmodell. Dieses Feature ändert nur die Component-Implementierung, nicht die Daten.

---

## Implementation Slices

### Dependencies

```
Slice 1 (Big Bang Migration) → Slice 2 (Storybook)
```

Slice 2 hängt von Slice 1 ab, weil die Stories die migrierten Components referenzieren.

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | Component Primitive Composition | **Composition Pattern:** Custom Components aus shadcn/ui Primitives zusammenbauen. **Buttons (11):** add-to-cart → `<Button isLoading>`, checkout → `<Button>`, quantity-stepper ×2 → `<Button size="icon">`, remove-item → `<Button variant="ghost" size="icon">`, size-selector → `<Button variant="default"/"outline">`, hamburger → `<Button variant="ghost" size="icon">`, error-close → `<Button variant="ghost" size="icon">`, gallery-thumbnails → `<Button asChild>`. **Button-als-Link (4):** hero CTA, empty-cart CTA, filter-chips aktiv/inaktiv → `<Button asChild><Link>`. **Card Composition (5):** product-card → `<Card variant="interactive">` Wrapper, cart-item-row → `<Card>` + `<CardContent>`, cart-summary → `<Card>` + `<CardContent>` + `<CardFooter>`, category-showcase → `<Card variant="interactive" asChild>`, produkt-beschreibung → `<Card>` + `<CardHeader>` + `<CardContent>`. **Badge (1):** cart-icon counter → `<Badge>`. **Accessibility-Audit:** ARIA Labels ergänzen (aria-pressed für Toggle-Buttons, aria-current für aktive Filter-Chips, aria-label für Buttons ohne sichtbaren Text). **Tests:** Bestehende Tests anpassen + mind. 1 neuer Test pro migriertem Pattern. | `pnpm test` — alle bestehenden + neuen Tests grün. `pnpm build` — Build erfolgreich. Visueller Vergleich: Seiten sehen identisch aus. Grep für custom `<button>` mit Button-Styling → nur Gallery-Dots + Color-Swatches als Ausnahme. axe-core oder manuelle Prüfung: alle ARIA-Attribute korrekt. | Setzt voraus: shadcn/ui Primitives existieren (Slice 2 des Theming-Features ✅) |
| 2 | Storybook Setup + Stories | **Setup:** Storybook 8 mit `@storybook/nextjs` Framework, Tailwind v4 Integration (PostCSS + globals.css Import in `.storybook/preview.ts`), TypeScript Config. **Stories für shadcn/ui Primitives (7):** Button (alle 6 Variants × 4 Sizes × States: default, hover, focus, disabled, loading), Card (default + interactive Variant), Input (default + error Variant), Badge (4 Variants), Skeleton (Sizes), Dialog (open/closed), Sheet (4 sides). **Stories für Layout Components (5):** Header, Footer, Mobile Menu (Sheet-Integration), Cart Icon (mit/ohne Badge-Count), Cookie Consent Banner. **Stories für Product Components (6):** Product Card, Product Card Skeleton, Add-to-Cart Button (States: default, loading, success, disabled), Image Gallery (Dots + Thumbnails), Variant Selector Size (selected/unselected/unavailable), Variant Selector Color (Swatches). **Stories für Cart Components (4):** Cart Item Row (Card), Cart Summary (mit Checkout-Button), Quantity Stepper, Empty Cart. **Stories für Category (1):** Filter Chips (aktiv/inaktiv). **Stories für Blocks (4):** Hero Block (loaded/loading), Product Grid Block (loaded/loading/empty), Category Showcase Block (loaded/loading), USP Bar Block. **Scripts:** `pnpm storybook` (dev), `pnpm build-storybook` (static build). | `pnpm storybook` startet ohne Fehler. Jede Story rendert korrekt. Alle Variants und States sichtbar. Tailwind-Klassen werden korrekt angewendet (Theme Tokens funktionieren). `pnpm build-storybook` erzeugt statischen Build. | Slice 1 |

### Recommended Order

1. **Slice 1: Component Primitive Migration** — Big Bang: alle 15 Patterns + Accessibility + Tests in einem Durchgang. Reihenfolge innerhalb des Slice: Buttons zuerst (größter Impact), dann Cards, dann Badge. Tests parallel anpassen.
2. **Slice 2: Storybook Setup + Stories** — Baut auf migrierten Components auf. Stories dokumentieren die fertigen Primitives + Components mit allen Variants und States.

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| Mobile Menu → Sheet Migration | `components/layout/mobile-menu.tsx` | Erfolgreich migriert in Theming Slice 3 — Pattern für `asChild` + Compound Components |
| Cookie Consent → Button Migration | `components/layout/cookie-consent-banner.tsx` | Erfolgreich migriert — Pattern für `<Button>` mit custom className |
| Product Card Skeleton → Skeleton | `components/product/product-card-skeleton.tsx` | Erfolgreich migriert — zeigt dass shadcn/ui Primitives drop-in funktionieren |
| shadcn/ui Button Variants | `components/ui/button.tsx` | 6 Variants (default, destructive, outline, ghost, link, secondary), 4 Sizes, `isLoading`, `asChild` |
| shadcn/ui Card Variants | `components/ui/card.tsx` | 2 Variants (default, interactive), 6 Compound Components |

### Web Research

| Source | Finding |
|--------|---------|
| shadcn/ui Docs: Button | `asChild` Pattern für Links: `<Button asChild><Link href="/">...</Link></Button>` — Radix `Slot` merged Props auf Kind-Element |
| shadcn/ui Docs: Theming | CVA Variants können mit `className` Prop überschrieben werden — ermöglicht Success-State ohne neuen Variant |
| WAI-ARIA: Button vs Link | Links navigieren (`<a>`), Buttons lösen Aktionen aus (`<button>`). Toggle-Buttons brauchen `aria-pressed`. |
| WAI-ARIA: Tabs | Gallery-Thumbnails mit `role="tab"` sind korrekt — Dots ebenfalls |
| Radix UI: Slot | `asChild` funktioniert mit `next/link` — Props werden gemerged, `className` wird kombiniert |
| Storybook Docs: Next.js Framework | `@storybook/nextjs` unterstützt App Router, next/font, next/image, React 19 |
| Storybook Recipes: Tailwind CSS | globals.css in preview.ts importieren, PostCSS wird automatisch verarbeitet. Kein Plugin nötig. |
| Vercel Academy: Extending shadcn/ui | "Composition over Inheritance": Leverage existing components as building blocks. MetricCard-Beispiel zeigt Card + CardHeader + CardContent + Badge Composition. CVA für Variants, cn() für conditional classes. |
| shadcn/ui Guide (DesignRevision 2026) | Composable interface: Jede Component nutzt gemeinsames, vorhersagbares Interface. Custom Components müssen Theming, Accessibility und API-Design der Primitives respektieren. |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Keine offenen Fragen | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-24 | Codebase | 7 shadcn/ui Primitives in `components/ui/`: Button, Card, Input, Badge, Skeleton, Dialog, Sheet |
| 2026-02-24 | Codebase | Nur 5 von 20 Stellen nutzen Primitives: mobile-menu (Sheet), cookie-consent (Button), 3x Skeleton |
| 2026-02-24 | Codebase | 13 Buttons mit custom `<button>` + eigenen className-Strings (Focus-Ring, Hover, Disabled je individuell) |
| 2026-02-24 | Codebase | 3 Card-Patterns mit `<div>`/`<section>`/`<Link>` + border+shadow+rounded (cart-item-row, category-showcase, produkt-beschreibung) |
| 2026-02-24 | Codebase | 1 Badge-Pattern mit `<span>` + bg-primary+rounded-full (cart-icon counter) |
| 2026-02-24 | Codebase | Gallery-Dots sind 10px groß — passen nicht in Button min-size (44px). Semantisch `role="tab"` korrekt. |
| 2026-02-24 | Codebase | Footer-Links sind semantisch Navigation (`<a>`) — `<Button variant="link">` wäre semantisch falsch |
| 2026-02-24 | Codebase | Button sizes inkonsistent: py-2 (32px), py-2.5 (36px), py-3 (40px), py-3.5 (44px) — shadcn/ui normalisiert auf 44px default |
| 2026-02-24 | Codebase | Compliance-Docs bestätigen Slice 3 als APPROVED — aber nur Farb-Migration, nicht Primitive-Adoption |
| 2026-02-24 | Web | shadcn/ui `asChild` + Next.js `<Link>`: Funktioniert out-of-box, Radix Slot merged className + href |
| 2026-02-24 | Web | WAI-ARIA Best Practice: Toggle-Buttons brauchen `aria-pressed`, Tab-Buttons brauchen `role="tab"` + `aria-selected` |
| 2026-02-24 | Web | Storybook 8 + Next.js: `@storybook/nextjs` Framework-Package, unterstützt App Router, React 19. PostCSS-Config wird automatisch erkannt. |
| 2026-02-24 | Web | Storybook + Tailwind v4: globals.css in `.storybook/preview.ts` importieren reicht — PostCSS wird automatisch von Storybook verarbeitet |
| 2026-02-24 | Stack | Projekt: Next.js ^16.1.6, React ^19.0.0, Tailwind ^4.0.0 — Storybook 8.x kompatibel |
| 2026-02-24 | Web | Vercel Academy: "Composition over Inheritance" — Custom Components aus shadcn/ui Primitives zusammenbauen. Zwei Ebenen: `components/ui/` (Primitives) → `components/{domain}/` (Composed Components). |
| 2026-02-24 | Web | shadcn/ui Docs: CVA Variants können mit `className` Prop überschrieben werden — ermöglicht Success-State ohne neuen Variant |
| 2026-02-24 | Codebase | product-card.tsx hat eigene border/shadow/rounded — exakt das was Card liefert. Composition-Kandidat. |
| 2026-02-24 | Codebase | add-to-cart-button.tsx: Button-Primitive hat bereits `isLoading` Prop — redundante Reimplementierung in custom Component |
| 2026-02-24 | Codebase | variant-selector-color.tsx: Zu domain-spezifisch für Button (rund, dynamischer Hex-Background, SVG-Strikethrough) |

---

## Q&A Log

| # | Frage | Antwort |
|---|-------|---------|
| 1 | Soll zuerst eine umfassende Codebase-Recherche durchgeführt werden oder direkt Q&A? | Recherche zuerst — alle Components analysieren und auf Primitives mappen |
| 2 | Was ist das Ziel? Alle 18 Patterns oder nur ein Bereich? | Alle 18 Patterns (komplette Migration) |
| 3 | Soll die Migration als Big Bang (1 Slice) oder inkrementell (mehrere Slices) erfolgen? | Big Bang — alle auf einmal in einem Slice |
| 4 | Sollen Standard-Variants oder neue Custom-Variants für spezielle Patterns genutzt werden? | Standard-Variants nutzen. Nur custom className für Sonderfälle (z.B. Success-State, Color-Swatches). |
| 5 | Sollen Gallery-Dots und Footer-Links auch migriert werden? Best Practice? | Best Practice: Gallery-Dots (10px, `role="tab"`) und Footer-Links (semantisch `<a>`, Navigation) NICHT migrieren. Scope reduziert auf 15 Patterns. |
| 6 | Wie tief soll die Discovery gehen? | Detailliert — alle Pflicht-Sections, Component-by-Component Analyse |
| 7 | Eigenständiges Feature oder Ergänzung zum bestehenden Theming-Spec? | Eigenständiges Feature: `specs/phase-0/2026-02-24-component-primitive-migration/` |
| 8 | Nur Primitive-Adoption oder auch Accessibility und Tests? | Alles drei: Primitive-Adoption + Accessibility-Audit + Tests |
| 9 | Storybook für welche Components? Nur migrierte oder alle? | Alle Components: shadcn/ui Primitives (7) + Layout (5) + Product (6) + Cart (4) + Category (1) + Blocks (4). Vollständiger Katalog. |
| 10 | Soll Storybook-Setup Teil dieses Features oder separates Feature sein? | Teil dieses Features als Slice 2 (nach der Migration). Logisch zusammengehörig. |
| 11 | Sollen Custom Components (product-card, cart-summary, etc.) aus shadcn/ui Primitives zusammengebaut werden? Best Practice? | Ja, Composition Pattern (Vercel Academy Best Practice): Custom Components nutzen `<Card>`, `<Button>`, `<Badge>` als Bausteine. Z.B. ProductCard = Card + CardContent + domain-spezifische Logik. |
| 12 | Soll variant-selector-color.tsx (runde Color-Swatches mit dynamischem Hex-Background) auf Button umgestellt werden? | Nein, custom belassen. Zu domain-spezifisch: 40x40px rund, dynamisches style={{backgroundColor}}, SVG-Strikethrough. Kein Button-Primitive passt. |
