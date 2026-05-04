<?php
/**
 * Spreadconnect HTTP transport — Bearer-authenticated REST client.
 *
 * Provides the generic {@see self::request()} entry-point used by every
 * Spreadconnect endpoint wrapper in Slice 10 (27 endpoints + 4 reserved).
 *
 * Slice 07 baseline:
 *   - Bearer-Auth header from the `spreadconnect_api_key` option (read per
 *     request — no in-memory caching, per `architecture.md` Z. 482).
 *   - Production / Staging Base-URL toggle via `spreadconnect_use_staging`
 *     (architecture Z. 80).
 *   - JSON request-body encoding (POST / PUT / PATCH / DELETE-with-body).
 *   - Status classification: 2xx -> structured array return, 4xx ->
 *     {@see SpreadconnectClientError} (permanent), 5xx / network ->
 *     {@see SpreadconnectTransientError} (retryable).
 *   - Logging via `wc_get_logger()` with source `spreadconnect-api-client`,
 *     never leaking the API-key (architecture Z. 494: `Bearer ***`).
 *
 * Slice 08 additions (rate-limit awareness):
 *   - Proactive 1 s sleep before the next dispatch when the previous response
 *     reported `X-RateLimit-Remaining <= 5` (architecture Z. 81 / Z. 513).
 *   - Reactive single-retry on HTTP 429 honouring
 *     `X-RateLimit-Retry-After-Seconds` (default 1 s, capped at 30 s,
 *     architecture Z. 606 + Z. 644). Exactly one inner retry; a second 429
 *     re-throws as `SpreadconnectTransientError` with code `http_429` so
 *     Action Scheduler can apply the outer 1 m / 5 m / 15 m cascade.
 *   - All other Slice-07 paths (4xx, 5xx, network, malformed JSON) remain
 *     unchanged — inner retry is exclusive to 429.
 *
 * Out of scope (deliberately deferred):
 *   - Typed endpoint methods (`createOrder()`, `getArticles()`, …) — Slice 10.
 *   - DTO mapping — Slice 09 / 10 caller responsibility.
 *
 * @package SpreadconnectPod\Api
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api;

use InvalidArgumentException;
use SpreadconnectPod\Api\Dto\ArticleDetail;
use SpreadconnectPod\Api\Dto\ArticleSummary;
use SpreadconnectPod\Api\Dto\AuthOk;
use SpreadconnectPod\Api\Dto\DtoMapper;
use SpreadconnectPod\Api\Dto\OrderCreate;
use SpreadconnectPod\Api\Dto\Preview;
use SpreadconnectPod\Api\Dto\ShippingType;
use SpreadconnectPod\Api\Dto\StockEntry;
use SpreadconnectPod\Api\Dto\Subscription;
use Throwable;
use WP_Error;

/**
 * Outbound REST client for the Spreadconnect Fulfillment API v2.3.9.
 *
 * Marked as a regular class (not `final`) so Slice 08 can extend it via a
 * direct `Edit` of this file (adding rate-limit hooks). The current public
 * API surface — constructor + {@see self::request()} — is the contract Slice
 * 08 / 10 / 12 consume.
 */
class SpreadconnectClient
{
	/**
	 * Production Base-URL (architecture.md Z. 80).
	 */
	private const BASE_URL_PRODUCTION = 'https://rest.spreadconnect.com';

	/**
	 * Staging Base-URL (architecture.md Z. 80).
	 */
	private const BASE_URL_STAGING = 'https://staging.spreadconnect.com';

	/**
	 * Logger source string for `wc_get_logger()`.
	 *
	 * Final per architecture Z. 398 / Slice-07 Constraints. Must NOT be
	 * altered downstream — Failed-Ops dashboards filter on this exact source.
	 */
	private const LOG_SOURCE = 'spreadconnect-api-client';

	/**
	 * Default HTTP timeout in seconds.
	 *
	 * Explicitly set rather than relying on WP's 5-second default —
	 * Spreadconnect's `POST /orders` and `GET /productTypes/{id}` can
	 * take 8-12 s under load (Constraints).
	 */
	private const DEFAULT_TIMEOUT_SECONDS = 15;

	/**
	 * WP-Option name for the API-Key (Slice 05 default `''`).
	 */
	private const OPTION_API_KEY = 'spreadconnect_api_key';

	/**
	 * WP-Option name for the staging-toggle (Slice 05 default `false`).
	 */
	private const OPTION_USE_STAGING = 'spreadconnect_use_staging';

	/**
	 * Default sleep duration for the 429-retry path when the upstream did NOT
	 * provide a usable `X-RateLimit-Retry-After-Seconds` header (Slice 08 AC-3).
	 *
	 * Hardcoded per Discovery (no Settings-UI knob).
	 */
	private const RETRY_AFTER_DEFAULT_SECONDS = 1;

	/**
	 * Hard ceiling for the 429-retry sleep (Slice 08 AC-4). A misbehaving
	 * upstream that returns `Retry-After: 600` would otherwise stall the
	 * Action-Scheduler worker for 10 minutes — clamp to a value that still
	 * fits within the AS claim window (5 min) with margin.
	 */
	private const RETRY_AFTER_MAX_SECONDS = 30;

	/**
	 * Threshold at which the proactive (pre-send) drossel kicks in (Slice 08
	 * AC-5 / AC-6). Comparison is `<=` (not `<`), per architecture Z. 81 /
	 * Z. 513 — i.e. a value of 5 already triggers the sleep, 6 does not.
	 */
	private const RATE_LIMIT_PROACTIVE_THRESHOLD = 5;

	/**
	 * Optional override for the API-Key, used by the Settings -> Test
	 * Connection AJAX (Slice 12) to authenticate with an unsaved value.
	 *
	 * `null` (the default) means: read from `get_option()` per request.
	 */
	private ?string $apiKeyOverride;

	/**
	 * Last `X-RateLimit-Remaining` value observed on a successful (or any
	 * header-bearing) response. Lives in the instance for the lifetime of a
	 * single Action-Scheduler worker claim — a fresh worker process starts at
	 * `null`, which is treated as "no information" (no proactive sleep,
	 * Slice 08 AC-7).
	 *
	 * Never persisted to the DB — Discovery / architecture Z. 644 explicitly
	 * forbids cross-request rate-limit state (single-retry-layer principle).
	 */
	private ?int $lastRateLimitRemaining = null;

