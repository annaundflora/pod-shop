# Slice 4: Rechtliches + Rechnungen einrichten

> **Slice 4 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-03-warenkorb-checkout-redirect.md` |
> | **Nächster:** | `slice-05-spreadconnect.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-rechtliches-rechnungen` |
| **Test** | `pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-infrastruktur", "slice-02-produktkatalog-frontend", "slice-03-warenkorb-checkout-redirect"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` – Vitest Unit Tests (kein Playwright)
- **Dependencies**: Slice 1 (Infrastruktur, Footer-Struktur), Slice 2 (Footer-Komponente), Slice 3 (WooCommerce Bestellsystem mit `order_status_processing` Hook)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Slices 1–3 dokumentieren `frontend/package.json` mit Next.js 16, Vitest 3.x. Stack: `typescript-nextjs`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts` |
| **Integration Command** | `pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts --reporter=verbose` |
| **Acceptance Command** | `curl -f http://localhost:3000 && echo "Frontend OK"` |
| **Start Command** | `docker compose up -d && cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: Next.js 16 (App Router), React 19, Tailwind CSS v4, Vitest
- **Test Command**: Vitest Unit Tests für CookieConsentBanner-Logik (localStorage-Interaktion)
- **Integration Command**: Gleiche Tests mit ausführlicher Ausgabe
- **Acceptance Command**: Smoke Test – Next.js Frontend erreichbar (Cookie-Banner erscheint auf erster Seite)
- **Start Command**: Docker für WordPress Backend, dann Next.js Dev-Server
- **Health Endpoint**: Startseite – Cookie-Banner muss beim ersten Besuch angezeigt werden
- **Mocking Strategy**: localStorage wird in Unit Tests via jsdom-Mock aus tests/setup.ts gehandelt (bereits in Slice 1 konfiguriert)

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Ready | `slice-02-produktkatalog-frontend.md` |
| 3 | Warenkorb + Checkout-Redirect | Ready | `slice-03-warenkorb-checkout-redirect.md` |
| 4 | Rechtliches + Rechnungen | Ready | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Pending | `slice-05-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Pending | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice stellt die rechtliche Compliance des POD Shops sicher und automatisiert die Rechnungserstellung. Nach Abschluss werden PDF-Rechnungen mit korrektem §19 UStG Hinweis automatisch bei jeder Bestellung erstellt, alle Pflichtseiten (Impressum, AGB, Datenschutz, Widerruf) existieren in WordPress, der Footer im Next.js Frontend verlinkt auf diese Seiten, und ein Cookie Consent Banner kontrolliert, ob Pinterest-Tracking aktiv ist.

**Scope-Abgrenzung:**
- Faktur Pro Template-Design/Branding: IN SCOPE (§19-Hinweis ist Pflicht, grundlegendes Layout)
- Pinterest Tag-Aktivierung nach Consent: IN SCOPE (localStorage-Flag setzen) – das Tag-Laden selbst ist Slice 6
- Spreadconnect Bestellweiterleitung: OUT OF SCOPE (Slice 5)
- Pinterest CAPI: OUT OF SCOPE (Slice 6)
- User-Accounts: OUT OF SCOPE (Slice 7)
- SevDesk / Buchhaltungs-Anbindung: OUT OF SCOPE (discovery.md: bewusst raus)
- Germanized Plugin: OUT OF SCOPE (discovery.md: kein Mehrwert)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Business Logic Flow (Faktur Pro), Integrations (Invoicing, Cookie Consent), Constraints (Kleinunternehmerregelung §19 UStG, Cookie Consent vor Pinterest Tag)

```
Order Created (status: processing)
    │
    ├── Faktur Pro ──► PDF-Rechnung erstellt (trigger: order_status_processing)
    │       │
    │       └── §19 UStG Hinweis im Mustache HTML-Template konfiguriert
    │
[Next.js Frontend]
    │
    ├── CookieConsentBanner (Overlay)
    │       ├── "Alle Akzeptieren" → localStorage: cookie-consent = "accepted"
    │       └── "Nur Notwendige" → localStorage: cookie-consent = "rejected"
    │
    └── Slice 6 (Pinterest Tag) prüft localStorage: cookie-consent === "accepted"
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Business Logic` (WordPress) | Faktur Pro Plugin installiert + konfiguriert (Download Feb 2026, v4.x) |
| `Business Logic` (WordPress) | Faktur Pro Mustache HTML-Template mit §19 UStG Hinweis |
| `Business Logic` (WordPress) | WooCommerce Tax = "Keine Steuer" bestätigt (bereits Slice 1) |
| `Business Logic` (WordPress) | WordPress-Seiten: Impressum, AGB, Datenschutz, Widerruf |
| `Business Logic` (WordPress) | WooCommerce Checkout: Widerrufsrecht 14-Tage-Link gesetzt |
| `Presentation` (Next.js) | `frontend/components/layout/cookie-consent-banner.tsx` – Neue Client Component |
| `Presentation` (Next.js) | `frontend/lib/consent/cookie-consent.ts` – Consent-State-Utilities |
| `Presentation` (Next.js) | `frontend/components/layout/footer.tsx` – MODIFIZIERT: Rechtliche Links hinzugefügt |
| `Presentation` (Next.js) | `frontend/app/layout.tsx` – MODIFIZIERT: CookieConsentBanner eingebunden |

### 2. Datenfluss: Rechnungserstellung

```
Mollie zahlung abgeschlossen
  ↓
WooCommerce Bestellung erstellt (status: processing)
  ↓
WooCommerce Hook: order_status_processing fired
  ↓
Faktur Pro reagiert auf Hook → generiert PDF-Rechnung
  ↓
Rechnungsnummer: fortlaufend, GoBD-konform (von Faktur Pro verwaltet)
  ↓
PDF enthält §19 UStG Hinweis (im Mustache-Template konfiguriert)
  ↓
PDF wird per E-Mail an Kunden gesendet (WooCommerce Bestellbestätigungs-E-Mail)
```

### 3. Datenfluss: Cookie Consent

