# E2E Checklist: Shop Completeness Phase 1

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-26
**Feature:** Shop Completeness — Blocks & Pages

---

## Pre-Conditions

- [ ] Alle 6 Slices APPROVED (Gate 2)
- [ ] Architecture APPROVED (Gate 1)
- [ ] Integration Map Verdict: READY FOR ORCHESTRATION
- [ ] `pnpm test tests/slices/shop-completeness/` laeuft vollstaendig gruen
- [ ] `pnpm build` ohne TypeScript-Fehler
- [ ] WordPress + WooCommerce laufen (Docker: `docker compose up -d`)
- [ ] Next.js Dev-Server laeuft (`cd frontend && pnpm dev`)
- [ ] Mindestens 1 Produkt mit Reviews in WooCommerce vorhanden
- [ ] Mindestens 1 WooCommerce-Kategorie mit mehr als 24 Produkten vorhanden (fuer Pagination-Test)
- [ ] `announcement-dismissed-launch-2026` Key ist in localStorage NICHT gesetzt (Frisch-Browser oder Inkognito)

---

## Journey 1: Startseite

### J1.1 Announcement Bar

- [ ] Startseite (`/`) laden
- [ ] Announcement Bar erscheint oberhalb des Headers (kein Flicker)
- [ ] Text entspricht dem konfigurierten Wert in `global.yaml`
- [ ] X-Button hat `aria-label="Ankündigung schliessen"` (Dev-Tools pruefen)
- [ ] X-Button klicken → Bar verschwindet ohne Page-Reload
- [ ] `localStorage['announcement-dismissed-launch-2026']` ist nach Dismiss gesetzt (Dev-Tools pruefen)
- [ ] Seite neu laden → Bar bleibt ausgeblendet (kein Flicker/Flash)
- [ ] Anderen Tab oeffnen → Bar ebenfalls ausgeblendet

### J1.2 Testimonials Block

- [ ] Testimonials-Grid unter `category-showcase` sichtbar
- [ ] Mindestens 3 Testimonial-Karten im Grid
- [ ] Karten enthalten: Name, Zitat-Text (`<blockquote>`), Sterne-Anzeige
- [ ] Bei `rating: 5` → genau 5 gefuellte Sterne sichtbar
- [ ] `aria-label="N von 5 Sternen"` auf Sterne-Container (Screen-Reader-Test oder Dev-Tools)
- [ ] `location` (z.B. "Muenchen") wird angezeigt wenn konfiguriert
- [ ] Mobile (< 768px): 1-spaltig; Tablet (768-1024px): 2-spaltig; Desktop (> 1024px): 3-spaltig

### J1.3 Featured Collection Block

- [ ] Featured Collection Block unter Testimonials sichtbar
- [ ] Kategorie-Titel als Heading sichtbar
- [ ] 4 Produkt-Cards im Grid (2 Mobile, 4 Desktop)
- [ ] "Alle ansehen"-Link vorhanden und navigiert zu `/kategorie/{slug}` (semantischer `<a>`-Tag)
- [ ] Bei Kategorie ohne Bild: Block ohne Bild gerendert (Titel + Cards sichtbar, kein broken image)
- [ ] Produkt-Card klicken → navigiert zu `/produkt/[slug]`

### J1.4 Newsletter-Signup Block

- [ ] Newsletter-Block unter Featured Collection sichtbar
- [ ] Headline, Beschreibungstext, E-Mail-Input und Submit-Button sichtbar
- [ ] Leeres Formular absenden → Fehler "Bitte gib eine gueltige E-Mail-Adresse ein" erscheint als Inline-Fehler
- [ ] Ungueltige E-Mail ("kein-at-zeichen") eingeben, Submit → Inline-Fehlermeldung
- [ ] Gueltige E-Mail ("test@example.com") eingeben, Submit → Button wechselt zu Loading-State (disabled + Spinner)
- [ ] Nach kurzer Verzoegerung → Erfolgsmeldung erscheint, Formular ausgeblendet
- [ ] Kein echtes API-Call (Network-Tab pruefen: kein POST-Request)

