# Gate 0: Discovery <-> Wireframe Compliance

**Discovery:** `docs/features/pod-shop-mvp/discovery.md`
**Wireframes:** `docs/features/pod-shop-mvp/wireframes.md`
**Pruefdatum:** 2026-02-21

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 24 |
| Auto-Fix Needed | 8 |
| Blocking | 0 |

**Verdict:** APPROVED (Blocking = 0)

**100% Compliance:** Keine Blocking-Issues. 8 Discovery-Updates erforderlich (Wireframe-Details die in Discovery fehlen).

---

## A) Discovery -> Wireframe

### User Flow Coverage

| Discovery Flow | Steps | Wireframe Coverage | Status |
|----------------|-------|--------------------|--------|
| Flow 1: Produkt finden und kaufen | 11 | Homepage -> Category -> Product Detail -> Cart -> WooCommerce Redirect: All Next.js steps covered in wireframes. Steps 6-11 (WooCommerce Checkout) correctly excluded from wireframes | PASS |
| Flow 2: Bestellung wird fulfilt (POD) | 6 | Backend-only flow, no frontend UI needed. Correctly not wireframed | PASS |
| Flow 3: User-Account | 4 | WooCommerce-only flow. Footer + Mobile Menu include "Mein Konto" link to WooCommerce. Correctly handled | PASS |
| Flow 4: Pinterest Tracking (technisch) | 6 | Technical/invisible flow. Cookie Banner wireframed as gate for Pinterest Tag. Correctly handled | PASS |

### UI Screen Coverage (Next.js Frontend)

| Discovery Screen | Wireframe Present | Content Match | Status |
|------------------|-------------------|---------------|--------|
| Startseite (Hero, Featured Products, Kategorien) | Homepage wireframe | Hero + Featured Products + Kategorien all shown | PASS |
| Kategorieseite (Produktliste, Filter) | Category Page wireframe | Product grid + filter chips + product count shown | PASS |
| Produktdetailseite (Bild, Varianten, Preis, Button, Beschreibung) | Product Detail wireframe (Mobile + Desktop) | Image gallery, name, price, color selector, size selector, add-to-cart button, description all shown | PASS |
| Warenkorb (Produktliste, Menge, Preise, Checkout-Button) | Cart Page wireframe | Product items with image, variant info, quantity control, remove button, shipping, total, checkout button all shown | PASS |

### UI Screen Coverage (WooCommerce - correctly excluded from wireframes)

| Discovery Screen | Wireframe Status | Status |
|------------------|------------------|--------|
| Checkout | Excluded (WooCommerce standard) | PASS |
| Bestellbestaetigung | Excluded (WooCommerce standard) | PASS |
| Mein Konto | Excluded, linked from Footer + Mobile Menu | PASS |
| Rechtliche Seiten | Excluded, linked from Footer | PASS |

### UI Component Coverage

| Discovery Component | Wireframe Location | Annotation ID | States Covered | Status |
|---------------------|--------------------|---------------|----------------|--------|
| Produkt-Card | Homepage (3), Category Page (4) | Yes | Default, Hover, Loading (Skeleton) | PASS |
| Varianten-Selector (Groesse) | Product Detail (5) | Yes | Available, Selected (filled bg), Unavailable (grayed out) | PASS |
| Varianten-Selector (Farbe) | Product Detail (4) | Yes | Available, Selected (ring), Unavailable (grayed out + strikethrough) | PASS |
| "In den Warenkorb"-Button | Product Detail (6) | Yes | Default, Loading (spinner), Success (checkmark + "Hinzugefuegt!"), Disabled (no variant) | PASS |
| Warenkorb-Icon (Header) | Header (2) | Yes | Empty (no badge), With items (badge with number) | PASS |
| Mengen-Steuerung | Cart Page (4) | Yes | Default, Min 1 (minus disabled), Loading on change | PASS |
| "Entfernen"-Button | Cart Page (5) | Yes | Default (X icon), Removing (fade out) | PASS |
| "Zur Kasse"-Button | Cart Page (9) | Yes | Default, Disabled (empty cart) | PASS |
| Cookie-Banner | Cookie Banner Overlay (1) | Yes | First visit (visible), Consent given (hidden), Declined (hidden, tag disabled) | PASS |

### UI State Coverage (Discovery State Machine)

| State Machine | Discovery States | Wireframe Coverage | Status |
|---------------|------------------|--------------------|--------|
| Warenkorb: Leer | "Warenkorb ist leer" + Link zum Shop | Cart Page: "Dein Warenkorb ist leer" + "Weiter shoppen" link | PASS |
| Warenkorb: Mit Produkten | Badge-Counter, Bestaetigung | Header badge updates, Add-to-cart success state | PASS |
| Warenkorb: Menge aendern | Preis aktualisiert | Cart: quantity input disabled briefly, price updates | PASS |
| Warenkorb: Entfernen (letztes) | Leeren State zeigen | Cart: transitions to empty cart state | PASS |
| Warenkorb: Zur Kasse | Redirect | Cart: Checkout button redirects to WooCommerce | PASS |

### Business Rules in UI

| Business Rule | Wireframe Representation | Status |
|---------------|--------------------------|--------|
| Kleinunternehmerregelung (kein MwSt) | Product Detail: "inkl. Versandkosten" (no VAT line). Cart: "Gemaess Paragraph 19 UStG wird keine Umsatzsteuer berechnet." | PASS |
| Versandkosten | Cart: Shipping costs line item shown (7: 4,99 EUR) | PASS |
| Cookie Consent vor Pinterest Tag | Cookie Banner: Accept enables Tag, Reject keeps Tag disabled | PASS |