```
Erster Besuch (localStorage "cookie-consent" nicht gesetzt)
  ↓
CookieConsentBanner wird angezeigt (Bottom Overlay)
  ↓
Nutzer klickt "Alle Akzeptieren"
    ↓
    localStorage.setItem("cookie-consent", "accepted")
    ↓
    Banner verschwindet, wird nicht mehr angezeigt
    ↓
    Slice 6: Pinterest Tag feuert (liest localStorage "cookie-consent" === "accepted")

Nutzer klickt "Nur Notwendige"
    ↓
    localStorage.setItem("cookie-consent", "rejected")
    ↓
    Banner verschwindet, wird nicht mehr angezeigt
    ↓
    Slice 6: Pinterest Tag bleibt inaktiv
```

### 4. Faktur Pro Plugin-Konfiguration (WordPress)

> **Quelle:** `architecture.md` → Integrations: Faktur Pro (Plugin-Slug: woorechnung), discovery.md → Rechnungs-Plugin: Faktur Pro (ehem. WooRechnung) von Zweischneider

| Schritt | Aktion | Pfad |
|---------|--------|------|
| 1 | Faktur Pro Account erstellen/einloggen | https://faktur.pro |
| 2 | Plugin-ZIP herunterladen | Faktur Pro Dashboard → Downloads |
| 3 | Plugin hochladen und aktivieren | WP-Admin → Plugins → Neu hinzufügen → ZIP hochladen |
| 4 | Faktur Pro mit Account verbinden | WP-Admin → WooCommerce → Faktur Pro → Lizenzschlüssel eintragen |
| 5 | Trigger konfigurieren | Rechnung erstellen bei: `Verarbeitung` (= `order_status_processing`) |
| 6 | Rechnungsvorlagen-Template konfigurieren | Faktur Pro → Templates → Eigenes Template mit §19-Hinweis (siehe Sektion 5) |
| 7 | Rechnungsnummernkreis festlegen | Faktur Pro → Einstellungen → Nummernkreis: fortlaufend, Präfix z.B. `RE-{YEAR}-` |
| 8 | E-Mail-Anhang aktivieren | Faktur Pro → Einstellungen → PDF bei Bestellbestätigung anhängen: aktiviert |

**Plugin-Versionierung:**
- Kein öffentliches Versionierungsschema bei Faktur Pro.
- Dokumentierter Download-Zeitpunkt: **Februar 2026** (Plugin-ZIP-Dateiname für Reproduzierbarkeit aufbewahren)
- Plugin-Slug im WordPress-Dateisystem: `woorechnung`

**WooCommerce Tax-Konfiguration (§19 UStG):**

| Setting | Wert | WP-Admin Pfad |
|---------|------|---------------|
| Steuern aktiviert | Nein (deaktiviert) | WooCommerce → Einstellungen → Allgemein → Steuern aktivieren: Checkbox DEAKTIVIERT |

Hinweis: Diese Einstellung wurde bereits in Slice 1 konfiguriert. Slice 4 bestätigt, dass sie korrekt gesetzt ist, bevor Faktur Pro konfiguriert wird.

### 5. Faktur Pro Mustache HTML-Template

> **Quelle:** `discovery.md` → Faktur Pro: Freie Layout-Gestaltung (HTML + Mustache Templates). `architecture.md` → Constraints: §19-Hinweis auf Rechnungen.

Das Template wird unter WP-Admin → WooCommerce → Faktur Pro → Templates bearbeitet.

**Pflicht-Elemente im Template:**

```html
<!-- Faktur Pro Mustache Template – Pflicht-Elemente für §19 UStG Compliance -->
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
    .header { margin-bottom: 30px; }
    .shop-name { font-size: 18px; font-weight: bold; }
    .invoice-title { font-size: 16px; font-weight: bold; margin: 20px 0 10px; }
    .invoice-meta { margin-bottom: 20px; }
    .invoice-meta table { border-collapse: collapse; }
    .invoice-meta td { padding: 3px 10px 3px 0; }
    table.items { width: 100%; border-collapse: collapse; margin: 20px 0; }
    table.items th { border-bottom: 2px solid #333; padding: 6px 4px; text-align: left; }
    table.items td { border-bottom: 1px solid #ccc; padding: 6px 4px; }
    .total-row { font-weight: bold; }
    .legal-notice { margin-top: 30px; font-size: 11px; color: #555; border-top: 1px solid #ccc; padding-top: 10px; }
    .tax-notice { margin-top: 10px; font-style: italic; }
  </style>
</head>
<body>

  <!-- Absender -->
  <div class="header">
    <div class="shop-name">{{ shop_name }}</div>
    <div>{{ shop_address }}</div>
  </div>

  <!-- Empfänger -->
  <div class="recipient">
    <strong>{{ billing_first_name }} {{ billing_last_name }}</strong><br>
    {{ billing_address_1 }}<br>
    {{ billing_postcode }} {{ billing_city }}<br>
    {{ billing_country }}
  </div>

  <!-- Rechnungsdetails -->
  <div class="invoice-title">Rechnung</div>
  <div class="invoice-meta">
    <table>
      <tr>
        <td><strong>Rechnungsnummer:</strong></td>
        <td>{{ invoice_number }}</td>
      </tr>
      <tr>
        <td><strong>Rechnungsdatum:</strong></td>
        <td>{{ invoice_date }}</td>
      </tr>
      <tr>
        <td><strong>Bestellnummer:</strong></td>
        <td>{{ order_number }}</td>
      </tr>
    </table>
  </div>

  <!-- Positionen -->
  <table class="items">
    <thead>
      <tr>
        <th>Pos.</th>
        <th>Artikel</th>
        <th>Menge</th>
        <th style="text-align:right;">Einzelpreis</th>
        <th style="text-align:right;">Gesamt</th>
      </tr>
    </thead>
    <tbody>
      {{#order_items}}
      <tr>
        <td>{{ position }}</td>
        <td>{{ name }}{{#variation}}<br><small>{{ variation }}</small>{{/variation}}</td>
        <td>{{ quantity }}</td>
        <td style="text-align:right;">{{ unit_price }}</td>
        <td style="text-align:right;">{{ subtotal }}</td>
      </tr>
      {{/order_items}}
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4">Versandkosten</td>
        <td style="text-align:right;">{{ shipping_total }}</td>
      </tr>
      <tr class="total-row">
        <td colspan="4"><strong>Gesamtbetrag</strong></td>
        <td style="text-align:right;"><strong>{{ order_total }}</strong></td>
      </tr>
    </tfoot>
  </table>

  <!-- §19 UStG Pflichthinweis -->
  <div class="legal-notice">
    <div class="tax-notice">
      Gemäß §19 UStG wird keine Umsatzsteuer berechnet.
    </div>
  </div>

</body>
</html>
```

