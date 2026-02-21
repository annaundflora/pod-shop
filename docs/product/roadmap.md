---
title: Roadmap
created: 2026-02-21
updated: 2026-02-21
---

# Roadmap: POD Shop Platform

## Aktueller Stand

**Phase:** 0 – MVP Launch
**Status:** in-progress
**Letztes Check-in:** 2026-02-21

### Wo stehe ich?

Der Code ist weiter als erwartet: Alle 7 MVP-Slices (Infrastruktur, Produktkatalog, Warenkorb, Rechtliches, Spreadconnect, Pinterest, Accounts) sind implementiert und haben Tests. Der Kaufflow funktioniert bis zum Warenkorb. Was fehlt: **Konfiguration der externen Services** (Spreadconnect, Pinterest, Mollie), **echte Produkte**, **Rechtliches** und **Go-Live-Infrastruktur**.

Die größte Unsicherheit: Theming soll von Anfang an mitgedacht werden — das beeinflusst, wie wir den Feinschliff am Frontend angehen.

---

## Aktuelle Prioritäten

### P0.1: E2E-Kaufflow validieren

**Warum zuerst?** Ohne funktionierenden Kaufflow ist alles andere wertlos. Code existiert, muss aber mit echten Credentials und Testdaten validiert werden.

**Nächste Schritte:**
1. [ ] Spreadconnect API-Key besorgen und in WordPress konfigurieren
2. [ ] Mollie Test-Credentials einrichten
3. [ ] Pinterest Tag ID + CAPI Access Token einrichten
4. [ ] Testprodukte in WooCommerce anlegen (mit Spreadshirt Article IDs)
5. [ ] Kompletten Kaufflow durchklicken: Produkt → Warenkorb → Checkout → Bezahlung → Order an Spreadshirt

### P0.2: Frontend-Architektur für Theming vorbereiten

**Warum jetzt?** Theming ist ein Kernziel. Bevor wir am Design feilen, sollte die Token-Struktur stehen, damit wir nicht alles doppelt machen.

**Nächste Schritte:**
1. [ ] Design-Token-Audit: Welche Werte sind aktuell hardcoded vs. in CSS Variables?
2. [ ] Theme-Config-Konzept: Was gehört in eine Shop-Config? (Name, Farben, Fonts, Logo, Texte)
3. [ ] CSS-Variable-System in `globals.css` erweitern (`@theme` Block)
4. [ ] Komponenten auf Token umstellen (statt hardcodierte Tailwind-Klassen)

### P0.3: Rechtliches & SEO

**Warum?** Ohne Impressum, AGB, Datenschutz darf der Shop nicht live gehen. Ohne Sitemap wird er nicht gefunden.

**Nächste Schritte:**
1. [ ] Rechtstexte erstellen/generieren (Impressum, AGB, Datenschutz, Widerruf)
2. [ ] Statische Seiten im Frontend bauen (`/impressum`, `/agb`, `/datenschutz`, `/widerruf`)
3. [ ] Sitemap.xml generieren (Next.js built-in)
4. [ ] robots.txt konfigurieren

### P0.4: Mobile UX & Feinschliff

**Warum?** Pinterest-Traffic ist 80%+ mobil. Der Shop muss auf dem Handy einwandfrei funktionieren.

**Nächste Schritte:**
1. [ ] Mobile Walkthrough aller Seiten
2. [ ] Touch-Target-Größen prüfen (min 44px)
3. [ ] Ladezeiten auf Mobile prüfen (Lighthouse)
4. [ ] Produktbilder: WebP, responsive sizes

---

## Offene Entscheidungen

| Entscheidung | Status | Notizen |
|--------------|--------|---------|
| Hosting-Provider Frontend (Vercel vs. Selfhost) | offen | Vercel am einfachsten, aber Kosten bei Traffic-Spikes |
| Domain-Strategie (eine Domain vs. mehrere) | offen | Beeinflusst Theming-Architektur in Phase 1 |
| Rechtstexte: Generator vs. Anwalt | offen | Generator (e-recht24, IT-Recht Kanzlei) reicht für MVP |
| Theming-Ansatz: CSS Variables vs. Tailwind Config vs. beides | offen | Discovery nötig |

## Geparkt (Nicht jetzt)

| Was | Grund |
|-----|-------|
| Google Ads Integration | Erst Pinterest validieren |
| Next.js Account-Seiten | WooCommerce `/mein-konto` reicht für MVP |
| Newsletter / E-Mail-Marketing | Kein Launch-Blocker |
| Bewertungen / Reviews | Nice-to-have, nicht MVP |
| Wishlist / Merkzettel | Erst nach Launch sinnvoll |

## Erledigtes

| Datum | Was |
|-------|-----|
| 2026-02-21 | Onboarding abgeschlossen, Vision + Phasen + Roadmap erstellt |
| vor 2026-02 | MVP-Slices 01-07 implementiert (Infrastruktur, Katalog, Warenkorb, Rechtliches, Spreadconnect, Pinterest, Accounts) |

---

## Nächste Roadmap-Session

**Wann:** Nach Abschluss von P0.1 (E2E-Kaufflow validiert)
**Agenda:**
- Theming-Discovery Ergebnisse besprechen
- Rechtstexte-Status prüfen
- Go-Live-Datum einschätzen

---
*Letzte Aktualisierung: 2026-02-21*