---

## Journey 2: Kategorie-Page (mit Pagination und Sortierung)

### J2.1 Breadcrumb-Navigation

- [ ] Kategorie-Page (`/kategorie/t-shirts`) laden
- [ ] Breadcrumb oberhalb Page-Heading sichtbar: "Startseite › T-Shirts"
- [ ] "Startseite" ist ein klickbarer Link zu "/"
- [ ] "T-Shirts" ist nicht klickbar, hat `aria-current="page"` (Dev-Tools)
- [ ] `<nav aria-label="Breadcrumb">` vorhanden (Screen-Reader-Test oder Dev-Tools)
- [ ] JSON-LD BreadcrumbList im `<script type="application/ld+json">` Tag vorhanden (Dev-Tools)

### J2.2 Sort-Bar

- [ ] Sort-Dropdown unter Product-Count sichtbar mit Label "Sortieren nach:"
- [ ] Dropdown zeigt 4 Optionen: "Empfohlen", "Preis: aufsteigend", "Preis: absteigend", "Neueste zuerst"
- [ ] "Empfohlen" ist standardmaessig ausgewaehlt
- [ ] "Preis: aufsteigend" waehlen → URL aendert sich zu `?sort=price_asc`, Seite neu gerendert
- [ ] Nach Sort-Wechsel wird `page`-Parameter auf 1 zurueckgesetzt (kein Verbleiben auf Seite 3 mit neuer Sortierung)
- [ ] `<select aria-label="Produkte sortieren">` vorhanden (Dev-Tools)

### J2.3 Pagination

- [ ] Kategorie mit mehr als 24 Produkten aufrufen → Pagination erscheint unterhalb des Produktgrids
- [ ] Format: `‹ 1 [2] 3 ... 8 ›` (erste und letzte Seite immer sichtbar, Ellipsis bei Luecken)
- [ ] Seite 1: Prev-Button hat `aria-disabled="true"` und kein klickbares href
- [ ] Letzte Seite: Next-Button hat `aria-disabled="true"`
- [ ] Aktuelle Seite hat `aria-current="page"` (Dev-Tools)
- [ ] Alle Seitenlinks sind echte `<a href>` Links (Cmd+Click oeffnet neuen Tab korrekt)
- [ ] Pagination-Link klicken → URL wechselt zu `?page=N`, korrekte Produkte geladen
- [ ] Sort-Param bleibt erhalten beim Seitenwechsel: `/kategorie/t-shirts?sort=price_asc&page=2`
- [ ] `<nav aria-label="Seitennavigation">` vorhanden (Dev-Tools)
- [ ] Touch-Targets mindestens 44x44px (Mobile-Test)

### J2.4 Empty State (bei Kategorie ohne Produkte)

- [ ] Kategorie ohne Produkte aufrufen
- [ ] Empty-State-Block erscheint statt leerem Grid
- [ ] Icon (Lupe oder PackageOpen) sichtbar
- [ ] Headline "Keine Produkte gefunden" sichtbar
- [ ] Suggestion-Links als klickbare `<a href>` Links sichtbar
- [ ] Kein leerer Grid-Container sichtbar

---

## Journey 3: Produktdetail-Seite

### J3.1 Trust Badges

- [ ] Produkt-Page (`/produkt/[slug]`) laden
- [ ] Trust-Badges-Section unterhalb von Product-Description sichtbar
- [ ] 3 Badges: "Versand in 3-5 Werktagen", "30 Tage Rueckgabe", "Sichere Zahlung"
- [ ] Icons haben `aria-hidden={true}` (kein Text-Duplikat fuer Screen-Reader)
- [ ] `<section aria-label="Vertrauenssiegel">` vorhanden (Dev-Tools)

### J3.2 Produktbewertungen — Anzeige

