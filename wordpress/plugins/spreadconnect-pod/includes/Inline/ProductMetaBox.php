<?php
/**
 * WC-Product-Edit "Spreadconnect" sidebar meta-box (slice-34).
 *
 * Renders one of three states based on the parent product's
 * `_spreadconnect_*` postmeta:
 *
 *   1. `unlinked`        — Article-Picker (search input + result list).
 *                           No cost/margin/stock blocks rendered.
 *   2. `linked`          — Cost + live-margin block (margin recomputed
 *                           client-side from `data-sc-cost` + the WC price
 *                           form input), stock table (read from
 *                           `sc_stock_{sku}` transients; cache-miss
 *                           renders `…`) and a sync-status block with
 *                           [Re-Sync now] / [Unlink] buttons.
 *   3. `removed_in_sc`   — Red banner + greyed cost/margin/stock + a
 *                           [Re-Sync (re-create)] action.
 *
 * Server-side responsibilities only:
 *   - Determine which of the three states applies (purely from postmeta).
 *   - Render the static markup including the `data-sc-cost` attribute
 *     used by the JS for live margin recalculation.
 *   - Read `sc_stock_{sku}` and `sc_stock_refresh_{productId}` transients
 *     for the stock-table render (NEVER an API call on render).
 *
 * Margin computation, debounce, and AJAX wiring all live in
 * `assets/js/product-meta-box.js`. Margin numbers are NEVER computed
 * server-side — the block only emits the cost as a data attribute.
 *
 * Mount-point: {@see Bootstrap\Plugin::init()} registers
 *   - `add_meta_boxes` -> {@see self::register}
 *   - `admin_enqueue_scripts` -> {@see self::enqueueAssets}
 *
 * Architecture references:
 *   - architecture.md "Service Map" -> `Inline\ProductMetaBox`
 *   - architecture.md "WC-Product Meta" (Z. 287-292)
 *   - architecture.md "Cache-Keys" Z. 350 (`sc_stock_{sku}` 5 min TTL)
 *   - wireframes.md Screen 9 (linked / unlinked / removed_in_sc layouts)
 *
 * @package SpreadconnectPod\Inline
 */

declare(strict_types=1);

namespace SpreadconnectPod\Inline;

use SpreadconnectPod\Hub\Ajax\ProductActions;
use WC_Product_Variable;
use WP_Post;

/**
 * Stateless renderer for the WC-Product-Edit "Spreadconnect" meta-box.
 *
 * `final` per slice-34 Constraints. All entry-points are static so the
 * class can act directly as a WP-hook callable
 * (`[ ProductMetaBox::class, 'register' ]`).
 */
final class ProductMetaBox
{
	/**
	 * WP meta-box ID (also used as the `data-sc-meta-box` selector by the JS).
	 */
	public const META_BOX_ID = 'spreadconnect_product_meta_box';

	/**
	 * Postmeta keys (mirrors {@see \SpreadconnectPod\Catalog\ProductMapper}).
	 *
	 * The mapper writes these keys; the meta-box only reads them. Any change
	 * to the mapper-side names must be reflected here verbatim.
	 */
	private const META_ARTICLE_ID    = '_spreadconnect_article_id';
	private const META_COST          = '_spreadconnect_cost';
	private const META_COST_CURRENCY = '_spreadconnect_cost_currency';
	private const META_LAST_SYNC     = '_spreadconnect_last_sync';
	private const META_SYNC_STATE    = '_spreadconnect_sync_state';

	/**
	 * Sync-state enum values (architecture.md Z. 292).
	 */
	private const SYNC_STATE_REMOVED_IN_SC = 'removed_in_sc';

	/**
	 * Cache-key templates for the live-stock cache (slice-36 wraps the same
	 * keys as a service later — see {@see ProductActions::TRANSIENT_STOCK_PREFIX}).
	 */
	private const TRANSIENT_STOCK_PREFIX         = 'sc_stock_';
	private const TRANSIENT_STOCK_REFRESH_PREFIX = 'sc_stock_refresh_';

