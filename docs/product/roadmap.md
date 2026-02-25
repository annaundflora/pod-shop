---
title: Roadmap
created: 2026-02-21
updated: 2026-02-25
---

# Roadmap: POD Shop Platform

## Aktueller Stand

**Phase:** 0 -- MVP Launch + Phase 1 -- Theming (parallel)
**Status:** in-progress
**Letztes Check-in:** 2026-02-25

### Wo stehe ich?

Alle 7 MVP-Slices implementiert (688 Frontend-Tests gruen). Theming-Engine komplett: Token System, YAML-Pages, Multi-Shop-Config, zweiter Demo-Shop. Component Primitive Migration abgeschlossen: 17 Components auf shadcn/ui, Storybook mit 27 Stories. Homepage laeuft bereits als Block-basierte YAML-Page.

**Richtungsentscheidung (2026-02-25):** Foundation-Fokus gewaehlt. Alle Seiten werden erst als Block-basierte YAML-Pages migriert, bevor Credentials und Kaufflow-Validierung kommen. Grund: Template-System soll komplett sein, bevor der erste Shop live geht.

**Naechster Schritt:** Discovery fuer Block-basierte Seiten-Migration starten. Architektur klaeren (wie weit geht YAML-Konfigurierbarkeit bei interaktiven Seiten wie Produkt und Warenkorb).

---

## Aktuelle Prioritaeten

> Specs sind Source of Truth. Jede Prioritaet verlinkt auf ihren Spec-Ordner.

### P0.3: Block-basierte Seiten-Migration -- open

**Spec:** Noch nicht erstellt (Discovery noetig)
**Status:** Naechster Schritt -- Discovery starten

Alle verbleibenden Seiten (Kategorie, Produkt, Warenkorb, Rechtliches) als YAML-konfigurierbare Block-Pages aufbauen. Neue Block-Components erstellen, DataLoader erweitern, Page-Renderer generalisieren.

**Teilschritte:**

1. **Discovery: Architektur-Entscheidung** -- Wie weit geht YAML bei interaktiven Seiten?
   - Kategorie-Seite: relativ klar (Heading-Block, FilterChips-Block, ProductGrid-Block)
   - Produkt-Seite: komplex (Gallery, VariantSelector, AddToCart sind eng gekoppelt)
   - Warenkorb: Sonderfall (komplett client-seitig, kein RSC/YAML-Datenfetch)
   - Rechtliches: einfach (statischer Content-Block)

2. **Slice A: Kategorie-Seite als Block-Page**
   - Neue Block-Types: `page-heading`, `filter-chips`, evtl. `product-count`
   - DataLoader erweitern: `woocommerce` Query `products_by_category` (mit slug-Parameter)
   - `themes/default/pages/category.yaml` erstellen
   - Page-Renderer fuer dynamische Routen (`[slug]`) generalisieren

3. **Slice B: Produkt-Seite als Block-Page**
   - Neue Block-Types: `product-gallery`, `product-info`, `product-description`
   - Herausforderung: Client-Components (VariantSelector, AddToCart) innerhalb von Blocks
   - JSON-LD SEO muss erhalten bleiben
   - `themes/default/pages/product.yaml` erstellen

4. **Slice C: Rechtsseiten als Block-Pages**
   - Neuer Block-Type: `rich-text` oder `legal-content`
   - Content-Source: `inline` (YAML) oder `wordpress` (WP Pages)
   - `themes/default/pages/impressum.yaml`, `datenschutz.yaml`, `agb.yaml`, `widerruf.yaml`

5. **Slice D: Warenkorb als Block-Page (optional)**
   - Sonderfall: komplett client-seitig
   - Moeglichkeit: YAML definiert nur Layout-Reihenfolge, Logik bleibt in Client-Component
   - Oder: Warenkorb bleibt hardcoded (einzige Ausnahme)

**Abhaengigkeit:** Component Primitive Migration (done).

### P0.4: Credentials + E2E-Kaufflow -- open

**Spec:** Keiner noetig (manuelle Konfiguration + Validierung)
**Status:** Wartet auf Block-Migration

