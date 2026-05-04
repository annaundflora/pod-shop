<?php
/**
 * Central WC-Logger adapter (slice-42).
 *
 * Wraps `wc_get_logger()->log()` with three responsibilities:
 *
 *   1. **Source whitelist** — every `$context['source']` MUST be one of the
 *      six canonical Spreadconnect log sources defined by {@see Sources}.
 *      Anything else is rejected with `\InvalidArgumentException` so a
 *      typo at the call site fails loud at the earliest possible point.
 *   2. **Secret redaction** — Bearer tokens, the `X-SPRD-SIGNATURE` HMAC
 *      header and the `api_key` context key are scrubbed from BOTH the
 *      message and the context (recursively) before the final
 *      `WC_Logger::log()` call. Architecture.md Z. 494 makes this a
 *      compliance mandate (Bearer redaction). The same pass scrubs the
 *      slice-15 webhook signature header to keep webhook-receiver logs
 *      free of HMAC bytes.
 *   3. **Forward to WC_Logger** — once the inputs are sanitised and the
 *      source has been validated, the entry is dispatched as a single
 *      `wc_get_logger()->log( $level, $message, $context )` call. WC's
 *      default file handler routes the entry to
 *      `wc-logs/spreadconnect-{source}-{date}-{hash}.log`, which slice-42
 *      `Hub\View\Logs` then reads back for the admin tail-view.
 *
 * Also provides {@see self::redact()} as a public, idempotent helper that
 * other slices (e.g. `Webhook\WebhookController` for pre-persist payload
 * snippets) can call directly without going through the logger pipeline.
 *
 * The adapter is `final` and exposes only `static` methods — there is no
 * cross-request state and the call surface should be auditable from any
 * grep without instance-tracking.
 *
 * @package SpreadconnectPod\Logging
 */

declare(strict_types=1);

namespace SpreadconnectPod\Logging;

use InvalidArgumentException;

/**
 * Stateless adapter over `wc_get_logger()`.
 *
 * Architecture refs:
 *   - architecture.md Z. 398 (Service Map row + 5 source strings).
 *   - architecture.md Z. 494 (Bearer-Redaction is mandatory before write).
 *   - architecture.md Z. 687 (raw-`error_log` banned — every log goes here).
 *   - architecture.md Z. 538 + Z. 757 (`wc_get_logger` over raw-`error_log`).
 *
 * Slice 42 introduces this wrapper as the single Plugin-side logger; the
 * existing source-string constants in `SpreadconnectClient` (slice-07) and
 * `WebhookController` (slice-15) intentionally remain private — they merely
 * mirror the values that ALSO live in {@see Sources::ALL}, the Single
 * Source of Truth. Adding a new source means editing exactly one constant
 * list ({@see Sources}) and one row in slice-42's Sources table.
 */
final class WcLoggerAdapter
{
	/**
	 * Maximum recursion depth for the {@see self::redactValue()} walker.
	 *
	 * Guards against pathological cyclic object graphs that pass `__toString`
	 * but reference themselves via private state. The slice-42 Constraints
	 * document this as the "Tiefe-Limit 3" recursion budget.
	 */
	private const REDACTION_MAX_DEPTH = 3;

	/**
	 * Public placeholder string used for every redacted secret.
	 *
	 * Three asterisks — short enough to keep log lines compact, long enough
	 * to be visually distinct from a real token. Matches the literal
	 * fixtures in slice-42 AC-2/3/4.
	 */
	private const REDACTION_PLACEHOLDER = '***';

	/**
	 * Context keys whose VALUE is always replaced by the placeholder
	 * regardless of its content. The KEY itself remains for diagnostic
	 * traceability (slice-42 AC-4 — "der Schluessel selbst bleibt erhalten").
	 *
	 * @var list<string>
	 */
	private const SECRET_CONTEXT_KEYS = array(
		'api_key',
		'apikey',
		'api-key',
		'authorization',
		'bearer',
		'webhook_secret',
		'secret',
	);

