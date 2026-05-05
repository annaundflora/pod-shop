# Systemic Review Report

**Feature:** Spreadconnect POD Plugin v2 — Full API Coverage
**Branch:** 7-spreadconnect-pod-plugin-v2-full-api-coverage
**Datum:** 2026-05-04

---

## Summary

**Verdict:** FAILED

| Kriterium | Findings |
|-----------|----------|
| Duplicate Solution Paths | 0 |
| Abstraction Reuse | 2 |
| Schema Consistency | 0 |
| Dead Code / Unused Imports | 0 |
| Error Handling Divergence | 1 |
| Configuration Drift | 1 |
| Interface Inconsistency | 1 |
| Dependency Direction | 0 |
| Security Pattern Consistency | 1 |
| Performance Pattern Consistency | 1 |
| **Total** | **7** |

> Hinweis: `.decisions.md` existiert nicht im Worktree. Discovery + architecture.md + codebase-scan.md liefern den Konventions-Kontext fuer alle Findings.

---

## Findings

### SR-1: Inkonsistente Cap+Nonce-Reihenfolge ueber 9 AJAX-Handler

**Kriterium:** 3.9 Security Pattern Consistency
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Die neuen AJAX-Handler (alle in `Hub/Ajax/`) wenden ihre beiden Hard-Gates (`current_user_can('manage_woocommerce')` + `check_ajax_referer(...)`) in unterschiedlicher Reihenfolge an. Beide Reihenfolgen sind funktional sicher (beide Gates schliessen vor Business-Logik), aber es gibt im Plugin keine einheitliche Konvention. Die FailedOpsActions-Doku sagt sogar explizit "nonce first (cheap), then cap" und konfligiert damit mit der Mehrheit. Die divergierenden Doc-Kommentare ("AC-5 cap first" vs. "AC-5 nonce first") zeigen, dass die Intention pro Slice abweicht — ein Slice, der die "andere" Variante als Vorbild greift, kann unbemerkt einer dritten, falschen Variante folgen.

**Cap-first, then nonce (5 Files):**
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/ExportImportSettings.php:156`
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/ProductActions.php:412`
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/RegenerateSecret.php:116`
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/SimulateEvent.php:125`
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/TestConnection.php:114`

**Nonce-first, then cap (3 Files):**
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/FailedOpsActions.php:639` (Doc: "Gate-Reihenfolge per slice-38 AC-5: nonce first (cheap), then cap.")
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/OrderActions.php:435`
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/RepairSubscriptions.php:128`

**Nonce-only, cap delegated (1 File):**
- `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/SyncNow.php:122` (cap geprueft via `Hub\Controller::ensureCapability()`)

**Bestehendes Pattern:** `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/TestConnection.php:99-100` (Slice 12, der erste AJAX-Handler) etabliert: "1. cap -> 2. nonce".

**Empfehlung:**
Eine `Hub\Ajax\GateGuard`-Klasse mit `ensureCapAndNonce(string $cap, string $nonceAction, string $nonceField): void` extrahieren (oder die existierende `Hub\Controller::ensureCapability`-Methode um Nonce-Pruefung erweitern). Alle 9 Handler darauf umstellen. Damit ist die Reihenfolge plus die JSON-Error-Shape (`{code, message}`) zentral fixiert. Alternativ: in `architecture.md` (Z. 484 erweitern) die Reihenfolge verbindlich festschreiben (cap-first per slice-12 Mehrheits-Konvention) und alle abweichenden Handler angleichen.

---

### SR-2: Inkonsistente JSON-Error-Envelope-Shape ueber AJAX-Handler

**Kriterium:** 3.7 Interface Inconsistency
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Drei verschiedene Error-Envelope-Shapes werden parallel von AJAX-Handlern emittiert. JS-Clients, die handlerspezifisch geschrieben sind, kommen damit klar — aber sobald ein generischer Toast/Modal-Renderer (z.B. eine kuenftige `spreadconnect-admin.js`-Library) Fehlermeldungen abfangen will, muss er drei Shape-Varianten branchen.

**Shape A — `{code, message}`:**
- `Hub/Ajax/FailedOpsActions.php:204` (`'code' => 'not_found', 'message' => __(...)`)
- `Hub/Ajax/ExportImportSettings.php:160` (`'code' => 'forbidden'`)
- `Hub/Ajax/RepairSubscriptions.php:131` (`'code' => 'invalid_nonce'`)

**Shape B — `{ok: false, message}`:**
- `Hub/Ajax/TestConnection.php:117` (`'ok' => false, 'message' => __(...)`)
- `Hub/Ajax/RegenerateSecret.php:118` (`'ok' => false, 'message' => __(...)`)
- `Hub/Ajax/SimulateEvent.php:127` (`'ok' => false, 'message' => __(...)`)

