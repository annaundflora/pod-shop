<?php
/**
 * Plugin bootstrap entry-point.
 *
 * Wires hooks and exposes the plugin file path to follow-up slices
 * (HPOS declare in slice-03, schema/dbDelta in slice-04, options-defaults
 * in slice-05, i18n textdomain in slice-06, and beyond).
 *
 * @package SpreadconnectPod\Bootstrap
 */

declare(strict_types=1);

namespace SpreadconnectPod\Bootstrap;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Catalog\ArticleRemovedJob;
use SpreadconnectPod\Catalog\AttributeProvisioner;
use SpreadconnectPod\Catalog\SyncArticleJob;
use SpreadconnectPod\Catalog\SyncCatalogJob;
use SpreadconnectPod\Failure\AdminNoticeStore;
use SpreadconnectPod\Failure\BulkResendCoordinator;
use SpreadconnectPod\Failure\FailedOpsRepo;
use SpreadconnectPod\Failure\FailureNotifier;
use SpreadconnectPod\Failure\RetryPolicyListener;
use SpreadconnectPod\Hub\Ajax\ExportImportSettings as HubAjaxExportImportSettings;
use SpreadconnectPod\Hub\Ajax\FailedOpsActions as HubAjaxFailedOpsActions;
use SpreadconnectPod\Hub\Ajax\OrderActions as HubAjaxOrderActions;
use SpreadconnectPod\Hub\Ajax\ProductActions as HubAjaxProductActions;
use SpreadconnectPod\Hub\Ajax\RegenerateSecret as HubAjaxRegenerateSecret;
use SpreadconnectPod\Hub\Ajax\RepairSubscriptions as HubAjaxRepairSubscriptions;
use SpreadconnectPod\Hub\Ajax\SimulateEvent as HubAjaxSimulateEvent;
use SpreadconnectPod\Hub\Ajax\SyncNow as HubAjaxSyncNow;
use SpreadconnectPod\Hub\Ajax\TestConnection as HubAjaxTestConnection;
use SpreadconnectPod\Hub\Controller as HubController;
use SpreadconnectPod\Hub\Rest\SyncProgress as HubRestSyncProgress;
use SpreadconnectPod\Hub\View\Logs as HubLogsView;
use SpreadconnectPod\Hub\View\Settings as HubSettingsView;
use SpreadconnectPod\Inline\OrderListColumns as InlineOrderListColumns;
use SpreadconnectPod\Inline\OrderMetaBox as InlineOrderMetaBox;
use SpreadconnectPod\Inline\ProductListColumns as InlineProductListColumns;
use SpreadconnectPod\Inline\ProductMetaBox as InlineProductMetaBox;
use SpreadconnectPod\Logging\PurgeOldLogsJob;
use SpreadconnectPod\Order\FetchTrackingJob;
use SpreadconnectPod\Order\OrderCancelJob;
use SpreadconnectPod\Order\OrderCancelMirrorJob;
use SpreadconnectPod\Order\OrderConfirmJob;
use SpreadconnectPod\Order\OrderHandler;
use SpreadconnectPod\Order\OrderStateMachine;
use SpreadconnectPod\Order\OrderSubmitJob;
use SpreadconnectPod\Stock\StockSyncJob;
use SpreadconnectPod\Subscription\SubscriptionManager;
use SpreadconnectPod\Webhook\ProcessWebhookEventJob;
use SpreadconnectPod\Webhook\WebhookController;

/**
 * Central bootstrap for the Spreadconnect POD plugin.
 *
 * Idempotent: subsequent calls to {@see self::init()} are no-ops once the
 * skeleton has been wired the first time. Future slices will add hook
 * registrations to this class, all of which must remain idempotent under
 * the same guard.
 */
final class Plugin
{
	/**
	 * Whether {@see self::init()} has already executed.
	 */
	private static bool $initialized = false;

	/**
	 * Absolute path to the main plugin file (`spreadconnect-pod.php`).
	 *
	 * Stored on first invocation so that follow-up slices can resolve the
	 * file for `FeaturesUtil::declare_compatibility()` (slice-03),
	 * `plugin_basename()` for i18n (slice-06), `register_activation_hook()`
	 * (slice-04/05) and similar lifecycle helpers.
	 */
	private static string $pluginFile = '';

