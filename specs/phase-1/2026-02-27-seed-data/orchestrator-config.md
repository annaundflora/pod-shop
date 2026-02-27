# Orchestrator Configuration: Seed Data — 100+ POD-Produkte mit KI-generierten Bildern

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-27

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "specs/phase-1/2026-02-27-seed-data/architecture.md"
    required: "Exists and Approved"

  - name: "Gate 2: All Slices Approved"
    files: "specs/phase-1/2026-02-27-seed-data/slices/compliance-slice-*.md"
    required: "ALL Verdict == APPROVED"
    status:
      - "compliance-slice-01.md: APPROVED"
      - "compliance-slice-02.md: APPROVED"
      - "compliance-slice-03.md: APPROVED"
      - "compliance-slice-04.md: APPROVED"
      - "compliance-slice-05.md: APPROVED"

  - name: "Gate 3: Integration Map Valid"
    file: "specs/phase-1/2026-02-27-seed-data/integration-map.md"
    required: "Missing Inputs == 0 AND Deliverable-Consumer Gaps == 0"
    status: "READY FOR ORCHESTRATION"
```

---

## Implementation Order

### Wave 1 — Parallel (keine Dependencies)

Beide Slices haben `Dependencies: []` und koennen gleichzeitig implementiert werden.

| Order | Slice | Name | Depends On | Parallel? | Test Command |
|-------|-------|------|------------|-----------|--------------|
| 1 | Slice 01 | Produktkatalog-Definition | — | Ja, mit Slice 03 | `cd frontend && pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` |
| 1 | Slice 03 | Motiv-Definition pro Produkt | — | Ja, mit Slice 01 | `cd frontend && pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` |

**Wave 1 Gate:** Beide Slices fertig, bevor Wave 2 startet.

- [ ] `scripts/product-catalog.json` existiert und parst: `node -e "require('./scripts/product-catalog.json')"`
- [ ] `frontend/tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` existiert und alle Tests gruen
- [ ] `frontend/tests/slices/seed-data/slice-03-motiv-definition.test.ts` existiert und alle Tests gruen
- [ ] Alle 110 `motif`-Felder in `product-catalog.json` sind befuellt (nicht leerer String)

---

### Wave 2 — Parallel (beide brauchen Slice 01; Slice 04 braucht zusaetzlich Slice 03)

Slice 02 und Slice 04 koennen nach Wave 1 parallel laufen.

| Order | Slice | Name | Depends On | Parallel? | Test Command |
|-------|-------|------|------------|-----------|--------------|
| 2 | Slice 02 | Seed-Script Erweiterung | Slice 01 | Ja, mit Slice 04 | `cd frontend && pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` |
| 2 | Slice 04 | Bild-Generierung Script | Slice 01, Slice 03 | Ja, mit Slice 02 | `cd frontend && pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` |

**Wichtiger Hinweis zu Slice 04:**

> **Slice 04 (`generate-images.mjs`) ist ein developer-triggered one-time script — KEIN automatischer Build-Step.**
>
> Der Orchestrator implementiert das Script und fuehrt die Unit-Tests aus. Die tatsaechliche Bild-Generierung (Aufruf von `node scripts/generate-images.mjs`) erfordert:
> - `REPLICATE_API_TOKEN` in `.env` gesetzt
> - Manuelle Ausfuehrung durch den Developer
> - Anschliessend: `git add wordpress/uploads/products/ && git commit`
>
> Erst DANACH kann Slice 05 vollstaendig getestet werden (Integration-Test). Die Unit-Tests von Slice 05 laufen unabhaengig.

**Wave 2 Gate:** Beide Slices fertig, bevor Wave 3 startet.

- [ ] `scripts/seed-products.php` refactored: enthaelt `pod_create_simple_product()`, `pod_create_color_only_variable_product()`, JSON-Catalog-Loading
- [ ] `scripts/mock-data.sh` enthaelt `--force` Flag
- [ ] `docker-compose.yml` wpcli-Service enthaelt Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads`
- [ ] `scripts/generate-images.mjs` existiert und importiert: `node --input-type=module --eval "import('./scripts/generate-images.mjs').then(m => console.log('Module OK'))"`
- [ ] `.env.example` enthaelt `REPLICATE_API_TOKEN` Platzhalter
- [ ] Alle Unit-Tests fuer Slice 02 gruen
- [ ] Alle Unit-Tests fuer Slice 04 gruen

