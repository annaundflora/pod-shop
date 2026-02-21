# Wireframes: POD Shop MVP

**Discovery:** `discovery.md` (same folder)
**Status:** Draft

---

## Component Coverage

| UI Component (from Discovery) | Screen |
|-------------------------------|--------|
| Produkt-Card | Homepage, Category Page |
| Varianten-Selector (Größe) | Product Detail Page |
| Varianten-Selector (Farbe) | Product Detail Page |
| "In den Warenkorb"-Button | Product Detail Page |
| Warenkorb-Icon (Header) | All Screens (Header) |
| Mengen-Steuerung (Warenkorb) | Cart Page |
| "Entfernen"-Button (Warenkorb) | Cart Page |
| "Zur Kasse"-Button | Cart Page |
| Cookie-Banner | All Screens (Overlay) |

---

## User Flow Overview

```
[Homepage] ──click category──► [Category Page] ──click product──► [Product Detail]
    │                                                                    │
    └──click featured product──► [Product Detail]                        │
                                      │                          select variant
                                      │                                  │
                                      ▼                                  ▼
                                [Add to Cart] ──────────────────► [Cart Page]
                                                                       │
                                                                  click checkout
                                                                       │
                                                                       ▼
                                                          [WooCommerce Checkout]
                                                                  (redirect)
```

---

## Shared: Header

**Context:** Persistent header on all Next.js frontend pages. Mobile-first.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│  ☰  ①        SHOP NAME        ② 🛒 │
└─────────────────────────────────────┘
```

### Wireframe (Desktop)

```
┌──────────────────────────────────────────────────────────┐
│  SHOP NAME     ①Kategorie 1  Kategorie 2     ② 🛒(3)   │
└──────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Navigation: Category links (mobile: hamburger menu, desktop: inline links)
- ② `Warenkorb-Icon`: Cart icon with badge counter showing item count

### State Variations

| State | Visual Change |
|-------|---------------|
| Cart empty | ② No badge shown |
| Cart has items | ② Badge with number (e.g. "3") |
| Mobile menu open | Overlay with category links, legal page links, "Mein Konto" link |

---

## Shared: Footer

**Context:** Persistent footer on all Next.js frontend pages.

### Wireframe

```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│  Impressum · AGB · Datenschutz · Widerruf                │
│                                                          │
│  Mein Konto (→ WooCommerce)                              │
│                                                          │
│  © 2025 Shop Name                                        │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Annotations:**
- Legal page links redirect to WooCommerce WordPress pages
- "Mein Konto" links to WooCommerce account page

---

## Screen: Homepage

**Context:** Main entry point. Pinterest traffic lands here or on product detail pages. Mobile-first layout.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│                                     │
│  ┌─────────────────────────────┐    │
│  │                             │    │
│  │      ① HERO IMAGE           │    │
│  │                             │    │
│  │    Tagline / Claim          │    │
│  │                             │    │
│  │    [ Jetzt shoppen ]        │    │
│  │                             │    │
│  └─────────────────────────────┘    │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  ② Featured Products                │
│  ───────────────────                │
│                                     │
│  ┌───────────┐  ┌───────────┐      │
│  │  ③[img]   │  │  ③[img]   │      │
│  │           │  │           │      │
│  │  Name     │  │  Name     │      │
│  │  29,99 €  │  │  24,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│  ┌───────────┐  ┌───────────┐      │
│  │  ③[img]   │  │  ③[img]   │      │
│  │           │  │           │      │
│  │  Name     │  │  Name     │      │
│  │  34,99 €  │  │  29,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  ④ Kategorien                       │
│  ───────────────────                │
│                                     │
│  ┌─────────────────────────────┐    │
│  │  [img]  T-Shirts     →     │    │
│  └─────────────────────────────┘    │
│  ┌─────────────────────────────┐    │
│  │  [img]  Hoodies      →     │    │
│  └─────────────────────────────┘    │
│                                     │
│          [Footer]                   │
└─────────────────────────────────────┘
```

**Annotations:**
- ① Hero section with full-width image, tagline, CTA button
- ② Featured Products section heading
- ③ `Produkt-Card`: Product image, name, price. Click navigates to product detail page
- ④ Category overview: Category image + name, click navigates to category page

### State Variations

| State | Visual Change |
|-------|---------------|
| Loading | Skeleton placeholders for product cards and categories |
| No featured products | Section hidden |
| Product card hover (desktop) | Subtle scale/shadow effect |

---

## Screen: Category Page

