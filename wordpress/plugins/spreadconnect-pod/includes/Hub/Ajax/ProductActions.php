<?php
/**
 * Admin-AJAX handlers for the WC-Product-Edit "Spreadconnect" meta-box (slice-34).
 *
 * Four `wp_ajax_*` actions, all gated by `manage_woocommerce` and the
 * shared nonce `spreadconnect_product_actions`:
 *
 *   - `spreadconnect_search_articles`  — debounced article picker
 *                                         (`getArticles(0, 20, $search)`).
 *   - `spreadconnect_link_article`     — write `_spreadconnect_article_id`
 *                                         and enqueue
 *                                         `spreadconnect/sync_article`.
 *                                         Re-Sync uses the same handler with
 *                                         `force=true` (idempotent meta-write
 *                                         + re-enqueue).
 *   - `spreadconnect_unlink_article`   — delete every `_spreadconnect_*`
 *                                         parent-product meta key. No SC
 *                                         API call (Constraints).
 *   - `spreadconnect_refresh_stock`    — bulk `getStock(null, $skus)` for
 *                                         a product, write `sc_stock_{sku}`
 *                                         + `sc_stock_refresh_{productId}`
 *                                         transients (5 min TTL).
 *
 * Shape of every response: `wp_send_json_success` / `wp_send_json_error`
 * with a `'message'` key on errors. Capability / nonce failures return a
 * 403 status code so the JS can branch on `xhr.status` if needed.
 *
 * Architecture references:
 *   - architecture.md "AJAX Action Inventory" Z. 149-151
 *   - architecture.md "Outbound Endpoints" Z. 94 + Z. 120
 *   - architecture.md "Cache-Keys" Z. 350 (`sc_stock_{sku}` 5 min TTL)
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use InvalidArgumentException;
use SpreadconnectPod\Api\Dto\ArticleSummary;
use SpreadconnectPod\Api\Dto\StockEntry;
use SpreadconnectPod\Api\Dto\Variant;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use Throwable;
use WC_Product;
use WC_Product_Variable;

/**
 * Stateful AJAX front-controller for the Product-Edit meta-box.
 *
 * Slice-34 introduces constructor-DI on this class so unit tests can inject
 * a Mockery-mocked {@see SpreadconnectClient}. Production wiring uses the
 * static `*Static` bridges, which build a default client and delegate.
 *
 * `final` per slice-34 Constraints.
 */
final class ProductActions
{
	/**
	 * Shared nonce-action name for all four AJAX handlers.
	 *
	 * Slice-34 Constraints: "Nonce-Action-String fuer alle vier
	 * AJAX-Handler einheitlich: `spreadconnect_product_actions`."
	 */
	public const NONCE_ACTION = 'spreadconnect_product_actions';

	/**
	 * POST field carrying the nonce. Localized as `nonce` by
	 * {@see \SpreadconnectPod\Inline\ProductMetaBox::enqueueAssets()},
	 * so the JS posts it under that key.
	 */
	private const NONCE_FIELD = 'nonce';

	/**
	 * Capability gate (Slice-34 Constraints: `manage_woocommerce`).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Action-Scheduler hook + group (mirror of slice-23 conventions).
	 */
	private const AS_HOOK_SYNC_ARTICLE = 'spreadconnect/sync_article';
	private const AS_GROUP             = 'spreadconnect';

	/**
	 * Cache-key templates for the live-stock cache. Identical strings to
	 * {@see \SpreadconnectPod\Inline\ProductMetaBox} so the picker render
	 * + the refresh handler hit the same transient.
	 */
	public const TRANSIENT_STOCK_PREFIX         = 'sc_stock_';
	public const TRANSIENT_STOCK_REFRESH_PREFIX = 'sc_stock_refresh_';

	/**
	 * 5-minute TTL for the stock transients (Slice-34 Constraints).
	 */
	private const TRANSIENT_STOCK_TTL_SECONDS = 300;

	/**
	 * Picker page-size (Slice-34 AC-2: top-20 most-recent).
	 */
	private const PICKER_PAGE_SIZE = 20;