---

### Wave 3 — Sequentiell (braucht Slice 02 + Slice 04)

| Order | Slice | Name | Depends On | Parallel? | Test Command |
|-------|-------|------|------------|-----------|--------------|
| 3 | Slice 05 | Bild-Import im Seed | Slice 02, Slice 04 | Nein — finale Integration | `cd frontend && pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` |

**Wave 3 Gate:**

- [ ] `scripts/seed-products.php` enthaelt alle 3 Helper-Funktionen: `pod_create_attachment()`, `pod_import_product_images()`, `pod_import_category_image()`
- [ ] Alle Unit-Tests fuer Slice 05 gruen
- [ ] Integration-Test (Docker): `docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html` — kein Fehler

---

## Deliverables Checklist (vollstaendig)

```yaml
deliverables:
  slice_01:
    - path: "scripts/product-catalog.json"
      description: "110 Produkte, 15 Kategorien, befuellte motif-Felder"
      verify: "node -e \"require('./scripts/product-catalog.json')\" && jq '.products | length' scripts/product-catalog.json"
    - path: "frontend/tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts"
      description: "Vitest-Tests fuer JSON-Struktur und Produktverteilung"

  slice_02:
    - path: "scripts/seed-products.php"
      description: "Refactored: JSON-Loading, pod_create_simple_product, pod_create_color_only_variable_product, Reviews, Featured"
    - path: "scripts/mock-data.sh"
      description: "--force Flag hinzugefuegt"
    - path: "docker-compose.yml"
      description: "wpcli-Service: uploads Volume-Mount hinzugefuegt"
    - path: "frontend/tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts"
      description: "Vitest-Tests fuer PHP/Shell Datei-Struktur"

  slice_03:
    - path: "specs/phase-1/2026-02-27-seed-data/slices/slice-03-motiv-definition.md"
      description: "Spec-Dokument mit 110 Motiv-Beschreibungen (bereits vorhanden als Spec)"
    - path: "frontend/tests/slices/seed-data/slice-03-motiv-definition.test.ts"
      description: "Vitest-Tests fuer Motiv-Verteilung und Typ-Abdeckung"
    - note: "Motiv-Strings werden in scripts/product-catalog.json integriert (Deliverable von Slice 01)"

  slice_04:
    - path: "scripts/generate-images.mjs"
      description: "Node.js ESM Script fuer Replicate Flux 2 Pro Bild-Generierung"
    - path: ".env.example"
      description: "REPLICATE_API_TOKEN Placeholder hinzugefuegt"
    - path: "frontend/tests/slices/seed-data/slice-04-bild-generierung-script.test.ts"
      description: "Vitest Unit-Tests fuer buildPrompt, withRetry, CATEGORY_TEMPLATES"
    - note: "WebP-Bilder sind KEIN automatisches Deliverable — werden manuell generiert und committed"

  slice_05:
    - path: "scripts/seed-products.php"
      description: "Erweiterung um pod_create_attachment, pod_import_product_images, pod_import_category_image"
    - path: "frontend/tests/slices/seed-data/slice-05-bild-import-seed.test.ts"
      description: "Vitest-Tests fuer PHP-Helper-Funktionen via readFileSync"
```

---

## Post-Slice Validation

Fuer jeden abgeschlossenen Slice:

```yaml
validation_steps:
  - step: "Deliverables Check"
    action: "Verify all files in DELIVERABLES_START section exist"

  - step: "Unit Tests"
    action: "Run: cd frontend && pnpm test tests/slices/seed-data/slice-{NN}-*.test.ts"
    required: "All tests pass (0 failing)"

  - step: "Integration Command"
    action: "Run integration command from slice Test-Strategy"
    reference: "See each slice's 'Test-Strategy' table → Integration Command"

  - step: "Integration Points Check"
    action: "Verify outputs accessible by dependent slices"
    reference: "integration-map.md → Connections"
```

---

## Spezifische Ausfuehrungs-Anleitung fuer /orchestrate

### Schritt 1: Wave 1 starten (Slice 01 + Slice 03 parallel)