**Context:** Shows all products within a category. Reached via header nav or homepage category links.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│                                     │
│  ① T-Shirts                         │
│  ═══════════════════════════════    │
│                                     │
│  ② Filter: [Alle] [T-Shirts]       │
│            [Hoodies] [...]          │
│                                     │
│  ③ 12 Produkte                      │
│                                     │
│  ┌───────────┐  ┌───────────┐      │
│  │  ④[img]   │  │  ④[img]   │      │
│  │           │  │           │      │
│  │  Name     │  │  Name     │      │
│  │  29,99 €  │  │  24,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│  ┌───────────┐  ┌───────────┐      │
│  │  ④[img]   │  │  ④[img]   │      │
│  │           │  │           │      │
│  │  Name     │  │  Name     │      │
│  │  29,99 €  │  │  34,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│  ┌───────────┐  ┌───────────┐      │
│  │  ④[img]   │  │  ④[img]   │      │
│  │           │  │           │      │
│  │  Name     │  │  Name     │      │
│  │  24,99 €  │  │  29,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│          [Footer]                   │
└─────────────────────────────────────┘
```

**Annotations:**
- ① Category title (from WooCommerce category name)
- ② Filter chips by product type (horizontal scrollable on mobile)
- ③ Product count
- ④ `Produkt-Card`: Product image, name, price. 2-column grid on mobile, 3-4 columns on desktop

### State Variations

| State | Visual Change |
|-------|---------------|
| Loading | Skeleton cards in grid layout |
| Empty category | "Keine Produkte in dieser Kategorie" message |
| Filter active | Active filter chip highlighted, product list filtered |
| Product card hover (desktop) | Subtle scale/shadow effect |

---

## Screen: Product Detail Page

**Context:** Main conversion page. Pinterest traffic often lands directly here. Shows product with variant selection.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│                                     │
│  ┌─────────────────────────────┐    │
│  │                             │    │
│  │                             │    │
│  │      ① PRODUCT IMAGE        │    │
│  │         (large)             │    │
│  │                             │    │
│  │                             │    │
│  └─────────────────────────────┘    │
│    ○  ●  ○  ○  (image dots)        │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  ② Product Name                     │
│  ③ 29,99 €                          │
│                                     │
│  inkl. Versandkosten                │
│                                     │
│  ───────────────────                │
│                                     │
│  ④ Farbe: Schwarz                   │
│                                     │
│  [⬛] [⬜] [🔵] [🔴]              │
│                                     │
│  ───────────────────                │
│                                     │
│  ⑤ Größe                            │
│                                     │
│  [ S ] [ M ] [  L  ] [ XL ] [XXL]  │
│                                     │
│  ───────────────────                │
│                                     │
│  ┌─────────────────────────────┐    │
│  │     ⑥ IN DEN WARENKORB      │    │
│  └─────────────────────────────┘    │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  ⑦ Beschreibung                     │
│  ───────────────────                │
│  Lorem ipsum dolor sit amet,       │
│  consectetur adipiscing elit...     │
│                                     │
│          [Footer]                   │
└─────────────────────────────────────┘
```

**Annotations:**
- ① Product image gallery with swipe/dots navigation (mobile), thumbnails (desktop)
- ② Product name
- ③ Price in EUR (no VAT note due to Kleinunternehmerregelung)
- ④ `Varianten-Selector (Farbe)`: Color swatches, selected has border/ring
- ⑤ `Varianten-Selector (Größe)`: Size buttons, selected has filled background
- ⑥ `"In den Warenkorb"-Button`: Full-width primary button
- ⑦ Product description from WooCommerce

### Wireframe (Desktop)

```
┌──────────────────────────────────────────────────────────┐
│                    [Header]                              │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────────┐  ┌───────────────────────────┐    │
│  │                  │  │                           │    │
│  │                  │  │  ② Product Name            │    │
│  │   ① PRODUCT      │  │  ③ 29,99 €                │    │
│  │      IMAGE       │  │                           │    │
│  │                  │  │  inkl. Versandkosten      │    │
│  │                  │  │                           │    │
│  │                  │  │  ④ Farbe: Schwarz          │    │
│  │                  │  │  [⬛] [⬜] [🔵] [🔴]     │    │
│  │                  │  │                           │    │
│  │                  │  │  ⑤ Größe                   │    │
│  │                  │  │  [S] [M] [L] [XL] [XXL]  │    │
│  │                  │  │                           │    │
│  └──────────────────┘  │  ┌─────────────────────┐  │    │
│   [thumb] [thumb]       │  │ ⑥ IN DEN WARENKORB  │  │    │
│                         │  └─────────────────────┘  │    │
│                         └───────────────────────────┘    │
│                                                          │
│  ═══════════════════════════════════════════════════     │
│                                                          │
│  ⑦ Beschreibung                                          │
│  Lorem ipsum dolor sit amet...                           │
│                                                          │
│                    [Footer]                              │
└──────────────────────────────────────────────────────────┘
```

### State Variations

| State | Visual Change |
|-------|---------------|
| Loading | Skeleton for image, text blocks, and button |
| No variant selected | ⑥ Button disabled with text "Bitte Größe und Farbe wählen" |
| Variant selected | ④ Selected color has ring, ⑤ selected size has filled bg |
| Size unavailable | Size button grayed out, not clickable |
| Color unavailable | Color swatch grayed out with strikethrough |
| Add to cart loading | ⑥ Button shows spinner, disabled |
| Add to cart success | ⑥ Button briefly shows checkmark + "Hinzugefügt!", header cart badge updates |
| Product image gallery | Swipe on mobile, thumbnail click on desktop to change main image |

