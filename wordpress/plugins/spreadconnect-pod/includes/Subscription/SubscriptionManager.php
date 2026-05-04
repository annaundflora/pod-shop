<?php
/**
 * Webhook-Subscription lifecycle service (Slice 18).
 *
 * Owns the diff-and-repair flow for Spreadconnect webhook subscriptions:
 * compares the 7 expected event types ({@see self::EXPECTED_EVENTS}) against
 * `GET /subscriptions`, registers missing entries via `POST /subscriptions`,
 * and removes orphaned entries on our callback URL via
 * `DELETE /subscriptions/{id}`. Foreign callback URLs are never touched —
 * the URL-equality check on {@see self::currentCallbackUrl()} is the
 * ownership boundary (architecture.md Z. 108 "Never deletes foreign URLs").
 *
 * Trigger surface:
 *   1. Settings-Save with a valid connection
 *      (`updated_option_spreadconnect_api_key` listener) →
 *      authenticate → generate-secret-if-empty → register.
 *   2. Secret rotation hook from {@see WebhookSecretManager::regenerate()}
 *      (`spreadconnect/webhook_secret_rotated`) → resubscribeAll.
 *   3. Recurring Action-Scheduler check
 *      (`spreadconnect/auto_subscription_check`, weekly) → driftCheck.
 *
 * Scope boundary (slice-18 Constraints):
 *   - No UI: slice-19 ships the Subscriptions repair view.
 *   - No persistent subscription cache: live `getSubscriptions()` is the
 *     single source of truth.
 *   - No FailedOpsRepo wiring: errors are returned in the summary array;
 *     slice-39 decides how to surface them.
 *
 * @package SpreadconnectPod\Subscription
 */

declare(strict_types=1);

namespace SpreadconnectPod\Subscription;

use SpreadconnectPod\Api\Dto\Subscription;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use Throwable;

/**
 * Stateless service for the webhook-subscription lifecycle.
 *
 * `final` + only `static` methods — same convention as
 * {@see WebhookSecretManager}. The single test seam is
 * {@see self::makeClient()}, marked `protected static` so a thin test
 * subclass can substitute a mock `SpreadconnectClient` without monkey-
 * patching the constructor globally.
 */
final class SubscriptionManager
{
	/**
	 * Action-Scheduler hook for the weekly drift-check.
	 *
	 * Wired in {@see self::bootListeners()} as the consumer; scheduled in
	 * {@see self::scheduleRecurringDriftCheck()} (called from
	 * `Bootstrap\Plugin` activation hook).
	 */
	public const HOOK_DRIFT_CHECK = 'spreadconnect/auto_subscription_check';

	/**
	 * Action-Scheduler group slug for every plugin-owned recurring action
	 * (architecture.md Z. 558). Surfaces the action under
	 * `Tools → Scheduled Actions` filtered by group.
	 */
	public const AS_GROUP = 'spreadconnect';

	/**
	 * The seven webhook events the plugin must keep registered with
	 * Spreadconnect at all times (architecture.md Z. 41 + Z. 175).
	 *
	 * Mirrored — and validated against — by
	 * {@see Subscription::ALLOWED_EVENT_TYPES}; the constant here is the
	 * **diff** input set, the DTO constant is the response-shape whitelist.
	 *
	 * @var list<string>
	 */
	final public const EXPECTED_EVENTS = array(
		'Article.added',
		'Article.updated',
		'Article.removed',
		'Order.processed',
		'Order.cancelled',
		'Order.needs-action',
		'Shipment.sent',
	);

	/**
	 * REST namespace + route exposed by the slice-15 webhook receiver.
	 *
	 * Single-source-of-truth for the URL we send to Spreadconnect on
	 * every `createSubscription` call — must match the route registered
	 * in {@see \SpreadconnectPod\Webhook\WebhookController::registerRoutes()}.
	 */
	private const WEBHOOK_REST_ROUTE = 'spreadconnect/v1/webhook';

	/**
	 * Option key for the inline persistent-admin-notice stub used by
	 * {@see self::driftCheck()} until slice-39 ships the proper
	 * `Failure\AdminNoticeStore`.
	 */
	private const ADMIN_NOTICES_OPTION = 'spreadconnect_admin_notices';

