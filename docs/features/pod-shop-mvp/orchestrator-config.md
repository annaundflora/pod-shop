# Orchestrator Configuration: POD Shop MVP

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-21

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "docs/features/pod-shop-mvp/compliance-architecture.md"
    required: "Verdict == APPROVED"

  - name: "Gate 2: Alle Slices Approved"
    files:
      - "docs/features/pod-shop-mvp/slices/compliance-slice-01.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-02.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-03.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-04.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-05.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-06.md"
      - "docs/features/pod-shop-mvp/slices/compliance-slice-07.md"
    required: "ALL Verdict == APPROVED"
    status: "ALLE 7 APPROVED - Gate erfullt"

  - name: "Gate 3: Integration Map Valid"
    file: "docs/features/pod-shop-mvp/integration-map.md"
    required: "Missing Inputs == 0 AND Verdict == READY FOR ORCHESTRATION"
    status: "BESTATIGT - READY FOR ORCHESTRATION"
```

---

## Slices Configuration

```yaml
slices:
  - id: "slice-01-infrastruktur"
    name: "Infrastruktur aufsetzen"
    order: 1
    parallel_group: null
    stack: "typescript-nextjs+docker-compose"
    depends_on: []
    spec_file: "docs/features/pod-shop-mvp/slices/slice-01-infrastruktur.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-01.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts --reporter=verbose"
    acceptance_command: "curl -f http://localhost:8080/graphql -X POST -H 'Content-Type: application/json' -d '{\"query\":\"{ products { nodes { id name } } }\"}' && echo 'GraphQL OK'"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:8080/graphql"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "frontend/lib/apollo/token-manager.ts"
      - "frontend/lib/apollo/client.ts"
      - "frontend/components/apollo-wrapper.tsx"
      - "frontend/app/layout.tsx"
      - "docker-compose.yml"

  - id: "slice-02-produktkatalog-frontend"
    name: "Produktkatalog Frontend"
    order: 2
    parallel_group: null
    stack: "typescript-nextjs"
    depends_on:
      - "slice-01-infrastruktur"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-02-produktkatalog-frontend.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-02.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts --reporter=verbose"
    acceptance_command: "curl -f http://localhost:3000/ && curl -f http://localhost:3000/kategorie/t-shirts && echo 'Pages OK'"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:3000/"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "frontend/lib/graphql/types.ts"
      - "frontend/lib/graphql/fragments.ts"
      - "frontend/lib/graphql/queries.ts"
      - "frontend/components/product/add-to-cart-button.tsx"
      - "frontend/app/produkt/[slug]/product-variant-selector.tsx"
      - "frontend/components/layout/header.tsx"
      - "frontend/components/layout/footer.tsx"
      - "frontend/components/layout/mobile-menu.tsx"

  - id: "slice-03-warenkorb-checkout-redirect"
    name: "Warenkorb + Checkout-Redirect"
    order: 3
    parallel_group: null
    stack: "typescript-nextjs"
    depends_on:
      - "slice-01-infrastruktur"
      - "slice-02-produktkatalog-frontend"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-03-warenkorb-checkout-redirect.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-03.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts --reporter=verbose"
    acceptance_command: "curl -f http://localhost:3000/warenkorb && echo 'Cart Page OK'"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:3000/warenkorb"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "frontend/contexts/cart-context.tsx"
      - "frontend/contexts/cart-context.types.ts"
      - "frontend/lib/graphql/cart-mutations.ts"
      - "frontend/lib/cart/checkout-redirect.ts"
      - "frontend/app/warenkorb/page.tsx"
      - "frontend/app/layout.tsx"  # Modifiziert: CartProvider eingebunden
    modifies:
      - "frontend/app/layout.tsx"
      - "frontend/components/layout/header.tsx"
      - "frontend/components/product/add-to-cart-button.tsx"
      - "frontend/app/produkt/[slug]/product-variant-selector.tsx"

  - id: "slice-04-rechtliches-rechnungen"
    name: "Rechtliches + Rechnungen"
    order: 4
    parallel_group: "group-post-checkout"
    stack: "typescript-nextjs"
    depends_on:
      - "slice-01-infrastruktur"
      - "slice-02-produktkatalog-frontend"
      - "slice-03-warenkorb-checkout-redirect"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-04-rechtliches-rechnungen.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-04.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts --reporter=verbose"
    acceptance_command: "curl -f http://localhost:3000 && echo 'Frontend OK'"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:3000"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "frontend/components/layout/cookie-consent-banner.tsx"
      - "frontend/lib/consent/cookie-consent.ts"
    modifies:
      - "frontend/components/layout/footer.tsx"
      - "frontend/app/layout.tsx"
      - "frontend/app/globals.css"
    manual_setup_required:
      - "Faktur Pro Plugin installieren + konfigurieren in WP-Admin"
      - "WordPress-Seiten anlegen: /impressum, /agb, /datenschutz, /widerruf"
      - "WooCommerce: Widerruf-Seite konfigurieren"

  - id: "slice-05-pod-anbindung-spreadconnect"
    name: "POD-Anbindung (Spreadconnect)"
    order: 4
    parallel_group: "group-post-checkout"
    stack: "php-wordpress"
    depends_on:
      - "slice-01-infrastruktur"
      - "slice-03-warenkorb-checkout-redirect"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-05-pod-anbindung-spreadconnect.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-05.md"
    test_command: "cd wordpress/plugins/spreadconnect-pod && php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox"
    integration_command: "cd wordpress/plugins/spreadconnect-pod && php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox --verbose"
    acceptance_command: "curl -s http://localhost:8080/wp-json/spreadconnect/v1/health | grep -q 'ok' && echo 'Spreadconnect Plugin OK'"
    start_command: "docker compose up -d"
    health_endpoint: "http://localhost:8080/wp-json/spreadconnect/v1/health"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php"
      - "wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php"
      - "wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php"
      - "wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php"
      - "wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php"
    manual_setup_required:
      - "Plugin in WP-Admin aktivieren"
      - "Spreadconnect API Key in WP-Admin eintragen"
      - "Staging-Modus aktivieren fur lokale Entwicklung"

  - id: "slice-06-pinterest-tracking"
    name: "Pinterest Tracking (Client + CAPI)"
    order: 5
    parallel_group: null
    stack: "typescript-nextjs+php-wordpress"
    depends_on:
      - "slice-01-infrastruktur"
      - "slice-02-produktkatalog-frontend"
      - "slice-03-warenkorb-checkout-redirect"
      - "slice-04-rechtliches-rechnungen"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-06-pinterest-tracking.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-06.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-06-pinterest-tracking.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-06-pinterest-tracking.test.ts --reporter=verbose"
    acceptance_command: "cd wordpress/plugins/pinterest-capi && ./vendor/bin/phpunit tests/ --testdox"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:3000/"
    mocking_strategy: "mock_external"
    critical_outputs:
      - "frontend/lib/tracking/pinterest-tag.ts"
      - "frontend/lib/tracking/event-id.ts"
      - "frontend/hooks/use-pinterest-tag.ts"
      - "frontend/components/tracking/pinterest-tag-init.tsx"
      - "wordpress/plugins/pinterest-capi/pinterest-capi.php"
      - "wordpress/plugins/pinterest-capi/includes/class-pinterest-capi-service.php"
      - "wordpress/plugins/pinterest-capi/includes/class-pinterest-capi-hooks.php"
    modifies:
      - "frontend/app/layout.tsx"
      - "frontend/app/page.tsx"
      - "frontend/app/kategorie/[slug]/page.tsx"
      - "frontend/contexts/cart-context.tsx"
      - "frontend/lib/cart/checkout-redirect.ts"
    manual_setup_required:
      - "Pinterest Business Account einrichten"
      - "Pinterest CAPI Plugin in WP-Admin konfigurieren (Access Token, Ad Account ID, Tag ID)"
      - "NEXT_PUBLIC_PINTEREST_TAG_ID in frontend/.env.local setzen"

  - id: "slice-07-user-accounts"
    name: "User Accounts"
    order: 5
    parallel_group: null
    stack: "typescript-nextjs"
    depends_on:
      - "slice-01-infrastruktur"
      - "slice-02-produktkatalog-frontend"
      - "slice-03-warenkorb-checkout-redirect"
    spec_file: "docs/features/pod-shop-mvp/slices/slice-07-user-accounts.md"
    compliance_file: "docs/features/pod-shop-mvp/slices/compliance-slice-07.md"
    test_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts"
    integration_command: "cd frontend && pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts --reporter=verbose"
    acceptance_command: "curl -f http://localhost:8080/mein-konto && echo 'WooCommerce Account Page OK'"
    start_command: "docker compose up -d && cd frontend && pnpm dev"
    health_endpoint: "http://localhost:8080/mein-konto"
    mocking_strategy: "no_mocks"
    critical_outputs:
      - "frontend/lib/config/account.ts"
    modifies:
      - "frontend/components/layout/mobile-menu.tsx"
      - "frontend/components/layout/footer.tsx"
    manual_setup_required:
      - "WooCommerce Mein-Konto-Seite unter /mein-konto konfigurieren"
      - "WooCommerce: Kundenkonto-Einstellungen pruefen"
