<?php
/**
 * REST controller for `POST /wp-json/spreadconnect/v1/webhook` (slice-15).
 *
 * This slice ships the **auth layer + route wiring only** —
 * architecture.md Z. 432-450 (Flow E) describes the full receive
 * pipeline, but only the `permission_callback` HMAC gate and a
 * trivial 200-Stub handler land here. Slice 16 will rewrite
 * {@see self::handle()} to perform the deterministic event_id hashing,
 * `INSERT IGNORE INTO wp_spreadconnect_webhook_log`, AS-enqueue and
 * 202-ACK response.
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
 * Slice-16 contract: {@see self::handle()} stays trivially overridable.
 * The body must remain a single `return new WP_REST_Response(null, 200)`
 * line so slice-16's edit replaces a one-liner cleanly.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

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
	 * Webhook handler — SLICE-15 STUB.
	 *
	 * Returns an empty 200 response by design. Slice 16 rewrites this
	 * method to:
	 *   1. compute the deterministic `event_id`,
	 *   2. `INSERT IGNORE INTO wp_spreadconnect_webhook_log`,
	 *   3. `as_enqueue_async_action('spreadconnect/process_webhook_event', [log_id])`,
	 *   4. return HTTP 202 + literal body `[accepted]`.
	 *
	 * The slice-15 stub MUST stay a single `return` line so that the
	 * slice-16 edit produces a clean diff.
	 *
	 * @param WP_REST_Request $request Authenticated request (passed the
	 *                                 `permission_callback`).
	 *
	 * @return WP_REST_Response Empty 200 response.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	{
		return new WP_REST_Response( null, 200 );
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
	 * The function intentionally uses `error_log` (not the WC logger
	 * adapter) because slice-42 has not yet introduced the adapter; the
	 * stub keeps the log surface visible in PHP's default error stream
	 * and is swappable without API change.
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
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}

		$ip = self::resolveClientIp( $request );

		$headers = $request->get_headers();
		$names   = is_array( $headers ) ? array_keys( $headers ) : array();

		// Plain-text-free message: only the redacted context fields are
		// concatenated. The signature header VALUE is never emitted; only
		// the header NAME survives the `array_keys()` projection.
		error_log(
			sprintf(
				'[%s] webhook_rejected reason=%s ip=%s headers=%s',
				self::LOG_SOURCE,
				$reason,
				'' !== $ip ? $ip : '-',
				implode( ',', array_map( 'strval', $names ) )
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