**Shape C — `{message}` only:**
- `Hub/Ajax/OrderActions.php:438` (nur `'message'`)
- `Hub/Ajax/ProductActions.php:415` (nur `'message'` in `ensureCapAndNonce`)

**Bestehendes Pattern:** Das Repo hat keine vorausgehende AJAX-Convention (Pinterest-Plugin nutzt keine `wp_ajax_*`). Die erste Slice (12, TestConnection) etabliert Shape B; die zweite Welle (38, FailedOps) wechselt zu Shape A; die dritte (32, OrderActions) auf Shape C.

**Empfehlung:**
Eine `Hub\Ajax\Response`-Hilfsklasse mit `ok(array $data): void` und `error(string $code, string $message, int $status = 400): void` einfuehren, die Shape A (`{code, message}`) zementiert — `code` ist die maschinenlesbare Diagnose, `message` die UI-Translation. Alle 9 Handler darauf umstellen. JS-Client kann dann in einer einzigen `handleAjaxError(response)`-Funktion auf `response.data.code` switchen statt pro Handler eine Variante zu kennen. Architektur-Update in `architecture.md` einbauen.

---

### SR-3: Order-Meta-Key-Konstanten 6-fach dupliziert (`_spreadconnect_order_id`, `_spreadconnect_state`)

**Kriterium:** 3.2 Abstraction Reuse
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Der String `_spreadconnect_order_id` wird in 8 verschiedenen Klassen als private Konstante deklariert; `_spreadconnect_state` 3-fach (zusaetzlich existiert `OrderStateMachine::META_KEY` als public-Konstante, die als kanonische Quelle dienen koennte, aber nicht referenziert wird). Eine einzige Aenderung am Schluessel-Namen verlangt 8 Edits — typische Fehlerquelle, da der Code-Reviewer schnell uebersieht, dass eine Klasse den Key nicht ueber die zentrale Konstante zieht.

**Neuer Code (Duplikate):**
- `_spreadconnect_order_id` deklariert in:
  - `Inline/OrderListColumns.php:67`
  - `Inline/OrderMetaBox.php:71`
  - `Order/OrderConfirmJob.php:69`
  - `Order/OrderSubmitJob.php:72`
  - `Order/OrderHandler.php:66`
  - `Order/FetchTrackingJob.php:67`
  - `Order/OrderCancelJob.php:69`
  - `Order/OrderCancelMirrorJob.php:91`
- `_spreadconnect_state` deklariert in:
  - `Inline/OrderListColumns.php:66`
  - `Inline/OrderMetaBox.php:72`
  - `Order/OrderStateMachine.php:84` (`public const META_KEY` — die wahre SoT)
  - `Hub/View/Dashboard.php:284` (Inline-String-Literal, nicht mal als Const)
- `_spreadconnect_article_id` deklariert in `Inline/ProductMetaBox.php:71`, `Inline/ProductListColumns.php:55`, `Catalog/ProductMapper.php:55`, `Catalog/ArticleRemovedJob.php:69`

**Bestehendes Pattern:**
- `OrderStateMachine::META_KEY` (Z. 84) ist bereits `public const`, also als zentraler SoT vorgesehen — aber niemand referenziert sie ausserhalb der eigenen Klasse.
- Die `WcLoggerAdapter` + `Logging\Sources` (siehe `Logging/WcLoggerAdapter.php` Z. 38-58 + `Logging/Sources.php`) loesen dasselbe Muster fuer Log-Sources ueber **eine** Konstanten-Klasse, die alle anderen referenzieren — das ist die Vorlage fuer Meta-Keys.

**Empfehlung:**
Eine `class \SpreadconnectPod\MetaKeys` einfuehren mit `public const ORDER_ID = '_spreadconnect_order_id'`, `STATE`, `ARTICLE_ID`, `COST`, `LAST_SYNC` etc. Alle Files, die heute lokale `META_*`-Konstanten haben, durch `MetaKeys::ORDER_ID` ersetzen. Spart bei jeder Schluessel-Aenderung 5-7 Edits und macht eine `_spreadconnect_*`-Migration in v2.1 zu einem Single-Point-Refactor.

---

### SR-4: `sc_stock_`-Transient-Prefix dreifach hardcodiert