	/**
	 * Single-step entry-point — every other public method (info/warning/…)
	 * funnels into here.
	 *
	 * Pre-conditions (validated, fail-loud on violation):
	 *   - `$context['source']` MUST exist.
	 *   - It MUST be one of the six values in {@see Sources::ALL}.
	 *
	 * Side-effects:
	 *   - Redacts Bearer tokens, `X-SPRD-SIGNATURE` headers and known
	 *     secret-keys in BOTH `$message` and the entire `$context` array
	 *     (recursive walk capped at {@see self::REDACTION_MAX_DEPTH}).
	 *   - Forwards the cleansed pair to `wc_get_logger()->log()` with the
	 *     original `$level` and the original `$context` (now redacted).
	 *
	 * Behaves as a no-op when `wc_get_logger()` is unavailable (very early
	 * bootstrap, stripped test contexts) — the source-validation still
	 * runs, so a typo cannot silently sneak past in those environments.
	 *
	 * @param string                $level   PSR-3 level — one of
	 *                                       `debug|info|warning|error`. The
	 *                                       slice-42 Constraints restrict
	 *                                       the legal set to that subset.
	 * @param string                $message Pre-formatted human-readable
	 *                                       message. Bearer tokens / HMAC
	 *                                       signatures inside are scrubbed.
	 * @param array<string, mixed>  $context Context map. MUST carry
	 *                                       `source` ∈ {@see Sources::ALL}.
	 *
	 * @throws InvalidArgumentException When `source` is missing or not
	 *                                  whitelisted. The `wc_get_logger()`
	 *                                  side-channel is NEVER called in
	 *                                  this case.
	 */
	public static function log( string $level, string $message, array $context ): void
	{
		$source = isset( $context['source'] ) ? $context['source'] : null;
		if ( ! is_string( $source ) || ! in_array( $source, Sources::ALL, true ) ) {
			throw new InvalidArgumentException(
				'WcLoggerAdapter: $context["source"] must be one of: '
				. implode( ', ', Sources::ALL )
			);
		}

		$redactedMessage = self::redactString( $message );
		$redactedContext = self::redactArray( $context, 0 );

		// Defensive double-set: even if a caller ever passed `source` through
		// `redactArray` and a future redaction rule mangles its literal value,
		// re-pin the validated string so the WC log file routes correctly.
		$redactedContext['source'] = $source;

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log( $level, $redactedMessage, $redactedContext );
	}

	/**
	 * Convenience wrapper for `log('info', ...)`.
	 *
	 * @param string               $source  One of {@see Sources::ALL}.
	 * @param string               $message Free-form message.
	 * @param array<string, mixed> $context Optional extra context — `source`
	 *                                      is overwritten with `$source`.
	 */
	public static function info( string $source, string $message, array $context = array() ): void
	{
		$context['source'] = $source;
		self::log( 'info', $message, $context );
	}

	/**
	 * Convenience wrapper for `log('warning', ...)`. See {@see self::info()}.
	 */
	public static function warning( string $source, string $message, array $context = array() ): void
	{
		$context['source'] = $source;
		self::log( 'warning', $message, $context );
	}

	/**
	 * Convenience wrapper for `log('error', ...)`. See {@see self::info()}.
	 */
	public static function error( string $source, string $message, array $context = array() ): void
	{
		$context['source'] = $source;
		self::log( 'error', $message, $context );
	}

	/**
	 * Convenience wrapper for `log('debug', ...)`. See {@see self::info()}.
	 */
	public static function debug( string $source, string $message, array $context = array() ): void
	{
		$context['source'] = $source;
		self::log( 'debug', $message, $context );
	}

	/**
	 * Public, idempotent redaction helper.
	 *
	 * Other slices (e.g. `Webhook\WebhookController` when persisting payload
	 * snippets to `webhook_log.payload`) call this directly to scrub Bearer
	 * tokens / `X-SPRD-SIGNATURE` values BEFORE persistence — the adapter
	 * walks identical strings to the same result, so passing through twice
	 * is safe and never accumulates.
	 *
	 * @param string|array<mixed, mixed>|mixed $value Scalar or arbitrarily
	 *                                                nested array. Other
	 *                                                types are returned
	 *                                                unchanged.
	 *
	 * @return string|array<mixed, mixed>|mixed The same shape as `$value`,
	 *                                          with secret substrings
	 *                                          replaced by `***`.
	 */
	public static function redact( $value )
	{
		if ( is_string( $value ) ) {
			return self::redactString( $value );
		}
		if ( is_array( $value ) ) {
			return self::redactArray( $value, 0 );
		}
		return $value;
	}

