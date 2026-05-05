<?php
/**
 * REST controller for `POST /wp-json/spreadconnect/v1/webhook`
 * (slice-15 wiring + slice-16 handler).
 *
 * Slice 15 shipped the **auth layer + route wiring**: HMAC-gate in the
 * `permission_callback` and a trivial 200-stub handler. Slice 16 replaces
 * the stub handler with the full receive pipeline as documented in
 * architecture.md Z. 432-450 (Flow E):
 *
 *   1. parse the JSON body (best-effort — failures collapse to the
 *      `_unknown` marker path),
 *   2. compute the deterministic `event_id` via
 *      {@see EventIdHasher::compute()},
 *   3. `INSERT IGNORE INTO wp_spreadconnect_webhook_log` via
 *      {@see WebhookLogRepo::insertOrIgnore()},
 *   4. on `'inserted'`: `as_enqueue_async_action(
 *      'spreadconnect/process_webhook_event', [$log_id], 'spreadconnect')`
 *      + return `202 [accepted]`,
 *   5. on `'duplicate'`: NO async-schedule, return `200 duplicate`.
 *
 * Auth model (architecture.md Z. 483 + Z. 514):
 *   - Public route — no `manage_woocommerce` gate. Spreadconnect is an
 *     anonymous caller; HMAC-SHA256 over the raw body **is** the auth.
 *   - {@see self::authorize()} reads the raw bytes via
 *     `$request->get_body()` (NOT `get_json_params()` — that would
 *     decode-then-re-encode the JSON and break the byte-stable HMAC),
 *     then delegates to the pure-domain
 *     {@see WebhookSignatureVerifier::verify()}.
 *   - Failures funnel through {@see self::logRejected()}, which emits a
 *     redacted `error_log` line carrying ONLY the IP, the list of
 *     header **names** (no values) and a short reason marker. No raw
 *     body, no signature bytes, no secret ever reach the log.
 *
 * ACK-≤-8s constraint (architecture.md Z. 638): the handler performs no
 * domain mutation — only HMAC verify (slice-15) + INSERT + AS-enqueue +
 * return. Schema-validation, dispatch and persistence updates are
 * deferred to {@see \SpreadconnectPod\Webhook\ProcessWebhookEventJob}
 * (slice-17).
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;
use SpreadconnectPod\Subscription\WebhookSecretManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stateless adapter wiring the SC webhook receive route into WP REST.
 *
 * Final + all-static — there is no instance state. The controller's
 * collaborators are global (the persisted secret option, the WP REST
 * registry, `error_log`); injecting them through a constructor would
 * not buy any testability that Brain\Monkey + Patchwork do not already
 * supply.
 */
final class WebhookController
{
	/**
	 * REST namespace shared with {@see \SpreadconnectPod\Hub\Rest\SyncProgress}
	 * — architecture.md Z. 127-132.
	 */
	public const ROUTE_NAMESPACE = 'spreadconnect/v1';

	/**
	 * REST route path appended to {@see self::ROUTE_NAMESPACE}.
	 */
	public const ROUTE_PATH = '/webhook';

	/**
	 * Header carrying the base64-encoded HMAC-SHA256 signature, as
	 * documented by Spreadconnect (architecture.md Z. 466).
	 *
	 * WordPress normalises header names to lower-case before lookup
	 * (`WP_REST_Request::get_header()`), but we keep the canonical mixed
	 * case as a constant for log-context emission and reader clarity.
	 */
	public const SIGNATURE_HEADER = 'X-SPRD-SIGNATURE';

	/**
	 * Lower-case form used for `WP_REST_Request::get_header()`. WP's
	 * internal `get_header()` calls `strtolower()` on the supplied name
	 * before the lookup; pre-lowercasing lets us pass the constant
	 * directly without rewrapping at every call site.
	 */
	private const SIGNATURE_HEADER_LC = 'x-sprd-signature';

