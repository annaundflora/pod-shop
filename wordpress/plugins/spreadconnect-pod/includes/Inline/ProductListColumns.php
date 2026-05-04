<?php
/**
 * WC-Product-List "Spreadconnect" columns + filter (slice-35).
 *
 * Adapter that extends the native WooCommerce product-list table at
 * `wp-admin/edit.php?post_type=product` with three additional columns
 * — SC-Linked, SC-Cost, Margin — and a "Spreadconnect" filter drop-down
 * (`All / Linked / Unlinked / Margin <20%`).
 *
 * Read-only by design: the class only consumes the postmeta written by
 * slice-22 (`_spreadconnect_article_id`, `_spreadconnect_cost`,
 * `_spreadconnect_cost_currency`) and the WC price fetched via
 * `wc_get_product()`. No mutations, no API calls, no raw `$wpdb`
 * (filtering is implemented entirely through `WP_Query`/`meta_query`).
 *
 * Margin colour-coding uses the exact CSS classes established by
 * slice-34's meta-box (`sc-margin-low` / `-mid` / `-high` / `-unknown`)
 * with the same thresholds (`<20%` / `20-40%` / `>40%`).
 *
 * Mount-point: {@see Bootstrap\Plugin::init()} registers
 *   - `manage_edit-product_columns`           -> {@see self::registerColumnsStatic}
 *   - `manage_product_posts_custom_column`    -> {@see self::renderColumnStatic}
 *   - `manage_edit-product_sortable_columns`  -> {@see self::registerSortableColumnsStatic}
 *   - `pre_get_posts`                         -> {@see self::preGetPostsStatic}
 *   - `restrict_manage_posts`                 -> {@see self::renderFilterDropdownStatic}
 *
 * Architecture references:
 *   - architecture.md "Service Map" (Z. 396) — Inline\ProductListColumns adapter.
 *   - architecture.md "WC-Product Meta" (Z. 287-291) — postmeta keys consumed.
 *   - wireframes.md Screen 10 — column layout, filter-dropdown options, sort states.
 *   - slice-34-product-meta-box-margin-stock.md — CSS-class contract reused 1:1.
 *
 * @package SpreadconnectPod\Inline
 */

declare(strict_types=1);

namespace SpreadconnectPod\Inline;

use WC_Product;
use WP_Query;

/**
 * Stateless WC-Product-List adapter.
 *
 * `final` per slice-35 Constraints. All public entry-points are static so
 * they can be wired directly as WP-hook callables (the orchestrator
 * dispatches the hooks via the `*Static` bridges referenced in AC-11).
 */
final class ProductListColumns
{
	/**
	 * Postmeta keys consumed by this slice (mirrors slice-22 mapper output).
	 */
	private const META_ARTICLE_ID    = '_spreadconnect_article_id';
	private const META_COST          = '_spreadconnect_cost';
	private const META_COST_CURRENCY = '_spreadconnect_cost_currency';

	/**
	 * Column slugs (also used as `orderby` query-var values for sort).
	 */
	public const COLUMN_LINKED = 'sc_linked';
	public const COLUMN_COST   = 'sc_cost';
	public const COLUMN_MARGIN = 'sc_margin';

	/**
	 * Filter query-var name + whitelisted values.
	 */
	public const FILTER_QUERY_VAR    = 'sc_filter';
	public const FILTER_LINKED       = 'linked';
	public const FILTER_UNLINKED     = 'unlinked';
	public const FILTER_LOW_MARGIN   = 'low_margin';

	/**
	 * Allowed `sc_filter` values (defensive whitelist — anything else is NO-OP).
	 *
	 * @var string[]
	 */
	private const ALLOWED_FILTERS = array(
		'',
		self::FILTER_LINKED,
		self::FILTER_UNLINKED,
		self::FILTER_LOW_MARGIN,
	);

