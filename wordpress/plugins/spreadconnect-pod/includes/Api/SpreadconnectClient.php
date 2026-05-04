<?php
/**
 * Spreadconnect HTTP transport — Bearer-authenticated REST client.
 *
 * Provides the generic {@see self::request()} entry-point used by every
 * Spreadconnect endpoint wrapper in Slice 10 (27 endpoints + 4 reserved).
 * This Slice (07) covers the transport basics only:
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
 * Out of scope (deliberately deferred):
 *   - 429-Retry-After / `X-RateLimit-Remaining` proactive sleep — Slice 08.
 *   - Typed endpoint methods (`createOrder()`, `getArticles()`, …) — Slice 10.
 *   - DTO mapping — Slice 09 / 10 caller responsibility.
 *
 * @package SpreadconnectPod\Api
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api;

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
	 * Optional override for the API-Key, used by the Settings -> Test
	 * Connection AJAX (Slice 12) to authenticate with an unsaved value.
	 *
	 * `null` (the default) means: read from `get_option()` per request.
	 */
	private ?string $apiKeyOverride;

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
	 *   3. Build `wp_remote_request()` args: method, headers (Bearer +
	 *      Accept + optional Content-Type + User-Agent), JSON body, timeout.
	 *   4. Dispatch via `wp_remote_request()`.
	 *   5. Map the result to an `(int, array, array<string,string>)` tuple
	 *      on 2xx, or throw the appropriate exception on 4xx / 5xx /
	 *      `WP_Error`.
	 *   6. Log every outcome at the matching level (info / error / warning)
	 *      with the redacted Authorization header.
	 *
	 * @param string                     $method HTTP method (`GET`, `POST`, `PUT`, `DELETE`, `PATCH`).
	 * @param string                     $path   Endpoint path; leading slash optional.
	 * @param array<string, mixed>|null  $body   JSON-serialisable payload, or `null` for body-less requests.
	 *
	 * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
	 *
	 * @throws SpreadconnectClientError    On 4xx responses or pre-flight guard failures.
	 * @throws SpreadconnectTransientError On 5xx / network / malformed-JSON responses.
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

		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error || ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) ) {
			// Network-level failure: timeouts, DNS, TLS handshakes, etc.
			// Never include the WP_Error data array verbatim — it can
			// contain the full request `args` and therefore the Bearer
			// token. Only the human-readable message is safe to log.
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

		// 2xx -> success path (decode body, return tuple).
		if ( $status >= 200 && $status < 300 ) {
			$decoded = $this->decodeJsonBody( $rawBody, $method, $normalized, $status );

			$this->log( 'info', $logBaseLine );

			return array(
				'status'  => $status,
				'body'    => $decoded,
				'headers' => $headers,
			);
		}

		// 4xx -> permanent client error (no AS retry).
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
		$this->log( 'warning', $logBaseLine );

		throw new SpreadconnectTransientError(
			'http_5xx',
			$logBaseLine,
			$status,
			$normalized
		);
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
}
