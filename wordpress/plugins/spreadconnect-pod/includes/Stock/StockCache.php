<?php
/**
 * Per-SKU live-stock cache backed by WordPress transients.
 *
 * Discovery flow F (architecture.md Z. 350 + Z. 685): the live-stock cache
 * stores the most recently observed SC stock-quantity per variation SKU
 * under the contract-bound transient key `sc_stock_{sku}` so the WC product-
 * meta-box and other consumers can render a stock indicator without hitting
 * the SC API on every page-load.
 *
 * The TTL is sourced from the `spreadconnect_live_cache_ttl_seconds` option
 * and clamped into the architecture-mandated `[60, 900]` window so a buggy
 * settings-form write or a defensive defense-in-depth fallback can never
 * persist an out-of-bounds TTL into the transient layer.
 *
 * Slice-36 contract surface (Provides To slice-34 + Webhook handler):
 *   - `get(string $sku): ?int`     — read-through, never calls the API.
 *   - `set(string $sku, int $qty)` — clamp-aware writer.
 *   - `invalidate(string $sku)`    — used by `spreadconnect_refresh_stock`
 *                                    AJAX (slice-34) before re-fetch.
 *
 * @package SpreadconnectPod\Stock
 */

declare(strict_types=1);

namespace SpreadconnectPod\Stock;

/**
 * Read-through transient wrapper for per-SKU stock quantities.
 *
 * `final` per slice-36 Constraints (no subclassing — the static collaborators
 * are wired by the consumer via constructor-DI).
 */
final class StockCache
{
	/**
	 * Transient key prefix shared across slice-34 (`Hub\Ajax\ProductActions`),
	 * slice-36 (this class + `LiveStockRefresher` + `StockSyncJob`) and any
	 * future read-only consumer. Architecture.md Z. 350 binds the literal.
	 */
	private const TRANSIENT_PREFIX = 'sc_stock_';

	/**
	 * Option name for the configurable TTL (slice-05 + slice-11).
	 */
	private const OPTION_TTL = 'spreadconnect_live_cache_ttl_seconds';

	/**
	 * Default TTL in seconds when the option is missing or unset
	 * (architecture.md Z. 350 — "default 300").
	 */
	private const TTL_DEFAULT_SECONDS = 300;

	/**
	 * Lower TTL clamp (architecture.md Z. 471 "Stock TTL clamp").
	 */
	private const TTL_MIN_SECONDS = 60;

	/**
	 * Upper TTL clamp (architecture.md Z. 471 "Stock TTL clamp").
	 */
	private const TTL_MAX_SECONDS = 900;

	/**
	 * Read the cached quantity for an SKU.
	 *
	 * Returns `null` on cache miss (the underlying `get_transient()` returns
	 * `false`, which we deliberately do NOT propagate to avoid the
	 * `0 == false` boolean confusion called out in slice-36 AC-1; the
	 * architecture-level type signature is `int?`).
	 *
	 * @param string $sku Variation SKU; passed verbatim into the transient
	 *                    key (`sc_stock_{sku}`).
	 *
	 * @return int|null Cached quantity, or `null` on miss / non-int payload.
	 */
	public function get( string $sku ): ?int
	{
		$cached = get_transient( self::TRANSIENT_PREFIX . $sku );

		if ( false === $cached ) {
			return null;
		}

		// Defensive: a corrupt transient (legacy serialised value, or a
		// downstream caller that wrote a non-int) is treated as a miss so
		// the LiveStockRefresher can rebuild the entry from a fresh API
		// call instead of poisoning the threshold-comparison in the job.
		if ( ! is_int( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Persist a per-SKU quantity under `sc_stock_{sku}` with the
	 * configured TTL.
	 *
	 * The TTL is read fresh on every `set()` call so a settings-form save
	 * within the same request flips behaviour without a plugin restart.
	 *
	 * @param string $sku      Variation SKU (transient-key suffix).
	 * @param int    $quantity Non-negative quantity returned by SC.
	 */
	public function set( string $sku, int $quantity ): void
	{
		set_transient(
			self::TRANSIENT_PREFIX . $sku,
			$quantity,
			$this->resolveTtlSeconds()
		);
	}

	/**
	 * Drop the cached entry for an SKU.
	 *
	 * Slice-36 Provides-To contract: invoked by the `spreadconnect_refresh_stock`
	 * AJAX (slice-34) before a forced re-fetch and internally by callers that
	 * need a guaranteed cache-miss on the next `get()`. No mass-invalidate
	 * surface — bulk refresh overwrites entries via {@see self::set()}.
	 */
	public function invalidate( string $sku ): void
	{
		delete_transient( self::TRANSIENT_PREFIX . $sku );
	}

	/**
	 * Resolve + clamp the TTL setting.
	 *
	 * Layered defence (slice-11 sanitises on save, slice-36 clamps on read)
	 * so a hand-edited `wp_options` row, a misbehaving migration script or
	 * an off-by-one in a future settings-form revision can never produce
	 * a TTL outside the documented `[60, 900]` window.
	 */
	private function resolveTtlSeconds(): int
	{
		$raw = get_option( self::OPTION_TTL, self::TTL_DEFAULT_SECONDS );

		// `get_option()` may return arbitrary scalars (or even objects when
		// a buggy plugin pollutes the row). Coerce numeric strings to int,
		// fall back to the default on anything else.
		if ( is_int( $raw ) ) {
			$ttl = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw && ctype_digit( $raw ) ) {
			$ttl = (int) $raw;
		} elseif ( is_numeric( $raw ) ) {
			$ttl = (int) $raw;
		} else {
			$ttl = self::TTL_DEFAULT_SECONDS;
		}

		return min( self::TTL_MAX_SECONDS, max( self::TTL_MIN_SECONDS, $ttl ) );
	}
}