```

---

## Implementation Order

Based on dependency analysis:

| Order | Slice ID | Name | Depends On | Parallel? | Stack |
|-------|----------|------|------------|-----------|-------|
| 1 | slice-01-infrastruktur | Infrastruktur | - | Nein (Foundation) | typescript-nextjs + docker |
| 2 | slice-02-produktkatalog-frontend | Produktkatalog | slice-01 | Nein (Liefert Typen fur slice-03) | typescript-nextjs |
| 3 | slice-03-warenkorb-checkout-redirect | Warenkorb + Checkout | slice-01, slice-02 | Nein (Liefert WooCommerce Bestellsystem) | typescript-nextjs |
| 4a | slice-04-rechtliches-rechnungen | Rechtliches + Rechnungen | slice-01, 02, 03 | Ja (parallel zu 4b) | typescript-nextjs |
| 4b | slice-05-pod-anbindung-spreadconnect | Spreadconnect POD | slice-01, slice-03 | Ja (parallel zu 4a) | php-wordpress |
| 5 | slice-06-pinterest-tracking | Pinterest Tracking | slice-01, 02, 03, 04 | Nein (braucht slice-04 cookie-consent) | typescript-nextjs + php |
| 5 | slice-07-user-accounts | User Accounts | slice-01, 02, 03 | Ja (parallel zu slice-06) | typescript-nextjs |

**Wichtige Einschrankung Parallelitat 4a/4b:**
Slices 04 und 05 konnen parallel implementiert werden, da sie unterschiedliche Stacks haben (Next.js vs. PHP). Beide warten auf denselben Vorganger (slice-03). Kein Datekonflikt, da slice-04 nur Next.js-Dateien anlegt und slice-05 nur PHP-Plugin-Dateien.

**Wichtige Einschrankung Slice 06:**
Slice 06 MUSS auf slice-04 warten, da es den `cookie-consent` localStorage Key und den `cookie-consent-accepted` Custom Event konsumiert, die von Slice 04 definiert werden.

**app/layout.tsx Modifikations-Reihenfolge (KRITISCH):**
Die Datei `frontend/app/layout.tsx` wird von 4 Slices modifiziert. Der Orchestrator muss die akkumulativen Modifikationen in dieser Reihenfolge anwenden:

```
Slice 01: ApolloWrapper als Root Provider
  ↓