```bash
# Slice 01: Produktkatalog-Definition implementieren
# Deliverable: scripts/product-catalog.json (vollstaendig mit 110 Produkten + befuellten Motiv-Feldern)
# Test:
cd frontend && pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts

# Slice 03: Motiv-Definition implementieren
# Deliverable: frontend/tests/slices/seed-data/slice-03-motiv-definition.test.ts
# Test:
cd frontend && pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts
```

**Wave 1 Smoke Test:**
```bash
node -e "require('./scripts/product-catalog.json') && console.log('JSON OK')"
jq '.products | length' scripts/product-catalog.json  # Erwartet: 110
jq '[.products[] | select(.motif == "")] | length' scripts/product-catalog.json  # Erwartet: 0
```

### Schritt 2: Wave 2 starten (Slice 02 + Slice 04 parallel)

```bash
# Slice 02: Seed-Script Erweiterung implementieren
# Deliverables: scripts/seed-products.php (refactored), scripts/mock-data.sh, docker-compose.yml
# Test:
cd frontend && pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts

# Slice 04: Bild-Generierung Script implementieren
# Deliverables: scripts/generate-images.mjs, .env.example, tests/slices/seed-data/slice-04-bild-generierung-script.test.ts
# Test:
cd frontend && pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts
```

**Wave 2 Smoke Tests:**
```bash
# Slice 02:
grep -q "pod_create_simple_product" scripts/seed-products.php && echo "OK: pod_create_simple_product"
grep -q "pod_create_color_only_variable_product" scripts/seed-products.php && echo "OK: pod_create_color_only_variable_product"
grep -q "./wordpress/uploads:/var/www/html/wp-content/uploads" docker-compose.yml && echo "OK: volume mount"

# Slice 04:
node --input-type=module --eval "import('./scripts/generate-images.mjs').then(m => console.log('Module OK'))"
grep -q "REPLICATE_API_TOKEN" .env.example && echo "OK: env example"
```

**Manuelle Developer-Action fuer Slice 04 (NACH Unit-Tests, VOR Wave 3):**
```bash
# Developer fuehrt einmalig aus (erfordert REPLICATE_API_TOKEN in .env):
node scripts/generate-images.mjs

# Developer committet generierte Bilder:
git add wordpress/uploads/products/
git commit -m "feat: add generated product images (232 WebP files via Replicate Flux 2 Pro)"
```

### Schritt 3: Wave 3 starten (Slice 05 — nach Wave 2 abgeschlossen)

```bash
# Slice 05: Bild-Import im Seed implementieren
# Deliverable: scripts/seed-products.php (erweitert um 3 Helper-Funktionen)
# Test (Unit):
cd frontend && pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts

# Test (Integration, erfordert Docker):
docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html
```

**Wave 3 Smoke Test (nach Integration-Test):**
```bash
# GraphQL-Abfrage: min. 1 Produkt mit sourceUrl nicht null
curl -X POST http://localhost:8080/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ products { nodes { image { sourceUrl } } } }"}' \
  | jq '[.data.products.nodes[] | select(.image.sourceUrl != null)] | length'
# Erwartet: 110 (wenn Bilder vorhanden) oder 0 (wenn keine Bilder generiert wurden — Graceful Degradation)
```

---

## E2E Validation

Nach allen Slices abgeschlossen:

```yaml
e2e_validation:
  - step: "Execute e2e-checklist.md"
    action: "Alle Checkboxen in e2e-checklist.md abarbeiten"
    file: "specs/phase-1/2026-02-27-seed-data/e2e-checklist.md"

  - step: "All Unit Tests"
    action: "Alle 5 Test-Suites laufen gruen"
    commands:
      - "cd frontend && pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts"
      - "cd frontend && pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts"
      - "cd frontend && pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts"
      - "cd frontend && pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts"
      - "cd frontend && pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts"

  - step: "FOR each failing check"
    actions:
      - "Identify responsible slice from Integration Map → Connections table"
      - "Create fix task with slice reference"
      - "Re-run affected slice tests"

  - step: "Final Approval"
    condition: "ALL checks in e2e-checklist.md PASS"
    output: "Feature READY: Shop hat 110 Produkte mit KI-generierten Bildern, Kategorien, Featured-Section, Reviews"
```