- Spreadconnect API-Key besorgen und konfigurieren
- Mollie Test-Credentials einrichten
- Pinterest Tag ID + CAPI Access Token einrichten
- Testprodukte in WooCommerce anlegen (mit Spreadshirt Article IDs)
- Kompletten Kaufflow durchklicken

**Abhaengigkeit:** P0.3 Block-basierte Seiten (damit Produktseite final steht).

### P0.5: Rechtliches & SEO -- open

**Spec:** Noch nicht erstellt
**Status:** Wartet auf Block-Migration

- Rechtstexte erstellen/generieren (Impressum, AGB, Datenschutz, Widerruf)
- Seiten als Block-Pages (wird in P0.3 Slice C vorbereitet)
- Sitemap.xml (Next.js built-in)
- robots.txt
- Meta-Tags pro Seite

**Abhaengigkeit:** P0.3 Slice C (Legal-Block-Type).

### P0.6: Go-Live -- open

**Spec:** Noch nicht erstellt
**Status:** Wartet auf alles davor

- Domain + Hosting (Vercel o.ae.)
- WordPress Produktions-Server
- SSL, DNS, Umgebungsvariablen
- Mobile UX-Review (Pinterest-Traffic 80%+ mobil)
- Smoke-Test auf Production

**Abhaengigkeit:** P0.4 + P0.5 abgeschlossen.

---

## Offene Entscheidungen

| Entscheidung | Status | Notizen |
|--------------|--------|---------|
| Block-Architektur fuer Produkt-/Warenkorbseiten | **naechste Discovery** | Wie weit geht YAML-Konfigurierbarkeit? Nur Layout oder auch Logik? |
| Warenkorb als Block-Page oder Ausnahme | offen | Client-only Seite -- passt nicht ins RSC-Block-Pattern |
| Hosting-Provider Frontend (Vercel vs. Selfhost) | offen | Vercel am einfachsten, aber Kosten bei Traffic-Spikes |
| Domain-Strategie (eine Domain vs. mehrere) | offen | Beeinflusst Multi-Shop-Setup |
| Rechtstexte: Generator vs. Anwalt | offen | Generator (e-recht24, IT-Recht Kanzlei) reicht fuer MVP |
| WordPress Hosting (Managed vs. eigener Server) | offen | Betrifft Kosten, Wartung, Performance |

## Geparkt (Nicht jetzt)

| Was | Grund |
|-----|-------|
| Google Ads Integration | Erst Pinterest validieren |
| Next.js Account-Seiten | WooCommerce `/mein-konto` reicht fuer MVP |
| Newsletter / E-Mail-Marketing | Kein Launch-Blocker |
| Bewertungen / Reviews | Nice-to-have, nicht MVP |
| Wishlist / Merkzettel | Erst nach Launch sinnvoll |

## Erledigtes

| Datum | Spec | Was |
|-------|------|-----|
| 2026-02-25 | `specs/phase-0/2026-02-24-component-primitive-migration/` | Component Primitive Migration abgeschlossen (17 Components auf shadcn/ui, 27 Storybook Stories, 688 Tests gruen) |
| 2026-02-24 | `specs/phase-0/2026-02-21-frontend-theming/` | Theming abgeschlossen (Token System, shadcn/ui, Component Migration, Block Registry, Multi-Shop) |
| 2026-02-21 | -- | Onboarding, Vision + Phasen + Roadmap erstellt |
| vor 2026-02 | `specs/features/pod-shop-mvp/` | MVP-Slices 01-07 (Infrastruktur, Katalog, Warenkorb, Rechtliches, Spreadconnect, Pinterest, Accounts) |

---

## Naechste Roadmap-Session

**Wann:** Nach Abschluss der Discovery fuer P0.3
**Agenda:**
- Architektur-Entscheidung: YAML-Tiefe fuer interaktive Seiten
- Slice-Aufteilung finalisieren
- Warenkorb: Block-Page oder Ausnahme?

---
*Letzte Aktualisierung: 2026-02-25*
