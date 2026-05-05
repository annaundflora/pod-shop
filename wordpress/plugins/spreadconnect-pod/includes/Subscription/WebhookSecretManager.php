<?php
/**
 * HMAC webhook-secret lifecycle manager (Slice 14).
 *
 * Owns the create / rotate / read flow for `spreadconnect_webhook_secret`
 * — the shared secret that signs every inbound Spreadconnect webhook
 * (architecture.md Z. 483 / Z. 492). The plaintext is exposed exactly once
 * via the one-time-reveal payload returned by {@see self::generate()} /
 * {@see self::regenerate()}; subsequent reads happen exclusively through
 * {@see self::peek()} and are reserved for the HMAC verifier (slice-15) +
 * the settings-export exclusion list (slice-45) — every other consumer
 * must treat the plaintext as opaque and unreadable from PHP.
 *
 * Persistence layout (companion options, lazy-defaulted to keep slice-05
 * clean — see slice-14 spec "Option-Defaults" note):
 *   - `spreadconnect_webhook_secret`            base64(32 random bytes), ~44 chars.
 *   - `spreadconnect_webhook_secret_generated_at` int, unix timestamp of last write.
 *   - `spreadconnect_webhook_secret_revealed_at` int, one-way "user has seen it" flag.
 *
 * Rotation contract: on every successful persist, the manager fires
 * `do_action('spreadconnect/webhook_secret_rotated', $newSecret, $context)`
 * exactly once. Slice 18 (`SubscriptionManager::resubscribeAll`) listens
 * to that hook; this slice ships only the producer. The action fires
 * AFTER persistence — a failed `update_option()` must not trigger a
 * re-subscribe sweep.
 *
 * @package SpreadconnectPod\Subscription
 */

declare(strict_types=1);

namespace SpreadconnectPod\Subscription;

use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;

/**
 * Stateless manager for the HMAC webhook secret.
 *
 * Final + only static methods — the manager carries no instance state. The
 * single non-public seam is {@see self::generateRandomBytes()}, declared
 * `protected static` so test doubles can substitute deterministic bytes
 * via a subclass without monkey-patching `random_bytes()` globally.
 */
final class WebhookSecretManager
{
	/**
	 * Persisted base64 secret. `wp_options` `LONGTEXT` (architecture.md Z. 272).
	 */
	public const OPTION_SECRET = 'spreadconnect_webhook_secret';

	/**
	 * Companion option: unix timestamp of the most recent generate/regenerate.
	 *
	 * Settings UI displays this as the "last regenerated" hint. Lazy-defaulted
	 * via `get_option(..., 0)` to avoid mutating slice-05 OptionsDefaults.
	 */
	public const OPTION_GENERATED_AT = 'spreadconnect_webhook_secret_generated_at';

	/**
	 * Companion option: one-way flag — once a non-zero timestamp is written
	 * (after the user clicks `[Done]` on the initial reveal panel), the
	 * panel is permanently UI-locked. Never reset by {@see self::regenerate()}.
	 */
	public const OPTION_REVEALED_AT = 'spreadconnect_webhook_secret_revealed_at';

	/**
	 * Action hook fired once after every successful persist.
	 *
	 * Contract for slice-18 listener: `do_action(self::ACTION_ROTATED, string $newSecret, array $context)`.
	 * Context array carries `is_initial` (bool) plus `generated_at` (int).
	 */
	public const ACTION_ROTATED = 'spreadconnect/webhook_secret_rotated';

	/**
	 * Number of random bytes to draw before base64-encoding. base64(32) ≈ 44
	 * characters; comfortably within the LONGTEXT capacity and well above the
	 * 256-bit minimum entropy requirement for HMAC-SHA256.
	 */
	private const SECRET_BYTES = 32;

	/**
	 * Generate the very first secret.
	 *
	 * Behaviour mirrors {@see self::regenerate()} except the returned reveal
	 * payload carries `is_initial=true` and the rotation hook is fired with
	 * the same `is_initial=true` context, so slice-18 can distinguish a
	 * post-onboarding initial subscribe from a subsequent rotation.
	 *
	 * @return array{secret:string,generated_at:int,is_initial:bool}
	 */
	public static function generate(): array
	{
		return self::createAndPersist( true );
	}

