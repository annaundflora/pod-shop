<?php
/**
 * Article + ProductType + Previews -> WC_Product_Variable / WC_Product_Variation upsert.
 *
 * Domain service that takes a fully-validated `ArticleDetail` DTO, the
 * matching `ProductTypeDetail` size/color label tables, and a list of
 * already-sideloaded image attachment-IDs, and reconciles them against
 * the WooCommerce product/variation tree:
 *
 *   * Reverse-lookup an existing WC-Variable-Product by
 *     `_spreadconnect_article_id` postmeta (idempotency anchor).
 *   * Create-or-update the parent product (title, description, attributes,
 *     tracking-meta).
 *   * Create-or-update one variation per Variant (size/color combination,
 *     SKU = SC-Variant-SKU).
 *   * Soft-archive obsolete variations (status `private`); never delete.
 *   * Featured + gallery images set ONLY on first creation; re-sync
 *     never re-touches images (caller controls re-pull semantics).
 *
 * Hard contracts (per Slice 22 spec):
 *   * `_regular_price` / `_sale_price` / `_price` are NEVER written.
 *   * Reverse-lookup uses `get_posts()` with `meta_query` (never `$wpdb`).
 *   * Empty `Variant[]` -> {@see ProductMapperException} (fail-fast).
 *   * Caller (Slice 23 SyncArticleJob) is responsible for sideloading
 *     images, invoking `AttributeProvisioner::ensure()`, writing the
 *     `sync_history` row, and mapping exceptions to error-status.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use SpreadconnectPod\Api\Dto\ArticleDetail;
use SpreadconnectPod\Api\Dto\ProductTypeColor;
use SpreadconnectPod\Api\Dto\ProductTypeDetail;
use SpreadconnectPod\Api\Dto\ProductTypeSize;
use SpreadconnectPod\Api\Dto\Variant;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Domain service: reconcile a Spreadconnect article with WooCommerce.
 *
 * Stateless, final, no DI — instantiated per `upsert()` call by the
 * `Catalog\SyncArticleJob` (Slice 23).
 */
final class ProductMapper
{
	/**
	 * Postmeta keys (parent product). See `architecture.md` -> "WC-Product Meta".
	 */
	private const META_ARTICLE_ID       = '_spreadconnect_article_id';
	private const META_PRODUCT_TYPE_ID  = '_spreadconnect_product_type_id';
	private const META_COST             = '_spreadconnect_cost';
	private const META_COST_CURRENCY    = '_spreadconnect_cost_currency';
	private const META_LAST_SYNC        = '_spreadconnect_last_sync';
	private const META_SYNC_STATE       = '_spreadconnect_sync_state';

	/**
	 * Postmeta keys (variation). See `architecture.md` -> "WC-Variation Meta".
	 */
	private const META_VARIATION_SKU      = '_spreadconnect_sku';
	private const META_VARIATION_SIZE_ID  = '_spreadconnect_size_id';
	private const META_VARIATION_COLOR_ID = '_spreadconnect_color_id';

	/**
	 * Sync-state values. Fixed enum per architecture; mapper only ever
	 * writes `synced` — `partial`/`error`/`removed_in_sc` are written by
	 * the caller (Slice 23 / 25).
	 */
	private const SYNC_STATE_SYNCED = 'synced';

	/**
	 * WC attribute taxonomy names. Hard-coded German slugs per Slice 20
	 * (`AttributeProvisioner::TAXONOMIES`) and frontend `variant-utils.ts`.
	 */
	private const TAX_SIZE  = 'pa_groesse';
	private const TAX_COLOR = 'pa_farbe';

