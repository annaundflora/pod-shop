<?php
/**
 * On-demand bulk stock refresher for one WC product.
 *
 * Pairs with {@see StockCache} to implement the "live" half of Discovery
 * flow F (architecture.md Z. 387, Z. 685): when the WC product-edit
 * meta-box (slice-34) or the article-updated webhook (slice-25 + this slice's
 * edit) needs the freshest stock-state for one product, this class:
 *
 *   1. Reverse-looks-up the WC product via `_spreadconnect_article_id` (the
 *      job iterates pre-resolved IDs, but the public method accepts an int).
 *   2. Skips when the product carries `_spreadconnect_sync_state='removed_in_sc'`
 *      so freshly-removed articles never re-trigger SC API traffic
 *      (slice-25 contract).
 *   3. Walks the variations, collects the non-empty SKUs.
 *   4. Cache-first read: every SKU already in {@see StockCache} short-circuits
 *      the API call (full-cache-hit path — slice-36 AC-4); on full miss the
 *      class issues exactly ONE bulk `getStock(null, $skus)` call (AC-3) — the
 *      per-SKU `getStockBySku()` loop is forbidden in hot paths
 *      (architecture.md Z. 716 / Z. 797).
 *   5. Writes every fresh entry back into {@see StockCache::set()} so a
 *      subsequent `get()` is a hit.
 *   6. Returns a `array<string,int>` map (sku → quantity) covering every
 *      variation, ready for the slice-34 stock-indicator render.
 *
 * The mixed-hit branch — some SKUs cached, others not — is implemented as a
 * partial-bulk-call (only the miss-set is sent to SC). This is the strictest
 * interpretation of slice-36 AC-4 ("Mixed-Hit darf entweder partial-bulk-call
 * (nur Miss-SKUs) oder Full-Skip implementieren — Implementer-Wahl") and
 * minimises both API traffic and cache staleness.
 *
 * Failure semantics (slice-36 Constraint "Webhook-Edit-Idempotenz"):
 *   - The class itself does NOT swallow exceptions. Both
 *     {@see SpreadconnectClientError} (4xx) and
 *     {@see SpreadconnectTransientError} (5xx) propagate verbatim so
 *     callers can decide whether to surface (slice-34 AJAX → 502) or
 *     swallow (Article.updated webhook → log + continue).
 *
 * @package SpreadconnectPod\Stock
 */

declare(strict_types=1);

namespace SpreadconnectPod\Stock;

use SpreadconnectPod\Api\Dto\StockEntry;
use SpreadconnectPod\Api\SpreadconnectClient;
use WC_Product;
use WC_Product_Variable;

/**
 * Application-layer collaborator for one-product stock refreshes.
 *
 * `final` per slice-36 Constraints. Constructor-DI mirrors slice-23 /
 * slice-25 conventions so unit tests can wire Mockery doubles for the SC
 * client and the cache.
 */
final class LiveStockRefresher
{
	/**
	 * Postmeta key for the SC article-ID reverse-lookup anchor (slice-22 /
	 * slice-23 / slice-25 mirror). Drives the iteration in {@see StockSyncJob}
	 * but is also read directly here as the "is this WC product even linked
	 * to SC?" gate.
	 */
	private const META_ARTICLE_ID = '_spreadconnect_article_id';

	/**
	 * Postmeta key for the multi-state sync result enum
	 * (`synced`/`partial`/`error`/`removed_in_sc`). Slice-25 sets the
	 * `removed_in_sc` value; slice-36 honours it as a hard skip-filter
	 * (AC-5).
	 */
	private const META_SYNC_STATE = '_spreadconnect_sync_state';

	/**
	 * Sentinel value marking a product as "the SC article has been
	 * `Article.removed`-webhooked" — slice-25 contract anchor.
	 */
	private const SYNC_STATE_REMOVED = 'removed_in_sc';

	public function __construct(
		private readonly SpreadconnectClient $client,
		private readonly StockCache $cache,
	) {
	}

	/**
	 * Refresh the live-stock state for one WC product.
	 *
	 * @param int $productId WC product post-ID. Must reference a
	 *                       {@see WC_Product_Variable}; non-variable or
	 *                       missing products yield an empty map.
	 *
	 * @return array<string, int> Map sku → quantity, or empty when the
	 *                            product is removed-in-SC, has no SKUs, or
	 *                            cannot be resolved.
	 */
	public function refresh( int $productId ): array
	{
		// AC-5: skip the entire pipeline when the WC product was flipped to
		// `removed_in_sc` by the slice-25 ArticleRemovedJob. No API call,
		// no cache write, empty map.
		if ( $this->isRemovedInSc( $productId ) ) {
			return array();
		}

		$skus = $this->collectVariationSkus( $productId );
		if ( empty( $skus ) ) {
			return array();
		}

		// Cache-first read pass — every SKU already cached is recorded into
		// the result map and removed from the API-call shortlist. The
		// remaining SKUs are sent to SC in ONE bulk call (AC-3 / AC-4).
		$result   = array();
		$missSkus = array();

		foreach ( $skus as $sku ) {
			$cached = $this->cache->get( $sku );

			if ( null === $cached ) {
				$missSkus[] = $sku;
				continue;
			}

			$result[ $sku ] = $cached;
		}

		// AC-4: full cache-hit path — no SKU missing, return cached map.
		if ( empty( $missSkus ) ) {
			return $result;
		}

		// AC-3: single bulk call for the miss-set. Per-SKU loops are
		// forbidden in hot paths (architecture.md Z. 716 / Z. 797).
		// FIXME(post-MVP): per-SKU fallback bei 4xx auf Bulk-Endpoint
		// (architecture.md Z. 121 "Optional fallback only").
		$entries = $this->client->getStock( null, $missSkus );

		foreach ( $entries as $entry ) {
			if ( ! ( $entry instanceof StockEntry ) ) {
				continue;
			}

			$this->cache->set( $entry->sku, $entry->quantity );
			$result[ $entry->sku ] = $entry->quantity;
		}

		return $result;
	}

	/**
	 * Return `true` when the product carries the
	 * `_spreadconnect_sync_state='removed_in_sc'` sentinel.
	 *
	 * Defensive against missing-product / corrupted-meta paths: any value
	 * other than the exact literal returns `false`, so a stale `synced` or
	 * a missing meta-row falls through into the normal refresh flow.
	 */
	private function isRemovedInSc( int $productId ): bool
	{
		$state = get_post_meta( $productId, self::META_SYNC_STATE, true );

		return is_string( $state ) && self::SYNC_STATE_REMOVED === $state;
	}

	/**
	 * Walk a variable product's children, filter non-empty SKUs.
	 *
	 * Mirrors {@see \SpreadconnectPod\Hub\Ajax\ProductActions::collectVariationSkus()}
	 * verbatim — kept duplicated rather than shared via a static helper to
	 * avoid a cross-package dependency between Hub and Stock layers.
	 *
	 * @return string[]
	 */
	private function collectVariationSkus( int $productId ): array
	{
		$skus = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $skus;
		}

		$product = wc_get_product( $productId );
		if ( ! ( $product instanceof WC_Product_Variable ) ) {
			return $skus;
		}

		$children = $product->get_children();
		if ( ! is_array( $children ) ) {
			return $skus;
		}

		foreach ( $children as $childId ) {
			$variation = wc_get_product( (int) $childId );
			if ( ! ( $variation instanceof WC_Product ) ) {
				continue;
			}

			$sku = (string) $variation->get_sku();
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		return $skus;
	}
}