Slice 03: CartProvider innerhalb ApolloWrapper hinzufugen
  ↓
Slice 04: CookieConsentBanner nach {children} innerhalb CartProvider
  ↓
Slice 06: PinterestTagInit nach CookieConsentBanner (BEIDE Komponenten behalten)
```

**Finale app/layout.tsx Zielstruktur:**
```typescript
<html lang="de">
  <body>
    <ApolloWrapper>
      <CartProvider>
        {children}
        <CookieConsentBanner />
        <PinterestTagInit />
      </CartProvider>
    </ApolloWrapper>
  </body>
</html>
```

---

## Post-Slice Validation

```yaml
validation_steps:
  per_slice:
    - step: "1. Deliverables Check"
      action: "Verifiziere dass alle Dateien in DELIVERABLES_START/END existieren"
      failure: "Agent wird blockiert - fehlende Dateien melden"

    - step: "2. Unit Tests ausfuhren"
      action: "Test Command aus Slice-Metadata ausfuhren"
      success_condition: "Exit Code 0, alle Tests PASS"
      failure: "Slice als FAILED markieren, Fixes anfordern"

    - step: "3. Integration Points pruefen"
      action: "Outputs aus integration-map.md Connections verifizieren"
      reference: "integration-map.md → Connections Tabelle"

    - step: "4. Acceptance Test (optional, wenn Stack lauft)"
      action: "Acceptance Command aus Slice-Metadata ausfuhren"
      note: "Erfordert laufenden Docker + Next.js Dev Server"

  slice_specific:
    slice-01:
      - "docker compose ps → 3 Container (wordpress, db, phpmyadmin) running"
      - "curl http://localhost:8080/graphql → HTTP 200"
      - "pnpm test → 10 Tests PASS (7 TokenManager + 3 sessionLink)"

    slice-02:
      - "pnpm build → keine TypeScript/Build-Fehler"
      - "Vitest: extractVariantOptions (7 Tests) + findVariation (5 Tests) + generateProductJsonLd (6 Tests) PASS"
      - "HTTP 200 auf /, /kategorie/{slug}, /produkt/{slug}"

    slice-03:
      - "pnpm test → CartContext Tests + checkout-redirect Tests PASS"
      - "HTTP 200 auf /warenkorb"
      - "CartProvider in app/layout.tsx vorhanden"
      - "addToCart Mutation feuert korrekt auf Produktdetailseite"

    slice-04:
      - "pnpm test → cookie-consent.ts Tests PASS (hasConsentDecision, setConsentAccepted, setConsentRejected)"
      - "CookieConsentBanner in app/layout.tsx vorhanden (nach CartProvider)"
      - "Footer enthalt 4 Legal Links + Mein Konto Link"
      - "WordPress Seiten /impressum, /agb, /datenschutz, /widerruf → HTTP 200"

    slice-05:
      - "composer install in wordpress/plugins/spreadconnect-pod/"
      - "phpunit → 13 Tests PASS (ApiClient + OrderService + TrackingService)"
      - "curl http://localhost:8080/wp-json/spreadconnect/v1/health → {status: ok}"
      - "Plugin in WP-Admin aktiv"

    slice-06:
      - "pnpm test → Vitest Tests fur pinterest-tag.ts + event-id.ts + usePinterestTag PASS"
      - "phpunit im pinterest-capi Plugin-Verzeichnis → PinterestCAPIServiceTest PASS"
      - "PinterestTagInit in app/layout.tsx vorhanden (nach CookieConsentBanner)"
      - "cart-context.tsx enthalt fireAddToCart Aufruf nach addToCartMutation"
      - "checkout-redirect.ts enthalt pinterest_event_id URL-Parameter"

    slice-07:
      - "pnpm test → 6 getAccountUrl Tests PASS"
      - "mobile-menu.tsx enthalt getAccountUrl() Import + <a href={getAccountUrl()}>"
      - "footer.tsx enthalt getAccountUrl() Import + Mein Konto Link"
      - "curl http://localhost:8080/mein-konto → HTTP 200"