	/**
	 * Logger source-marker for `error_log` rejection lines. Mirrors the
	 * `WcLoggerAdapter` source identifier introduced by slice-42
	 * (architecture.md Z. 398) so a future swap from `error_log` to
	 * `WcLoggerAdapter::warn()` keeps log-grep recipes unchanged.
	 */
	private const LOG_SOURCE = 'spreadconnect-webhook-receiver';

	/**
	 * `WP_Error` code returned on every authentication failure
	 * — slice-15 AC-3.
	 */
	private const REJECT_ERROR_CODE = 'spreadconnect_webhook_unauthorized';

	/**
	 * Reason marker logged when the request never carried a signature
	 * header (or the header was empty).
	 */
	private const REASON_MISSING_HEADER = 'missing_header';

	/**
	 * Reason marker logged when a header was present but the bytes did
	 * not authenticate (HMAC mismatch OR non-base64 input).
	 */
	private const REASON_INVALID_HMAC = 'invalid_hmac';

	/**
	 * Plugin text-domain for translatable error messages.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Action Scheduler hook name dispatched on fresh-insert
	 * (architecture.md Z. 449 + Z. 553). Slice 17 registers the listener
	 * via `add_action(self::ASYNC_HOOK, [ProcessWebhookEventJob::class,
	 * 'handle'], 10, 1)`.
	 */
	private const ASYNC_HOOK = 'spreadconnect/process_webhook_event';

	/**
	 * Action Scheduler group used for ALL AS actions emitted by this
	 * plugin (architecture.md Z. 657). Centralises Hub-Admin →
	 * Scheduled-Actions filtering under a single well-known label.
	 */
	private const ASYNC_GROUP = 'spreadconnect';

	/**
	 * Literal `text/plain` body returned on a fresh insert (AC-5).
	 * Architecture.md Z. 85 / Z. 638 / Z. 678 fix the exact byte
	 * sequence — including the surrounding square brackets — as the
	 * Spreadconnect-side ACK-recognition pattern.
	 */
	private const ACK_BODY_ACCEPTED = '[accepted]';

	/**
	 * Literal `text/plain` body returned on a UNIQUE-event_id conflict
	 * (AC-7). Architecture.md Z. 448 mandates `200` (NOT `202`) for this
	 * path so SC suppresses any retry of the duplicate delivery.
	 */
	private const ACK_BODY_DUPLICATE = 'duplicate';

	/**
	 * Register the inbound webhook REST route.
	 *
	 * Bound to `rest_api_init` from {@see \SpreadconnectPod\Bootstrap\Plugin::init()}.
	 * The route is **public** (`permission_callback` does the HMAC
	 * gating) and accepts `POST` only — slice-15 AC-1.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'authorize' ),
			)
		);
	}

	/**
	 * `permission_callback` — HMAC-SHA256 gate.
	 *
	 * Pre-checks the header BEFORE invoking the verifier so an absent
	 * header never triggers an `hash_hmac`/`hash_equals` round-trip
	 * (slice-15 AC-3, defense-in-depth: distinguishable log reason).
	 *
	 * On rejection the method returns a `WP_Error` with HTTP 401; WP
	 * REST converts that into a `{code, message, data:{status:401}}`
	 * response without invoking {@see self::handle()}. The companion
	 * {@see self::logRejected()} emits a redacted log line (IP +
	 * header-names + reason) — the raw body, signature bytes and
	 * secret never leak into the log channel (architecture.md Z. 493 +
	 * Z. 609).
	 *
	 * @param WP_REST_Request $request Incoming WP REST request.
	 *
	 * @return bool|WP_Error `true` when the HMAC authenticates,
	 *                       `WP_Error` (401) otherwise.
	 */
	public static function authorize( WP_REST_Request $request )
	{
		$signature = (string) $request->get_header( self::SIGNATURE_HEADER_LC );

		// AC-3: missing OR empty header collapses into a single 401
		// reason marker — no verifier call.
		if ( '' === $signature ) {
			self::logRejected( $request, self::REASON_MISSING_HEADER );

			return self::buildRejectError();
		}

		// AC-2: read the raw bytes — NEVER `get_json_params()`. JSON
		// re-encoding would shuffle key order / re-quote strings and
		// break the byte-stable HMAC.
		$rawBody = (string) $request->get_body();

		// AC-2: secret lookup goes through the slice-14 single-source-
		// of-truth accessor, not direct `get_option()`.
		$secret = WebhookSecretManager::peek();

		if ( WebhookSignatureVerifier::verify( $rawBody, $signature, $secret ) ) {
			return true;
		}

		// AC-4 / AC-7: any non-`true` result — non-base64 header, byte
		// mismatch, empty-secret short-circuit — funnels into the same
		// invalid_hmac log marker. The caller (SC) sees an opaque 401
		// either way.
		self::logRejected( $request, self::REASON_INVALID_HMAC );

		return self::buildRejectError();
	}

