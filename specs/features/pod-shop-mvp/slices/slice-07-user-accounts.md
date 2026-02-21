# Slice 7: User-Accounts einrichten

> **Slice 7 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-06-pinterest-tracking.md` |
> | **Nächster:** | — |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-07-user-accounts` |
| **Test** | `pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-infrastruktur", "slice-02-produktkatalog-frontend", "slice-03-warenkorb-checkout-redirect"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` – Vitest Unit Tests (kein Playwright; WooCommerce-Seite ist kein Next.js, E2E würde externen Browser auf localhost:8080 benötigen)
- **Dependencies**: Slice 1 (Apollo, Infrastructure), Slice 2 (Header-Komponente, Footer-Komponente), Slice 3 (WooCommerce Checkout-Flow)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Slice 1 dokumentiert: `frontend/package.json` mit Next.js 16, Vitest 3.x, `@tailwindcss/postcss`. Stack: `typescript-nextjs`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts` |
| **Integration Command** | `pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts --reporter=verbose` |
| **Acceptance Command** | `curl -f http://localhost:8080/mein-konto && echo "WooCommerce Account Page OK"` |
| **Start Command** | `docker compose up -d && cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:8080/mein-konto` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 (App Router) + WooCommerce (Standard, kein Headless für Accounts)
- **Test Command**: Vitest Unit Tests für `getAccountUrl()` Utility-Funktion + Link-Konfiguration
- **Integration Command**: Gleiche Tests mit ausführlicher Ausgabe
- **Acceptance Command**: Manuelle Smoke Tests – WooCommerce "Mein Konto"-Seite erreichbar
- **Start Command**: Docker für WordPress Backend, dann Next.js Dev-Server
- **Health Endpoint**: WooCommerce Account-Seite (localhost:8080/mein-konto) – muss HTTP 200 zurückgeben
- **Mocking Strategy**: Keine externe API; reine Konfiguration + URL-Funktion ohne Mocking nötig

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Ready | `slice-02-produktkatalog-frontend.md` |
| 3 | Warenkorb + Checkout-Redirect | Ready | `slice-03-warenkorb-checkout-redirect.md` |
| 4 | Rechtliches + Rechnungen | Pending | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Pending | `slice-05-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Ready | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice aktiviert das User-Account-System für den POD Shop. Das gesamte Account-Management (Login, Registrierung, Bestellhistorie, Adressverwaltung) übernimmt WooCommerce out-of-the-box auf der WordPress-Seite – es wird kein eigenes Auth-System in Next.js implementiert.

Die einzige Next.js-seitige Aufgabe: Den "Mein Konto"-Link im Header und Footer korrekt auf die WooCommerce Account-Seite zeigen lassen. WooCommerce entscheidet selbst, ob der Nutzer zum Login oder direkt zum Account-Dashboard weitergeleitet wird.

**Scope-Abgrenzung:**
- WooCommerce "Mein Konto"-Seite: IN SCOPE (konfigurieren, dass sie erreichbar ist unter `/mein-konto`)
- "Mein Konto"-Link im Next.js Header (Mobile Menu): IN SCOPE (modifiziert `components/layout/mobile-menu.tsx`)
- "Mein Konto"-Link im Next.js Footer: IN SCOPE (Footer aus Slice 2 enthält bereits Platzhalter; hier korrekte URL setzen)
- Kein NextAuth, kein eigenes Login-Formular, kein JWT im Next.js Frontend: OUT OF SCOPE
- Account-Seiten-Styling (WooCommerce-seitig): Minimales WooCommerce-Standard-Theme, OUT OF SCOPE für ausführliches Styling
- Eigener Registrierungsflow in Next.js: OUT OF SCOPE

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Scope & Boundaries + Data Flow

```
User-Accounts (aus architecture.md Scope):
  - "User-Accounts (WooCommerce-seitig)" ist IN SCOPE

WooCommerce-seitige Screens (kein Next.js):
  - "Mein Konto" | Login/Registrierung, Bestellhistorie, Adressen

Next.js Frontend:
  - "Mein Konto"-Link → localhost:8080/mein-konto (Redirect)
  - WooCommerce Account-Session via Standard WP Session Cookie
  - Kein eigenes Auth-System

