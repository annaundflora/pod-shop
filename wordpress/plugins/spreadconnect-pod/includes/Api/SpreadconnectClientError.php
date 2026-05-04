<?php
/**
 * Permanent (non-retryable) Spreadconnect API error.
 *
 * Thrown by {@see SpreadconnectClient::request()} when the upstream API
 * responds with an HTTP 4xx status (or a pre-flight guard refuses to dispatch
 * the request, e.g. a missing API key). Permanent errors must NOT trigger an
 * Action-Scheduler retry — callers (Slice 28-30 order jobs, Slice 37 DLQ)
 * record them directly to `wp_spreadconnect_failed_ops` and surface an
 * Admin-Notice.
 *
 * The companion class {@see SpreadconnectTransientError} signals 5xx /
 * network / `Retry-After` conditions that ARE retryable.
 *
 * Architecture: `architecture.md -> "Error Handling Strategy" Z. 603-608`
 * (4xx = permanent client error). Discovery: `discovery.md -> Slice 2`
 * (Outbound API client error taxonomy).
 *
 * @package SpreadconnectPod\Api
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api;

use RuntimeException;
use Throwable;

/**
 * Permanent client-side API error (HTTP 4xx, auth-missing, validation, …).
 *
 * Subclass of `\RuntimeException` so existing global error handlers continue
 * to work. Adds a string {@see self::getAppCode()} accessor (the int-typed
 * `\Exception::$code` is unsuitable for our string error codes such as
 * `auth_missing` / `http_4xx`) plus optional HTTP-status and endpoint-path
 * accessors for log enrichment.
 *
 * Marked `final` because Slice 08 (rate-limit / retry) extends behaviour by
 * reusing this class as-is, not by sub-classing it.
 */
final class SpreadconnectClientError extends RuntimeException
{
	/**
	 * Application-level error code (e.g. `auth_missing`, `http_4xx`, `invalid_json`).
	 *
	 * Stored in a dedicated property because PHP's built-in `\Exception::$code`
	 * is an `int` — the test contract (Slice 07 AC-10) requires a string code.
	 */
	private string $appCode;

	/**
	 * HTTP status returned by Spreadconnect, when the failure originates from
	 * an upstream response. `null` for pre-flight guards that never dispatched
	 * (e.g. `auth_missing`).
	 */
	private ?int $statusCode;

	/**
	 * Spreadconnect endpoint path that was being called (e.g. `/orders`).
	 * `null` when the failure pre-dates path resolution.
	 */
	private ?string $endpointPath;

	/**
	 * @param string         $code         Application error code (string).
	 * @param string         $message      Human-readable diagnostic message
	 *                                     (must NOT contain the Bearer token).
	 * @param int|null       $statusCode   HTTP status, when available.
	 * @param string|null    $endpointPath SC endpoint path, when available.
	 * @param Throwable|null $previous     Previous exception for chaining.
	 */
	public function __construct(
		string $code,
		string $message,
		?int $statusCode = null,
		?string $endpointPath = null,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );

		$this->appCode      = $code;
		$this->statusCode   = $statusCode;
		$this->endpointPath = $endpointPath;
	}

	/**
	 * Return the application-level (string) error code.
	 *
	 * Distinct from the inherited `getCode()` which always returns `int 0`
	 * (we deliberately do not override the parent method to avoid clashing
	 * with PHP's `\Exception` covariance rules).
	 */
	public function getAppCode(): string
	{
		return $this->appCode;
	}

	/**
	 * Return the upstream HTTP status code, if available.
	 */
	public function getStatusCode(): ?int
	{
		return $this->statusCode;
	}

	/**
	 * Return the Spreadconnect endpoint path, if available.
	 */
	public function getEndpointPath(): ?string
	{
		return $this->endpointPath;
	}
}