	/**
	 * Margin thresholds (mirror of slice-34 AC-5 / Constraints).
	 *
	 * `< LOW_MAX` -> sc-margin-low
	 * `LOW_MAX <= margin <= MID_MAX` -> sc-margin-mid
	 * `> MID_MAX` -> sc-margin-high
	 */
	private const MARGIN_LOW_MAX = 20.0;
	private const MARGIN_MID_MAX = 40.0;

	/**
	 * Capability gate for sort/filter mutations on `pre_get_posts`.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	// ------------------------------------------------------------------
	// Static bridges — wired from `Bootstrap\Plugin::init()` per AC-11.
	// ------------------------------------------------------------------

	/**
	 * Bridge for `manage_edit-product_columns`.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public static function registerColumnsStatic( $columns ): array
	{
		return self::registerColumns( is_array( $columns ) ? $columns : array() );
	}

	/**
	 * Bridge for `manage_product_posts_custom_column`.
	 */
	public static function renderColumnStatic( $column, $postId ): void
	{
		self::renderColumn( is_string( $column ) ? $column : '', (int) $postId );
	}

	/**
	 * Bridge for `manage_edit-product_sortable_columns`.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public static function registerSortableColumnsStatic( $columns ): array
	{
		return self::registerSortableColumns( is_array( $columns ) ? $columns : array() );
	}

	/**
	 * Bridge for `pre_get_posts`.
	 *
	 * Handles both AC-7 sorting and AC-9/10 filtering in a single closure.
	 */
	public static function preGetPostsStatic( $query ): void
	{
		if ( ! ( $query instanceof WP_Query ) ) {
			return;
		}
		self::preGetPosts( $query );
	}

	/**
	 * Bridge for `restrict_manage_posts`.
	 */
	public static function renderFilterDropdownStatic( $postType ): void
	{
		self::renderFilterDropdown( is_string( $postType ) ? $postType : '' );
	}

	// ------------------------------------------------------------------
	// AC-1: Column registration
	// ------------------------------------------------------------------

	/**
	 * Insert the three SC-columns into the WC-product list.
	 *
	 * Order: directly after the `price` column. If the WC `price` column is
	 * missing for whatever reason (custom plugin removed it), the columns
	 * are inserted before `categories` instead. As a last resort they are
	 * appended at the end.
	 *
	 * @param array<string, string> $columns Existing column-name => header map.
	 *
	 * @return array<string, string> New map with three additional columns.
	 */
	public static function registerColumns( array $columns ): array
	{
		$additions = array(
			self::COLUMN_LINKED => __( 'SC-Linked', self::TEXT_DOMAIN ),
			self::COLUMN_COST   => __( 'SC-Cost', self::TEXT_DOMAIN ),
			self::COLUMN_MARGIN => __( 'Margin', self::TEXT_DOMAIN ),
		);

		// Determine the anchor key: prefer 'price', fallback to 'categories'.
		// We insert *after* 'price' (AC-1) or *before* 'categories'.
		$anchorAfter  = array_key_exists( 'price', $columns ) ? 'price' : null;
		$anchorBefore = ( null === $anchorAfter && array_key_exists( 'categories', $columns ) )
			? 'categories'
			: null;

		// Last-resort fallback: append at the end. This preserves all
		// existing columns and never throws.
		if ( null === $anchorAfter && null === $anchorBefore ) {
			return array_merge( $columns, $additions );
		}

		$merged = array();
		foreach ( $columns as $key => $label ) {
			if ( null !== $anchorBefore && $key === $anchorBefore ) {
				foreach ( $additions as $addKey => $addLabel ) {
					$merged[ $addKey ] = $addLabel;
				}
			}

			$merged[ $key ] = $label;

			if ( null !== $anchorAfter && $key === $anchorAfter ) {
				foreach ( $additions as $addKey => $addLabel ) {
					$merged[ $addKey ] = $addLabel;
				}
			}
		}

		return $merged;
	}