Discovery Flow 3: User-Account
  1. Registrierung/Login auf WooCommerce-Seite
  2. Bestellhistorie einsehen
  3. Adressen verwalten
  4. Aus dem Next.js Frontend: Link zu "Mein Konto" auf WooCommerce
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Presentation` (Next.js) | `components/layout/mobile-menu.tsx` – "Mein Konto"-Link aktivieren (bereits als Platzhalter in Slice 2 vorbereitet) |
| `Presentation` (Next.js) | `components/layout/footer.tsx` – "Mein Konto"-Link auf korrekten WooCommerce-URL setzen |
| `Presentation` (Next.js) | `lib/config/account.ts` – `getAccountUrl()` Utility für WooCommerce Account-URL |
| `Business Logic` (WordPress) | WooCommerce "Mein Konto"-Seite unter Permalink `/mein-konto` erreichbar konfigurieren |
| `Business Logic` (WordPress) | WooCommerce Einstellung: Kunden dürfen sich bei Checkout registrieren |

### 2. Datenfluss

```
User klickt "Mein Konto" im Next.js Header/Footer
  ↓
<a href="http://localhost:8080/mein-konto"> (externer Link, kein next/link)
  ↓
Browser navigiert zu WooCommerce (localhost:8080/mein-konto)
  ↓
WooCommerce prüft WP Session Cookie:
  - Kein Session → Login-Formular anzeigen
  - Aktiver Account → Dashboard anzeigen (Bestellhistorie, Adressen)
  ↓
Nach Login: Standard WooCommerce Account-Dashboard
```

### 3. WooCommerce Konfiguration

| Setting | Wert | WP-Admin Pfad |
|---------|------|---------------|
| "Mein Konto"-Seite | Seite "Mein Konto" (Standard WooCommerce Setup) | WooCommerce → Einstellungen → Konten & Datenschutz |
| Permalink "Mein Konto" | `/mein-konto` | Seiteneditor → Seite "Mein Konto" → Permalink |
| Kundenkonto erstellen bei Checkout | Aktiviert (optional, Empfehlung) | WooCommerce → Einstellungen → Konten & Datenschutz |
| Kundenkonto erstellen bei Registrierung | Aktiviert | WooCommerce → Einstellungen → Konten & Datenschutz |
| Gastbestellungen | Aktiviert (Kunden müssen sich nicht registrieren) | WooCommerce → Einstellungen → Konten & Datenschutz |

**Hinweis:** WooCommerce erstellt die "Mein Konto"-Seite automatisch beim Setup-Wizard (Slice 1). Falls nicht vorhanden: WP-Admin → Seiten → Neu → Titel "Mein Konto", Inhalt `[woocommerce_my_account]` Shortcode, Permalink `/mein-konto`.

### 4. Next.js: Account URL Utility

**Datei:** `frontend/lib/config/account.ts`

```typescript
// Zentrale Konfiguration für WooCommerce Account-URLs
// Verwendet NEXT_PUBLIC_WP_URL aus .env.local (aus Slice 1 bekannt)

export function getAccountUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto`
}

export function getAccountOrdersUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto/orders`
}

export function getAccountAddressesUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto/edit-address`
}
```

### 5. Next.js: Mobile Menu Update

Der Mobile Menu aus Slice 2 (`components/layout/mobile-menu.tsx`) enthält den "Mein Konto"-Link bereits im Wireframe-Design (State: "Mobile menu open" → "Overlay with ... 'Mein Konto' link"). Dieser Link wird jetzt mit der korrekten URL befüllt.

**Datei:** `frontend/components/layout/mobile-menu.tsx` (Modifikation)

Relevanter Ausschnitt – der "Mein Konto"-Link im Mobile Overlay:

```typescript
import { getAccountUrl } from '@/lib/config/account'

// Im Mobile Menu Overlay (bereits vorhanden, URL ergänzen):
<a
  href={getAccountUrl()}
  className="flex items-center py-3 text-base text-text-secondary hover:text-text-primary transition-colors"
>
  Mein Konto
</a>
```

**Hinweis zur Implementierung:** `getAccountUrl()` wird server-side aufgerufen (Server Component oder statisch zur Build-Zeit). Der Link ist ein normales `<a>` (kein `next/link`), da er auf eine externe Domain (WooCommerce) zeigt.

### 6. Next.js: Footer Update

Der Footer aus Slice 2 (`components/layout/footer.tsx`) enthält laut Wireframe bereits einen "Mein Konto (→ WooCommerce)"-Eintrag. Die korrekte URL wird gesetzt.

**Datei:** `frontend/components/layout/footer.tsx` (Modifikation)

Relevanter Ausschnitt:

```typescript
import { getAccountUrl } from '@/lib/config/account'

// Im Footer (bereits vorhanden, URL ergänzen):
<a
  href={getAccountUrl()}
  className="text-sm text-text-secondary hover:text-text-primary transition-colors"
>
  Mein Konto
</a>
```

