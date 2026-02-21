# Discovery: POD Shop MVP

**Status:** Ready
**Typ:** Greenfield
**Datum:** 2025-02-20

---

## Problem & Lösung

### Problem

- Spreadshirt-Marketplace bietet keinen Pinterest Tag / Conversion Tracking
- Keine Möglichkeit, auf Pinterest-Conversions zu optimieren (kein ROAS-Tracking)
- Kein eigener Shop = keine Kontrolle über Checkout, Tracking, SEO, Branding
- Abhängigkeit von Spreadshirts Plattform-Entscheidungen

### Lösung

Eigener POD-Shop mit:
- Headless-Architektur: WooCommerce (Backend) + Next.js (Frontend)
- WooCommerce Checkout-Seite für Zahlungsabwicklung (Mollie)
- Pinterest Tag + Conversions API für vollständiges Conversion-Tracking
- Spreadconnect API für POD-Fulfillment (bestehende Spreadshirt-Produkte)
- Alles self-hosted auf Hetzner

### Messbare Ziele

- Pinterest Conversion-Tracking funktioniert (PageVisit → AddToCart → Checkout → Purchase)
- Bestellungen werden automatisch an Spreadconnect weitergeleitet
- Kunden erhalten Tracking-Nummern
- Rechnungen werden automatisch erstellt

---

## Scope

### IN Scope (MVP)

- Produktkatalog (Startseite, Kategorien, Produktdetail mit Varianten)
- Warenkorb im Frontend
- Checkout via WooCommerce-Seite (Redirect)
- Zahlungen via Mollie (Klarna, PayPal, Kreditkarte)
- Rechnungserstellung via Faktur Pro (ehem. WooRechnung, Zweischneider)
- Rechtliche Seiten (Impressum, AGB, Datenschutz, Widerruf) – selbst erstellt
- Cookie Consent (kostenloses Plugin)
- Kleinunternehmerregelung §19 UStG
- POD-Anbindung via Spreadconnect API
- Tracking-Nummern in WooCommerce + Kundenbenachrichtigung
- Pinterest Tag (Client-side) + Pinterest Conversions API (Server-side)
- User-Accounts (Login, Registrierung, Bestellhistorie, Adressen) – WooCommerce-seitig
- Mobile-first Design (Pinterest-Traffic ist überwiegend mobil)

### OUT of Scope (bewusst raus)

- Suche / Volltextsuche / Algolia
- Wishlist / Merkliste
- Bewertungen / Reviews
- Blog / Content-Seiten
- Google Ads Integration
- Rabattcodes / Coupons
- Multi-Language / Mehrsprachigkeit
- Newsletter / E-Mail-Marketing
- SevDesk / Buchhaltungs-Anbindung
- Germanized Plugin (kein Mehrwert für diesen Case)
- Eigener Checkout-Flow (WooCommerce Checkout reicht)

### Annahmen

- Kleinunternehmerregelung bleibt bestehen (Umsatz < 22.000€/Jahr)
- Spreadconnect API ist verfügbar und kompatibel mit bestehenden Spreadshirt-Produkten
- Hetzner CX33 hat genug Ressourcen für WordPress + Next.js auf einem Server
- Mollie unterstützt Kleinunternehmer-Accounts
- Pinterest Conversions API ist ohne Mindestbudget nutzbar

---

## Context & Research

### Headless Commerce – Optionen evaluiert

| Option | Bewertung | Entscheidung |
|--------|-----------|-------------|
| WooCommerce + Next.js | Bekanntes System, riesiges Plugin-Ökosystem für DE-Markt, Checkout + Payments out of the box | ✅ Gewählt |
| Medusa.js | Modern, TypeScript, aber Rechnungen/DE-Recht alles selbst bauen | ❌ Zu viel Eigenentwicklung |
| Vendure | Stabil, NestJS, aber kleine Community, kein DE-Ökosystem | ❌ |
| Shopify | SaaS-Kosten, Vendor Lock-in, Transaktionsgebühren | ❌ |

### POD-Anbieter mit API (Deutschland)

| Anbieter | API | Besonderheit |
|----------|-----|-------------|
| Spreadconnect | REST API, kostenlos | Spreadshirt-eigenes Fulfillment, bestehende Produkte nutzbar |
| Shirtigo | REST API (Python/JS/PHP Clients) | Produktion in DE, Mockup-Rendering per API |
| Gelato | REST API | Global, DE-Produktion verfügbar |
| Prodigi | REST API | UK/EU Netzwerk, White-Label |