**Kritische Hinweise:**
- Der exakte Text `Gemäß §19 UStG wird keine Umsatzsteuer berechnet.` muss im Template enthalten sein (GoBD-Anforderung + discovery.md Business Rule)
- `{{ invoice_number }}` wird von Faktur Pro automatisch fortlaufend und GoBD-konform vergeben
- Mustache-Variablen (`{{ }}` und `{{# }}`) werden von Faktur Pro interpretiert – exakte Namen laut Faktur Pro-Dokumentation auf faktur.pro prüfen

### 6. WordPress-Pflichtseiten (WP-Admin)

> **Quelle:** `architecture.md` → In Scope: Rechtliche Seiten (Impressum, AGB, Datenschutz, Widerruf). `discovery.md` → Rechtliche Seiten – selbst erstellt.

Alle vier Seiten werden als WordPress-Seiten (nicht Beiträge) angelegt.

| Seite | WordPress Seitenname | URL-Slug | Inhalt |
|-------|---------------------|----------|--------|
| Impressum | Impressum | `/impressum` | Pflichtangaben nach §5 TMG (Name, Anschrift, E-Mail, ggf. USt-IdNr.) |
| AGB | AGB | `/agb` | Allgemeine Geschäftsbedingungen inkl. Widerrufsbelehrung |
| Datenschutz | Datenschutzerklärung | `/datenschutz` | DSGVO-konforme Datenschutzerklärung (Cookies, Tracking, Datenerhebung) |
| Widerruf | Widerruf | `/widerruf` | Widerrufsbelehrung 14 Tage + Widerrufsformular |

**Erstellungsweg:**
- WP-Admin → Seiten → Neue Seite hinzufügen
- Titel und Inhalt eintragen
- Permalink/Slug manuell setzen (wie in Tabelle)
- Status: Veröffentlicht
- Seiten müssen NICHT in der WordPress-Navigation erscheinen (Footer-Links im Next.js Frontend übernehmen die Verlinkung)

**WooCommerce-Integration – Widerrufsrecht:**

| Setting | Wert | WP-Admin Pfad |
|---------|------|---------------|
| Widerrufseite | WordPress-Seite "Widerruf" (Slug: `/widerruf`) | WooCommerce → Einstellungen → Erweitert → Widerruf-Seite |

WooCommerce verlinkt die Widerruf-Seite automatisch im Checkout (AGB-Checkbox-Bereich), sobald sie hier konfiguriert ist.

### 7. Cookie Consent Banner Component (Next.js)

> **Quelle:** `architecture.md` → Cookie Consent: Custom Implementation, localStorage Flag `cookie-consent`. `wireframes.md` → Overlay: Cookie Banner.

**Datei:** `frontend/lib/consent/cookie-consent.ts`

```typescript
// Cookie Consent State Utilities
// localStorage Key: "cookie-consent"
// Werte: "accepted" | "rejected" | null (nicht gesetzt = erster Besuch)

export const CONSENT_KEY = 'cookie-consent' as const

export type ConsentState = 'accepted' | 'rejected' | null

export function getConsentState(): ConsentState {
  if (typeof window === 'undefined') return null
  const value = localStorage.getItem(CONSENT_KEY)
  if (value === 'accepted' || value === 'rejected') return value
  return null
}

export function setConsentAccepted(): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(CONSENT_KEY, 'accepted')
}

export function setConsentRejected(): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(CONSENT_KEY, 'rejected')
}

export function hasConsentDecision(): boolean {
  return getConsentState() !== null
}

export function isConsentAccepted(): boolean {
  return getConsentState() === 'accepted'
}
```

**Datei:** `frontend/components/layout/cookie-consent-banner.tsx`

```typescript
'use client'

import { useState, useEffect } from 'react'
import Link from 'next/link'
import {
  hasConsentDecision,
  setConsentAccepted,
  setConsentRejected,
} from '@/lib/consent/cookie-consent'

// WP_URL aus env – zeigt auf WooCommerce WordPress-Seite
const WP_URL = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'

export function CookieConsentBanner() {
  const [isVisible, setIsVisible] = useState(false)

  useEffect(() => {
    // Banner nur anzeigen wenn noch keine Entscheidung getroffen wurde
    if (!hasConsentDecision()) {
      setIsVisible(true)
    }
  }, [])

  const handleAccept = () => {
    setConsentAccepted()
    setIsVisible(false)
  }

  const handleReject = () => {
    setConsentRejected()
    setIsVisible(false)
  }

  if (!isVisible) return null

  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-label="Cookie-Einstellungen"
      aria-live="polite"
      className={[
        'fixed bottom-0 left-0 right-0 z-50',
        'bg-white border-t border-border shadow-lg',
        'p-4 md:p-6',
        // prefers-reduced-motion: keine Slide-Animation
        'motion-safe:animate-slide-up',
      ].join(' ')}
    >
      <div className="max-w-2xl mx-auto">
        {/* Text */}
        <p className="text-sm text-text-primary mb-1">
          <strong>Wir verwenden Cookies</strong>
        </p>
        <p className="text-sm text-text-secondary mb-4">
          Diese Website nutzt Cookies für Analyse und Marketing.{' '}
          <a
            href={`${WP_URL}/datenschutz`}
            className="underline hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
          >
            Mehr in der Datenschutzerklärung.
          </a>
        </p>

        {/* Buttons */}
        <div className="flex flex-col sm:flex-row gap-2">
          <button
            onClick={handleAccept}
            className={[
              'flex-1 py-2.5 px-4 rounded-lg font-medium text-sm',
              'bg-primary text-white',
              'hover:bg-primary-hover',
              'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
              'transition-colors',
            ].join(' ')}
            style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
          >
            Alle Akzeptieren
          </button>

          <button
            onClick={handleReject}
            className={[
              'flex-1 py-2.5 px-4 rounded-lg font-medium text-sm',
              'bg-white text-text-primary border border-border',
              'hover:bg-surface-elevated',
              'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
              'transition-colors',
            ].join(' ')}
            style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
          >
            Nur Notwendige
          </button>
        </div>
      </div>
    </div>
  )
}
```