### Error/Edge State Coverage

| Discovery Error Path | Wireframe Coverage | Status |
|----------------------|--------------------|--------|
| Zahlung fehlgeschlagen | WooCommerce-side, correctly excluded | PASS |
| Produkt nicht verfuegbar | Size unavailable (grayed out), Color unavailable (grayed out + strikethrough) | PASS |
| Session abgelaufen | WooCommerce-side session handling, correctly excluded | PASS |

---

## B) Wireframe -> Discovery (Auto-Fix Rueckfluss)

### Visual Specs - Missing in Discovery

| # | Wireframe Spec | Value | Discovery Has | Status |
|---|----------------|-------|---------------|--------|
| 1 | Product Card grid layout | 2-column mobile, 3-4 column desktop | No - Discovery says "Produktliste mit Bild, Name, Preis" but no grid spec | AUTO-FIX NEEDED |
| 2 | Product image gallery navigation | Swipe + dots (mobile), thumbnails (desktop) | No - Discovery says "Grosses Produktbild" but no gallery navigation detail | AUTO-FIX NEEDED |
| 3 | Color swatch selected state visual | Border/ring indicator | No - Discovery says "Ausgewaehlt" but no visual spec | AUTO-FIX NEEDED |
| 4 | Size button selected state visual | Filled background | No - Discovery says "Ausgewaehlt" but no visual spec | AUTO-FIX NEEDED |
| 5 | Cart line item shows variant details | Color + Size displayed per item | No - Discovery says "Produktliste mit Bild, Variante, Menge" but not explicit | AUTO-FIX NEEDED |
| 6 | Paragraph 19 UStG note visible in Cart UI | Text shown below total | No - Discovery mentions it for invoices but not for Cart UI display | AUTO-FIX NEEDED |
| 7 | Filter chips on Category Page | Horizontal scrollable chip buttons | No - Discovery says "Filter nach Produkttyp" but no UI pattern specified | AUTO-FIX NEEDED |
| 8 | Shipping costs as separate line in Cart | Separate line item above total | No - Discovery says "Versandkosten werden von Spreadconnect definiert" but not that they show as line item in Cart | AUTO-FIX NEEDED |

### Implicit Constraints Found in Wireframes

| # | Wireframe Shows | Implied Constraint | Discovery Has | Status |
|---|-----------------|-------------------|---------------|--------|
| 1 | Hero section with CTA "Jetzt shoppen" | Homepage needs hero image + tagline content management | Yes - Discovery: "Hero-Bereich, Featured Products" | PASS |
| 2 | Product count on Category Page ("12 Produkte") | Category page shows product count | No - not in Discovery | AUTO-FIX (minor, covered by grid spec update) |
| 3 | "inkl. Versandkosten" text on Product Detail | Shipping cost inclusion note on PDP | No - not explicitly in Discovery | AUTO-FIX (covered by Paragraph 19 update) |
| 4 | Mobile hamburger menu with legal + account links | Mobile navigation includes legal pages and account link | Yes - Discovery: Footer/Header links | PASS |
| 5 | "Weiter shoppen" link in empty cart | Empty cart has navigation back to shop | Yes - Discovery: "Warenkorb ist leer + Link zum Shop" | PASS |
| 6 | Skeleton loading states on all screens | Loading states use skeleton UI pattern | No - Discovery mentions Loading state but not skeleton pattern | AUTO-FIX (covered by visual spec updates) |

---

## C) Auto-Fix Summary

### Discovery Updates Required

These details from wireframes need to be added to discovery.md to ensure no information is lost before Architecture phase:

| # | Discovery Section | Content to Add |
|---|-------------------|----------------|
| 1 | UI Layout - Kategorieseite | Add: "2-Spalten-Grid (mobil), 3-4 Spalten (desktop). Horizontale Filter-Chips zum Filtern nach Produkttyp. Produktanzahl wird angezeigt." |
| 2 | UI Layout - Produktdetailseite | Add: "Bildergalerie mit Swipe + Dots-Navigation (mobil), Thumbnail-Navigation (desktop)." |
| 3 | UI Components - Varianten-Selector (Farbe) | Update States to include: "Ausgewaehlt: Ring/Border um Swatch" |
| 4 | UI Components - Varianten-Selector (Groesse) | Update States to include: "Ausgewaehlt: Gefuellter Hintergrund" |
| 5 | UI Layout - Warenkorb | Add: "Jedes Produkt zeigt Thumbnail, Name, gewaehlte Variante (Farbe + Groesse), Mengensteuerung, Einzelpreis. Versandkosten als separate Zeile. Paragraph 19 UStG Hinweis unter Gesamtpreis sichtbar." |
| 6 | UI Components - Produkt-Card | Add note: "Loading State: Skeleton-Platzhalter" |
| 7 | Business Rules - Kleinunternehmerregelung | Add: "Paragraph 19 UStG Hinweis wird auch im Warenkorb unter dem Gesamtpreis angezeigt (nicht nur auf Rechnungen)." |
| 8 | UI Layout - Produktdetailseite | Add: "Hinweis 'inkl. Versandkosten' unter dem Preis angezeigt." |

### Wireframe Updates Needed (Blocking)

None. All Discovery requirements are covered in wireframes.

---

## Blocking Issues

None.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Auto-Fix Discovery Updates Needed:** 8
**Required Wireframe Updates:** 0

**Next Steps:**
- [ ] Apply 8 Discovery updates (see Auto-Fix Summary above) to ensure wireframe details flow back into discovery.md
- [ ] Proceed to Architecture phase after Discovery updates are applied
