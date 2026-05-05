# Wireframes: Spreadconnect POD Plugin v2 — Full API Coverage

**Discovery:** `discovery.md` (same folder)
**Status:** Draft

> **i18n note:** All labels in these wireframes are shown in **English source** strings (see Discovery → Business Rules → i18n). The German rendering is delivered via `de_DE.po` translation at runtime; translation review happens during /implement, not in this wireframe.

---

## Component Coverage

| UI Component (from Discovery) | Screen(s) |
|-------------------------------|-----------|
| `connection_status_badge`     | 1 Hub Dashboard, 7 Settings (Hub Header on every Hub screen) |
| `sync_now_button`             | 1 Hub Dashboard, 2 Catalog |
| `sync_progress_bar`           | 2 Catalog |
| `subscription_status_badge`   | 1 Hub Dashboard (Hub Header), 8 Subscriptions |
| `repair_subscriptions_button` | 8 Subscriptions |
| `regenerate_secret_button`    | 7 Settings |
| `confirm_order_button`        | 11 Order-Edit Meta-Box |
| `cancel_order_button`         | 11 Order-Edit Meta-Box |
| `shipping_type_dropdown`      | 11 Order-Edit Meta-Box |
| `resend_button`               | 5 Failed Ops, 11 Order-Edit Meta-Box, 12 Order-List Bulk |
| `refresh_state_button`        | 11 Order-Edit Meta-Box |
| `margin_display`              | 9 Product-Edit Meta-Box, 10 Product-List |
| `live_stock_indicator`        | 9 Product-Edit Meta-Box |
| `failed_op_resend`            | 5 Failed Ops |
| `webhook_log_row`             | 4 Webhook Event Log |
| `failure_admin_notice`        | All WP-Admin pages (header banner – shown on Screen 1, 3, 5, 11) |
| `low_margin_notice_in_list`   | 10 Product-List |
| `cancel_sync_button`          | 2 Catalog |
| `article_picker`              | 9 Product-Edit Meta-Box (state `unlinked`) |
| `dismiss_resolution_modal`    | 5 Failed Ops (state `dismiss_modal_create_order_open`) |
| `save_success_panel`          | 7 Settings (state `save_success_panel`/`save_partial_error`) |
| `initial_secret_reveal_panel` | 7 Settings (nested inside `save_success_panel` on first-time setup) |
| `auto_confirm_pending_indicator` | 11 Order-Edit Meta-Box (state `auto_confirm_pending`) |
| `cancel_auto_confirm_button`  | 11 Order-Edit Meta-Box (state `auto_confirm_pending`) |
| `dashboard_failure_breakdown` | 1 Hub Dashboard (Card ⑩) |
| `bulk_resend_outcome_panel`   | 12 Order-List Bulk |
| `default_shipping_type_radio` | 7 Settings (Section ⑥a) |
| `auto_confirm_radio`          | 7 Settings (Section ⑥b) |

---

## User Flow Overview

```
                                ┌──────────────────────────┐
                                │  WP Admin → WooCommerce  │
                                │      → Spreadconnect     │
                                └────────────┬─────────────┘
                                             │
                  ┌──────────────────────────┼─────────────────────────┐
                  │                          │                         │
            [Initial Setup]            [Operate Daily]            [Reconcile]
                  │                          │                         │
                  ▼                          ▼                         ▼
           ┌────────────┐           ┌──────────────┐         ┌────────────────┐
           │ 7 Settings │           │ 1 Dashboard  │         │ 5 Failed Ops   │
           └─────┬──────┘           └──────┬───────┘         └────────┬───────┘
                 │ Save API-Key            │                          │ Resend
                 │ + Auto-Subs             │                          │   ▲
                 ▼                         ├─► 2 Catalog ─► [Sync]    │   │
           ┌────────────┐                  │      │                   │   │
           │8 Subs Mgr  │                  │      ▼                   │   │
           │  Repair    │                  │   Live progress poll     │   │
           └────────────┘                  │                          │   │
                                           ├─► 3 Orders ─► 11 Order-Edit ─┘
                                           │      │              │
                                           │      ▼              ▼
                                           │   12 Order-List   [Confirm]
                                           │   Bulk-Resend     [Cancel]
                                           │                   [Resend]
                                           ├─► 4 Webhook Log
                                           │      │
                                           │      ▼ Expand row
                                           │   JSON-Payload
                                           │
                                           ├─► 5 Failed Ops ──► Resend ─► out of list
                                           │
                                           ├─► 6 Logs (WC_Logger reader)
                                           │
                                           ├─► 9 Product-Edit Meta-Box
                                           │      (Margin, Stock, Re-Sync)
                                           │
                                           └─► 10 Product-List
                                                  (Cost/Margin columns)

  Webhook (inbound) ─► 4 Webhook Log row ─► async process ─► success or 5 Failed Ops
```

---

## Screen 1: Hub Dashboard

**Context:** WP-Admin top-level navigation `WooCommerce → Spreadconnect`. Default landing page when admin opens the plugin hub. Renders with `manage_woocommerce` capability check.

### Wireframe