- [ ] Product-Reviews-Block unterhalb Trust-Badges sichtbar (wenn Produkt Reviews hat)
- [ ] Sterne-Zusammenfassung: Durchschnitt (z.B. "4,2") und Anzahl (z.B. "47 Bewertungen")
- [ ] Review-Liste mit einzelnen Review-Karten: Name, Datum, Sterne, Text
- [ ] "Bewertung schreiben"-Button sichtbar
- [ ] Bei Produkt ohne Reviews + `reviewsAllowed: true`: Nur Button, keine "Noch keine Bewertungen"-Meldung
- [ ] Bei `reviewsAllowed: false`: Gesamter Block unsichtbar (kein leerer Bereich)

### J3.3 Produktbewertungen — Formular

- [ ] "Bewertung schreiben" klicken → Formular erscheint (Slide-in-Animation)
- [ ] Formular-Felder: Name, E-Mail, Sterne-Auswahl (1-5), Bewertungstext
- [ ] Sterne-Buttons haben `aria-label="N Stern(e)"` und `aria-pressed` State (Dev-Tools)
- [ ] "Abbrechen" klicken → Formular schliesst, Button wieder sichtbar
- [ ] Leeres Formular absenden → Inline-Fehlermeldungen pro Feld
- [ ] Name < 2 Zeichen → Fehler "min. 2 Zeichen"
- [ ] Ungueltige E-Mail → Fehlermeldung
- [ ] Rating = 0 → Fehlermeldung
- [ ] Text < 10 Zeichen → Fehler "mindestens 10 Zeichen"
- [ ] `aria-invalid="true"` auf fehlerhaften Inputs (Dev-Tools)
- [ ] Gueltiges Formular absenden → Button zeigt Spinner (disabled), Mutation wird gesendet
- [ ] Erfolgreiche Mutation → Formular verschwindet, Danke-Text erscheint ("Deine Bewertung wird nach Pruefung angezeigt")
- [ ] `aria-live="polite"` Container fuer Status-Meldungen (Dev-Tools)

### J3.4 Produktbewertungen — Fehler-Szenario

- [ ] Backend-Fehler simulieren (Netzwerk offline oder GraphQL-Error)
- [ ] Toast-Meldung "Bewertung konnte nicht gespeichert werden. Bitte versuche es erneut." erscheint
- [ ] Formular bleibt offen mit eingegebenen Werten erhalten

### J3.5 Produkt-Empfehlungen

- [ ] Product-Recommendations-Block unterhalb Reviews sichtbar (wenn verwandte Produkte vorhanden)
- [ ] Ueberschrift aus YAML ("Das koennte dir auch gefallen") sichtbar
- [ ] Grid mit 4 Produkt-Cards (Desktop: 4 Spalten, Mobile: 2 Spalten)
- [ ] Produkt-Card klicken → navigiert zu `/produkt/[slug]` des empfohlenen Produkts
- [ ] Bei Produkt ohne verwandte Produkte → Fallback auf Kategorie-Produkte (kein leerer Block)
- [ ] Bei 0 verwandten und 0 Kategorie-Produkten → Block vollstaendig unsichtbar

---

## Journey 4: Suchseite

### J4.1 Header-Suchlink

- [ ] Suchicon im Header sichtbar (neben Cart-Icon)
- [ ] Icon-Link hat `aria-label="Suche oeffnen"` (Dev-Tools)
- [ ] Suchicon klicken → navigiert zu `/suche`
- [ ] `href="/suche"` am Link-Element (Dev-Tools)

### J4.2 Suchanfrage mit Ergebnissen

- [ ] `/suche` aufrufen → Suchfeld mit `role="search"` sichtbar
- [ ] Suchbegriff eingeben (z.B. "shirt") und Enter oder "Suchen"-Button
- [ ] URL wechselt zu `/suche?q=shirt`
- [ ] Produkt-Grid mit Ergebnissen erscheint
- [ ] Ergebnisanzahl angezeigt (z.B. "47 Ergebnisse fuer 'shirt'")
- [ ] Sortierung verfuegbar (Sort-Bar sichtbar)
- [ ] Pagination bei mehr als 24 Ergebnissen

