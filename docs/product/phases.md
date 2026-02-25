---
title: Phasen-Definition
created: 2026-02-21
updated: 2026-02-25
---

# Phasen-Definition: POD Shop Platform

## Uebersicht

| Phase | Name | Kernfrage | Status |
|-------|------|-----------|--------|
| 0 | MVP Launch | Kann ein Kunde kaufen und wird die Bestellung erfuellt? | in-progress |
| 1 | Template & Theming | Kann ich einen zweiten Shop in unter einem Tag aufsetzen? | in-progress |
| 2 | Multi-Provider & Scale | Kann ich andere POD-Provider anbinden und mehrere Shops betreiben? | open |
| 3 | Technisches SEO | Rankt der Shop und sind Core Web Vitals im gruenen Bereich? | open |

---

## Phase 0: MVP Launch (AKTUELL)

**Frage:** Kann ein Kunde ein Produkt finden, kaufen und bekommt es geliefert?

### Kriterien

- [x] Homepage mit Featured Products und Kategorien
- [x] Kategorieseiten mit Produktgrid
- [x] Produktdetailseiten mit Varianten-Auswahl (Groesse, Farbe)
- [x] Warenkorb mit Add/Update/Remove
- [x] Checkout-Redirect zu WooCommerce mit Session-Token
- [x] Mollie-Payment-Plugin installiert
- [x] Spreadconnect-Plugin mit API-Client, Order-Service, Tracking
- [x] Pinterest Client-Side Tracking mit Consent Gate
- [x] Pinterest Server-Side CAPI
- [x] Cookie-Consent-Banner
- [x] Component Primitive Migration (17 Components auf shadcn/ui, 688 Tests gruen)
- [x] Storybook mit 27 Component Stories
- [ ] Alle Seiten als Block-basierte YAML-Pages (Kategorie, Produkt, Rechtliches)
- [ ] E2E-Kaufflow getestet (Produkt -> Bezahlung -> Fulfillment)
- [ ] Spreadconnect API-Credentials eingerichtet und getestet
- [ ] Pinterest Tag + CAPI Credentials eingerichtet und getestet
- [ ] Mollie Payment-Flow getestet (Testmodus)
- [ ] Echte Produkte in WooCommerce angelegt
- [ ] Rechtliches: Impressum, AGB, Datenschutz, Widerrufsbelehrung
- [ ] Domain + Hosting fuer Frontend (Vercel o.ae.)
- [ ] SEO-Basics: Sitemap, robots.txt, Meta-Tags
- [ ] Mobile UX-Review (Pinterest-Traffic ist 80%+ mobil)

### Exit-Kriterium

> "Ein Testkunde kann ueber Pinterest auf den Shop kommen, ein Produkt kaufen, mit Mollie bezahlen, und die Bestellung wird automatisch an Spreadshirt weitergeleitet. Das Tracking funktioniert."

### Nicht in dieser Phase

- Keine eigenen Account-Seiten in Next.js (WooCommerce reicht)
- Kein zweiter POD-Provider
- Keine Google Ads Integration (erst Pinterest validieren)
- Keine Newsletter-Integration

---

## Phase 1: Template & Theming (PARALLEL -- fast abgeschlossen)

**Frage:** Kann ich das Frontend fuer einen zweiten Shop wiederverwenden?

### Kriterien

- [x] Design-Token-System (Farben, Fonts, Spacing als CSS Variables / Theme-Config)
- [x] Shop-Config-Datei (Name, Logo, Social Links, Footer-Text etc.)
- [x] Component-Varianten oder Slots fuer unterschiedliche Layouts
- [x] Theme-Switching ohne Code-Aenderungen (nur Config + Assets)
- [x] Zweiter Demo-Shop als Proof of Concept
- [x] Component Primitives (shadcn/ui) mit Theme-Token-Integration
- [x] Storybook als visuelles Inventar
- [ ] Alle Seiten als Block-basierte YAML-Pages (geteilt mit Phase 0)
- [ ] Dokumentation: "So setzt du einen neuen Shop auf"

### Exit-Kriterium

> "Ein neuer Shop mit eigenem Branding kann durch Aendern einer Config-Datei und Hochladen eigener Assets aufgesetzt werden, ohne React-Komponenten zu aendern."

### Nicht in dieser Phase

- Kein visueller Theme-Editor (Code-Config reicht)
- Kein Multi-Tenancy (separate Deployments pro Shop)
- Keine Plugin-Architektur fuer Komponenten

---

## Phase 2: Multi-Provider & Scale

**Frage:** Kann ich andere POD-Provider anbinden und mehrere Shops parallel betreiben?

### Kriterien

- [ ] Provider-Abstraktionsschicht (nicht nur Spreadshirt)
- [ ] Zweiter Provider angebunden (z.B. Printful, Gelato)
- [ ] Multi-Shop-Betrieb mit geteiltem Codebase
- [ ] Google Ads Conversion-Tracking
- [ ] Performance-Monitoring ueber mehrere Shops

### Exit-Kriterium

> "Zwei Shops laufen parallel mit unterschiedlichen POD-Providern und eigenem Branding."

### Nicht in dieser Phase (Ausblick)

- Kein Self-Service fuer Dritte (nur eigene Shops)
- Kein Marketplace-Modell

---

## Phase 3: Technisches SEO

**Frage:** Rankt der Shop in Suchmaschinen und sind Core Web Vitals im gruenen Bereich?

### Kriterien

- [ ] Core Web Vitals: LCP < 2.5s, INP < 200ms, CLS < 0.1 (gemessen auf Production)
- [ ] Structured Data / JSON-LD vollstaendig: Product, BreadcrumbList, Organization
- [ ] Canonical Tags korrekt gesetzt (inkl. Paginated Pages)
- [ ] Open Graph + Twitter Card Meta-Tags fuer alle Seiten
- [ ] Sitemap.xml automatisch generiert (Next.js built-in, dynamisch)
- [ ] robots.txt korrekt konfiguriert
- [ ] Google Search Console eingerichtet und verifiziert
- [ ] Interne Verlinkungsstruktur geprueft
- [ ] 404-Seite optimiert (korrekte HTTP-Status-Codes)
- [ ] Bildoptimierung: WebP/AVIF, lazy loading, korrekte Alt-Texte

### Exit-Kriterium

> "Der Shop besteht einen Lighthouse-Audit mit 90+ Performance und 100 SEO Score. Google Search Console zeigt keine kritischen Fehler. Alle Produktseiten sind indexiert."

### Nicht in dieser Phase

- Kein Content SEO (Keyword-Strategie, Texte) -- erst nach Echtdaten sinnvoll
- Kein Blog / Landing Pages
- Keine Link-Building-Massnahmen

---
*Letzte Aktualisierung: 2026-02-25*