```
┌─[ WordPress Admin ]──────────────────────────────────────────────────────────────────────┐
│ ☰  My Sites    Howdy, admin ▾                                                            │
├──────────┬───────────────────────────────────────────────────────────────────────────────┤
│ 🏠 Dash. │ ⚠ ① Order #1083 failed to submit to Spreadconnect — [View] [Resend] [Dismiss]│
│ 📝 Posts │ ─────────────────────────────────────────────────────────────────────────────│
│ 🛒 WooC. │                                                                               │
│  ├ Orders│ Spreadconnect                                                                 │
│  ├ Prod. │ ┌──────────────────────────────────────────────────────────────────────────┐ │
│  ├ Cust. │ │ ② Connection: ✓ Connected   API-Key: ••••••8f2a   Env: Production       │ │
│  └►Sprdc.│ │ ③ Subscriptions: 7/7 active                  Last Webhook: 2 min ago    │ │
│ 🔌 Plug. │ └──────────────────────────────────────────────────────────────────────────┘ │
│          │ ┌─────────────┬───────────────────────────────────────────────────────────┐ │
│          │ │ ④ Sidebar   │  Main                                                     │ │
│          │ │             │                                                           │ │
│          │ │►Dashboard   │  ┌──────────────────────┐  ┌──────────────────────────┐ │ │
│          │ │ Catalog     │  │ ⑤ Connection         │  │ ⑥ Catalog                │ │ │
│          │ │ Orders      │  │                      │  │                          │ │ │
│          │ │ Webhooks    │  │ ✓ Connected          │  │ 142 WC-Products linked   │ │ │
│          │ │ Failed Ops  │  │ Last check: 14:02    │  │ Last sync: 2h ago        │ │ │
│          │ │ Logs        │  │ [Re-test]            │  │ [⑦ Sync now]│ │ │
│          │ │ Settings    │  │                      │  │                          │ │ │
│          │ │ Subscriptns │  └──────────────────────┘  └──────────────────────────┘ │ │
│          │ │             │                                                           │ │
│          │ │             │  ┌──────────────────────┐  ┌──────────────────────────┐ │ │
│          │ │             │  │ ⑧ Orders (30d)       │  │ ⑨ Webhooks               │ │ │
│          │ │             │  │                      │  │                          │ │ │
│          │ │             │  │ NEW       :   12     │  │ ✓ 7/7 subscriptions      │ │ │
│          │ │             │  │ CONFIRMED :   24     │  │ Last event: Shipment.sent│ │ │
│          │ │             │  │ PROCESSED :  186     │  │   on Order #1079         │ │ │
│          │ │             │  │ FAILED    :    2 ⚠  │  │   2 min ago              │ │ │
│          │ │             │  │ [View Orders]        │  │ [View Log]               │ │ │
│          │ │             │  └──────────────────────┘  └──────────────────────────┘ │ │
│          │ │             │                                                           │ │
│          │ │             │  ┌────────────────────────────────────────────────────┐  │ │
│          │ │             │  │ ⑩ Failed Operations                  3 unresolved │  │ │
│          │ │             │  │                                                    │  │ │
│          │ │             │  │  ●1 order      (urgent — customer waiting)         │  │ │
│          │ │             │  │  ●2 catalog    (sync warnings)                     │  │ │
│          │ │             │  │  ○0 webhook                                        │  │ │
│          │ │             │  │                                                    │  │ │
│          │ │             │  │  Most recent: ⚠ Order #1083 — HTTP 422 (14:32)     │  │ │
│          │ │             │  │  [View All →]   [Open #1083 →]                     │  │ │
│          │ │             │  └────────────────────────────────────────────────────┘  │ │
│          │ └─────────────┴───────────────────────────────────────────────────────────┘ │
└──────────┴───────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① `failure_admin_notice`: persistent admin notice for permanent order failures, dismiss only via [Dismiss] or successful Resend
- ② `connection_status_badge`: Hub-header badge — `connected` (green) shown; click triggers `GET /authentication`
- ③ `subscription_status_badge`: aggregate badge in Hub-header (X/7) — click navigates to Screen 8
- ④ Hub Sidebar Navigation (8 sections)
- ⑤ Card "Connection" — re-test runs `GET /authentication`
- ⑥ Card "Catalog" — counts of linked WC-products + last sync timestamp
- ⑦ `sync_now_button`: schedules `spreadconnect/sync_catalog` Action; disabled while running
- ⑧ Card "Orders" — counts per SC-state (last 30 days)
- ⑨ Card "Webhooks" — last event received (type + entity + timestamp)
- ⑩ Card "Failed Operations" — total count broken down by op-type group (orders / catalog / webhooks); urgent group (orders) red ●, warning group (catalog) yellow ●, idle group (webhooks) outlined ○; surfaces most recent order-failure inline with direct [Open #X →] shortcut + general [View All →]

### State Variations

| State | Visual Change |
|-------|---------------|
| `unconfigured` | Hub-header badge red `Not configured`; cards ⑥⑧⑨⑩ replaced with "Configure API key in Settings" CTA |
| `auth_failed` | Hub-header badge red `Invalid Key`; ⚠ banner "API key invalid — re-enter in Settings"; cron jobs auto-disabled note |
| `network_error` | Hub-header badge yellow ⚠ `Cannot reach Spreadconnect`; Re-test button prominent in ⑤ |
| `sync_running` | ⑦ replaced with "Sync running… X/Y articles" + spinner; click navigates to Screen 2 |
| `no_failures` | ⑩ shows "0 unresolved" in green with checkmark; per-group rows hidden; "Most recent" line hidden |
| `failures_orders_only` | ⑩ shows red `●N order` row only; other rows omitted; most-recent line surfaces the order-failure |
| `failures_catalog_only` | ⑩ shows yellow `●N catalog` row only; "Most recent" surfaces a sync warning instead of an order |

---

## Screen 2: Catalog Sync (Hub Sub-Page)

**Context:** Reached via Sidebar → "Catalog". Primary surface for triggering catalog pulls and observing background-job progress.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header (see Screen 1)] ─────────────────────────────────────────┐
│ ┌─────────────┬────────────────────────────────────────────────────────────────────┐    │
│ │ Dashboard   │  Catalog Sync                                                      │    │
│ │►Catalog     │  ┌────────────────────────────────────────────────────────────────┐│    │
│ │ Orders      │  │ ① [Sync now]   ② Sync-Settings ▾                  ││    │
│ │ Webhooks    │  │                              ┌──────────────────────────────┐  ││    │
│ │ Failed Ops  │  │                              │ Pull Images:    [✓] On       │  ││    │
│ │ Logs        │  │                              │ Stock Threshold: [10  ]      │  ││    │
│ │ Settings    │  │                              │ Force re-pull images: [ ] Off│  ││    │
│ │ Subscriptns │  │                              └──────────────────────────────┘  ││    │
│ │             │  └────────────────────────────────────────────────────────────────┘│    │
│ │             │                                                                    │    │
│ │             │  ┌─ ③ During Sync (visible only while running) ─────────────────┐ │    │
│ │             │  │                                              ⑨ [✗ Cancel sync]│ │    │
│ │             │  │ ④ Sync running  ████████████████░░░░░░░░  84 / 142 articles │ │    │
│ │             │  │                                                              │ │    │
│ │             │  │ ⑤ created 12 │ updated 68 │ skipped 3 │ error 1 │ ETA ~2:14 │ │    │
│ │             │  │                                                              │ │    │
│ │             │  │ ⑥ Live Log (last 20 entries):                                │ │    │
│ │             │  │ ┌──────────────────────────────────────────────────────────┐ │ │    │
│ │             │  │ │ 14:31:08  ✓ updated  Article 88421  "Bio-Shirt Unisex"   │ │ │    │
│ │             │  │ │ 14:31:05  ✓ updated  Article 88420  "Hoodie Damen"       │ │ │    │
│ │             │  │ │ 14:31:01  ⚠ partial  Article 88419  (image sideload fail)│ │ │    │
│ │             │  │ │ 14:30:58  ✗ error    Article 88418  (no design in SC)    │ │ │    │
│ │             │  │ │ 14:30:55  ✓ created  Article 88417  "Tasche Premium"     │ │ │    │
│ │             │  │ │ … (auto-scroll, refresh every 3s)                        │ │ │    │
│ │             │  │ └──────────────────────────────────────────────────────────┘ │ │    │
│ │             │  └──────────────────────────────────────────────────────────────┘ │    │
│ │             │                                                                    │    │
│ │             │  ⑦ Sync History                                                    │    │
│ │             │  ┌──────────────────────────────────────────────────────────────┐ │    │
│ │             │  │ Started      │ Dur.  │ Trigger │ Cre │ Upd │ Skp │ Err │Stat │ │    │
│ │             │  ├──────────────┼───────┼─────────┼─────┼─────┼─────┼─────┼─────┤ │    │
│ │             │  │ ▶ 14:30 today│  4m   │ Manual  │  12 │  68 │   3 │   1 │ ✓ OK │ │    │
│ │             │  │ ▶ 12:01 today│  3m   │ Webhook │   1 │   0 │   0 │   0 │ ✓ OK │ │    │
│ │             │  │ ▶ 06:00 today│  6m   │Scheduled│   0 │ 142 │   0 │   2 │ ⚠part│ │    │
│ │             │  │ ▶ Yest 22:14 │  5m   │ Manual  │   3 │   0 │   1 │   0 │ ✓ OK │ │    │
│ │             │  │ ▶ Yest 14:00 │  4m   │Scheduled│   0 │ 142 │   0 │   0 │ ✓ OK │ │    │
│ │             │  └──────────────────────────────────────────────────────────────┘ │    │
│ │             │  Click ▶ on a row → ⑧ expand per-article details                   │    │
│ │             │                                                                    │    │
│ └─────────────┴────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────────────────┘

┌─ Expanded History Row (when ▶ clicked) ──────────────────────────────────────────────┐
│ ⑧ Per-article details for run #182 (started 14:30 today)                             │
│ ┌────────────────────────────────────────────────────────────────────────────────┐   │
│ │ Article ID │ Title              │ Status   │ Notes                              │   │
│ ├────────────┼────────────────────┼──────────┼────────────────────────────────────┤   │
│ │ 88421      │ Bio-Shirt Unisex   │ ✓ updated│ —                                  │   │
│ │ 88419      │ Beanie             │ ⚠ partial│ image sideload timeout              │   │
│ │ 88418      │ Mug Premium        │ ✗ error  │ Design missing in SC dashboard      │   │
│ │ … (paginated, expandable)                                                        │   │
│ └────────────────────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① `sync_now_button`: schedules `spreadconnect/sync_catalog`; disabled while job is `pending` or `in-progress`
- ② Sync-Settings collapsible: image-pull toggle, low-stock threshold input, force-re-pull-images toggle (one-shot per run)
- ③ "During Sync" panel — visible only while job state is `pending` or `in-progress`
- ④ `sync_progress_bar`: AJAX-poll every 3s of `/wp-json/spreadconnect/v1/sync-progress`, shows X/Y processed
- ⑤ Live counters: created/updated/skipped/error + ETA (estimated)
- ⑥ Live Log Tail: last 20 entries, auto-scroll, color-coded (✓/⚠/✗)
- ⑦ Sync History table — sourced from `wp_spreadconnect_sync_history`
- ⑧ Per-article expansion (click ▶) — paginated detail rows from `details` JSON column
- ⑨ `[✗ Cancel sync]` — visible only while job is `pending` or `in-progress`. Click opens confirm dialog (see state variations); on confirm calls `as_unschedule_action()` for queued + remaining per-article actions. In-flight worker finishes its current article. Sync history row records `canceled` with processed-vs-skipped counts.

### State Variations

| State | Visual Change |
|-------|---------------|
| `idle` (no current job) | Panel ③ hidden; ① "Sync now" enabled; ⑦ shows past runs |
| `pending` (queued) | Panel ③ visible: "Queued — waiting for worker"; ④ replaced with spinner; ① disabled; ⑨ visible & enabled |
| `in-progress` | Layout shown above; ⑨ visible & enabled |
| `cancel_confirm_open` | Modal: "Stop catalog sync? Articles already processed (84) will remain. Remaining 58 articles will be skipped. The job cannot be resumed — you will need to start a new sync."  [Continue sync] [Stop sync] |
| `canceling` | Panel ③: progress bar frozen at last value, status "Canceling…"; ⑨ disabled with spinner; in-flight worker finishes its article |
| `complete` | Top of ⑦ shows new completed row in green; brief inline success banner above ① for ~5s |
| `failed` (job-level) | New row in ⑦ with red status + link "View errors → Failed Ops" |
| `canceled` | Row in ⑦ with grey "Canceled" status, processed-N / skipped-M counts; ① re-enabled |
| `no_history_yet` | ⑦ shows empty-state: "No sync runs yet — click 'Sync now' to start" |

---

## Screen 3: Orders (Hub Sub-Page)

**Context:** Reached via Sidebar → "Orders". Plugin-side cross-reference of WC-orders that have been or should be sent to Spreadconnect, filterable by SC-state. Distinct from the native WC-Orders list (Screen 12), this view is SC-scoped.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Orders                                                               │ │
│ │ Catalog     │  ┌─ ① Filter Bar ───────────────────────────────────────────────────┐ │ │
│ │►Orders      │  │ Search SC-OrderID:[__________]  SC-State:[All ▾]  Date:[7d ▾]   │ │ │
│ │ Webhooks    │  └──────────────────────────────────────────────────────────────────┘ │ │
│ │ Failed Ops  │                                                                       │ │
│ │ Logs        │  ┌─ ② Tabs ─────────────────────────────────────────────────────────┐ │ │
│ │ Settings    │  │ [All] [Needs Action ⚠ 2]                                         │ │ │
│ │ Subscriptns │  └──────────────────────────────────────────────────────────────────┘ │ │
│ │             │                                                                       │ │
│ │             │  ┌─ ③ Orders Table ─────────────────────────────────────────────────┐│ │
│ │             │  │ WC-#  │Customer       │SC-OrderID│SC-State    │Shipping │Tracking│││ │
│ │             │  ├───────┼───────────────┼──────────┼────────────┼─────────┼────────┤│ │
│ │             │  │#1083  │Anna M.        │—         │● FAILED    │—        │— [⑥]  │││ │
│ │             │  │#1082  │Berit S.       │SC-77194 ↗│● NEW       │—        │—       │││ │
│ │             │  │#1081  │Carla K.       │SC-77191 ↗│● CONFIRMED │STANDARD │—       │││ │
│ │             │  │#1080  │Dieter R.      │SC-77187 ↗│● PROCESSED │STANDARD │TR-…  ↗│││ │
│ │             │  │#1079  │Erika P.       │SC-77185 ↗│● PROCESSED │EXPRESS  │TR-…  ↗│││ │
│ │             │  │#1078  │Frank H.       │SC-77180 ↗│● CANCELLED │PREMIUM  │—       │││ │
│ │             │  │#1077  │Gisela T.      │SC-77175 ↗│⚠ NEEDS ACT.│STANDARD │—       │││ │
│ │             │  │  …                                                              │││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │  ④ ●NEW ●CONFIRMED ●PROCESSED ●CANCELLED ●FAILED ⚠NEEDS-ACTION       │ │
│ │             │                                                                       │ │
│ │             │  ⑤ Row hover → Action menu opens:                                     │ │
│ │             │     [View WC-Order] [View in SC ↗] [Refresh State] [Resend (failed)]  │ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Filter bar — search SC-OrderID, dropdown SC-State (NEW/CONFIRMED/PROCESSED/CANCELLED/FAILED/NEEDS-ACTION/All), date-range
- ② Tabs: "All" + secondary "Needs Action" with persistent counter badge
- ③ Orders table — SC-OrderID column links externally to SC-backend (↗); WC-# links to WC Order-Edit (Screen 11)
- ④ Legend for SC-State color badges (matches Order State machine in Discovery)
- ⑤ Row Action Menu (hover or row-action affordance) — Resend visible only for `failed`
- ⑥ Resend affordance for FAILED rows = same `resend_button` as Failed-Ops list

### State Variations

| State | Visual Change |
|-------|---------------|
| `loading` | Table replaced with skeleton rows (5x) |
| `empty` | Table replaced with: "No orders submitted to Spreadconnect yet." |
| `needs_action_tab_active` | ② Active tab "Needs Action"; ③ filtered to rows with `needs_action` flag; rows highlighted yellow |
| `filter_no_results` | "No orders match the current filters. [Clear filters]" |

---

## Screen 4: Webhook Event Log (Hub Sub-Page)

**Context:** Reached via Sidebar → "Webhooks". Read-only audit trail of all incoming webhook events (`Article.*`, `Order.*`, `Shipment.sent`) with HMAC verification result and processing outcome. Backed by `wp_spreadconnect_webhook_log`.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Webhook Event Log                                                    │ │
│ │ Catalog     │  ┌─ ① Filter Bar ───────────────────────────────────────────────────┐ │ │
│ │ Orders      │  │ Event: [All ▾]  Date: [Last 7 days ▾]  HMAC: [All ▾]            │ │ │
│ │►Webhooks    │  └──────────────────────────────────────────────────────────────────┘ │ │
│ │ Failed Ops  │                                                                       │ │
│ │ Logs        │  ② Retention: 90 days (configurable in Settings)                      │ │
│ │ Settings    │                                                                       │ │
│ │ Subscriptns │  ┌─ ③ Event Table ──────────────────────────────────────────────────┐│ │
│ │             │  │ Received    │ Event-Type      │ Entity        │ HMAC│Proc │Action│││ │
│ │             │  ├─────────────┼─────────────────┼───────────────┼─────┼─────┼──────┤│ │
│ │             │  │14:33:08 ▶ ④ │Shipment.sent    │Order SC-77187↗│  ✓  │ ✓ ok│      │││ │
│ │             │  │14:31:55 ▶   │Article.updated  │Article 88421 ↗│  ✓  │ ✓ ok│      │││ │
│ │             │  │14:28:12 ▶   │Order.processed  │Order SC-77185↗│  ✓  │ ✓ ok│      │││ │
│ │             │  │14:14:01 ▶   │Article.added    │Article 88500 ↗│  ✓  │ ✓ ok│      │││ │
│ │             │  │13:55:40 ▶   │Order.cancelled  │Order SC-77180↗│  ✓  │ ⚠err│Retry │││ │
│ │             │  │13:42:18 ▶   │Order.needs-act. │Order SC-77175↗│  ✓  │ ✓ ok│      │││ │
│ │             │  │13:18:09 ▶   │(unknown)        │—              │  ✗  │skip │      │││ │
│ │             │  │  …          │                 │               │     │     │      │││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ⑤ Click ▶ to expand row                                              │ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑥ Expanded Row ─────────────────────────────────────────────────┐│ │
│ │             │  │ Event Detail — Shipment.sent (received 14:33:08)                 ││ │
│ │             │  │ ┌──────────────────────────────────────────────────────────────┐ ││ │
│ │             │  │ │ Full Payload (JSON):                                         │ ││ │
│ │             │  │ │ {                                                            │ ││ │
│ │             │  │ │   "eventType": "Shipment.sent",                              │ ││ │
│ │             │  │ │   "eventId":   "evt_abc123…",                                │ ││ │
│ │             │  │ │   "orderReference": "SC-77187",                              │ ││ │
│ │             │  │ │   "shipment": { "trackingNumber": "TR-…", … }                │ ││ │
│ │             │  │ │ }                                                            │ ││ │
│ │             │  │ └──────────────────────────────────────────────────────────────┘ ││ │
│ │             │  │ Processing log:                                                  ││ │
│ │             │  │   • 14:33:08 received (HMAC valid)                               ││ │
│ │             │  │   • 14:33:08 scheduled fetch_tracking action                     ││ │
│ │             │  │   • 14:33:09 GET /orders/SC-77187/shipments → 200 OK            ││ │
│ │             │  │   • 14:33:09 wrote tracking-number to WC-Order #1080            ││ │
│ │             │  │   • 14:33:09 set WC-Order status = completed                    ││ │
│ │             │  │ Source IP: 35.157.x.x                                            ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Filter bar — Event-Type (7 events + "All"), Date-Range, HMAC-Status (valid/invalid/all)
- ② Retention info — readable hint that older entries are auto-purged
- ③ Event table — `webhook_log_row` per row; entity column links externally to SC-backend (↗) or internally to WC-Order
- ④ HMAC column shows ✓ valid / ✗ invalid (constant-time-compared); Processing column shows ✓ ok / ⚠ err / skip
- ⑤ Inline ▶ chevron to expand row in-place
- ⑥ Expanded view — full JSON payload (read-only, monospace), processing log timeline, source IP

### State Variations

| State | Visual Change |
|-------|---------------|
| `loading` | Skeleton rows |
| `empty` | "No webhook events received yet — confirm that subscriptions are active in [Subscriptions]" |
| `hmac_failed_row` | Row tinted red, HMAC ✗, no payload shown in expanded view (security: only IP + headers logged) |
| `processing_error_row` | Processing column "⚠ err" with truncated error; expanded view shows full stack |
| `unknown_event_row` | Event-Type "(unknown)", Entity "—", row dimmed |
| `filter_no_results` | "No events match current filters. [Clear filters]" |

---

## Screen 5: Failed Operations (Hub Sub-Page)

**Context:** Reached via Sidebar → "Failed Operations". Permanent dead-letter view of operations that failed after 3 retries. Sourced from `wp_spreadconnect_failed_ops`. Primary recovery surface for the admin.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Failed Operations  [⚠ 3 unresolved]                                  │ │
│ │ Catalog     │                                                                       │ │
│ │ Orders      │  ┌─ ① Bulk Action Bar ───────────────────────────────────────────────┐│ │
│ │ Webhooks    │  │ [✓] Select all   Bulk: [Resend Selected ▾] [Apply]                ││ │
│ │►Failed Ops  │  │                  └ Dismiss Selected                                ││ │
│ │ Logs        │  └────────────────────────────────────────────────────────────────────┘│ │
│ │ Settings    │                                                                       │ │
│ │ Subscriptns │  ┌─ ② Failed Operations Table ──────────────────────────────────────┐│ │
│ │             │  │ ☐ │Failed-At │Op-Type      │Entity        │Error (truncated) │Try│Action││ │
│ │             │  ├───┼──────────┼─────────────┼──────────────┼──────────────────┼───┼──────┤│ │
│ │             │  │ ☐ │14:32 ▶ ③ │create_order │WC-#1083     │HTTP 422 Article …│ 3 │④[Resend][Detail][Dismiss]││ │
│ │             │  │ ☐ │13:21 ▶   │sync_article │Article 88500 │Network timeout   │ 3 │ [Resend][Detail][Dismiss]││ │
│ │             │  │ ☐ │11:08 ▶   │handle_webhook│evt_abc      │JSON parse error  │ 3 │ [Resend][Detail][Dismiss]││ │
│ │             │  └────────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ⑤ Click ▶ to expand row                                              │ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑥ Expanded Row ─────────────────────────────────────────────────┐│ │
│ │             │  │ Operation Detail — create_order (failed 14:32)                   ││ │
│ │             │  │ Related: WC-Order #1083 [Open WC Order ↗]                        ││ │
│ │             │  │                                                                   ││ │
│ │             │  │ Original Payload (JSON):                                          ││ │
│ │             │  │ ┌──────────────────────────────────────────────────────────────┐ ││ │
│ │             │  │ │ { "items":[{"sku":"…","quantity":1}], "shipping":{…} }       │ ││ │
│ │             │  │ └──────────────────────────────────────────────────────────────┘ ││ │
│ │             │  │                                                                   ││ │
│ │             │  │ Per-Retry Log:                                                    ││ │
│ │             │  │   • 14:30:11 attempt 1 → HTTP 502 Bad Gateway (transient)        ││ │
│ │             │  │   • 14:30:12 attempt 2 (after 1m) → HTTP 502                     ││ │
│ │             │  │   • 14:31:13 attempt 3 (after 5m) → HTTP 422 Unprocessable Entity ││ │
│ │             │  │   • 14:31:13 PERMANENT FAILURE → moved to failed_ops table       ││ │
│ │             │  │                                                                   ││ │
│ │             │  │ Full error trace:                                                 ││ │
│ │             │  │ ┌──────────────────────────────────────────────────────────────┐ ││ │
│ │             │  │ │ { "code": "ARTICLE_NOT_FOUND",                                │ ││ │
│ │             │  │ │   "message": "Article 88500 not found in catalog",            │ ││ │
│ │             │  │ │   "details": [...] }                                          │ ││ │
│ │             │  │ └──────────────────────────────────────────────────────────────┘ ││ │
│ │             │  │                                                                   ││ │
│ │             │  │ [Resend now]   [Dismiss]                                          ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Bulk-Action Bar — multi-select Resend or Dismiss; **Bulk Dismiss is refused if any selected row has op_type = `create_order`** (see state variation `bulk_dismiss_blocked`); admin must resolve those individually.
- ② Failed-Ops table — one row per `wp_spreadconnect_failed_ops` entry in `unresolved` state
- ③ Inline ▶ to expand row
- ④ Per-row actions: `failed_op_resend` ([Resend]), [Detail] (= expand), [Dismiss]. **Dismiss semantics depend on op_type** (per Discovery → Failure Handling business rule):
   - `create_order` rows → [Dismiss] opens **Resolve Order modal** (3-choice, see state variation `dismiss_modal_create_order_open`); plain dismiss is not available (the WC order would be orphaned).
   - `confirm_order` / `cancel_order` / `set_shipping` → plain Dismiss with confirm dialog "Dismiss this operation? SC state remains unchanged."
   - `sync_article` / `handle_article_removed` / `handle_webhook` → plain Dismiss without dialog; row removed.
- ⑤ Expansion — full payload, per-retry log timeline, full error trace
- ⑥ Detail panel actions — same Resend/Dismiss buttons (with same op-type-dependent behavior), also accessible while expanded

### State Variations

| State | Visual Change |
|-------|---------------|
| `empty` (no failures) | Whole table replaced with green panel: "No failed operations — all systems running smoothly." |
| `resending` | Row shows spinner in Action column; Resend button disabled until success/failure response |
| `resolved` | Row removed from list with brief inline confirmation banner "Operation resent successfully" (auto-dismisses 4s) |
| `dismissed` | Row removed; banner "Operation dismissed (logged)" |
| `dismiss_modal_create_order_open` | Modal blocking the page (see ASCII below) — admin must choose 1 of 3 resolutions before the row can be removed |
| `dismiss_resolve_manual_input` | Within the modal: "Submitted manually" branch reveals a text input for the external SC-OrderID (validated as non-empty) before [Confirm Resolution] is enabled |
| `bulk_dismiss_blocked` | Bulk-Dismiss aborted with banner: "X create_order entries require explicit resolution — open them individually." Selection retained; only `create_order` rows highlighted |
| `loading` | Skeleton rows |
| `bulk_action_in_progress` | Bulk action button shows spinner; per-row actions disabled until done |

#### `dismiss_modal_create_order_open` ASCII

```
┌─ Resolve order #1083 — no SC submission yet ────────────────────────────┐
│                                                                          │
│  This WC order was never submitted to Spreadconnect. Choose how to       │
│  resolve so the customer is not left without fulfillment:                │
│                                                                          │
│  (•) Resend now                                                          │
│       Re-queue the create_order action. Same payload, fresh attempt.     │
│                                                                          │
│  ( ) Cancel WC order                                                     │
│       Set WC order #1083 to "cancelled". Plugin does NOT auto-refund —  │
│       handle refund via WC native flow. [Refund docs ↗]                  │
│                                                                          │
│  ( ) Submitted manually outside plugin                                   │
│       I have created this order in the SC backend by hand.               │
│       External SC-OrderID: [_______________]  (required)                │
│       Plugin will record this ID, mark state NEW, add an order note.    │
│                                                                          │
│                                          [Cancel]   [Confirm Resolution] │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Screen 6: Logs (Hub Sub-Page)