```

---

## E2E Validation

```yaml
e2e_validation:
  trigger: "Nach Abschluss aller 7 Slices"
  reference: "e2e-checklist.md"

  steps:
    - step: "E2E Checklist ausfuhren"
      file: "e2e-checklist.md"
      scope: "Alle Flows (Flow 1: Kaufprozess, Flow 2: User Account)"

    - step: "Cross-Slice Integration Points validieren"
      reference: "e2e-checklist.md → Cross-Slice Integration Points (11 Punkte)"

    - step: "Bei fehlgeschlagenem Check"
      actions:
        - "Verantwortlichen Slice aus integration-map.md identifizieren"
        - "Fix-Aufgabe mit Slice-Referenz erstellen"
        - "Test Commands des Slices erneut ausfuhren"
        - "E2E-Checklist nach Fix wiederholen"

    - step: "Finale Genehmigung"
      condition: "ALLE Checks in e2e-checklist.md PASS"
      output: "Feature READY for merge / deployment"

  critical_integration_checks:
    - "Apollo Session Token fließt korrekt von Slice 01 → Slice 03"
    - "CartProvider umschliesst alle Client Components die useCart() nutzen"
    - "app/layout.tsx enthalt korrekte Verschachtelung aller 4 Provider/Components"
    - "cookie-consent localStorage Flag aus Slice 04 wird von Slice 06 korrekt gelesen"
    - "event_id Kette: generateEventId() → storeLastEventId() → checkout-redirect URL → WooCommerce Order Meta → CAPI purchase"
