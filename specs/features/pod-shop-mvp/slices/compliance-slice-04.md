# Gate 2: Slice 04 Compliance Report (Re-Check)

**Gepruefter Slice:** `docs/features/pod-shop-mvp/slices/slice-04-rechtliches-rechnungen.md`
**Pruefdatum:** 2026-02-21
**Re-Check nach Fix:** Ja – vorheriger Verdict: FAILED (1 Blocking Issue)
**Architecture:** `docs/features/pod-shop-mvp/architecture.md`
**Wireframes:** `docs/features/pod-shop-mvp/wireframes.md`
**Discovery:** `docs/features/pod-shop-mvp/discovery.md`
**Vorherige Slices:** `slice-01-infrastruktur.md`, `slice-02-produktkatalog-frontend.md`, `slice-03-warenkorb-checkout-redirect.md`

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 69 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## Re-Check: Geprueftes Blocking Issue

### Vorheriges Blocking Issue (Issue 1): AC-11 ohne manuellen Testschritt

**Anforderung aus vorherigem Report:**
> Manuellen Testschritt fuer `prefers-reduced-motion: reduce` erganzen (DevTools Emulation, Banner erscheint ohne Slide-Animation).

**Fix gefunden in Slice (Zeile 949):**
> "Chrome DevTools oeffnen (F12) → Reiter 'Rendering' → 'Emulate CSS media feature prefers-reduced-motion' auf `reduce` setzen (alternativ: macOS Systemeinstellungen → Bedienungshilfen → Bewegung → Bewegung reduzieren aktivieren). Inkognito-Fenster oeffnen → `http://localhost:3000` aufrufen. THEN: Cookie-Consent-Banner erscheint sofort am unteren Rand ohne Slide-Up-Animation (kein `translateY`-Uebergang sichtbar, Banner ist sofort in Endposition) → AC-11 verifiziert"

**Bewertung:** Fix vollstaendig und korrekt. Der manuelle Testschritt (Nr. 18) beschreibt:
- Aktivierungsweg via Chrome DevTools Rendering-Tab (praeferierter Weg)
- Alternativweg via macOS Systemeinstellungen
- Inkognito-Fenster-Vorgabe (sicherstellt erster Besuch / kein gespeicherter Consent)
- Konkrete URL (`http://localhost:3000`)
- Messbares THEN: "Banner erscheint sofort... kein `translateY`-Uebergang sichtbar, Banner ist sofort in Endposition"

Das Blocking Issue ist behoben. AC-11 ist nun vollstaendig verifizierbar.

---

## 0) Inhaltliche Pruefung

### Template-Sections Check