**Context:** Reached via Sidebar → "Logs". Tail/filter view over the WC_Logger output (source = `spreadconnect-*`). Persisted via WC standard in `wc-logs/`. Read-only with CSV export.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Logs                                                                 │ │
│ │ Catalog     │  ┌─ ① Filter & Action Bar ──────────────────────────────────────────┐│ │
│ │ Orders      │  │ Level: [All ▾] Date: [Today ▾] Source: [All ▾]                  ││ │
│ │ Webhooks    │  │ ② Auto-refresh [✓] (3s)   [⬇ Download CSV]                       ││ │
│ │ Failed Ops  │  └──────────────────────────────────────────────────────────────────┘│ │
│ │►Logs        │                                                                       │ │
│ │ Settings    │  ③ Tail-View (last 200 entries — newest first):                       │ │
│ │ Subscriptns │  ┌──────────────────────────────────────────────────────────────────┐│ │
│ │             │  │ Time     │Lvl │Source            │Message                       ││ │
│ │             │  ├──────────┼────┼──────────────────┼──────────────────────────────┤│ │
│ │             │  │14:33:09  │INF │spreadconnect-api │GET /orders/SC-77187/shipments ││ │
│ │             │  │          │    │                  │  → 200 (245ms)               ││ │
│ │             │  │14:33:09  │INF │spreadconnect-ord.│Order #1080 → completed       ││ │
│ │             │  │14:33:08  │INF │spreadconnect-wh  │event Shipment.sent received  ││ │
│ │             │  │14:31:13  │ERR │spreadconnect-api │POST /orders → HTTP 422 (3/3) ││ │
│ │             │  │14:30:55  │WAR │spreadconnect-api │X-RateLimit-Remaining=4 — slp ││ │
│ │             │  │14:30:11  │INF │spreadconnect-sync│sync_article 88421 created    ││ │
│ │             │  │14:30:08  │DBG │spreadconnect-api │GET /articles?page=4 → 200    ││ │
│ │             │  │  …                                                              ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ④ Files persisted in wc-logs/ via WC_Logger (WC standard)            │ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Filter Bar: Level (debug/info/warning/error/All), Date-Range, Source dropdown (api-client/order-service/webhook-receiver/sync-job/All)
- ② Auto-refresh toggle (every 3s) + CSV download for current filter
- ③ Tail view — last 200 entries, newest first; level color-coded
- ④ Footnote: storage location is WC-standard `wc-logs/` folder, written by `WC_Logger`