	/**
	 * Webhook handler — slice-16 receive pipeline.
	 *
	 * Sequence (architecture.md Flow E, Z. 444-450):
	 *   1. read the raw bytes once (`$request->get_body()`); best-effort
	 *      `json_decode` to extract `eventType` + `data.entity.id`,
	 *   2. compute the deterministic `event_id` via
	 *      {@see EventIdHasher::compute()}; on missing keys / bad JSON
	 *      fall through to the `_unknown` marker path (AC-9),
	 *   3. assemble the row, call
	 *      {@see WebhookLogRepo::insertOrIgnore()},
	 *   4. branch on `$result['status']`:
	 *        - `'inserted'` ⇒ `as_enqueue_async_action(...)` + `202 [accepted]`,
	 *        - `'duplicate'` ⇒ NO schedule, `200 duplicate`.
	 *
	 * The function makes ZERO domain mutations — by design. The
	 * 8-second ACK budget (architecture.md Z. 638) does not allow any
	 * order/article work in the request thread; everything heavy is the
	 * job's responsibility (slice-17).
	 *
	 * Body strings are literal `text/plain` (NOT JSON, no surrounding
	 * quotes) per architecture.md Z. 85 / Z. 450 / Z. 678.
	 *
	 * @param WP_REST_Request $request Authenticated request (already
	 *                                 passed the HMAC `permission_callback`,
	 *                                 so `hmac_status='valid'` is implied).
	 *
	 * @return WP_REST_Response 202 + `[accepted]` on fresh insert,
	 *                          200 + `duplicate` on UNIQUE-conflict.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response
	{
		// Step 1: read the raw bytes ONCE. `$request->get_body()` is the
		// only API that returns the byte-stable input; `get_json_params()`
		// would decode-then-re-encode and silently mangle key order.
		$rawBody = (string) $request->get_body();

		// Step 2: best-effort JSON parse. A malformed body or missing
		// pflicht-keys is NOT a 4xx in this method — AC-9 mandates that
		// every HMAC-valid delivery yields a 202 ACK and an inserted row;
		// schema-validation is the job's job (slice-17, sets
		// `processing_status='error'` with `unknown_event_type`).
		$decoded = json_decode( $rawBody, true );

		$eventType = '';
		$entityId  = '';
		$payload   = is_array( $decoded ) ? $decoded : array();

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['eventType'] ) && is_string( $decoded['eventType'] ) ) {
				$eventType = $decoded['eventType'];
			}

			if (
				isset( $decoded['data']['entity']['id'] )
				&& ( is_string( $decoded['data']['entity']['id'] ) || is_int( $decoded['data']['entity']['id'] ) )
			) {
				$entityId = (string) $decoded['data']['entity']['id'];
			}
		}

		// AC-9: any missing piece collapses into the `_unknown` marker
		// triple. The marker values are deterministic, so duplicate
		// malformed deliveries still hit the UNIQUE-event_id idempotency
		// barrier (slice-17 will mark them as
		// `processing_error='unknown_event_type'`).
		$markerEventType   = '_unknown';
		$markerEntityId    = '_';
		$resolvedEventType = '' !== $eventType ? $eventType : $markerEventType;
		$resolvedEntityId  = '' !== $entityId ? $entityId : $markerEntityId;
		// Entity-type derivation must follow the resolved event-type so
		// that the `_unknown` marker path lands in `'unknown'` exactly
		// once (AC-9: `related_entity_type='unknown'`).
		$resolvedEntityType = self::resolveEntityType( $resolvedEventType );

		try {
			$eventId = EventIdHasher::compute( $resolvedEventType, $resolvedEntityId, $rawBody );
		} catch ( \InvalidArgumentException $e ) {
			// Defensive: if the marker constants ever drift to empty
			// strings we still want to hash deterministically. Re-call
			// with the canonical markers as a hard fallback.
			$eventId = EventIdHasher::compute( $markerEventType, $markerEntityId, $rawBody );
		}

		// Step 3: assemble the row (architecture.md Z. 212-231).
		// `payload` stores the RE-ENCODED JSON view, not the raw body —
		// architecture.md Z. 221 explicitly notes this trade-off
		// (HMAC-re-verify is impossible after storage; readability in the
		// admin UI is preserved).
		$payloadJson = wp_json_encode( $payload );
		if ( ! is_string( $payloadJson ) ) {
			// `wp_json_encode` only fails on non-encodable input (NaN,
			// resources, ...); a decoded webhook body never contains
			// those. The empty-array fallback keeps the schema's
			// `NOT NULL` contract intact.
			$payloadJson = '[]';
		}

		$row = array(
			'event_type'          => $resolvedEventType,
			'event_id'            => $eventId,
			'related_entity_type' => $resolvedEntityType,
			'related_entity_id'   => $resolvedEntityId,
			'payload'             => $payloadJson,
			'hmac_status'         => 'valid',
			'processing_status'   => WebhookLogRepo::STATUS_PENDING,
			'received_at'         => (string) current_time( 'mysql', true ),
		);

		try {
			$result = WebhookLogRepo::insertOrIgnore( $row );
		} catch ( \RuntimeException $e ) {
			// Repo only throws on non-Duplicate insert failures (schema
			// mismatch, connection loss, ...). The 8-second ACK budget
			// forbids any retry loop here — surface a 500 so SC retries
			// the delivery on its own schedule (architecture.md Z. 638).
			return new WP_REST_Response(
				'error',
				500,
				array( 'Content-Type' => 'text/plain; charset=utf-8' )
			);
		}

		$logId  = isset( $result['log_id'] ) ? (int) $result['log_id'] : 0;
		$status = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : '';

		// Step 4: branch on the binary `inserted | duplicate` result.
		if ( WebhookLogRepo::STATUS_INSERTED === $status ) {
			// AC-5 + AC-10: schedule the async dispatcher with the log_id
			// as a single positional int. Group `'spreadconnect'` keeps
			// every AS action listable under one filter in WP-Admin →
			// Tools → Scheduled Actions (architecture.md Z. 657).
			as_enqueue_async_action(
				self::ASYNC_HOOK,
				array( $logId ),
				self::ASYNC_GROUP
			);

			return new WP_REST_Response(
				self::ACK_BODY_ACCEPTED,
				202,
				array( 'Content-Type' => 'text/plain; charset=utf-8' )
			);
		}

		// AC-7: duplicate path — NO schedule, 200 (NOT 202; SC must not
		// retry — architecture.md Z. 448).
		return new WP_REST_Response(
			self::ACK_BODY_DUPLICATE,
			200,
			array( 'Content-Type' => 'text/plain; charset=utf-8' )
		);
	}

	/**
	 * Map an `eventType` (e.g. `Order.processed`, `Article.updated`,
	 * `Shipment.sent`) to the schema's `related_entity_type` enum value.
	 *
	 * The mapping mirrors architecture.md Z. 446 + the Order/Article
	 * Event-Handler split (slices 25 + 30): `Order.*` and `Shipment.*`
	 * deliveries describe an order, `Article.*` deliveries describe an
	 * article. Anything we cannot recognise drops to `'unknown'` — slice
	 * 17 surfaces it as `processing_error='unknown_event_type'`.
	 *
	 * @param string $eventType Top-level webhook `eventType`.
	 *
	 * @return string One of {`order`, `article`, `unknown`}.
	 */
	private static function resolveEntityType( string $eventType ): string
	{
		if ( 0 === strpos( $eventType, 'Order.' ) || 0 === strpos( $eventType, 'Shipment.' ) ) {
			return WebhookLogRepo::ENTITY_TYPE_ORDER;
		}

		if ( 0 === strpos( $eventType, 'Article.' ) ) {
			return 'article';
		}

		return 'unknown';
	}

