<?php
/**
 * Transient (retryable) Spreadconnect API error.
 *
 * Thrown by {@see SpreadconnectClient::request()} when the upstream call
 * fails in a way that is potentially recoverable on retry: HTTP 5xx,
 * network failure / timeout (`WP_Error` from `wp_remote_request()`),
 * malformed JSON response on a 2xx, or — once Slice 08 lands — an HTTP 429
 * with `Retry-After`.
 *
 * Callers (Slice 28-30 Action-Scheduler job handlers) re-throw transient
 * errors so Action-Scheduler can apply the standard 1m / 5m / 15m retry
 * cascade. After the third retry the failure is recorded in
 * `wp_spreadconnect_failed_ops` (Slice 37).
 *
 * Architecture: `architecture.md -> "Error Handling Strategy" Z. 603-608`
 * (5xx / network = transient).
 *
 * @package SpreadconnectPod\Api
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api;

use RuntimeException;
use Throwable;

/**
 * Retryable upstream API error (HTTP 5xx, network/timeout, 429, malformed JSON).
 *
 * Mirrors {@see SpreadconnectClientError} byte-for-byte at the API surface
 * — same constructor signature, same accessors — so callers can
 * `try/catch` either type interchangeably while higher-level orchestrators
 * (Slice 28+) branch on the concrete class for retry semantics.
 *
 * Marked `final` for the same reason as {@see SpreadconnectClientError}:
 * Slice 08 reuses this exact class for the 429-Retry-After path rather than
 * sub-classing it.
 */
final class SpreadconnectTransientError extends RuntimeException
{
	/**
	 * Application-level error code (e.g. `http_5xx`, `network_error`, `invalid_json`).
	 *
	 * String-typed (the test contract requires a string accessor — see
	 * {@see SpreadconnectClientError::$appCode} for rationale).
	 */
	private string $appCode;

	/**
	 * HTTP status returned by Spreadconnect, when available. `null` for
	 * `WP_Error` / network-level failures where no HTTP exchange took place.
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
