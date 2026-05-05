<?php
/**
 * Recurring per-product stock-sync (Action-Scheduler hook handler).
 *
 * Periodic half of Discovery flow F (architecture.md Z. 386, Z. 554, Z. 621-
 * 623): on every tick of the `spreadconnect/scheduled_stock_sync` recurring
 * action this job iterates all WC products linked to an SC article-id, fetches
 * their current stock state via ONE bulk `GET /stock?skus=…` call per product
 * and mutates WC variation-stock when (and only when) the SC `quantity` falls
 * below the configured `spreadconnect_low_stock_threshold`. Above-threshold
 * variations stay on POD-default `manage_stock=false` (unlimited) so the
 * shop never has to track infinity.
 *
 * Interval is sourced from the `spreadconnect_stock_sync_interval` enum
 * (`1h`/`4h`/`6h`/`12h`/`24h`) by the bootstrap; the job itself does not
 * read the option — it always processes the full linked-product set on
 * every dispatch.
 *
 * Sequence (slice-36 AC-6 / AC-7):
 *   1. Iterate WC products with `_spreadconnect_article_id IS NOT NULL`
 *      AND `_spreadconnect_sync_state != 'removed_in_sc'` (paginated via
 *      {@see self::BATCH_SIZE} so a 10k catalogue does not OOM).
 *   2. Per product: collect non-empty variation SKUs.
 *   3. Per product: ONE bulk `getStock(null, $skus)` call (per-SKU loops
 *      are forbidden — architecture.md Z. 716 / Z. 797).
 *   4. Per StockEntry: cache-write via {@see StockCache} (AC-2 path —
 *      every SKU caches, threshold-gated decision applies only to the
 *      WC mutation).
 *   5. Per StockEntry: when `quantity < threshold` →
 *      `set_manage_stock(true)` + `set_stock_quantity($qty)` +
 *      `set_stock_status($qty > 0 ? 'instock' : 'outofstock')` + `save()`.
 *      Above threshold → no WC-stock mutation.
 *
 * Failure mapping (slice-36 AC-10 + Constraints "Per-Produkt-Isolation"):
 *   - {@see SpreadconnectTransientError} (5xx / network) → re-thrown
 *     unchanged so AS triggers the 1m/5m/15m retry cascade
 *     (architecture.md Z. 554).
 *   - {@see SpreadconnectClientError} (4xx) → also re-thrown; slice-37
 *     RetryPolicyListener decides DLQ-Aufnahme.
 *   - Per-product try/catch boundary on {@see SpreadconnectClientError}
 *     would be a useful future refinement (article in SC removed but the
 *     webhook hasn't landed yet) — slice-36 keeps it simple: hard re-throw
 *     so the run is idempotently retried; already-processed products
 *     persist their cache writes from earlier in the run.
 *
 * Architecture references:
 *   - "Service Map" → `Stock\StockSyncJob` (Application).
 *   - "Action Scheduler — Hook Inventory" → `spreadconnect/scheduled_stock_sync`
 *     (recurring, Concurrency 1, Retry 1m/5m/15m).
 *   - "Settings" → `spreadconnect_low_stock_threshold` (default 10).
 *
 * @package SpreadconnectPod\Stock
 */

declare(strict_types=1);

namespace SpreadconnectPod\Stock;

use SpreadconnectPod\Api\Dto\StockEntry;
use SpreadconnectPod\Api\SpreadconnectClient;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Action-Scheduler handler for the `spreadconnect/scheduled_stock_sync` hook.
 *
 * Constructor-DI mirrors slice-23 / slice-25 conventions; the static
 * {@see self::handleStatic()} bridge is the `add_action()` callable wired by
 * `Bootstrap\Plugin` and instantiates the production-default collaborator
 * chain ({@see SpreadconnectClient}, {@see StockCache}).
 *
 * `final` per slice-36 Constraints.
 */
final class StockSyncJob
{
	/**
	 * Postmeta key for the SC article-ID anchor (slice-22 / slice-23 /
	 * slice-25 mirror). Reverse-lookup target for the linked-product
	 * iteration in {@see self::handle()}.
	 */
	private const META_ARTICLE_ID = '_spreadconnect_article_id';