	// ------------------------------------------------------------------
	// AC-2/3/4/5: Per-row column rendering
	// ------------------------------------------------------------------

	/**
	 * Echo the cell HTML for one row + one of our three columns.
	 *
	 * Dispatches on `$column` and is a no-op for any column slug that does
	 * not belong to this slice (other plugins may share the same hook).
	 */
	public static function renderColumn( string $column, int $postId ): void
	{
		switch ( $column ) {
			case self::COLUMN_LINKED:
				self::renderLinkedCell( $postId );
				return;

			case self::COLUMN_COST:
				self::renderCostCell( $postId );
				return;

			case self::COLUMN_MARGIN:
				self::renderMarginCell( $postId );
				return;
		}
	}

	/**
	 * AC-2: Render the SC-Linked column cell.
	 */
	private static function renderLinkedCell( int $postId ): void
	{
		$articleId = self::readMetaString( $postId, self::META_ARTICLE_ID );

		if ( '' !== $articleId ) {
			echo '<span class="sc-linked-yes" aria-label="' . esc_attr__( 'linked', self::TEXT_DOMAIN ) . '">'
				. esc_html( '✓' ) . '</span>';
			return;
		}

		echo '<span class="sc-linked-no" aria-label="' . esc_attr__( 'unlinked', self::TEXT_DOMAIN ) . '">'
			. esc_html( '—' ) . '</span>';
	}

	/**
	 * AC-3: Render the SC-Cost column cell.
	 *
	 * `_spreadconnect_cost == '0'` is a valid cost (free product) and is
	 * rendered as `wc_price(0)` — NOT a dash. Only fully-missing meta
	 * (cache-miss / unlinked) renders the dash.
	 */
	private static function renderCostCell( int $postId ): void
	{
		$costRaw = get_post_meta( $postId, self::META_COST, true );

		// Truly missing (false / null / '') -> dash. '0' is a valid cost.
		if ( ! is_string( $costRaw ) && ! is_numeric( $costRaw ) ) {
			echo esc_html( '—' );
			return;
		}

		$costString = is_string( $costRaw ) ? $costRaw : (string) $costRaw;
		if ( '' === $costString ) {
			echo esc_html( '—' );
			return;
		}

		$currency = self::readMetaString( $postId, self::META_COST_CURRENCY );
		$args     = ( '' !== $currency ) ? array( 'currency' => $currency ) : array();

		// `wc_price()` returns escape-safe HTML (a span with the formatted
		// amount). Trust WC core and emit unescaped (slice-35 Constraints).
		// Cast to float so `wc_price('0')` produces `wc_price(0.0)` — the
		// helper accepts numeric strings too, but float keeps the contract
		// explicit.
		echo wc_price( (float) $costString, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * AC-4 / AC-5: Render the Margin column cell.
	 *
	 * Server-side margin computation using the postmeta cost (numeric
	 * string) and the WC product price (`get_price()` — for variable
	 * products this is the min variation price). Falls through to the
	 * `sc-margin-unknown` dash for any of: missing article-id, missing
	 * cost, non-positive WC price.
	 */
	private static function renderMarginCell( int $postId ): void
	{
		$articleId = self::readMetaString( $postId, self::META_ARTICLE_ID );
		$costRaw   = get_post_meta( $postId, self::META_COST, true );

		$costNumeric = is_numeric( $costRaw ) ? (float) $costRaw : null;
		$priceFloat  = self::resolveProductPrice( $postId );

		if ( '' === $articleId || null === $costNumeric || $priceFloat === null || $priceFloat <= 0.0 ) {
			echo '<span class="sc-margin-unknown">' . esc_html( '—' ) . '</span>';
			return;
		}

		$marginEur = $priceFloat - $costNumeric;
		$marginPct = ( $marginEur / $priceFloat ) * 100.0;

		$class = self::marginClass( $marginPct );

		// Format: "17.56 € (58.7%) ●" — one decimal for both numbers.
		// Currency symbol is hard-coded `€` per wireframes.md Screen 10
		// because the SC-Cost currency is always EUR for this shop and
		// the column value should mirror the meta-box's display.
		$formatted = sprintf(
			'%.2f €&nbsp;(%.1f%%)&nbsp;●',
			$marginEur,
			$marginPct
		);

		echo '<span class="' . esc_attr( $class ) . '">' . wp_kses_post( $formatted ) . '</span>';
	}

	/**
	 * Map a margin percentage to one of the three colour classes.
	 *
	 * Threshold contract (slice-34 AC-5 / Constraints):
	 *   - `< 20.0%`   -> `sc-margin-low`   (red)
	 *   - `20.0-40.0` -> `sc-margin-mid`   (yellow)
	 *   - `> 40.0%`   -> `sc-margin-high`  (green)
	 *
	 * Boundary cases follow the same convention as slice-34: exactly
	 * `20.0` is "mid", exactly `40.0` is "mid", `>40.0` is "high".
	 */
	private static function marginClass( float $marginPct ): string
	{
		if ( $marginPct < self::MARGIN_LOW_MAX ) {
			return 'sc-margin-low';
		}

		if ( $marginPct > self::MARGIN_MID_MAX ) {
			return 'sc-margin-high';
		}

		return 'sc-margin-mid';
	}

	/**
	 * Resolve the WC price for the margin computation.
	 *
	 * AC-5: For variable products without a parent-level price,
	 * `WC_Product::get_price()` returns the min variation price; we use
	 * that directly. If `get_price()` returns an empty string or a
	 * non-numeric value, the caller falls back to the
	 * `sc-margin-unknown` path.
	 *
	 * @return float|null  numeric price, or null when WC has no price.
	 */
	private static function resolveProductPrice( int $postId ): ?float
	{
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $postId );
		if ( ! ( $product instanceof WC_Product ) ) {
			return null;
		}

		$price = $product->get_price();
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return null;
		}

		return (float) $price;
	}