### 8. Tailwind Animation für Banner (globals.css Ergänzung)

> **Quelle:** Web Design Skill → Animation: prefers-reduced-motion. Tailwind v4 Skill → @theme Animationen.

**Ergänzung in** `frontend/app/globals.css`:

```css
/* Ergänzung zu bestehenden @theme Tokens aus Slice 1 */
@theme {
  /* Cookie Consent Banner Slide-Up Animation */
  --animate-slide-up: slide-up 0.25s ease-out;
}

@keyframes slide-up {
  from {
    transform: translateY(100%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

/* prefers-reduced-motion: Animation deaktivieren */
@media (prefers-reduced-motion: reduce) {
  @keyframes slide-up {
    from { transform: translateY(0); opacity: 1; }
    to   { transform: translateY(0); opacity: 1; }
  }
}
```

**Hinweis:** `motion-safe:animate-slide-up` in der Component wendet die Animation nur an, wenn der User keine `prefers-reduced-motion: reduce`-Präferenz hat. Tailwind v4 unterstützt `motion-safe:` als Modifier für genau diesen Zweck.

### 9. Footer-Komponente – Rechtliche Links (Modifikation)

> **Quelle:** `wireframes.md` → Shared: Footer: Links "Impressum · AGB · Datenschutz · Widerruf" → WooCommerce Pages.

**Datei:** `frontend/components/layout/footer.tsx` – MODIFIZIERT

```typescript
// WP_URL aus env – zeigt auf WooCommerce WordPress-Seite (localhost:8080 oder Produktions-Domain)
const WP_URL = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'

const LEGAL_LINKS = [
  { label: 'Impressum', href: `${WP_URL}/impressum` },
  { label: 'AGB', href: `${WP_URL}/agb` },
  { label: 'Datenschutz', href: `${WP_URL}/datenschutz` },
  { label: 'Widerruf', href: `${WP_URL}/widerruf` },
] as const

export function Footer() {
  const currentYear = new Date().getFullYear()

  return (
    <footer className="border-t border-border mt-auto py-8 px-4">
      <div className="max-w-5xl mx-auto">
        {/* Rechtliche Links */}
        <nav aria-label="Rechtliche Seiten">
          <ul className="flex flex-wrap gap-x-4 gap-y-2 justify-center text-sm text-text-secondary">
            {LEGAL_LINKS.map((link) => (
              <li key={link.href}>
                <a
                  href={link.href}
                  className="hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded underline-offset-2 hover:underline"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        {/* Mein Konto Link */}
        <div className="text-center mt-3">
          <a
            href={`${WP_URL}/mein-konto`}
            className="text-sm text-text-secondary hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
          >
            Mein Konto
          </a>
        </div>

        {/* Copyright */}
        <p className="text-center text-xs text-text-secondary mt-4" suppressHydrationWarning>
          &copy; {currentYear} POD Shop
        </p>
      </div>
    </footer>
  )
}
```

### 10. CookieConsentBanner in Root Layout einbinden (Modifikation)

**Modifikation:** `frontend/app/layout.tsx`

```typescript
import type { Metadata } from 'next'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import { CartProvider } from '@/contexts/cart-context'
import { CookieConsentBanner } from '@/components/layout/cookie-consent-banner'
import './globals.css'

export const metadata: Metadata = {
  title: 'POD Shop',
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="de">
      <body>
        <ApolloWrapper>
          <CartProvider>
            {children}
            {/* Cookie Consent Banner – übernimmt Kontrolle über Pinterest Tag (Slice 6) */}
            <CookieConsentBanner />
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
```

### 11. Abhängigkeiten (package.json)

Keine neuen npm-Abhängigkeiten erforderlich. Alle verwendeten Libraries sind bereits in Slice 1 installiert:
- `react` v19 – useState, useEffect
- `next` v16 – next/link

---

## UI Anforderungen

### Wireframe (aus wireframes.md)

> **Quelle:** `wireframes.md` → Overlay: Cookie Banner + Shared: Footer

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

