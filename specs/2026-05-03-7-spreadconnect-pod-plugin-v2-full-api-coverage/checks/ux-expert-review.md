<!-- AGENT_DEF_LOADED: ux-expert-review-v1 -->

# UX Expert Review — Spreadconnect POD Plugin v2

**Spec:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/`
**Reviewer:** Senior UX Expert
**Date:** 2026-05-03
**Inputs:** `discovery.md`, `wireframes.md`
**Surface scope:** WP-Admin only (8 Hub sub-pages + 4 inline extensions). No public frontend.
**Audience:** WC shop admin operating a POD fulfillment integration.

---

## Summary

**Verdict:** CHANGES_REQUESTED

A solid, comprehensive concept that respects WP-Admin and WC-Admin conventions. The Hub-with-sidebar pattern, Action-Scheduler-backed background jobs, dual HPOS+legacy hooks, dead-letter recovery flow, and HMAC-secured webhook receiver are all senior-grade choices. Findings cluster around (a) gaps between Discovery flows and the wireframed UI states, and (b) operational dead-ends/silent failures the admin cannot recover from.

### Findings Table

| ID  | Title                                                              | Severity     | Category        |
|-----|--------------------------------------------------------------------|--------------|-----------------|
| F-1 | First-time HMAC secret disclosure UI is missing                   | Critical     | Workflow        |
| F-2 | Long-running Catalog Sync cannot be cancelled                     | Critical     | Workflow        |
| F-3 | Auto-Confirm conflicts with Shipping-Type pre-check (silent fail) | Critical     | Inconsistency   |
| F-4 | Bulk Re-send "silently skips" non-FAILED rows                     | Critical     | Usability       |
| F-5 | Dismiss on a `create_order` failure orphans the WC order          | Critical     | Workflow        |
| F-6 | Manual Article-ID linking is a bare input with no guidance        | Improvement  | Usability       |
| F-7 | "Test Connection" — relationship to unsaved API-Key is ambiguous  | Improvement  | Usability       |
| F-8 | Dashboard "Failed Operations" card hides operation type           | Improvement  | Usability       |
| F-9 | No proactive detection when Webhook URL changes (site migration)  | Improvement  | Gap             |
| F-10| Color-coded badges lack text labels / SR-friendly affordance      | Improvement  | Usability/A11y  |
| F-11| Settings "Save" performs ≥3 side-effects with no per-step result  | Improvement  | Usability       |
| F-12| `Order.cancelled` race during Auto-Confirm timer window           | Improvement  | Gap             |
| F-13| Notification fatigue: email + admin-notice + order-note per fail  | Suggestion   | Usability       |
| F-14| Stock burst on Product-Edit page (per-variation parallel calls)   | Suggestion   | Scalability     |
| F-15| Wireframe German/English mix doesn't model i18n source strings    | Suggestion   | Inconsistency   |

**Totals:** Critical: 5 · Improvement: 7 · Suggestion: 3

---

## Workflow Analysis

### State machine reachability

I walked the full Connection / Subscription / Order / Article / Sync / Failed-Op state machines from Discovery and cross-referenced wireframes:

| Machine       | Reachability | Exit-ability | Comment |
|---------------|--------------|--------------|---------|
| Connection    | OK           | OK           | All 5 states have UI representation in Hub-Header + Settings. |
| Subscription  | OK           | OK           | `unknown` → `active`/`missing`/`orphan` flows clear; `Repair All` reconciles. |
| Order         | OK           | **Partial**  | `failed_to_submit` exits via Resend; **but** Dismiss exits to "void" (see F-5). `submitting` has no manual escape if stuck. |
| Article       | OK           | OK           | `removed_in_sc` is well-handled via banner + draft. |
| Sync-Job      | **Broken**   | **Broken**   | Discovery promises Cancel for `pending`; wireframe never renders it. `in-progress` has no abort path (see F-2). |
| Failed-Op     | OK           | OK           | Resend / Dismiss both exit. Concern is what Dismiss *means* in domain terms (F-5). |

### Cross-surface navigation (positive)

The wireframes cross-link sensibly: Dashboard cards → sub-sections, Failed-Ops detail → WC-Order, Webhook-Log entity → Order/Article, Order-Edit → Failed-Ops Detail. Few admin plugins do this well; this one does.

---

## Findings

### Finding F-1: First-time HMAC secret disclosure UI is missing

**Severity:** Critical
**Category:** Workflow / Gap

**Problem:**
Discovery Flow A step 4 promises that on initial Settings save the HMAC secret is generated and **shown once** to the admin (with the warning to copy it). The wireframed Settings screen (Screen 7) only depicts the *steady-state*: a masked secret with `[Regenerate Secret]`. There is no wireframe of the one-shot reveal moment. State variation `regenerate_success` covers the *Regenerate* path, but **not the initial-save path**. An admin doing first-time setup will save → secret silently generates → never seen → has to immediately Regenerate to get a copy, which is wasteful and confusing.

**Context:**
> **From Discovery (Flow A.4):**
> ```
> 4. System: HMAC-Secret wird generiert + einmalig angezeigt (Hinweis: Speichern, später nicht mehr sichtbar)
> ```
>
> **From Wireframe (Screen 7, "Webhook Security"):**
> ```
> HMAC Secret:    ••••••••••••••••••••  ④ [Regenerate Secret]
>                 (Last regenerated: 2026-04-12 09:14)
> ```
> State variations only define `regenerate_success` (post-button), not `initial_setup_secret_reveal`.

**Impact:**
- First-time admins miss their only chance to copy the secret.
- The SC-side webhook subscription configuration *requires* this secret — the entire integration cannot complete without it.
- Forces a Regenerate cycle on day 1, which (by the system's own rules) invalidates any subscriptions just created.

**Recommendation:**
Add a state variation `initial_secret_reveal` to Screen 7 (mirror of `regenerate_success`): on first successful Save, show an inline panel above the Webhook Security section with the new secret in monospace, a `[Copy]` button, and a `[Done — I have copied it]` acknowledgement that locks the reveal. Only mark the secret as "shown" once the admin acknowledges.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-2: Long-running Catalog Sync cannot be cancelled

**Severity:** Critical
**Category:** Workflow

**Problem:**
A Catalog Sync can run several minutes (the wireframe shows ETA ~2:14 with 142 articles). The Discovery state machine explicitly defines a `Cancel` action for the `pending` state of a Sync-Job. The wireframe never renders a Cancel control — neither for `pending` (state variation says "① disabled") nor for `in-progress`. An admin who hits Sync by mistake, or notices an issue mid-run (wrong threshold, staging vs production confusion, runaway error rate), has no escape hatch other than waiting the full run out.

**Context:**
> **From Discovery (Sync-Job State):**
> ```
> | pending     | Catalog-Page: "Queued"           | Cancel (entfernt aus Queue) |
> | in-progress | Catalog-Page: Live-Progress-Bar  | --                          |
> ```
>
> **From Wireframe (Screen 2, state `pending`):**
> ```
> Panel ③ visible: "Queued — waiting for worker"; ④ replaced with spinner; ① disabled
> ```
> No `[Cancel]` button shown in any state variation.

**Impact:**
- Admin must wait through a misfired sync (worst case: hundreds of articles with image sideload at ~1–2s each).
- A sync started against the wrong environment (staging toggle accidentally on) will write garbage into WC products that then has to be cleaned up manually.
- Violates Nielsen #3 (User Control and Freedom — clearly marked emergency exits).

**Recommendation:**
Add `[Cancel sync]` button to the "During Sync" panel ③ for both `pending` and `in-progress` states. Implementation can call `as_unschedule_action()` for queued + remaining per-article actions, and let any in-flight worker finish its current article. Show a confirm dialog ("Stop catalog sync? Articles already processed will remain. Remaining N articles will be skipped.") to prevent accidental clicks. Add `cancelled` row to Sync History with count of processed-vs-skipped.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed (already covered — wireframe just needs to honour it)

---

### Finding F-3: Auto-Confirm conflicts with Shipping-Type pre-check (silent failure)

**Severity:** Critical
**Category:** Inconsistency / Workflow

**Problem:**
Discovery defines two rules in tension:
1. *Confirm Pre-Check:* "Shipping-Type muss gewählt sein."
2. *Auto-Confirm Setting:* `Off / Immediately / After X minutes`.

Auto-Confirm fires asynchronously (via `Custom Action spreadconnect/confirm_order`) on a timer or immediately after submit. **No setting picks a default Shipping-Type.** The wireframed Order-Edit shows `Type: [Select… ▾]` with no default. So if Auto-Confirm = Immediately or After X minutes, and no admin has set the shipping type yet, the Confirm call will fail the pre-check. There is no wireframed UI for "Auto-Confirm fired but pre-check failed" — it will just enter Failed Operations silently, and the admin will be confused why an auto-confirmed order is suddenly in dead-letter.

**Context:**
> **From Discovery (Business Rules → Order Lifecycle):**
> ```
> - Confirm-Pre-Check: Shipping-Type muss gewählt sein. Default-Shipping-Type-Setting könnte
>   Auto-Pick (Out-of-Scope für MVP).
> - SC-Order wird im State NEW erstellt — nicht automatisch confirmed (außer Setting "Auto-Confirm" aktiviert).
> ```
>
> **From Discovery (UI Components & States — `confirm_order_button`):**
> ```
> Click → POST /orders/{id}/confirm
> ```
>
> **From Wireframe (Screen 11, ⑤ Shipping):**
> ```
> Type: [Select… ▾]    (no default selected)
> ```

**Impact:**
- Admins enabling Auto-Confirm = Immediately will see *every* order land in Failed-Ops, silently.
- The *purpose* of Auto-Confirm (reduce manual touch) is defeated.
- Failed-Ops fills with noise that is actually an unfulfilled config gap, not a real failure.
- Worst case: admin assumes auto-confirm is working, customer orders sit in NEW indefinitely.

**Recommendation:**
Choose one:
- **Option A (Preferred):** Make Auto-Confirm setting depend on a "Default Shipping-Type" setting; disable Auto-Confirm radios with inline note "Set a default shipping type to enable" until the dependency is satisfied. Adds the default-shipping field that Discovery flagged as out-of-scope, but is necessary for Auto-Confirm to be honest.
- **Option B:** Constrain Auto-Confirm UI to "Off" only in MVP; explicitly mark Immediately/After-X as "Coming with Default Shipping-Type setting"; remove the disabled options or move them out of UI.
- In *either* case, when Auto-Confirm fails the pre-check, surface a **specific, dedicated** admin notice ("Auto-confirm could not run: order #X has no shipping type") rather than a generic Failed-Ops entry.

**Affects:**
- [x] Wireframe change needed
- [x] Discovery change needed (re-scope or constrain Auto-Confirm)

---

### Finding F-4: Bulk Re-send "silently skips" non-FAILED rows

**Severity:** Critical
**Category:** Usability (Nielsen #1 — Visibility of System Status)

**Problem:**
Screen 12 annotation ⑦ states the Bulk-Action "Re-send to Spreadconnect" is *"skipped silently for non-FAILED"*. State variation `bulk_action_complete` shows the banner "5 orders re-queued — see [Failed Ops] for any further failures". Both messages lie to the admin: an admin who selects 10 orders (mixed states) will see "5 orders re-queued" — but if only 2 were actually FAILED, the other 8 were silently dropped from the operation. The admin has no way to know what actually happened.

**Context:**
> **From Wireframe (Screen 12, ⑦):**
> ```
> Plugin Bulk-Action: "Re-send to Spreadconnect" — re-queues create_order for selected
> rows (skipped silently for non-FAILED)
> ```
>
> **From Wireframe (Screen 12, state `bulk_action_complete`):**
> ```
> Banner "5 orders re-queued — see [Failed Ops] for any further failures"
> ```

**Impact:**
- Direct violation of Nielsen #1: system state is hidden.
- Admin cannot distinguish "5 succeeded" from "I selected 5 and all 5 worked" vs "I selected 10 and 5 silently disappeared".
- Bulk-Resend is the operational tool for failure recovery — if the admin can't trust the count, they can't trust the recovery.

**Recommendation:**
Two-part fix:
1. Show explicit per-row outcome counts: "2 of 10 re-queued · 8 skipped (not in FAILED state)". Add an `[Show details]` link expanding to per-row table (Order# / decision / reason).
2. Pre-empt the issue: when bulk action menu is opened, grey out selected rows that don't match the bulk action's preconditions (Standard WP-Admin pattern). Or: select-all defaults to "all FAILED" within the visible filter. Or: refuse the action with a specific error if no eligible rows are selected.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-5: Dismiss on a `create_order` failure orphans the WC order

**Severity:** Critical
**Category:** Workflow / Gap

**Problem:**
Failed Operations Screen 5 lets the admin `[Dismiss]` an entry, which "logs the dismissal, removes row" (state variation `dismissed`). But for a `create_order` failure the WC order remains in `processing` state, never submitted to SC, with `_spreadconnect_state` empty/`failed_to_submit`. Once the dead-letter row is dismissed, the only remaining trace is in WC_Logger and an order-note. The persistent admin notice is also presumably cleared. The order is now invisible in operational dashboards (Hub Orders count: only counts states NEW/CONFIRMED/PROCESSED/FAILED — a `failed_to_submit` row was just dismissed). Fulfillment for a paying customer is silently lost.

**Context:**
> **From Wireframe (Screen 5, state `dismissed`):**
> ```
> Row removed; banner "Operation dismissed (logged)"
> ```
>
> **From Discovery (Failure Handling):**
> ```
> - Order-Failure triggert zusätzlich: Order-Note + persistente Admin-Notice
>   (dismissible nur per "Mark as resolved" oder Resend-Success).
> ```
> No clarification on whether Dismiss in Failed-Ops also clears the persistent admin notice or leaves the WC order in a recoverable state.

**Impact:**
- Real-world consequence: customer pays, order is never fulfilled, plugin shows "all clear", admin learns about it via customer support ticket weeks later.
- Inconsistency: persistent admin notice is "dismissible only via Mark-as-resolved or Resend-Success"; Dismiss in Failed-Ops appears to bypass that contract.

**Recommendation:**
- Distinguish two dismiss semantics: **(a) Dismiss = Acknowledge, leave order's WC state visible** vs **(b) Dismiss = Resolved manually (handled in SC backend by hand)**. Force the admin to choose, with consequences spelled out.
- For `create_order` op type specifically, require the admin to either (i) Resend, (ii) Mark order as `cancelled` in WC (with refund flow note), or (iii) explicitly confirm "I have submitted this order to Spreadconnect manually". Plain `Dismiss` should not be allowed for orders not yet submitted.
- Persistent admin notice for the affected order must remain until one of the three explicit resolutions occurs.

**Affects:**
- [x] Wireframe change needed
- [x] Discovery change needed (define Dismiss semantics per op-type)

---

### Finding F-6: Manual Article-ID linking is a bare input with no guidance

**Severity:** Improvement
**Category:** Usability

**Problem:**
Screen 9 state variation `unlinked` shows a free-text input: `"Article-ID: [______] [Link]"`. There is no helper text, no "Where do I find this?" link, no validation feedback (e.g. format check, exists-in-SC lookup), no autocomplete. Discovery rules out a Catalog Browser as Out-of-Scope (Open Question #5), so manual linking is the only path. SC Article-IDs are opaque numerical strings (e.g. `88421`); admins won't have them memorized.

**Context:**
> **From Wireframe (Screen 9, state `unlinked`):**
> ```
> Replace ②③⑤⑥ with: "Not linked to a Spreadconnect article. Article-ID: [______] [Link]"
> ```
>
> **From Discovery (Open Questions #5):**
> ```
> Soll Plugin auch GET /productTypes/categories für ein Catalog-Browser-UI nutzen
> (Browse SC-Catalog im Backend ohne Article anzulegen)?
> → Out-of-Scope MVP
> ```

**Impact:**
- Admin pastes a wrong/typo'd Article-ID, hits Link, plugin happily writes meta — only on next sync does an error surface.
- Frustrating onboarding for the rare case where pull-only sync misses an article and manual linking is needed.

**Recommendation:**
- Add inline helper: "Find Article-ID in your Spreadconnect dashboard → Articles. Link →" (link to SC dashboard articles page).
- Validate on `[Link]` click via `GET /articles/{id}` synchronously: if 200 → confirm with article title preview before writing meta; if 404 → inline error "Article not found in Spreadconnect".
- Optional but cheap: type-ahead search via `GET /articles?search=` if SC API supports it (check API spec).

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-7: "Test Connection" — relationship to unsaved API-Key is ambiguous

**Severity:** Improvement
**Category:** Usability

**Problem:**
Settings Screen 7 shows API Key with `[Show] [Edit]` and a separate `[Test Connection]` button below. There is no visual cue whether `[Test Connection]` tests *the value currently in the input* or *the saved option*. The standard WP pattern (Settings API) saves on form submission; `[Test Connection]` placed inline implies it acts on the live input, but Discovery's API-Client-test-roundtrip wording is ambiguous. State variation `auth_failed` shows the result; no state covers "user typed new key, didn't save, clicked Test, got result for old key".

**Context:**
> **From Wireframe (Screen 7, ② annotation):**
> ```
> Inline connection_status_badge-style status next to [Test Connection] action
> ```
> No annotation explains whether Test uses the form value or the saved option.
>
> **From Discovery (Slice 2):**
> ```
> Settings-Page mit API-Key + Test-Connection.
> ```

**Impact:**
- Admin pastes new key, clicks Test, gets a stale "still works" or "still broken" — wastes a debug cycle.
- Common WP admin frustration; senior admins will figure it out, junior admins will file a ticket.

**Recommendation:**
- Either: make `[Test Connection]` always test the *current input value* (without saving) — and change label to "Test This Key". On success, show "✓ Valid — click Save to apply". On failure, show inline error.
- Or: disable `[Test Connection]` until the form is saved, with tooltip "Save first, then test". Less ideal but unambiguous.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-8: Dashboard "Failed Operations" card hides operation type

**Severity:** Improvement
**Category:** Usability

**Problem:**
Dashboard card ⑩ shows only a count: "3 unresolved operations [View All →]". An order failure (customer waiting, time-critical) is collapsed with a sync failure (cosmetic, not blocking customers). The admin cannot triage urgency from the dashboard glance — they have to click through.

**Context:**
> **From Wireframe (Screen 1, ⑩):**
> ```
> Card "Failed Operations"
> 3 unresolved operations    [View All →]
> ```
>
> **From Discovery (Failed-Ops table):**
> ```
> op_type enum: create_order/confirm_order/cancel_order/set_shipping/sync_article/handle_webhook
> ```

**Impact:**
- Dashboard is the *primary triage surface* (per the wireframe's User Flow Overview "Operate Daily"). It should highlight the urgent.
- 1 order-failure should weigh more than 10 sync warnings.

**Recommendation:**
Break the count down: "3 unresolved: 1 order, 2 catalog [View All →]". Color the order-failure portion red; sync-failure portion yellow. Optionally surface the most recent order-failure inline ("⚠ #1083 — HTTP 422").

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-9: No proactive detection when Webhook URL changes (site migration)

**Severity:** Improvement
**Category:** Gap

**Problem:**
The plugin generates the Webhook URL from the WP site URL. Site migrations (staging → production, domain change) silently break webhook delivery — every subscription becomes `orphan` from SC's perspective and `missing` from the plugin's perspective. The user must manually navigate to Subscriptions → Repair All to discover and fix this. Discovery has a weekly drift-detection cron (`spreadconnect/auto_subscription_check`), but the wireframes do not show a proactive notice ("Webhook URL changed since last check — your subscriptions are out of date").

**Context:**
> **From Discovery (Trigger Inventory → Custom Actions):**
> ```
> spreadconnect/auto_subscription_check — (Recurring, weekly Drift-Detection)
> ```
>
> **From Wireframe:** No state variation in Screen 1 (Dashboard) or Screen 8 (Subscriptions) addresses URL-change detection.

**Impact:**
- After a migration, every webhook (orders, articles, shipments) silently drops for up to 7 days until drift-detection fires.
- During that window, orders appear stuck in NEW/CONFIRMED, tracking never arrives, admin debugs the wrong layer.

**Recommendation:**
Add an `admin_init` URL fingerprint check: store the URL used at last subscription registration in `wp_options`. On every admin page load, compare to `home_url('/wp-json/spreadconnect/v1/webhook')`. If mismatch → render a persistent admin notice on every Hub page: "Site URL changed — webhooks may be unreachable. [Repair Subscriptions]". Reflect this state in Hub-Header subscription badge and on Screen 8 with a dedicated `url_drift_detected` state variation.

**Affects:**
- [x] Wireframe change needed
- [x] Discovery change needed (add the fingerprint check as a business rule)

---

### Finding F-10: Color-coded badges lack text labels / SR-friendly affordance

**Severity:** Improvement
**Category:** Usability / Accessibility

**Problem:**
The wireframes lean heavily on color-coded badges and dots: state badges (NEW/CONFIRMED/PROCESSED/CANCELLED — blue/orange/violet/grey), margin colors (red/yellow/green), HMAC ✓/✗, processing ✓/⚠. While the textual labels are usually present, the *meaning differentiation is encoded in color* (e.g. NEW vs CONFIRMED look identical except hue). For colour-blind users (1 in 12 men) and screen-reader users on a *back-office* tool that admins use daily, this is a disservice.

**Context:**
> **From Discovery (UI Components & States):**
> ```
> margin_display | farbcodiert: red (<20%), yellow (20–40%), green (>40%)
> ```
>
> **From Wireframe (multiple screens):**
> Badges appear only as color + label; no shape/icon redundancy mentioned, no aria-* annotations in any wireframe.

**Impact:**
- Accessibility regression vs WP-Admin baseline (WP core uses dashicons + text + colour).
- Colour-blind admins misjudge margin status (red/green confusion is the most common pattern).

**Recommendation:**
- Pair every colour with an icon (✓ / ⚠ / ✗ / ●○△) — already partly done in some places, do it consistently.
- For state badges, shape-encode in addition to colour (filled circle for terminal states like CANCELLED, ring for in-flight states like NEW/CONFIRMED).
- Add `aria-label` annotations to wireframes for the live-progress region (`aria-live="polite"`) and for badges.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-11: Settings "Save" performs ≥3 side-effects with no per-step result

**Severity:** Improvement
**Category:** Usability

**Problem:**
Discovery Flow A defines that on Settings save with valid API key, the system: (1) verifies connection, (2) generates HMAC secret if first time, (3) auto-registers 7 webhook subscriptions. That's three independent operations bundled into one [Save Changes] click. State variation `save_in_progress` shows a single spinner; `save_error` shows a generic error panel. There is no UI for "API key worked but 3 of 7 subscriptions failed to register" — a common partial-failure scenario.

**Context:**
> **From Discovery (Flow A.3-6):**
> ```
> 3. System: GET /authentication → Verbindung verifiziert → Connection-Status grün
> 4. System: HMAC-Secret wird generiert + einmalig angezeigt
> 5. System: Auto-Registriert alle 7 Webhook-Subscriptions
> 6. Subscription-Status: alle 7 active → grün
> ```
>
> **From Wireframe (Screen 7, state `save_error`):**
> ```
> Inline error panel above ⑨ with field-level error markers
> ```

**Impact:**
- Partial-success state is invisible — admin sees "Saved!" and assumes everything worked.
- Subscription Manager Screen 8 will show the truth, but the admin has no reason to look.

**Recommendation:**
After Save, render a stepwise result panel:
```
✓ API connection verified
✓ HMAC secret generated [Copy] [Done]
⚠ Subscriptions: 4 of 7 registered (3 failed) [Open Subscriptions Manager →]
```
Make this the dominant UI on the page until acknowledged. Also: link to Subscriptions Manager from this panel directly.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

### Finding F-12: `Order.cancelled` race during Auto-Confirm timer window

**Severity:** Improvement
**Category:** Gap

**Problem:**
Auto-Confirm "After X minutes" schedules a deferred confirm action. Within that window, the WC order can be cancelled by the admin (or by a stornoplugin). The Cancel-Mirror logic (Flow D) reads `_spreadconnect_state` = `NEW` and schedules `POST /orders/{id}/cancel`. Two actions are now racing: the deferred Confirm and the immediate Cancel. Discovery doesn't define ordering. The likely outcome: race condition, sometimes the confirm wins, sometimes cancel wins, sometimes both fire and one fails late.

**Context:**
> **From Discovery (Flow D + Order Behavior):**
> ```
> - Auto-Confirm: Off / Immediately / After X minutes (default Off)
> - WC-Cancel-Mirror triggert SC-Cancel automatisch nur wenn SC-State = NEW
> ```
> No mention of pending auto-confirm action interaction.

**Impact:**
- Order may end up CONFIRMED in SC despite admin cancelling in WC — fulfillment proceeds for a cancelled order.
- Debugging the race after the fact is hard: webhook log shows both actions scheduled; outcome depends on Action Scheduler queue order.

**Recommendation:**
On WC Cancel: also call `as_unschedule_action('spreadconnect/confirm_order', [order_id])` to remove any pending auto-confirm before scheduling the cancel-mirror. Document this rule in Discovery → Order Lifecycle.

**Affects:**
- [ ] Wireframe change needed
- [x] Discovery change needed (add the unschedule rule)

---

### Finding F-13: Notification fatigue — email + admin-notice + order-note per failure

**Severity:** Suggestion
**Category:** Usability

**Problem:**
For a single order failure, Discovery prescribes: (1) email to recipients, (2) persistent admin notice, (3) order-note, (4) Failed-Ops table entry. Four notifications for one event. In a busy shop with occasional 5xx noise from SC, this becomes overwhelming and admins start tuning out.

**Context:**
> **From Discovery (Failure Handling):**
> ```
> - Email-Notification an Empfänger-Liste (Setting), nur bei Permanent Failure.
> - Order-Failure triggert zusätzlich: Order-Note + persistente Admin-Notice
> ```

**Impact:**
- Notification fatigue → admin auto-dismisses notices → real failures get ignored.
- Email noise for transient-then-persistent failures fills inboxes.

**Recommendation:**
- Provide notification-batching: instead of one email per failure, send a digest every N minutes (configurable, e.g. "≤ every 15 min").
- Persistent admin notice should aggregate ("3 orders failed in the last hour") rather than render one per order on every page load.

**Affects:**
- [ ] Wireframe change needed
- [x] Discovery change needed (add batching rule)

---

### Finding F-14: Stock burst on Product-Edit page (per-variation parallel calls)

**Severity:** Suggestion
**Category:** Scalability

**Problem:**
Discovery Flow F.1: "Bei Aufruf einer Product-Edit-Page → falls Cache stale: `GET /stock/{sku}` (für jede Variation parallel)". A T-Shirt with 5 sizes × 4 colors = 20 variations → 20 parallel API calls on every cache miss. With the `X-RateLimit-Remaining ≤ 5` proactive sleep rule (Discovery Business Rules), the page will visibly pause. Worse, multiple admins editing different products simultaneously can rate-limit each other.

**Context:**
> **From Discovery (Flow F.1 + Business Rules):**
> ```
> Live-Cache (5min TTL): Bei Aufruf einer Product-Edit-Page → falls Cache stale:
>   GET /stock/{sku} (für jede Variation parallel)
> Rate-Limit-Awareness: bei X-RateLimit-Remaining ≤ 5 proaktiv 1s sleep
> ```

**Impact:**
- Slow page loads on cache miss for products with many variations.
- Risk of rate-limit cascading across admins.

**Recommendation:**
- Use the bulk endpoint `GET /stock` (already in Trigger Inventory for the periodic job) and filter client-side, instead of N parallel `/stock/{sku}` calls.
- Defer stock fetch to *after* page render: render the meta-box with cached values + a `[Loading live stock…]` skeleton, fetch async, swap in. Avoids blocking page TTI.

**Affects:**
- [ ] Wireframe change needed (the deferred-render UX is implementation-level)
- [x] Discovery change needed (replace per-SKU loop with bulk-endpoint usage)

---

### Finding F-15: Wireframe German/English mix doesn't model i18n source strings

**Severity:** Suggestion
**Category:** Inconsistency

**Problem:**
The wireframes mix German ("Jetzt synchronisieren", "Spreadconnect", section title "Catalog Sync" mixed with German button labels) and English ("Sync running", "Last sync", "Webhook Event Log") freely. Discovery rules say *English source + de_DE.po translation*. Wireframes — being a design artefact — should consistently model the source language, with a note that German is the rendered translation.

**Context:**
> **From Discovery (i18n):**
> ```
> Source-Strings English, Translation de_DE.po mitgeliefert.
> ```
>
> **From Wireframe (Screen 1, ⑦):**
> ```
> [⑦ Jetzt synchronisieren]
> ```

**Impact:**
- Dev confusion during implementation: "is the source string German or English?"
- Loss of i18n discipline review (e.g. truncation issues for longer German equivalents).

**Recommendation:**
Choose one convention: either (a) all wireframe labels in English source, with a footnote "German rendering shown in deployment", or (b) two-column labels for ambiguous strings. Cheap fix: replace "Jetzt synchronisieren" with "Sync now" throughout.

**Affects:**
- [x] Wireframe change needed
- [ ] Discovery change needed

---

## Gaps & Inconsistencies (cross-cutting)

| # | Item | Source of truth | Wireframed? |
|---|------|-----------------|-------------|
| G-1 | First-time HMAC secret reveal flow | Discovery Flow A.4 | **No** (F-1) |
| G-2 | Sync cancellation UI for `pending`/`in-progress` | Discovery State Machine | **No** (F-2) |
| G-3 | Auto-Confirm + Shipping-Type pre-check interaction | Both contradict | **No** (F-3) |
| G-4 | Bulk Resend per-row outcome reporting | Wireframe annotation only | Partial (F-4) |
| G-5 | Dismiss semantics for `create_order` op-type | Underspecified | **No** (F-5) |
| G-6 | URL-drift detection UI | Discovery cron mentioned | **No** (F-9) |
| G-7 | Settings save partial-success state | Multi-step Flow A | **No** (F-11) |
| G-8 | Auto-Confirm + WC Cancel race | Implicit | **No** (F-12) |

---

## Scalability & Risks

- **Action Scheduler at 100+ articles:** Discovery references 25 actions/batch. A 200-article catalog means ~8 batches. The wireframe's "ETA ~2:14" is plausible. No concern.
- **Webhook log retention 90 days:** At an active shop (say 100 events/day) → ~9000 rows. Fine for a CPT-style table with index on `received_at`. No concern.
- **Failed-Ops table growth:** Default 90-day retention plus admin-driven Resend/Dismiss should keep it small. No concern.
- **Multi-admin contention:** Two admins simultaneously editing settings or running repair: no optimistic-locking or dirty-state UI. Low-probability for single-shop installations; flag for V2.
- **Stock-sync rate-limit cascade:** Already covered in F-14. The bulk-endpoint switch is the right scaling lever.

---

## Expert Assessment

**Is this the right solution for the problem?**
Yes. The decision to build greenfield rather than incrementally extend v1 is correct given the scope (2 → 27 endpoints + 7 webhooks + background queue + HPOS + dead-letter + reconciliation). The Hub-with-sidebar pattern is the right WP-Admin convention for a *single, opinionated* operational surface — superior to scattering features across multiple top-level menus or fragmenting them into sub-plugins.

**What's strong:**
- Action-Scheduler-first is the right choice over WP-Cron for reliability.
- Dual HPOS + legacy hooks: correct and explicitly addresses the 2026 WC plugin reality.
- Failed-Ops as a first-class surface (rather than burying failures in WC_Logger): matches operator mental model.
- Subscription Manager with Repair: the "self-healing" pattern is exactly right for systems prone to drift.
- HMAC verification with constant-time-compare: correct security baseline.

**What's weak:**
- The 5 critical findings (F-1 through F-5) all share a common root: **the wireframes model happy-path steady-state but not the mode-switching events** (first-time setup, mid-operation control, failure dismissal). For an operational tool, mode-switching *is* the UX. This needs a focused pass.
- Auto-Confirm without Default-Shipping-Type is a half-feature; either complete it (F-3 Option A) or defer it.

**Strategic fit:**
- The plugin is intentionally portable across multiple WP installations (the multi-shop strategy). Settings-only config + HMAC-secret management are the right pattern for this. No theming-engine entanglement (correctly out of scope per CLAUDE.md frontend-only theming).
- One concern: at 200+ products, the Hub Dashboard's "142 WC-Products linked" count is a single number — admins will want to know "how many *with sync errors*" at a glance. Already covered by F-8 indirectly.

**Recommendation:**
Address F-1 through F-5 (Critical) before implementation. F-6 through F-12 (Improvements) should be addressed before merge but can be sliced. F-13 through F-15 (Suggestions) can be deferred to post-MVP.

---

## Positive Highlights

- **Cross-surface navigation discipline:** Failed-Ops → WC Order, Webhook-Log → Order/Article, Order-Edit → Failed-Ops Detail. Few WP-Admin plugins do this; this one does.
- **Stock indicator design** (per-variation table with Available / Low / Out + last-refresh + manual refresh button): exemplary information density for a backend surface.
- **Subscription Manager's deletion safety rule** ("only orphans on our URL are deleted; foreign URLs preserved"): senior-grade defensive design that prevents accidental damage to other systems sharing the SC tenant.
- **State Machine completeness in Discovery:** rare to see a plugin spec with this level of state-transition rigor. Gives the wireframe reviewer (me) something concrete to verify against.
- **Settings → Export/Import JSON:** future-friendly for staging/production parity.
- **Sync History table with per-article expansion:** correct level of detail — summary first, details on demand.

---

## Verdict

**Verdict:** CHANGES_REQUESTED

**Rationale:** 5 Critical findings (F-1 through F-5) describe operational failure modes the admin cannot recover from, or silent state divergences. They must be resolved in the wireframe / discovery before implementation. 7 Improvements should also be addressed for a polished V1.

**Next steps:**
1. Update Discovery + Wireframes to address F-1 through F-5 (Critical).
2. Update Wireframes to address F-6 through F-11.
3. Update Discovery for F-12 (race condition rule).
4. Re-submit for review.