	// ------------------------------------------------------------------
	// AC-6: Sortable columns
	// ------------------------------------------------------------------

	/**
	 * Mark the three SC-columns sortable. The `orderby` query-var values
	 * are identical to the column slugs — `pre_get_posts` then maps them
	 * to the appropriate `meta_key` / `orderby='meta_value*'` pair.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public static function registerSortableColumns( array $columns ): array
	{
		$columns[ self::COLUMN_LINKED ] = self::COLUMN_LINKED;
		$columns[ self::COLUMN_COST ]   = self::COLUMN_COST;
		$columns[ self::COLUMN_MARGIN ] = self::COLUMN_MARGIN;

		return $columns;
	}

	// ------------------------------------------------------------------
	// AC-7 / AC-9 / AC-10: pre_get_posts (sort + filter)
	// ------------------------------------------------------------------

	/**
	 * Combined `pre_get_posts` handler — applies sort (AC-7) + filter
	 * (AC-9/10) for the WC-product main admin query.
	 *
	 * Guards (Constraints):
	 *   1. `is_admin()` — front-end queries are untouched.
	 *   2. `$query->is_main_query()` — sub-queries / widgets ignored.
	 *   3. `$query->get('post_type') === 'product'` — orders / posts ignored.
	 *   4. `current_user_can('manage_woocommerce')` — non-admins cannot
	 *       trigger the meta_query joins (defence-in-depth — admin pages
	 *       are already cap-gated, but `pre_get_posts` is global).
	 */
	public static function preGetPosts( WP_Query $query ): void
	{
		if ( ! is_admin() ) {
			return;
		}

		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		self::applySorting( $query );
		self::applyFilter( $query );
	}