### State Variations

| State | Visual Change |
|-------|---------------|
| `auto_refresh_off` | ② shows static state, no polling |
| `empty` | "No log entries match current filters." |
| `loading` | Skeleton rows |
| `download_in_progress` | CSV button shows spinner |

---

## Screen 7: Settings (Hub Sub-Page)

**Context:** Reached via Sidebar → "Settings". Single form with 5 grouped sections, save/import/export footer. Settings persist to `wp_options`.

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Settings                                                             │ │
│ │ Catalog     │                                                                       │ │
│ │ Orders      │  ┌─ ① API Connection ───────────────────────────────────────────────┐│ │
│ │ Webhooks    │  │ API Key:        [••••••••••••••8f2a]      [Show] [Edit]          ││ │
│ │ Failed Ops  │  │ Use Staging:    [ ] Off   (Production: api.spreadconnect.com)    ││ │
│ │ Logs        │  │                 [✓] On    (Staging:    staging.spreadconnect.com)││ │
│ │►Settings    │  │ ② [Test This Key]   Status: ✓ Valid — click Save to apply        ││ │
│ │ Subscriptns │  │     (Tests the value currently in the field, without saving)     ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ┌─ ③ Webhook Security ─────────────────────────────────────────────┐│ │
│ │             │  │ Webhook URL:    https://shop.example.com/wp-json/spreadconnect/  ││ │
│ │             │  │                 v1/webhook                              [⎘ Copy] ││ │
│ │             │  │ HMAC Secret:    ••••••••••••••••••••  ④ [Regenerate Secret]     ││ │
│ │             │  │                 (Last regenerated: 2026-04-12 09:14)             ││ │
│ │             │  │ ⑤ Hint: Regenerate will invalidate all existing subscriptions.   ││ │
│ │             │  │      System will auto-re-register them.                          ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑥ Order Behavior ───────────────────────────────────────────────┐│ │
│ │             │  │ ⑥a Default Shipping-Type:   ( ) None  ( ) STANDARD               ││ │
│ │             │  │                             (•) PREMIUM   ( ) EXPRESS            ││ │
│ │             │  │     (Pre-fills shipping-type on submit; per-order override       ││ │
│ │             │  │      remains in Order-Edit)                                      ││ │
│ │             │  │                                                                  ││ │
│ │             │  │ ⑥b Auto-Confirm:   ( ) Off   (Recommended)                       ││ │
│ │             │  │                    ( ) Immediately after submit                  ││ │
│ │             │  │                    (•) After [10] minutes                        ││ │
│ │             │  │     (Enabled because Default Shipping-Type is set)               ││ │
│ │             │  │                                                                  ││ │
│ │             │  │ Auto-Cancel-Mirror: [✓] On — WC cancel triggers SC cancel        ││ │
│ │             │  │                            (only when SC state = NEW)            ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑦ Catalog Sync ─────────────────────────────────────────────────┐│ │
│ │             │  │ Pull Images on Sync:    [✓] On                                   ││ │
│ │             │  │ Periodic Stock-Sync:    [6h ▾]                                   ││ │
│ │             │  │ Low-Stock Threshold:    [10  ] (units)                           ││ │
│ │             │  │ Live-Cache TTL:         [5min ▾]                                 ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑧ Failure Notifications ────────────────────────────────────────┐│ │
│ │             │  │ Recipients (comma-sep):                                          ││ │
│ │             │  │ ┌──────────────────────────────────────────────────────────────┐ ││ │
│ │             │  │ │ admin@example.com, ops@example.com                           │ ││ │
│ │             │  │ └──────────────────────────────────────────────────────────────┘ ││ │
│ │             │  │ Send notification on:                                            ││ │
│ │             │  │   [✓] Order Failure   [✓] Sync Failure   [ ] Webhook Failure    ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │                                                                       │ │
│ │             │  ┌─ ⑨ Footer Actions ───────────────────────────────────────────────┐│ │
│ │             │  │ [Save Changes]   [Export Settings JSON]   [Import Settings JSON] ││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① API Connection section — masked API key with [Show]/[Edit], Staging toggle
- ② `[Test This Key]` — tests the **current input value** without saving (so the admin can paste a new key and verify before saving). Inline status appears immediately to the right; on success label "Valid — click Save to apply", on failure red "Invalid Key — check value or environment".
- ③ Webhook Security section — read-only Webhook URL with [Copy], masked HMAC Secret, regeneration timestamp
- ④ `regenerate_secret_button` — opens confirm dialog (see state variations); on confirm POSTs regenerate, shows new secret once
- ⑤ Inline hint about consequences of regeneration (auto re-subscribe explained)
- ⑥a Default Shipping-Type radio (None/STANDARD/PREMIUM/EXPRESS); when not None it is auto-applied at `create_order` time
- ⑥b Auto-Confirm radio — **gated**: disabled & visually dimmed unless ⑥a ≠ None (see state variation `auto_confirm_locked`)
- ⑦ Catalog Sync — image-pull toggle, stock-sync interval, low-stock threshold, cache TTL
- ⑧ Failure Notifications — recipient list + per-event-type checkboxes
- ⑨ Footer — Save / Export / Import settings JSON