	/**
	 * Idempotently upsert a Spreadconnect article into WooCommerce.
	 *
	 * Sequence:
	 *   1. Validate non-empty Variants (else -> {@see ProductMapperException}).
	 *   2. Reverse-lookup parent via `_spreadconnect_article_id` postmeta.
	 *   3. Build size/color label maps from the ProductTypeDetail and
	 *      ensure the corresponding WC-terms exist (lazy create via
	 *      `wp_insert_term()` when missing).
	 *   4. Create/update the `WC_Product_Variable` parent (name,
	 *      description, attributes; never `_regular_price`).
	 *   5. Create/update one `WC_Product_Variation` per Variant (matched by
	 *      SKU); archive any leftover variations whose SKU is no longer in
	 *      the article (`status='private'`, never delete).
	 *   6. Write tracking-meta (article-id, product-type-id, cost, last-sync).
	 *   7. On *creation*, wire `set_image_id($attachmentIds[0])` +
	 *      `set_gallery_image_ids(array_slice($attachmentIds, 1))`.
	 *      On *update*, images are NEVER re-written (caller controls
	 *      re-pull semantics via the `spreadconnect_force_repull_images`
	 *      option, which is interpreted in Slice 23, not here).
	 *
	 * @param ArticleDetail     $article       Fully-validated article DTO from `getArticle()`.
	 * @param ProductTypeDetail $productType   Size/color labels from `getProductType()` (slice-23 instantiates).
	 * @param int[]             $attachmentIds Pre-resolved WP attachment-IDs from `ImageSideloader`. Empty list = do not touch images.
	 *
	 * @return int WC-Product ID of the parent variable product (always > 0 on success).
	 *
	 * @throws ProductMapperException When `$article->variants` is empty or
	 *                                a WC-API call fails irrecoverably.
	 */
	public function upsert(
		ArticleDetail $article,
		ProductTypeDetail $productType,
		array $attachmentIds = []
	): int {
		if ( empty( $article->variants ) ) {
			throw new ProductMapperException(
				'ArticleDetail has no variants — refusing to create empty Variable-Product'
			);
		}

		$existingProductId = $this->findProductIdByArticleId( $article->id );
		$isNew             = ( null === $existingProductId );

		// Build size/color label maps once — used for term-resolution +
		// attribute-options on the parent + per-variation set_attributes().
		$sizeLabels  = $this->buildLabelMap( $productType->sizes );
		$colorLabels = $this->buildLabelMap( $productType->colors );

		// Resolve (or lazily create) WC-terms for every size/color label
		// referenced by any Variant — captures slug for set_attributes().
		[ $sizeSlugById, $sizeLabelSlugs ]   = $this->ensureTerms(
			self::TAX_SIZE,
			$sizeLabels,
			$this->collectSizeIds( $article->variants )
		);
		[ $colorSlugById, $colorLabelSlugs ] = $this->ensureTerms(
			self::TAX_COLOR,
			$colorLabels,
			$this->collectColorIds( $article->variants )
		);

		// ----- Parent product ------------------------------------------------
		if ( $isNew ) {
			$parent = new WC_Product_Variable();
			$parent->set_status( 'publish' );
		} else {
			$loaded = wc_get_product( $existingProductId );
			$parent = ( $loaded instanceof WC_Product_Variable )
				? $loaded
				: new WC_Product_Variable( $existingProductId );
		}

		$parent->set_name( $article->title );

		if ( null !== $article->description ) {
			$parent->set_description( $article->description );
		}

		$parent->set_attributes(
			$this->buildParentAttributes( $sizeLabelSlugs, $colorLabelSlugs )
		);

		// Featured + gallery images — only on first creation. Re-sync
		// never re-calls set_image_id / set_gallery_image_ids (Slice 22
		// AC-5): the `spreadconnect_force_repull_images` toggle is
		// honoured upstream by Slice 23, which orchestrates re-pull
		// semantics directly. An empty `$attachmentIds` list always
		// means "do not touch images".
		if ( $isNew && ! empty( $attachmentIds ) ) {
			$ids      = array_values( array_filter( array_map( 'intval', $attachmentIds ) ) );
			$featured = array_shift( $ids );
			if ( null !== $featured && $featured > 0 ) {
				$parent->set_image_id( $featured );
			}
			$parent->set_gallery_image_ids( $ids );
		}

		$parentId = (int) $parent->save();

		if ( $parentId <= 0 ) {
			throw new ProductMapperException(
				sprintf( 'WC_Product_Variable::save() returned non-positive id for article "%s".', $article->id )
			);
		}

		// ----- Tracking-meta on parent --------------------------------------
		update_post_meta( $parentId, self::META_ARTICLE_ID, $article->id );
		update_post_meta( $parentId, self::META_PRODUCT_TYPE_ID, $article->productTypeId );

		$cost = $this->resolveCostFromVariants( $article->variants );
		if ( null !== $cost ) {
			update_post_meta( $parentId, self::META_COST, $cost['amount'] );
			update_post_meta( $parentId, self::META_COST_CURRENCY, $cost['currency'] );
		}

		update_post_meta( $parentId, self::META_LAST_SYNC, time() );
		update_post_meta( $parentId, self::META_SYNC_STATE, self::SYNC_STATE_SYNCED );

		// ----- Variations ----------------------------------------------------
		$existingVariations = $this->getExistingVariations( $parentId );
		$seenSkus           = array();

		foreach ( $article->variants as $variant ) {
			$variation = $this->upsertVariation(
				$parentId,
				$variant,
				$sizeSlugById,
				$colorSlugById,
				$existingVariations
			);

			if ( null !== $variation ) {
				$seenSkus[] = $variant->sku;
			}
		}

		// Soft-archive variations whose SKU is no longer present in the
		// new article state (AC-8). Never delete — order-item history
		// references variation IDs.
		foreach ( $existingVariations as $sku => $variationId ) {
			if ( in_array( $sku, $seenSkus, true ) ) {
				continue;
			}
			$loaded   = wc_get_product( (int) $variationId );
			$archived = ( $loaded instanceof WC_Product_Variation )
				? $loaded
				: new WC_Product_Variation( (int) $variationId );
			$archived->set_status( 'private' );
			$archived->save();
		}

		return $parentId;
	}