**Entscheidung:** Spreadconnect (bestehender Account + Produkte auf Spreadshirt vorhanden)

### WooCommerce API-Anbindung

| Weg | Beschreibung |
|-----|-------------|
| WooCommerce REST API | Offiziell, stabil, JS-Client vorhanden |
| WPGraphQL + WooGraphQL | GraphQL-Layer, flexibler für Frontend-Dev, Community-Plugin |

**Entscheidung:** WPGraphQL + WooGraphQL (besseres Developer-Erlebnis für Next.js Frontend)

### Pinterest Tracking

- **Pinterest Tag** (Client-side): JavaScript-Snippet für PageVisit, ViewCategory, AddToCart Events
- **Pinterest Conversions API** (Server-side): API-Calls vom Backend, umgeht Ad-Blocker/Cookie-Restrictions
- Pinterest empfiehlt beide zusammen → ~24% mehr erfasste Conversions, ~9% besserer CPA
- Event-Deduplizierung über `event_id` Parameter nötig wenn beides aktiv
- Kein Mindestbudget, braucht Pinterest Business Account + Access Token

### Checkout-Strategie

| Ansatz | Aufwand | Payment-Support |
|--------|---------|-----------------|
| Hybrid (Warenkorb Next.js, Checkout redirect WooCommerce) | Niedrig | Alle WooCommerce Gateways sofort |
| Komplett headless (Stripe Elements) | Hoch | Nur Stripe |
| WooCommerce Store API | Mittel | Eingeschränkt |

**Entscheidung:** Hybrid – Warenkorb im Next.js Frontend, "Zur Kasse" leitet auf WooCommerce Checkout weiter. Alle Mollie-Zahlungsarten sofort verfügbar ohne eigene Payment-Integration.

### Rechnungs-Plugin

- **Faktur Pro for WooCommerce** (Plugin-Slug: `woorechnung`)
- Hersteller: Zweischneider GmbH & Co. KG
- Erstellt PDF-Rechnungen, Lieferscheine, Gutschriften automatisch
- Freie Layout-Gestaltung (HTML + Mustache Templates)
- Braucht Account auf faktur.pro
- Bereits bekannt und erfolgreich eingesetzt

---

## User Flows

### Flow 1: Produkt finden und kaufen

1. Kunde kommt via Pinterest auf Produktseite (oder Startseite/Kategorie)
2. Wählt Variante (Größe, Farbe)
3. Klickt "In den Warenkorb"
4. Warenkorb öffnet sich (Menge ändern, entfernen möglich)
5. Klickt "Zur Kasse"
6. **Redirect auf WooCommerce Checkout-Seite**
7. Gibt Adressdaten ein (oder Login für Bestandskunden)
8. Wählt Zahlungsart (Klarna / PayPal / Kreditkarte via Mollie)
9. Schließt Zahlung ab
10. Bestellbestätigung wird angezeigt
11. Bestellbestätigungs-E-Mail + Rechnung (PDF via Faktur Pro)

**Fehler-Pfade:**
- Zahlung fehlgeschlagen → Zurück zur Zahlungsauswahl
- Produkt nicht mehr verfügbar → Hinweis im Warenkorb
- Session abgelaufen → Warenkorb bleibt erhalten (WooCommerce Session)

### Flow 2: Bestellung wird fulfilt (POD)

1. Neue Bestellung in WooCommerce (Status: "Processing")
2. Bestellung wird automatisch an Spreadconnect API übermittelt
3. Spreadconnect produziert und versendet
4. Tracking-Nummer kommt via Webhook/API zurück
5. WooCommerce-Bestellung wird mit Tracking-Nummer aktualisiert
6. Kunde erhält Versandbenachrichtigung per E-Mail

### Flow 3: User-Account

1. Registrierung/Login auf WooCommerce-Seite
2. Bestellhistorie einsehen
3. Adressen verwalten
4. Aus dem Next.js Frontend: Link zu "Mein Konto" auf WooCommerce

### Flow 4: Pinterest Tracking (technisch)

1. Kunde landet auf Seite → Pinterest Tag feuert `page_visit` + CAPI sendet `page_visit`
2. Kunde sieht Kategorie → `view_category` (Tag + CAPI)
3. Kunde legt in Warenkorb → `add_to_cart` (Tag + CAPI)
4. Kunde geht zum Checkout → `checkout` (Tag auf WooCommerce-Seite)
5. Kunde kauft → `purchase` (CAPI serverseitig bei Order Complete)
6. Deduplizierung über gemeinsame `event_id`

