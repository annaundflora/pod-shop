# Gate 0 — Discovery ↔ Wireframe Compliance

**Spec:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/`
**Reviewer:** zen:discovery-wireframe-compliance (fresh-context sub-agent)
**Date:** 2026-05-03
**Inputs:** `discovery.md`, `wireframes.md`
**Iteration:** 1 (first pass — APPROVED on first attempt)

---

## Verdict

**VERDICT: APPROVED**

All 12 screens are visualized, all 28 UI Components are annotated, no new wireframe-only business logic introduced.

---

## A) Discovery → Wireframe (Forward check — all screens visualized?)

| Screen | Discovery Section | Wireframe | Status |
|---|---|---|---|
| 1  Hub Dashboard              | UI Layout § Screen 1  | Screen 1 (lines 94–171)  | ✅ |
| 2  Catalog Sync               | UI Layout § Screen 2  | Screen 2 (lines 174–262) | ✅ |
| 3  Orders                     | UI Layout § Screen 3  | Screen 3 (lines 265–319) | ✅ |
| 4  Webhook Event Log          | UI Layout § Screen 4  | Screen 4 (lines 322–394) | ✅ |
| 5  Failed Operations          | UI Layout § Screen 5  | Screen 5 (lines 397–499) | ✅ |
| 6  Logs                       | UI Layout § Screen 6  | Screen 6 (lines 502–551) | ✅ |
| 7  Settings                   | UI Layout § Screen 7  | Screen 7 (lines 554–689) | ✅ |
| 8  Subscriptions Manager      | UI Layout § Screen 8  | Screen 8 (lines 692–745) | ✅ |
| 9  Product-Edit Meta-Box      | UI Layout § Screen 9  | Screen 9 (lines 748–860) | ✅ |
| 10 Product-List Spalten       | UI Layout § Screen 10 | Screen 10 (lines 863–916)| ✅ |
| 11 Order-Edit Meta-Box        | UI Layout § Screen 11 | Screen 11 (lines 919–995)| ✅ |
| 12 Order-List Spalten + Bulk  | UI Layout § Screen 12 | Screen 12 (lines 998–1049)| ✅ |

All 12 screens covered.

### UI Components Coverage

All 28 entries from Discovery's `UI Components & States` table are mapped in the wireframes' `Component Coverage` table (wireframes.md lines 11–42). This includes the 17 original components plus the 11 added during the UX Expert Review pass (`cancel_sync_button`, `article_picker`, `dismiss_resolution_modal`, `save_success_panel`, `initial_secret_reveal_panel`, `auto_confirm_pending_indicator`, `cancel_auto_confirm_button`, `dashboard_failure_breakdown`, `bulk_resend_outcome_panel`, `default_shipping_type_radio`, `auto_confirm_radio`).

### State Coverage

| State Machine (Discovery)             | States | Visualized in |
|---------------------------------------|--------|---------------|
| Connection State                      | 5      | Screen 1 (Hub Header) + Screen 7 |
| Subscription State (per Event)        | 4      | Screen 8 + Screen 1 (Hub Header aggregate) |
| Order State (8 incl. failed_to_submit, needs_action) | 8      | Screen 11 (per-state variation) + Screen 3 + Screen 12 |
| Article Sync State                    | 5      | Screen 9 (per-state variation) |
| Sync-Job State (incl. canceled)       | 5      | Screen 2 (per-state variation; new `cancel_confirm_open`/`canceling`/`canceled`) |
| Failed-Operation State                | 4      | Screen 5 |

All terminal states have an exit path defined.

---

## B) Wireframe → Discovery (Backflow check — no new logic introduced?)

Spot-checked the wireframe-only details that could risk drifting from Discovery:

| # | Wireframe detail | Discovery anchor | Verdict |
|---|------------------|------------------|---------|
| 1 | "ETA ~2:14" in Catalog Sync progress bar | Discovery Flow B mentions live progress; ETA is a presentation detail of the same data | ✅ presentation-only |
| 2 | "Force re-pull images" toggle in Sync-Settings | Discovery Business Rules → Catalog Sync ("außer Admin-Toggle 'Force re-pull images' gesetzt") | ✅ |
| 3 | `/wp-json/spreadconnect/v1/sync-progress` REST route | Discovery Trigger Inventory → WC-Hooks: "Register `/spreadconnect/v1/webhook`, `/sync-progress`, `/health`" | ✅ |
| 4 | Bulk-Resend pre-flight count + per-row outcome | Discovery `bulk_resend_outcome_panel` component (states `in_progress`/`complete_with_counts`/`no_eligible`) | ✅ |
| 5 | "Test This Key" tests current input value | Discovery: "Settings → Test Connection (manuell)"; the without-saving semantic is wireframe-explicit but consistent | ✅ |
| 6 | Cancel-sync confirm modal text | Discovery `cancel_sync_button` state `confirming` | ✅ |
| 7 | Article-Picker 64×64 thumbnails | Discovery `article_picker` component definition; size is presentation-only | ✅ |
| 8 | "Last regenerated" timestamp display in Settings | Inferred from `spreadconnect_webhook_secret` option lifecycle; presentation-only | ✅ |
| 9 | Sync History columns (Started/Duration/Trigger/…) | Discovery `wp_spreadconnect_sync_history` table columns | ✅ exact match |
| 10 | "Submitting…" interim state | Discovery Order State machine includes `submitting` | ✅ |
| 11 | "NEW · auto-confirm in 7 min" badge composition | Discovery `auto_confirm_pending_indicator` component | ✅ |
| 12 | `failure_admin_notice` "Mark Resolved" affordance | Discovery: "Bleibt bis Admin 'Mark as resolved' klickt" | ✅ |
| 13 | Hub-Header on every Hub sub-page | Discovery Hub-Page pattern in UI Patterns / "New Patterns" | ✅ |
| 14 | Webhook Log "(unknown)" event type row | Defensive UI for HMAC-valid but unknown event-type — defensible | ✅ defensive UI |

No critical wireframe-only logic that contradicts or extends Discovery.

---

## C) Bidirectional Sync of UX-Review Updates

The UX Expert Review (`checks/ux-expert-review.md`) produced 12 implement-decisions that affected both documents. Verifying alignment:

| Finding | Discovery anchor | Wireframe anchor | Aligned? |
|---------|------------------|------------------|----------|
| F-1 Initial HMAC reveal | (implicit in `spreadconnect_webhook_secret` option) | Screen 7 state `initial_secret_reveal` + nested in `save_success_panel` ASCII | ✅ |
| F-2 Cancel sync | Discovery `cancel_sync_button` UI Component | Screen 2 ⑨ + state `cancel_confirm_open`/`canceling`/`canceled` | ✅ |
| F-3 Default-Shipping-Type + gating | Discovery: new wp_option `spreadconnect_default_shipping_type`, Order Lifecycle business rule (Auto-Confirm-Gating + Pre-Check-Failure-Handling), Settings UI Layout updated | Screen 7 § ⑥a/⑥b + state `auto_confirm_locked` + Screen 11 state `NEW_default_shipping_prefilled`/`auto_confirm_pending`/`auto_confirm_failed_no_shipping` | ✅ |
| F-4 Bulk skip outcome | Discovery `bulk_resend_outcome_panel` component | Screen 12 ⑦ + states `bulk_action_selected_with_ineligible`/`bulk_action_complete`/`bulk_action_no_eligible` | ✅ |
| F-5 Per-op-type Dismiss | Discovery Failure Handling business rule (3 op-type buckets, modal contract) | Screen 5 ④ annotation + state `dismiss_modal_create_order_open` ASCII + `bulk_dismiss_blocked` | ✅ |
| F-6 Article Picker | Discovery `article_picker` component | Screen 9 state `unlinked` + ASCII + behavior bullet list | ✅ |
| F-7 Test This Key | (implicit; consistent with existing intent) | Screen 7 ② updated label + helper text | ✅ |
| F-8 Failed-Ops breakdown | Discovery `dashboard_failure_breakdown` component | Screen 1 ⑩ ASCII + state variations `failures_orders_only`/`failures_catalog_only` | ✅ |
| F-11 Stepwise save panel | Discovery `save_success_panel` component | Screen 7 state `save_success_panel`/`save_partial_error` ASCII | ✅ |
| F-12 Race protection | Discovery Order Lifecycle business rule (`as_unschedule_action` on WC-Cancel) | Screen 11 state `auto_confirm_pending` `[Cancel auto-confirm]` button + `cancel_auto_confirm_button` component | ✅ |
| F-14 Bulk stock endpoint | Discovery Flow F.1 rewritten + Trigger Inventory updated | (implementation-level; no wireframe change required per F-14 decision) | ✅ |
| F-15 i18n source | (already in Discovery → Business Rules → i18n) | Wireframes top-of-file note + "Sync now" replaces "Jetzt synchronisieren" | ✅ |

All 12 implement-findings are reflected in both documents.

---

## D) Issues Found

**BLOCKING_ISSUES:** none.

Minor non-blocking observations (informational only — no action required):
- ETA in Sync progress bar is a presentation detail that could optionally be acknowledged in Discovery's Catalog Sync screen description. Non-blocking.
- "Last regenerated" timestamp display for HMAC secret is inferred presentation; could be made explicit in Discovery's options table. Non-blocking.

These are presentation refinements, not contract gaps.

---

## E) Discovery Updates Required

**DISCOVERY_UPDATES:** `[]` (none — Discovery already contains all the contract-level information needed for the wireframes; presentation-only details are appropriately wireframe-scoped)

---

## Summary

- All 12 screens visualized → ✅
- All 28 UI Components annotated → ✅
- All state machines have UI representation → ✅
- All terminal states reachable + exit-able → ✅
- No new wireframe-only business logic → ✅
- All 12 UX-Review implement-decisions reflected bidirectionally → ✅

**Gate 0: PASS — proceed to /zen:architecture.**