	/**
	 * Rotate the existing secret.
	 *
	 * Overwrites whatever was persisted. The previous plaintext is unreadable
	 * after this call — `update_option()` is destructive by design, and
	 * {@see self::peek()} reads the option freshly each time.
	 *
	 * @return array{secret:string,generated_at:int,is_initial:bool}
	 */
	public static function regenerate(): array
	{
		return self::createAndPersist( false );
	}

	/**
	 * Read-only accessor for the persisted plaintext.
	 *
	 * **Authorised callers (slice-14 spec AC-4):**
	 *   - Slice 15 `Webhook\WebhookSignatureVerifier` for `hash_equals()` compare.
	 *   - Slice 45 settings-export filter — only to add the option key to the
	 *     export-exclusion list, never to write the value into export JSON.
	 *
	 * Empty string when the option has never been generated. Never logs.
	 *
	 * @return string base64-encoded plaintext or `''` when uninitialised.
	 */
	public static function peek(): string
	{
		$value = get_option( self::OPTION_SECRET, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Internal create+persist pipeline shared by `generate()` and `regenerate()`.
	 *
	 * Strict ordering (Constraints "Persistenz-Atomicity"):
	 *   1. base64-encode 32 random bytes,
	 *   2. `update_option( OPTION_SECRET, $secret )`,
	 *   3. `update_option( OPTION_GENERATED_AT, $now )`,
	 *   4. log a redacted event marker (no plaintext — AC-9),
	 *   5. fire `do_action( ACTION_ROTATED, $secret, $context )`.
	 *
	 * The action fires only when persistence succeeded.
	 *
	 * @param bool $isInitial Whether this is the first-ever generation.
	 * @return array{secret:string,generated_at:int,is_initial:bool}
	 */
	private static function createAndPersist( bool $isInitial ): array
	{
		$secret      = base64_encode( static::generateRandomBytes( self::SECRET_BYTES ) );
		$generatedAt = (int) time();

		// `update_option()` returns false when the new value equals the old
		// one. Random secrets practically never collide; the return value
		// is intentionally discarded — what matters is the post-call option
		// content, not the changed-flag.
		update_option( self::OPTION_SECRET, $secret );

		update_option( self::OPTION_GENERATED_AT, $generatedAt );

		// AC-9: log the event marker WITHOUT the plaintext. We use error_log
		// here as a minimal stub; slice-42 swaps in the WC_Logger adapter
		// (which already redacts Bearer tokens analogously, architecture.md
		// Z. 494). The hint payload carries `len` + `is_initial` only.
		self::logRotationEvent( $isInitial, strlen( $secret ) );

		$context = array(
			'is_initial'   => $isInitial,
			'generated_at' => $generatedAt,
		);

		do_action( self::ACTION_ROTATED, $secret, $context );

		return array(
			'secret'       => $secret,
			'generated_at' => $generatedAt,
			'is_initial'   => $isInitial,
		);
	}

	/**
	 * Log the rotation event without leaking the plaintext.
	 *
	 * The full base64 string is replaced by a length hint — analog to the
	 * Bearer-token redaction in `WcLoggerAdapter` (architecture.md Z. 494).
	 *
	 * @param bool $isInitial Whether this was the first-ever generation.
	 * @param int  $len       Length of the (redacted) base64 string.
	 * @return void
	 */
	private static function logRotationEvent( bool $isInitial, int $len ): void
	{
		$marker = $isInitial ? 'secret_generated' : 'secret_rotated';
		// Plain-text-free message: the secret value is NEVER concatenated
		// into the log line; only the constant marker, length and is_initial
		// flag are emitted. Routed through slice-42 WcLoggerAdapter so the
		// entry lands in `wc-logs/spreadconnect-webhook-receiver-*` and
		// the AC-10 raw-`error_log` ban stays intact.
		WcLoggerAdapter::info(
			Sources::WEBHOOK_RECEIVER,
			sprintf(
				'%s len=%d is_initial=%s',
				$marker,
				$len,
				$isInitial ? 'true' : 'false'
			),
			array(
				'event'      => $marker,
				'length'     => $len,
				'is_initial' => $isInitial,
			)
		);
	}

	/**
	 * Cryptographic randomness seam.
	 *
	 * The single point in the codebase that calls `random_bytes()`. Tests
	 * substitute a subclass that overrides this method to return a fixed
	 * byte string — no Patchwork-replace of the global is required.
	 *
	 * @param int $len Number of bytes to draw (≥ 1).
	 * @return string `$len` cryptographically secure random bytes.
	 */
	protected static function generateRandomBytes( int $len ): string
	{
		return random_bytes( $len );
	}
}
