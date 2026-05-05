# Pre-Architecture Review

**Date:** 2026-05-03
**Reviewer:** discovery-wireframe-compliance (fresh-context, architecture-readiness pass)
**Scope:** Konsistenz / Vollständigkeit / Machbarkeit VOR Architecture-Schritt
**Inputs:** `discovery.md` (960 LOC), `wireframes.md` (1073 LOC), `checks/ux-expert-review.md`, `gates/compliance-discovery-wireframe.md`, bestehender Code in `wordpress/plugins/spreadconnect-pod/`, `CLAUDE.md`.

---

## Executive Verdict

**NEEDS_FIXES_FIRST**

Der Konzeptkern (Hub + Inline-Erweiterungen + Action-Scheduler-Queue + HPOS + HMAC + Failure-Recovery) ist senior-grade und intern weitgehend stimmig. Es gibt aber 4 echte Blocker, die der Architect nicht aus eigener Recherche schließen darf, weil sie inhaltliche Discovery-Entscheidungen sind: ein widersprüchliches Retry-Modell (HTTP-Layer vs. Action-Scheduler-Layer), ein nicht spezifiziertes Webhook-Signatur-Format (Header-Name + signed-bytes), eine unvollständige `_spreadconnect_state`-Enum-Persistenz vs. State-Machine, und drei API-Annahmen ohne Verifikation (`GET /articles?search`, `GET /stock` als Bulk-Endpoint, Default-Shipping-Type beim `POST /orders`). Sieben weitere Major-Findings sollten während der Architecture-Phase explizit aufgegriffen werden.

---

## Findings

### BLOCKER (must fix before architecture)

- **[B-1] Doppelt definiertes Retry-Modell — HTTP-Level ↔ Action-Scheduler-Level kollidiert**
  Discovery hat zwei Retry-Strategien, die nicht aufeinander abgestimmt sind:
  - Business Rules → API & Security (`discovery.md:557`): "**3x Retry mit Exponential-Backoff** (1s/2s/4s) für 5xx und Network-Errors".
  - Failure Handling (`discovery.md:599`): "**Auto-Retry** via Action Scheduler 3x mit 1min/5min/15min Backoff für transient Errors".
  - Flow G.1 (`discovery.md:196`): "Action Scheduler retried automatisch nach 1min/5min/15min (3x)".
  
  Konsequenz: Kombiniert hieße das 3 HTTP-Retries × 3 Action-Retries = bis zu 9 Versuche pro Operation, was für einen Customer-Order eine sehr lange Latenz und für ein 5xx-Outage einen Worker-Sturm bedeutet. Die existierende v1 (`includes/class-spreadconnect-api-client.php:53–135`) hat nur den HTTP-Layer (1s/2s/4s) implementiert.
  
  **Empfehlung:** Klarstellen: (a) Innerhalb eines Action-Scheduler-Jobs **kein** HTTP-Retry — der Job selbst ist die Retry-Einheit; nur 429-Header-Respect bleibt. ODER (b) HTTP-Retry für nur Network-Errors+429, Action-Scheduler-Retry für 5xx. Eindeutige Entscheidung in `discovery.md` notieren, sonst implementiert der Architect zwei nicht aufeinander abgestimmte Layer.

- **[B-2] HMAC-Webhook-Signatur — Header-Name und signed-bytes nicht spezifiziert**
  `discovery.md:552` definiert "HMAC-SHA256-Verifizierung … constant-time-compare (`hash_equals()`)" und `discovery.md:587` den Endpoint. Aber:
  - Welcher HTTP-Header trägt die Signatur (`X-Signature`? `X-Spreadconnect-Signature`? `X-Hub-Signature-256`?)
  - Was wird signiert (raw body? body+timestamp? URL+body?)
  - Format der Signatur (hex? base64? `sha256=…`-Präfix?)
  
  Wireframe Screen 4 (`wireframes.md:339–349`) zeigt nur "HMAC ✓/✗" als Resultat, keinen Header-Namen. Open Questions enthält das nicht (`discovery.md:906–917`).
  
  **Empfehlung:** Spreadconnect-Doku konsultieren oder als explizite Open Question für Architecture-Phase aufnehmen ("Validierung im Staging-Env via Simulate-Endpoint"). Ohne diese Info kann der Architect keine korrekte Verify-Funktion designen — und die existierende v1 `verify_webhook_signature` ist ein Stub mit `WP_DEBUG`-Bypass (`includes/class-spreadconnect-tracking-service.php:86–104`), also wirklich Greenfield.

