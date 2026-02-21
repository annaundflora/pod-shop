---
title: Phasen-Definition
created: 2026-02-21
updated: 2026-02-21
---

# Phasen-Definition: POD Shop Platform

## Übersicht

| Phase | Name | Kernfrage | Status |
|-------|------|-----------|--------|
| 0 | MVP Launch | Kann ein Kunde kaufen und wird die Bestellung erfüllt? | in-progress |
| 1 | Template & Theming | Kann ich einen zweiten Shop in unter einem Tag aufsetzen? | open |
| 2 | Multi-Provider & Scale | Kann ich andere POD-Provider anbinden und mehrere Shops betreiben? | open |

---

## Phase 0: MVP Launch (AKTUELL)

**Frage:** Kann ein Kunde ein Produkt finden, kaufen und bekommt es geliefert?

### Kriterien

- [x] Homepage mit Featured Products und Kategorien
- [x] Kategorieseiten mit Produktgrid
- [x] Produktdetailseiten mit Varianten-Auswahl (Größe, Farbe)
- [x] Warenkorb mit Add/Update/Remove
- [x] Checkout-Redirect zu WooCommerce mit Session-Token
- [x] Mollie-Payment-Plugin installiert
- [x] Spreadconnect-Plugin mit API-Client, Order-Service, Tracking
- [x] Pinterest Client-Side Tracking mit Consent Gate
- [x] Pinterest Server-Side CAPI
- [x] Cookie-Consent-Banner
- [ ] E2E-Kaufflow getestet (Produkt → Bezahlung → Fulfillment)
- [ ] Spreadconnect API-Credentials eingerichtet und getestet
- [ ] Pinterest Tag + CAPI Credentials eingerichtet und getestet
- [ ] Mollie Payment-Flow getestet (Testmodus)
- [ ] Echte Produkte in WooCommerce angelegt
- [ ] Rechtliches: Impressum, AGB, Datenschutz, Widerrufsbelehrung
- [ ] Domain + Hosting für Frontend (Vercel o.ä.)
- [ ] SEO-Basics: Sitemap, robots.txt, Meta-Tags
- [ ] Mobile UX-Review (Pinterest-Traffic ist 80%+ mobil)

### Exit-Kriterium

> "Ein Testkunde kann über Pinterest auf den Shop kommen, ein Produkt kaufen, mit Mollie bezahlen, und die Bestellung wird automatisch an Spreadshirt weitergeleitet. Das Tracking funktioniert."

### Nicht in dieser Phase

- Kein Theming-System (kommt Phase 1)
- Keine eigenen Account-Seiten in Next.js (WooCommerce reicht)
- Kein zweiter POD-Provider
- Keine Google Ads Integration (erst Pinterest validieren)
- Keine Newsletter-Integration

---

## Phase 1: Template & Theming

**Frage:** Kann ich das Frontend für einen zweiten Shop wiederverwenden?

### Kriterien

- [ ] Design-Token-System (Farben, Fonts, Spacing als CSS Variables / Theme-Config)
- [ ] Shop-Config-Datei (Name, Logo, Social Links, Footer-Text etc.)
- [ ] Component-Varianten oder Slots für unterschiedliche Layouts
- [ ] Theme-Switching ohne Code-Änderungen (nur Config + Assets)
- [ ] Dokumentation: "So setzt du einen neuen Shop auf"
- [ ] Zweiter Demo-Shop als Proof of Concept

### Exit-Kriterium

> "Ein neuer Shop mit eigenem Branding kann durch Ändern einer Config-Datei und Hochladen eigener Assets aufgesetzt werden, ohne React-Komponenten zu ändern."

### Nicht in dieser Phase

- Kein visueller Theme-Editor (Code-Config reicht)
- Kein Multi-Tenancy (separate Deployments pro Shop)
- Keine Plugin-Architektur für Komponenten

---

## Phase 2: Multi-Provider & Scale

**Frage:** Kann ich andere POD-Provider anbinden und mehrere Shops parallel betreiben?

### Kriterien

- [ ] Provider-Abstraktionsschicht (nicht nur Spreadshirt)
- [ ] Zweiter Provider angebunden (z.B. Printful, Gelato)
- [ ] Multi-Shop-Betrieb mit geteiltem Codebase
- [ ] Google Ads Conversion-Tracking
- [ ] Performance-Monitoring über mehrere Shops

### Exit-Kriterium

> "Zwei Shops laufen parallel mit unterschiedlichen POD-Providern und eigenem Branding."

### Nicht in dieser Phase (Ausblick)

- Kein Self-Service für Dritte (nur eigene Shops)
- Kein Marketplace-Modell

---
*Letzte Aktualisierung: 2026-02-21*