	/**
	 * Logger source (architecture.md Z. 398). Shared with
	 * {@see SpreadconnectClient} so dashboards filter on a single source
	 * for now; slice-42 may split it into
	 * `spreadconnect-subscription-service`.
	 */
	private const LOG_SOURCE = 'spreadconnect-api-client';

	/**
	 * Register the WP-hook listeners owned by this slice.
	 *
	 * Called once from {@see \SpreadconnectPod\Bootstrap\Plugin::init()}.
	 * Hook list:
	 *   - `spreadconnect/webhook_secret_rotated` (slice-14 producer) →
	 *     {@see self::resubscribeAll()} with the freshly rotated secret.
	 *   - `updated_option_spreadconnect_api_key` (WP core after settings
	 *     persistence) → {@see self::onApiKeySaved()} which orchestrates
	 *     the authenticate → generate-if-empty → register pipeline.
	 *   - {@see self::HOOK_DRIFT_CHECK} (Action-Scheduler) →
	 *     {@see self::driftCheck()}.
	 *
	 * Idempotency: WP de-duplicates identical static-method/priority
	 * pairs; the `Bootstrap\Plugin::init()` `$initialized` guard keeps
	 * the registration count at exactly one per request anyway.
	 */
	public static function bootListeners(): void
	{
		add_action(
			WebhookSecretManager::ACTION_ROTATED,
			[ self::class, 'onSecretRotated' ],
			10,
			2
		);

		add_action(
			'updated_option_spreadconnect_api_key',
			[ self::class, 'onApiKeySaved' ],
			10,
			3
		);

		add_action(
			self::HOOK_DRIFT_CHECK,
			[ self::class, 'driftCheck' ],
			10,
			0
		);
	}