	/**
	 * Postmeta key for the multi-state sync result enum (slice-25 sets
	 * `removed_in_sc`). Filtered out of the iteration so removed articles
	 * never re-trigger SC API traffic.
	 */
	private const META_SYNC_STATE = '_spreadconnect_sync_state';

	/**
	 * Sentinel value for the `_spreadconnect_sync_state` filter (slice-25
	 * contract anchor — a product flipped to this state is intentionally
	 * left as-is by the stock-sync pipeline).
	 */
	private const SYNC_STATE_REMOVED = 'removed_in_sc';

	/**
	 * Option name for the configurable low-stock threshold
	 * (slice-05 + slice-11). Default `10` per architecture.md Z. 333.
	 */
	private const OPTION_THRESHOLD = 'spreadconnect_low_stock_threshold';

	/**
	 * Threshold default when the option is missing or unset.
	 */
	private const THRESHOLD_DEFAULT = 10;

	/**
	 * Page size for the linked-product iteration. Slice-36 Constraint
	 * "Reverse-Lookup-Pattern" suggests 50 — small enough to avoid
	 * memory spikes on a 10k catalogue, large enough to keep the
	 * `posts_per_page` round-trips manageable.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * `wc_get_logger()` source string for the periodic stock-sync job
	 * (architecture.md Z. 532). Slice-42 swaps the direct `wc_get_logger`
	 * call for a `WcLoggerAdapter` but the source label stays.
	 */
	private const LOG_SOURCE = 'spreadconnect-sync-job';

	public function __construct(
		private readonly SpreadconnectClient $client,
		private readonly StockCache $cache,
	) {
	}

	/**
	 * Static bridge for `add_action('spreadconnect/scheduled_stock_sync', …)`.
	 *
	 * Action-Scheduler invokes the registered hook with the args-array as
	 * the first parameter (recurring schedules pass `[]`). This bridge
	 * instantiates the production-default collaborator chain and delegates
	 * to {@see self::handle()}.
	 *
	 * @param array<string, mixed> $args Recurring AS args (empty for slice-36).
	 */
	public static function handleStatic( array $args = array() ): void
	{
		( new self(
			new SpreadconnectClient(),
			new StockCache()
		) )->handle( $args );
	}

	/**
	 * Run one full periodic stock-sync sweep over every linked WC product.
	 *
	 * @param array<string, mixed> $args Unused (recurring hook passes `[]`).
	 */
	public function handle( array $args = array() ): void
	{
		$threshold = $this->resolveThreshold();

		$paged = 1;

		// Paginated iteration — slice-36 Constraint "Reverse-Lookup-Pattern":
		// `wc_get_products` is preferred over a raw `$wpdb` query so the
		// lookup stays consistent with the slice-22 / slice-25 reverse-lookup
		// idiom (and respects WP filter hooks). On boots without WC the
		// helper is missing and the job becomes a no-op.
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}

