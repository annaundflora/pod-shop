# E2E Checklist: POD Shop MVP

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-21

---

## Pre-Conditions

- [ ] Alle 7 Slices APPROVED (Gate 2) - bestatigt in integration-map.md
- [ ] Architecture APPROVED (Gate 1)
- [ ] Integration Map hat keine MISSING INPUTS (0 gefunden)
- [ ] Docker Compose Stack lauft: `docker compose up -d`
- [ ] WordPress erreichbar unter `http://localhost:8080`
- [ ] WooCommerce aktiviert und konfiguriert (EUR, §19 UStG, DE-Versandzone)
- [ ] WPGraphQL + WooGraphQL aktiviert
- [ ] Next.js Frontend lauft: `cd frontend && pnpm dev` → `http://localhost:3000`

---

## Flow 1: Produkt finden und kaufen (Happy Path)

### Schritt 1: Infrastruktur prufen (Slice 01)

- [ ] `curl -f http://localhost:8080/graphql -X POST -H "Content-Type: application/json" -d '{"query":"{ products { nodes { id name } } }"}' && echo "GraphQL OK"`
- [ ] WooCommerce Admin unter `http://localhost:8080/wp-admin` erreichbar
- [ ] phpMyAdmin unter `http://localhost:8180` erreichbar

### Schritt 2: Startseite (Slice 02)

- [ ] `http://localhost:3000` aufrufen → Hero-Bereich mit CTA-Button sichtbar
- [ ] "Ausgewahlte Produkte" Sektion zeigt Featured Products (oder Skeleton-Loading wahrend Laden)
- [ ] "Kategorien" Sektion zeigt Kategorie-Kacheln mit Bild und Pfeil
- [ ] Produktkarte zeigt Bild, Name, Preis
- [ ] Produktkarte Hover: Scale/Shadow-Effekt (Desktop)

### Schritt 3: Kategorieseite (Slice 02)

- [ ] Kategorie-Kachel klicken → Weiterleitung auf `/kategorie/{slug}`
- [ ] Produktliste im 2-Spalten-Grid (Mobile) / 4-Spalten (Desktop)
- [ ] Produktanzahl wird angezeigt (z.B. "12 Produkte")
- [ ] Filter-Chips sichtbar, Klick filtert Produkte (URL-Parameter aktualisiert)
- [ ] Aktiver Filter-Chip ist visuell hervorgehoben (gefullter Hintergrund)
- [ ] Loading-State: Skeleton-Karten wahrend Datenladen

### Schritt 4: Cookie Consent Banner (Slice 04)

- [ ] Erster Besuch (Inkognito-Fenster): Cookie-Banner erscheint am unteren Rand
- [ ] Banner enthalt Text "Wir verwenden Cookies" und Link zur Datenschutzerklarung
- [ ] Button "Alle Akzeptieren" vorhanden (Primary Style, min 44px Touch-Target)
- [ ] Button "Nur Notwendige" vorhanden (Outline Style, min 44px Touch-Target)
- [ ] `localStorage.getItem("cookie-consent")` = `null` vor Entscheidung
- [ ] Klick "Alle Akzeptieren" → Banner verschwindet → `localStorage.getItem("cookie-consent")` = `"accepted"`
- [ ] Seitenreload: Banner erscheint NICHT erneut

### Schritt 5: Produktdetailseite (Slice 02)

- [ ] Produktkarte klicken → Weiterleitung auf `/produkt/{slug}`
- [ ] Produktbild-Galerie sichtbar (Hauptbild + Thumbnails Desktop / Dots Mobile)
- [ ] Touch-Swipe auf Mobile: Links/Rechts wechselt Bild (50px Delta-Schwelle)
- [ ] Thumbnail-Klick wechselt Hauptbild (Desktop)
- [ ] Produktname als `<h1>` vorhanden
- [ ] Preis angezeigt mit "inkl. Versandkosten" Hinweis darunter
- [ ] Grossenauswahl (Button-Reihe): Verfugbare Grossen klickbar, nicht verfugbare ausgegraut
- [ ] Farbauswahl (Color Swatches): Verfugbare Farben klickbar, nicht verfugbare mit Diagonal-Strich
- [ ] "In den Warenkorb"-Button: Disabled wenn keine Variante gewahlt ("Bitte Grosse und Farbe wahlen")
- [ ] JSON-LD Product Schema im HTML-Source (`<script type="application/ld+json">`)

### Schritt 6: Add to Cart (Slice 03)

- [ ] Grosse + Farbe wahlen → Button wird aktiv
- [ ] "In den Warenkorb" klicken → Button zeigt Loading-State (disabled + "Wird hinzugefugt...")
- [ ] Nach Mutation: Button zeigt Checkmark + "Hinzugefugt!" (grun, 2 Sekunden, dann Reset)
- [ ] Header Warenkorb-Icon: Badge-Counter erhoht sich um 1 (z.B. "1")
- [ ] `localStorage.getItem("woo-session-token")` enthalt Token (von Apollo sessionLink)
- [ ] `localStorage.getItem("pod-cart-backup")` enthalt JSON-Array mit Produkt

