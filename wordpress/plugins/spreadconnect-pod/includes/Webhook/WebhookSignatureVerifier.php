<?php
/**
 * Pure-domain HMAC-SHA256 signature verifier for inbound Spreadconnect
 * webhooks (slice-15).
 *
 * The verifier carries no WordPress dependencies, no logger and no side
 * effects — its sole job is to answer the question "does this raw body
 * authenticate against this base64-encoded signature using this shared
 * secret?" in **constant time**.
 *
 * Algorithm (architecture.md Z. 483):
 *   1. Reject empty `$secret` immediately (defense-in-depth — an
 *      uninitialised plugin must never accept any signature, even when
 *      the caller has supplied a matching empty string).
 *   2. Strict-decode `$providedSignatureBase64` via
 *      `base64_decode($_, true)`. Non-base64 input → `false` → reject.
 *   3. Compute the expected raw-binary HMAC via
 *      `hash_hmac('sha256', $rawBody, $secret, true)`.
 *   4. Compare the two raw-binary strings exclusively through
 *      `hash_equals()`. No `===`, no `==`, no `strcmp`,
 *      no `strlen()`-prefilter — `hash_equals` itself is constant-time
 *      regardless of length differences.
 *
 * The Patchwork-redefine seam for `hash_equals` (already listed in the
 * plugin's `patchwork.json`) lets the test suite assert that the
 * comparison runs through `hash_equals` exactly once per `verify()`
 * invocation — the architectural constraint "constant-time-compare
 * verified" from the slice-15 done-signal.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

/**
 * Stateless, single-method HMAC verifier.
 *
 * Final + only one `static` method. No constructor, no instance state.
 * The class is the canonical Domain-layer answer to the inbound-webhook
 * authentication question and is consumed by
 * {@see WebhookController::authorize()} (slice-15) and verifiable in
 * isolation without any WP bootstrap.
 */
final class WebhookSignatureVerifier
{
	/**
	 * HMAC algorithm identifier — single canonical choice for both
	 * `hash_hmac()` and (transitively) the SC-side signing process.
	 *
	 * Centralised as a `private const` so the test suite can rely on the
	 * exact string when computing fixture signatures with the same
	 * algorithm; changing this value here would mean changing it on the
	 * SC side too — a coordinated rotation, not a one-sided edit.
	 */
	private const HMAC_ALGO = 'sha256';

	/**
	 * Verify a base64-encoded HMAC-SHA256 signature against a raw body
	 * and a shared secret.
	 *
	 * Returns `true` only when the constant-time compare succeeds.
	 * Every other code-path — empty secret, non-base64 signature,
	 * mismatching bytes — collapses to `false`.
	 *
	 * @param string $rawBody                The exact raw request body as
	 *                                       emitted by Spreadconnect, byte
	 *                                       for byte. Never JSON-decoded
	 *                                       and re-encoded.
	 * @param string $providedSignatureBase64 The value of the
	 *                                       `X-SPRD-SIGNATURE` request
	 *                                       header — base64-encoded raw
	 *                                       HMAC-SHA256 bytes.
	 * @param string $secret                 The shared secret persisted
	 *                                       under
	 *                                       `spreadconnect_webhook_secret`
	 *                                       (read via
	 *                                       {@see \SpreadconnectPod\Subscription\WebhookSecretManager::peek()}).
	 *
	 * @return bool `true` when the signature authenticates, `false` in
	 *              every other case.
	 */
	public static function verify(
		string $rawBody,
		string $providedSignatureBase64,
		string $secret
	): bool {
		// AC-6: a never-generated secret must never accept any signature.
		// We bail BEFORE invoking hash_hmac/hash_equals so the test
		// instrumentation can assert a zero call-count for both.
		if ( '' === $secret ) {
			return false;
		}

		// AC-3 / AC-4: strict base64 decode rejects any byte that is not
		// part of the base64 alphabet. A `false` return here means the
		// header was either empty, padded with garbage or missing the
		// trailing `=` — all three are 401 conditions.
		$providedRaw = base64_decode( $providedSignatureBase64, true );
		if ( false === $providedRaw || '' === $providedRaw ) {
			return false;
		}

		// `true` = raw binary output. The result is exactly 32 bytes for
		// SHA-256, identical in length to a correctly base64-decoded
		// `X-SPRD-SIGNATURE` value (architecture-constraint AC-10).
		$expectedRaw = hash_hmac( self::HMAC_ALGO, $rawBody, $secret, true );

		// AC-4 / AC-5 / AC-10: the SOLE place the two byte-strings are
		// compared. `hash_equals` runs in constant time even when the
		// lengths differ, so we deliberately omit any `strlen()` early
		// exit — that would re-introduce a timing side-channel.
		return hash_equals( $expectedRaw, $providedRaw );
	}
}