- **[B-3] `_spreadconnect_state`-Enum vs. State-Machine — drei States haben keine Persistenz**
  Order State Machine (`discovery.md:482–493`) listet 8 States: `pending`, `submitting`, `NEW`, `CONFIRMED`, `PROCESSED`, `CANCELLED`, `failed_to_submit`, `needs_action`.
  Order-Meta-Persistenz (`discovery.md:682`): `_spreadconnect_state` enum **nur** `NEW/CONFIRMED/PROCESSED/CANCELLED`.
  
  Damit haben `submitting`, `failed_to_submit` und `needs_action` keinen definierten Storage. Discovery hat zwar `_spreadconnect_needs_action` als orthogonalen bool-Flag (`discovery.md:686`) und behandelt das somit; aber `submitting` (Order-Note + UI-Badge) und `failed_to_submit` (Failed-Ops + Resend-Pfad) haben keinen Persistenz-Anker. Wireframe-Screen 12 hat einen Filter `SC-State: …/FAILED/NEEDS-ACTION/…` (`wireframes.md:1031`) — der Filter hätte ohne separate Flags nichts zum Filtern.
  
  **Empfehlung:** Entweder Enum erweitern auf `submitting/NEW/CONFIRMED/PROCESSED/CANCELLED/failed_to_submit` und `_spreadconnect_needs_action` als Overlay-Flag stehenlassen, ODER explizit dokumentieren, dass `submitting` aus Action-Scheduler-Status abgeleitet ist und `failed_to_submit` aus `wp_spreadconnect_failed_ops`-Existence (= Filter-Logik wird Join statt Meta-Query). Architect muss das wissen, sonst dreht er es willkürlich aus.