	/**
	 * Postmeta keys mutated/deleted by the link / unlink handlers.
	 *
	 * The list is derived directly from architecture.md "WC-Product Meta"
	 * Z. 287-292 + the mapper-only key `_spreadconnect_sync_error`.
	 *
	 * @var string[]
	 */
	private const META_KEYS_PRODUCT = array(
		'_spreadconnect_article_id',
		'_spreadconnect_product_type_id',
		'_spreadconnect_cost',
		'_spreadconnect_cost_currency',
		'_spreadconnect_last_sync',
		'_spreadconnect_sync_state',
		'_spreadconnect_sync_error',
	);

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	public function __construct(
		private readonly SpreadconnectClient $client,
	) {
	}

	// ------------------------------------------------------------------
	// Static bridges — wired from `Bootstrap\Plugin::init()` per AC-11.
	// ------------------------------------------------------------------

	/**
	 * Bridge for `wp_ajax_spreadconnect_search_articles`.
	 */
	public static function searchArticlesStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->searchArticles();
	}

	/**
	 * Bridge for `wp_ajax_spreadconnect_link_article`.
	 */
	public static function linkArticleStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->linkArticle();
	}

	/**
	 * Bridge for `wp_ajax_spreadconnect_unlink_article`.
	 */
	public static function unlinkArticleStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->unlinkArticle();
	}

	/**
	 * Bridge for `wp_ajax_spreadconnect_refresh_stock`.
	 */
	public static function refreshStockStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->refreshStock();
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * Picker server-side search.
	 *
	 * Reads the `search` POST field (optional) and proxies to
	 * `SpreadconnectClient::getArticles(0, 20, $search)`. Empty search
	 * returns the top-20 most-recent articles (slice-34 AC-2).
	 */
	public function searchArticles(): void
	{
		if ( ! $this->ensureCapAndNonce() ) {
			return;
		}

		$searchRaw = isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '';
		$search    = is_string( $searchRaw ) ? trim( sanitize_text_field( $searchRaw ) ) : '';
		$search    = '' === $search ? null : $search;

		try {
			$page = $this->client->getArticles( 0, self::PICKER_PAGE_SIZE, $search );
		} catch ( SpreadconnectTransientError | SpreadconnectClientError $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				502
			);
			return;
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
			return;
		}

		$rawItems = isset( $page['items'] ) && is_array( $page['items'] ) ? $page['items'] : array();
		$items    = array();
		foreach ( $rawItems as $summary ) {
			if ( $summary instanceof ArticleSummary ) {
				$items[] = $this->articleSummaryToPickerItem( $summary );
			}
		}

		$total = $page['total'] ?? null;
		if ( ! is_int( $total ) ) {
			$total = count( $items );
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	/**
	 * Link an article: write `_spreadconnect_article_id` and enqueue the
	 * `spreadconnect/sync_article` AS-job.
	 *
	 * Re-Sync (slice-34 AC-9) reuses this handler — the meta-write is
	 * idempotent (`update_post_meta` with the same value is a no-op), and
	 * the subsequent enqueue runs unconditionally so the worker runs again.
	 */
	public function linkArticle(): void
	{
		if ( ! $this->ensureCapAndNonce() ) {
			return;
		}

		$productId = $this->resolveProductId();
		$articleId = $this->resolvePostString( 'article_id' );

		if ( $productId <= 0 || '' === $articleId ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid product or article id.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		update_post_meta( $productId, '_spreadconnect_article_id', $articleId );

		// Args-shape per slice-34 Constraints: the inner assoc-array is
		// wrapped in an outer array so AS's `do_action_ref_array()` splats
		// exactly one positional argument (the assoc array) into
		// {@see SyncArticleJob::handleStatic()} — matching the
		// `accepted_args = 1` registration done in `Bootstrap\Plugin`.
		// `run_id` is intentionally omitted so the handler hits the
		// slice-23 AC-10 webhook-triggered branch (no history-row
		// append) — meta-box re-syncs are not part of a catalog run.
		as_enqueue_async_action(
			self::AS_HOOK_SYNC_ARTICLE,
			array( array( 'article_id' => $articleId ) ),
			self::AS_GROUP
		);

		wp_send_json_success(
			array(
				'linked'     => true,
				'article_id' => $articleId,
				'enqueued'   => true,
			)
		);
	}

	/**
	 * Unlink the article: delete every `_spreadconnect_*` parent-product
	 * meta key. WC variations + their SKUs are left untouched (slice-34
	 * Constraints "Pricing-Trade-off"). No SC API call.
	 */
	public function unlinkArticle(): void
	{
		if ( ! $this->ensureCapAndNonce() ) {
			return;
		}

		$productId = $this->resolveProductId();
		if ( $productId <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid product id.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		foreach ( self::META_KEYS_PRODUCT as $key ) {
			delete_post_meta( $productId, $key );
		}

		wp_send_json_success(
			array(
				'unlinked' => true,
			)
		);
	}

	/**
	 * Refresh the live-stock cache for one product.
	 *
	 * Walks the variations via `WC_Product_Variable::get_children()`,
	 * filters non-empty SKUs, calls
	 * `SpreadconnectClient::getStock(null, $skus)` (bulk) and writes one
	 * `sc_stock_{sku}` transient per `StockEntry` plus a
	 * `sc_stock_refresh_{productId}` timestamp transient.
	 */
	public function refreshStock(): void
	{
		if ( ! $this->ensureCapAndNonce() ) {
			return;
		}

		$productId = $this->resolveProductId();
		if ( $productId <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid product id.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		$skus = $this->collectVariationSkus( $productId );
		if ( empty( $skus ) ) {
			wp_send_json_success(
				array(
					'stock'        => new \stdClass(),
					'refreshed_at' => time(),
				)
			);
			return;
		}

		try {
			$entries = $this->client->getStock( null, $skus );
		} catch ( InvalidArgumentException | SpreadconnectTransientError | SpreadconnectClientError $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				502
			);
			return;
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
			return;
		}

		$stock = array();
		foreach ( $entries as $entry ) {
			if ( ! ( $entry instanceof StockEntry ) ) {
				continue;
			}
			$stock[ $entry->sku ] = $entry->quantity;
			set_transient(
				self::TRANSIENT_STOCK_PREFIX . $entry->sku,
				$entry->quantity,
				self::TRANSIENT_STOCK_TTL_SECONDS
			);
		}

		$refreshedAt = time();
		set_transient(
			self::TRANSIENT_STOCK_REFRESH_PREFIX . $productId,
			$refreshedAt,
			self::TRANSIENT_STOCK_TTL_SECONDS
		);

		wp_send_json_success(
			array(
				'stock'        => $stock,
				'refreshed_at' => $refreshedAt,
			)
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Cap + nonce gate. Returns `true` when both checks pass; otherwise
	 * sends a 403 JSON error and returns `false` (caller bails).
	 */
	private function ensureCapAndNonce(): bool
	{
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return false;
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
				),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Read an integer `product_id` POST field. Returns `0` on missing /
	 * non-numeric input.
	 */
	private function resolveProductId(): int
	{
		if ( ! isset( $_POST['product_id'] ) ) {
			return 0;
		}

		$raw = wp_unslash( $_POST['product_id'] );

		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}

		if ( is_string( $raw ) && '' !== $raw && ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		return 0;
	}

	/**
	 * Read an arbitrary string POST field (sanitized). Returns `''` on
	 * missing / non-string input.
	 */
	private function resolvePostString( string $key ): string
	{
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return trim( sanitize_text_field( $raw ) );
	}

	/**
	 * Walk a variable product's children, filter non-empty SKUs.
	 *
	 * Mirrors {@see \SpreadconnectPod\Inline\ProductMetaBox::collectVariations}
	 * — kept duplicated rather than shared via a static helper to avoid a
	 * cross-package dependency between Inline and Hub layers (the constants
	 * for the meta-keys are already mirrored too).
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

	/**
	 * Project an `ArticleSummary` DTO into the picker JSON shape.
	 *
	 * Slice-34 AC-2 contract: `{id, title, thumbUrl, sizes, colors}`.
	 * `thumbUrl` is `null` here — the summary endpoint does not expose a
	 * preview URL. `sizes` / `colors` are derived from the embedded
	 * `Variant[]` (count of distinct size/color ids).
	 *
	 * @return array{id:string, title:string, thumbUrl:?string, sizes:int, colors:int}
	 */
	private function articleSummaryToPickerItem( ArticleSummary $summary ): array
	{
		$sizeIds  = array();
		$colorIds = array();

		foreach ( $summary->variants as $variant ) {
			if ( $variant instanceof Variant ) {
				$sizeIds[ $variant->sizeId ]   = true;
				$colorIds[ $variant->colorId ] = true;
			}
		}

		return array(
			'id'       => $summary->id,
			'title'    => $summary->title,
			'thumbUrl' => null,
			'sizes'    => count( $sizeIds ),
			'colors'   => count( $colorIds ),
		);
	}
}