**Kriterium:** 3.2 Abstraction Reuse
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Der Transient-Prefix `sc_stock_` ist in drei Files als lokale Konstante deklariert:
- `Stock/StockCache.php:42` (`private const TRANSIENT_PREFIX`)
- `Inline/ProductMetaBox.php:86` (`private const TRANSIENT_STOCK_PREFIX`)
- `Hub/Ajax/ProductActions.php:93` (`public const TRANSIENT_STOCK_PREFIX` — explizit als "shared SoT" markiert)

Zusaetzlich existiert der Prefix `sc_stock_refresh_` doppelt (`Inline/ProductMetaBox.php:87` als private + `Hub/Ajax/ProductActions.php:94` als public). Alle drei Files referenzieren in ihren Doc-Kommentaren explizit "muss identisch zu …" — eine textuelle SoT-Annotation, die der Compiler nicht enforced.

**Neuer Code:**
- `Stock/StockCache.php:42`
- `Inline/ProductMetaBox.php:86-87`
- `Hub/Ajax/ProductActions.php:93-94`

**Bestehendes Pattern:** `Hub/Ajax/ProductActions.php:93-94` deklariert die beiden Prefixes bereits als `public const` — es gibt also schon eine SoT, sie wird aber nicht wiederverwendet.

**Empfehlung:**
`Stock\StockCache::TRANSIENT_PREFIX` und `Stock\StockCache::TRANSIENT_REFRESH_PREFIX` zu `public const` machen (StockCache ist die kanonische Cache-Schicht, slice-36-owned, architecture.md Z. 350). `ProductMetaBox` und `ProductActions` darauf verweisen statt eigener Konstanten. Eliminiert die "muss identisch sein"-Doc-Kommentare und macht eine TTL-/Prefix-Aenderung zu einem Single-Edit.

---

### SR-5: Inkonsistenter Transient-Namespace (`sc_*` vs `spreadconnect_*`)

**Kriterium:** 3.6 Configuration Drift
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Die etablierten Codebase-Konventionen sind:
- Options: `spreadconnect_*` (codebase-scan.md Z. 139, 18 Keys)
- Post-/Order-Meta: `_spreadconnect_*` (codebase-scan.md Z. 140, 4 Keys)
- WP_Error-Codes: `spreadconnect_*` (codebase-scan.md Z. 138, 4 Codes)

Fuer Transients existiert noch keine Konvention im Altcode (Transient-Pattern war NEW, codebase-scan.md Z. 107-108). Die neue Implementation faehrt **zwei parallele** Namespaces:
- `sc_*` (Kurzform): `sc_stock_`, `sc_sync_total_`, `sc_sync_log_tail_`, `sc_stock_refresh_`, `sc_pt_`, `sc_subscriptions_status`, `sc_health` — 7 Keys
- `spreadconnect_*` (Langform): `spreadconnect_initial_secret_reveal` — 1 Key

Beide funktionieren — aber das ist die einzige Praefix-Inkonsistenz im gesamten v2-Plugin. Ein Operator, der mit `wp transient delete --all-meta='spreadconnect_*'` aufraeumen will, vergisst die `sc_*`-Variante und umgekehrt.

**Neuer Code:**
- `sc_*`-Namespace: `Stock/StockCache.php:42`, `Catalog/SyncHistoryRepo.php:70,80`, `Inline/ProductMetaBox.php:86-87`, `Hub/View/Dashboard.php:169`, `Subscription/SubscriptionManager.php:716`, `Catalog/SyncArticleJob.php:375,392` (`sc_pt_` literal in body)
- `spreadconnect_*`-Namespace: `Hub/Ajax/RegenerateSecret.php:72`, `Hub/View/Settings.php:822`

**Bestehendes Pattern:** Plugin-eigene Konvention fuer alle anderen Schluessel (Options, Meta, Error-Codes) ist `spreadconnect_*`. Ohne kuerzere Begruendung waere die Konsistenz besser.

**Empfehlung:**
Entweder
(a) **Alle** Transients auf `spreadconnect_*` migrieren — entspricht der Plugin-Convention. Wegen 5min-TTL ist die Migration auch ohne Sweep-Code (innerhalb 5min selbst-heilend); ein einmaliger `delete_transient('sc_*')`-Call im Activation-Hook waere sauberer.
(b) **Alle** Transients auf `sc_*` migrieren — kuerzer, aber bricht mit der sonst durchgaengigen Plugin-Praefix-Konvention.
PM-Entscheidung notwendig. Falls (a): `architecture.md` Z. 350 anpassen (heute steht dort `sc_stock_{sku}`).

---

### SR-6: `findByEntity` ohne LIMIT — Pattern-Abweichung in FailedOpsRepo