Footer:
┌──────────────────────────────────────────────────────────┐
│  Impressum · AGB · Datenschutz · Widerruf                │
│  Mein Konto (→ WooCommerce)                              │
│  © 2025 Shop Name                                        │
└──────────────────────────────────────────────────────────┘
```

**Referenz Skills:**
- `.claude/skills/react-best-practices/SKILL.md` – rerender-lazy-state-init, client-localstorage-schema
- `.claude/skills/web-design/SKILL.md` – Accessibility (aria-label, role="dialog"), Animation (prefers-reduced-motion)
- `.claude/skills/tailwind-v4/SKILL.md` – motion-safe:, @theme Animationen

### 1. CookieConsentBanner (Wireframe ① – ③)

**Komponenten & Dateien:**
- `components/layout/cookie-consent-banner.tsx` – Bottom Overlay, fixed position, z-50

**Verhalten:**
- Erscheint nur beim ersten Besuch (wenn `localStorage["cookie-consent"]` nicht gesetzt)
- Position: Fixed, Bottom, volle Breite
- Slide-up Animation beim Erscheinen (`motion-safe:animate-slide-up`)
- "Alle Akzeptieren": Primary Button → setzt `cookie-consent = "accepted"`, Banner schließt
- "Nur Notwendige": Secondary Button (Outline) → setzt `cookie-consent = "rejected"`, Banner schließt
- Link "Datenschutzerklärung" → WooCommerce Datenschutz-Seite (`${WP_URL}/datenschutz`)

**Zustände:**

| State | Visual Change |
|-------|---------------|
| Erster Besuch (kein localStorage-Wert) | Banner sichtbar, slide-up animation |
| Consent gegeben (accepted oder rejected) | Banner nicht sichtbar (`return null`) |
| `prefers-reduced-motion` aktiv | Keine Slide-Animation, Banner erscheint sofort |

### 2. Footer (Wireframe Shared: Footer)

**Komponenten & Dateien:**
- `components/layout/footer.tsx` – MODIFIZIERT: Rechtliche Links als `<nav>` mit `<ul>`/`<li>`

**Verhalten:**
- Alle 4 rechtlichen Links als `<a href>` (externe Links → WooCommerce WordPress-Seite)
- Links navigieren zu WooCommerce WordPress-Seiten (nicht Next.js Pages – kein `next/link`)
- "Mein Konto" Link zu WooCommerce Account-Seite
- Copyright-Jahr: dynamisch aus `new Date().getFullYear()` mit `suppressHydrationWarning`

**Zustände:**
- Kein Loading-State (statischer Footer)
- Responsive: Links auf Mobile untereinander (flex-wrap), auf Desktop nebeneinander

### 3. Accessibility

- [x] CookieConsentBanner: `role="dialog"`, `aria-label="Cookie-Einstellungen"`
- [x] CookieConsentBanner: `aria-live="polite"` (Screen Reader kündigt Banner an)
- [x] Footer: `<nav aria-label="Rechtliche Seiten">` als semantisches Navigationselement
- [x] Footer-Links: Kein `<a>` ohne sichtbaren Text (Labels klar benannt)
- [x] Buttons: Beide haben sichtbares Label (kein Icon-only)
- [x] Alle Touch-Targets: `min-height: 2.75rem` (44px), `touch-action: manipulation`
- [x] Focus-States: `focus-visible:ring-2` auf Buttons und Links

---

## Acceptance Criteria

1) GIVEN eine neue WooCommerce-Bestellung wird erstellt (Mollie-Zahlung erfolgreich)
   WHEN der Bestellstatus auf `processing` wechselt
   THEN erstellt Faktur Pro automatisch eine PDF-Rechnung und hängt sie an die Bestellbestätigungs-E-Mail an

2) GIVEN eine Faktur Pro PDF-Rechnung wurde erstellt
   WHEN die Rechnung angesehen wird
   THEN enthält sie den exakten Text "Gemäß §19 UStG wird keine Umsatzsteuer berechnet." im Footer-Bereich der Rechnung

3) GIVEN Faktur Pro ist konfiguriert
   WHEN mehrere Bestellungen eingehen
   THEN sind die Rechnungsnummern fortlaufend und GoBD-konform (keine Lücken, keine Wiederholungen)

4) GIVEN WordPress ist installiert und WooCommerce konfiguriert
   WHEN die Seiten Impressum, AGB, Datenschutz und Widerruf angelegt werden
   THEN sind diese unter den Slugs `/impressum`, `/agb`, `/datenschutz`, `/widerruf` erreichbar und zeigen Inhalt an

5) GIVEN die WooCommerce Checkout-Seite
   WHEN der Nutzer die Bestellübersicht ansieht
   THEN ist die Widerrufsbelehrung (Link zur Widerruf-Seite) im AGB-Bereich des Checkouts verlinkt (WooCommerce Standard)

6) GIVEN ein Nutzer besucht den Shop zum ersten Mal (localStorage "cookie-consent" nicht gesetzt)
   WHEN eine beliebige Seite geladen wird
   THEN erscheint der Cookie-Consent-Banner am unteren Bildschirmrand mit den Buttons "Alle Akzeptieren" und "Nur Notwendige"

7) GIVEN der Cookie-Consent-Banner ist sichtbar
   WHEN der Nutzer auf "Alle Akzeptieren" klickt
   THEN verschwindet der Banner, und `localStorage.getItem("cookie-consent")` gibt `"accepted"` zurück

8) GIVEN der Cookie-Consent-Banner ist sichtbar
   WHEN der Nutzer auf "Nur Notwendige" klickt
   THEN verschwindet der Banner, und `localStorage.getItem("cookie-consent")` gibt `"rejected"` zurück

9) GIVEN der Nutzer hat bereits eine Consent-Entscheidung getroffen (accepted oder rejected)
   WHEN der Nutzer die Seite neu lädt oder eine andere Seite besucht
   THEN wird der Cookie-Consent-Banner nicht erneut angezeigt

10) GIVEN das Next.js Frontend wird aufgerufen
    WHEN der Footer sichtbar ist
    THEN sind die Links Impressum, AGB, Datenschutz und Widerruf als anklickbare Links vorhanden und zeigen auf die jeweiligen WooCommerce WordPress-Seiten (Ziel: `${WP_URL}/impressum` etc.)

11) GIVEN ein Nutzer mit `prefers-reduced-motion: reduce` in den Systemeinstellungen
    WHEN der Cookie-Consent-Banner erstmalig erscheint
    THEN erscheint der Banner ohne Slide-Up-Animation (sofort sichtbar, kein `translateY`-Übergang)

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts
import { describe, it, expect, beforeEach } from 'vitest'
import {
  getConsentState,
  setConsentAccepted,
  setConsentRejected,
  hasConsentDecision,
  isConsentAccepted,
  CONSENT_KEY,
} from '../../../frontend/lib/consent/cookie-consent'

describe('Slice 04: CookieConsent – getConsentState', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return null when no consent decision exists (AC-6)', () => {
    // Arrange – localStorage leer (beforeEach)
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })

  it('should return "accepted" after setConsentAccepted (AC-7)', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('accepted')
  })

  it('should return "rejected" after setConsentRejected (AC-8)', () => {
    // Arrange
    setConsentRejected()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('rejected')
  })

  it('should return null for unknown/invalid localStorage values', () => {
    // Arrange – ungültiger Wert
    localStorage.setItem(CONSENT_KEY, 'unknown-value')
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })
})

describe('Slice 04: CookieConsent – hasConsentDecision', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return false when no decision exists (AC-6)', () => {
    expect(hasConsentDecision()).toBe(false)
  })

  it('should return true after accepting (AC-9)', () => {
    setConsentAccepted()
    expect(hasConsentDecision()).toBe(true)
  })

  it('should return true after rejecting (AC-9)', () => {
    setConsentRejected()
    expect(hasConsentDecision()).toBe(true)
  })
})

describe('Slice 04: CookieConsent – isConsentAccepted', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return false when no consent exists', () => {
    expect(isConsentAccepted()).toBe(false)
  })

  it('should return true after accepting (AC-7)', () => {
    setConsentAccepted()
    expect(isConsentAccepted()).toBe(true)
  })

  it('should return false after rejecting (AC-8)', () => {
    setConsentRejected()
    expect(isConsentAccepted()).toBe(false)
  })
})

describe('Slice 04: CookieConsent – localStorage Key', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should use the key "cookie-consent" in localStorage', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const rawValue = localStorage.getItem('cookie-consent')
    // Assert – Slice 6 (Pinterest Tag) liest genau diesen Key
    expect(rawValue).toBe('accepted')
  })

  it('should persist decision across multiple reads (AC-9)', () => {
    // Arrange
    setConsentAccepted()
    // Act – mehrfaches Lesen
    const first = getConsentState()
    const second = getConsentState()
    const third = isConsentAccepted()
    // Assert
    expect(first).toBe('accepted')
    expect(second).toBe('accepted')
    expect(third).toBe(true)
  })

  it('should overwrite previous decision', () => {
    // Arrange – erst akzeptieren, dann ablehnen
    setConsentAccepted()
    setConsentRejected()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('rejected')
    expect(isConsentAccepted()).toBe(false)
  })
})

describe('Slice 04: §19 UStG Pflichttext', () => {
  it('should use exact §19 UStG text as required by GoBD and discovery.md', () => {
    // Arrange – Business Rule aus discovery.md + architecture.md
    const requiredText = 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet.'
    // Assert – Dieser exakte Text muss in Faktur Pro Template + (bereits getestet: CartSummary aus Slice 3)
    expect(requiredText).toMatch(/Gemäß §19 UStG/)
    expect(requiredText).toMatch(/keine Umsatzsteuer/)
    expect(requiredText.endsWith('.')).toBe(true)
  })
})

describe('Slice 04: Footer Legal Links', () => {
  it('should define all 4 required legal page slugs', () => {
    // Arrange – Pflicht-Seiten aus architecture.md In Scope
    const requiredSlugs = ['/impressum', '/agb', '/datenschutz', '/widerruf']
    const LEGAL_LINKS = [
      { label: 'Impressum', slug: '/impressum' },
      { label: 'AGB', slug: '/agb' },
      { label: 'Datenschutz', slug: '/datenschutz' },
      { label: 'Widerruf', slug: '/widerruf' },
    ]
    // Act
    const definedSlugs = LEGAL_LINKS.map((l) => l.slug)
    // Assert
    for (const slug of requiredSlugs) {
      expect(definedSlugs).toContain(slug)
    }
  })

  it('should have exactly 4 legal links', () => {
    const LEGAL_LINKS = ['Impressum', 'AGB', 'Datenschutz', 'Widerruf']
    expect(LEGAL_LINKS).toHaveLength(4)
  })
})
```
</test_spec>