	/**
	 * Reverse-lookup a WC-Product by `_spreadconnect_article_id` meta.
	 *
	 * Uses `get_posts()` with `meta_query` per Constraints (no raw `$wpdb`).
	 * `post_status` includes `private`/`draft` so a previously-archived
	 * product (Article-Removed Webhook in Slice 25) is not duplicated by
	 * a subsequent re-sync.
	 *
	 * @return int|null Product-ID if exactly one match exists, else null.
	 */
	private function findProductIdByArticleId( string $articleId ): ?int
	{
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'key'     => self::META_ARTICLE_ID,
						'value'   => $articleId,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return null;
		}

		$first = (int) $posts[0];

		return $first > 0 ? $first : null;
	}

	/**
	 * Build a `[id => label]` map from a list of size/color value objects.
	 *
	 * @param ProductTypeSize[]|ProductTypeColor[] $items
	 *
	 * @return array<string, string>
	 */
	private function buildLabelMap( array $items ): array
	{
		$map = array();
		foreach ( $items as $item ) {
			$map[ $item->id ] = $item->label;
		}

		return $map;
	}

	/**
	 * Collect every distinct size-id referenced by any Variant.
	 *
	 * @param Variant[] $variants
	 *
	 * @return string[]
	 */
	private function collectSizeIds( array $variants ): array
	{
		$ids = array();
		foreach ( $variants as $variant ) {
			$ids[ $variant->sizeId ] = true;
		}

		return array_keys( $ids );
	}

	/**
	 * Collect every distinct color-id referenced by any Variant.
	 *
	 * @param Variant[] $variants
	 *
	 * @return string[]
	 */
	private function collectColorIds( array $variants ): array
	{
		$ids = array();
		foreach ( $variants as $variant ) {
			$ids[ $variant->colorId ] = true;
		}

		return array_keys( $ids );
	}

	/**
	 * Ensure WC-terms exist for every referenced label and return two maps.
	 *
	 * Term-slug = sanitized lowercase of the label. `term_exists()` is
	 * checked first; only missing terms are inserted via `wp_insert_term()`.
	 *
	 * @param string                $taxonomy        `pa_groesse` or `pa_farbe`.
	 * @param array<string, string> $labelMap        DTO `id` -> human label.
	 * @param string[]              $referencedIds   Subset of DTO IDs actually used by any variant.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>}
	 *               First map: DTO `id` -> term-slug (used by variations).
	 *               Second map: term-slug -> term-slug (parent-attribute options; map shape kept symmetric for clarity).
	 */
	private function ensureTerms( string $taxonomy, array $labelMap, array $referencedIds ): array
	{
		$slugById = array();
		$slugSet  = array();

		foreach ( $referencedIds as $id ) {
			if ( ! isset( $labelMap[ $id ] ) ) {
				// Variant references an unknown size/color — skip term
				// creation; the corresponding variation will simply omit
				// this attribute axis (consistent with empty-axis policy).
				continue;
			}

			$label = $labelMap[ $id ];
			$slug  = $this->slugifyLabel( $label );

			$existing = term_exists( $slug, $taxonomy );
			if ( ! $existing ) {
				wp_insert_term( $label, $taxonomy, array( 'slug' => $slug ) );
			}

			$slugById[ $id ] = $slug;
			$slugSet[ $slug ] = $slug;
		}

		return array( $slugById, $slugSet );
	}

	/**
	 * Build the two `WC_Product_Attribute` objects (size + color) for the
	 * parent variable product.
	 *
	 * Both flagged `variation=true`, `visible=true`. `set_options()` takes
	 * the term-slug list — WC resolves them to term-IDs via the taxonomy.
	 *
	 * @param array<string, string> $sizeSlugs  Set of size term-slugs (slug => slug).
	 * @param array<string, string> $colorSlugs Set of color term-slugs (slug => slug).
	 *
	 * @return WC_Product_Attribute[]
	 */
	private function buildParentAttributes( array $sizeSlugs, array $colorSlugs ): array
	{
		$attributes = array();

		if ( ! empty( $sizeSlugs ) ) {
			$sizeAttr = new WC_Product_Attribute();
			$sizeAttr->set_name( self::TAX_SIZE );
			$sizeAttr->set_options( array_values( $sizeSlugs ) );
			$sizeAttr->set_visible( true );
			$sizeAttr->set_variation( true );
			$attributes[] = $sizeAttr;
		}

		if ( ! empty( $colorSlugs ) ) {
			$colorAttr = new WC_Product_Attribute();
			$colorAttr->set_name( self::TAX_COLOR );
			$colorAttr->set_options( array_values( $colorSlugs ) );
			$colorAttr->set_visible( true );
			$colorAttr->set_variation( true );
			$attributes[] = $colorAttr;
		}

		return $attributes;
	}

	/**
	 * Pick a representative cost from the variant list.
	 *
	 * Discovery: SC variant `priceCalculation` is per-variant, but
	 * Spreadconnect generally returns a uniform cost across an article's
	 * variants — we surface the first non-null `priceCalculation` as the
	 * parent-level `_spreadconnect_cost` for Admin margin display
	 * (Slice 32 ProductMetaBox). Variant-level differences (e.g. an XL
	 * surcharge) are visible via the variation's own `priceCalculation`
	 * if needed downstream.
	 *
	 * @param Variant[] $variants
	 *
	 * @return array{amount:string, currency:string}|null
	 */
	private function resolveCostFromVariants( array $variants ): ?array
	{
		foreach ( $variants as $variant ) {
			if ( null === $variant->priceCalculation ) {
				continue;
			}
			return array(
				'amount'   => $variant->priceCalculation->amount,
				'currency' => $variant->priceCalculation->currency,
			);
		}

		return null;
	}

	/**
	 * Index existing variations of `$parentId` by their SC-SKU.
	 *
	 * Reads from `_spreadconnect_sku` (preferred — explicit tracking meta)
	 * with a fallback to WC's own `_sku` to keep idempotency robust if a
	 * pre-Slice-22 product happens to be matched (defensive coding).
	 *
	 * @return array<string, int> SKU => variation post-ID.
	 */
	private function getExistingVariations( int $parentId ): array
	{
		$variationPosts = get_posts(
			array(
				'post_type'   => 'product_variation',
				'post_parent' => $parentId,
				'post_status' => array( 'publish', 'private', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		$bySku = array();

		if ( ! is_array( $variationPosts ) ) {
			return $bySku;
		}

		foreach ( $variationPosts as $variationId ) {
			$id = (int) $variationId;
			if ( $id <= 0 ) {
				continue;
			}

			$sku = get_post_meta( $id, self::META_VARIATION_SKU, true );
			if ( ! is_string( $sku ) || '' === $sku ) {
				$sku = get_post_meta( $id, '_sku', true );
			}

			if ( is_string( $sku ) && '' !== $sku ) {
				$bySku[ $sku ] = $id;
			}
		}

		return $bySku;
	}

	/**
	 * Create or update a single variation for one Variant DTO.
	 *
	 * Existing-variation match is performed via the `$existingVariations`
	 * SKU-map; absence triggers a fresh `WC_Product_Variation`.
	 *
	 * Importantly: this method NEVER touches `_regular_price`,
	 * `_sale_price`, or `_price` on the variation — Admin keeps full
	 * pricing control (Slice 22 AC-3).
	 *
	 * @param array<string, string> $sizeSlugById  DTO size-id   -> term-slug.
	 * @param array<string, string> $colorSlugById DTO color-id  -> term-slug.
	 * @param array<string, int>    $existingVariations Existing SKU -> variation-id map.
	 */
	private function upsertVariation(
		int $parentId,
		Variant $variant,
		array $sizeSlugById,
		array $colorSlugById,
		array $existingVariations
	): ?WC_Product_Variation {
		$existingId = $existingVariations[ $variant->sku ] ?? null;

		if ( null !== $existingId ) {
			$loaded    = wc_get_product( (int) $existingId );
			$variation = ( $loaded instanceof WC_Product_Variation )
				? $loaded
				: new WC_Product_Variation( (int) $existingId );
		} else {
			$variation = new WC_Product_Variation();
		}

		$variation->set_parent_id( $parentId );
		$variation->set_sku( $variant->sku );
		$variation->set_status( 'publish' );

		$attributes = array();
		if ( isset( $sizeSlugById[ $variant->sizeId ] ) ) {
			$attributes[ self::TAX_SIZE ] = $sizeSlugById[ $variant->sizeId ];
		}
		if ( isset( $colorSlugById[ $variant->colorId ] ) ) {
			$attributes[ self::TAX_COLOR ] = $colorSlugById[ $variant->colorId ];
		}
		$variation->set_attributes( $attributes );

		$variationId = (int) $variation->save();
		if ( $variationId <= 0 ) {
			return null;
		}

		// Variation tracking-meta (architecture.md "WC-Variation Meta").
		update_post_meta( $variationId, self::META_VARIATION_SKU, $variant->sku );
		update_post_meta( $variationId, self::META_VARIATION_SIZE_ID, $variant->sizeId );
		update_post_meta( $variationId, self::META_VARIATION_COLOR_ID, $variant->colorId );

		return $variation;
	}

	/**
	 * Sanitize a human-readable label to a WC term-slug.
	 *
	 * Examples: `'S'` -> `'s'`, `'Schwarz'` -> `'schwarz'`,
	 * `'Light Blue'` -> `'light-blue'`. Falls back to `sanitize_title()`
	 * when available (production), else a minimal in-house slugger that
	 * matches WC's behaviour for the vast majority of label shapes
	 * (test/CI compatibility).
	 */
	private function slugifyLabel( string $label ): string
	{
		if ( function_exists( 'sanitize_title' ) ) {
			$slug = sanitize_title( $label );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		$lower = strtolower( $label );
		$slug  = preg_replace( '/[^a-z0-9]+/', '-', $lower );
		$slug  = is_string( $slug ) ? trim( $slug, '-' ) : '';

		return '' === $slug ? 'unknown' : $slug;
	}
}

/**
 * Raised when the product mapper cannot produce a consistent WC-product
 * tree for a given article — either because the article carries no
 * variants (AC-7) or because a downstream WC-API call returned an
 * unrecoverable error.
 *
 * Extends `\RuntimeException` (not a new base) so Action-Scheduler retry
 * logic in Slice 23 classifies it as a permanent failure — same pattern
 * as `AttributeProvisionerException` (Slice 20) and
 * `SpreadconnectClientError` (Slice 07).
 */
final class ProductMapperException extends \RuntimeException
{
}