---

## UI Anforderungen

### Wireframe (aus wireframes.md)

> **Quelle:** `wireframes.md` → Shared: Header (Mobile State), Shared: Footer

**Header Mobile Menu (State: offen):**
```
Mobile menu open → Overlay mit:
  - Kategorie-Links
  - Legal page links
  - "Mein Konto" link  ← DIESER SLICE
```

**Footer:**
```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│  Impressum · AGB · Datenschutz · Widerruf                │
│                                                          │
│  Mein Konto (→ WooCommerce)    ← DIESER SLICE           │
│                                                          │
│  © 2025 Shop Name                                        │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/web-design/SKILL.md` – Accessibility: Links mit korrektem `href`, keine `<div onClick>` für Navigation
- `.claude/skills/react-best-practices/SKILL.md` – `bundle-barrel-imports`: Direkte Imports

### 1. "Mein Konto"-Link (Header Mobile Menu + Footer)

**Komponenten & Dateien:**
- `components/layout/mobile-menu.tsx` – Modifikation: `getAccountUrl()` einbinden
- `components/layout/footer.tsx` – Modifikation: `getAccountUrl()` einbinden
- `lib/config/account.ts` – Neu: URL-Utility

**Verhalten:**
- Klick öffnet WooCommerce "Mein Konto"-Seite in normalem Browser-Navigation (kein `target="_blank"`)
- WooCommerce entscheidet intern: Login-Form (nicht eingeloggt) oder Dashboard (eingeloggt)
- Der Link ist immer sichtbar (keine Konditionierung auf Auth-Status im Next.js Frontend)

**Zustände:**
- Default: Link sichtbar, zeigt auf `{WP_URL}/mein-konto`
- Hover: `hover:text-text-primary` (konsistent mit anderen Links)
- Focus: `focus-visible:ring-2 focus-visible:ring-primary` (Keyboard-Navigation)

**Accessibility:**
- [ ] `<a>` mit `href` (kein `<div onClick>`) – semantisches HTML
- [ ] Sichtbarer Focus-State für Keyboard-Navigation
- [ ] Link-Text "Mein Konto" ist selbsterklärend (kein "hier klicken")

---

## Acceptance Criteria

1) GIVEN WooCommerce ist konfiguriert und WordPress läuft unter `localhost:8080`
   WHEN ein Nutzer `http://localhost:8080/mein-konto` aufruft
   THEN zeigt WooCommerce das Login-Formular (nicht eingeloggte Nutzer) mit Feldern für E-Mail und Passwort sowie einem Registrierungsformular

2) GIVEN ein Nutzer ist nicht registriert
   WHEN er sich auf `http://localhost:8080/mein-konto` mit E-Mail + Passwort registriert
   THEN wird ein neues WooCommerce-Kundenkonto erstellt und der Nutzer ist eingeloggt und sieht das Account-Dashboard

3) GIVEN ein Nutzer hat ein Konto und ist nicht eingeloggt
   WHEN er sich auf `http://localhost:8080/mein-konto` mit E-Mail + Passwort einloggt
   THEN wird er zum WooCommerce Account-Dashboard weitergeleitet und sieht seine Bestellhistorie

4) GIVEN ein Nutzer hat eine Test-Bestellung durchgeführt (Slice 3 Testkauf via Mollie Sandbox)
   WHEN er sich in sein WooCommerce-Konto einloggt und die Bestellhistorie aufruft
   THEN ist die Testbestellung in der Liste sichtbar mit Bestellnummer, Datum, Status und Betrag

5) [MANUELLER TEST – kein automatisierter Test; Browser-Navigation nicht per Vitest testbar]
   GIVEN das Next.js Frontend läuft unter `localhost:3000`
   WHEN ein Nutzer auf den "Mein Konto"-Link im Mobile Menu oder Footer klickt
   THEN navigiert der Browser zu `http://localhost:8080/mein-konto` (WooCommerce Account-Seite)
   → Abgedeckt durch manuellen Test #8 und #9 in der Testfälle-Section; das `href`-Attribut des Links wird automatisiert durch AC-6 und AC-7 geprüft

6) GIVEN der Mobile Menu ist geöffnet im Next.js Frontend
   WHEN der "Mein Konto"-Link gerendert wird
   THEN ist `href` auf `{NEXT_PUBLIC_WP_URL}/mein-konto` gesetzt (aus Environment-Variable, Fallback: `http://localhost:8080/mein-konto`)