### Manuelle Tests (WordPress + Faktur Pro + WooCommerce)

1. Faktur Pro Plugin-ZIP herunterladen (faktur.pro, Feb 2026) → WP-Admin: Plugin hochladen + aktivieren → Aktivierung bestätigt
2. Faktur Pro Lizenzschlüssel eintragen → WP-Admin → WooCommerce → Faktur Pro → Verbindung erfolgreich
3. Trigger konfigurieren: Rechnung bei Status "Verarbeitung" (processing) → Einstellung gespeichert
4. Faktur Pro Template: §19-Hinweis-Text `Gemäß §19 UStG wird keine Umsatzsteuer berechnet.` im Template eingetragen → Template gespeichert
5. Testbestellung mit Mollie Sandbox durchführen → Bestellstatus auf "processing" wechseln → Im WP-Admin: Bestellung → "Dokumente"-Tab zeigt PDF-Rechnung
6. PDF-Rechnung herunterladen → Rechnung enthält §19-Text, fortlaufende Rechnungsnummer sichtbar
7. WordPress-Seite "Impressum" anlegen (Slug: `/impressum`) → `http://localhost:8080/impressum` erreichbar
8. WordPress-Seite "AGB" anlegen (Slug: `/agb`) → `http://localhost:8080/agb` erreichbar
9. WordPress-Seite "Datenschutzerklärung" anlegen (Slug: `/datenschutz`) → `http://localhost:8080/datenschutz` erreichbar
10. WordPress-Seite "Widerruf" anlegen (Slug: `/widerruf`) → `http://localhost:8080/widerruf` erreichbar
11. WooCommerce → Einstellungen → Erweitert → Widerruf-Seite: Seite "Widerruf" auswählen → WooCommerce Checkout zeigt Widerrufslink
12. Next.js Frontend: `http://localhost:3000` im Inkognito-Fenster öffnen → Cookie-Consent-Banner erscheint am unteren Rand
13. "Alle Akzeptieren" klicken → Banner verschwindet → DevTools → localStorage: `cookie-consent = "accepted"`
14. Seite neu laden → Banner erscheint nicht mehr
15. Neues Inkognito-Fenster → "Nur Notwendige" klicken → localStorage: `cookie-consent = "rejected"`, Banner weg
16. Footer prüfen: Alle 4 Links (Impressum, AGB, Datenschutz, Widerruf) klickbar → leiten auf WooCommerce-Seiten weiter
17. `pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts` → Alle Tests grün
18. Chrome DevTools öffnen (F12) → Reiter "Rendering" → "Emulate CSS media feature prefers-reduced-motion" auf `reduce` setzen (alternativ: macOS Systemeinstellungen → Bedienungshilfen → Bewegung → Bewegung reduzieren aktivieren). Inkognito-Fenster öffnen → `http://localhost:3000` aufrufen. THEN: Cookie-Consent-Banner erscheint sofort am unteren Rand ohne Slide-Up-Animation (kein `translateY`-Übergang sichtbar, Banner ist sofort in Endposition) → AC-11 verifiziert

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] Faktur Pro Plugin installiert, lizenziert und konfiguriert (Trigger: processing, §19-Hinweis im Template)
- [ ] Testbestellung erzeugt PDF-Rechnung mit §19 UStG Hinweis und fortlaufender Rechnungsnummer
- [ ] WordPress-Seiten Impressum, AGB, Datenschutz, Widerruf veröffentlicht und erreichbar
- [ ] WooCommerce Widerruf-Seite konfiguriert (Link im Checkout sichtbar)
- [ ] Cookie-Consent-Banner erscheint beim ersten Besuch, verschwindet nach Entscheidung
- [ ] `localStorage["cookie-consent"]` wird korrekt auf "accepted" oder "rejected" gesetzt
- [ ] Footer zeigt alle 4 rechtlichen Links mit korrekten URLs
- [ ] Vitest Unit Tests laufen durch (`pnpm test`)
- [ ] `prefers-reduced-motion` Banner-Animation deaktiviert sich korrekt

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `rerender-lazy-state-init`: `isVisible` Initial-State auf `false` (Banner prüft localStorage im Effect, nicht beim ersten Render – vermeidet Hydration-Mismatch)
- [x] `rerender-move-effect-to-event`: Consent-Logik in Event-Handlern (`handleAccept`, `handleReject`), nicht in Effects