	/**
	 * @param string|null $apiKeyOverride Optional unsaved API-Key for
	 *                                    test-connection use; `null` reads
	 *                                    `spreadconnect_api_key` per request.
	 */
	public function __construct( ?string $apiKeyOverride = null )
	{
		$this->apiKeyOverride = $apiKeyOverride;
	}

	/**
	 * Perform an authenticated HTTP request against the Spreadconnect API.
	 *
	 * Steps:
	 *   1. Pre-flight: refuse with `auth_missing` when the Bearer token is
	 *      empty — never spend a network round-trip on a guaranteed 401.
	 *   2. Build the absolute URL: `<base>/<path>` with idempotent slash
	 *      normalisation (`'/x'` and `'x'` both produce `<base>/x`).
	 *   3. Slice 08 — proactive drossel: if the previous response on this
	 *      instance reported `X-RateLimit-Remaining <= 5`, sleep 1 s before
	 *      the next dispatch (architecture Z. 81 / Z. 513).
	 *   4. Build `wp_remote_request()` args: method, headers (Bearer +
	 *      Accept + optional Content-Type + User-Agent), JSON body, timeout.
	 *   5. Dispatch via `wp_remote_request()` and classify the response.
	 *   6. Slice 08 — reactive 429 retry: on HTTP 429, sleep
	 *      `X-RateLimit-Retry-After-Seconds` (default 1 s, capped 30 s) and
	 *      dispatch exactly once more. A second 429 throws
	 *      `SpreadconnectTransientError` with code `http_429`. All other
	 *      paths (4xx ≠ 429, 5xx, network, malformed JSON) follow Slice-07
	 *      semantics with no inner retry.
	 *   7. Log every outcome at the matching level (info / error / warning)
	 *      with the redacted Authorization header.
	 *
	 * @param string                     $method HTTP method (`GET`, `POST`, `PUT`, `DELETE`, `PATCH`).
	 * @param string                     $path   Endpoint path; leading slash optional.
	 * @param array<string, mixed>|null  $body   JSON-serialisable payload, or `null` for body-less requests.
	 *
	 * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
	 *
	 * @throws SpreadconnectClientError    On 4xx responses or pre-flight guard failures.
	 * @throws SpreadconnectTransientError On 5xx / network / malformed-JSON / double-429 responses.
	 */
	public function request( string $method, string $path, ?array $body = null ): array
	{
		$method     = strtoupper( $method );
		$normalized = $this->normalizePath( $path );

		$apiKey = $this->resolveApiKey();
		if ( '' === $apiKey ) {
			// Pre-flight guard: never dispatch when the Bearer token is empty.
			// AC-3: throw `auth_missing` BEFORE any wp_remote_request call.
			$message = sprintf( 'Spreadconnect API-Key is missing — refused %s %s.', $method, $normalized );
			$this->log( 'error', $message );
			throw new SpreadconnectClientError( 'auth_missing', $message, null, $normalized );
		}

		$url  = $this->resolveBaseUrl() . $normalized;
		$args = $this->buildRequestArgs( $method, $apiKey, $body );

		// Proactive drossel — must run BEFORE the first dispatch of THIS call,
		// based on the remaining-counter observed at the END of the previous
		// call on this client instance. The first ever call sees `null` and
		// skips (Slice 08 AC-7).
		$this->maybeProactiveSleep();

		// First attempt — classify into one of: success tuple, 429 (return
		// signal for the retry path), or thrown exception (4xx/5xx/network).
		$first = $this->dispatchOnce( $method, $normalized, $url, $args, false );

		if ( 429 !== $first['status'] ) {
			// Success / non-429 paths already handled the response (decoded
			// body or threw). The dispatcher only ever returns here for 2xx.
			return $first['result'];
		}

		// === 429 retry path (Slice 08 AC-1 / AC-2 / AC-3 / AC-4) ===========

		$retryDecision = $this->resolveRetryAfterSeconds( $first['headers'] );
		$retryAfter    = $retryDecision['seconds'];
		$cappedFrom    = $retryDecision['capped_from'];

		// AC-11: emit EXACTLY ONE WARN log for the retry path. AC-4 piggy-
		// backs the cap notice onto the same line so the total stays at one
		// WARN log even when the upstream returned an extreme Retry-After.
		$retryLogMessage = sprintf(
			'%s: 429 on %s %s; retrying after %ds (attempt 2/2)',
			self::LOG_SOURCE,
			$method,
			$normalized,
			$retryAfter
		);

		if ( null !== $cappedFrom ) {
			$retryLogMessage .= sprintf(
				' [capped from %ds, max %ds]',
				$cappedFrom,
				self::RETRY_AFTER_MAX_SECONDS
			);
		}

		$this->log( 'warning', $retryLogMessage );

		$this->sleepSeconds( $retryAfter );

		$second = $this->dispatchOnce( $method, $normalized, $url, $args, true );

		// `dispatchOnce(..., $isRetry=true)` only ever returns on 2xx — every
		// other status (including a second 429) has already thrown.
		return $second['result'];
	}