	/**
	 * Emit a redacted WARN log line for a rejected webhook request.
	 *
	 * Context redaction (slice-15 AC-7, architecture.md Z. 493 +
	 * Z. 609):
	 *   - `ip`     — `X-Forwarded-For` first-hop value when present,
	 *                else `$_SERVER['REMOTE_ADDR']`. Both are
	 *                operational signals only; PII handling is the same
	 *                as any other inbound request log.
	 *   - `headers` — only the **keys** of the request header map. No
	 *                 values — never the signature bytes, never any
	 *                 cookie payload.
	 *   - `reason`  — fixed enum {`missing_header`,`invalid_hmac`}.
	 *
	 * Routed through slice-42 {@see WcLoggerAdapter} so the entry lands
	 * in `wc-logs/spreadconnect-webhook-receiver-*` and the AC-10
	 * raw-`error_log` ban stays intact. The adapter additionally redacts
	 * any stray `X-SPRD-SIGNATURE` header value that might have leaked
	 * into a free-form context string — defence in depth on top of the
	 * `array_keys()` projection below.
	 *
	 * @param WP_REST_Request $request Incoming request (already deemed
	 *                                 unauthenticated — never log its
	 *                                 body or its signature header
	 *                                 value).
	 * @param string          $reason  One of the `REASON_*` constants.
	 *
	 * @return void
	 */
	private static function logRejected( WP_REST_Request $request, string $reason ): void
	{
		$ip = self::resolveClientIp( $request );

		$headers = $request->get_headers();
		$names   = is_array( $headers ) ? array_keys( $headers ) : array();

		// Plain-text-free message: only the redacted context fields are
		// concatenated. The signature header VALUE is never emitted; only
		// the header NAME survives the `array_keys()` projection.
		WcLoggerAdapter::warning(
			Sources::WEBHOOK_RECEIVER,
			sprintf(
				'webhook_rejected reason=%s ip=%s headers=%s',
				$reason,
				'' !== $ip ? $ip : '-',
				implode( ',', array_map( 'strval', $names ) )
			),
			array(
				'reason'  => $reason,
				'ip'      => $ip,
				'headers' => $names,
			)
		);
	}