### State Variations

| State | Visual Change |
|-------|---------------|
| `unconfigured` | API Key field empty, Status "Not configured", section ③ hidden until ① has been saved successfully; sections ⑥⑦⑧ visible but inactive |
| `test_key_invalid` | Inline status next to ② red "Invalid Key — check value or environment"; ① fields stay editable so admin can fix and re-test |
| `auth_failed` (post-save) | Status next to ② shows red `Invalid Key`, Save button highlights API Key field |
| `auto_confirm_locked` | ⑥a = None → ⑥b radio rendered greyed out; inline note under ⑥b: "Set a default shipping type to enable auto-confirm." |
| `regenerate_dialog_open` | Modal: "Regenerate HMAC Secret? This will invalidate existing subscriptions and they will be re-registered automatically." [Cancel] [Confirm Regenerate] |
| `regenerate_success` | Inline panel below ③ with the new secret in monospace + warning "This will only be shown once. Copy now." [⎘ Copy] [Done — I have copied it] |
| `initial_secret_reveal` | After **first** successful Save: inline panel above ③ with newly generated HMAC secret in monospace + warning "First-time setup — this will only be shown once. Copy now." [⎘ Copy] [Done — I have copied it]. The Done acknowledgement permanently locks the panel; subsequent reveals only available via `regenerate_success`. |
| `save_in_progress` | [Save Changes] shows spinner, all inputs disabled |
| `save_success_panel` | Stepwise result panel above ⑨ summarizes side-effects of the Save (see ASCII below). Dominates the page until acknowledged. Contains the `initial_secret_reveal` panel inline as one of its rows when first-time setup. |
| `save_partial_error` | Stepwise result panel shown with ⚠ on the failing step (e.g. "4 of 7 subscriptions registered"); offers [Open Subscriptions Manager →] |
| `save_error` | Inline error panel above ⑨ with field-level error markers |
| `staging_active` | ① Staging Toggle on; visible "Staging mode" banner at top of page; Dev-Tools simulate-buttons appear in a new "Developer Tools" section above ⑨ (only in Staging) |
| `import_dialog_open` | File picker modal "Import Settings JSON"; preview of changes before apply |

#### `save_success_panel` ASCII

Replaces the area above ⑨ until [Acknowledge] is clicked.

```
┌─ Settings saved — initial setup complete ────────────────────────────────┐
│                                                                          │
│  ✓  API connection verified                                              │
│  ✓  HMAC secret generated  (see panel below — copy now)                  │
│  ✓  Subscriptions registered (7 of 7)                                    │
│                                                                          │
│  ┌─ HMAC Secret (initial reveal — shown once) ─────────────────────────┐ │
│  │ AbCdEf1234567890==…== (monospace, full secret)         [⎘ Copy]    │ │
│  │ ⚠ This will only be shown once. Copy and paste into your SC        │ │
│  │   webhook subscription configuration.                              │ │
│  │                                                          [Done]    │ │
│  └──────────────────────────────────────────────────────────────────────┘│
│                                                                          │
│  Next: visit [Subscriptions Manager →] to verify webhook reception.      │
│                                                                          │
│                                                       [Acknowledge]      │
└──────────────────────────────────────────────────────────────────────────┘
```

#### `save_partial_error` variant of the panel