		do {
			$productIds = $this->fetchLinkedProductBatch( $paged );

			if ( empty( $productIds ) ) {
				break;
			}

			foreach ( $productIds as $productId ) {
				$this->syncOneProduct( (int) $productId, $threshold );
			}

			++$paged;
		} while ( count( $productIds ) === self::BATCH_SIZE );
	}

	/**
	 * Resolve + clamp the low-stock threshold setting.
	 *
	 * Defensive coercion mirrors {@see StockCache::resolveTtlSeconds()};
	 * a non-numeric option payload falls back to the architecture default.
	 */
	private function resolveThreshold(): int
	{
		$raw = get_option( self::OPTION_THRESHOLD, self::THRESHOLD_DEFAULT );

		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw && ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}

		return self::THRESHOLD_DEFAULT;
	}

	/**
	 * Fetch one batch of linked WC product IDs.
	 *
	 * Uses `wc_get_products` with a `meta_query` that asserts the article-id
	 * anchor exists AND the sync-state is not the `removed_in_sc` sentinel.
	 * The `NOT EXISTS` branch covers the (common) case where a product was
	 * synced before slice-25 was wired and therefore has no sync-state meta
	 * at all.
	 *
	 * @return int[] Product-IDs in the page; empty when the iteration is done.
	 */
	private function fetchLinkedProductBatch( int $paged ): array
	{
		$result = wc_get_products(
			array(
				'limit'      => self::BATCH_SIZE,
				'page'       => $paged,
				'status'     => array( 'publish', 'draft', 'private' ),
				'return'     => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_ARTICLE_ID,
						'compare' => 'EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_SYNC_STATE,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_SYNC_STATE,
							'value'   => self::SYNC_STATE_REMOVED,
							'compare' => '!=',
						),
					),
				),
			)
		);

		if ( ! is_array( $result ) ) {
			return array();
		}

		// Coerce to a flat int[] regardless of the WC return shape (some WC
		// versions wrap the IDs in a paginated object when `paginate=true`).
		$ids = array();
		foreach ( $result as $entry ) {
			$ids[] = (int) $entry;
		}

		return $ids;
	}

	/**
	 * Run the stock-sync sequence for one WC product.
	 *
	 * Cache-write happens for EVERY entry (AC-6 explicit: "Cache `sc_stock_{sku}`
	 * wird fuer alle drei SKUs geschrieben"); the WC mutation is gated on
	 * the threshold comparison.
	 */
	private function syncOneProduct( int $productId, int $threshold ): void
	{
		$skuMap = $this->collectVariationIdsBySku( $productId );

		if ( empty( $skuMap ) ) {
			return;
		}

		$skus = array_keys( $skuMap );

		// AC-7: ONE bulk call per product. Per-SKU `getStockBySku()` loops
		// are forbidden in hot paths (architecture.md Z. 716 / Z. 797).
		// FIXME(post-MVP): per-SKU fallback bei 4xx auf Bulk-Endpoint
		// (architecture.md Z. 121 "Optional fallback only").
		//
		// AC-10: both 4xx and 5xx propagate so AS triggers the
		// 1m/5m/15m retry cascade (architecture.md Z. 554).
		$entries = $this->client->getStock( null, $skus );

		foreach ( $entries as $entry ) {
			if ( ! ( $entry instanceof StockEntry ) ) {
				continue;
			}

			// AC-6: cache-write is unconditional — every observed SKU
			// updates the live cache regardless of threshold-gating.
			$this->cache->set( $entry->sku, $entry->quantity );

			$variationId = $skuMap[ $entry->sku ] ?? null;
			if ( null === $variationId ) {
				// SC returned an SKU we did not query — defensive ignore.
				continue;
			}

			$this->maybeMutateWcStock( $variationId, $entry->quantity, $threshold );
		}
	}

	/**
	 * Walk a variable product's children, return a `[sku => variationId]`
	 * map. Variations without a SKU are filtered out — they cannot
	 * participate in the bulk-`getStock` query anyway.
	 *
	 * @return array<string, int>
	 */
	private function collectVariationIdsBySku( int $productId ): array
	{
		$map = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $map;
		}

		$product = wc_get_product( $productId );
		if ( ! ( $product instanceof WC_Product_Variable ) ) {
			return $map;
		}

		$children = $product->get_children();
		if ( ! is_array( $children ) ) {
			return $map;
		}

		foreach ( $children as $childId ) {
			$variation = wc_get_product( (int) $childId );
			if ( ! ( $variation instanceof WC_Product ) ) {
				continue;
			}

			$sku = (string) $variation->get_sku();
			if ( '' === $sku ) {
				continue;
			}

			$map[ $sku ] = (int) $variation->get_id();
		}

		return $map;
	}

	/**
	 * Apply the threshold-gated WC stock mutation to ONE variation.
	 *
	 * Slice-36 AC-6 + Constraint "WC-Stock-Mutation-Sequenz":
	 *   - `quantity < threshold` → enable manage_stock + write quantity +
	 *     `instock`/`outofstock` per quantity sign + save.
	 *   - `quantity >= threshold` → no mutation (POD-default unlimited;
	 *     architecture.md Z. 623, Discovery Z. 191).
	 */
	private function maybeMutateWcStock( int $variationId, int $quantity, int $threshold ): void
	{
		// AC-6 strict less-than: a `quantity == threshold` reading keeps WC
		// on POD-default unlimited.
		if ( $quantity >= $threshold ) {
			return;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$variation = wc_get_product( $variationId );
		if ( ! ( $variation instanceof WC_Product_Variation ) ) {
			return;
		}

		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $quantity );
		$variation->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
		$variation->save();
	}
}