**High Priority:**
- [x] `rendering-hydration-no-flicker`: Banner-State initial `false`, dann via `useEffect` gesetzt → kein SSR-Mismatch (Server rendert keinen Banner, Client prüft localStorage)

**Medium Priority:**
- [x] `client-localstorage-schema`: localStorage-Key `cookie-consent` dokumentiert, Werte typisiert (`"accepted" | "rejected" | null`)

### Web Design Guidelines Verification

**Accessibility:**
- [x] `role="dialog"` auf Banner (semantisch korrekt für Overlay)
- [x] `aria-label="Cookie-Einstellungen"` auf Dialog-Container
- [x] `aria-live="polite"` für Screen-Reader-Ankündigung
- [x] Footer: `<nav aria-label="Rechtliche Seiten">` mit `<ul>`/`<li>` Struktur
- [x] Buttons: sichtbare Labels (kein Icon-only)
- [x] Focus-visible states: `focus-visible:ring-2` auf allen interaktiven Elementen

**Animation & Motion:**
- [x] `motion-safe:animate-slide-up` – Animation nur ohne `prefers-reduced-motion: reduce`
- [x] `@media (prefers-reduced-motion: reduce)` Keyframe-Override in globals.css
- [x] Nur `transform` + `opacity` für Animation (compositor-friendly)

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf beiden Buttons
- [x] Touch-Targets mindestens 2.75rem (44px) – `minHeight: '2.75rem'` auf Buttons

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Bestehende Tokens aus Slice 1 verwendet (`--color-primary`, `--color-border`, `--color-text-primary`, `--color-text-secondary`, `--color-surface-elevated`)
- [x] `--animate-slide-up` als neuer `@theme` Token in globals.css ergänzt

**Responsive:**
- [x] Banner: `flex-col sm:flex-row` für Button-Layout (Mobile: übereinander, Desktop: nebeneinander)
- [x] Footer: `flex-wrap` für Link-Liste, funktioniert auf 320px+

---

## Constraints & Hinweise

**Betrifft:**
- Faktur Pro kein öffentliches Versionierungsschema → Download-ZIP mit Dateinamen aufbewahren (z.B. `woorechnung-2026-02.zip`) für Reproduzierbarkeit
- Cookie Consent Banner: Custom-Implementierung (kein WP-Plugin) → volle Kontrolle über Consent-State
- `cookie-consent` localStorage-Flag ist die einzige Consent-Schnittstelle für Slice 6 (Pinterest Tag) – der Variablenname darf nicht geändert werden
- Banner erscheint auf ALLEN Next.js Frontend-Seiten (da in `layout.tsx` eingebunden) – bewusste Entscheidung gemäß DSGVO

**API Contract:**
- `CONSENT_KEY = 'cookie-consent'` ist der einzige localStorage-Key für Consent
- `ConsentState = 'accepted' | 'rejected' | null` – kein anderer Wert wird gesetzt
- `isConsentAccepted()` ist der von Slice 6 genutzte Check für Pinterest Tag

**Abgrenzung:**
- Pinterest Tag-Aktivierung (tatsächliches Feuern des Tags): OUT OF SCOPE → Slice 6
- Consent für CAPI (Pinterest Conversions API): NICHT nötig – CAPI ist serverseitig und consent-unabhängig (architecture.md: "CAPI ist serverseitig und consent-unabhängig")
- Rechnungsnummern-Format: Von Faktur Pro verwaltet (kein eigener Code nötig)
- Rechtstexte (Impressum, AGB etc.) werden selbst erstellt und eingetragen (kein Generator-Tool im Scope)

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-infrastruktur | Docker Compose Stack | Infrastructure | WordPress erreichbar unter `http://localhost:8080` – Voraussetzung für Faktur Pro Installation |
| slice-01-infrastruktur | WooCommerce Grundkonfiguration | WordPress Config | Tax deaktiviert (§19 UStG), Slice 1 konfiguriert dies bereits |
| slice-01-infrastruktur | `frontend/app/globals.css` | CSS-Datei | `@theme` Tokens vorhanden – Slice 4 ergänzt `--animate-slide-up` Token |
| slice-02-produktkatalog-frontend | `Footer` Component | React Component | `frontend/components/layout/footer.tsx` – MODIFIZIERT in Slice 4: Rechtliche Links hinzugefügt |
| slice-03-warenkorb-checkout-redirect | WooCommerce Bestellsystem | WordPress Backend | Hook `order_status_processing` muss feuern nach erfolgreicher Mollie-Zahlung (Slice 3 konfiguriert Mollie) |
| slice-03-warenkorb-checkout-redirect | `frontend/app/layout.tsx` | Next.js Root Layout | `CartProvider` und `ApolloWrapper` bereits vorhanden – Slice 4 ergänzt `CookieConsentBanner` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `isConsentAccepted()` | Function | slice-06-pinterest-tracking | `() => boolean` – gibt `true` zurück wenn `localStorage["cookie-consent"] === "accepted"` |
| `getConsentState()` | Function | slice-06-pinterest-tracking | `() => ConsentState` – gibt `"accepted" \| "rejected" \| null` zurück |
| `CONSENT_KEY` | Constant | slice-06-pinterest-tracking | `"cookie-consent"` – localStorage-Key für direkten Zugriff |
| `ConsentState` | TypeScript Type | slice-06-pinterest-tracking | `"accepted" \| "rejected" \| null` – exportiert aus `frontend/lib/consent/cookie-consent.ts` |
| WordPress-Seiten (Impressum, AGB, Datenschutz, Widerruf) | WordPress Pages | Footer (alle Slices), WooCommerce Checkout | URLs: `{WP_URL}/impressum`, `{WP_URL}/agb`, `{WP_URL}/datenschutz`, `{WP_URL}/widerruf` |
| Faktur Pro Rechnungs-PDF | WordPress/WooCommerce Output | Automatische E-Mail (WooCommerce) | PDF-Anhang an Bestellbestätigungs-E-Mail, trigger: `order_status_processing` |