**Kriterium:** 3.10 Performance Pattern Consistency
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
`FailedOpsRepo::findByEntity()` setzt **kein** `LIMIT` auf die Ergebnis-Menge:
```php
"SELECT * FROM {$this->table()} "
. "WHERE related_entity_type = %s AND related_entity_id = %s "
. 'ORDER BY created_at DESC, id DESC'
```
Alle anderen `findX*`-Methoden im selben Repo (Z. 260, 319, 429) sowie der Schwester-Repo `WebhookLogRepo::findByOrder()` (Z. 469-477) wenden konsequent `LIMIT %d` an. In der Praxis sammelt `findByEntity` typischerweise <100 Rows pro `(type, entity_id)`-Paar (eine WC-Order hat selten >5 fehlgeschlagene Operationen), daher kein akutes Risiko — aber das Pattern divergiert von der etablierten Repo-Norm und ein degenerierter Fall (z.B. ein Article-Sync der 1000x retried wird) wuerde unbeschnitten geladen.

**Neuer Code:** `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php:362-369` und `:371-380`.

**Bestehendes Pattern:**
- `FailedOpsRepo::findById` (Z. 260): `"... WHERE id = %d LIMIT 1"`
- `FailedOpsRepo::findFiltered` (Z. 319): `"... LIMIT %d OFFSET %d"`
- `WebhookLogRepo::findByOrder` (Z. 469-477): `"... ORDER BY received_at DESC LIMIT %d"`

**Empfehlung:**
`findByEntity` einen optionalen `int $limit = 100` Parameter geben und in den SQL als `LIMIT %d` rendern. Caller (heute nur `OrderEventHandler` + `WebhookEventDispatcher`) bleiben kompatibel, weil Default-Limit > praktischer Maximum.

---

### SR-7: WP_Error-Code ohne `spreadconnect_*`-Prefix in SyncProgress REST-Endpoint

**Kriterium:** 3.5 Error Handling Divergence
**PM-Entscheidung:** Fixen / Bewusst akzeptiert / Abgelehnt

**Problem:**
Im REST-Endpoint `Hub/Rest/SyncProgress.php` weicht der WP_Error-Code-Praefix vom etablierten Convention `spreadconnect_*` ab. Z. 184 emittiert:
```php
return new WP_Error(
    'sync_run_not_found',  // <-- ohne "spreadconnect_"-Prefix
    sprintf( __( 'No sync run found for run_id %d.', self::TEXT_DOMAIN ), $requested ),
    array( 'status' => 404 )
);
```

Alle anderen WP_Error-Codes im Plugin folgen `spreadconnect_*`-Convention (codebase-scan.md Z. 138 — 4-fach in Slice-Tests asserted):
- `WebhookController.php:108`: `REJECT_ERROR_CODE = 'spreadconnect_webhook_unauthorized'` ✓
- `ImageSideloader.php:116`: `'spreadconnect_invalid_sideload_args'` ✓
- `SyncProgress.php:184`: `'sync_run_not_found'` ✗ (Inkonsistenz)

Der Slice-26-Spec verlangt die exakte String-Form (`AC-9: WP_Error-Code MUSS exakt "sync_run_not_found" sein`) — die Spec ist also der Grund. Die Spec selbst ist gegenueber der Plugin-Convention divergent.

**Neuer Code:** `wordpress/plugins/spreadconnect-pod/includes/Hub/Rest/SyncProgress.php:184`

**Bestehendes Pattern:** codebase-scan.md Z. 138: "WP_Error codes prefixed `spreadconnect_*` — Count 4". Plus die zwei anderen v2-Verwendungen (`spreadconnect_webhook_unauthorized`, `spreadconnect_invalid_sideload_args`) ziehen mit.

**Empfehlung:**
Entweder Spec-Update fuer Slice 26 (`spreadconnect_sync_run_not_found`) und Code-Edit gemeinsam, ODER bewusst akzeptieren mit Decision-Log-Eintrag "REST-Error-Codes folgen nicht der Plugin-WP_Error-Convention". Praeferenz: angleichen, da die einzige Stelle ist und der JS-Client (`assets/js/...`) den Code-String bisher nicht hardcodiert (nur `status === 404` wird gecheckt).

---

## Decision Log Updates

| # | Neuer Eintrag | Date |
|---|---------------|------|
| {N} | (PM ergaenzt nach Review) | 2026-05-04 |

> Bei "Bewusst akzeptiert"-Entscheidungen sollte der PM einen `.decisions.md`-Eintrag im Worktree-Root anlegen (heute nicht vorhanden). Insbesondere fuer SR-2 (JSON-Envelope) und SR-5 (Transient-Namespace) ist die Decision orchestratorisch wichtig, weil zukuenftige Slice-Authors die Convention sehen muessen.