	/**
	 * Apply the four redaction patterns to a single string.
	 *
	 * Order matters: header-prefixed Bearer first (longest match), then the
	 * `X-SPRD-SIGNATURE` header, then the free-form Bearer pattern.
	 *
	 * Patterns (architecture.md Z. 494, slice-42 Constraints):
	 *   - `/Authorization\s*:\s*Bearer\s+[^\s,;]+/i` → `Authorization: Bearer ***`
	 *   - `/X-SPRD-SIGNATURE\s*:\s*[^\s,;]+/i`      → `X-SPRD-SIGNATURE: ***`
	 *   - `/Bearer\s+[A-Za-z0-9_\-\.=]{20,}/i`      → `Bearer ***`
	 *
	 * The free-form Bearer pattern is intentionally restrictive (20+ chars
	 * from the URL-safe-base64 alphabet) so an arbitrary message containing
	 * the word "Bearer" without a token suffix is left untouched.
	 *
	 * @param string $value Free-form text.
	 *
	 * @return string Same text with secret substrings replaced by `***`.
	 */
	private static function redactString( string $value ): string
	{
		$result = preg_replace(
			'/Authorization\s*:\s*Bearer\s+[^\s,;]+/i',
			'Authorization: Bearer ' . self::REDACTION_PLACEHOLDER,
			$value
		);
		if ( null === $result ) {
			$result = $value;
		}

		$result = preg_replace(
			'/X-SPRD-SIGNATURE\s*:\s*[^\s,;]+/i',
			'X-SPRD-SIGNATURE: ' . self::REDACTION_PLACEHOLDER,
			$result
		);
		if ( null === $result ) {
			$result = $value;
		}

		$result = preg_replace(
			'/Bearer\s+[A-Za-z0-9_\-\.=]{20,}/i',
			'Bearer ' . self::REDACTION_PLACEHOLDER,
			$result
		);
		if ( null === $result ) {
			$result = $value;
		}

		return $result;
	}

	/**
	 * Recursive context walker.
	 *
	 * - Strings → {@see self::redactString()}.
	 * - Arrays → recurse, capped at {@see self::REDACTION_MAX_DEPTH}.
	 * - Objects with `__toString` → cast to string and redact.
	 * - Other objects → `(string) get_class()` placeholder so we never
	 *   accidentally leak property data via `print_r`.
	 * - Scalars (int/float/bool/null) → unchanged (cannot carry secrets).
	 *
	 * Sensitive context KEYS (e.g. `api_key`) get their VALUE replaced by
	 * the placeholder before any per-type recursion — slice-42 AC-4
	 * mandates that the key remains visible while the value is wiped
	 * regardless of its concrete type.
	 *
	 * @param array<mixed, mixed> $value Context array (may be nested).
	 * @param int                 $depth Current recursion depth — caller
	 *                                   passes 0; recursion increments.
	 *
	 * @return array<mixed, mixed> Same shape, with sensitive substrings
	 *                             replaced.
	 */
	private static function redactArray( array $value, int $depth ): array
	{
		if ( $depth >= self::REDACTION_MAX_DEPTH ) {
			// Past the budget — return as-is rather than risk infinite
			// recursion on a cyclic object graph.
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::isSecretContextKey( $key ) ) {
				$out[ $key ] = self::REDACTION_PLACEHOLDER;
				continue;
			}

			if ( is_string( $item ) ) {
				$out[ $key ] = self::redactString( $item );
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::redactArray( $item, $depth + 1 );
				continue;
			}

			if ( is_object( $item ) ) {
				if ( method_exists( $item, '__toString' ) ) {
					$out[ $key ] = self::redactString( (string) $item );
				} else {
					$out[ $key ] = '<' . get_class( $item ) . '>';
				}
				continue;
			}

			$out[ $key ] = $item;
		}

		return $out;
	}

	/**
	 * Case-insensitive membership test for {@see self::SECRET_CONTEXT_KEYS}.
	 *
	 * Comparing in lower-case lets callers spell context keys however they
	 * like (`Authorization`, `authorization`, `API_KEY`, `api-key`) without
	 * a single one of those leaking through.
	 */
	private static function isSecretContextKey( string $key ): bool
	{
		$normalised = strtolower( $key );
		return in_array( $normalised, self::SECRET_CONTEXT_KEYS, true );
	}
}