| Section | Vorhanden? | Status |
|---------|------------|--------|
| Metadata (ID, Test, E2E, Dependencies) | Ja – Zeilen 12–25 | Pass |
| Test-Strategy Section | Ja – Zeilen 29–51 | Pass |
| Integration Contract Section | Ja – Zeilen 1033–1066 | Pass |
| DELIVERABLES_START/END Marker | Ja – Zeilen 1101/1131 | Pass |
| Code Examples MANDATORY Section | Ja – Zeilen 1069–1082 | Pass |

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes – trigger: order_status_processing, output: PDF + E-Mail | Yes | Yes | Yes | Pass |
| AC-2 | Yes | Yes – exakter String "Gemass §19 UStG wird keine Umsatzsteuer berechnet." | Yes | Yes | Yes | Pass |
| AC-3 | Yes | Yes – "keine Luecken, keine Wiederholungen" | Yes | Yes | Yes (GoBD-konform per Faktur Pro) | Pass |
| AC-4 | Yes | Yes – konkrete Slugs /impressum, /agb, /datenschutz, /widerruf | Yes | Yes | Yes – HTTP erreichbar + Inhalt | Pass |
| AC-5 | Yes | Yes – AGB-Bereich des Checkouts, Link sichtbar | Yes | Yes | Yes | Pass |
| AC-6 | Yes | Yes – localStorage "cookie-consent" nicht gesetzt, Buttons benannt | Yes | Yes | Yes | Pass |
| AC-7 | Yes | Yes – localStorage.getItem("cookie-consent") === "accepted" | Yes | Yes | Yes – messbarer localStorage-Wert | Pass |
| AC-8 | Yes | Yes – localStorage.getItem("cookie-consent") === "rejected" | Yes | Yes | Yes | Pass |
| AC-9 | Yes | Yes – erneuter Besuch / Seitenreload | Yes | Yes | Yes – Banner nicht sichtbar | Pass |
| AC-10 | Yes | Yes – konkrete URLs ${WP_URL}/impressum etc. | Yes | Yes | Yes | Pass |
| AC-11 | Yes | Yes – "kein translateY-Uebergang" | Yes | Yes | Yes – Manueller Testschritt 18 prueft konkret auf "sofort in Endposition, kein translateY-Uebergang sichtbar" | Pass |

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| `cookie-consent.ts` – 5 Funktionen + CONSENT_KEY + ConsentState | Yes | N/A (kein Import) | Yes – getConsentState(): ConsentState, setConsentAccepted(): void etc. | Yes – CONSENT_KEY = 'cookie-consent' as const | Pass |
| `CookieConsentBanner` Component | Yes | Yes – @/lib/consent/cookie-consent korrekt | Yes – useState, useEffect, Link (next/link) | Yes – role="dialog", aria-live="polite" | Pass |
| `globals.css` @theme + @keyframes | N/A (CSS) | N/A | N/A | Yes – motion-safe: kompatibel mit Tailwind v4, prefers-reduced-motion Override vorhanden | Pass |
| `footer.tsx` | Yes – as const korrekt | Yes – process.env.NEXT_PUBLIC_WP_URL | Yes | Yes – LEGAL_LINKS 4 Eintraege, nav aria-label | Pass |
| `layout.tsx` Modifikation | Yes | Yes – @/contexts/cart-context, @/components/layout/cookie-consent-banner | Yes | Yes – CookieConsentBanner nach {children} innerhalb CartProvider | Pass |
| Faktur Pro HTML Template | N/A (HTML/Mustache) | N/A | N/A | Yes – {{ invoice_number }}, §19-Pflichttext, {{#order_items}} Loop | Pass |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (aus Slice 1 etabliert) | Pass |
| Commands vollstaendig | 3 – Test Command, Integration Command, Acceptance Command definiert | 3 (unit, integration, acceptance) | Pass |
| Start-Command | `docker compose up -d && cd frontend && pnpm dev` | Passt zu typescript-nextjs + docker-compose Stack | Pass |
| Health-Endpoint | `http://localhost:3000` | Passt – Next.js Frontend, Cookie-Banner erscheint auf Startseite | Pass |
| Mocking-Strategy | `mock_external` – localStorage via jsdom-Mock aus tests/setup.ts | Definiert | Pass |

---

## A) Architecture Compliance

### Schema Check

Slice 4 fuegt kein eigenes Datenbankschema hinzu. WooCommerce verwaltet das Schema (architecture.md: "WooCommerce verwaltet das Datenbankschema. Keine eigenen Tables noetig."). Faktur Pro verwaltet Rechnungsnummern intern.

| Arch-Element | Arch-Spec | Slice-Spec | Status |
|--------------|-----------|------------|--------|
| WooCommerce Tax | Keine Steuer (§19 UStG) | Sektion 4: "Steuern aktiviert: Nein" – bestaetigt Slice-1-Konfiguration | Pass |
| Faktur Pro Trigger | order_status_processing | Sektion 4 Schritt 5: Trigger "Verarbeitung" (= order_status_processing) | Pass |
| Cookie Consent Storage | localStorage Flag "cookie-consent" | CONSENT_KEY = 'cookie-consent' as const | Pass |
| Faktur Pro Version | 4.x (Download Feb 2026) | "v4.x, Download Feb 2026" – Sektion 4 Versionierung | Pass |
| Cookie Consent Impl. | Custom Implementation (architecture.md) | Custom Banner + cookie-consent.ts – kein WP-Plugin | Pass |

### API Check

Slice 4 fuegt keine neuen Endpoints hinzu. Verwendete Integrationspunkte:

| Integration | Arch-Spec | Slice-Impl | Status |
|-------------|-----------|------------|--------|
| WooCommerce Hook order_status_processing | architecture.md Business Logic Flow | Faktur Pro reagiert auf diesen Hook | Pass |
| localStorage Key cookie-consent | architecture.md Constraints: "Cookie Consent State in localStorage" | CONSENT_KEY = 'cookie-consent' | Pass |
| WP_URL env var | architecture.md: NEXT_PUBLIC_WP_URL | process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080' | Pass |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Cookie Consent vor Pinterest Tag | "Cookie Consent State in localStorage, Pinterest Tag conditional load in Next.js" | localStorage-Flag gesetzt, Slice 6 prueft isConsentAccepted() | Pass |
| CAPI consent-unabhaengig | "CAPI ist serverseitig und consent-unabhaengig" | Scope-Abgrenzung: "Consent fuer CAPI: NICHT noetig" – korrekt | Pass |
| API Keys nicht im Code | Faktur Pro Lizenzschluessel via WP-Admin, nicht in Quellcode | Konfiguration via WP-Admin beschrieben – kein Hardcoding | Pass |

---

## B) Wireframe Compliance

### UI Elements

| Wireframe Element | Annotation | Slice Component | Status |
|-------------------|------------|-----------------|--------|
| Cookie Banner Headline | "Wir verwenden Cookies" | CookieConsentBanner: `<strong>Wir verwenden Cookies</strong>` | Pass |
| Cookie Banner Text | Datenschutzerklarung-Link | `<a href="${WP_URL}/datenschutz">Mehr in der Datenschutzerklarung.</a>` | Pass |
| "Alle Akzeptieren" Button | Primary Button | Button mit onClick={handleAccept} | Pass |
| "Nur Notwendige" Button | Secondary/Outline Button | Button mit onClick={handleReject}, Outline-Styling | Pass |
| Cookie Banner Position | Fixed bottom overlay | `fixed bottom-0 left-0 right-0 z-50` | Pass |
| Footer: Impressum | Legal link | LEGAL_LINKS[0]: { label: 'Impressum', href: ... } | Pass |
| Footer: AGB | Legal link | LEGAL_LINKS[1]: { label: 'AGB', href: ... } | Pass |
| Footer: Datenschutz | Legal link | LEGAL_LINKS[2]: { label: 'Datenschutz', href: ... } | Pass |
| Footer: Widerruf | Legal link | LEGAL_LINKS[3]: { label: 'Widerruf', href: ... } | Pass |
| Footer: Mein Konto | WooCommerce link | `<a href="${WP_URL}/mein-konto">Mein Konto</a>` | Pass |
| Footer: Copyright | "© 2025 Shop Name" | `&copy; {currentYear} POD Shop` mit suppressHydrationWarning | Pass |

### State Variations

| State | Wireframe | Slice | Status |
|-------|-----------|-------|--------|
| Banner: Erster Besuch | Banner visible at bottom of page | isVisible = true wenn !hasConsentDecision() via useEffect | Pass |
| Banner: Consent gegeben | Banner hidden, not shown again | `if (!isVisible) return null` | Pass |
| Banner: Consent abgelehnt | Pinterest Tag remains disabled | setConsentRejected() → isVisible = false; Slice 6 prueft Flag | Pass |
| prefers-reduced-motion | Keine Slide-Animation | motion-safe:animate-slide-up + CSS-Override in globals.css | Pass |

### Visual Specs

| Spec | Wireframe / Architecture | Slice Value | Status |
|------|--------------------------|-------------|--------|
| Banner Position | Bottom Overlay, volle Breite | fixed bottom-0 left-0 right-0 | Pass |
| Banner z-Index | Overlay ueber Page-Content | z-50 | Pass |
| "Alle Akzeptieren" Stil | Primary Button (solid) | bg-primary text-white | Pass |
| "Nur Notwendige" Stil | Secondary Button (Outline) | bg-white text-text-primary border border-border | Pass |
| Footer Links: externe Navigation | Legal page links → WooCommerce WordPress pages | `<a href>` (kein next/link) – korrekt fuer andere Domain | Pass |
| Touch-Targets | Min 44px (architecture.md: Mobile-first) | minHeight: '2.75rem' (44px) auf Buttons | Pass |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| Docker Compose Stack | slice-01-infrastruktur | Integration Contract "Requires" – WordPress erreichbar unter localhost:8080 | Pass |
| WooCommerce Grundkonfiguration (Tax deaktiviert) | slice-01-infrastruktur | Sektion 4: "Tax deaktiviert – Slice 1 konfiguriert dies bereits" | Pass |
| frontend/app/globals.css (@theme Tokens) | slice-01-infrastruktur | Integration Contract: "Slice 4 ergaenzt --animate-slide-up Token" | Pass |
| Footer Component (zu modifizieren) | slice-02-produktkatalog-frontend | Integration Contract: "frontend/components/layout/footer.tsx – MODIFIZIERT in Slice 4" | Pass |
| WooCommerce Bestellsystem (order_status_processing) | slice-03-warenkorb-checkout-redirect | Integration Contract: "Hook muss feuern nach Mollie-Zahlung" | Pass |
| frontend/app/layout.tsx (CartProvider + ApolloWrapper) | slice-03-warenkorb-checkout-redirect | Integration Contract: "CartProvider und ApolloWrapper bereits vorhanden" | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| isConsentAccepted() | slice-06-pinterest-tracking | Interface: `() => boolean` definiert | Pass |
| getConsentState() | slice-06-pinterest-tracking | Interface: `() => ConsentState` definiert | Pass |
| CONSENT_KEY | slice-06-pinterest-tracking | `"cookie-consent"` – Konstante exportiert | Pass |
| ConsentState Type | slice-06-pinterest-tracking | `"accepted" | "rejected" | null` exportiert | Pass |
| WordPress-Seiten (4 Legal Pages) | Footer (alle Slices), WooCommerce Checkout | URLs: {WP_URL}/impressum, /agb, /datenschutz, /widerruf | Pass |
| Faktur Pro Rechnungs-PDF | WooCommerce E-Mail | trigger: order_status_processing, PDF-Anhang an Bestellbestaetigung | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| isConsentAccepted() | slice-06 (zukuenftig) | Forward reference – Slice 6 noch nicht vorhanden | slice-06 (geplant) | Pass |
| CONSENT_KEY | slice-06 (zukuenftig) | Forward reference – Slice 6 noch nicht vorhanden | slice-06 (geplant) | Pass |
| WordPress Legal Pages | footer.tsx (modifiziert) | frontend/components/layout/footer.tsx in Deliverables als "MODIFIZIERT" | slice-04 | Pass |
| CookieConsentBanner | frontend/app/layout.tsx | layout.tsx in Deliverables als "MODIFIZIERT" | slice-04 | Pass |

### AC-Deliverable-Konsistenz

| AC # | Referenced File/Resource | In Deliverables? | Status |
|------|--------------------------|-------------------|--------|
| AC-6, 7, 8, 9 | CookieConsentBanner – cookie-consent-banner.tsx | Ja – Deliverables "Neue Dateien" | Pass |
| AC-10 | footer.tsx | Ja – Deliverables "Modifizierte Dateien" | Pass |
| AC-11 | CookieConsentBanner + globals.css | Ja – beide in Deliverables | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| cookie-consent.ts (Utilities) | Sektion 7 | Ja – alle 5 Funktionen + CONSENT_KEY + ConsentState | Ja | Pass |
| CookieConsentBanner Component | Sektion 7 | Ja – 'use client', role="dialog", aria-live, motion-safe, 2 Buttons mit touchAction | Ja | Pass |
| globals.css @theme + @keyframes slide-up | Sektion 8 | Ja – @theme Token, @keyframes, prefers-reduced-motion Override | Ja | Pass |
| footer.tsx (MODIFIZIERT) | Sektion 9 | Ja – LEGAL_LINKS 4 Eintraege, nav aria-label, Mein Konto, Copyright | Ja | Pass |
| layout.tsx (MODIFIZIERT) | Sektion 10 | Ja – CookieConsentBanner Import + Placement nach {children} | Ja | Pass |
| Faktur Pro HTML Template | Sektion 5 | Ja – {{ invoice_number }}, Mustache-Loops, §19-Pflichttext | Ja (WordPress-Template, nicht TS) | Pass |

---

## E) Build Config Sanity Check

Slice 4 fuegt keine Build-Config-Deliverables hinzu. Die globals.css-Ergaenzung (@theme, @keyframes) ist CSS-only und benoetigt kein neues Build-Plugin. Der @tailwindcss/postcss Build-Setup wurde in Slice 1 etabliert.

| Pruef-Aspekt | Requirement | Vorhanden? | Status |
|--------------|-------------|------------|--------|
| Neue Build-Plugins | Keine – Slice 4 benoetigt kein neues Plugin | N/A | Pass |
| process.env Replacement | Kein IIFE/UMD Build | N/A | Pass |
| CSS Build Plugin | Tailwind v4 via @tailwindcss/postcss (Slice 1) | N/A – unveraendert | Pass |

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Typ | Status |
|--------------------|----------------|----------|--------|
| AC-1: Faktur Pro PDF bei order_status_processing | Manueller Testschritt 5 (Testbestellung) | Manuell | Pass |
| AC-2: §19 UStG Pflichttext | `describe('Slice 04: §19 UStG Pflichttext')` | Unit | Pass |
| AC-3: GoBD-konforme Rechnungsnummern | Manueller Testschritt 6 | Manuell | Pass |
| AC-4: WP-Seiten unter korrekten Slugs erreichbar | Manuelle Testschritte 7–10 | Manuell | Pass |
| AC-5: Widerruf-Link im Checkout | Manueller Testschritt 11 | Manuell | Pass |
| AC-6: Banner bei erstem Besuch | `hasConsentDecision() returns false` | Unit | Pass |
| AC-7: "Alle Akzeptieren" → localStorage "accepted" | `setConsentAccepted` + localStorage Key Test | Unit | Pass |
| AC-8: "Nur Notwendige" → localStorage "rejected" | `setConsentRejected` Test | Unit | Pass |
| AC-9: Banner nicht erneut anzeigen | `hasConsentDecision() true after decision` | Unit | Pass |
| AC-10: Footer-Links vorhanden | `describe('Slice 04: Footer Legal Links')` | Unit | Pass |
| AC-11: prefers-reduced-motion – keine Slide-Animation | Manueller Testschritt 18: DevTools Rendering → prefers-reduced-motion: reduce → Banner erscheint sofort ohne translateY-Uebergang | Manuell | Pass |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| IN Scope | Rechnungserstellung via Faktur Pro | Ja | Ja – vollstaendige Plugin-Konfiguration in Sektion 4 | Pass |
| IN Scope | Rechtliche Seiten (Impressum, AGB, Datenschutz, Widerruf) | Ja | Ja – WordPress-Seiten in Sektion 6 | Pass |
| IN Scope | Cookie Consent | Ja | Ja – Custom Banner + cookie-consent.ts | Pass |
| IN Scope | Kleinunternehmerregelung §19 UStG | Ja | Ja – Template + WooCommerce Tax-Config | Pass |
| OUT of Scope | Germanized Plugin | Ja | Ja – "OUT OF SCOPE (discovery.md: kein Mehrwert)" dokumentiert | Pass |
| OUT of Scope | SevDesk | Ja | Ja – "OUT OF SCOPE (discovery.md: bewusst raus)" dokumentiert | Pass |
| UI Components | Cookie-Banner: Sichtbar (erster Besuch), Versteckt (nach Entscheidung) | Ja | Ja – beide States implementiert | Pass |
| Business Rules | "Gemass §19 UStG..." exakter Pflichttext | Ja | Ja – Sektion 5 + AC-2 | Pass |
| Business Rules | Cookie Consent vor Pinterest Tag | Ja | Ja – localStorage-Flag als Schnittstelle fuer Slice 6 | Pass |
| Business Rules | Rechnungsnummern fortlaufend, GoBD-konform | Ja | Ja – AC-3 + Sektion 4 Schritt 7 | Pass |
| Business Rules | Widerrufsrecht 14 Tage | Ja | Ja – AC-5, Sektion 6 WooCommerce-Integration | Pass |
| Rechnungs-Plugin | Faktur Pro (Plugin-Slug: woorechnung), freie Layout-Gestaltung | Ja | Ja – Sektion 4 + HTML-Template | Pass |
| Rechnungs-Plugin | Download Feb 2026 (kein festes Versionierungsschema) | Ja | Ja – Sektion 4 "Plugin-Versionierung" | Pass |

---

## Blocking Issues Summary

Keine Blocking Issues. Das vorherige Blocking Issue (Issue 1: AC-11 ohne manuellen Testschritt) wurde durch Testschritt 18 in der "Manuelle Tests"-Section behoben.

---

## Recommendations

1. **Optional (nicht blockierend):** Den §19-Pflichttext-Test in `describe('Slice 04: §19 UStG Pflichttext')` staerken – derzeit prueft er eine lokale Konstante gegen sich selbst (tautologisch). Ein staerkerer Test wuerde den tatsaechlichen HTML-Output der `CookieConsentBanner`-Component via @testing-library/react oder den Faktur-Pro-Template-String direkt pruefen. Da AC-2 ohnehin manuell via Testschritt 6 verifiziert wird, ist dies kein Blocking Issue.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Begruendung:** Das einzige Blocking Issue aus dem vorherigen Report (AC-11 ohne verifizierbaren Testpfad) wurde durch den neu hinzugefuegten manuellen Testschritt 18 vollstaendig behoben. Der Testschritt benennt konkret:
- Aktivierungsweg (Chrome DevTools → Rendering → prefers-reduced-motion: reduce, alternativ macOS Systemeinstellungen)
- Testbedingung (Inkognito-Fenster, localhost:3000)
- Messbares THEN (Banner sofort in Endposition, kein translateY-Uebergang sichtbar)

Alle uebrigen Pruefpunkte (Architecture, Wireframe, Integration Contract, Code Examples, Build Config, Test Coverage, Discovery) bleiben unveraendert korrekt.

---

VERDICT: APPROVED