	/**
	 * JS-asset handle / localized object name (slice-34 Constraints).
	 */
	private const JS_HANDLE  = 'spreadconnect-product-meta-box';
	private const JS_OBJECT  = 'spreadconnectProductMetaBox';
	private const JS_VERSION = '1.0.0';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Register the meta-box on the WC-Product edit screen.
	 *
	 * Wired from `Bootstrap\Plugin::init()` to the `add_meta_boxes` hook.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_meta_box(
			self::META_BOX_ID,
			__( 'Spreadconnect', self::TEXT_DOMAIN ),
			array( self::class, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Enqueue the meta-box JS on the product-edit screen.
	 *
	 * Wired from `Bootstrap\Plugin::init()` to the `admin_enqueue_scripts`
	 * hook. Guarded by `get_current_screen()->post_type === 'product'` so the
	 * asset is never loaded on unrelated admin pages.
	 *
	 * @param string $hookSuffix Current admin screen hook (e.g. `post.php`).
	 *
	 * @return void
	 */
	public static function enqueueAssets( string $hookSuffix ): void
	{
		// Only the post-edit / new-post screens carry a meta-box — abort
		// early on every other admin page to keep the asset count minimal.
		if ( 'post.php' !== $hookSuffix && 'post-new.php' !== $hookSuffix ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || 'product' !== ( $screen->post_type ?? '' ) ) {
			return;
		}

		$postId = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Resolve the on-disk JS path so `wp_enqueue_script` can compute a
		// stable URL relative to the plugin root. The path is computed from
		// THIS file location to avoid having to thread the plugin file
		// pointer through every static call site.
		$pluginDir = dirname( __DIR__, 2 );
		$jsRelPath = 'assets/js/product-meta-box.js';
		$jsUrl     = plugins_url( $jsRelPath, $pluginDir . '/spreadconnect-pod.php' );

		wp_enqueue_script(
			self::JS_HANDLE,
			$jsUrl,
			array( 'jquery' ),
			self::JS_VERSION,
			true
		);

		wp_localize_script(
			self::JS_HANDLE,
			self::JS_OBJECT,
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( ProductActions::NONCE_ACTION ),
				'productId' => $postId,
			)
		);
	}

	/**
	 * Render the meta-box body for one product.
	 *
	 * Dispatches between `unlinked`, `linked` and `removed_in_sc` based on
	 * postmeta state.
	 *
	 * @param WP_Post $post Current product post (provided by WP).
	 *
	 * @return void
	 */
	public static function render( WP_Post $post ): void
	{
		$productId = (int) $post->ID;

		$articleId = get_post_meta( $productId, self::META_ARTICLE_ID, true );
		$articleId = is_string( $articleId ) ? $articleId : '';

		// AC-1: empty article-id → render the picker only. Cost / margin /
		// stock / sync blocks are NOT rendered in this state (Constraints
		// "Cost/Margin/Stock/Sync-Bloecke werden nur gerendert wenn
		// _spreadconnect_article_id non-empty ist").
		if ( '' === $articleId ) {
			self::renderUnlinked( $productId );
			return;
		}

		$state = get_post_meta( $productId, self::META_SYNC_STATE, true );
		$state = is_string( $state ) ? $state : '';

		// AC-10: removed_in_sc renders the same linked-state markup but with
		// a red banner at the top + a `sc-greyed` wrapper class on the
		// info-blocks + a [Re-Sync (re-create)] button. The unlink button
		// remains available so the admin can manually break the link.
		$isRemoved = ( self::SYNC_STATE_REMOVED_IN_SC === $state );

		self::renderLinked( $productId, $articleId, $isRemoved );
	}

	// ------------------------------------------------------------------
	// Render helpers
	// ------------------------------------------------------------------

	/**
	 * Render the `unlinked` state — Article-Picker UI.
	 *
	 * The picker is empty on initial render; the JS triggers a debounced
	 * search-call on focus / input and populates `#sc-picker-results`.
	 */
	private static function renderUnlinked( int $productId ): void
	{
		?>
		<div
			class="sc-meta-box sc-state-unlinked"
			data-sc-state="unlinked"
			data-sc-product-id="<?php echo esc_attr( (string) $productId ); ?>"
		>
			<p class="sc-intro">
				<?php esc_html_e( 'Not linked to a Spreadconnect article.', self::TEXT_DOMAIN ); ?>
			</p>
			<p>
				<label
					for="sc-picker-search"
					class="screen-reader-text"
				><?php esc_html_e( 'Search articles', self::TEXT_DOMAIN ); ?></label>
				<input
					type="search"
					id="sc-picker-search"
					class="sc-picker-search widefat"
					placeholder="<?php echo esc_attr__( 'Search by title or ID...', self::TEXT_DOMAIN ); ?>"
					autocomplete="off"
				/>
			</p>
			<div
				id="sc-picker-results"
				class="sc-picker-results"
				aria-live="polite"
			></div>
			<p class="sc-picker-footer description">
				<?php
				printf(
					/* translators: %s: Anchor opening tag for the SC dashboard link. */
					esc_html__( '%1$sOpen SC dashboard %2$s', self::TEXT_DOMAIN ),
					'<a href="https://app.spreadconnect.com/articles" target="_blank" rel="noopener noreferrer">',
					'&#x2197;</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the `linked` (and `removed_in_sc`) states.
	 *
	 * `removed_in_sc` is a visual variation of `linked`: identical block
	 * inventory, but the info-blocks carry the `sc-greyed` class and a
	 * red banner is prepended.
	 */
	private static function renderLinked( int $productId, string $articleId, bool $isRemoved ): void
	{
		$cost         = self::readMetaString( $productId, self::META_COST );
		$costCurrency = self::readMetaString( $productId, self::META_COST_CURRENCY );
		$lastSync     = self::readMetaInt( $productId, self::META_LAST_SYNC );
		$syncState    = self::readMetaString( $productId, self::META_SYNC_STATE );

		$variations  = self::collectVariations( $productId );
		$initialPrice = self::resolveInitialPrice( $productId, $variations );

		$lastRefresh = get_transient( self::TRANSIENT_STOCK_REFRESH_PREFIX . $productId );
		$lastRefresh = is_int( $lastRefresh ) ? $lastRefresh : 0;

		$blocksClass = $isRemoved ? 'sc-info-blocks sc-greyed' : 'sc-info-blocks';
		?>
		<div
			class="sc-meta-box sc-state-linked"
			data-sc-state="<?php echo esc_attr( $isRemoved ? self::SYNC_STATE_REMOVED_IN_SC : 'linked' ); ?>"
			data-sc-product-id="<?php echo esc_attr( (string) $productId ); ?>"
			data-sc-article-id="<?php echo esc_attr( $articleId ); ?>"
			data-sc-cost="<?php echo esc_attr( $cost ); ?>"
		>
			<?php if ( $isRemoved ) : ?>
				<div class="notice notice-error sc-removed-banner inline">
					<p>
						<strong>
							<?php esc_html_e( 'Article removed in Spreadconnect — WC product set to draft', self::TEXT_DOMAIN ); ?>
						</strong>
					</p>
				</div>
			<?php endif; ?>

			<div class="sc-block sc-block-article">
				<p>
					<strong><?php esc_html_e( 'Article-ID:', self::TEXT_DOMAIN ); ?></strong>
					<code class="sc-article-id"><?php echo esc_html( $articleId ); ?></code>
					<button
						type="button"
						class="button-link button-link-delete sc-action-unlink"
					><?php esc_html_e( 'Unlink', self::TEXT_DOMAIN ); ?></button>
				</p>
			</div>

			<div class="<?php echo esc_attr( $blocksClass ); ?>">
				<div class="sc-block sc-block-cost-margin">
					<h4><?php esc_html_e( 'Cost & Margin', self::TEXT_DOMAIN ); ?></h4>
					<p>
						<?php esc_html_e( 'SC Cost:', self::TEXT_DOMAIN ); ?>
						<span class="sc-cost-value">
							<?php
							if ( '' === $cost ) {
								echo '&mdash;';
							} else {
								echo esc_html( $cost );
								if ( '' !== $costCurrency ) {
									echo ' ' . esc_html( $costCurrency );
								}
							}
							?>
						</span>
					</p>
					<p>
						<?php esc_html_e( 'WC Price:', self::TEXT_DOMAIN ); ?>
						<span class="sc-price-value" data-sc-initial-price="<?php echo esc_attr( $initialPrice ); ?>">
							<?php echo esc_html( '' === $initialPrice ? '—' : $initialPrice ); ?>
						</span>
					</p>
					<p>
						<?php esc_html_e( 'Margin:', self::TEXT_DOMAIN ); ?>
						<span id="sc-margin-value" class="sc-margin-value sc-margin-unknown">&mdash;</span>
					</p>
				</div>

				<div class="sc-block sc-block-stock">
					<h4>
						<?php esc_html_e( 'Stock (cached 5 min)', self::TEXT_DOMAIN ); ?>
						<button
							type="button"
							class="button-link sc-action-refresh-stock"
							title="<?php echo esc_attr__( 'Refresh stock from Spreadconnect', self::TEXT_DOMAIN ); ?>"
						>&#x27F3; <?php esc_html_e( 'Refresh', self::TEXT_DOMAIN ); ?></button>
					</h4>
					<table class="sc-stock-table widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'SKU', self::TEXT_DOMAIN ); ?></th>
								<th scope="col"><?php esc_html_e( 'Stock', self::TEXT_DOMAIN ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							if ( empty( $variations ) ) :
								?>
								<tr>
									<td colspan="2">
										<em><?php esc_html_e( 'No variations.', self::TEXT_DOMAIN ); ?></em>
									</td>
								</tr>
								<?php
							else :
								foreach ( $variations as $sku ) :
									$transientKey = self::TRANSIENT_STOCK_PREFIX . $sku;
									$cachedQty    = get_transient( $transientKey );

									[ $badgeClass, $badgeText ] = self::renderStockBadge( $cachedQty );
									?>
									<tr data-sc-sku="<?php echo esc_attr( $sku ); ?>">
										<td><code><?php echo esc_html( $sku ); ?></code></td>
										<td>
											<span class="sc-stock-badge <?php echo esc_attr( $badgeClass ); ?>">
												<?php echo esc_html( $badgeText ); ?>
											</span>
										</td>
									</tr>
									<?php
								endforeach;
							endif;
							?>
						</tbody>
					</table>
					<p class="sc-stock-meta description">
						<?php
						if ( $lastRefresh > 0 ) {
							printf(
								/* translators: %s: localized date/time. */
								esc_html__( 'Last refresh: %s', self::TEXT_DOMAIN ),
								esc_html( wp_date( 'H:i', $lastRefresh ) )
							);
						} else {
							esc_html_e( 'Last refresh: —', self::TEXT_DOMAIN );
						}
						?>
					</p>
				</div>

				<div class="sc-block sc-block-sync">
					<h4><?php esc_html_e( 'Sync Status', self::TEXT_DOMAIN ); ?></h4>
					<p>
						<?php esc_html_e( 'Last sync:', self::TEXT_DOMAIN ); ?>
						<span class="sc-last-sync">
							<?php
							if ( $lastSync > 0 ) {
								echo esc_html( wp_date( 'Y-m-d H:i', $lastSync ) );
							} else {
								echo '&mdash;';
							}
							?>
						</span>
					</p>
					<p>
						<?php esc_html_e( 'State:', self::TEXT_DOMAIN ); ?>
						<span class="sc-sync-state sc-sync-state-<?php echo esc_attr( '' === $syncState ? 'unknown' : $syncState ); ?>">
							<?php echo esc_html( '' === $syncState ? '—' : $syncState ); ?>
						</span>
					</p>
					<p>
						<button
							type="button"
							class="button button-secondary sc-action-resync"
						>
							<?php
							if ( $isRemoved ) {
								esc_html_e( 'Re-Sync (re-create)', self::TEXT_DOMAIN );
							} else {
								esc_html_e( 'Re-Sync now', self::TEXT_DOMAIN );
							}
							?>
						</button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Translate a transient stock value to a (cssClass, label) tuple.
	 *
	 * Cache miss (`false`) → loading placeholder (`…`), no badge.
	 *
	 * @param mixed $cachedQty
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function renderStockBadge( mixed $cachedQty ): array
	{
		if ( false === $cachedQty || ! is_int( $cachedQty ) ) {
			return array( 'sc-stock-loading', '…' );
		}

		if ( $cachedQty <= 0 ) {
			return array( 'sc-stock-out', '✗ ' . __( 'Out', self::TEXT_DOMAIN ) );
		}

		if ( $cachedQty < 10 ) {
			return array(
				'sc-stock-low',
				sprintf( '⚠ ' . __( 'Low (%d)', self::TEXT_DOMAIN ), $cachedQty ),
			);
		}

		return array( 'sc-stock-available', '● ' . __( 'Available', self::TEXT_DOMAIN ) );
	}

	/**
	 * Read a non-empty string postmeta value, fallback `''`.
	 */
	private static function readMetaString( int $productId, string $key ): string
	{
		$value = get_post_meta( $productId, $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Read a positive int postmeta value, fallback `0`.
	 */
	private static function readMetaInt( int $productId, string $key ): int
	{
		$value = get_post_meta( $productId, $key, true );
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			return (int) $value;
		}
		return 0;
	}

	/**
	 * Collect all variation-SKUs of a variable product.
	 *
	 * Returns the SKUs in the order WC reports the children. Empty SKUs
	 * are filtered out (matches `ProductActions::refreshStock` semantics).
	 *
	 * @return string[]
	 */
	private static function collectVariations( int $productId ): array
	{
		$skus = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $skus;
		}

		$product = wc_get_product( $productId );
		if ( ! ( $product instanceof WC_Product_Variable ) ) {
			return $skus;
		}

		$childIds = $product->get_children();
		if ( ! is_array( $childIds ) ) {
			return $skus;
		}

		foreach ( $childIds as $childId ) {
			$variation = wc_get_product( (int) $childId );
			if ( null === $variation || false === $variation ) {
				continue;
			}
			$sku = (string) $variation->get_sku();
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		return $skus;
	}

	/**
	 * Resolve a price string for the initial server-side render.
	 *
	 * The actual margin recomputation is JS-driven, but the initial
	 * paint shows the parent product's price (or the first variation's
	 * price when the parent has none).
	 *
	 * @param string[] $variationSkus
	 */
	private static function resolveInitialPrice( int $productId, array $variationSkus ): string
	{
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $productId );
		if ( null === $product || false === $product ) {
			return '';
		}

		$price = (string) $product->get_price();
		if ( '' !== $price ) {
			return $price;
		}

		// Fallback: first variation's price. Variation SKUs are already
		// filtered for non-empty by `collectVariations`, but the children
		// are re-fetched here to read the price.
		if ( $product instanceof WC_Product_Variable ) {
			foreach ( (array) $product->get_children() as $childId ) {
				$variation = wc_get_product( (int) $childId );
				if ( null === $variation || false === $variation ) {
					continue;
				}
				$variationPrice = (string) $variation->get_price();
				if ( '' !== $variationPrice ) {
					return $variationPrice;
				}
			}
		}

		return '';
	}
}