### Schritt 7: Pinterest add_to_cart Event prufen (Slice 06 - nur wenn Consent gegeben)

- [ ] Pinterest Tag SDK geladen (wenn `cookie-consent === "accepted"`)
- [ ] Browser DevTools → Network → `ct.pinterest.com` Request nach Add to Cart (addtocart Event)
- [ ] Event enthalt `event_id` (UUID v4 Format)
- [ ] `localStorage.getItem("pinterest-last-event-id")` = UUID v4

### Schritt 8: Warenkorb-Seite (Slice 03)

- [ ] Warenkorb-Icon klicken → Weiterleitung auf `/warenkorb`
- [ ] Produktzeile zeigt: Thumbnail (80x80), Name, Variante ("Farbe: X, Grosse: Y"), Menge, Zeilenpreis
- [ ] Mengensteuerung [-] [N] [+]: Bei Menge 1 ist [-] disabled
- [ ] [+] klicken → Menge erhoht, Zeilenpreis aktualisiert
- [ ] [-] klicken → Menge reduziert
- [ ] X-Button klicken → Produkt wird aus Warenkorb entfernt
- [ ] Letztes Produkt entfernen → Leer-State erscheint ("Dein Warenkorb ist leer." + "Weiter shoppen" Link)
- [ ] CartSummary: Versandkosten-Zeile sichtbar
- [ ] CartSummary: Gesamtpreis sichtbar (Versand + Produkte)
- [ ] CartSummary: Exakter Text "Gemaß §19 UStG wird keine Umsatzsteuer berechnet."
- [ ] "Zur Kasse"-Button: Disabled wenn Warenkorb leer

### Schritt 9: Checkout-Redirect (Slice 03)

- [ ] Produkt im Warenkorb vorhanden
- [ ] "Zur Kasse" klicken → Browser navigiert zu `http://localhost:8080/checkout?session-token={token}`
- [ ] URL enthalt `session-token` Parameter mit korrektem WooGraphQL Session Token
- [ ] WooCommerce Checkout-Seite ladt korrekt (Produkt in WooCommerce-Session vorhanden)
- [ ] Widerruf-Link im Checkout-Bereich (AGB-Checkbox) verlinkt auf `/widerruf` (Slice 04)

### Schritt 10: Checkout + Zahlung (Slice 03, Mollie Sandbox)

- [ ] Adressdaten eingeben (Testadresse)
- [ ] Zahlungsart wahlen (Mollie Kreditkarte: `3782 8224 6310 005` oder Mollie Test-Dashboard)
- [ ] "Kaufen" klicken → Weiterleitung zu Mollie
- [ ] Zahlung bestatigen → Weiterleitung zur WooCommerce Bestellbestatigung
- [ ] Bestellnummer sichtbar in WooCommerce Admin

### Schritt 11: Rechnung prufen (Slice 04)

- [ ] WooCommerce Admin → Bestellungen → Neue Bestellung aufrufen
- [ ] Status: "Processing"
- [ ] Faktur Pro hat PDF-Rechnung erstellt (WooCommerce Admin → Bestellung → Faktur Pro Tab)
- [ ] Rechnung enthalt: Rechnungsnummer, Datum, Bestellnummer, Produkt-Positionen, Versandkosten, Gesamtbetrag
- [ ] Rechnung enthalt Pflichttext: "Gemaß §19 UStG wird keine Umsatzsteuer berechnet."
- [ ] Rechnungsnummer ist fortlaufend (z.B. RE-2026-001)
- [ ] PDF-Anhang in WooCommerce Bestellbestatigungsmail vorhanden

### Schritt 12: Spreadconnect Weiterleitung (Slice 05)

- [ ] WooCommerce Admin → Bestellung → Custom Fields: `_spreadconnect_order_id` ist gesetzt (nach Processing-Status)
- [ ] Bestellnotiz enthalt "Spreadconnect Order erstellt: sc-..." Eintrag
- [ ] Health-Endpoint: `curl -s http://localhost:8080/wp-json/spreadconnect/v1/health | grep -q "ok" && echo "OK"`

### Schritt 13: Tracking + Order Complete (Slice 05)

- [ ] Spreadconnect Webhook simulieren: `curl -X POST http://localhost:8080/wp-json/spreadconnect/v1/webhook -H "Content-Type: application/json" -d '{"wcOrderId":{id},"trackingNumber":"TEST-123","trackingUrl":"https://tracking.example.com/TEST-123"}'`
- [ ] WooCommerce Admin → Bestellung: Status wechselt auf "Abgeschlossen" (Completed)
- [ ] Custom Fields: `_spreadconnect_tracking_number` = "TEST-123" gesetzt
- [ ] WooCommerce Versandbestatigungsmail wird an Kunden-Testadresse gesendet

### Schritt 14: Pinterest CAPI purchase Event (Slice 06)

- [ ] WooCommerce Order Status = "Completed"
- [ ] WordPress Error Log prufen: Kein `[Pinterest CAPI] WP_Error` Eintrag (nur wenn API-Keys konfiguriert)
- [ ] Pinterest Events Manager: purchase Event erscheint (mit konfigurierten API-Keys und echtem Account)
- [ ] event_id im CAPI-Event stimmt mit dem Frontend add_to_cart event_id uberein (Deduplizierung)