```
┌─ Settings saved with warnings ───────────────────────────────────────────┐
│  ✓  API connection verified                                              │
│  ✓  HMAC secret generated   [Copy] [Done]                                │
│  ⚠  Subscriptions: 4 of 7 registered (3 failed)                          │
│        ↪ Article.removed   ✗ HTTP 503                                    │
│        ↪ Order.processed   ✗ HTTP 503                                    │
│        ↪ Shipment.sent     ✗ HTTP 503                                    │
│       [Open Subscriptions Manager →]                                     │
│                                                                          │
│                                                       [Acknowledge]      │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Screen 8: Subscriptions Manager (Hub Sub-Page)

**Context:** Reached via Sidebar → "Subscriptions". Compares the locally expected 7 webhook events against `GET /subscriptions` on Spreadconnect. Repair button reconciles in both directions (POST missing, DELETE only orphans on our URL).

### Wireframe

```
┌─ [WP Admin chrome + Hub Header] ────────────────────────────────────────────────────────┐
│ ┌─────────────┬───────────────────────────────────────────────────────────────────────┐ │
│ │ Dashboard   │  Subscriptions Manager                                                │ │
│ │ Catalog     │                                                                       │ │
│ │ Orders      │  ┌─ ① Action Bar ───────────────────────────────────────────────────┐│ │
│ │ Webhooks    │  │ [Repair All]   [Refresh from SC]    Last check: 14:30 today      ││ │
│ │ Failed Ops  │  └──────────────────────────────────────────────────────────────────┘│ │
│ │ Logs        │                                                                       │ │
│ │ Settings    │  ┌─ ② Subscription Table (7 rows, one per event) ────────────────────┐│ │
│ │►Subscriptns │  │ Event-Type        │ Status   │ Subscription-ID │ Registered-URL  │││ │
│ │             │  ├───────────────────┼──────────┼─────────────────┼─────────────────┤│ │
│ │             │  │ Article.added     │● active  │ sub_a1b2…       │…/wp-json/sprd…  │││ │
│ │             │  │ Article.updated   │● active  │ sub_a1b3…       │…/wp-json/sprd…  │││ │
│ │             │  │ Article.removed   │● active  │ sub_a1b4…       │…/wp-json/sprd…  │││ │
│ │             │  │ Order.processed   │● active  │ sub_a1b5…       │…/wp-json/sprd…  │││ │
│ │             │  │ Order.cancelled   │● active  │ sub_a1b6…       │…/wp-json/sprd…  │││ │
│ │             │  │ Order.needs-act.  │○ missing │ —               │ —               │││ │
│ │             │  │                   │          │                 │   ④ [Repair]    │││ │
│ │             │  │ Shipment.sent     │△ orphan  │ sub_old99…      │…/old-url/wbk…   │││ │
│ │             │  │                   │          │                 │   ④ [Repair]    │││ │
│ │             │  └──────────────────────────────────────────────────────────────────┘│ │
│ │             │  ③ ●active   ○missing   △orphan (registered, but URL ≠ ours)         │ │
│ │             │                                                                       │ │
│ │             │  ⑤ Note: Repair will POST missing & DELETE orphans — only orphans     │ │
│ │             │     pointing to our domain are deleted; foreign URLs are preserved.   │ │
│ └─────────────┴───────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Action Bar: `repair_subscriptions_button` (bulk reconcile) + Refresh-from-SC + last-check timestamp
- ② Subscription table — exactly 7 rows (one per declared event); status color-coded
- ③ `subscription_status_badge` legend
- ④ Per-row [Repair] action — only shown for `missing` and `orphan` rows
- ⑤ Inline policy hint — explains what "Repair All" will do (POST missing, DELETE only orphans on our URL)

### State Variations

| State | Visual Change |
|-------|---------------|
| `loading` (initial) | All rows show grey `?` (`unknown`) while `GET /subscriptions` runs |
| `all_active` | All 7 rows green ●, ① bar shows "✓ All subscriptions active" inline; [Repair All] disabled |
| `repair_in_progress` | ① [Repair All] shows spinner; per-row [Repair] disabled; status column → spinner |
| `repair_success` | Brief inline banner "Subscriptions repaired (3 added, 1 deleted)"; table reloads green |
| `repair_partial_error` | Banner "Some operations failed. See [Failed Operations]"; failed rows keep prior status |
| `network_error` | All rows `?`; banner red "Cannot reach Spreadconnect" + [Retry] |

---

## Screen 9: Product-Edit Meta-Box (Inline-Erweiterung)

**Context:** Sidebar meta-box on `wp-admin/post.php?post={id}&action=edit` for any WC product. Behavior differs based on whether the product is linked to a Spreadconnect article.

### Wireframe