---

## Screen: Cart Page

**Context:** Shows all items in cart. Accessible via header cart icon. Last step before redirect to WooCommerce checkout.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│                                     │
│  ① Warenkorb (3 Artikel)            │
│  ═══════════════════════════════    │
│                                     │
│  ┌─────────────────────────────┐    │
│  │ ┌──────┐                    │    │
│  │ │②[img]│  ③ Product Name    │    │
│  │ │      │  Farbe: Schwarz    │    │
│  │ └──────┘  Größe: L          │    │
│  │                             │    │
│  │  ④ [-] [  2  ] [+]  ⑤ ✕    │    │
│  │                             │    │
│  │              ⑥ 59,98 €      │    │
│  └─────────────────────────────┘    │
│                                     │
│  ┌─────────────────────────────┐    │
│  │ ┌──────┐                    │    │
│  │ │②[img]│  ③ Product Name    │    │
│  │ │      │  Farbe: Weiß       │    │
│  │ └──────┘  Größe: M          │    │
│  │                             │    │
│  │  ④ [-] [  1  ] [+]  ⑤ ✕    │    │
│  │                             │    │
│  │              ⑥ 29,99 €      │    │
│  └─────────────────────────────┘    │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  Versandkosten        ⑦ 4,99 €     │
│  ───────────────────────────────    │
│  Gesamt               ⑧ 94,96 €    │
│                                     │
│  Gemäß §19 UStG wird keine         │
│  Umsatzsteuer berechnet.           │
│                                     │
│  ┌─────────────────────────────┐    │
│  │      ⑨ ZUR KASSE            │    │
│  └─────────────────────────────┘    │
│                                     │
│          [Footer]                   │
└─────────────────────────────────────┘
```

**Annotations:**
- ① Cart title with item count
- ② Product thumbnail image
- ③ Product name with selected variant details (color, size)
- ④ `Mengen-Steuerung`: Minus/plus buttons with quantity input, min 1
- ⑤ `"Entfernen"-Button`: Remove item from cart (X icon)
- ⑥ Line item subtotal (price x quantity)
- ⑦ Shipping costs (from WooCommerce/Spreadconnect configuration)
- ⑧ Cart total
- ⑨ `"Zur Kasse"-Button`: Full-width primary button, redirects to WooCommerce checkout

### State Variations

| State | Visual Change |
|-------|---------------|
| Empty cart | "Dein Warenkorb ist leer" message + "Weiter shoppen" link to homepage |
| Loading (quantity change) | Quantity input briefly disabled, price updates |
| Single item, quantity 1 | ④ Minus button disabled |
| Removing item | Item fades out / removed from list |
| Last item removed | Transitions to empty cart state |
| Checkout button disabled | ⑨ Grayed out if cart is empty |

---

## Overlay: Cookie Banner

**Context:** Shown on first visit, overlaying the bottom of any page. Must be dismissed before Pinterest Tag fires.

### Wireframe (Mobile)

```
┌─────────────────────────────────────┐
│                                     │
│       [... page content ...]        │
│                                     │
│  ┌─────────────────────────────┐    │
│  │                             │    │
│  │  ① Wir verwenden Cookies    │    │
│  │                             │    │
│  │  Diese Website nutzt        │    │
│  │  Cookies für Analyse und    │    │
│  │  Marketing.                 │    │
│  │  Mehr in der Datenschutz-   │    │
│  │  erklärung.                 │    │
│  │                             │    │
│  │  ┌───────────────────────┐  │    │
│  │  │  ② ALLE AKZEPTIEREN   │  │    │
│  │  └───────────────────────┘  │    │
│  │                             │    │
│  │  ┌───────────────────────┐  │    │
│  │  │  ③ NUR NOTWENDIGE     │  │    │
│  │  └───────────────────────┘  │    │
│  │                             │    │
│  └─────────────────────────────┘    │
└─────────────────────────────────────┘
```

**Annotations:**
- ① `Cookie-Banner`: Banner with consent text and link to privacy policy
- ② Accept all cookies button (primary) - enables Pinterest Tag
- ③ Reject optional cookies button (secondary) - Pinterest Tag stays disabled

### State Variations

| State | Visual Change |
|-------|---------------|
| First visit | Banner visible at bottom of page |
| Consent given | Banner hidden, not shown again |
| Consent declined | Banner hidden, Pinterest Tag remains disabled |

---

## Completeness Check

| Check | Status |
|-------|--------|
| All Screens from UI Layout (Discovery) covered | ✅ Homepage, Category, Product Detail, Cart |
| All UI Components annotated | ✅ All 9 components from Discovery mapped |
| Relevant State Variations documented | ✅ Loading, empty, error, interaction states |
| No Logic/Rules duplicated (stays in Discovery) | ✅ Referenced but not duplicated |
| WooCommerce screens excluded (not Next.js) | ✅ Checkout, Account, Legal handled by WooCommerce |
| Mobile-first wireframes provided | ✅ Mobile shown first, desktop where layout differs |
| Header/Footer shared components shown | ✅ Documented as shared screens |