---

## Flow 2: User Account (Slice 07)

- [ ] Next.js Footer: "Mein Konto"-Link vorhanden und klickbar
- [ ] Footer-Link fuhrt zu `http://localhost:8080/mein-konto`
- [ ] Mobile Menu offnen → "Mein Konto"-Link vorhanden
- [ ] Mobile Menu Link fuhrt zu `http://localhost:8080/mein-konto`
- [ ] `http://localhost:8080/mein-konto`: Login-Formular + Registrierungsformular sichtbar
- [ ] Registrierung mit Testdaten (test@example.com) → Account-Dashboard sichtbar
- [ ] Ausloggen → Login-Formular erscheint erneut
- [ ] Login → Account-Dashboard, Bestellhistorie zeigt Testbestellung

---

## Edge Cases

### Cookie Consent - Ablehnen

- [ ] Inkognito-Fenster: Banner erscheint
- [ ] "Nur Notwendige" klicken → `localStorage.getItem("cookie-consent")` = `"rejected"`
- [ ] `http://s.pinimg.com` (Pinterest Tag Script) wird NICHT geladen (Network Tab)
- [ ] Add to Cart funktioniert weiterhin ohne Pinterest Tag Event

### Session-Verlust (Slice 03 Robustheit)

- [ ] Produkt in Warenkorb legen → `pod-cart-backup` in localStorage vorhanden
- [ ] Browser-Tab schliessen und neu offnen → Warenkorb-Seite zeigt noch Produkte (localStorage Backup)

### WooCommerce Fehler-Pfade

- [ ] Warenkorb leer → "Zur Kasse"-Button ist disabled (kein Klick moglich)
- [ ] Mengensteuerung: Bei Menge 99 ist [+] disabled

### Spreadconnect - Fehlender Article ID (Slice 05)

- [ ] Produkt ohne `_spreadconnect_article_id` kaufen → Admin-E-Mail mit Fehlerbenachrichtigung
- [ ] Bestellnotiz enthalt Hinweis auf fehlende Article ID
- [ ] WooCommerce Admin-E-Mail empfangen

### Rechtliche Seiten (Slice 04)

- [ ] `http://localhost:8080/impressum` → HTTP 200, Inhalt vorhanden
- [ ] `http://localhost:8080/agb` → HTTP 200, Inhalt vorhanden
- [ ] `http://localhost:8080/datenschutz` → HTTP 200, Inhalt vorhanden
- [ ] `http://localhost:8080/widerruf` → HTTP 200, Inhalt vorhanden
- [ ] Next.js Footer: Alle 4 Links (Impressum, AGB, Datenschutz, Widerruf) vorhanden und erreichbar

### prefers-reduced-motion (Slice 04)

- [ ] Chrome DevTools → Rendering → "Emulate CSS media feature prefers-reduced-motion" auf `reduce`
- [ ] Inkognito-Fenster → Cookie-Banner erscheint SOFORT ohne Slide-Up-Animation (kein translateY-Ubergang)

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | Verifikation |
|---|-------------------|--------|--------------|
| 1 | Apollo Session Token: CartContext liest WooGraphQL Token | 01 → 03 | `localStorage.getItem("woo-session-token")` nach Add-to-Cart |
| 2 | AddToCartButton Erfolgs-State nach addToCart Mutation | 02 → 03 | Button zeigt Checkmark + "Hinzugefugt!" fur 2 Sekunden |
| 3 | Header Badge-Counter nach Add-to-Cart | 02 → 03 | Badge-Zahl entspricht `cartItems.length` |
| 4 | Checkout-Redirect mit Session Token | 01, 03 | URL enthalt `?session-token=...` |
| 5 | Faktur Pro reagiert auf order_status_processing | 03 → 04 | PDF vorhanden in WooCommerce Admin nach Zahlung |
| 6 | Spreadconnect reagiert auf order_status_processing | 03 → 05 | `_spreadconnect_order_id` in WooCommerce Custom Fields |
| 7 | Cookie Consent Gate fur Pinterest Tag | 04 → 06 | Pinterest Script nur geladen wenn `cookie-consent === "accepted"` |
| 8 | Pinterest event_id Deduplizierung (Frontend → Backend) | 03, 06 | `_pinterest_event_id` in Order Meta = UUID von add_to_cart Event |
| 9 | WooCommerce Order Completed → Pinterest CAPI | 05, 06 | CAPI purchase Event nach Tracking-Webhook |
| 10 | Mein Konto Link Header + Footer | 02, 07 | `href={getAccountUrl()}` → `http://localhost:8080/mein-konto` |
| 11 | app/layout.tsx finale Komposition | 01, 03, 04, 06 | ApolloWrapper > CartProvider > {children} + CookieConsentBanner + PinterestTagInit |

---

## Sign-Off

| Tester | Datum | Ergebnis |
|--------|-------|---------|
| [Name] | [Datum] | PASS / FAIL |

**Notizen:**
[Beobachtungen oder gefundene Probleme]