```
┌─ [WP Admin Edit-Product Page] ──────────────────────────────────────────────────────────┐
│ ╔════════════════════════════════════════╗  ┌──────────────────────────────────────┐    │
│ ║ Title: Bio-Shirt Unisex                ║  │ Publish [Save Draft] [Update]        │    │
│ ║                                        ║  └──────────────────────────────────────┘    │
│ ║ [Editor / WC Product Data tabs …]      ║  ┌──────────────────────────────────────┐    │
│ ║ Regular Price: 29.90 €  Sale: ___      ║  │ Categories                           │    │
│ ║                                        ║  └──────────────────────────────────────┘    │
│ ║ [Variations] (size × color)            ║                                              │
│ ║                                        ║  ┌─ ① Spreadconnect ────────────────────┐    │
│ ║                                        ║  │                                      │    │
│ ║                                        ║  │ ② Article-ID: 88421     [Unlink]     │    │
│ ║                                        ║  │     (linked since 2026-04-01)        │    │
│ ║                                        ║  │                                      │    │
│ ║                                        ║  │ ③ Cost & Margin                      │    │
│ ║                                        ║  │   SC Cost:    12.34 €                │    │
│ ║                                        ║  │   WC Price:   29.90 € (live)         │    │
│ ║                                        ║  │   ④ Margin:   17.56 € (58.7%) ●green │    │
│ ║                                        ║  │                                      │    │
│ ║                                        ║  │ ⑤ Stock (cached 5min)                │    │
│ ║                                        ║  │   ┌──────────────────────────────┐   │    │
│ ║                                        ║  │   │ SKU         │ Stock         │   │    │
│ ║                                        ║  │   ├─────────────┼───────────────┤   │    │
│ ║                                        ║  │   │ TS-S-RED    │ ● Available   │   │    │
│ ║                                        ║  │   │ TS-M-RED    │ ● Available   │   │    │
│ ║                                        ║  │   │ TS-L-RED    │ ⚠ Low (4)     │   │    │
│ ║                                        ║  │   │ TS-S-BLUE   │ ✗ Out         │   │    │
│ ║                                        ║  │   │ … (15 vars) │               │   │    │
│ ║                                        ║  │   └──────────────────────────────┘   │    │
│ ║                                        ║  │   Last refresh: 14:28  [⟳ Refresh]   │    │
│ ║                                        ║  │                                      │    │
│ ║                                        ║  │ ⑥ Sync Status                        │    │
│ ║                                        ║  │   Last sync: 2h ago                  │    │
│ ║                                        ║  │   State: ✓ synced                    │    │
│ ║                                        ║  │   [Re-Sync now]   [View Sync Detail] │    │
│ ║                                        ║  └──────────────────────────────────────┘    │
│ ╚════════════════════════════════════════╝                                              │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① Spreadconnect meta-box (sidebar position, collapsible like other WP meta-boxes)
- ② Article-ID display + Unlink action (when linked); Manual link UI (when unlinked, see state variation)
- ③ Cost & Margin block — only when linked
- ④ `margin_display`: live JS-recalculated when "Regular Price" or "Sale Price" form fields change; color: red <20% / yellow 20–40% / green >40%
- ⑤ Per-variation `live_stock_indicator` — cached 5min, ⟳ Refresh forces `GET /stock/{sku}` per variation
- ⑥ Sync Status block — last sync timestamp, sync_state badge, [Re-Sync now] schedules `spreadconnect/sync_article {articleId}`

### State Variations

| State | Visual Change |
|-------|---------------|
| `unlinked` | Replace ②③⑤⑥ with the **Article Picker** (see ASCII below) — searchable dropdown over `GET /articles` results with thumbnail + title + ID |
| `picker_loading` | Picker shows skeleton rows while paginating `/articles?page=…`; "Loading articles…" hint |
| `picker_empty_search` | Empty search returns top 20 most recent SC articles; with search term: filtered list |
| `picker_no_results` | "No articles match your search. [Open SC dashboard ↗]" |
| `picker_link_in_progress` | After clicking an item: spinner inline + "Linking…" |
| `picker_link_error` | Inline red error "Could not link — article may have been removed in Spreadconnect. [Try again]" |
| `synced` | Layout shown above (default linked state) |
| `sync_partial` | ⑥ State badge yellow "Partial — image sideload failed"; [Re-Sync] highlighted |
| `sync_error` | ⑥ State badge red "Sync error"; tooltip with error message; [View Sync Detail] expanded |
| `removed_in_sc` | Banner at top of meta-box: "⚠ Article removed in Spreadconnect — WC product set to draft"; ⑤⑥ greyed; [Re-Sync (re-create)] action |
| `stock_loading` | ⑤ rows show spinner instead of stock badge |
| `margin_low` | ④ red, with inline warning "Margin below 20% — review pricing" |
| `price_being_edited` | ④ pulses subtly while live-recalc runs (JS) |

#### `unlinked` — Article Picker ASCII

```
┌─ Spreadconnect ────────────────────────────────────────┐
│                                                        │
│  Not linked to a Spreadconnect article.                │
│                                                        │
│  ┌─ Find article in your SC catalog ────────────────┐  │
│  │ [🔍 Search by title or ID …                    ] │  │
│  │                                                   │  │
│  │ ┌─[88421] Bio-Shirt Unisex ──────────────────────┐│  │
│  │ │ [img]  Bio-Shirt Unisex                        ││  │
│  │ │  64×64  Article-ID: 88421 · 5 sizes × 4 colors ││  │
│  │ │         [Link this article]                    ││  │
│  │ └────────────────────────────────────────────────┘│  │
│  │ ┌─[88420] Hoodie Damen ──────────────────────────┐│  │
│  │ │ [img]  Hoodie Damen                            ││  │
│  │ │  64×64  Article-ID: 88420 · 4 sizes × 3 colors ││  │
│  │ │         [Link this article]                    ││  │
│  │ └────────────────────────────────────────────────┘│  │
│  │ ┌─[88417] Tasche Premium ────────────────────────┐│  │
│  │ │ [img]  Tasche Premium                          ││  │
│  │ │  64×64  Article-ID: 88417 · 1 size × 2 colors  ││  │
│  │ │         [Link this article]                    ││  │
│  │ └────────────────────────────────────────────────┘│  │
│  │  …                                                │  │
│  │  Showing 20 of 142     [Load more]                │  │
│  └───────────────────────────────────────────────────┘  │
│                                                        │
│  Can't find it? [Open SC dashboard ↗]                  │
│                                                        │
└────────────────────────────────────────────────────────┘
```

**Picker behavior:**
- Search input debounced (300ms); empty state shows 20 most recent articles via `GET /articles?page=0&size=20`
- With query: `GET /articles?search=…` if API supports server-side search; otherwise client-side filter on already-paginated list
- Each result shows thumbnail (from article preview), title, Article-ID, variation summary (sizes × colors)
- Clicking [Link this article] writes `_spreadconnect_article_id` and triggers an immediate `spreadconnect/sync_article {articleId}` schedule; meta-box switches to `synced` state on completion
- "Open SC dashboard ↗" links to the SC backend Articles page (external)

---

## Screen 10: Product-List Spalten (Inline-Erweiterung)

**Context:** Native WC Products list page (`wp-admin/edit.php?post_type=product`). Plugin adds 3 columns + 1 filter dropdown without changing the WC chrome.

### Wireframe

```
┌─ [WP Admin → Products] ─────────────────────────────────────────────────────────────────┐
│ Products  [Add New]                                                                     │
│                                                                                         │
│ ① Filter Bar (WC native + plugin additions):                                            │
│ [All categories ▾]  [All product types ▾]  [Stock status ▾]  [SC: All ▾ ②]  [Filter]   │
│                                                                                         │
│ Bulk: [Bulk actions ▾] [Apply]                                                          │
│ ┌──────┬──────────────────┬────┬────┬───┬────────┬─────────┬───────────┬────────┬─────┐│
│ │ ☐    │ Name             │SKU │Stk │Pri│ ③SC-Lk │ ④SC-Cost│ ⑤Margin   │ Cat.   │Date ││
│ ├──────┼──────────────────┼────┼────┼───┼────────┼─────────┼───────────┼────────┼─────┤│
│ │ ☐ □  │ Bio-Shirt Unisex │ —  │∞   │29.│ ✓      │ 12.34 € │ 17.56 € ● │ Shirts │ Apr ││
│ │      │                  │    │    │90 │        │         │ (58.7% gn)│        │     ││
│ │ ☐ □  │ Hoodie Damen     │ —  │∞   │54.│ ✓      │ 28.80 € │ 25.40 € ● │ Hoodies│ Apr ││
│ │      │                  │    │    │20 │        │         │ (46.9% gn)│        │     ││
│ │ ☐ □  │ Beanie           │ —  │∞   │14.│ ✓      │ 8.10 €  │ 5.80 €  ● │ Hats   │ Apr ││
│ │      │                  │    │    │90 │        │         │ ⑥(38.9%yl)│        │     ││
│ │ ☐ □  │ Mug Premium      │ —  │∞   │12.│ ✓      │ 11.20 € │ 0.80 €  ● │ Mugs   │ Apr ││
│ │      │                  │    │    │00 │        │         │ ⑦(6.7% rd)│        │     ││
│ │ ☐ □  │ Custom T-Shirt   │ —  │∞   │25.│ —      │   —     │   —       │ Shirts │ Mar ││
│ │      │                  │    │    │00 │        │         │           │        │     ││
│ │ …                                                                                    ││
│ └──────────────────────────────────────────────────────────────────────────────────────┘│
│                                                                                         │
│ ⑧ Click Column-Header on SC-Linked, SC-Cost, or Margin → sort                           │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① WC native filter row, plugin appends ② only
- ② Filter dropdown "Spreadconnect-Status": All / Linked / Unlinked / Margin <20%
- ③ Column "SC-Linked" — ✓ (linked) / — (unlinked); sortable
- ④ Column "SC-Cost" — formatted currency from `_spreadconnect_cost`
- ⑤ `margin_display` (list variant) — value + percent + colored dot
- ⑥ Yellow margin example (20–40%)
- ⑦ Red margin example (<20%) — same as `low_margin_notice_in_list`
- ⑧ Columns are sortable (uses standard WP `manage_edit-product_sortable_columns`)

### State Variations

| State | Visual Change |
|-------|---------------|
| `mixed_linked_unlinked` | Layout shown above (typical) |
| `filter_unlinked` | Only rows with `—` in ③ shown |
| `filter_low_margin` | Only rows with margin <20% shown |
| `loading` | Standard WP-list skeleton |
| `no_products` | WP standard "No products found" |
| `sort_by_margin_asc` | Lowest margins first; red rows surface to top |

---

## Screen 11: Order-Edit Meta-Box (Inline-Erweiterung)

**Context:** Sidebar meta-box on Order-Edit page (HPOS path: `wp-admin/admin.php?page=wc-orders&action=edit&id={id}`; Legacy path: `post.php?post={id}&action=edit`). Visible on every WC order.

### Wireframe

