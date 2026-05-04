<?php
/**
 * Not-Implemented marker exception for reserved Spreadconnect endpoint wrappers.
 *
 * Thrown by the four reserved wrapper methods on {@see SpreadconnectClient}
 * (`pushArticle`, `deleteArticle`, `updateOrder`, `uploadDesign`) that exist
 * only to keep the API surface complete vis-à-vis the Spreadconnect
 * Fulfillment API v2.3.9 spec. Calling any of them is a programmer error,
 * not a runtime / network failure — therefore this class extends
 * {@see \LogicException} and is **not** a subclass of
 * {@see SpreadconnectClientError} or {@see SpreadconnectTransientError}.
 *
 * Action Scheduler must NOT classify this as either permanent (4xx) or
 * transient (5xx / 429 / network) — the operation cannot succeed regardless
 * of how many times it is retried; the call site has to be removed by the
 * developer.
 *
 * Architecture: `architecture.md -> "Outbound: Spreadconnect REST Endpoints"`
 * Z. 96, 97, 100, 123 — the four reserved/out-of-scope wrappers.
 *
 * @package SpreadconnectPod\Api
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api;

use LogicException;

/**
 * Marker exception for the four reserved wrappers (out-of-MVP-scope endpoints).
 *
 * Constructor preserves the standard `\LogicException` signature; callers
 * compose the message with the canonical pattern
 * `"<VERB> <path> is out of MVP scope (<reason>)"`.
 */
final class NotImplementedError extends LogicException
{
}