	/**
	 * Execute a single HTTP attempt and classify the outcome.
	 *
	 * On a 2xx response this returns
	 * `['status' => $status, 'result' => <Slice-07 tuple>]`. On a 429 during
	 * the FIRST attempt it returns `['status' => 429, 'headers' => ...]` so
	 * the orchestrator can drive the retry; on a 429 during the RETRY it
	 * throws `SpreadconnectTransientError('http_429', ...)`. All other
	 * statuses (4xx, 5xx, network, malformed JSON) throw the same exception
	 * Slice 07 throws — no inner retry for those (architecture Z. 644).
	 *
	 * @param string               $method     HTTP method (already upper-case).
	 * @param string               $normalized Endpoint path (leading slash, no trailing slash).
	 * @param string               $url        Fully-resolved URL passed to `wp_remote_request()`.
	 * @param array<string, mixed> $args       Request args from {@see self::buildRequestArgs()}.
	 * @param bool                 $isRetry    `true` when this is the 429-driven second attempt.
	 *
	 * @return array{status: int, result?: array{status: int, body: array<string, mixed>, headers: array<string, string>}, headers?: array<string, string>}
	 *
	 * @throws SpreadconnectClientError    On 4xx responses (incl. retried 429? — no, 429 stays transient).
	 * @throws SpreadconnectTransientError On 5xx / network / malformed-JSON / second-429.
	 */
	private function dispatchOnce(
		string $method,
		string $normalized,
		string $url,
		array $args,
		bool $isRetry
	): array {
		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error || ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) ) {
			// Network-level failure: timeouts, DNS, TLS handshakes, etc.
			// Never include the WP_Error data array verbatim — it can
			// contain the full request `args` and therefore the Bearer
			// token. Only the human-readable message is safe to log.
			//
			// Slice 08 AC-10: NO inner retry on network errors — Action
			// Scheduler owns the outer cascade.
			$wpErrorMessage = $response instanceof WP_Error
				? $response->get_error_message()
				: 'Unknown network error';

			$logMessage = sprintf(
				'%s %s -> network_error: %s',
				$method,
				$normalized,
				$wpErrorMessage
			);
			$this->log( 'warning', $logMessage );

			throw new SpreadconnectTransientError(
				'network_error',
				$logMessage,
				null,
				$normalized
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$rawBody     = (string) wp_remote_retrieve_body( $response );
		$headers     = $this->normalizeHeaders( wp_remote_retrieve_headers( $response ) );
		$logBaseLine = sprintf( '%s %s -> %d', $method, $normalized, $status );

		// Capture the rate-limit-remaining counter for the NEXT call on this
		// instance, regardless of status. AC-7: absent header keeps the prior
		// value untouched (no implicit reset).
		$this->captureRateLimitRemaining( $headers );

		// Branch order matters (architecture Z. 644 + Slice 08 Constraints):
		// 429 must short-circuit BEFORE the generic 4xx branch.

		// 429 -> orchestrator drives the (single) retry, or throw on retry.
		if ( 429 === $status ) {
			if ( $isRetry ) {
				// AC-2 + AC-11: a SECOND consecutive 429 throws transient
				// without emitting an additional WARN log — the
				// retry-trigger WARN emitted by `request()` already covers
				// the whole retry path (the call count is the assertion,
				// not the log count). The exception still carries the
				// status + path so AS-job consumers can branch on it.
				throw new SpreadconnectTransientError(
					'http_429',
					$logBaseLine,
					$status,
					$normalized
				);
			}

			// First attempt: hand the headers back so the caller can read
			// `X-RateLimit-Retry-After-Seconds` and sleep accordingly.
			return array(
				'status'  => 429,
				'headers' => $headers,
			);
		}

		// 2xx -> success path (decode body, return tuple).
		if ( $status >= 200 && $status < 300 ) {
			$decoded = $this->decodeJsonBody( $rawBody, $method, $normalized, $status );

			$this->log( 'info', $logBaseLine );

			return array(
				'status' => $status,
				'result' => array(
					'status'  => $status,
					'body'    => $decoded,
					'headers' => $headers,
				),
			);
		}

		// 4xx (non-429) -> permanent client error (no AS retry).
		if ( $status >= 400 && $status < 500 ) {
			$this->log( 'error', $logBaseLine );

			throw new SpreadconnectClientError(
				'http_4xx',
				$logBaseLine,
				$status,
				$normalized
			);
		}

		// 5xx (and the defensive 3xx fallback) -> transient (AS retries).
		// Slice 08 AC-9: NO inner retry — delegated to Action Scheduler.
		$this->log( 'warning', $logBaseLine );

		throw new SpreadconnectTransientError(
			'http_5xx',
			$logBaseLine,
			$status,
			$normalized
		);
	}

	/**
	 * Apply the proactive 1-second drossel when the previous response on
	 * this instance reported `X-RateLimit-Remaining <= 5` (Slice 08 AC-5 /
	 * AC-6 / AC-7).
	 *
	 * `null` means "never observed" → no sleep (defensive default; the
	 * absence of a header is not equivalent to a value of `0`).
	 */
	private function maybeProactiveSleep(): void
	{
		if ( null === $this->lastRateLimitRemaining ) {
			return;
		}

		if ( $this->lastRateLimitRemaining > self::RATE_LIMIT_PROACTIVE_THRESHOLD ) {
			return;
		}

		$this->sleepSeconds( 1 );
	}

	/**
	 * Capture `X-RateLimit-Remaining` from a response header map for use by
	 * the NEXT proactive-sleep check on this instance.
	 *
	 * AC-7 contract: a missing or non-numeric header MUST NOT mutate the
	 * stored value (so a stale-low counter cannot be silently cleared by a
	 * mocked / older response that happens to omit the header).
	 *
	 * @param array<string, string> $headers Lower-cased header map from
	 *                                       {@see self::normalizeHeaders()}.
	 */
	private function captureRateLimitRemaining( array $headers ): void
	{
		$value = $headers['x-ratelimit-remaining'] ?? '';

		if ( '' === $value || ! is_numeric( $value ) ) {
			return;
		}

		$this->lastRateLimitRemaining = (int) $value;
	}

	/**
	 * Resolve the sleep duration for the 429-retry path from response headers.
	 *
	 * Returns a `['seconds' => int, 'capped_from' => int|null]` decision so
	 * the caller can fold the cap notice into a single WARN log (AC-11 keeps
	 * the WARN-count for the retry path at exactly one).
	 *
	 * Rules (Slice 08 AC-3 / AC-4):
	 *   - Header missing / empty / non-numeric / <= 0 → default 1 s, no cap.
	 *   - Header > 30                                  → clamp to 30 s, `capped_from` = original.
	 *   - Otherwise                                    → integer cast, no cap.
	 *
	 * @param array<string, string> $headers Lower-cased header map.
	 *
	 * @return array{seconds: int, capped_from: int|null}
	 */
	private function resolveRetryAfterSeconds( array $headers ): array
	{
		$raw = $headers['x-ratelimit-retry-after-seconds'] ?? '';

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return array(
				'seconds'     => self::RETRY_AFTER_DEFAULT_SECONDS,
				'capped_from' => null,
			);
		}

		$seconds = (int) $raw;

		if ( $seconds <= 0 ) {
			return array(
				'seconds'     => self::RETRY_AFTER_DEFAULT_SECONDS,
				'capped_from' => null,
			);
		}

		if ( $seconds > self::RETRY_AFTER_MAX_SECONDS ) {
			return array(
				'seconds'     => self::RETRY_AFTER_MAX_SECONDS,
				'capped_from' => $seconds,
			);
		}

		return array(
			'seconds'     => $seconds,
			'capped_from' => null,
		);
	}

	/**
	 * Sleep wrapper — extracted into a `protected` seam so PHPUnit tests can
	 * override it via a thin subclass (or via Brain\Monkey when running
	 * through the Slice-08 test bootstrap) and observe the call sequence
	 * without ever burning real wall-clock time.
	 *
	 * Production path defers to `sleep()`; non-positive arguments are a
	 * no-op (defence-in-depth against `Retry-After: -1` exploits).
	 */
	protected function sleepSeconds( int $seconds ): void
	{
		if ( $seconds <= 0 ) {
			return;
		}

		sleep( $seconds );
	}

	/**
	 * Resolve the active Bearer token.
	 *
	 * Priority: explicit constructor override (Slice 12 Test-Connection)
	 * before the `spreadconnect_api_key` option. Reading the option per
	 * request guarantees that admin-side key updates take effect on the
	 * very next call without a process restart (architecture Z. 482).
	 */
	private function resolveApiKey(): string
	{
		if ( null !== $this->apiKeyOverride ) {
			return $this->apiKeyOverride;
		}

		$apiKey = get_option( self::OPTION_API_KEY, '' );

		// `get_option` may surface non-string values from a corrupted DB
		// entry; coerce defensively so callers see a clean string contract.
		return is_string( $apiKey ) ? $apiKey : '';
	}

	/**
	 * Resolve the active Base-URL based on the `spreadconnect_use_staging` toggle.
	 *
	 * Slice 05 stores the option type-true (PHP `bool`); we still pass through
	 * a `(bool)` cast as a defensive net for callers that mutate the option
	 * directly with `update_option('spreadconnect_use_staging', '1')`.
	 */
	private function resolveBaseUrl(): string
	{
		$useStaging = (bool) get_option( self::OPTION_USE_STAGING, false );

		return $useStaging ? self::BASE_URL_STAGING : self::BASE_URL_PRODUCTION;
	}

	/**
	 * Normalise the endpoint path so concatenation with the Base-URL is
	 * idempotent regardless of whether the caller wrote `'/orders'`,
	 * `'orders'`, or even `'///orders///'`.
	 *
	 * Returned form: leading slash, no trailing slash (unless the path is
	 * the root `'/'`). Internal slashes are preserved.
	 */
	private function normalizePath( string $path ): string
	{
		$trimmed = trim( $path );
		$trimmed = ltrim( $trimmed, '/' );
		$trimmed = rtrim( $trimmed, '/' );

		return '/' . $trimmed;
	}

	/**
	 * Build the `wp_remote_request()` arguments array.
	 *
	 * @param string                    $method HTTP method (already upper-case).
	 * @param string                    $apiKey Resolved Bearer token (non-empty).
	 * @param array<string, mixed>|null $body   JSON payload or `null` for body-less requests.
	 *
	 * @return array<string, mixed>
	 */
	private function buildRequestArgs( string $method, string $apiKey, ?array $body ): array
	{
		$headers = array(
			'Authorization' => 'Bearer ' . $apiKey,
			'Accept'        => 'application/json',
			'User-Agent'    => $this->resolveUserAgent(),
		);

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
		);

		// AC-4: GET (and other body-less calls) must NOT carry a `body`
		// key or a `Content-Type` header. POST / PUT / PATCH / DELETE WITH
		// payload encode JSON and set the Content-Type.
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		return $args;
	}

	/**
	 * Resolve the `User-Agent` header.
	 *
	 * Prefers the version constant defined by Slice 02 (when present) and
	 * falls back to the literal `2.0.0` (matches the value in
	 * `spreadconnect-pod.php`) so the client remains usable in isolation
	 * — e.g. inside unit tests that bootstrap only this single class.
	 */
	private function resolveUserAgent(): string
	{
		$version = defined( 'SPREADCONNECT_POD_VERSION' )
			? (string) constant( 'SPREADCONNECT_POD_VERSION' )
			: '2.0.0';

		return 'spreadconnect-pod/' . $version;
	}

	/**
	 * JSON-decode a 2xx response body to an associative array.
	 *
	 * Empty bodies are tolerated — Spreadconnect returns `204 No Content`
	 * shaped responses for endpoints like `DELETE /subscriptions/{id}`,
	 * and an empty 2xx body should map to an empty PHP array, not a
	 * decode-failure.
	 *
	 * Malformed bodies on a 2xx status are mapped to a transient error:
	 * the upstream server is technically successful but the result is
	 * unusable, so retrying once may yield a clean response.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpreadconnectTransientError When the body cannot be decoded.
	 */
	private function decodeJsonBody( string $rawBody, string $method, string $path, int $status ): array
	{
		if ( '' === $rawBody ) {
			return array();
		}

		try {
			$decoded = json_decode( $rawBody, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			$message = sprintf(
				'%s %s -> %d invalid_json: %s',
				$method,
				$path,
				$status,
				$e->getMessage()
			);
			$this->log( 'warning', $message );

			throw new SpreadconnectTransientError(
				'invalid_json',
				$message,
				$status,
				$path,
				$e
			);
		}

		// Top-level non-array JSON (string / number / bool) is a protocol
		// violation for SC's REST endpoints — treat it the same as a
		// decode failure to keep the contract `array<string,mixed>`.
		if ( ! is_array( $decoded ) ) {
			$message = sprintf(
				'%s %s -> %d invalid_json: top-level JSON is not an object/array',
				$method,
				$path,
				$status
			);
			$this->log( 'warning', $message );

			throw new SpreadconnectTransientError(
				'invalid_json',
				$message,
				$status,
				$path
			);
		}

		return $decoded;
	}

	/**
	 * Normalise `wp_remote_retrieve_headers()` output to a flat
	 * `array<string, string>` with lower-case keys.
	 *
	 * `wp_remote_retrieve_headers()` returns a `Requests_Utility_CaseInsensitiveDictionary`
	 * (or `WpOrg\Requests\Utility\...`) on modern WP and a plain array on
	 * very old versions / mocks; both are iterable. We coerce values to
	 * `string` so Slice 08 can deterministically read e.g.
	 * `$headers['x-ratelimit-remaining']` regardless of WP version.
	 *
	 * @param mixed $headers Raw return value of `wp_remote_retrieve_headers()`.
	 *
	 * @return array<string, string>
	 */
	private function normalizeHeaders( $headers ): array
	{
		$normalized = array();

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_iterable( $headers ) ) {
			return $normalized;
		}

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			// Multi-valued headers come through as arrays (e.g. Set-Cookie);
			// join with ", " — RFC 7230 §3.2.2 allows the merge.
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$normalized[ strtolower( $name ) ] = (string) $value;
		}

		return $normalized;
	}

	/**
	 * Emit a single log entry via `wc_get_logger()` with the canonical source.
	 *
	 * The message must already be redaction-safe (callers compose it from
	 * method + path + status — never from the request headers). Behaves as
	 * a no-op when `wc_get_logger()` is unavailable (very early bootstrap
	 * or a stripped test context); logging is a stub-friendly side-channel,
	 * never a hard dependency.
	 *
	 * @param string $level   Log level (`info`, `warning`, `error`, …).
	 * @param string $message Pre-redacted human-readable message.
	 */
	private function log( string $level, string $message ): void
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

	// =========================================================================
	// Slice 10 — Typed endpoint wrappers (27 methods + 4 reserved).
	//
	// All wrappers are thin adaptors around {@see self::request()}. They only
	// build the path / query / body, delegate transport to `request()`, and
	// optionally map the response into a DTO. They do NOT catch exceptions —
	// 4xx (`SpreadconnectClientError`) and 5xx / 429 / network
	// (`SpreadconnectTransientError`) propagate untouched per Slice 10 AC-12.
	//
	// Method order mirrors Constraints "Method-Reihenfolge in der Datei":
	// Auth → Articles → Orders → Shipping → Subscriptions → Simulate →
	// ProductTypes → Designs (Previews) → Stock → Reserved.
	// =========================================================================

	// ---------------------------------------------------------------------
	// Authentication
	// ---------------------------------------------------------------------

	/**
	 * `GET /authentication` — verify the configured API key.
	 *
	 * Spreadconnect signals validity via HTTP-200; the body may be empty or
	 * carry caller-info fields. The {@see AuthOk} DTO captures whatever the
	 * upstream returned for diagnostic display in the Settings → Test
	 * Connection UI (Slice 12).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. 401 invalid key) or
	 *                                     pre-flight `auth_missing`.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network failure.
	 */
	public function authenticate(): AuthOk
	{
		$result = $this->request( 'GET', '/authentication', null );

		return AuthOk::fromResponse( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// Articles
	// ---------------------------------------------------------------------

	/**
	 * `GET /articles?page={p}&size={s}[&search={q}]` — paginated article list.
	 *
	 * Caller decides pagination. The `search` parameter is server-side
	 * filtering (architecture Z. 796 / Open Q9) — when omitted, the full
	 * catalog is returned page-by-page.
	 *
	 * @return array{items: ArticleSummary[], page: int, size: int, total: int|null}
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getArticles( int $page, int $size, ?string $search = null ): array
	{
		$query = $this->buildQuery(
			array(
				'page'   => $page,
				'size'   => $size,
				'search' => $search,
			)
		);

		$result = $this->request( 'GET', '/articles' . $query, null );
		$body   = $result['body'];

		// Spreadconnect's paginated list shapes vary slightly across endpoints
		// — some wrap items under `items`, others under `content`, some return
		// a top-level list. Defensively support all three so a future server
		// shape-change does not break callers.
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			$items_raw = $body['items'];
		} elseif ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			$items_raw = $body['content'];
		} elseif ( array_is_list( $body ) ) {
			$items_raw = $body;
		} else {
			$items_raw = array();
		}

		$items = array();
		foreach ( $items_raw as $item ) {
			if ( is_array( $item ) ) {
				$items[] = ArticleSummary::fromResponse( $item );
			}
		}

		$total = null;
		if ( isset( $body['total'] ) && is_int( $body['total'] ) ) {
			$total = $body['total'];
		} elseif ( isset( $body['totalElements'] ) && is_int( $body['totalElements'] ) ) {
			$total = $body['totalElements'];
		}

		return array(
			'items' => $items,
			'page'  => $page,
			'size'  => $size,
			'total' => $total,
		);
	}

	/**
	 * `GET /articles/{id}` — full article payload (variants + design).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getArticle( string $id ): ArticleDetail
	{
		$path = $this->buildPath( '/articles/{id}', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return ArticleDetail::fromResponse( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// Orders — lifecycle (create / get / confirm / cancel)
	// ---------------------------------------------------------------------

	/**
	 * `POST /orders` — submit a new fulfillment order.
	 *
	 * The {@see OrderCreate} DTO is serialised to a snake_case array via
	 * {@see self::orderCreateToSnakeArray()}. There is no `OrderResponse`
	 * DTO in Slice 09 — callers receive the snake→camel-converted body as a
	 * raw associative array.
	 *
	 * @return array<string, mixed> Order detail shape (`id`, `state`, …).
	 *
	 * @throws SpreadconnectClientError    On 4xx (validation, 401, 409).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function createOrder( OrderCreate $dto ): array
	{
		$body = $this->orderCreateToSnakeArray( $dto );

		$result = $this->request( 'POST', '/orders', $body );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /orders/{id}` — order detail (state + line items + addresses).
	 *
	 * @return array<string, mixed> Order detail shape (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getOrder( string $id ): array
	{
		$path = $this->buildPath( '/orders/{id}', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `POST /orders/{id}/confirm` — transition order from `NEW` to `CONFIRMED`.
	 *
	 * Empty-body POST (per Spreadconnect spec). The wrapper sends an empty
	 * associative array `[]` rather than `null` so the request carries the
	 * `Content-Type: application/json` header — this matches the upstream
	 * contract, see AC-3.
	 *
	 * @return array<string, mixed> Updated order detail (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. order not in `NEW` state).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function confirmOrder( string $id ): array
	{
		$path = $this->buildPath( '/orders/{id}/confirm', array( 'id' => $id ) );

		$result = $this->request( 'POST', $path, array() );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `POST /orders/{id}/cancel` — cancel an order while still in `NEW`.
	 *
	 * Empty-body POST (see {@see self::confirmOrder()} for body-shape rationale).
	 *
	 * @return array<string, mixed> Updated order detail (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. already `CONFIRMED`).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function cancelOrder( string $id ): array
	{
		$path = $this->buildPath( '/orders/{id}/cancel', array( 'id' => $id ) );

		$result = $this->request( 'POST', $path, array() );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// Shipping
	// ---------------------------------------------------------------------

	/**
	 * `GET /orders/{id}/shipments` — tracking-number / carrier list.
	 *
	 * @return array<string, mixed> Raw `Shipment[]` shape (snake→camel converted).
	 *                              No DTO in Slice 09 → callers parse defensively.
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getShipments( string $orderId ): array
	{
		$path = $this->buildPath( '/orders/{id}/shipments', array( 'id' => $orderId ) );

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /orders/{id}/shippingTypes` — available shipping options.
	 *
	 * @return ShippingType[]
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getShippingTypes( string $orderId ): array
	{
		$path = $this->buildPath( '/orders/{id}/shippingTypes', array( 'id' => $orderId ) );

		$result = $this->request( 'GET', $path, null );

		return $this->mapList( $result['body'], array( ShippingType::class, 'fromResponse' ) );
	}

	/**
	 * `POST /orders/{id}/shippingType` — set the chosen shipping type.
	 *
	 * Body uses **camelCase** key (`shippingType`) per architecture Z. 105 /
	 * Slice 10 AC-4 — Spreadconnect accepts the camel form on this endpoint
	 * because the path is already camelCase.
	 *
	 * @return array<string, mixed> Updated order detail (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. invalid shipping type).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function setShippingType( string $orderId, string $shippingType ): array
	{
		$path = $this->buildPath( '/orders/{id}/shippingType', array( 'id' => $orderId ) );

		$body = array( 'shippingType' => $shippingType );

		$result = $this->request( 'POST', $path, $body );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// Subscriptions
	// ---------------------------------------------------------------------

	/**
	 * `GET /subscriptions` — list all webhook subscriptions for this account.
	 *
	 * @return Subscription[]
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getSubscriptions(): array
	{
		$result = $this->request( 'GET', '/subscriptions', null );

		return $this->mapList( $result['body'], array( Subscription::class, 'fromResponse' ) );
	}

	/**
	 * `POST /subscriptions` — register a new webhook subscription.
	 *
	 * Body keys are camelCase per the SC spec (`eventType`, `callbackUrl`,
	 * `secret`). The `secret` is the HMAC-SHA256 shared secret stored under
	 * the `spreadconnect_webhook_secret` option (Slice 17 / 18).
	 *
	 * @throws SpreadconnectClientError    On 4xx (duplicate, validation).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function createSubscription( string $eventType, string $callbackUrl, string $secret ): Subscription
	{
		$body = array(
			'eventType'   => $eventType,
			'callbackUrl' => $callbackUrl,
			'secret'      => $secret,
		);

		$result = $this->request( 'POST', '/subscriptions', $body );

		return Subscription::fromResponse( $result['body'] );
	}

	/**
	 * `DELETE /subscriptions/{id}` — remove a webhook subscription.
	 *
	 * Returns `void` because the SC endpoint responds with `204 No Content`
	 * and there is no meaningful body to surface. A non-2xx response has
	 * already been thrown by {@see self::request()} before this method
	 * returns, so a successful return implies a successful delete.
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. ID not found).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function deleteSubscription( string $id ): void
	{
		$path = $this->buildPath( '/subscriptions/{id}', array( 'id' => $id ) );

		$this->request( 'DELETE', $path, null );
	}

	// ---------------------------------------------------------------------
	// Simulate (staging-only event triggers)
	// ---------------------------------------------------------------------

	/**
	 * `POST /orders/{id}/simulate/order-cancelled` — staging event simulator.
	 *
	 * The wrapper itself is **not** gated on `spreadconnect_use_staging` —
	 * UI-level gating happens in the Slice-44 `Hub\Ajax\SimulateEvent`
	 * controller. The HTTP-layer wrapper just exposes the endpoint.
	 *
	 * @return array<string, mixed> Order detail shape (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. endpoint disabled in prod).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function simulateOrderCancelled( string $orderId ): array
	{
		$path = $this->buildPath(
			'/orders/{id}/simulate/order-cancelled',
			array( 'id' => $orderId )
		);

		$result = $this->request( 'POST', $path, array() );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `POST /orders/{id}/simulate/order-processed` — staging event simulator.
	 *
	 * @return array<string, mixed> Order detail shape (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function simulateOrderProcessed( string $orderId ): array
	{
		$path = $this->buildPath(
			'/orders/{id}/simulate/order-processed',
			array( 'id' => $orderId )
		);

		$result = $this->request( 'POST', $path, array() );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `POST /orders/{id}/simulate/shipment-sent` — staging event simulator.
	 *
	 * @return array<string, mixed> Shipment shape (snake→camel converted).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function simulateShipmentSent( string $orderId ): array
	{
		$path = $this->buildPath(
			'/orders/{id}/simulate/shipment-sent',
			array( 'id' => $orderId )
		);

		$result = $this->request( 'POST', $path, array() );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// ProductTypes (catalogue meta)
	// ---------------------------------------------------------------------

	/**
	 * `GET /productTypes` — list of all product types (heavily cached upstream).
	 *
	 * @return array<int|string, mixed> Raw `ProductTypeSummary[]` shape (snake→camel).
	 *                                  No DTO in Slice 09. Caching/ETag handled in Slice 23.
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getProductTypes(): array
	{
		$result = $this->request( 'GET', '/productTypes', null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /productTypes/{id}` — full product-type detail (sizes, colors, …).
	 *
	 * @return array<string, mixed> ProductTypeDetail shape (snake→camel).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getProductType( string $id ): array
	{
		$path = $this->buildPath( '/productTypes/{id}', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /productTypes/{id}/views` — print-area views for hotspot detection.
	 *
	 * @return array<int|string, mixed> Raw `View[]` shape (snake→camel).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getProductTypeViews( string $id ): array
	{
		$path = $this->buildPath( '/productTypes/{id}/views', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /productTypes/{id}/size-chart` — measurement table for product-edit.
	 *
	 * @return array<string, mixed> SizeChart shape (snake→camel).
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getProductTypeSizeChart( string $id ): array
	{
		$path = $this->buildPath( '/productTypes/{id}/size-chart', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	/**
	 * `GET /productTypes/{id}/hotspots/design/{designId}` — hotspot lookup.
	 *
	 * @return array<string, mixed> Hotspot shape (snake→camel).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. unknown design).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getHotspot( string $productTypeId, string $designId ): array
	{
		$path = $this->buildPath(
			'/productTypes/{id}/hotspots/design/{designId}',
			array(
				'id'       => $productTypeId,
				'designId' => $designId,
			)
		);

		$result = $this->request( 'GET', $path, null );

		return DtoMapper::snakeToCamel( $result['body'] );
	}

	// ---------------------------------------------------------------------
	// Designs / Previews
	// ---------------------------------------------------------------------

	/**
	 * `POST /productTypes/{id}/previews` — generate presigned preview URLs.
	 *
	 * Body keys are camelCase (`designId`, `hotspotId`, `viewIds`) per
	 * architecture Z. 119. Returned URLs are short-lived; the Slice-23
	 * sync-job consumes them immediately via `media_sideload_image()`.
	 *
	 * @param string[] $viewIds View IDs from {@see self::getProductTypeViews()}.
	 *
	 * @return Preview[]
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. missing hotspot).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function createPreviews(
		string $productTypeId,
		string $designId,
		string $hotspotId,
		array $viewIds
	): array {
		$path = $this->buildPath(
			'/productTypes/{id}/previews',
			array( 'id' => $productTypeId )
		);

		$body = array(
			'designId'  => $designId,
			'hotspotId' => $hotspotId,
			'viewIds'   => array_values( $viewIds ),
		);

		$result = $this->request( 'POST', $path, $body );

		return $this->mapList( $result['body'], array( Preview::class, 'fromArray' ) );
	}

	// ---------------------------------------------------------------------
	// Stock
	// ---------------------------------------------------------------------

	/**
	 * `GET /stock?[productTypeId={p}][&skus={comma-sep}]` — bulk stock query.
	 *
	 * Per architecture Z. 797 (Open Q10) the bulk endpoint **must** be
	 * called with at least one filter (productTypeId or skus). A
	 * filter-less call would pull the entire SC catalog stock — explicitly
	 * forbidden — so we throw {@see InvalidArgumentException} pre-flight.
	 *
	 * SKUs are comma-separated **without** a `[]` array suffix (KISS form
	 * matches Spreadconnect's documented syntax). Each SKU is
	 * `rawurlencode()`-ed individually so commas in caller-supplied SKUs
	 * (extremely unlikely but technically valid) don't bleed into the
	 * delimiter.
	 *
	 * @param string[]|null $skus SKU list, comma-joined; null → omit param.
	 *
	 * @return StockEntry[]
	 *
	 * @throws InvalidArgumentException     When both filters are null.
	 * @throws SpreadconnectClientError     On 4xx.
	 * @throws SpreadconnectTransientError  On 5xx / 429 / network.
	 */
	public function getStock( ?string $productTypeId = null, ?array $skus = null ): array
	{
		if ( null === $productTypeId && null === $skus ) {
			throw new InvalidArgumentException(
				'SpreadconnectClient::getStock(): productTypeId or skus required '
				. '(filter-less bulk-call forbidden — see architecture Open Q10).'
			);
		}

		$queryParts = array();

		if ( null !== $productTypeId ) {
			$queryParts[] = 'productTypeId=' . rawurlencode( $productTypeId );
		}

		if ( null !== $skus ) {
			$encoded = array_map( 'rawurlencode', array_values( $skus ) );
			// Comma is a sub-delim in RFC 3986 reserved set; raw-urlencoding
			// individual SKUs first means a SKU containing "," would be
			// preserved (encoded as %2C), so the delimiter is unambiguous.
			$queryParts[] = 'skus=' . implode( ',', $encoded );
		}

		$query = '' === implode( '', $queryParts ) ? '' : '?' . implode( '&', $queryParts );

		$result = $this->request( 'GET', '/stock' . $query, null );

		return $this->mapList( $result['body'], array( StockEntry::class, 'fromResponse' ) );
	}

	/**
	 * `GET /stock/{sku}` — single-SKU fallback (used only when bulk rejects filter).
	 *
	 * @throws SpreadconnectClientError    On 4xx (e.g. unknown SKU).
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getStockBySku( string $sku ): StockEntry
	{
		$path = $this->buildPath( '/stock/{sku}', array( 'sku' => $sku ) );

		$result = $this->request( 'GET', $path, null );

		return StockEntry::fromResponse( $result['body'] );
	}

	/**
	 * `GET /stock/productType/{id}` — full per-product-type stock list.
	 *
	 * @return StockEntry[]
	 *
	 * @throws SpreadconnectClientError    On 4xx.
	 * @throws SpreadconnectTransientError On 5xx / 429 / network.
	 */
	public function getStockByProductType( string $id ): array
	{
		$path = $this->buildPath( '/stock/productType/{id}', array( 'id' => $id ) );

		$result = $this->request( 'GET', $path, null );

		return $this->mapList( $result['body'], array( StockEntry::class, 'fromResponse' ) );
	}

	// ---------------------------------------------------------------------
	// Reserved / out-of-MVP-scope wrappers (always throw NotImplementedError)
	// ---------------------------------------------------------------------

	/**
	 * Reserved: `POST /articles` (push-sync). Out of MVP scope.
	 *
	 * @throws NotImplementedError Always — call site must be removed.
	 */
	public function pushArticle(): never
	{
		throw new NotImplementedError(
			'POST /articles is out of MVP scope (push-sync — pull-only architecture).'
		);
	}

	/**
	 * Reserved: `DELETE /articles/{id}`. Out of MVP scope.
	 *
	 * @throws NotImplementedError Always — call site must be removed.
	 */
	public function deleteArticle( string $id ): never
	{
		throw new NotImplementedError(
			sprintf(
				'DELETE /articles/%s is out of MVP scope (no article-delete in MVP).',
				$id
			)
		);
	}

	/**
	 * Reserved: `PUT /orders/{id}` (post-submit edit). Out of MVP scope.
	 *
	 * @throws NotImplementedError Always — call site must be removed.
	 */
	public function updateOrder( string $id ): never
	{
		throw new NotImplementedError(
			sprintf(
				'PUT /orders/%s is out of MVP scope (no order-edit-after-submit).',
				$id
			)
		);
	}

	/**
	 * Reserved: `POST /designs/upload`. Out of MVP scope.
	 *
	 * @throws NotImplementedError Always — call site must be removed.
	 */
	public function uploadDesign(): never
	{
		throw new NotImplementedError(
			'POST /designs/upload is out of MVP scope (designs are uploaded out-of-band).'
		);
	}

	// ---------------------------------------------------------------------
	// Path / Query / Body helpers (Slice 10-internal)
	// ---------------------------------------------------------------------

	/**
	 * Substitute `{name}`-placeholders in a path template with rawurlencoded values.
	 *
	 * Example: `buildPath('/articles/{id}', ['id' => 'art id/42'])`
	 *          → `/articles/art%20id%2F42`.
	 *
	 * Each variable value is `rawurlencode()`-d so reserved URI characters
	 * (`/`, `?`, `#`, space, …) cannot bleed into the path structure
	 * (Slice 10 AC-11). Callers therefore pass raw IDs / SKUs without
	 * pre-encoding.
	 *
	 * @param string                $template Path template with `{name}` placeholders.
	 * @param array<string, string> $vars     Placeholder → raw value map.
	 */
	private function buildPath( string $template, array $vars ): string
	{
		$path = $template;

		foreach ( $vars as $name => $value ) {
			$path = str_replace( '{' . $name . '}', rawurlencode( $value ), $path );
		}

		return $path;
	}

	/**
	 * Build a `?key=value&key=value` query suffix from an associative param map.
	 *
	 * Filters out `null` values (so an unset optional param is genuinely
	 * omitted, not rendered as `&key=`). Returns the empty string when
	 * every value is null. Uses RFC-3986 percent-encoding to keep the
	 * encoding stable across PHP versions.
	 *
	 * @param array<string, mixed> $params
	 */
	private function buildQuery( array $params ): string
	{
		$filtered = array();

		foreach ( $params as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}

		if ( array() === $filtered ) {
			return '';
		}

		return '?' . http_build_query( $filtered, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Map a list-shaped response body through a DTO factory.
	 *
	 * Tolerates the three ways Spreadconnect can shape a list response:
	 *   - top-level numeric array (`[{…}, {…}]`),
	 *   - wrapped under `items` key,
	 *   - wrapped under `content` key (Spring-style pagination).
	 *
	 * Non-array entries are skipped silently — DTO factories already throw
	 * on malformed data, but a stray non-array slot would be a server bug
	 * we shouldn't propagate as a hard failure.
	 *
	 * @param array<int|string, mixed> $body    Raw decoded response body.
	 * @param callable                 $factory DTO `fromResponse`/`fromArray` callable.
	 *
	 * @return array<int, mixed> List of DTO instances.
	 */
	private function mapList( array $body, callable $factory ): array
	{
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			$items = $body['items'];
		} elseif ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			$items = $body['content'];
		} else {
			$items = $body;
		}

		$out = array();
		foreach ( $items as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = $factory( $entry );
		}

		return $out;
	}

	/**
	 * Serialise an {@see OrderCreate} DTO to a snake_case associative array.
	 *
	 * Slice 09 deliberately did not add a `toArray()` method to OrderCreate
	 * (per Slice-10 Integration-Contract note), so we produce the request
	 * body here. The path is camelCase → snake_case via {@see DtoMapper}
	 * (architecture Z. 161 / Slice 10 AC-3 expects exact `external_order_reference`,
	 * `order_items`, `billing_address`, `shipping_address` keys).
	 *
	 * Optional fields (`shippingType`, `customerEmail`, `phone`, `taxType`)
	 * are emitted only when non-null so we don't litter the payload with
	 * `null` keys (some SC endpoints reject them).
	 *
	 * @return array<string, mixed>
	 */
	private function orderCreateToSnakeArray( OrderCreate $dto ): array
	{
		$camel = array(
			'externalOrderReference' => $dto->externalOrderReference,
			'orderItems'             => array_map(
				/**
				 * @param object $item OrderItem (Slice 09 readonly DTO).
				 */
				static function ( $item ): array {
					$entry = array(
						'sku'      => $item->sku,
						'quantity' => $item->quantity,
					);

					if ( null !== $item->customerPrice ) {
						$entry['customerPrice'] = self::moneyToArray( $item->customerPrice );
					}

					return $entry;
				},
				$dto->orderItems
			),
			'billingAddress'         => $this->addressToArray( $dto->billingAddress ),
			'shippingAddress'        => $this->addressToArray( $dto->shippingAddress ),
		);

		if ( null !== $dto->shippingType ) {
			$camel['shippingType'] = $dto->shippingType;
		}
		if ( null !== $dto->customerEmail ) {
			$camel['customerEmail'] = $dto->customerEmail;
		}
		if ( null !== $dto->phone ) {
			$camel['phone'] = $dto->phone;
		}
		if ( null !== $dto->taxType ) {
			$camel['taxType'] = $dto->taxType;
		}

		return DtoMapper::camelToSnake( $camel );
	}

	/**
	 * Serialise an Address DTO to a camelCase array (pre-Mapper form).
	 *
	 * @param object $address Address DTO (Slice 09).
	 *
	 * @return array<string, mixed>
	 */
	private function addressToArray( $address ): array
	{
		$out = array(
			'firstName' => $address->firstName,
			'lastName'  => $address->lastName,
			'street'    => $address->street,
			'zipCode'   => $address->zipCode,
			'city'      => $address->city,
			'country'   => $address->country,
		);

		if ( null !== $address->streetAnnex ) {
			$out['streetAnnex'] = $address->streetAnnex;
		}
		if ( null !== $address->state ) {
			$out['state'] = $address->state;
		}

		return $out;
	}

	/**
	 * Serialise a Money DTO to a camelCase array (pre-Mapper form).
	 *
	 * Static so it can be invoked from the array_map() closure inside
	 * {@see self::orderCreateToSnakeArray()} without a `$this`-bind.
	 *
	 * @param object $money Money DTO (Slice 09).
	 *
	 * @return array<string, mixed>
	 */
	private static function moneyToArray( $money ): array
	{
		$out = array(
			'amount'   => $money->amount,
			'currency' => $money->currency,
		);

		if ( null !== $money->taxRate ) {
			$out['taxRate'] = $money->taxRate;
		}
		if ( null !== $money->taxAmount ) {
			$out['taxAmount'] = $money->taxAmount;
		}

		return $out;
	}
}