---

## UI Layout & Context

### Screens im Next.js Frontend

| Screen | Inhalt |
|--------|--------|
| **Startseite** | Hero-Bereich, Featured Products, Kategorien-Übersicht |
| **Kategorieseite** | Produktliste mit Bild, Name, Preis. 2-Spalten-Grid (mobil), 3-4 Spalten (desktop). Horizontale Filter-Chips zum Filtern nach Produkttyp. Produktanzahl wird angezeigt |
| **Produktdetailseite** | Produktbild-Galerie mit Swipe + Dots-Navigation (mobil) / Thumbnail-Navigation (desktop), Varianten-Auswahl (Größe, Farbe), Preis mit Hinweis "inkl. Versandkosten", "In den Warenkorb"-Button, Beschreibung |
| **Warenkorb** | Produktliste mit Thumbnail, Name, gewählte Variante (Farbe + Größe), Mengensteuerung (+/-), Einzelpreis. Versandkosten als separate Zeile. Gesamtpreis mit §19 UStG Hinweis darunter. "Zur Kasse"-Button |

### Screens auf WooCommerce (Standard-Theme, minimal gestylt)

| Screen | Inhalt |
|--------|--------|
| **Checkout** | Adressformular, Zahlungsarten (Mollie), Bestellübersicht, AGB-Checkbox, "Kaufen"-Button |
| **Bestellbestätigung** | Bestellnummer, Zusammenfassung, nächste Schritte |
| **Mein Konto** | Login/Registrierung, Bestellhistorie, Adressen |
| **Rechtliche Seiten** | Impressum, AGB, Datenschutz, Widerruf |

---

## UI Components (Frontend)

| Component | Interaktion | States |
|-----------|-------------|--------|
| Produkt-Card | Klick → Produktdetailseite | Default, Hover (Scale/Shadow), Loading (Skeleton-Platzhalter), Sold Out |
| Varianten-Selector (Größe) | Auswahl einer Größe (Button-Reihe) | Verfügbar, Ausgewählt (gefüllter Hintergrund), Nicht verfügbar (ausgegraut, nicht klickbar) |
| Varianten-Selector (Farbe) | Auswahl einer Farbe (Color Swatches) | Verfügbar, Ausgewählt (Ring/Border um Swatch), Nicht verfügbar (ausgegraut + durchgestrichen) |
| "In den Warenkorb"-Button | Klick → Produkt zum Warenkorb | Default, Loading, Erfolg (kurze Bestätigung), Disabled (keine Variante gewählt) |
| Warenkorb-Icon (Header) | Klick → Warenkorb-Seite | Leer, Mit Anzahl-Badge |
| Mengen-Steuerung (Warenkorb) | +/- Buttons oder Input | Min 1, Aktualisiert Preis |
| "Entfernen"-Button (Warenkorb) | Klick → Produkt aus Warenkorb entfernen | Default, Confirmation optional |
| "Zur Kasse"-Button | Klick → Redirect auf WooCommerce Checkout | Default, Disabled (Warenkorb leer) |
| Cookie-Banner | Akzeptieren / Ablehnen | Sichtbar (erster Besuch), Versteckt (nach Entscheidung) |

---

## Business Rules

| Regel | Beschreibung |
|-------|-------------|
| Kleinunternehmerregelung | Keine MwSt ausweisen. Hinweis auf Rechnungen: "Gemäß §19 UStG wird keine Umsatzsteuer berechnet." Gleicher Hinweis wird auch im Warenkorb unter dem Gesamtpreis angezeigt |
| Versand | Versandkosten werden von Spreadconnect/Spreadshirt definiert. In WooCommerce als Versandzone konfigurieren. Im Warenkorb als separate Zeile über dem Gesamtpreis angezeigt. Auf Produktdetailseite Hinweis "inkl. Versandkosten" unter dem Preis |
| Widerrufsrecht | 14 Tage, Seite muss verlinkt sein im Checkout (WooCommerce Standard) |
| Cookie Consent | Pinterest Tag darf erst nach Consent feuern. CAPI ist serverseitig und consent-unabhängig |
| Produkt-Verfügbarkeit | Kommt von Spreadconnect API – Produkte sind Print-on-Demand, also prinzipiell immer verfügbar |
| Bestellweiterleitung | Jede bezahlte Bestellung muss automatisch an Spreadconnect gehen. Keine manuelle Bearbeitung |
| Rechnungsnummern | Fortlaufend, von Faktur Pro verwaltet. GoBD-konform |