- **[B-4] Drei nicht-verifizierte API-Annahmen, auf denen Wireframes/Flows aufbauen**
  Diese Annahmen sind in Discovery zwar erwähnt, aber nicht als Open Questions geflaggt — der Architect würde sie als gesetzt nehmen:
  
  1. **`GET /articles?search=…` Server-side-Suche** — Wireframe Screen 9 Article-Picker (`wireframes.md:855–856`) gibt selbst zu: "*if API supports server-side search; otherwise client-side filter on already-paginated list*". Das ist ein Open-Question im Wireframe, das in `discovery.md` Open Questions fehlt.
  2. **`GET /stock` als Bulk-Endpoint** — Flow F.1 (`discovery.md:184`) baut darauf auf: "ein einziger `GET /stock` (Bulk-Endpoint, gefiltert per `productTypeId` oder per query nach den gewünschten SKUs falls API das unterstützt; sonst Bulk-Pull + client-side filter)". Wieder: Annahme + Fallback, nirgends eine Verifikations-Aktion benannt.
  3. **`POST /orders` mit initialem Shipping-Type** — Default-Shipping-Type-Doku (`discovery.md:642`): "wird beim `create_order` als initialer Shipping-Type übergeben (**sofern API das zulässt**, sonst direkt nach Submit via `POST /shippingType`)". Auch das ist ein Architectural Branch-Point, der nicht aufgelöst ist.
  
  **Empfehlung:** Diese drei Punkte als Open Questions formal aufnehmen (analog zu Q#1–7 in `discovery.md:906–917`) und in der Architecture-Phase verbindlich klären (OpenAPI-Spec von rest.spod.com lesen, Staging-Test, oder explizit eine der beiden Implementierungs-Pfade festlegen). Sonst baut der Architect auf einer Annahme — und einer davon wird falsch sein.

### MAJOR (should fix during architecture; flag explicitly)

- **[M-1] Plugin-Activation-Flow widersprüchlich (Activation vs. Settings-Save)**
  `discovery.md:619–622`: "Bei Activation: … Bei API-Key-Save mit gültiger Connection: Auto-Subscriptions registrieren." 
  `discovery.md:130` (Flow A.5) sagt klar: Auto-Register erst nach Settings-Save.
  Aber `discovery.md:48` (Solution): "Auto-Webhook-Registrierung bei Plugin-Aktivierung".
  
  Die Solution-Beschreibung impliziert "bei Activation", die Detail-Flows sagen "bei Save". Bei Activation ist API-Key noch leer → Subscriptions können nicht registriert werden. Architect sollte explizit "bei Save mit valid connection" verfestigen.

- **[M-2] Custom-Tables ohne explizite Indexes (Performance-Risiko)**
  `discovery.md:691–732`: Drei Custom-Tables. Indizes nur erwähnt für `wp_spreadconnect_webhook_log.received_at` (`discovery.md:718`). Fehlend:
  - `wp_spreadconnect_webhook_log.event_id` (für Idempotency-Lookup pro Webhook-Empfang).
  - `wp_spreadconnect_webhook_log.related_entity_id` (für Wireframe Screen 11 ⑩ "last 5 webhook events for this order").
  - `wp_spreadconnect_failed_ops.state` + `op_type` (für Dashboard-Card-Counts und Filter-Listen).
  - `wp_spreadconnect_failed_ops.related_entity_id` (für Per-Order-Resolution-Lookup im `cancel_order_mirror`-Flow).
  
  Ohne Indexes wird der Hub-Dashboard-Aggregate-Query pro Page-Load die Tabelle full-scannen.

- **[M-3] Webhook-Idempotency: `eventId` ist Annahme, kein Vertrag**
  `discovery.md:590`: "Idempotency: Webhook-Eindeutigkeit per `eventId` (**falls von SC geliefert**) oder Hash(eventType+entityId+timestamp). Duplikate werden geloggt + ignoriert."
  
  Das "falls" ist ein Open-Question-Marker. Wenn SC keine `eventId` liefert, ist der Hash-Fallback brüchig: Hash(eventType+entityId+timestamp) ist nicht reproduzierbar bei Retransmission, weil SC denselben Event mit zweitem Timestamp resenden kann (oder kann nicht — wir wissen es nicht).
  
  **Empfehlung:** Architect soll Discovery's Open-Question-Liste um diesen Punkt erweitern und im Staging verifizieren. Ohne `eventId` ist die Idempotency-Strategie nicht haltbar.

- **[M-4] State-Transition-Tabelle ist unvollständig — Race-Conditions nicht abgedeckt**
  `discovery.md:526–545` listet ~14 Transitions. Lücken:
  - Was wenn Webhook `Order.processed` während `_spreadconnect_state` noch `submitting` ist (Race: SC hat Order erstellt + sofort processed, aber unsere `POST /orders`-Response noch im Flight)?
  - Was wenn `Order.cancelled`-Webhook empfangen wird während gleichzeitig `cancel_order_mirror`-Job läuft (doppeltes `_spreadconnect_state`-Update)?
  - Was wenn `Article.updated`-Webhook für einen Article ankommt, der gerade in `sync_article` re-synct wird (locking? last-write-wins?)?
  - Was wenn `processing`-Hook zweimal feuert (Mollie-Webhook + admin manuell), bevor `_spreadconnect_order_id` geschrieben ist?
  
  Discovery hat Auto-Confirm-vs-WC-Cancel-Race (`discovery.md:583`) elegant über `as_unschedule_action` gelöst — aber das war F-12 aus dem UX-Review. Die anderen Races sind unbearbeitet.
  
  **Empfehlung:** Architecture-Doc soll je Race einen "Atomic-Update + Lock" oder "Last-Write-Wins"-Beschluss festschreiben.

- **[M-5] `details` JSON-Spalte in `wp_spreadconnect_sync_history` ohne Schema**
  `discovery.md:732`: `details` Column ist `longtext (JSON) | per-article-summary`. Wireframe Screen 2 ⑧ erwartet "Per-article details" mit `Article ID / Title / Status / Notes`-Spalten. Discovery sagt nicht, ob dieser JSON ein Array von `{article_id, title, status, notes}`-Objekten ist oder ein Map.
  
  **Empfehlung:** Schema explizieren — sonst muss der Architect raten und Implementer und QA dürfen eine Runde mehr drehen.

- **[M-6] `media_sideload_image()` aus Cron-Context — Includes fehlen**
  `discovery.md:900` (Research-Log): "`media_sideload_image()` requires `wp-admin/includes/media.php`, `image.php`, `file.php` outside Admin." 
  
  Action-Scheduler-Worker laufen außerhalb des Admin-Contexts (CRON-Request). Discovery weist nicht aus, dass `sync_article`-Action diese Includes laden muss. v1 hat diese Codepath nicht — v2 muss es. Architect sollte explizit aufnehmen, sonst bricht's bei jedem Image-Sideload silent.

- **[M-7] Wireframe behauptet API-Daten ohne Discovery-Anker (Shipping-Type-Preise)**
  Wireframe Screen 11 ⑤ (`wireframes.md:943–945`):
  ```
  STANDARD  (4.99 € / 5–7d)
  PREMIUM   (7.99 € / 3–4d)
  EXPRESS   (12.99 €/ 1–2d)
  ```
  `GET /orders/{id}/shippingTypes` ist im Trigger Inventory (`discovery.md:755`), aber die Response-Shape ist nirgends spezifiziert. Wireframe nimmt an, dass Preise + Lieferzeit in der Response stehen — das mag stimmen oder nicht. Wenn nicht, hat der Wireframe eine Affordance, die nicht implementierbar ist.
  
  **Empfehlung:** API-Spec konsultieren und entweder Wireframe entschärfen oder Discovery um das Shape-Detail ergänzen.

- **[M-8] "Webhook Activity (last 5)" auf Order-Edit — fehlender Index ist nur Symptom; Discovery erlaubt das Feature nicht explizit**
  Wireframe Screen 11 ⑩ (`wireframes.md:958–961`): "Webhook Activity (last 5) … letzten 5 Events für diese Order". Discovery hat dieses Feature in der UI Layout-Beschreibung (`discovery.md:411`) aber nicht in einem Trigger oder Datenmodell-Eintrag. Die Query auf `wp_spreadconnect_webhook_log WHERE related_entity_id = ?` wäre notwendig — siehe M-2.
  
  Außerdem: Webhooks haben `orderReference` als SC-OrderID, nicht als WC-Order-ID. Mapping-Logik wird vom Wireframe vorausgesetzt, aber Discovery sagt nicht, wo dieses Mapping lebt (Meta-Lookup pro Render? Cache?).

### MINOR (informational; can be deferred)

- **[m-1] Solution-Text "Periodisch (4x/Tag)" widerspricht Settings-Range** — `discovery.md:17` und Q&A 22 (`discovery.md:957`) sagen "4x/Tag", Settings-Option erlaubt aber `1h/4h/6h/12h/24h` (`discovery.md:645`), wovon nur `6h` zu "4x/Tag" passt. Keine Inkonsistenz im Verhalten, nur Wortlaut. Editierbar in Discovery.

- **[m-2] Failed-Op-State `resending` ist Persistenz-frei** — State Machine (`discovery.md:520`) nennt `resending`; Custom-Table (`discovery.md:704`) hat enum `unresolved/resolved/dismissed`. Akzeptabel weil `resending` nur UI-State (Spinner) ist, aber wenn ein Worker mid-resend crasht, bleibt der Eintrag in `unresolved` — Doku-Klärung wert.

- **[m-3] Stock-Cache-TTL festverdrahtet im Wireframe** — Screen 9 ⑤ zeigt "(cached 5min)". Setting (`discovery.md:647`) ist konfigurierbar 60..900s. Wireframe sollte den Setting-Wert spiegeln, nicht eine Konstante. Presentation-only.

- **[m-4] API-Key-Last-4-Anzeige in Hub-Header** — Wireframe Screen 1 zeigt "API-Key: ••••••8f2a". Discovery erwähnt keine "letzte-4-Stellen-Anzeige" (`discovery.md:637`). Kleine Spec-Lücke (Threat-Model: ist OK, aber sollte sein dürfen explizit).

- **[m-5] Settings Export/Import-JSON-Format unspezifiziert** — Footer (`discovery.md:344`, Wireframe Screen 7 ⑨) erlaubt Export/Import. Was wird exportiert? HMAC-Secret? API-Key? Sicherheits-Konsequenz fehlt. Architect oder Slice-Planner soll Format spezifizieren (whitelist nicht-sensitiver Felder).

- **[m-6] Mark-as-resolved-Flag-Persistenz** — Wireframe Screen 11 banner ① "Mark Resolved" für `needs_action`. Discovery hat `_spreadconnect_needs_action` bool, aber sagt nicht, dass "Mark Resolved" diesen Flag auf false setzt + Banner-Rendering davon abhängt. Implementierungs-Detail, leicht zu klären.

- **[m-7] Composer-Autoload-Pattern** — `composer.json:13` nutzt `classmap`-Autoload, Discovery sagt "PSR-4 namespace `SpreadconnectPod\` autoloaded" (`CLAUDE.md` und `discovery.md:849`). Greenfield-Migration wird das umstellen — Slice 1 sollte das explizit benennen (`autoload.psr-4: { "SpreadconnectPod\\": "includes/" }`).

- **[m-8] Catalog-Page Sync-Settings dupliziert globale Settings** — Wireframe Screen 2 ② zeigt "Pull Images, Stock Threshold, Force re-pull images". Globalsettings (Screen 7 ⑦) hat dieselben drei. Wireframe-Annotation sagt: Force-re-pull ist "one-shot per run" — aber Pull Images / Threshold? Override per-run oder Edit-Default? Akzeptabel als Implementer-Klärung.

- **[m-9] Bestehender Code: `update_post_meta` für Order-Meta** — `class-spreadconnect-tracking-service.php:153` und `class-spreadconnect-order-service.php:69` nutzen `update_post_meta`. v1 ist HPOS-incompat. Greenfield-Lösch-Plan löst das, aber: bei Greenfield sollte die Discovery explizit "v1 wird *vor* v2-Bootstrap entfernt; falls vorhanden migration: keine" sagen — vorhanden in `discovery.md:71` und Out-of-Scope in `discovery.md:64`. OK, kein Nachbessern.

---

## Section Breakdown

### 1. Interne Konsistenz Discovery

- B-1 (Doppel-Retry-Layer), B-3 (State-Enum unvollständig), M-1 (Activation vs. Save), m-1 (Periodic-Wording), m-2 (Failed-Op `resending`), m-7 (Autoload-Style).
- Ansonsten: durchweg konsistent. UI-Components-Tabelle ↔ Wireframe-Coverage-Tabelle ↔ State-Machine sind sehr sauber abgestimmt; Trigger-Inventory deckt 100 % der Hooks ab; Settings-Optionen ↔ Settings-UI matchen 1:1.

### 2. Discovery ↔ Wireframes

- Gate 0 hat Coverage und UX-Review-Findings sauber abgehakt.
- **Verbleibende Semantik-Probleme:** M-7 (Shipping-Type-Preise), M-8 (Webhook-Activity-Mapping), M-2 (Indexes für Wireframe-Queries), m-3 (Stock-TTL in UI), m-4 (API-Key-Anzeige), m-6 (Mark-Resolved-Persistenz).
- Wireframe Screen 4 zeigt Action-Spalte mit "Retry" für Webhook-Processing-Errors — Discovery definiert kein "manuelles Webhook-Retry" als Trigger. Die Spalte ist either ein Verweis auf den Failed-Ops-Resend (cross-link) oder ein neues Feature. Sollte geklärt werden, aber nicht Blocker — Wireframe ist klein-genug Detail.

### 3. Vollständigkeit / Architecture-Readiness

| Aspekt | Status |
|--------|--------|
| API-Endpoint-Liste | ✓ alle 27 in Trigger Inventory benannt; **Request/Response-Shapes fehlen** für Architect-Use (kann aus OpenAPI gezogen werden, akzeptabel) |
| Webhook-Events | ✓ alle 7 benannt; **Payload-Shapes fehlen**; Idempotency-Key spekulativ (M-3) |
| Datenmodell | ✓ Tabellen + Spalten + Typen; **Indexes fehlen** (M-2); JSON-Spalten ohne Schema (M-5) |
| State-Transitions | ⚠ Tabelle existiert, deckt aber Race-Conditions nicht ab (M-4) |
| Error-Handling | ⚠ 9 Cases tabelliert, aber Worker-Crash, JSON-parse, double-hook nicht abgedeckt |
| Retry/Backoff | ❌ widersprüchlich (B-1) |
| Idempotency | ⚠ `eventId`-Annahme (M-3); Order-Idempotency via `_spreadconnect_order_id`-Skip ist OK |
| Observability | ✓ WC_Logger mit Sub-Sources |
| Security HMAC | ❌ Header + Signed-Bytes unspezifiziert (B-2) |
| Security Secret-Rotation | ✓ |
| Security API-Key-Storage | ✓ (Open Q #7 explizit) |
| i18n | ✓ |
| Performance | ⚠ Bulk-Stock OK; **Webhook-Log-Indexes fehlen** (M-2); media_sideload-Includes (M-6) |
| WP-Capabilities | ✓ |

### 4. Technische Machbarkeit

- **Stack-Fit:** WP/WC/Action-Scheduler/REST-API/HPOS — alles WP-native, keine Stack-Sprengung.
- **Greenfield vs. v1:** Bestehendes v1 (`spreadconnect-pod.php`, 4 Klassen) ist klein und HPOS-incompat (verwendet `update_post_meta` statt `$order->update_meta_data`). Discovery's Plan "v1 löschen, v2 neu bauen" ist konsistent. Kein Migrationsweg nötig.
- **Headless-Setup:** `wordpress/mu-plugins/headless-redirect.php` lässt `is_admin()`, `REST_REQUEST`, `/wp-login.php` durch. Damit ist sowohl `/wp-admin/...` (Hub-Page) als auch `/wp-json/spreadconnect/v1/webhook` durchgängig erreichbar. Kompatibel; explizit OK.
- **Externe API-Annahmen (3 Stück):** B-4 oben.
- **`media_sideload_image()` in Cron-Context:** M-6.
- **PSR-4-Autoload-Migration:** m-7.

### 5. Scope / Grauzonen

- **Bewusst Out-of-Scope und gut markiert:** Push-Sync, Bi-Sync, Design-Upload-UI, Catalog-Browser, WC-Multisite, konfigurierbare Attribut-Slugs, Kategorie-Markup-Regeln. (`discovery.md:53–66`)
- **Implizit verschoben (nicht ausgesprochen):**
  - Settings-Export/Import-Format (m-5).
  - CSV-Logs-Export-Format (Wireframe Screen 6 ② zeigt Download-Button; Discovery erwähnt es nicht in Business-Rules).
  - Webhook-Manual-Retry (Wireframe Screen 4 Action-Column).
- **Nur-UX-Aspekte (Architect ignorieren):** ETA-Berechnung in Sync-Progress, Skeleton-Rows-Layout, Color-Codes für Margin-Badges, Modal-Texte. Diese gehören in Implementation, nicht Architecture.

---

## Recommended Discovery Patches

Wenn der User vor Architecture noch Discovery-Korrekturen einbauen möchte:

1. **B-1 Retry-Layer disambiguieren** — neuer Abschnitt unter "API & Security", z. B.:
   > "Es gibt zwei Retry-Layer:
   > (a) **HTTP-Layer** (innerhalb eines Action-Scheduler-Jobs): kein Retry; nur HTTP 429 wird respektiert via `X-RateLimit-Retry-After-Seconds`.
   > (b) **Job-Layer** (Action Scheduler): bei Network-Error/5xx/Timeout 3× Retry mit 1min/5min/15min Backoff; bei 4xx (außer 429) sofort Failed-Op."
   *(Oder der inverse Beschluss — wichtig ist eine Wahl.)*

2. **B-2 HMAC-Spec ergänzen** — Open-Question-Block (`discovery.md:906`) erweitern um:
   > "Q-8: Welcher HTTP-Header trägt Spreadconnect's Webhook-HMAC-Signatur? Welche Bytes werden signiert? Welches Format (hex/base64/`sha256=…`)? — *Validierung im Staging-Env via Simulate-Endpoint*."

3. **B-3 `_spreadconnect_state` Enum erweitern** — Order-Meta-Tabelle (`discovery.md:682`) ändern auf:
   > `_spreadconnect_state` enum: `submitting/NEW/CONFIRMED/PROCESSED/CANCELLED/failed_to_submit`. (`needs_action` bleibt orthogonaler Flag via `_spreadconnect_needs_action`.)

4. **B-4 Drei API-Annahmen formal als Open Questions** — Open-Question-Block erweitern:
   > "Q-9: Unterstützt `GET /articles?search=...` server-side filter? — *OpenAPI-Spec prüfen*.
   > Q-10: Ist `GET /stock` ein Bulk-Endpoint mit Filter-Param oder Listing-Endpoint? — *OpenAPI-Spec prüfen*.
   > Q-11: Akzeptiert `POST /orders` einen `shippingType` im Initial-Request? — *OpenAPI-Spec prüfen, sonst Fallback-Pfad via `POST /shippingType`*."

5. **M-2 Indexes auflisten** — Custom-Tables-Section um Index-Spec ergänzen pro Tabelle (mindestens: `event_id`, `related_entity_id`, `state`, `op_type`, `received_at`).

6. **M-3 Idempotency-Strategy verfeinern** — wenn Q-3 (eventId) negativ, Hash-Schema explizieren oder Hardcoded "skip nicht-idempotenter Verarbeitung; verlasse uns auf Action-Scheduler Single-Worker-Claim".

7. **M-4 Race-Conditions adressieren** — neuer Mini-Block unter "Order Lifecycle":
   > "Race-Schutz: `submitting → NEW`-Übergang ist atomar (`update_post_meta` mit Compare-and-Set). Eingehende Webhooks während `submitting` werden in einer Pending-Queue gepuffert und nach State-Übergang verarbeitet."
   *(Oder ähnlicher Beschluss — wichtig: Beschluss treffen.)*

---

## Open Questions for User

1. Soll vor Architecture ein **Spike auf der OpenAPI-Spec** (`https://api.spod.com/docs`) durchgeführt werden, um B-2 (HMAC-Header) und B-4 (Search/Stock/Shipping-on-Submit) deterministisch aufzulösen? Oder akzeptierst Du die drei als Architecture-Phase-Open-Questions, die der Architect dann selbst klären muss?
2. Beim Doppel-Retry-Layer (B-1): Welche Variante präferiert der Betreiber operativ — HTTP-Layer-only (schnell + brittle bei Cron-Crashes), Job-Layer-only (langsamer + robuster), oder Hybrid mit klarer Entscheidung welcher Layer-Pfad welche Fehlerklasse trägt?
3. Soll der Architect die **State-Enum-Erweiterung** (B-3) inkrementell innerhalb der Architecture-Phase festziehen, oder willst Du den Discovery-Patch jetzt selbst machen, damit der Architect mit korrektem Datenmodell startet?
4. Settings-Export/Import (m-5): Soll im Export der HMAC-Secret und API-Key **inkludiert** sein (Komfort: vollständige Backup) oder **excludiert** (Sicherheit: nur nicht-sensitive Felder)?

---

## Verdict (Recap)

**Status:** NEEDS_FIXES_FIRST

**Blocking Issues:** 4 (B-1, B-2, B-3, B-4)
**Major Issues:** 8 (M-1 … M-8)
**Minor Issues:** 9 (m-1 … m-9)

**Empfehlung an den User:** Patches B-1 bis B-4 (mind.) jetzt direkt in `discovery.md` einarbeiten — entweder selbst oder im Lightweight-Pass durch ein Discovery-Update. Danach Architecture freigeben. M-Findings können in der Architecture-Phase begleitet werden, sollten aber als Eingangs-Risiken im Architecture-Doc explizit gelistet sein (sonst gehen sie verloren). m-Findings sind Slice-/Implementation-Level und müssen die Architecture nicht halten.