	/**
	 * AC-7: Translate the SC `orderby` query-var to a `meta_key` +
	 * `orderby='meta_value*'` pair so WP can sort on postmeta.
	 *
	 * - `sc_cost`   -> `_spreadconnect_cost`,        `meta_value_num`
	 * - `sc_margin` -> `_spreadconnect_cost`,        `meta_value_num`
	 *                  (server-side approximation: cost-DESC ≈ margin-ASC
	 *                  for a fixed price; a real computed margin sort is
	 *                  out of scope per slim-slices.md.)
	 * - `sc_linked` -> `_spreadconnect_article_id`,  `meta_value`
	 */
	public static function applySorting( WP_Query $query ): void
	{
		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) || '' === $orderby ) {
			return;
		}

		switch ( $orderby ) {
			case self::COLUMN_COST:
			case self::COLUMN_MARGIN:
				$query->set( 'meta_key', self::META_COST );
				$query->set( 'orderby', 'meta_value_num' );
				return;

			case self::COLUMN_LINKED:
				$query->set( 'meta_key', self::META_ARTICLE_ID );
				$query->set( 'orderby', 'meta_value' );
				return;
		}
	}

	/**
	 * AC-9/10: Translate the `?sc_filter=…` query-var to a `meta_query`
	 * clause merged into the existing query.
	 *
	 * The whitelist is defensive — any value outside
	 * {@see self::ALLOWED_FILTERS} is treated as no-op. Existing
	 * `meta_query` entries are preserved via `array_merge` with
	 * `relation=AND`, so other plugins / built-in WC filters continue to
	 * work alongside this one.
	 */
	public static function applyFilter( WP_Query $query ): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no mutation.
		$filterRaw = isset( $_GET[ self::FILTER_QUERY_VAR ] ) ? $_GET[ self::FILTER_QUERY_VAR ] : '';
		$filterRaw = is_string( $filterRaw ) ? $filterRaw : '';
		$filter    = sanitize_key( $filterRaw );

		if ( ! in_array( $filter, self::ALLOWED_FILTERS, true ) ) {
			return;
		}

		if ( '' === $filter ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$clauses = self::buildFilterMetaClauses( $filter );
		if ( array() === $clauses ) {
			return;
		}

		// Merge with existing meta_query, ensuring `relation=AND`. We
		// preserve any pre-existing top-level `relation` key (other plugin
		// may have set OR there); the AC requires AND when WE add clauses.
		if ( array() === $existing ) {
			$merged = array_merge( array( 'relation' => 'AND' ), $clauses );
		} else {
			// Strip any existing relation key (it could be 'OR') and re-set
			// to 'AND' to satisfy the AC contract.
			$existingClauses = array_filter(
				$existing,
				static function ( $key ): bool {
					return 'relation' !== $key;
				},
				ARRAY_FILTER_USE_KEY
			);

			$merged = array_merge(
				array( 'relation' => 'AND' ),
				array_values( $existingClauses ),
				$clauses
			);
		}

		$query->set( 'meta_query', $merged );

		if ( self::FILTER_LOW_MARGIN === $filter ) {
			self::registerLowMarginPostsFilter( $query );
		}
	}

	/**
	 * Build the `meta_query` clause-set for a given whitelisted filter.
	 *
	 * Returns a list of clauses (without a leading `relation` key) so
	 * {@see self::applyFilter} can splice them into any existing
	 * `meta_query` and force `relation=AND`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function buildFilterMetaClauses( string $filter ): array
	{
		switch ( $filter ) {
			case self::FILTER_LINKED:
				return array(
					array(
						'key'     => self::META_ARTICLE_ID,
						'compare' => 'EXISTS',
					),
				);

			case self::FILTER_UNLINKED:
				return array(
					array(
						'key'     => self::META_ARTICLE_ID,
						'compare' => 'NOT EXISTS',
					),
				);

			case self::FILTER_LOW_MARGIN:
				// Two clauses: cost+article-id must both EXIST. The actual
				// `<20%` filtering happens in the `the_posts` post-filter
				// registered by {@see self::registerLowMarginPostsFilter}.
				return array(
					array(
						'key'     => self::META_COST,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_ARTICLE_ID,
						'compare' => 'EXISTS',
					),
				);
		}

		return array();
	}

	/**
	 * AC-10: Register a one-shot `the_posts` filter that drops every
	 * product whose computed margin is `>= 20%`. This is the "raw-SQL-free"
	 * Implementer-Choice (b) from the AC — slower than a `posts_clauses`
	 * SQL join but contained inside `WP_Query`'s public surface and easy
	 * to test with Brain\Monkey.
	 *
	 * The filter is bound to the *specific* `$query` instance via an
	 * identity check so other concurrent queries (sub-queries, widget
	 * calls during the same request) are unaffected.
	 */
	private static function registerLowMarginPostsFilter( WP_Query $query ): void
	{
		add_filter(
			'the_posts',
			static function ( $posts, $thePostsQuery ) use ( $query ) {
				if ( $thePostsQuery !== $query ) {
					return $posts;
				}

				if ( ! is_array( $posts ) ) {
					return $posts;
				}

				return array_values(
					array_filter(
						$posts,
						static function ( $post ): bool {
							if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
								return false;
							}

							return self::productHasLowMargin( (int) $post->ID );
						}
					)
				);
			},
			10,
			2
		);
	}

	/**
	 * True when a product has a defined cost+price AND the resulting
	 * margin is `< 20.0%`. Products without cost or without a positive
	 * WC price are excluded (no division-by-zero, no NULL-margin in the
	 * filtered set — slim-slices.md done-signal).
	 */
	private static function productHasLowMargin( int $postId ): bool
	{
		$articleId = self::readMetaString( $postId, self::META_ARTICLE_ID );
		if ( '' === $articleId ) {
			return false;
		}

		$costRaw = get_post_meta( $postId, self::META_COST, true );
		if ( ! is_numeric( $costRaw ) ) {
			return false;
		}
		$cost = (float) $costRaw;

		$price = self::resolveProductPrice( $postId );
		if ( null === $price || $price <= 0.0 ) {
			return false;
		}

		$marginPct = ( ( $price - $cost ) / $price ) * 100.0;

		return $marginPct < self::MARGIN_LOW_MAX;
	}

	// ------------------------------------------------------------------
	// AC-8: Filter dropdown
	// ------------------------------------------------------------------

	/**
	 * Render the `<select name="sc_filter">` drop-down on the WC-product
	 * list screen. NO-OP on every other post-type screen so the same hook
	 * registration cannot leak into `shop_order` or unrelated CPTs.
	 */
	public static function renderFilterDropdown( string $postType ): void
	{
		if ( 'product' !== $postType ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI, no mutation.
		$currentRaw = isset( $_GET[ self::FILTER_QUERY_VAR ] ) ? $_GET[ self::FILTER_QUERY_VAR ] : '';
		$currentRaw = is_string( $currentRaw ) ? $currentRaw : '';
		$current    = sanitize_key( $currentRaw );
		if ( ! in_array( $current, self::ALLOWED_FILTERS, true ) ) {
			$current = '';
		}

		$options = array(
			''                       => __( 'Spreadconnect: All', self::TEXT_DOMAIN ),
			self::FILTER_LINKED      => __( 'Linked', self::TEXT_DOMAIN ),
			self::FILTER_UNLINKED    => __( 'Unlinked', self::TEXT_DOMAIN ),
			self::FILTER_LOW_MARGIN  => __( 'Margin <20%', self::TEXT_DOMAIN ),
		);

		echo '<select name="' . esc_attr( self::FILTER_QUERY_VAR ) . '" class="sc-filter-dropdown">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"';
			echo selected( $current, $value, false );
			echo '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Read a postmeta value as a non-empty string, fallback `''`.
	 */
	private static function readMetaString( int $postId, string $key ): string
	{
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