---

## Data Fields

### Produkt (aus WooCommerce / Spreadconnect)

| Feld | Required | Validierung |
|------|----------|-------------|
| Name | Ja | Min 1 Zeichen |
| Beschreibung | Ja | - |
| Preis | Ja | > 0, in EUR |
| Bilder | Ja | Min 1 Bild |
| Kategorien | Ja | Min 1 Kategorie |
| Varianten (Größe) | Ja | Definierte Größen (S, M, L, XL, etc.) |
| Varianten (Farbe) | Ja | Definierte Farben mit Farbcode |
| Spreadconnect Produkt-ID | Ja | Für API-Zuordnung |

### Bestellung (WooCommerce)

| Feld | Required | Validierung |
|------|----------|-------------|
| Vorname | Ja | Min 1 Zeichen |
| Nachname | Ja | Min 1 Zeichen |
| E-Mail | Ja | Gültiges E-Mail-Format |
| Straße + Hausnummer | Ja | Min 1 Zeichen |
| PLZ | Ja | 5 Ziffern (DE) |
| Ort | Ja | Min 1 Zeichen |
| Land | Ja | Default: Deutschland |
| Zahlungsart | Ja | Mollie: Klarna / PayPal / Kreditkarte |

---

## Feature State Machine

### Bestellstatus

| State | Trigger | Nächster State | UI Feedback |
|-------|---------|---------------|-------------|
| Pending | Kunde klickt "Kaufen" | Processing / Failed | Weiterleitung zu Mollie |
| Failed | Zahlung fehlgeschlagen | Pending (Retry) | Fehlermeldung, erneut versuchen |
| Processing | Zahlung erfolgreich | On Hold / Completed | Bestätigungsseite + E-Mail + Rechnung |
| On Hold | Warte auf Spreadconnect-Versand | Completed | - |
| Completed | Tracking-Nummer von Spreadconnect | - | Versand-E-Mail mit Tracking |
| Refunded | Manuell: Widerruf | - | Gutschrift via Faktur Pro |

### Warenkorb (Frontend)

| State | Trigger | Nächster State | UI Feedback |
|-------|---------|---------------|-------------|
| Leer | - | Mit Produkten | "Warenkorb ist leer" + Link zum Shop |
| Mit Produkten | Add to Cart | Mit Produkten (aktualisiert) | Badge-Counter aktualisiert, kurze Bestätigung |
| Mit Produkten | Menge ändern | Mit Produkten (aktualisiert) | Preis aktualisiert |
| Mit Produkten | Entfernen (letztes Produkt) | Leer | Warenkorb-Seite zeigt leeren State |
| Mit Produkten | "Zur Kasse" klicken | Redirect | Weiterleitung zu WooCommerce Checkout |

---

## Implementation Slices

### Slice 1: Infrastruktur

- WordPress + WooCommerce auf Hetzner CX33 aufsetzen
- Next.js auf gleichem Server
- Nginx als Reverse Proxy (Frontend-Domain → Next.js, /wp-admin und /checkout → WordPress)
- SSL via Let's Encrypt
- WPGraphQL + WooGraphQL installieren und konfigurieren
- WooCommerce Grundkonfiguration (Kleinunternehmer, Versand DE, Währung EUR)

**Testbar:** WPGraphQL Playground erreichbar, Produkte per GraphQL abfragbar

### Slice 2: Produktkatalog (Frontend)

- Startseite mit Featured Products
- Kategorieseiten mit Produktliste
- Produktdetailseite mit Varianten-Auswahl (Größe, Farbe)
- Bilder, Preis, Beschreibung
- Mobile-first, responsive Design

**Abhängig von:** Slice 1
**Testbar:** Produkte aus WooCommerce werden im Next.js Frontend angezeigt

### Slice 3: Warenkorb + Checkout-Redirect

- "In den Warenkorb"-Funktion
- Warenkorb-Seite (Menge ändern, entfernen, Gesamtpreis)
- "Zur Kasse" → Redirect auf WooCommerce Checkout
- Mollie Plugin installieren und konfigurieren
- Bestellbestätigung