7) GIVEN der Footer wird gerendert
   WHEN der "Mein Konto"-Link gerendert wird
   THEN ist `href` auf `{NEXT_PUBLIC_WP_URL}/mein-konto` gesetzt, konsistent mit dem Mobile Menu Link

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

describe('Slice 07: User-Accounts – getAccountUrl()', () => {
  const originalEnv = process.env

  beforeEach(() => {
    vi.resetModules()
    process.env = { ...originalEnv }
  })

  afterEach(() => {
    process.env = originalEnv
  })

  it('should return WooCommerce account URL with NEXT_PUBLIC_WP_URL env variable', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto')
  })

  it('should fall back to localhost:8080 when NEXT_PUBLIC_WP_URL is not set', async () => {
    // Arrange
    delete process.env.NEXT_PUBLIC_WP_URL
    const { getAccountUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto')
  })

  it('should return orders URL pointing to WooCommerce orders page', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountOrdersUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountOrdersUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto/orders')
  })

  it('should return addresses URL pointing to WooCommerce address page', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountAddressesUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountAddressesUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto/edit-address')
  })

  it('should use production URL when NEXT_PUBLIC_WP_URL is set to production domain', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'https://meinshop.de'
    const { getAccountUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('https://meinshop.de/mein-konto')
  })

  it('should not contain trailing slash duplication in URL', async () => {
    // Arrange – WP_URL ohne trailing slash
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountUrl } = await import('../../../frontend/lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).not.toContain('//mein-konto')
    expect(url).toContain('/mein-konto')
  })
})
```
</test_spec>

### Manuelle Tests (WooCommerce-seitig – nicht automatisierbar)

1. `docker compose up -d` starten → WordPress läuft unter `http://localhost:8080`
2. `http://localhost:8080/mein-konto` im Browser öffnen → Login-Formular und Registrierungsformular sichtbar
3. Neues Konto registrieren (Testnutzer: test@example.com) → Weiterleitung auf Account-Dashboard
4. Ausloggen → Erneut auf `http://localhost:8080/mein-konto` → Login-Formular erscheint
5. Einloggen mit Testnutzer → Account-Dashboard mit Bestellhistorie (leer initial)
6. Testkauf durchführen (Slice 3 Mollie Sandbox) → mit Testnutzer-E-Mail als Gastbestellung oder eingeloggt
7. Bestellhistorie im Account prüfen → Testbestellung erscheint
8. Next.js Frontend starten (`pnpm dev`) → Mobile Menu öffnen → "Mein Konto"-Link sichtbar → klicken → WooCommerce Account-Seite öffnet
9. Footer "Mein Konto"-Link → klicken → WooCommerce Account-Seite öffnet

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] WooCommerce "Mein Konto"-Seite erreichbar unter `localhost:8080/mein-konto`
- [ ] Registrierung + Login auf WooCommerce-Seite funktioniert
- [ ] Bestellhistorie nach Testkauf sichtbar
- [ ] "Mein Konto"-Link in Next.js Mobile Menu und Footer korrekt verlinkt
- [ ] Unit Tests laufen durch (`pnpm test`)
- [ ] Kein eigenes Auth-System in Next.js implementiert

---

## Skill Verification

### React Best Practices Verification

**Relevant für diesen Slice:**
- [x] `bundle-barrel-imports`: `getAccountUrl` wird direkt aus `@/lib/config/account` importiert, nicht aus Barrel
- [x] Kein `next/dynamic` nötig (einfacher Link, keine schwere Komponente)

### Web Design Guidelines Verification

**Accessibility:**
- [x] "Mein Konto"-Link ist `<a href="...">` (semantisches HTML, kein `<div onClick>`)
- [x] Link-Text ist selbsterklärend ("Mein Konto")
- [x] `focus-visible:ring-2` Focus-State vorhanden (konsistent mit anderen Links aus Slice 2)

### Tailwind v4 Patterns Verification

- [x] Keine hardcoded URLs (aus `NEXT_PUBLIC_WP_URL` Environment-Variable)
- [x] Bestehende Tailwind-Klassen aus Slice 2 werden wiederverwendet (kein neuer `@theme` Bedarf)

---

## Constraints & Hinweise

**Betrifft:**
- WooCommerce Standard-Account-Seiten werden nicht gestylt (Standard-Theme, kein Headless)
- WooCommerce Session-Cookie wird vom Browser automatisch verwaltet – kein Eingriff aus Next.js nötig
- Bei Checkout (Slice 3): WooCommerce verknüpft die Bestellung automatisch mit dem eingeloggten Kunden