### J4.3 Such-URL-Parameter bleiben erhalten

- [ ] Bei `/suche?q=shirt&sort=price_asc` → Suchfeld zeigt "shirt", Sort-Dropdown zeigt "Preis: aufsteigend"
- [ ] Seitenwechsel ueber Pagination → URL: `/suche?q=shirt&sort=price_asc&page=2` (q und sort erhalten)
- [ ] Sort-Wechsel → URL: `/suche?q=shirt&sort=price_desc` (q bleibt erhalten, page wird zurueckgesetzt)

### J4.4 Clear-Button

- [ ] Suchfeld mit Query "shirt" → Clear-Button (X) erscheint
- [ ] Clear-Button klicken → Suchfeld geleert, navigiert zu `/suche` (ohne q-Param)
- [ ] Clear-Button hat `aria-label="Suche loeschen"` (Dev-Tools)

### J4.5 Mindestzeichenregel

- [ ] 1 Zeichen eingeben ("a") + Enter → kein API-Call, keine Ergebnisse, Empty-State oder Hinweis sichtbar
- [ ] Leere Suche → kein API-Call

### J4.6 Empty State bei keinen Ergebnissen

- [ ] Unbekannten Suchbegriff eingeben ("xyzunbekannt123")
- [ ] Empty-State erscheint: "Keine Ergebnisse gefunden"
- [ ] Kein leeres Produkt-Grid sichtbar
- [ ] Suggestion-Links sichtbar

### J4.7 SEO-Checks Suchseite

- [ ] Page-Source-Code pruefen: `<meta name="robots" content="noindex, follow">` vorhanden
- [ ] `<title>` enthaelt Suchbegriff (z.B. "shirt – Suche")
- [ ] `/suche` ohne Query → generischer Title "Suche"

---

## Journey 5: Collections-Page (Kollektion)

### J5.1 Collection-Header

- [ ] `/kollektion/[slug]` mit gueltiger WooCommerce-Kategorie aufrufen
- [ ] `<h1>` mit Kategorie-Namen sichtbar
- [ ] Kategorie-Bild als Hero-Bild mit dunklem Overlay sichtbar (wenn vorhanden)
- [ ] Kategorie-Beschreibung unterhalb sichtbar
- [ ] Ohne Bild → `bg-secondary` Fallback statt kaputtem Bild

### J5.2 Collections-Page Produkt-Grid und Pagination

- [ ] Produkt-Grid unterhalb des Headers sichtbar
- [ ] Pagination bei mehr als 24 Produkten
- [ ] `?page=N&sort=X` URL-Pattern funktioniert analog zur Kategorie-Page
- [ ] Sortierung verfuegbar (sort-bar nicht in collection.yaml gemaess Spec — pruefen ob architecture.md hier abweicht)

### J5.3 Collections-Page Error-Cases

- [ ] `/kollektion/nicht-existent-slug` aufrufen → 404-Page erscheint (nicht-branded Next.js Standard oder gebrandete 404)
- [ ] Ungueltige `?page=abc` URL → Redirect zu `/kollektion/[slug]` (Seite 1)

### J5.4 SEO-Checks Collections-Page

- [ ] `<title>` enthaelt Kategorie-Namen
- [ ] `<link rel="canonical">` auf `/kollektion/[slug]` zeigt (ohne page-Param)
- [ ] Seiten sind indexierbar (kein noindex auf Collection-Pages)

---

## Journey 6: Danke-Page (Order-Bestaetigung)

### J6.1 Mit Bestellnummer

- [ ] `/danke?order_id=12345` aufrufen
- [ ] Gruenes Haekchen-Icon (`CheckCircle2`) sichtbar
- [ ] Headline "Vielen Dank fuer deine Bestellung!" sichtbar
- [ ] Bestellnummer "#12345" nach kurzer Hydration-Verzoegerung sichtbar
- [ ] Bestaetigungstext sichtbar
- [ ] E-Mail-Hinweis-Text sichtbar
- [ ] "Weiter einkaufen" CTA-Link navigiert zu `/`