### Integration Validation Tasks

- [ ] `isConsentAccepted()` gibt `true` zurück nach `setConsentAccepted()` (Slice 6 Dependency)
- [ ] `CONSENT_KEY` ist `"cookie-consent"` (Slice 6 liest diesen Key direkt)
- [ ] `CookieConsentBanner` in `app/layout.tsx` eingebunden (außerhalb von `ApolloWrapper`/`CartProvider` nicht nötig, innerhalb korrekt)
- [ ] Footer enthält Links zu allen 4 WooCommerce-Pflichtseiten
- [ ] Faktur Pro reagiert auf `order_status_processing` Hook und erstellt PDF

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `cookie-consent.ts` Utilities | Sektion 7 | YES | Exakte Funktionsnamen: `getConsentState`, `setConsentAccepted`, `setConsentRejected`, `hasConsentDecision`, `isConsentAccepted`, `CONSENT_KEY` |
| `CookieConsentBanner` Component | Sektion 7 | YES | `role="dialog"`, `aria-live="polite"`, `motion-safe:animate-slide-up`, 2 Buttons mit `touchAction: manipulation` |
| `--animate-slide-up` + `@keyframes slide-up` | Sektion 8 | YES | In `globals.css` ergänzt, `prefers-reduced-motion` Override vorhanden |
| `Footer` Component | Sektion 9 | YES | `LEGAL_LINKS` Array mit 4 Einträgen, `<nav aria-label="Rechtliche Seiten">`, `suppressHydrationWarning` auf Copyright |
| `layout.tsx` Modifikation | Sektion 10 | YES | `CookieConsentBanner` innerhalb `CartProvider`, nach `{children}` |
| Faktur Pro HTML-Template | Sektion 5 | YES | Enthält `{{ invoice_number }}`, §19 UStG Pflichttext, Mustache-Variablen für Bestellpositionen |

---

## Links

- Faktur Pro Plugin: https://faktur.pro
- Faktur Pro WooCommerce Dokumentation: https://faktur.pro/docs
- WooCommerce Steuereinstellungen: https://woocommerce.com/document/setting-up-taxes-in-woocommerce/
- DSGVO Cookie Consent Anforderungen: https://www.datenschutz-grundverordnung.eu/
- architecture.md: `docs/features/pod-shop-mvp/architecture.md`
- discovery.md: `docs/features/pod-shop-mvp/discovery.md`
- wireframes.md: `docs/features/pod-shop-mvp/wireframes.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### WordPress / WooCommerce

- [ ] Faktur Pro Plugin (v4.x, Download Feb 2026) installiert und aktiviert (`woorechnung` Plugin-Slug)
- [ ] Faktur Pro mit faktur.pro Account verbunden (Lizenzschlüssel eingetragen)
- [ ] Faktur Pro Trigger konfiguriert: Rechnung bei `order_status_processing` (Bestellstatus "Verarbeitung")
- [ ] Faktur Pro Mustache HTML-Template konfiguriert (enthält §19 UStG Pflichttext: "Gemäß §19 UStG wird keine Umsatzsteuer berechnet.")
- [ ] Faktur Pro Rechnungsnummernkreis konfiguriert (fortlaufend, GoBD-konform)
- [ ] Faktur Pro PDF-Anhang an Bestellbestätigungs-E-Mail aktiviert
- [ ] WordPress-Seite "Impressum" veröffentlicht (Slug: `/impressum`)
- [ ] WordPress-Seite "AGB" veröffentlicht (Slug: `/agb`)
- [ ] WordPress-Seite "Datenschutzerklärung" veröffentlicht (Slug: `/datenschutz`)
- [ ] WordPress-Seite "Widerruf" veröffentlicht (Slug: `/widerruf`)
- [ ] WooCommerce → Einstellungen → Erweitert → Widerruf-Seite: Seite "Widerruf" ausgewählt

### Frontend (Next.js) – Neue Dateien

- [ ] `frontend/lib/consent/cookie-consent.ts` – Consent-State-Utilities: `getConsentState`, `setConsentAccepted`, `setConsentRejected`, `hasConsentDecision`, `isConsentAccepted`, `CONSENT_KEY`
- [ ] `frontend/components/layout/cookie-consent-banner.tsx` – Cookie Consent Banner Client Component mit 2 Buttons

### Frontend (Next.js) – Modifizierte Dateien

- [ ] `frontend/app/globals.css` – Ergänzt: `--animate-slide-up` Token in `@theme`, `@keyframes slide-up`, `prefers-reduced-motion` Override
- [ ] `frontend/components/layout/footer.tsx` – MODIFIZIERT: `LEGAL_LINKS` Array + `<nav aria-label="Rechtliche Seiten">` mit 4 Pflicht-Links (Impressum, AGB, Datenschutz, Widerruf) + "Mein Konto" Link + Copyright-Jahr
- [ ] `frontend/app/layout.tsx` – MODIFIZIERT: `CookieConsentBanner` importiert und nach `{children}` innerhalb `CartProvider` eingebunden

### Tests

- [ ] `tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts` – Unit Tests: getConsentState (4 Tests), hasConsentDecision (3 Tests), isConsentAccepted (3 Tests), localStorage Key (3 Tests), §19 UStG Text (1 Test), Footer Legal Links (2 Tests)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- `cookie-consent.ts` muss SSR-sicher sein (`typeof window === 'undefined'` Check) – Next.js rendert Server-seitig
- `CookieConsentBanner` initial-State `false` ist bewusst (verhindert SSR-Hydration-Mismatch) – Banner erscheint erst nach `useEffect`
- Faktur Pro Mustache-Template-Variablen (`{{ }}`) müssen laut faktur.pro Dokumentation geprüft werden (Variable-Namen können sich zwischen Plugin-Versionen unterscheiden)
- `motion-safe:` Modifier in Tailwind v4 funktioniert out-of-the-box – kein extra CSS nötig außer dem `@keyframes`-Override in globals.css
- Footer-Links sind `<a href>` (externe Links zu WooCommerce) – KEIN `next/link` verwenden (andere Domain/Port im lokalen Dev-Setup)