---

## Rollback Strategy

```yaml
rollback:
  - condition: "Slice 01 oder Slice 03 schlagen fehl"
    action: "Keine DB-Aenderungen — reine Datei-Deliverables. Dateien loeschen und neu implementieren."
    note: "Keine Dependencies auf vorherige Slices in Wave 1"

  - condition: "Slice 02 schlaegt fehl"
    action: "Git-Revert auf scripts/seed-products.php, scripts/mock-data.sh, docker-compose.yml"
    note: "Slice 05 kann nicht fortgesetzt werden ohne funktionales Slice 02"

  - condition: "Slice 04 schlaegt fehl (Script-Implementierung)"
    action: "Git-Revert auf scripts/generate-images.mjs"
    note: "WebP-Bilder werden noch nicht generiert — kein Filesystem-Schaden"

  - condition: "Slice 04 schlaegt fehl (Bild-Generierung waehrend manueller Ausfuehrung)"
    action: "Fehlgeschlagene Bilder identifizieren (Summary-Output), einzeln re-generieren oder Slice 05 mit Graceful Degradation fortsetzen"
    note: "Script ist idempotent — kann jederzeit erneut ausgefuehrt werden"

  - condition: "Slice 05 schlaegt fehl"
    action: "Git-Revert der Helper-Funktionen in scripts/seed-products.php"
    note: "Basis-Produkte aus Slice 02 bleiben unveraendert in DB"

  - condition: "Integration-Test (Docker-Seed) schlaegt fehl"
    action: "docker compose down -v && docker compose up -d (komplettes DB-Reset)"
    note: "Seed ist idempotent — nach Reset neu ausfuehren"
```

---

## Monitoring

Waehrend Implementierung:

| Metric | Alert Threshold | Action |
|--------|-----------------|--------|
| Unit Test failures | > 0 blocking | Stop, fix, re-run |
| `product-catalog.json` Produktanzahl != 110 | Any deviation | Slice 01 korrigieren |
| `motif`-Felder leer in product-catalog.json | > 0 leer | Slice 03 Integration in Slice 01 pruefen |
| Missing deliverable file | Any | Slice neu implementieren |
| Docker Integration Test Exit-Code != 0 | Any | PHP-Log pruefen, Slice 02/05 debuggen |
| generate-images.mjs "failed" Counter | > 10% der Bilder | Replicate API-Status pruefen, Prompts anpassen |

---

## Hinweise fuer den Orchestrator

### Test-Command Praefix

Alle Vitest-Tests werden im `frontend/`-Verzeichnis ausgefuehrt:

```bash
cd frontend && pnpm test tests/slices/seed-data/<test-file>.test.ts
```

Die Compliance Reports fuer Slice 01 vermerken, dass der Test-Command in den Slice-Metadaten das `cd frontend &&` Praefix fehlt — dies ist bekannt und nicht blockierend. Der Orchestrator verwendet stets das `cd frontend &&` Praefix.

### Slice 04 ist kein Build-Step

`scripts/generate-images.mjs` ist ein **einmaliges, manuell ausgefuehrtes Developer-Tool** — es ist kein Teil der automatischen `docker compose up -d` Pipeline. Der Orchestrator implementiert das Script und fuehrt die Unit-Tests aus. Die tatsaechliche Bild-Generierung erfordert manuelle Ausfuehrung durch den Developer mit einem gueltigen `REPLICATE_API_TOKEN`.

### Slice 03 und Slice 01 sind eng gekoppelt

Slice 03 definiert die Motiv-Strings im Spec-Dokument; Slice 01 integriert diese in `product-catalog.json`. Da beide `Dependencies: []` haben, koennen sie parallel implementiert werden — aber die `motif`-Felder in `product-catalog.json` muessen am Ende von Wave 1 vollstaendig befuellt sein (nicht leere Strings), bevor Slice 04 ausgefuehrt wird.

### Datei-Konflikt Slice 02 und Slice 05

Beide Slices modifizieren `scripts/seed-products.php`. Slice 05 ist additiv (fuegt Helper-Funktionen hinzu). Die Implementierungsreihenfolge stellt sicher, dass Slice 02 zuerst fertig ist und Slice 05 die bestehende Datei um die Helper-Funktionen erweitert.