### J6.2 Ohne Bestellnummer

- [ ] `/danke` (ohne `order_id`) aufrufen
- [ ] Keine Bestellnummer-Anzeige (kein "#undefined" oder leerer Block)
- [ ] Alle anderen Texte und der CTA-Link sichtbar

### J6.3 SEO-Checks Danke-Page

- [ ] `<meta name="robots" content="noindex">` vorhanden (Page-Source oder Dev-Tools)

---

## Journey 7: 404-Page

### J7.1 Gebrandete 404

- [ ] Unbekannte URL aufrufen (z.B. `/diese-seite-existiert-nicht`)
- [ ] `<h1>Diese Seite wurde nicht gefunden</h1>` sichtbar
- [ ] Link zu "/" vorhanden
- [ ] Link zu `/kategorie/alle` vorhanden
- [ ] Branded Design (nicht Next.js Standard-404)

### J7.2 SEO-Checks 404

- [ ] `<meta name="robots" content="noindex">` vorhanden

---

## Journey 8: Cross-Page Global Funktionen

### J8.1 Announcement Bar auf allen Pages

- [ ] Announcement Bar erscheint auf Startseite (`/`)
- [ ] Announcement Bar erscheint auf Kategorie-Page (`/kategorie/t-shirts`)
- [ ] Announcement Bar erscheint auf Produkt-Page (`/produkt/[slug]`)
- [ ] Announcement Bar erscheint auf Suchseite (`/suche`)
- [ ] Einmal dismisst → auf allen Pages ausgeblendet (localStorage-basiert)

---

## Mobile-Checks (Pinterest-Traffic-Schwerpunkt)

### M1 Touch-Targets

- [ ] Alle Pagination-Buttons: mindestens 44x44px Touch-Target (Mobile-Chrome-DevTools)
- [ ] Sort-Dropdown: ausreichend gross fuer Finger-Bedienung
- [ ] Announcement Bar X-Button: mindestens 44x44px
- [ ] Sterne-Buttons im Review-Formular: mindestens 44x44px
- [ ] Newsletter-Submit-Button: mindestens 44x44px
- [ ] Header-Suchlink: mindestens 44x44px

### M2 Mobile-Layouts

- [ ] Testimonials: 1-spaltig (< 768px), 2-spaltig (768-1024px), 3-spaltig (> 1024px)
- [ ] Product-Recommendations: 2-spaltig (Mobile), 4-spaltig (Desktop)
- [ ] Search-Results: 2-spaltig (Mobile), 3-spaltig (Tablet), 4-spaltig (Desktop)
- [ ] Trust-Badges: `flex-wrap` auf Mobile (Badge-Umbruch korrekt)
- [ ] Announcement Bar: Text zentriert, X-Button rechts — kein Overflow

### M3 `prefers-reduced-motion`

- [ ] Im Betriebssystem "Bewegung reduzieren" aktivieren
- [ ] Announcement Bar Dismiss: keine Animation (sofortiges Verschwinden)
- [ ] Review-Formular Slide-in: keine Animation
- [ ] Newsletter Success-Transition: keine Animation

---

## SEO-Checks (Zusammenfassung)

| Page | Erwartetes Verhalten | Status |
|------|---------------------|--------|
| `/suche` | `robots: noindex, follow` | [ ] |
| `/danke` | `robots: noindex` | [ ] |
| `not-found` | `robots: noindex` | [ ] |
| `/kollektion/[slug]` | Indexierbar, Canonical korrekt | [ ] |
| `/kategorie/[slug]?page=N` | Pagination-Links als echte `<a href>` (SEO) | [ ] |
| Breadcrumb | JSON-LD BreadcrumbList vorhanden | [ ] |
| Collections `?page=` | Keine noindex auf paginierten Kategorie-/Collections-Pages | [ ] |

---

## Pinterest-Tracking-Checks

### P1 Consent Gate