	/**
	 * Best-effort client-IP resolution for log context.
	 *
	 * Order: `X-Forwarded-For` first hop → `$_SERVER['REMOTE_ADDR']` →
	 * empty string. We never trust `X-Forwarded-For` for anything
	 * security-relevant; it is purely a diagnostic field for the
	 * rejection log line.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return string Best-effort IP string or `''`.
	 */
	private static function resolveClientIp( WP_REST_Request $request ): string
	{
		$forwardedFor = (string) $request->get_header( 'x-forwarded-for' );
		if ( '' !== $forwardedFor ) {
			// First hop only — comma-separated chain ends at the edge.
			$first = trim( (string) strtok( $forwardedFor, ',' ) );
			if ( '' !== $first ) {
				return $first;
			}
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			return (string) $_SERVER['REMOTE_ADDR'];
		}

		return '';
	}

	/**
	 * Build the canonical 401 `WP_Error` returned by every rejection
	 * code-path.
	 *
	 * Centralised so the code, message and HTTP status stay perfectly
	 * consistent between the missing-header branch and the
	 * invalid-bytes branch — slice-15 AC-3 calls them out as one
	 * indistinguishable surface to the caller.
	 *
	 * @return WP_Error 401 error envelope.
	 */
	private static function buildRejectError(): WP_Error
	{
		return new WP_Error(
			self::REJECT_ERROR_CODE,
			__( 'Invalid or missing webhook signature.', self::TEXT_DOMAIN ),
			array( 'status' => 401 )
		);
	}
}