**Abhängig von:** Slice 2
**Testbar:** Kompletter Kaufvorgang durchführbar (Test-Zahlung via Mollie Sandbox)

### Slice 4: Rechtliches + Rechnungen

- Faktur Pro Plugin installieren + konfigurieren
- Rechnung wird automatisch bei Bestellung erstellt (PDF)
- Hinweis §19 UStG auf Rechnung
- Impressum, AGB, Datenschutz, Widerruf als WordPress-Seiten
- Cookie Consent Plugin (kostenlos)

**Abhängig von:** Slice 3
**Testbar:** Testbestellung erzeugt korrekte Rechnung mit §19-Hinweis

### Slice 5: POD-Anbindung (Spreadconnect)

- Spreadconnect API-Zugang beantragen
- Bestehende Spreadshirt-Designs mit Spreadconnect verknüpfen
- WooCommerce-Plugin oder Custom Hook: bei neuer Bestellung → Order an Spreadconnect API
- Tracking-Nummer von Spreadconnect empfangen → in WooCommerce-Bestellung eintragen
- Versandbenachrichtigung an Kunden

**Abhängig von:** Slice 3
**Testbar:** Testbestellung wird an Spreadconnect übermittelt, Tracking kommt zurück

### Slice 6: Pinterest Tracking

- Pinterest Business Account einrichten
- Pinterest Tag im Next.js Frontend (page_visit, view_category, add_to_cart)
- Pinterest Tag auf WooCommerce Checkout-Seite (checkout)
- Pinterest Conversions API serverseitig (purchase bei Order Complete)
- Event-Deduplizierung konfigurieren (event_id)
- Validierung über Pinterest Tag Helper + Events Manager

**Abhängig von:** Slice 3
**Testbar:** Events erscheinen im Pinterest Events Manager, Deduplizierung funktioniert

### Slice 7: User-Accounts

- Login / Registrierung auf WooCommerce-Seite
- "Mein Konto"-Link im Next.js Frontend Header → WooCommerce Account-Seite
- Bestellhistorie einsehbar
- Adressverwaltung

**Abhängig von:** Slice 3
**Testbar:** Registrierung, Login, Bestellhistorie nach Testkauf sichtbar

---

## Q&A Log

| # | Frage | Antwort |
|---|-------|---------|
| 1 | Soll zuerst recherchiert werden oder direkt Fragen beantwortet? | Recherche zuerst durchführen |
| 2 | Welches Backend-System? WooCommerce, Medusa, Vendure, Shopify? | WooCommerce – bekanntes System, Plugin-Ökosystem für DE, erweiterbar mit eigenen Plugins |
| 3 | Frontend: Starter-Template kaufen oder selbst bauen? | Selbst bauen mit Next.js. Starter haben immer eigenen Stil, der nervt. Scope ist überschaubar |
| 4 | Hosting: Vercel oder self-hosted? | Self-hosted auf Hetzner CX33, sowohl Backend als auch Frontend |
| 5 | Checkout: Eigener Flow oder WooCommerce Checkout-Seite? | WooCommerce Checkout-Seite. Riesen-Vorteil: alle Zahlungsarten (Mollie) sofort verfügbar ohne eigene Payment-Integration. Nur eine Seite, Button drücken, fertig |
| 6 | Germanized Plugin nutzen? | Nein. Kein Mehrwert – Steuern kann man selbst konfigurieren, Rechtliche Seiten selbst anlegen, Rechnungen über eigenes Plugin |
| 7 | Welches Rechnungs-Plugin? | Faktur Pro (ehem. WooRechnung) von Zweischneider. Bereits erfolgreich eingesetzt |
| 8 | Zahlungsanbieter: Mollie oder Stripe? | Mollie – hat Klarna, PayPal, Kreditkarte in einem Plugin |
| 9 | Kleinunternehmerregelung? | Ja, §19 UStG |
| 10 | Welche Produktkategorien zum Start? | T-Shirts, Hoodies, ggf. weitere (wird noch festgelegt) |
| 11 | Domain vorhanden? | Nein, muss noch registriert werden |
| 12 | Slice-Reihenfolge okay? | Ja, prinzipiell okay |
| 13 | Spreadconnect API-Zugang vorhanden? | Nein, nur bestehender Spreadshirt-Account mit Produkten. API-Zugang muss beantragt werden |
| 14 | SevDesk anbinden? | Nein, nicht im MVP. Kostet Geld |