- [ ] `localStorage['cookie-consent']` ist NICHT gesetzt → kein `pintrk()`-Call beim Seitenaufruf (Network-Tab)
- [ ] `localStorage['cookie-consent'] = 'accepted'` setzen → `pintrk('page')` wird beim naechsten Seitenaufruf gefeuert (Network-Tab)

### P2 Event-ID Deduplication

- [ ] Checkout-Abschluss → `/danke?order_id=X&event_id=Y` URL vorhanden
- [ ] Client-seitiger und server-seitiger Pinterest-Event haben gleiche `event_id` (Log-Vergleich)

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | Verifikation |
|---|-------------------|--------|--------------|
| 1 | `TrustBadgesBlock` in `product.yaml` | Slice 01 → Slice 02 | `/produkt/[slug]` laden, Trust-Badges sichtbar |
| 2 | `BreadcrumbBlock` in `category.yaml` mit WC-Kategorie-Name | Slice 01 + Slice 03 | `/kategorie/t-shirts` laden, Breadcrumb zeigt "T-Shirts" (nicht "t-shirts") |
| 3 | `PaginationBlock` mit PaginatedProductsResult-Daten | Slice 01 + Slice 03 | Kategorie mit > 24 Produkten: Pagination erscheint mit korrekten totalPages |
| 4 | `SortBarBlock` behaelt q-Param auf Suchseite | Slice 01 + Slice 05 | Suche "shirt", Sort-Wechsel zu price_asc → URL `/suche?q=shirt&sort=price_asc` |
| 5 | `loadGlobalConfig()` + `global.yaml` + `layout.tsx` | Slice 01 + Slice 04 | Announcement Bar auf allen Pages sichtbar |
| 6 | `GET_PRODUCTS_PAGINATED` mit `$search`-Variable | Slice 03 → Slice 05 | Suche "shirt" → Products-Query mit `search: "shirt"` in Network-Tab |
| 7 | `category_meta` Branch fuer `CollectionHeaderBlock` | Slice 03 → Slice 06 | `/kollektion/[slug]` → Kategorie-Name als `<h1>` korrekt aus WooCommerce |
| 8 | `products_by_category` Branch (paginiert) auf Collections-Page | Slice 03 → Slice 06 | `/kollektion/[slug]?page=2` → korrekte Produkte aus Seite 2 |
| 9 | `buildOrderby()` wiederverwendet in search_products | Slice 03 → Slice 05 | Suche mit `?sort=price_asc` → Produkte nach Preis aufsteigend sortiert |
| 10 | `FeaturedCollectionBlock` kombiniert GET_CATEGORY_META + GET_PRODUCTS_PAGINATED | Slice 03 → Slice 04 | Homepage: Featured Collection zeigt Kategorie-Bild und 4 Produkte |

---

## Sign-Off

| Pruefschritt | Tester | Datum | Ergebnis |
|-------------|--------|-------|---------|
| Journey 1: Startseite | — | — | [ ] PASS / [ ] FAIL |
| Journey 2: Kategorie-Page | — | — | [ ] PASS / [ ] FAIL |
| Journey 3: Produktdetail-Seite | — | — | [ ] PASS / [ ] FAIL |
| Journey 4: Suchseite | — | — | [ ] PASS / [ ] FAIL |
| Journey 5: Collections-Page | — | — | [ ] PASS / [ ] FAIL |
| Journey 6: Danke-Page | — | — | [ ] PASS / [ ] FAIL |
| Journey 7: 404-Page | — | — | [ ] PASS / [ ] FAIL |
| Journey 8: Cross-Page Global | — | — | [ ] PASS / [ ] FAIL |
| Mobile-Checks | — | — | [ ] PASS / [ ] FAIL |
| SEO-Checks | — | — | [ ] PASS / [ ] FAIL |
| Pinterest-Tracking-Checks | — | — | [ ] PASS / [ ] FAIL |
| Cross-Slice Integration Points | — | — | [ ] PASS / [ ] FAIL |

**Gesamt-Ergebnis: [ ] PASS — Feature READY for Merge**

**Anmerkungen:**