	/**
	 * Bootstrap the plugin.
	 *
	 * Called from `spreadconnect-pod.php` on file include.
	 *
	 * Idempotency: re-entrant calls return early without re-registering
	 * hooks. This protects against double-includes (e.g. a misconfigured
	 * `must-use` shim) and ensures `_doing_it_wrong` notices never fire.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file
	 *                            (typically `__FILE__` from
	 *                            `spreadconnect-pod.php`).
	 */
	public static function init( string $plugin_file ): void
	{
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		self::$pluginFile  = $plugin_file;

		// Hook registrations.
		//
		// Follow-up slices will extend this method:
		//   - slice-04: `register_activation_hook()` -> Schema::dbDelta().
		//   - slice-05: Options defaults via activation hook.
		//   - slice-06: `plugins_loaded` -> load_plugin_textdomain().
		//   - slice-09+: REST routes, webhook controller, AS handlers, etc.

		// slice-03: Declare HPOS (Custom Order Tables) compatibility before
		// WooCommerce initialises its feature flags. Must run inside the
		// idempotency guard so duplicate `init()` calls cannot register the
		// listener twice (`add_action` does not dedupe static-method callables
		// on identical classes).
		add_action( 'before_woocommerce_init', [ self::class, 'declareHposCompatibility' ] );

		// slice-04: Register the schema installer on plugin activation. The
		// activation hook fires once per activation event; together with the
		// idempotency guard above it stays exactly-once per `init()` run.
		// `dbDelta()` itself is additive-only, so re-activating the plugin
		// is safe and never destroys existing rows.
		register_activation_hook( $plugin_file, [ Schema::class, 'install' ] );

		// slice-05: Seed the 18 explicit `spreadconnect_*` options on
		// activation. `OptionsDefaults::install()` is idempotent (uses
		// `add_option()`, which never overwrites existing values), so
		// re-activating the plugin preserves any admin-customised values.
		// The 19th option `spreadconnect_webhook_secret` is generated by
		// slice-14, not seeded here.
		register_activation_hook( $plugin_file, [ OptionsDefaults::class, 'install' ] );

		// slice-20: Provision the `pa_groesse` / `pa_farbe` WC attribute
		// taxonomies on activation. `AttributeProvisioner::ensure()` is
		// idempotent — pre-existing taxonomies are skipped without
		// modification, and only missing slugs trigger `wc_create_attribute()`.
		// The static-property guard above ensures this hook is registered
		// exactly once per `init()` run, preserving slice-02 AC-5 idempotency.
		register_activation_hook( $plugin_file, [ AttributeProvisioner::class, 'ensure' ] );

		// slice-18: Schedule the weekly drift-check Action-Scheduler job
		// on plugin activation. `scheduleRecurringDriftCheck()` is
		// idempotent — it pre-checks `as_next_scheduled_action()` so a
		// re-activate never produces a duplicate schedule (slice-18 AC-9).
		// The handler binds inside `bootListeners()` below — both halves
		// must be wired for the recurring sweep to do anything useful.
		register_activation_hook(
			$plugin_file,
			[ SubscriptionManager::class, 'scheduleRecurringDriftCheck' ]
		);

		// slice-36: Schedule the recurring stock-sync Action-Scheduler job
		// on plugin activation. `scheduleRecurringStockSync()` is idempotent
		// — it pre-checks `as_next_scheduled_action()` so a re-activate
		// never produces a duplicate schedule (slice-36 AC-8). The handler
		// binds inside the per-request hook block below; both halves must
		// be wired for the recurring sweep to do anything useful.
		register_activation_hook(
			$plugin_file,
			[ self::class, 'scheduleRecurringStockSync' ]
		);

		// slice-43: Schedule the daily `spreadconnect/purge_old_logs`
		// Action-Scheduler job on plugin activation.
		// `scheduleRecurringPurgeOldLogs()` is idempotent — it pre-checks
		// `as_next_scheduled_action()` so a re-activate never produces a
		// duplicate schedule (slice-43 AC-1). The hook handler binds inside
		// the per-request hook block below; both halves must be wired for
		// the recurring purge to do anything useful. Closes Discovery
		// Slice 10 "Auto-Purge-Cron" + mitigates DB-bloat risk
		// (architecture.md Z. 738).
		register_activation_hook(
			$plugin_file,
			[ self::class, 'scheduleRecurringPurgeOldLogs' ]
		);

		// slice-36: Re-schedule the stock-sync recurring action when the
		// admin saves a new `spreadconnect_stock_sync_interval`. The
		// existing recurring action is unscheduled before the new one is
		// laid down so the old interval cannot keep firing alongside the
		// new one (Constraint "Settings-Change-Re-Schedule").
		add_action(
			'update_option_spreadconnect_stock_sync_interval',
			[ self::class, 'rescheduleRecurringStockSync' ],
			10,
			0
		);

		// slice-06: Load the `spreadconnect-pod` text-domain on
		// `plugins_loaded` (WP-default priority 10). The hook fires once per
		// request, after WP has finalised the locale, which is the earliest
		// safe point to call `load_plugin_textdomain()`. Idempotency is
		// guaranteed by the `self::$initialized` guard above: the action is
		// registered exactly once per request, so the callback fires at most
		// once per request and `.mo` files are never loaded twice.
		add_action(
			'plugins_loaded',
			static function () use ( $plugin_file ): void {
				load_plugin_textdomain(
					'spreadconnect-pod',
					false,
					dirname( plugin_basename( $plugin_file ) ) . '/languages'
				);
			}
		);

		// slice-23: Register the per-article sync hook handler. Action-
		// Scheduler dispatches `spreadconnect/sync_article` with the args-
		// array as the first parameter; the static bridge resolves the
		// production-default collaborator chain (SpreadconnectClient,
		// ImageSideloader, ProductMapper, SyncHistoryRepo) and invokes
		// `handle()`. The hook is registered with priority 10 and exactly
		// one accepted argument (the args-array), per AS conventions.
		// Producer-side `as_enqueue_async_action()` calls live in slice-24
		// (catalog-job), slice-25 (article webhooks) and slice-34 (re-sync
		// button) — slice-23 is the consumer only.
		add_action(
			'spreadconnect/sync_article',
			[ SyncArticleJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-24: Register the catalog-sync producer hook handler.
		// Action-Scheduler dispatches `spreadconnect/sync_catalog` with
		// the args-array `['trigger'=>'manual'|'webhook'|'scheduled'|'initial']`
		// as the first parameter. The static bridge resolves the
		// production-default collaborator chain (SpreadconnectClient,
		// SyncHistoryRepo) and invokes `handle()`. Producer-side
		// `as_enqueue_async_action()` calls live in slice-25 (article
		// webhooks → trigger='webhook') and slice-26 (Catalog UI
		// "Sync Now" button → trigger='manual'); slice-24 is the
		// consumer that paginates `GET /articles` and schedules one
		// `spreadconnect/sync_article` action per discovered article.
		add_action(
			'spreadconnect/sync_catalog',
			[ SyncCatalogJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-25: Register the article-removed Action-Scheduler hook
		// handler. Producer-side `as_enqueue_async_action()` lives in
		// `Webhook\ArticleEventHandler` (slice-25 sibling) — fired on
		// `Article.removed` webhooks. The static bridge instantiates a
		// fresh `ArticleRemovedJob` and invokes `handle()`, which reverse-
		// looks-up the WC product via `_spreadconnect_article_id` postmeta
		// and flips `post_status` to `draft` (NEVER `wp_delete_post` —
		// architecture.md Z. 281 + Z. 736). Hook is registered with
		// priority 10 and exactly one accepted argument (the args-array),
		// per AS conventions and slice-23 / slice-24 mirror.
		add_action(
			'spreadconnect/handle_article_removed',
			[ ArticleRemovedJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-36: Register the recurring stock-sync Action-Scheduler
		// hook handler. The recurring schedule itself is laid down in the
		// activation hook above (`scheduleRecurringStockSync`); this
		// `add_action` is the consumer side that AS dispatches against on
		// every tick. Iterates linked WC products, bulk-fetches stock per
		// product and threshold-mutates the WC variation stock when
		// `quantity < spreadconnect_low_stock_threshold` (architecture.md
		// Z. 386, Z. 554, Z. 623). Hook priority 10, accepted-args 1
		// (recurring schedules pass `[]`), per AS conventions and the
		// slice-23 / slice-24 / slice-25 mirror.
		add_action(
			'spreadconnect/scheduled_stock_sync',
			[ StockSyncJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-43: Register the daily `spreadconnect/purge_old_logs`
		// Action-Scheduler hook handler. The recurring schedule itself is
		// laid down in the activation hook above (`scheduleRecurringPurgeOldLogs`);
		// this `add_action` is the consumer side that AS dispatches against
		// on every daily tick. Deletes rows older than the configured
		// retention from `wp_spreadconnect_webhook_log` and
		// `wp_spreadconnect_failed_ops` (architecture.md Z. 339-340 +
		// Z. 556 + Z. 738). Hook is registered with priority 10 and zero
		// accepted arguments — recurring schedules dispatch with `[]`, and
		// `PurgeOldLogsJob::handle()` takes no parameters (slice-43
		// Provides-To contract). Idempotency of this `init()` body keeps
		// `add_action()` calls at exactly one per request.
		add_action(
			PurgeOldLogsJob::HOOK,
			[ PurgeOldLogsJob::class, 'handle' ]
		);

		// slice-13: Mount the Spreadconnect Hub admin sub-page under the
		// `WooCommerce` parent menu. `Hub\Controller::registerMenu()` is
		// hooked to `admin_menu` (priority 10) so it fires after WC has
		// registered its own top-level menu but before WP renders the
		// admin chrome. The dispatcher inside the controller handles
		// capability gating + section routing.
		add_action( 'admin_menu', [ HubController::class, 'registerMenu' ] );

		// slice-13: Wire the slice-11 Settings registrar to `admin_init`.
		// Slice 11 deferred this hook explicitly to slice-13 (see Settings
		// docblock @see registerSettings — "Hooked from Bootstrap\Plugin
		// (slice-13) on `admin_init`."). Without this hook the Settings
		// API would have no fields to render even though the page is
		// reachable. WP de-duplicates identical callable/priority pairs,
		// and the `self::$initialized` guard above keeps the hook count
		// at 1 per request anyway.
		add_action( 'admin_init', [ HubSettingsView::class, 'registerSettings' ] );

		// slice-12: Register the `wp_ajax_spreadconnect_test_connection`
		// handler so the Settings -> "Test This Key" button can verify an
		// unsaved API key against `GET /authentication`. The handler
		// terminates via `wp_send_json_success/error` and never persists
		// the POST-body key — slice-11's `SettingsValidator` remains the
		// only persistence path. No `wp_ajax_nopriv_*` variant — admin-only.
		HubAjaxTestConnection::register();

		// slice-14: Register the two webhook-secret AJAX handlers
		// (`spreadconnect_regenerate_secret` + `spreadconnect_acknowledge_initial_reveal`).
		// Both share the `spreadconnect_secret_action` nonce and the
		// `manage_woocommerce` capability gate; only the authenticated
		// `wp_ajax_*` variant is registered so anonymous callers can never
		// rotate the secret or flip the reveal-lock. No `nopriv_*` variant.
		// `add_action` de-duplicates identical callable/priority pairs,
		// and the `self::$initialized` guard above keeps the registration
		// count at exactly 1 per request.
		HubAjaxRegenerateSecret::register();

		// slice-19: Register the Subscriptions-Manager "Repair All" AJAX
		// handler (`spreadconnect_repair_subscriptions`). The handler runs
		// `SubscriptionManager::removeOrphans()` BEFORE
		// `SubscriptionManager::register()` (slice-19 AC-6) and emits the
		// `{added, removed, errors}` summary as `wp_send_json_success`.
		// Capability + nonce gates terminate via 403 before any service
		// call; transient/client errors map to 503/500. Only the
		// authenticated `wp_ajax_*` variant — anonymous callers must never
		// be able to rewrite subscriptions.
		HubAjaxRepairSubscriptions::register();

		// slice-44: Register the Settings -> Developer-Tools "Simulate-*"
		// AJAX handler (`spreadconnect_simulate_event`). The handler is
		// gated on `manage_woocommerce` + `spreadconnect_simulate_event`
		// nonce + the server-side `spreadconnect_use_staging` toggle, and
		// dispatches to one of three `SpreadconnectClient::simulate*()`
		// wrappers (slice-10) on success. Cap + nonce + staging gates
		// terminate via 403 before any business logic runs; client/transient
		// errors map to 400/502. Only the authenticated `wp_ajax_*` variant
		// is registered — anonymous callers must never trigger Simulate
		// calls. `add_action` de-duplicates identical callable/priority
		// pairs and the `self::$initialized` guard above keeps the
		// registration count at exactly 1 per request.
		HubAjaxSimulateEvent::register();

		// slice-45: Register the Settings -> Footer Export/Import AJAX
		// handlers (`spreadconnect_export_settings` +
		// `spreadconnect_import_settings`). Both share the
		// `manage_woocommerce` capability gate but mint SEPARATE nonces
		// (per slice-45 AC-9) so a CSRF on one button cannot replay the
		// other. Only the authenticated `wp_ajax_*` variant is registered
		// — anonymous callers must never be able to read or rewrite
		// operational settings, even with secrets filtered out at both
		// ends of the roundtrip. `add_action` de-duplicates identical
		// callable/priority pairs and the `self::$initialized` guard
		// above keeps the registration count at exactly 1 per request.
		HubAjaxExportImportSettings::register();

		// slice-26: Register the Catalog sub-page "Sync now" AJAX handler.
		// `Hub\Ajax\SyncNow::register()` hooks itself onto
		// `wp_ajax_spreadconnect_sync_now`. The handler validates capability
		// + nonce and enqueues `spreadconnect/sync_catalog` with
		// `trigger='manual'`; the slice-24 consumer then does the actual
		// pagination work on the next AS tick. Only the authenticated
		// `wp_ajax_*` variant — anonymous callers must never enqueue.
		HubAjaxSyncNow::register();

		// slice-26: Register the read-only `/wp-json/spreadconnect/v1/sync-progress`
		// REST route used by the Catalog sub-page's 3 s AJAX-poll. The
		// route is `manage_woocommerce`-capability-gated via
		// `permission_callback` (architecture.md Z. 132 + Z. 484 — read-only
		// AJAX requires capability only, no nonce). `register()` is bound to
		// `rest_api_init` so the registration happens after WP has booted
		// the REST stack but before any route is dispatched.
		add_action( 'rest_api_init', [ HubRestSyncProgress::class, 'register' ] );

		// slice-15: Register the inbound `POST /wp-json/spreadconnect/v1/webhook`
		// route. The route is **public** (no capability gate) — Spreadconnect
		// is an anonymous caller and the HMAC-SHA256 verification inside
		// `WebhookController::authorize()` is the auth (architecture.md
		// Z. 483 + Z. 514). The `self::$initialized` guard above keeps this
		// `add_action` call at exactly one per request, so re-entrant
		// `init()` invocations never double-register the hook (slice-15
		// AC-9). `register_rest_route` itself is idempotent inside WP, but
		// avoiding duplicate hook entries keeps `has_action()` introspection
		// truthful.
		add_action( 'rest_api_init', [ WebhookController::class, 'register' ] );

		// slice-17: Register the `spreadconnect/process_webhook_event`
		// Action-Scheduler dispatcher. Slice 16 enqueues the hook with
		// `as_enqueue_async_action(..., [$logId], 'spreadconnect')` after
		// a fresh INSERT into `wp_spreadconnect_webhook_log`; this listener
		// loads the row, parses the JSON payload and dispatches per
		// `eventType`-prefix to either `Webhook\OrderEventHandler` or
		// `Webhook\ArticleEventHandler` (architecture.md Z. 449 + Z. 553).
		// Argument-shape MUST be exactly one positional `int $logId` —
		// AS reaches the array element directly through the
		// `accepted_args=1` channel; passing 2 would be a bug.
		// Idempotency is provided by the `self::$initialized` static
		// guard above (slice-02 AC-5 pattern); a second `init()` call
		// returns early and the `add_action` line never runs twice.
		add_action(
			'spreadconnect/process_webhook_event',
			[ ProcessWebhookEventJob::class, 'handle' ],
			10,
			1
		);

		// slice-18: Wire the subscription-lifecycle listeners. Three hooks:
		//   - `spreadconnect/webhook_secret_rotated` (slice-14 producer) →
		//     DELETE-then-POST sweep with the freshly rotated secret.
		//   - `updated_option_spreadconnect_api_key` (WP core after the
		//     settings form persists the API key) → authenticate →
		//     generate-secret-if-empty → register sweep.
		//   - `spreadconnect/auto_subscription_check` (Action-Scheduler
		//     weekly recurring) → drift-check + self-heal.
		// All three handlers are idempotent and stateless (slice-18 AC-9 /
		// AC-4); the static-method/priority pairs are de-duplicated by WP.
		SubscriptionManager::bootListeners();

		// slice-28 / slice-31: Wire the WC processing- and cancelled-hook
		// listeners and the `spreadconnect/create_order` Action-Scheduler
		// job handler.
		//
		// The hooks together implement the outbound order-submit pipeline
		// (architecture.md Z. 401-430 — Flow C) and the WC → SC cancel-
		// mirror branch (slice-31). One {@see OrderHandler} instance is
		// shared across all four listeners (`on_processing`,
		// `on_cancelled`, `maybeScheduleAutoConfirm`,
		// `recordAutoConfirmPreCheckFailure`) so the optional logger
		// override stays consistent. The real DI container is introduced
		// in slice-37. Idempotency of this `init()` body keeps
		// `add_action()` calls at exactly one per request — `has_action()`
		// returns identical for repeated `init()` invocations (slice-28
		// AC-9 / slice-31 AC-1).
		$orderHandler = new OrderHandler();

		add_action(
			'woocommerce_order_status_processing',
			[ $orderHandler, 'on_processing' ],
			10,
			2
		);

		// slice-31 AC-1: WC-Cancel listener → unschedules any pending
		// auto-confirm timer (race-protection, architecture.md Z. 642)
		// and enqueues the {@see OrderCancelMirrorJob} when the SC-state
		// is exactly `NEW`. Otherwise writes an Order-Note + persistent-
		// notice stub. Args=2 mirrors WC's `(order_id, order)` shape.
		add_action(
			'woocommerce_order_status_cancelled',
			[ $orderHandler, 'on_cancelled' ],
			10,
			2
		);

		// slice-31: Auto-Confirm-Timer scheduler. Fired by
		// {@see OrderSubmitJob} on the 2xx-success path (the
		// `spreadconnect/order_submitted` action is a pure notification —
		// slice-28 ACs stay semantically identical). The handler reads
		// `spreadconnect_auto_confirm` and may schedule a
		// `spreadconnect/confirm_order` action. Idempotent.
		add_action(
			'spreadconnect/order_submitted',
			[ $orderHandler, 'maybeScheduleAutoConfirm' ],
			10,
			1
		);

		// slice-31 AC-10: Auto-Confirm-Pre-Check-Failure listener. Fired
		// by {@see OrderConfirmJob} when its pre-check fails (a
		// notification-only action — slice-29 ACs stay semantically
		// identical). The handler emits the persistent-notice stub
		// (`admin_notice_pending_record` tag) and explicitly suppresses
		// the FailedOps DLQ-Aufnahme (architecture.md Z. 591).
		add_action(
			'spreadconnect/auto_confirm_pre_check_failed',
			[ $orderHandler, 'recordAutoConfirmPreCheckFailure' ],
			10,
			1
		);

		// `spreadconnect/create_order` AS-job handler. AS dispatches the
		// args-array as the first parameter (AC-9: priority 10, args 1).
		// The closure resolves the `$wpdb` global lazily so the production
		// path always sees the live DB connection while unit tests can
		// stub the dependencies via `OrderSubmitJob::__construct()` directly.
		add_action(
			'spreadconnect/create_order',
			static function ( array $args ): void {
				global $wpdb;

				$job = new OrderSubmitJob(
					new SpreadconnectClient(),
					new OrderStateMachine( $wpdb )
				);
				$job->handle( $args );
			},
			10,
			1
		);

		// slice-29: Wire the `spreadconnect/confirm_order` and
		// `spreadconnect/cancel_order` Action-Scheduler job handlers.
		//
		// Both implement the order-lifecycle confirm/cancel hops
		// (architecture.md "State-Transition" Z. 535-538). The producer
		// surface (auto-confirm timer, order-edit meta-box buttons,
		// failed-ops resend) lives in slice-31 / slice-32 / slice-38;
		// slice-29 ships only the consumers. Hook-args convention is
		// `['order_id' => int]` (slice-28 mirror), priority 10, accepts
		// 1 arg (the args-array). Re-entrant `init()` calls remain
		// idempotent per the `self::$initialized` static guard above —
		// `add_action()` is invoked exactly once per request, so
		// `has_action()` returns identical for repeated `init()` calls
		// (AC-12).
		add_action(
			'spreadconnect/confirm_order',
			static function ( array $args ): void {
				global $wpdb;

				$job = new OrderConfirmJob(
					new SpreadconnectClient(),
					new OrderStateMachine( $wpdb )
				);
				$job->handle( $args );
			},
			10,
			1
		);

		add_action(
			'spreadconnect/cancel_order',
			static function ( array $args ): void {
				global $wpdb;

				$job = new OrderCancelJob(
					new SpreadconnectClient(),
					new OrderStateMachine( $wpdb )
				);
				$job->handle( $args );
			},
			10,
			1
		);

		// slice-31: Wire the `spreadconnect/cancel_order_mirror` Action-
		// Scheduler job handler. Producer-side `as_enqueue_async_action()`
		// lives in {@see OrderHandler::on_cancelled} (slice-31 sibling) —
		// fired when WC transitions an order to `cancelled` AND the
		// persisted SC-state is exactly `NEW`. The static bridge resolves
		// the production-default collaborator chain (SpreadconnectClient,
		// OrderStateMachine via lazy `$wpdb`) and invokes `handle()`.
		// Hook-args convention `['order_id' => int]` (slice-28 mirror),
		// priority 10, accepts 1 arg (the args-array).
		add_action(
			OrderCancelMirrorJob::HOOK_CANCEL_ORDER_MIRROR,
			[ OrderCancelMirrorJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-30: Register the `spreadconnect/fetch_tracking` Action-
		// Scheduler hook handler. Producer-side `as_enqueue_async_action()`
		// lives in `Webhook\OrderEventHandler` (slice-30 sibling) — fired
		// on `Shipment.sent` webhooks. The static bridge instantiates a
		// fresh `FetchTrackingJob` with the production-default
		// `SpreadconnectClient` and invokes `handle()`, which calls
		// `getShipments()`, persists tracking-meta and flips WC-Status to
		// `completed` (architecture.md Z. 552). Hook is registered with
		// priority 10 and exactly one accepted argument (the args-array),
		// per AS conventions and slice-25 / slice-28 / slice-29 mirror.
		// Idempotency of this `init()` body keeps `add_action()` calls at
		// exactly one per request — `has_action()` returns identical for
		// repeated `init()` invocations (slice-30 AC-11).
		add_action(
			'spreadconnect/fetch_tracking',
			[ FetchTrackingJob::class, 'handleStatic' ],
			10,
			1
		);

		// slice-34: Mount the WC-Product-Edit "Spreadconnect" sidebar
		// meta-box and its four AJAX handlers.
		//
		// `add_meta_boxes` registers the box itself; `admin_enqueue_scripts`
		// is screen-guarded inside the callback so the JS never loads on
		// unrelated admin pages. The four `wp_ajax_*` actions cover the
		// search picker, link/unlink mutations and the live-stock refresh.
		// All four share the `spreadconnect_product_actions` nonce minted
		// by `enqueueAssets()` and the `manage_woocommerce` capability
		// gate; only the authenticated `wp_ajax_*` variant is registered —
		// no `wp_ajax_nopriv_*` (admin-only).
		add_action( 'add_meta_boxes', [ InlineProductMetaBox::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ InlineProductMetaBox::class, 'enqueueAssets' ] );

		add_action(
			'wp_ajax_spreadconnect_search_articles',
			[ HubAjaxProductActions::class, 'searchArticlesStatic' ]
		);
		add_action(
			'wp_ajax_spreadconnect_link_article',
			[ HubAjaxProductActions::class, 'linkArticleStatic' ]
		);
		add_action(
			'wp_ajax_spreadconnect_unlink_article',
			[ HubAjaxProductActions::class, 'unlinkArticleStatic' ]
		);
		add_action(
			'wp_ajax_spreadconnect_refresh_stock',
			[ HubAjaxProductActions::class, 'refreshStockStatic' ]
		);

		// slice-35: Mount the WC-Product-List columns + "Spreadconnect"
		// filter drop-down + sort hooks on the
		// `wp-admin/edit.php?post_type=product` screen.
		//
		// Five hooks total (AC-11): three render hooks
		// (`manage_edit-product_columns`, `manage_product_posts_custom_column`,
		// `manage_edit-product_sortable_columns` + `restrict_manage_posts`)
		// and one combined `pre_get_posts` handler that dispatches both
		// sort (AC-7) and filter (AC-9/10) inside a single closure. The
		// adapter is read-only — no postmeta writes, no API calls — so
		// only the `pre_get_posts` mutation is `manage_woocommerce`-cap
		// gated; the render hooks are admin-list-only by virtue of the
		// `manage_edit-*` / `manage_*_posts_custom_column` hook surface.
		add_filter(
			'manage_edit-product_columns',
			[ InlineProductListColumns::class, 'registerColumnsStatic' ]
		);
		add_action(
			'manage_product_posts_custom_column',
			[ InlineProductListColumns::class, 'renderColumnStatic' ],
			10,
			2
		);
		add_filter(
			'manage_edit-product_sortable_columns',
			[ InlineProductListColumns::class, 'registerSortableColumnsStatic' ]
		);
		add_action(
			'pre_get_posts',
			[ InlineProductListColumns::class, 'preGetPostsStatic' ]
		);
		add_action(
			'restrict_manage_posts',
			[ InlineProductListColumns::class, 'renderFilterDropdownStatic' ]
		);

		// slice-32: Mount the WC-Order-Edit "Spreadconnect" sidebar meta-box
		// and its five AJAX handlers (Confirm / Cancel / Refresh-State /
		// Save-Shipping-Type / Cancel-Auto-Confirm).
		//
		// `add_meta_boxes` registers the box on BOTH the HPOS screen-id
		// (`woocommerce_page_wc-orders`) and the legacy `shop_order` screen
		// — the callback inspects its argument to dual-register without
		// rendering on unrelated screens. `admin_enqueue_scripts` is screen-
		// guarded inside the callback so the JS never loads on unrelated
		// admin pages. The five `wp_ajax_*` actions share the
		// `spreadconnect_admin` nonce minted by `enqueueAssets()` and the
		// `manage_woocommerce` capability gate; only the authenticated
		// `wp_ajax_*` variant is registered — admin-only (no `nopriv_*`).
		add_action( 'add_meta_boxes', [ InlineOrderMetaBox::class, 'registerOnAddMetaBoxes' ] );
		add_action( 'admin_enqueue_scripts', [ InlineOrderMetaBox::class, 'enqueueAssets' ] );

		HubAjaxOrderActions::register();

		// slice-33: Mount the WC-Order-List columns + filter drop-down +
		// "Re-send to Spreadconnect" bulk-action on BOTH the legacy
		// `edit.php?post_type=shop_order` and the HPOS
		// `wp-admin/admin.php?page=wc-orders` screen surfaces (architecture.md
		// Z. 641 + Z. 821 — dual-hook contract).
		//
		// Single-Adapter pattern: every callback is `Inline\OrderListColumns`'
		// `*Static` bridge so one method body services BOTH hook variants.
		// The bridges resolve a default `Failure\BulkResendCoordinator`
		// per request; unit tests inject a Mockery double via the constructor.
		//
		// The `pre_get_posts` + `woocommerce_order_query_args` pair handles
		// AC-5 (sort) and AC-7/AC-8 (filter) in one closure each, so the
		// hook count stays at the AC-16 minimum.
		add_filter(
			'manage_edit-shop_order_columns',
			[ InlineOrderListColumns::class, 'registerColumnsStatic' ]
		);
		add_filter(
			'manage_woocommerce_page_wc-orders_columns',
			[ InlineOrderListColumns::class, 'registerColumnsStatic' ]
		);

		add_action(
			'manage_shop_order_posts_custom_column',
			[ InlineOrderListColumns::class, 'renderColumnStatic' ],
			10,
			2
		);
		add_action(
			'manage_woocommerce_page_wc-orders_custom_column',
			[ InlineOrderListColumns::class, 'renderColumnStatic' ],
			10,
			2
		);

		add_filter(
			'manage_edit-shop_order_sortable_columns',
			[ InlineOrderListColumns::class, 'registerSortableColumnsStatic' ]
		);
		add_filter(
			'manage_woocommerce_page_wc-orders_sortable_columns',
			[ InlineOrderListColumns::class, 'registerSortableColumnsStatic' ]
		);

		// AC-5/AC-7 (legacy) + AC-5/AC-8 (HPOS) — two filters, one per
		// query API. The HPOS filter mutates the `wc_get_orders` args
		// before `OrdersTableQuery` is built; `woocommerce_order_query_args`
		// is the canonical hook on WC ≥ 8.2 (architecture.md Z. 821).
		add_action(
			'pre_get_posts',
			[ InlineOrderListColumns::class, 'applySortingAndFilterStatic' ]
		);
		add_filter(
			'woocommerce_order_query_args',
			[ InlineOrderListColumns::class, 'applyOrderQueryArgsStatic' ]
		);
		add_filter(
			'woocommerce_order_list_table_prepare_items_query_args',
			[ InlineOrderListColumns::class, 'applyOrderQueryArgsStatic' ]
		);

		add_action(
			'restrict_manage_posts',
			[ InlineOrderListColumns::class, 'renderFilterDropdownStatic' ]
		);
		add_action(
			'woocommerce_order_list_table_restrict_manage_orders',
			[ InlineOrderListColumns::class, 'renderFilterDropdownStatic' ]
		);

		add_filter(
			'bulk_actions-edit-shop_order',
			[ InlineOrderListColumns::class, 'registerBulkActionStatic' ]
		);
		add_filter(
			'bulk_actions-woocommerce_page_wc-orders',
			[ InlineOrderListColumns::class, 'registerBulkActionStatic' ]
		);

		add_filter(
			'handle_bulk_actions-edit-shop_order',
			[ InlineOrderListColumns::class, 'handleBulkActionStatic' ],
			10,
			3
		);
		add_filter(
			'handle_bulk_actions-woocommerce_page_wc-orders',
			[ InlineOrderListColumns::class, 'handleBulkActionStatic' ],
			10,
			3
		);

		add_action(
			'admin_notices',
			[ InlineOrderListColumns::class, 'renderOutcomePanelStatic' ]
		);

		add_action(
			'admin_enqueue_scripts',
			[ InlineOrderListColumns::class, 'enqueueAssetsStatic' ]
		);

		// AC-10: pre-flight AJAX handler — admin-only (no `nopriv_*`
		// variant). Cap+Nonce-ordering inside the handler mirrors slice-32.
		add_action(
			'wp_ajax_' . InlineOrderListColumns::AJAX_ACTION_PREFLIGHT,
			[ InlineOrderListColumns::class, 'handlePreflightAjaxStatic' ]
		);

		// slice-42: CSV-export endpoint for the Logs sub-page (Hub
		// `?page=spreadconnect&section=logs`). The view itself is
		// dispatched via the slice-13 routing table — `Hub\Controller`'s
		// section-whitelist already maps `logs` → `Hub\View\Logs::render()`,
		// so no additional `admin_menu` hook is required. This AJAX
		// action exists solely to stream the filtered CSV back to the
		// browser when the `[Download CSV]` button is clicked. The
		// handler self-gates on `manage_woocommerce` + the shared
		// `spreadconnect_admin` nonce (architecture.md Z. 84) BEFORE any
		// header is emitted, so a forged or unauthenticated request
		// reliably produces a 403. Only the authenticated `wp_ajax_*`
		// variant is registered — no `nopriv_*` (admin-only).
		add_action(
			'wp_ajax_' . HubLogsView::CSV_AJAX_ACTION,
			[ HubLogsView::class, 'handleCsvExport' ]
		);

		// slice-37: Wire the AS retry-policy listener. The listener observes
		// `action_scheduler_failed_action` and decides — based on the thrown
		// exception class + the AS retry-counter — whether to record a row in
		// `wp_spreadconnect_failed_ops` (DLQ).
		//
		// Construction is inline (no DI container) per slice-37 Constraints
		// — the same lazy-closure pattern slice-28/29 uses for the
		// `spreadconnect/create_order`, `spreadconnect/confirm_order` and
		// `spreadconnect/cancel_order` job handlers (see lines 462-519
		// above). The closure resolves the `$wpdb` global at hook-fire time
		// rather than at `init()` time so the production path always sees
		// the live DB connection while unit tests that exercise `init()`
		// without a `$GLOBALS['wpdb']` mock (Slice02/13/14/15/28/29/32/34/35
		// bootstrap-style tests) do not trip the `FailedOpsRepo`
		// constructor's `wpdb` type-hint. Slice-37's own tests inject the
		// repo + listener directly and never reach this closure body
		// (see Slice37FailedOpsRepoTest::makeRepo / makeAction).
		//
		// AC-11 (`add_action('action_scheduler_failed_action', ...)` after
		// `init()`) is satisfied because the closure itself is registered
		// here unconditionally; the deferred construction does not affect
		// the hook-presence check `has_action()` performs.
		//
		// AC-12 (idempotency on double-fire of the same `$action_id`) is
		// preserved because each hook-fire creates a fresh listener that
		// queries the repo via `findByEntity()` — the 5-minute lookup
		// window is in the DB, not in PHP-instance state. The
		// `self::$initialized` guard above keeps the `add_action` call at
		// exactly one per request, so a re-entrant `init()` call cannot
		// double-register the listener (slice-37 AC-11).
		add_action(
			'action_scheduler_failed_action',
			static function ( $action_id ): void {
				global $wpdb;

				// slice-39: inject FailureNotifier + AdminNoticeStore so
				// the listener can dispatch email + persist a persistent
				// admin-notice after a successful DLQ insert. Construction
				// is lazy (inside the closure) so unit-tests that exercise
				// `init()` without a `$GLOBALS['wpdb']` mock or without WC
				// loaded never trip these constructors.
				$failedOpsRepo       = new FailedOpsRepo( $wpdb );
				$failureNotifier     = new FailureNotifier();
				$adminNoticeStore    = new AdminNoticeStore();
				$retryPolicyListener = new RetryPolicyListener(
					$failedOpsRepo,
					null,
					$failureNotifier,
					$adminNoticeStore
				);
				$retryPolicyListener->on_action_failed( (int) $action_id );
			},
			10,
			1
		);

		// slice-39: Mount the persistent admin-notices on every WP-Admin
		// page-load. `AdminNoticeStore::renderAll()` does its OWN
		// capability-check (`manage_woocommerce`) before emitting any
		// HTML, and the store self-resolves the option on each call —
		// no shared state across requests. The `self::$initialized`
		// guard above keeps the `add_action` call at exactly one per
		// request, so a re-entrant `init()` cannot double-register the
		// hook (slice-39 AC-13). The hook is a closure-callback so two
		// distinct `Plugin::init()` invocations (within the same
		// request) would not register two identical callable-pairs
		// either — but the static guard makes the duplication-check
		// moot in practice.
		add_action(
			'admin_notices',
			static function (): void {
				$store = new AdminNoticeStore();
				$store->renderAll();
			},
			10,
			0
		);

		// slice-38: Wire the three Failed-Ops AJAX handlers
		// (`spreadconnect_resend_failed_op`, `spreadconnect_dismiss_failed_op`,
		// `spreadconnect_resolve_create_order`). The class itself is
		// constructor-DI on `Failure\FailedOpsRepo`; production wiring
		// constructs both objects inside a closure that fires on the
		// admin-ajax dispatch path so `$wpdb` is always live (mirrors the
		// slice-37 lazy-listener fix — Bootstrap eagerness during test-suite
		// `init()` would otherwise trip the `FailedOpsRepo::__construct`
		// `wpdb` type-hint when `$GLOBALS['wpdb']` is absent).
		//
		// Idempotency: the `self::$initialized` guard above keeps the three
		// `add_action` calls at exactly one per request; `add_action()`
		// further de-duplicates identical closure pairs, so a re-entrant
		// `init()` cannot double-register the hooks (slice-38 AC-5
		// "register exactly once").
		$failedOpsAjaxLazy = static function ( string $method ): void {
			global $wpdb;

			$repo    = new FailedOpsRepo( $wpdb );
			$actions = new HubAjaxFailedOpsActions( $repo );
			$actions->{$method}();
		};

		add_action(
			'wp_ajax_spreadconnect_resend_failed_op',
			static function () use ( $failedOpsAjaxLazy ): void {
				$failedOpsAjaxLazy( 'resend' );
			}
		);
		add_action(
			'wp_ajax_spreadconnect_dismiss_failed_op',
			static function () use ( $failedOpsAjaxLazy ): void {
				$failedOpsAjaxLazy( 'dismiss' );
			}
		);
		add_action(
			'wp_ajax_spreadconnect_resolve_create_order',
			static function () use ( $failedOpsAjaxLazy ): void {
				$failedOpsAjaxLazy( 'resolve' );
			}
		);

		// slice-40: Wire the two NEW Bulk-AJAX-Hooks
		// (`spreadconnect_bulk_resend_failed_op`,
		// `spreadconnect_bulk_dismiss_failed_op`). The class itself is
		// constructor-DI on `Failure\FailedOpsRepo` (optional);
		// production wiring constructs both objects inside a lazy closure
		// that fires on the admin-ajax dispatch path so `$wpdb` is always
		// live (mirrors the slice-37 / slice-38 lazy-listener pattern).
		//
		// Idempotency: the `self::$initialized` guard above keeps the two
		// `add_action` calls at exactly one per request; `add_action()`
		// further de-duplicates identical closure pairs, so a re-entrant
		// `init()` cannot double-register the hooks (slice-40 AC-15).
		$bulkAjaxLazy = static function ( string $method ): void {
			global $wpdb;

			$repo        = new FailedOpsRepo( $wpdb );
			$coordinator = new BulkResendCoordinator( $repo );
			$coordinator->{$method}();
		};

		add_action(
			'wp_ajax_spreadconnect_bulk_resend_failed_op',
			static function () use ( $bulkAjaxLazy ): void {
				$bulkAjaxLazy( 'handleBulkResendAjax' );
			}
		);
		add_action(
			'wp_ajax_spreadconnect_bulk_dismiss_failed_op',
			static function () use ( $bulkAjaxLazy ): void {
				$bulkAjaxLazy( 'handleBulkDismissAjax' );
			}
		);
	}

	/**
	 * Declare HPOS (Custom Order Tables) compatibility with WooCommerce.
	 *
	 * Hooked to `before_woocommerce_init`. Guarded by `class_exists()` so the
	 * callback is a safe no-op when WooCommerce is inactive or its
	 * `FeaturesUtil` helper has not been loaded yet (e.g. very old WC
	 * versions).
	 *
	 * @return void
	 */
	public static function declareHposCompatibility(): void
	{
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			self::pluginFile(),
			true
		);
	}

	/**
	 * Return the absolute path to the main plugin file.
	 *
	 * Consumers (slice-03+) call this to obtain the value originally passed
	 * to {@see self::init()} without having to track `__FILE__` themselves.
	 */
	public static function pluginFile(): string
	{
		return self::$pluginFile;
	}

	/**
	 * Slice-36 AC-8: lay down the recurring `spreadconnect/scheduled_stock_sync`
	 * Action-Scheduler schedule.
	 *
	 * Idempotent — pre-checks `as_next_scheduled_action()` so re-running the
	 * activation hook (or this method via the option-update re-schedule path)
	 * never produces a duplicate schedule. Skipped silently when AS functions
	 * are unavailable (e.g. unit-test bootstrap without WC).
	 *
	 * Interval enum (architecture.md Z. 332):
	 *   `1h`  → 3600
	 *   `4h`  → 14400
	 *   `6h`  → 21600 (default)
	 *   `12h` → 43200
	 *   `24h` → 86400
	 */
	public static function scheduleRecurringStockSync(): void
	{
		if ( ! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		// Idempotency pre-check: bail when the recurring action is already
		// on the schedule. AS returns either an int (timestamp) or `false` —
		// anything truthy means "already scheduled".
		$existing = as_next_scheduled_action(
			'spreadconnect/scheduled_stock_sync',
			array(),
			'spreadconnect'
		);
		if ( false !== $existing && null !== $existing && 0 !== $existing ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			self::resolveStockSyncIntervalSeconds(),
			'spreadconnect/scheduled_stock_sync',
			array(),
			'spreadconnect'
		);
	}

	/**
	 * Slice-36 Constraint "Settings-Change-Re-Schedule": tear down the
	 * existing recurring action and lay down a fresh one with the new
	 * interval. Called from the `update_option_spreadconnect_stock_sync_interval`
	 * hook so the admin's setting change takes effect on the next tick
	 * (and the previous interval can no longer fire alongside the new one).
	 *
	 * Idempotent — `as_unschedule_action` is a safe no-op when no schedule
	 * exists, and {@see self::scheduleRecurringStockSync()} is itself
	 * idempotent. The two operations together produce exactly one
	 * recurring schedule with the freshly-resolved interval.
	 */
	public static function rescheduleRecurringStockSync(): void
	{
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action(
				'spreadconnect/scheduled_stock_sync',
				array(),
				'spreadconnect'
			);
		}

		self::scheduleRecurringStockSync();
	}

	/**
	 * Slice-43 AC-1: lay down the recurring `spreadconnect/purge_old_logs`
	 * Action-Scheduler schedule (daily, group `spreadconnect`).
	 *
	 * Idempotent — pre-checks `as_next_scheduled_action()` so re-running
	 * the activation hook never produces a duplicate schedule. Skipped
	 * silently when AS functions are unavailable (e.g. unit-test bootstrap
	 * without WC).
	 *
	 * Closes Discovery Slice 10 "Auto-Purge-Cron" and mitigates the
	 * architecture-Risk row "Action-Scheduler jobs accumulate without
	 * purge -> DB bloat" (architecture.md Z. 738). Hook fires daily and
	 * runs {@see PurgeOldLogsJob::handle()}.
	 */
	public static function scheduleRecurringPurgeOldLogs(): void
	{
		if ( ! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		// Idempotency pre-check (slice-43 AC-1): bail when the recurring
		// action is already on the schedule. AS returns either an int
		// (timestamp) or `false` — anything truthy means "already scheduled".
		$existing = as_next_scheduled_action(
			PurgeOldLogsJob::HOOK,
			array(),
			PurgeOldLogsJob::AS_GROUP
		);
		if ( false !== $existing && null !== $existing && 0 !== $existing ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			DAY_IN_SECONDS,
			PurgeOldLogsJob::HOOK,
			array(),
			PurgeOldLogsJob::AS_GROUP
		);
	}

	/**
	 * Resolve the `spreadconnect_stock_sync_interval` enum into a seconds
	 * value (architecture.md Z. 332). Falls back to the `6h` default when
	 * the option is missing or carries an unknown enum literal.
	 */
	private static function resolveStockSyncIntervalSeconds(): int
	{
		$raw = get_option( 'spreadconnect_stock_sync_interval', '6h' );

		$mapping = array(
			'1h'  => 3600,
			'4h'  => 14400,
			'6h'  => 21600,
			'12h' => 43200,
			'24h' => 86400,
		);

		if ( is_string( $raw ) && isset( $mapping[ $raw ] ) ) {
			return $mapping[ $raw ];
		}

		return $mapping['6h'];
	}
}