**API Contract:**
- Kein GraphQL-API-Aufruf in diesem Slice
- Kein NextAuth oder JWT im Next.js Frontend
- `NEXT_PUBLIC_WP_URL` aus `.env.local` (aus Slice 1 bekannt: `http://localhost:8080`)

**Abgrenzung:**
- Desktop-Header hat keinen "Mein Konto"-Link laut Wireframe (nur Mobile Menu + Footer) – das bleibt so
- WooCommerce "Mein Konto"-Seite kann optional minimal gestylt werden (child-theme), ist aber OUT OF SCOPE für diesen Slice

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-infrastruktur | `NEXT_PUBLIC_WP_URL` | Environment Variable | Gesetzt in `frontend/.env.local` auf `http://localhost:8080` |
| slice-01-infrastruktur | WordPress + WooCommerce | Docker Service | Läuft unter `localhost:8080`, WooCommerce aktiviert und konfiguriert |
| slice-02-produktkatalog-frontend | `components/layout/mobile-menu.tsx` | React Component | Existiert, enthält Mobile Menu Overlay mit Platz für "Mein Konto"-Link |
| slice-02-produktkatalog-frontend | `components/layout/footer.tsx` | React Component | Existiert, enthält "Mein Konto"-Platzhalter laut Wireframe |
| slice-03-warenkorb-checkout-redirect | WooCommerce Checkout Flow | WooCommerce Standard | Bestellungen werden in WooCommerce gespeichert und sind in Bestellhistorie sichtbar |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| — | — | — | Letzter Slice – keine weiteren Slice-Consumers |

### Integration Validation Tasks

- [ ] `NEXT_PUBLIC_WP_URL` in `frontend/.env.local` gesetzt und korrekt
- [ ] `frontend/components/layout/mobile-menu.tsx` enthält "Mein Konto"-Link mit `getAccountUrl()`
- [ ] `frontend/components/layout/footer.tsx` enthält "Mein Konto"-Link mit `getAccountUrl()`
- [ ] `http://localhost:8080/mein-konto` antwortet mit HTTP 200 (WooCommerce Account-Seite)
- [ ] Registrierung auf WooCommerce-Seite funktioniert

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `lib/config/account.ts` | Sektion 4 | YES | Alle 3 Funktionen: `getAccountUrl`, `getAccountOrdersUrl`, `getAccountAddressesUrl` |
| `mobile-menu.tsx` Ausschnitt | Sektion 5 | YES | `<a href={getAccountUrl()}>` – kein `next/link`, da externe Domain |
| `footer.tsx` Ausschnitt | Sektion 6 | YES | `<a href={getAccountUrl()}>` im Footer |

---

## Links

- Discovery: `docs/features/pod-shop-mvp/discovery.md` → Flow 3: User-Account
- Architecture: `docs/features/pod-shop-mvp/architecture.md` → Scope & Boundaries
- Wireframes: `docs/features/pod-shop-mvp/wireframes.md` → Shared: Header, Shared: Footer
- WooCommerce Account Docs: https://woocommerce.com/document/woocommerce-account-pages/

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### WordPress / WooCommerce
- [ ] WooCommerce "Mein Konto"-Seite erreichbar unter `http://localhost:8080/mein-konto` (Standard WooCommerce – manuelle Konfiguration)
- [ ] WooCommerce Einstellungen: Kundenkonto-Erstellung bei Checkout aktiviert (manuelle Konfiguration)

### Frontend (Next.js)
- [ ] `frontend/lib/config/account.ts` – `getAccountUrl()`, `getAccountOrdersUrl()`, `getAccountAddressesUrl()` Utility-Funktionen
- [ ] `frontend/components/layout/mobile-menu.tsx` – Modifikation: "Mein Konto"-Link mit `getAccountUrl()` befüllt
- [ ] `frontend/components/layout/footer.tsx` – Modifikation: "Mein Konto"-Link mit `getAccountUrl()` befüllt

### Tests
- [ ] `tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts` – 6 Unit Tests für `getAccountUrl()`, `getAccountOrdersUrl()`, `getAccountAddressesUrl()`
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- WordPress/WooCommerce-Konfiguration wird manuell im Browser durchgeführt (WP-Admin)
- `mobile-menu.tsx` und `footer.tsx` sind Modifikationen bestehender Dateien aus Slice 2 – nicht neu erstellen
- Der "Mein Konto"-Link verwendet `<a>` (nicht `next/link`), da er auf externe Domain (WooCommerce) verlinkt