```
┌─ [WP Admin → WooCommerce → Orders → Edit Order #1082] ──────────────────────────────────┐
│ ⚠ ① Order #1083 needs action in Spreadconnect [View] [Mark Resolved]                    │
│ ───────────────────────────────────────────────────────────────────────────────────────  │
│ Edit Order #1082                                                                         │
│                                                                                          │
│ ╔══════════════════════════════════════════╗  ┌─ ② Spreadconnect ──────────────────┐    │
│ ║ Order details (WC native)                ║  │                                    │    │
│ ║   General  Billing  Shipping             ║  │ ③ Order State                      │    │
│ ║                                          ║  │   SC-OrderID: SC-77194  [Open ↗]   │    │
│ ║ Items:                                   ║  │   State:  ● NEW                    │    │
│ ║  - Bio-Shirt M Red  ×1   29.90 €         ║  │   Last action: 14:14:01            │    │
│ ║  - Hoodie L Blue    ×1   54.20 €         ║  │   ④ [⟳ Refresh State]              │    │
│ ║                                          ║  │                                    │    │
│ ║ Notes:                                   ║  │ ⑤ Shipping                         │    │
│ ║  - 14:14 Submitted to Spreadconnect      ║  │   Type: [Select… ▾]                │    │
│ ║          (#SC-77194)                     │   │     ┌──────────────────────────┐  │    │
│ ║  - 14:14 Order created                   ║  │     │ DHL Standard      4.99 € │  │    │
│ ║                                          ║  │     │ DHL Express      12.99 € │  │    │
│ ║                                          ║  │     │ DPD Premium       7.99 € │  │    │
│ ║                                          ║  │     └──────────────────────────┘  │    │
│ ║                                          ║  │     ↳ "No tracking provided…"      │    │
│ ║                                          ║  │     [Save Shipping-Type]           │    │
│ ║                                          ║  │                                    │    │
│ ║                                          ║  │ ⑥ Actions                          │    │
│ ║                                          ║  │   [⑦ Confirm Order]                │    │
│ ║                                          ║  │   [⑧ Cancel Order in SC]           │    │
│ ║                                          ║  │   [Resend to Spreadconnect]        │    │
│ ║                                          ║  │     (only when failed)             │    │
│ ║                                          ║  │                                    │    │
│ ║                                          ║  │ ⑨ Shipments (none yet)             │    │
│ ║                                          ║  │   — no shipments recorded —        │    │
│ ║                                          ║  │                                    │    │
│ ║                                          ║  │ ⑩ Webhook Activity (last 5)        │    │
│ ║                                          ║  │   • 14:14 Order.created (sched.)   │    │
│ ║                                          ║  │   • —                              │    │
│ ║                                          ║  │   • —                              │    │
│ ╚══════════════════════════════════════════╝  └────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① `failure_admin_notice` for `needs_action` events — persistent until "Mark Resolved"
- ② Spreadconnect meta-box (sidebar) — visible on every WC-Order edit page
- ③ Order State block — SC-OrderID + external link (↗) + state badge + last action timestamp
- ④ `refresh_state_button` — runs `GET /orders/{id}` and updates state in place
- ⑤ Shipping block — `shipping_type_dropdown` + Save action; values from `GET /orders/{id}/shippingTypes`. When the Settings-level `Default Shipping-Type` is set (≠ None), the dropdown is **pre-filled** with that value at order-creation time and the order is submitted with it; admin can still override here while state = NEW.
- ⑥ Actions block
- ⑦ `confirm_order_button` — only enabled when state = NEW AND shipping-type set
- ⑧ `cancel_order_button` — only enabled when state = NEW; opens confirm dialog
- ⑨ Shipments list — populated when `Shipment.sent` webhook received; tracking number + URL clickable
- ⑩ Webhook Activity — last 5 webhook events affecting this order (event-type + timestamp)

### State Variations

| State | Visual Change |
|-------|---------------|
| `submitting` | ③ State "Submitting…" with spinner; all action buttons disabled |
| `NEW` | Layout shown above |
| `NEW_no_shipping_set` | ⑦ disabled with tooltip "Set shipping type first"; ⑤ field highlighted |
| `NEW_default_shipping_prefilled` | ⑤ Type dropdown shows the global Default Shipping-Type pre-selected; inline note "Pre-filled from Default Shipping-Type setting"; admin can change before clicking [Save Shipping-Type]; ⑦ Confirm immediately enabled |
| `auto_confirm_pending` | When Auto-Confirm = "After X minutes" and timer is pending: state badge shows "NEW · auto-confirm in 7 min"; ⑦ disabled with hint "Auto-confirm scheduled — click [Cancel auto-confirm] below to abort"; new inline action [Cancel auto-confirm] which calls `as_unschedule_action('spreadconnect/confirm_order', [order_id])` |
| `auto_confirm_failed_no_shipping` | Persistent admin-notice on the order: "Auto-confirm could not run — no shipping type set." Order remains in NEW; ⑤ field highlighted; ⑦ enabled once shipping is set |
| `CONFIRMED` | State badge orange; ⑤ block disabled; ⑦⑧ hidden; ⑥ shows only [⟳ Refresh] |
| `PROCESSED` | State badge violet; ⑨ may have shipments; ⑦⑧ hidden |
| `CANCELLED` | State badge grey; all actions hidden except [⟳ Refresh State] |
| `failed_to_submit` | State badge red "FAILED"; inline error excerpt shown; ⑥ shows [Resend to Spreadconnect] prominent + [Open Failed-Ops Detail ↗] |
| `needs_action` | ① banner visible; state badge yellow ⚠; ⑩ shows the needs-action event highlighted; [View in SC ↗] action |
| `shipped` (Shipment.sent received) | ⑨ populated: state, tracking-number (link), tracking-URL (clickable), carrier; WC order auto set to `completed` (visible in left column) |
| `cancel_mirror_blocked` | When admin sets WC order to cancelled but SC state ≠ NEW → ① banner "Cannot cancel in Spreadconnect (state: CONFIRMED)" + corresponding order-note |

---

## Screen 12: Order-List Spalten + Bulk (Inline-Erweiterung)

**Context:** Native WC orders list page. HPOS-default path `wp-admin/admin.php?page=wc-orders` (and Legacy `edit.php?post_type=shop_order`). Plugin adds 2 columns + 1 filter + 1 bulk action via dual hook-set (HPOS + legacy).

### Wireframe

```
┌─ [WP Admin → WooCommerce → Orders] ─────────────────────────────────────────────────────┐
│ Orders  [Add Order]                                                                     │
│                                                                                         │
│ ① Filter Bar (WC native + plugin additions):                                            │
│ [Status ▾]  [Date ▾]  [Customer ▾]  [SC-State: All ▾ ②]  [Filter]                       │
│                                                                                         │
│ Bulk: [Bulk actions ▾ ⑦] [Apply]                                                        │
│ ┌────┬────────┬───────────────┬─────────┬─────────┬──────────┬────────────┬───────────┐│
│ │ ☐  │ Order  │ Customer      │ Total   │ Status  │ ③SC-State│ ④SC-OrderID│ Date      ││
│ ├────┼────────┼───────────────┼─────────┼─────────┼──────────┼────────────┼───────────┤│
│ │ ☐  │ #1083  │ Anna M.       │ 29.90 € │Processing│●FAILED   │   —        │ Today     ││
│ │ ☐  │ #1082  │ Berit S.      │ 84.10 € │Processing│●NEW      │ SC-77194 ↗ │ Today     ││
│ │ ☐  │ #1081  │ Carla K.      │ 29.90 € │Processing│●CONFIRMED│ SC-77191 ↗ │ Today     ││
│ │ ☐  │ #1080  │ Dieter R.     │ 54.20 € │Completed │●PROCESSED│ SC-77187 ↗ │ Yest      ││
│ │ ☐  │ #1079  │ Erika P.      │ 84.10 € │Completed │●PROCESSED│ SC-77185 ↗ │ Yest      ││
│ │ ☐  │ #1078  │ Frank H.      │ 29.90 € │Cancelled │●CANCELLED│ SC-77180 ↗ │ Yest      ││
│ │ ☐  │ #1077  │ Gisela T.     │ 12.00 € │Processing│⚠NEEDS-ACT│ SC-77175 ↗ │ Yest      ││
│ │ …                                                                                    ││
│ └──────────────────────────────────────────────────────────────────────────────────────┘│
│ ⑤ Click SC-State header → sort ; ⑥ Click SC-OrderID ↗ → opens SC backend in new tab     │
│                                                                                         │
│ ⑦ Bulk-Action menu adds: "Re-send to Spreadconnect" (sensible only for FAILED rows)     │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Annotations:**
- ① WC native filter bar; ② is a plugin addition (filter dropdown SC-State: All/NEW/CONFIRMED/PROCESSED/CANCELLED/FAILED/NEEDS-ACTION)
- ③ Column "SC-State" — color-coded badge, sortable (`subscription_status_badge` colors apply identically)
- ④ Column "SC-OrderID" — link to SC backend (↗); `—` when not yet submitted
- ⑤ Sortable columns (registered via `manage_(...)_sortable_columns` for both HPOS and Legacy hook-sets)
- ⑥ External link affordance
- ⑦ Plugin Bulk-Action: "Re-send to Spreadconnect" — re-queues `create_order` only for rows with SC-State = `FAILED`. **Pre-empt:** when this bulk action is selected from the menu, ineligible rows in the current selection are visually greyed (with tooltip "Not in FAILED state") and excluded from the operation count. **Outcome:** result banner is **explicit** — "X of Y re-queued · Z skipped (not in FAILED state)" with [Show details] link expanding to a per-row table (Order# / decision / reason).

### State Variations

| State | Visual Change |
|-------|---------------|
| `default` | Layout above |
| `filter_failed` | ② = FAILED → only failed rows visible; bulk re-send becomes useful |
| `filter_needs_action` | ② = NEEDS-ACTION → highlighted yellow rows |
| `bulk_action_in_progress` | Inline "Re-sending N orders…" banner; selected eligible rows show spinner in SC-State column |
| `bulk_action_selected_with_ineligible` | When the bulk dropdown is set to "Re-send to Spreadconnect" and the selection contains non-FAILED rows: those rows are visually greyed; tooltip "Not in FAILED state — will be skipped"; pre-flight count shown above [Apply]: "Will re-send: 2 · Will skip: 8" |
| `bulk_action_complete` | Banner "2 of 10 re-queued · 8 skipped (not in FAILED state) [Show details ▾]"; expanded panel lists per-row Order# / decision / reason; secondary link "see [Failed Ops] for any further failures" |
| `bulk_action_no_eligible` | If selection contains zero FAILED rows: action refused with banner "Re-send needs at least one FAILED order in the selection." |
| `legacy_orders_screen` | Identical layout — same columns/filter/bulk via legacy `manage_edit-shop_order_columns` hook set |

---

## Completeness Check

| Check | Status |
|-------|--------|
| All screens from UI Layout (Discovery) covered (Screens 1–12) | ✅ |
| All UI Components from "UI Components & States" annotated | ✅ |
| Relevant state variations documented per screen | ✅ |
| No business rules / API details / state-machine logic duplicated (stays in Discovery) | ✅ |
| Both HPOS and Legacy paths represented for Order surfaces (Screen 11, 12) | ✅ |
| All 8 Hub sub-sections + 4 inline extensions visualized | ✅ |

### Self-Check vs Template

| Template Section | Present? | Deviation/Reason |
|------------------|----------|------------------|
| Component Coverage | ✅ | — |
| User Flow Overview | ✅ | — |
| Screen Wireframes | ✅ | 12 screens |
| Annotations | ✅ | — |
| State Variations | ✅ | Per-screen tables |
| Completeness Check | ✅ | — |