	/**
	 * Schedule the weekly drift-check Action-Scheduler job.
	 *
	 * Called from `Bootstrap\Plugin::init()` (or the activate hook) —
	 * idempotent: pre-checks `as_next_scheduled_action()` so a re-activate
	 * never produces a duplicate schedule (AC-9). Skipped silently when
	 * Action-Scheduler functions are unavailable (e.g. unit-test bootstrap
	 * without WC).
	 */
	public static function scheduleRecurringDriftCheck(): void
	{
		if ( ! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		// Idempotency pre-check (AC-9): bail when the recurring action
		// is already on the schedule. AS returns either an int (timestamp)
		// or `false` — anything truthy means "already scheduled".
		$existing = as_next_scheduled_action( self::HOOK_DRIFT_CHECK, [], self::AS_GROUP );
		if ( false !== $existing && null !== $existing && 0 !== $existing ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			WEEK_IN_SECONDS,
			self::HOOK_DRIFT_CHECK,
			[],
			self::AS_GROUP
		);
	}

	/**
	 * Diff the expected 7 events against the live `GET /subscriptions`
	 * response, classifying each entry into `active`, `missing` or `orphans`.
	 *
	 * Classification rules (slice-18 AC-1 / AC-2 / Constraints):
	 *   - `active`  — eventType ∈ {@see self::EXPECTED_EVENTS} AND
	 *                  `callbackUrl === currentCallbackUrl()` (strict equality).
	 *   - `missing` — eventType ∈ {@see self::EXPECTED_EVENTS} AND no
	 *                  matching active entry.
	 *   - `orphans` — entries on our callback-URL that are NOT in the
	 *                  expected list, OR entries whose eventType IS expected
	 *                  but whose `callbackUrl` is a stale variant of ours
	 *                  (different scheme, port, host — exact-string compare).
	 *
	 * Foreign callback URLs (entries on third-party shops) are
	 * **never** classified as orphans — the URL-match owner gate is the
	 * boundary architecture.md Z. 108 enforces.
	 *
	 * @return array{
	 *   active:  list<string>,
	 *   missing: list<string>,
	 *   orphans: list<array{id:string,eventType:string,callbackUrl:string}>,
	 * }
	 */
	public static function diff(): array
	{
		$expected = self::EXPECTED_EVENTS;
		$ours     = self::currentCallbackUrl();

		$client = static::makeClient();

		try {
			$remote = $client->getSubscriptions();
		} catch ( SpreadconnectTransientError $e ) {
			// Re-thrown unchanged so the outer caller (Action-Scheduler
			// in driftCheck, the settings-save side-effect, or slice-19
			// repair AJAX) can apply its own retry policy.
			throw $e;
		}

		/** @var array<string,bool> $expected_index */
		$expected_index = array_fill_keys( $expected, true );

		/** @var array<string,bool> $active_set */
		$active_set = array();

		/** @var list<array{id:string,eventType:string,callbackUrl:string}> $orphans */
		$orphans = array();

		foreach ( $remote as $sub ) {
			if ( ! $sub instanceof Subscription ) {
				continue;
			}

			// Foreign URL: never classify as orphan, never as active.
			// architecture.md Z. 108 — silently ignored.
			if ( $sub->callbackUrl !== $ours ) {
				$orphans[] = array(
					'id'          => $sub->id,
					'eventType'   => $sub->eventType,
					'callbackUrl' => $sub->callbackUrl,
				);
				continue;
			}

			// On-our-URL entries with an unexpected event type are also
			// orphans (defensive: the expected-event list could shrink
			// in a future minor release; we want to clean those up).
			if ( ! isset( $expected_index[ $sub->eventType ] ) ) {
				$orphans[] = array(
					'id'          => $sub->id,
					'eventType'   => $sub->eventType,
					'callbackUrl' => $sub->callbackUrl,
				);
				continue;
			}

			// Expected event on our URL → active.
			$active_set[ $sub->eventType ] = true;
		}

		// AC-2 fix-up: orphans on a stale variant of OUR URL must surface
		// the corresponding expected event under `missing` AND under
		// `orphans`. The first foreach already populated `$orphans` with
		// every non-equal-URL entry; we strip foreign-URL entries from
		// the orphan list by post-filter — anything whose URL contains
		// our REST namespace path is "ours-but-stale" and belongs in
		// orphans. Foreign shops never share that namespace.
		$ours_orphans = array();
		foreach ( $orphans as $entry ) {
			if ( self::isOwnedCallbackUrl( $entry['callbackUrl'] ) ) {
				$ours_orphans[] = $entry;
			}
		}

		/** @var list<string> $missing */
		$missing = array();
		foreach ( $expected as $event ) {
			if ( ! isset( $active_set[ $event ] ) ) {
				$missing[] = $event;
			}
		}

		return array(
			'active'  => array_keys( $active_set ),
			'missing' => $missing,
			'orphans' => $ours_orphans,
		);
	}

	/**
	 * Diff and repair: register every missing event, leave existing
	 * subscriptions untouched.
	 *
	 * Returns a summary tuple (slice-18 AC-4 contract):
	 *   - `added`   — count of `createSubscription` calls that succeeded.
	 *   - `removed` — always `0` here (use {@see self::removeOrphans()} or
	 *                  {@see self::resubscribeAll()} for delete flows).
	 *   - `skipped` — count of expected events that were already active.
	 *   - `errors`  — list of `{eventType, message}` tuples for 4xx
	 *                  failures collected during the loop. Transient
	 *                  errors are re-thrown unchanged (AS retries).
	 *
	 * @return array{added:int, removed:int, skipped:int, errors:list<array{eventType:string, message:string}>}
	 */
	public static function register(): array
	{
		$state = self::diff();

		$secret = WebhookSecretManager::peek();
		$ours   = self::currentCallbackUrl();
		$client = static::makeClient();

		$added   = 0;
		$skipped = count( $state['active'] );

		/** @var list<array{eventType:string,message:string}> $errors */
		$errors = array();

		foreach ( $state['missing'] as $event ) {
			try {
				$client->createSubscription( $event, $ours, $secret );
				++$added;

				self::log(
					'info',
					sprintf( 'subscription_registered eventType=%s', $event )
				);
			} catch ( SpreadconnectTransientError $e ) {
				// AC-6: re-thrown so Action-Scheduler can retry the
				// outer job. The loop body deliberately stops — partial
				// progress is fine; AS will pick up the rest on next run.
				throw $e;
			} catch ( SpreadconnectClientError $e ) {
				// AC-6: 4xx (validation, conflict, …) collected into
				// the summary; loop continues so the remaining events
				// still get a chance.
				$errors[] = array(
					'eventType' => $event,
					'message'   => self::translate( 'Subscription registration failed' ),
				);

				self::log(
					'warning',
					sprintf( 'subscription_register_failed eventType=%s', $event )
				);
			} catch ( Throwable $e ) {
				// Defensive net: never let a stray exception abort the
				// loop. Persist the failure under the same shape so the
				// caller can surface a single uniform error list.
				$errors[] = array(
					'eventType' => $event,
					'message'   => self::translate( 'Subscription registration failed' ),
				);
			}
		}

		return array(
			'added'   => $added,
			'removed' => 0,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Remove every orphan subscription owned by this site.
	 *
	 * Iterates the orphans list returned by {@see self::diff()} (always
	 * URL-owned by us — foreign shops are excluded by the diff filter)
	 * and calls `DELETE /subscriptions/{id}`. Returns the count of
	 * successful deletes.
	 *
	 * Errors are NOT collected — a failed delete is logged at WARN and
	 * the loop continues; the caller can re-run `removeOrphans()` to
	 * retry. Transient errors are re-thrown so AS-driven callers
	 * benefit from the retry cascade.
	 */
	public static function removeOrphans(): int
	{
		$state  = self::diff();
		$client = static::makeClient();

		$removed = 0;

		foreach ( $state['orphans'] as $entry ) {
			$id  = $entry['id'];
			$url = $entry['callbackUrl'];

			// Defence-in-depth: re-verify ownership of the callback URL
			// even though `diff()` has already filtered foreign URLs out.
			// architecture.md Z. 108 mandates an URL-match check directly
			// before every DELETE call.
			if ( ! self::isOwnedCallbackUrl( $url ) ) {
				continue;
			}

			try {
				$client->deleteSubscription( $id );
				++$removed;

				self::log(
					'info',
					sprintf(
						'subscription_removed id=%s eventType=%s',
						$id,
						$entry['eventType']
					)
				);
			} catch ( SpreadconnectTransientError $e ) {
				throw $e;
			} catch ( SpreadconnectClientError $e ) {
				self::log(
					'warning',
					sprintf(
						'subscription_remove_failed id=%s eventType=%s',
						$id,
						$entry['eventType']
					)
				);
			}
		}

		return $removed;
	}

	/**
	 * DELETE-then-POST sweep used after a secret rotation.
	 *
	 * Phase ordering is mandatory (slice-18 AC-8): the entire delete
	 * phase MUST complete before the create phase starts, otherwise an
	 * intermediate `getSubscriptions()` would mix entries signed with
	 * the old and the new secret.
	 *
	 * @param string             $newSecret The freshly rotated secret
	 *                                       passed by the
	 *                                       `spreadconnect/webhook_secret_rotated`
	 *                                       hook (slice-14).
	 * @param array<string,mixed> $context   Free-form context payload
	 *                                       forwarded by the hook
	 *                                       (currently `is_initial`,
	 *                                       `generated_at`).
	 *
	 * @return array{added:int, removed:int, skipped:int, errors:list<array{eventType:string, message:string}>}
	 */
	public static function resubscribeAll( string $newSecret, array $context = array() ): array
	{
		unset( $context ); // Currently unused — kept for hook-arity stability.

		$client = static::makeClient();

		// Phase 1 — DELETE: remove every existing subscription owned by us
		// (active + orphans). We deliberately bypass `removeOrphans()` so
		// active entries are also dropped (otherwise the subsequent POST
		// would 409 on duplicates).
		try {
			$remote = $client->getSubscriptions();
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		}

		$removed = 0;
		foreach ( $remote as $sub ) {
			if ( ! $sub instanceof Subscription ) {
				continue;
			}
			if ( ! self::isOwnedCallbackUrl( $sub->callbackUrl ) ) {
				continue;
			}

			try {
				$client->deleteSubscription( $sub->id );
				++$removed;
			} catch ( SpreadconnectTransientError $e ) {
				throw $e;
			} catch ( SpreadconnectClientError $e ) {
				self::log(
					'warning',
					sprintf(
						'subscription_resubscribe_delete_failed id=%s eventType=%s',
						$sub->id,
						$sub->eventType
					)
				);
			}
		}

		// Phase 2 — POST: re-create all 7 expected events with the new
		// secret. Cannot delegate to `register()` because that re-runs
		// `diff()` (which would race with the just-completed delete
		// phase if SC's read view is eventually consistent). Loop is
		// inlined to use `$newSecret` directly without touching the
		// option (option write is owned by slice-14).
		$ours    = self::currentCallbackUrl();
		$added   = 0;
		$errors  = array();

		foreach ( self::EXPECTED_EVENTS as $event ) {
			try {
				$client->createSubscription( $event, $ours, $newSecret );
				++$added;
			} catch ( SpreadconnectTransientError $e ) {
				throw $e;
			} catch ( SpreadconnectClientError $e ) {
				$errors[] = array(
					'eventType' => $event,
					'message'   => self::translate( 'Subscription registration failed' ),
				);
			}
		}

		self::log(
			'info',
			sprintf( 'subscription_resubscribed added=%d removed=%d', $added, $removed )
		);

		return array(
			'added'   => $added,
			'removed' => $removed,
			'skipped' => 0,
			'errors'  => $errors,
		);
	}

	/**
	 * Action-Scheduler handler for the weekly drift-check hook.
	 *
	 * Self-healing semantics: on detected drift (missing OR orphans
	 * non-empty) the manager calls `register()` (idempotent), removes
	 * orphans, and writes a persistent admin notice so the operator
	 * sees the auto-repair on next page load. A drift-free run is a
	 * silent no-op — we don't spam notices.
	 *
	 * Transient errors propagate so Action-Scheduler can apply the
	 * outer 1m / 5m / 15m retry cascade (architecture.md Z. 644).
	 */
	public static function driftCheck(): void
	{
		try {
			$state = self::diff();
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			// 4xx on the read path is most likely an auth issue —
			// swallow silently here, the settings-save listener will
			// surface it next time the user changes the key.
			return;
		}

		$missing = $state['missing'];
		$orphans = $state['orphans'];

		if ( array() === $missing && array() === $orphans ) {
			// Drift-free — no notice, no API write.
			return;
		}

		$summary = self::register();

		// Best-effort orphan cleanup; failures here are already logged
		// inside `removeOrphans()`. The notice reflects the intent count
		// from `$state` so the operator sees what the diff detected.
		$removed = self::removeOrphans();

		self::pushAdminNotice(
			sprintf(
				/* translators: %1$d added, %2$d removed */
				self::translate( 'Subscriptions out of sync — auto-repaired (added: %1$d, removed: %2$d)' ),
				$summary['added'],
				$removed
			)
		);
	}

	/**
	 * Listener for `WebhookSecretManager::ACTION_ROTATED` (slice-14).
	 *
	 * Exists as a thin pass-through so the `add_action` callable is a
	 * named static method (easier to test, easier to inspect via
	 * `has_action`). Forwards both arguments to
	 * {@see self::resubscribeAll()}.
	 *
	 * @param string              $newSecret Freshly rotated secret.
	 * @param array<string,mixed> $context   Hook context payload.
	 */
	public static function onSecretRotated( string $newSecret, array $context = array() ): void
	{
		self::resubscribeAll( $newSecret, $context );
	}

	/**
	 * Listener for the `updated_option_spreadconnect_api_key` WP hook.
	 *
	 * Fires AFTER WP has persisted the new API-key value (slice-11
	 * SettingsValidator already ran). Side-effects (slice-18 AC-7):
	 *   1. Verify the new key against `GET /authentication`.
	 *   2. If verification succeeds AND the webhook secret has never
	 *      been generated, run {@see WebhookSecretManager::generate()}.
	 *      The generate-call itself fires `ACTION_ROTATED`, so the
	 *      first-ever subscribe runs through {@see self::resubscribeAll()}
	 *      automatically — we do NOT call `register()` directly in that
	 *      branch to avoid a duplicate POST sweep.
	 *   3. Otherwise (secret already exists), call `register()` to
	 *      cover the missing-events / re-key case.
	 *
	 * Failure of step 1 short-circuits the rest: the form-submit save
	 * has already succeeded (the option is persisted); the manager just
	 * skips the auto-subscribe so no half-configured state lands at SC.
	 *
	 * @param mixed  $oldValue Previous option value (unused).
	 * @param mixed  $newValue New persisted option value (unused — read
	 *                          per request inside `SpreadconnectClient`).
	 * @param string $option   Option name (passed by WP, ignored — the
	 *                          hook is per-key already).
	 */
	public static function onApiKeySaved( $oldValue = null, $newValue = null, $option = '' ): void
	{
		unset( $oldValue, $newValue, $option );

		// Empty new key → operator cleared the field. Don't try to
		// authenticate against an empty Bearer (the client would refuse
		// pre-flight anyway), and don't touch existing subscriptions.
		$client = static::makeClient();

		try {
			$client->authenticate();
		} catch ( Throwable $e ) {
			// AC-7: invalid connection → no subscribe, no secret
			// generation. The settings-form already saved the key; the
			// operator will see the failure via the inline "Test
			// Connection" UI (slice-12) on the next page-render.
			self::log(
				'warning',
				'subscription_auto_register_skipped reason=auth_failed'
			);
			return;
		}

		$secret = WebhookSecretManager::peek();

		if ( '' === $secret ) {
			// Initial-setup branch: generating the secret fires
			// `ACTION_ROTATED` which is already wired to
			// `onSecretRotated` → `resubscribeAll`. The generate path
			// covers the full POST sweep with the new secret, so we
			// must NOT call `register()` again here.
			WebhookSecretManager::generate();
			return;
		}

		// Secret already exists → register-only sweep (idempotent).
		self::register();
	}

	/**
	 * Resolve the current canonical callback URL for SC subscriptions.
	 *
	 * Single-source-of-truth for the URL we register and the boundary
	 * value `diff()` compares against. Must match the route shipped
	 * by slice-15's `WebhookController` exactly — the namespace path
	 * is hard-coded {@see self::WEBHOOK_REST_ROUTE}.
	 *
	 * `home_url()` is preferred over `rest_url()` so the URL is stable
	 * across REST-prefix-changing plugins (e.g. WP-API permalink
	 * filters): the HMAC verifier uses the canonical URL too. We
	 * normalise to `https` when available — SC will reject `http://`
	 * subscriptions on production.
	 */
	public static function currentCallbackUrl(): string
	{
		// `home_url('wp-json/spreadconnect/v1/webhook')` produces the
		// REST-prefixed URL on default WP installs. The `https` scheme
		// hint is the preferred public-internet form; on a localhost
		// dev shop `home_url()` still respects the persisted scheme.
		if ( function_exists( 'home_url' ) ) {
			$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : null;

			return home_url( 'wp-json/' . self::WEBHOOK_REST_ROUTE, $scheme );
		}

		// Test bootstrap fallback — Brain\Monkey will normally stub
		// `home_url`, but a missing stub should still produce a stable
		// string so unit tests don't crash on the helper call.
		return 'http://example.test/wp-json/' . self::WEBHOOK_REST_ROUTE;
	}

	/**
	 * Slice 46 AC-11: read the cached `{active, total}` subscription counts
	 * for the Hub Dashboard "Webhooks" card.
	 *
	 * Reads the `sc_subscriptions_status` transient (writer: external —
	 * Slice 18 / Slice 19 repair flow). When the transient is missing or
	 * malformed the method returns the safe defaults
	 * `['active' => 0, 'total' => 7]` so the card always renders an integer
	 * pair — never triggers a live `getSubscriptions()` call.
	 *
	 * @return array{active:int,total:int}
	 */
	public static function getCachedStatus(): array
	{
		$default = array(
			'active' => 0,
			'total'  => count( self::EXPECTED_EVENTS ),
		);

		if ( ! function_exists( 'get_transient' ) ) {
			return $default;
		}

		$value = get_transient( 'sc_subscriptions_status' );
		if ( ! is_array( $value ) ) {
			return $default;
		}

		$active = isset( $value['active'] ) && is_numeric( $value['active'] )
			? (int) $value['active']
			: 0;
		$total = isset( $value['total'] ) && is_numeric( $value['total'] ) && (int) $value['total'] > 0
			? (int) $value['total']
			: count( self::EXPECTED_EVENTS );

		return array(
			'active' => $active,
			'total'  => $total,
		);
	}

	/**
	 * Test seam for the `SpreadconnectClient` collaborator.
	 *
	 * Production returns a fresh client; test subclasses substitute a
	 * mock implementation. Kept `protected` so a subclass in the
	 * Tests namespace can override without touching production code.
	 */
	protected static function makeClient(): SpreadconnectClient
	{
		return new SpreadconnectClient();
	}

	/**
	 * Whether a callback URL is "ours" (i.e. belongs to this plugin's
	 * subscription set), as opposed to a foreign URL that happens to
	 * appear in the same SC account's subscription list.
	 *
	 * Used by both `diff()` (to keep foreign URLs out of the orphan
	 * list) and `removeOrphans()` (defence-in-depth pre-DELETE check).
	 * Ownership is determined by **REST-namespace path match only**:
	 *   - URL contains `wp-json/spreadconnect/v1/webhook` → ours.
	 *   - Any other URL → foreign (never deleted).
	 *
	 * Slice-18 AC-2: a stale URL pointing at the SAME plugin's webhook
	 * namespace but a different host/scheme/port (e.g. after a domain
	 * rename) IS classified as ours-but-stale → goes into orphans.
	 * Slice-18 AC-1: a URL like `https://other-shop.example/webhook`
	 * (no namespace path) is foreign → silently skipped.
	 *
	 * Hostname equality is deliberately NOT required: a domain rename
	 * scenario would otherwise leave the old subscriptions orphaned at
	 * Spreadconnect forever.
	 */
	private static function isOwnedCallbackUrl( string $url ): bool
	{
		// Empty / malformed URLs are never owned.
		if ( '' === $url ) {
			return false;
		}

		// REST-namespace path match is the ownership signal. The
		// substring check is intentional — `parse_url()`'s `path`
		// component normalisation would produce false negatives for
		// URLs with explicit ports or trailing slashes, and a hostile
		// foreign URL containing our namespace path would have to be
		// registered against our own SC account anyway (API-key
		// scoped) before it could appear in `getSubscriptions()`.
		return false !== strpos( $url, self::WEBHOOK_REST_ROUTE );
	}

	/**
	 * Append a single message to the persistent admin-notice queue.
	 *
	 * Inline stub for the slice-39 `Failure\AdminNoticeStore`. Stored
	 * as a flat list under `spreadconnect_admin_notices` so the eventual
	 * `Failure\AdminNoticeStore` can pick the rows up unchanged.
	 */
	private static function pushAdminNotice( string $message ): void
	{
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$existing = get_option( self::ADMIN_NOTICES_OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = array(
			'message'   => $message,
			'level'     => 'warning',
			'source'    => 'subscription-manager',
			'timestamp' => time(),
		);

		update_option( self::ADMIN_NOTICES_OPTION, $existing );
	}

	/**
	 * `__()` wrapper that survives unit-test bootstraps without WP loaded.
	 *
	 * Brain\Monkey stubs `__` to a passthrough; this guard is a defensive
	 * net for the very early-bootstrap path where the stub may not be
	 * registered yet (e.g. when Slice 18 is loaded by composer's
	 * autoloader before Brain\Monkey setUp).
	 */
	private static function translate( string $message ): string
	{
		if ( function_exists( '__' ) ) {
			return __( $message, 'spreadconnect-pod' );
		}

		return $message;
	}

	/**
	 * Logger shim — never emits the plaintext secret.
	 *
	 * Mirrors the {@see SpreadconnectClient::log()} contract: requires
	 * `wc_get_logger()` to be available; silently no-ops otherwise so
	 * unit tests don't depend on WC being booted.
	 */
	private static function log( string $level, string $message ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