```

---

## Rollback Strategy

```yaml
rollback:
  slice_failure:
    condition: "Ein Slice schlagt fehl nach Implementierung"
    action: "Nur Deliverables dieses Slices revertieren"
    note: "Vorangegangene Slices sind stabil, keine Kaskaden"
    example: "Slice 05 (PHP) schlagt fehl → nur wordpress/plugins/spreadconnect-pod/ loschen, slice-01 bis 04 unverandert"

  integration_failure:
    condition: "E2E Tests schlagen nach allen Slice-Implementierungen fehl"
    action: "integration-map.md Connections pruefen, betroffenen Slice identifizieren"
    steps:
      - "Fehler-Cross-Referenz mit integration-map.md Connections Tabelle"
      - "Test Command des betroffenen Slices isoliert ausfuhren"
      - "Falls Fix nötig: Slice-Spec updaten + Compliance Re-Check"

  layout_accumulation_failure:
    condition: "app/layout.tsx enthalt nach Slice 06 nicht alle 4 Modifikationen"
    action: "Manuelle Vereinigung der 4 Modifikationen in finale Struktur"
    target: "ApolloWrapper > CartProvider > {children} + CookieConsentBanner + PinterestTagInit"

  database_rollback:
    condition: "WordPress-Konfiguration korrumpiert"
    action: "docker compose down -v && docker compose up -d (frische DB)"
    note: "Verlust aller WooCommerce-Konfiguration - Slice 01 Setup wiederholen"
```

---

## Monitoring During Implementation

| Metric | Schwellenwert | Aktion |
|--------|---------------|--------|
| Slice-Implementierungszeit | Mehr als 2x Schatzung | Status-Check anfordern |
| Test-Failures blocking | Mehr als 0 | Slice als BLOCKED markieren |
| TypeScript Build-Fehler | Jeder | Sofort beheben vor Deliverable-Check |
| Fehlende Deliverable-Datei | Jede | Agent wird blockiert, Stop-Hook greift |
| PHP Fatal Error in Plugin | Jeder | Slice-05/06 PHP neu pruefen |

---

## Environment Variables Checklist

Vor Implementierungsbeginn sicherstellen:

```bash
# Root-Ebene (.env)
MYSQL_DATABASE=wordpress
MYSQL_USER=wordpress
MYSQL_PASSWORD=wordpress_password
MYSQL_ROOT_PASSWORD=root_password

# frontend/.env.local
NEXT_PUBLIC_GRAPHQL_URL=http://localhost:8080/graphql
NEXT_PUBLIC_WP_URL=http://localhost:8080
NEXT_PUBLIC_WC_CHECKOUT_URL=http://localhost:8080/checkout
NEXT_PUBLIC_PINTEREST_TAG_ID={TAG_ID}  # Slice 06: manuell setzen

# WordPress (wp_options, via WP-Admin)
spreadconnect_api_key={KEY}         # Slice 05
spreadconnect_use_staging=true      # Slice 05
pinterest_capi_access_token={TOKEN} # Slice 06
pinterest_capi_ad_account_id={ID}   # Slice 06
pinterest_capi_tag_id={TAG_ID}      # Slice 06
```

---

## Final Status

```
VERDICT: READY FOR ORCHESTRATION

Alle Gates bestanden:
- Gate 1 (Architecture): APPROVED
- Gate 2 (Slices): 7/7 APPROVED
- Gate 3 (Integration Map): READY FOR ORCHESTRATION

Keine blockierenden Probleme.
Implementierungsreihenfolge: 01 → 02 → 03 → (04 || 05) → (06 + 07)
```
