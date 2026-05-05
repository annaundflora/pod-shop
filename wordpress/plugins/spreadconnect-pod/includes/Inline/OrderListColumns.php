<?php
/**
 * Inline\OrderListColumns (slice-33).
 *
 * Adapter that extends the WC-Order-List screen on BOTH HPOS
 * (`wp-admin/admin.php?page=wc-orders`) and legacy
 * (`edit.php?post_type=shop_order`) with:
 *
 *   - Two columns "SC-State" (badge) and "SC-OrderID" (external link).
 *   - A `?sc_state=…` filter drop-down.
 *   - A "Re-send to Spreadconnect" bulk-action with a pre-flight AJAX
 *     count and an outcome banner on redirect.
 *
 * Single-Adapter pattern (architecture.md Z. 641): every public method is
 * registered on BOTH the legacy and HPOS hook variants in one bootstrap
 * call. Static `*Static` bridges resolve a `Failure\BulkResendCoordinator`
 * default so the class can act as a hook callable directly.
 *
 * Slice 33 ships only the Adapter-Surface; the production-grade per-row
 * outcome panel + `[Show details ▾]` toggle, the real
 * `as_enqueue_async_action()` schedule pipeline and the
 * `Failure\FailedOpsRepo` correlation arrive in slice 40.
 *
 * Mount-points: {@see \SpreadconnectPod\Bootstrap\Plugin::init()} registers
 *   - column rendering (legacy + HPOS)
 *   - sortable columns (legacy + HPOS)
 *   - sort + filter via `pre_get_posts` and the HPOS-Order-Query filter
 *   - filter dropdown on `restrict_manage_posts` + HPOS-equivalent
 *   - bulk-action register / handle on both hook-sets
 *   - `admin_notices` outcome panel
 *   - `admin_enqueue_scripts` JS asset
 *   - `wp_ajax_spreadconnect_bulk_resend_preflight` handler
 *
 * Architecture references:
 *   - architecture.md Z. 397 — `Inline\OrderListColumns` adapter.
 *   - architecture.md Z. 309-311 — Order-Meta keys consumed.
 *   - architecture.md Z. 637, 641, 821 — HPOS dual-hook contract.
 *   - architecture.md Z. 84 — nonce-action `spreadconnect_admin`.
 *   - wireframes.md Screen 12 Z. 999-1051.
 *
 * @package SpreadconnectPod\Inline
 */

declare(strict_types=1);

namespace SpreadconnectPod\Inline;

use SpreadconnectPod\Failure\BulkResendCoordinator;
use SpreadconnectPod\Hub\Ajax\OrderActions;
use WC_Order;
use WP_Query;

/**
 * Stateful WC-Order-List adapter. Constructor-DI on the
 * `Failure\BulkResendCoordinator` so unit tests can inject a Mockery
 * double; production wiring uses the static bridges which build a default
 * coordinator instance per request.
 *
 * `final` per slice-33 Constraints.
 */
final class OrderListColumns
{
	/**
	 * Order-Meta keys consumed (architecture.md Z. 309-311).
	 */
	private const META_STATE         = '_spreadconnect_state';
	private const META_ORDER_ID      = '_spreadconnect_order_id';
	private const META_NEEDS_ACTION  = '_spreadconnect_needs_action';

	/**
	 * Column slugs (also used as `orderby` query-var values).
	 */
	public const COLUMN_STATE    = 'sc_state';
	public const COLUMN_ORDER_ID = 'sc_order_id';

	/**
	 * Bulk-action slug (slice-33 AC-9 — appended on both hook-sets).
	 */
	public const BULK_ACTION_RESEND = 'spreadconnect_bulk_resend';

	/**
	 * AJAX action slug for the bulk-resend pre-flight count
	 * (slice-33 AC-10 + AC-15).
	 */
	public const AJAX_ACTION_PREFLIGHT = 'spreadconnect_bulk_resend_preflight';

	/**
	 * `?sc_state=…` filter query-var name.
	 */
	public const FILTER_QUERY_VAR = 'sc_state';

	/**
	 * Outcome-banner query-var written by `handleBulkAction()` and read by
	 * `renderOutcomePanel()`.
	 */
	public const OUTCOME_QUERY_VAR = 'bulk_resend_summary';

	/**
	 * Outcome-banner enum values.
	 */
	public const OUTCOME_OK           = 'ok';
	public const OUTCOME_NO_ELIGIBLE  = 'no_eligible';

	/**
	 * Pseudo-states (no persistent meta). `pending` = pre-submit;
	 * `needs_action` = orthogonal `_spreadconnect_needs_action='1'` flag.
	 */
	public const PSEUDO_STATE_PENDING      = 'pending';
	public const PSEUDO_STATE_NEEDS_ACTION = 'needs_action';

	/**
	 * Persistent state literals (architecture.md Z. 310).
	 *
	 * @var string[]
	 */
	private const PERSISTENT_STATES = array(
		'submitting',
		'NEW',
		'CONFIRMED',
		'PROCESSED',
		'CANCELLED',
		'failed_to_submit',
	);

	/**
	 * Whitelisted `?sc_state=` filter values (defensive — anything else is NO-OP).
	 *
	 * @var string[]
	 */
	private const ALLOWED_FILTER_STATES = array(
		'',
		self::PSEUDO_STATE_PENDING,
		'submitting',
		'NEW',
		'CONFIRMED',
		'PROCESSED',
		'CANCELLED',
		'failed_to_submit',
		self::PSEUDO_STATE_NEEDS_ACTION,
	);

	/**
	 * Capability gate (architecture.md Z. 646).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Logger source (slice-33 Constraints).
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * JS asset handle / localized object.
	 */
	private const JS_HANDLE  = 'spreadconnect-order-list';
	private const JS_OBJECT  = 'SpreadconnectOrderList';
	private const JS_VERSION = '1.0.0';

	/**
	 * HPOS / legacy screen-id literals (architecture.md Z. 641 + slice-32
	 * mirror).
	 */
	private const HPOS_SCREEN_ID   = 'woocommerce_page_wc-orders';
	private const LEGACY_SCREEN_ID = 'edit-shop_order';
	private const LEGACY_POST_TYPE = 'shop_order';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	private BulkResendCoordinator $coordinator;

	public function __construct( BulkResendCoordinator $coordinator )
	{
		$this->coordinator = $coordinator;
	}

	// ------------------------------------------------------------------
	// Static bridges — wired from `Bootstrap\Plugin::init()` per AC-16.
	// ------------------------------------------------------------------

	private static function defaultInstance(): self
	{
		return new self( new BulkResendCoordinator() );
	}

	/**
	 * Bridge for `manage_edit-shop_order_columns`
	 * + `manage_woocommerce_page_wc-orders_columns`.
	 *
	 * @param array<string, string>|mixed $columns
	 *
	 * @return array<string, string>
	 */
	public static function registerColumnsStatic( $columns ): array
	{
		return self::defaultInstance()->registerColumns( is_array( $columns ) ? $columns : array() );
	}

	/**
	 * Bridge for `manage_shop_order_posts_custom_column` (legacy WP signature
	 * `(string $column, int $post_id)`) + the HPOS render hook
	 * `manage_woocommerce_page_wc-orders_custom_column` which has the same
	 * argument shape.
	 */
	public static function renderColumnStatic( $column, $orderId ): void
	{
		self::defaultInstance()->renderColumn(
			is_string( $column ) ? $column : '',
			(int) $orderId
		);
	}

	/**
	 * Bridge for `manage_edit-shop_order_sortable_columns`
	 * + `manage_woocommerce_page_wc-orders_sortable_columns`.
	 *
	 * @param array<string, string>|mixed $columns
	 *
	 * @return array<string, string>
	 */
	public static function registerSortableColumnsStatic( $columns ): array
	{
		return self::defaultInstance()->registerSortableColumns(
			is_array( $columns ) ? $columns : array()
		);
	}

	/**
	 * Bridge for `pre_get_posts` (legacy). Handles AC-5 + AC-7 in one call.
	 */
	public static function applySortingAndFilterStatic( $query ): void
	{
		if ( ! ( $query instanceof WP_Query ) ) {
			return;
		}
		self::defaultInstance()->applySortingAndFilterLegacy( $query );
	}

	/**
	 * Bridge for the HPOS Order-Query args filter (AC-5 + AC-8). WC fires
	 * either `woocommerce_order_query_args` or
	 * `woocommerce_order_list_table_prepare_items_query_args` depending on
	 * version; both have the same `(array $args): array` shape.
	 *
	 * @param array<string, mixed>|mixed $args
	 *
	 * @return array<string, mixed>
	 */
	public static function applyOrderQueryArgsStatic( $args ): array
	{
		return self::defaultInstance()->applyOrderQueryArgs(
			is_array( $args ) ? $args : array()
		);
	}

	/**
	 * Bridge for `restrict_manage_posts` (legacy) +
	 * `woocommerce_order_list_table_restrict_manage_orders` (HPOS).
	 *
	 * The HPOS hook passes a screen-id string (or the order-status enum).
	 * The legacy hook passes the `$post_type`. Both shapes are accepted.
	 */
	public static function renderFilterDropdownStatic( $screenOrPostType = '' ): void
	{
		self::defaultInstance()->renderFilterDropdown(
			is_string( $screenOrPostType ) ? $screenOrPostType : ''
		);
	}

	/**
	 * Bridge for `bulk_actions-edit-shop_order`
	 * + `bulk_actions-woocommerce_page_wc-orders`.
	 *
	 * @param array<string, string>|mixed $actions
	 *
	 * @return array<string, string>
	 */
	public static function registerBulkActionStatic( $actions ): array
	{
		return self::defaultInstance()->registerBulkAction(
			is_array( $actions ) ? $actions : array()
		);
	}

	/**
	 * Bridge for `handle_bulk_actions-edit-shop_order`
	 * + `handle_bulk_actions-woocommerce_page_wc-orders`.
	 *
	 * @param string|mixed         $redirectUrl
	 * @param string|mixed         $action
	 * @param array<int, int>|mixed $orderIds
	 */
	public static function handleBulkActionStatic( $redirectUrl, $action, $orderIds ): string
	{
		return self::defaultInstance()->handleBulkAction(
			is_string( $redirectUrl ) ? $redirectUrl : '',
			is_string( $action ) ? $action : '',
			is_array( $orderIds ) ? $orderIds : array()
		);
	}

	/**
	 * Bridge for `admin_notices`.
	 */
	public static function renderOutcomePanelStatic(): void
	{
		self::defaultInstance()->renderOutcomePanel();
	}

	/**
	 * Bridge for `admin_enqueue_scripts`.
	 */
	public static function enqueueAssetsStatic( $hookSuffix = '' ): void
	{
		self::defaultInstance()->enqueueAssets(
			is_string( $hookSuffix ) ? $hookSuffix : ''
		);
	}

	/**
	 * Bridge for `wp_ajax_spreadconnect_bulk_resend_preflight`.
	 */
	public static function handlePreflightAjaxStatic(): void
	{
		self::defaultInstance()->handlePreflightAjax();
	}

	// ------------------------------------------------------------------
	// AC-1: register the two SC columns
	// ------------------------------------------------------------------

	/**
	 * Insert `sc_state` + `sc_order_id` columns directly after the WC
	 * `order_status` column. Fallback: insert before `order_date` if
	 * `order_status` is absent. As a last resort, append at the end.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function registerColumns( array $columns ): array
	{
		$additions = array(
			self::COLUMN_STATE    => __( 'SC-State', self::TEXT_DOMAIN ),
			self::COLUMN_ORDER_ID => __( 'SC-OrderID', self::TEXT_DOMAIN ),
		);

		$anchorAfter  = array_key_exists( 'order_status', $columns ) ? 'order_status' : null;
		$anchorBefore = ( null === $anchorAfter && array_key_exists( 'order_date', $columns ) )
			? 'order_date'
			: null;

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
	// AC-2 + AC-3: per-row column rendering
	// ------------------------------------------------------------------

	/**
	 * Echo the cell HTML for one row + one of our two columns. No-op for
	 * any other column slug (other plugins may share the same hook).
	 */
	public function renderColumn( string $column, int $orderId ): void
	{
		if ( $orderId <= 0 ) {
			return;
		}

		switch ( $column ) {
			case self::COLUMN_STATE:
				$this->renderStateCell( $orderId );
				return;

			case self::COLUMN_ORDER_ID:
				$this->renderOrderIdCell( $orderId );
				return;
		}
	}

	/**
	 * AC-2: SC-State badge with class-mapping for all 7 known states.
	 *
	 *   - persistent states: `submitting | NEW | CONFIRMED | PROCESSED |
	 *     CANCELLED | failed_to_submit`
	 *   - pseudo state when meta absent: `pending`
	 *   - orthogonal `_spreadconnect_needs_action='1'` flag is exposed on
	 *     the SAME span as `data-needs-action="true"` + class
	 *     `spreadconnect-state-needs-action`.
	 */
	private function renderStateCell( int $orderId ): void
	{
		$order = $this->resolveOrder( $orderId );
		if ( null === $order ) {
			echo esc_html( '—' );
			return;
		}

		$rawState   = (string) $order->get_meta( self::META_STATE );
		$state      = '' === $rawState ? self::PSEUDO_STATE_PENDING : $rawState;
		$needsAct   = '1' === (string) $order->get_meta( self::META_NEEDS_ACTION );

		$label = ( 'failed_to_submit' === $state )
			? __( 'FAILED', self::TEXT_DOMAIN )
			: $state;

		$classes = array(
			'spreadconnect-state-badge',
			'spreadconnect-state-' . $state,
		);
		if ( $needsAct ) {
			$classes[] = 'spreadconnect-state-needs-action';
		}

		printf(
			'<span class="%s" data-state="%s"%s>%s</span>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $state ),
			$needsAct ? ' data-needs-action="true"' : '',
			esc_html( $label )
		);
	}

	/**
	 * AC-3: SC-OrderID external link or em-dash.
	 *
	 * URL is built via the filter hook `spreadconnect/sc_backend_order_url`
	 * with a default of the option `spreadconnect_sc_backend_url_template`
	 * (template literal — `{id}` placeholder is `rawurlencode`d). Default
	 * `'#'`.
	 */
	private function renderOrderIdCell( int $orderId ): void
	{
		$order = $this->resolveOrder( $orderId );
		if ( null === $order ) {
			echo esc_html( '—' );
			return;
		}

		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			echo esc_html( '—' );
			return;
		}

		$url = $this->resolveBackendOrderUrl( $scOrderId );

		printf(
			'<a target="_blank" rel="noopener noreferrer" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( sprintf( 'SC-%s ↗', $scOrderId ) )
		);
	}

	// ------------------------------------------------------------------
	// AC-4: sortable columns
	// ------------------------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function registerSortableColumns( array $columns ): array
	{
		$columns[ self::COLUMN_STATE ]    = self::COLUMN_STATE;
		$columns[ self::COLUMN_ORDER_ID ] = self::COLUMN_ORDER_ID;

		return $columns;
	}

	// ------------------------------------------------------------------
	// AC-5 / AC-7: Legacy `pre_get_posts` — sort + filter
	// ------------------------------------------------------------------

	/**
	 * Combined `pre_get_posts` handler for the legacy `shop_order` CPT
	 * order-list. Applies sort (AC-5) AND filter (AC-7) in one call.
	 *
	 * Guards (slice-33 Constraints):
	 *   1. `is_admin()`              — front-end queries are untouched.
	 *   2. `$query->is_main_query()` — sub-queries / widgets ignored.
	 *   3. `post_type === 'shop_order'`.
	 */
	public function applySortingAndFilterLegacy( WP_Query $query ): void
	{
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		if ( ! $query->is_main_query() ) {
			return;
		}

		$postType = $query->get( 'post_type' );
		if ( self::LEGACY_POST_TYPE !== $postType ) {
			return;
		}

		$this->applyLegacySorting( $query );
		$this->applyLegacyFilter( $query );
	}

	/**
	 * AC-5 (legacy half): translate `?orderby=sc_state` / `sc_order_id` to
	 * `meta_key` + `orderby='meta_value'`.
	 */
	private function applyLegacySorting( WP_Query $query ): void
	{
		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) || '' === $orderby ) {
			return;
		}

		switch ( $orderby ) {
			case self::COLUMN_STATE:
				$query->set( 'meta_key', self::META_STATE );
				$query->set( 'orderby', 'meta_value' );
				return;

			case self::COLUMN_ORDER_ID:
				$query->set( 'meta_key', self::META_ORDER_ID );
				$query->set( 'orderby', 'meta_value' );
				return;
		}
	}

	/**
	 * AC-7: Translate `?sc_state=…` to a `meta_query` clause. Existing
	 * `meta_query` entries are preserved with `relation=AND`.
	 */
	private function applyLegacyFilter( WP_Query $query ): void
	{
		$filter = $this->readFilterQueryVar();
		if ( '' === $filter ) {
			return;
		}

		$clause = $this->buildFilterMetaClause( $filter );
		if ( null === $clause ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		$merged   = $this->mergeMetaQuery( is_array( $existing ) ? $existing : array(), $clause );

		$query->set( 'meta_query', $merged );
	}

	// ------------------------------------------------------------------
	// AC-5 / AC-8: HPOS Order-Query args filter — sort + filter
	// ------------------------------------------------------------------

	/**
	 * Combined HPOS handler. WC fires either `woocommerce_order_query_args`
	 * or `woocommerce_order_list_table_prepare_items_query_args` — both
	 * carry an `(array $args): array` shape.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function applyOrderQueryArgs( array $args ): array
	{
		$args = $this->applyHposSorting( $args );
		$args = $this->applyHposFilter( $args );

		return $args;
	}

	/**
	 * AC-5 (HPOS half): map `?orderby=sc_state` / `sc_order_id` onto the
	 * `wc_get_orders` args.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	private function applyHposSorting( array $args ): array
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort.
		$orderbyRaw = isset( $_GET['orderby'] ) ? $_GET['orderby'] : '';
		$orderby    = is_string( $orderbyRaw ) ? sanitize_key( $orderbyRaw ) : '';
		if ( '' === $orderby ) {
			return $args;
		}

		switch ( $orderby ) {
			case self::COLUMN_STATE:
				$args['meta_key'] = self::META_STATE;
				$args['orderby']  = 'meta_value';
				return $args;

			case self::COLUMN_ORDER_ID:
				$args['meta_key'] = self::META_ORDER_ID;
				$args['orderby']  = 'meta_value';
				return $args;
		}

		return $args;
	}

	/**
	 * AC-8: Inject the `meta_query` clause into the HPOS args.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	private function applyHposFilter( array $args ): array
	{
		$filter = $this->readFilterQueryVar();
		if ( '' === $filter ) {
			return $args;
		}

		$clause = $this->buildFilterMetaClause( $filter );
		if ( null === $clause ) {
			return $args;
		}

		$existing = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
			? $args['meta_query']
			: array();

		$args['meta_query'] = $this->mergeMetaQuery( $existing, $clause );

		return $args;
	}

	// ------------------------------------------------------------------
	// AC-6: filter dropdown
	// ------------------------------------------------------------------

	/**
	 * Render `<select name="sc_state">` on the order-list screens.
	 *
	 * Legacy: `restrict_manage_posts` passes `$post_type`. HPOS:
	 * `woocommerce_order_list_table_restrict_manage_orders` passes a
	 * screen-id (or empty). On unrelated screens we early-return.
	 */
	public function renderFilterDropdown( string $screenOrPostType ): void
	{
		if ( ! $this->isOrderListScreenContext( $screenOrPostType ) ) {
			return;
		}

		$current = $this->readFilterQueryVar();

		$options = array(
			''                                  => __( 'SC-State: All', self::TEXT_DOMAIN ),
			self::PSEUDO_STATE_PENDING          => __( 'pending', self::TEXT_DOMAIN ),
			'NEW'                               => __( 'NEW', self::TEXT_DOMAIN ),
			'CONFIRMED'                         => __( 'CONFIRMED', self::TEXT_DOMAIN ),
			'PROCESSED'                         => __( 'PROCESSED', self::TEXT_DOMAIN ),
			'CANCELLED'                         => __( 'CANCELLED', self::TEXT_DOMAIN ),
			'failed_to_submit'                  => __( 'FAILED', self::TEXT_DOMAIN ),
			self::PSEUDO_STATE_NEEDS_ACTION     => __( 'NEEDS-ACTION', self::TEXT_DOMAIN ),
		);

		echo '<select name="' . esc_attr( self::FILTER_QUERY_VAR ) . '" class="sc-state-filter">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	// ------------------------------------------------------------------
	// AC-9: bulk-action registration
	// ------------------------------------------------------------------

	/**
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function registerBulkAction( array $actions ): array
	{
		$actions[ self::BULK_ACTION_RESEND ] = __( 'Re-send to Spreadconnect', self::TEXT_DOMAIN );
		return $actions;
	}

	// ------------------------------------------------------------------
	// AC-13: bulk-apply (Slice-40 produces real schedules — slice-33 stub)
	// ------------------------------------------------------------------

	/**
	 * Handle the bulk-action click. WC-filter signature `(string
	 * $redirect_url, string $action, int[] $order_ids): string`.
	 *
	 *   - `$action !== 'spreadconnect_bulk_resend'`  → return URL as-is.
	 *   - `BulkResendCoordinator::run()` returns `queued=0` in slice 33;
	 *     therefore the result is currently always `no_eligible` when the
	 *     selection contains zero `failed_to_submit` orders, OR
	 *     `ok&queued=0&skipped=N&run_id=…` when at least one eligible id
	 *     was present (slice 40 will switch `queued` to `>0`).
	 */
	public function handleBulkAction( string $redirectUrl, string $action, array $orderIds ): string
	{
		if ( self::BULK_ACTION_RESEND !== $action ) {
			return $redirectUrl;
		}

		$ids = array();
		foreach ( $orderIds as $rawId ) {
			$id = (int) $rawId;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$summary = $this->coordinator->run( $ids );

		$queued     = isset( $summary['queued'] ) ? (int) $summary['queued'] : 0;
		$skipped    = isset( $summary['skipped'] ) ? (int) $summary['skipped'] : 0;
		$runId      = isset( $summary['run_id'] ) && is_string( $summary['run_id'] )
			? $summary['run_id']
			: '';
		$totalCount = count( $ids );

		// Per AC-13: `queued > 0` -> `ok` summary. Slice 40 makes `queued`
		// non-zero by replacing the coordinator stub. Until then the
		// selection-has-eligibles vs. zero-eligibles split is decided by
		// inspecting `per_row` (which slice-33 populates with one entry per
		// eligible id).
		$perRow         = isset( $summary['per_row'] ) && is_array( $summary['per_row'] )
			? $summary['per_row']
			: array();
		$eligibleCount  = count( $perRow );

		if ( $queued <= 0 && 0 === $eligibleCount ) {
			$redirectUrl = add_query_arg(
				array(
					self::OUTCOME_QUERY_VAR => self::OUTCOME_NO_ELIGIBLE,
					'total'                 => $totalCount,
					'skipped'               => $skipped,
				),
				$redirectUrl
			);
			return $redirectUrl;
		}

		$redirectUrl = add_query_arg(
			array(
				self::OUTCOME_QUERY_VAR => self::OUTCOME_OK,
				'queued'                => $queued,
				'skipped'               => $skipped,
				'total'                 => $totalCount,
				'run_id'                => $runId,
			),
			$redirectUrl
		);

		return $redirectUrl;
	}

	// ------------------------------------------------------------------
	// AC-14: outcome panel
	// ------------------------------------------------------------------

	/**
	 * Render the bulk-resend outcome banner from the redirect query.
	 *
	 * Skipped on screens unrelated to the order-list — only fires when the
	 * `bulk_resend_summary` query-param is present AND the active screen
	 * is the legacy or HPOS order-list.
	 */
	public function renderOutcomePanel(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect query, not a mutation.
		if ( ! isset( $_GET[ self::OUTCOME_QUERY_VAR ] ) ) {
			return;
		}

		if ( ! $this->isOrderListAdminScreen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$summaryRaw = wp_unslash( (string) $_GET[ self::OUTCOME_QUERY_VAR ] );
		$summary    = sanitize_key( $summaryRaw );

		if ( self::OUTCOME_NO_ELIGIBLE === $summary ) {
			printf(
				'<div class="notice notice-warning spreadconnect-bulk-outcome" data-panel="bulk_resend_outcome_panel"><p>%s</p></div>',
				esc_html__( 'Re-send needs at least one FAILED order in the selection.', self::TEXT_DOMAIN )
			);
			return;
		}

		if ( self::OUTCOME_OK !== $summary ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queued  = isset( $_GET['queued'] ) ? (int) $_GET['queued'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = isset( $_GET['total'] ) ? (int) $_GET['total'] : ( $queued + $skipped );

		$message = sprintf(
			/* translators: 1: queued count, 2: total selected, 3: skipped count */
			_n(
				'%1$d of %2$d re-queued · %3$d skipped (not in FAILED state)',
				'%1$d of %2$d re-queued · %3$d skipped (not in FAILED state)',
				$queued,
				self::TEXT_DOMAIN
			),
			$queued,
			$total,
			$skipped
		);

		printf(
			'<div class="notice notice-info spreadconnect-bulk-outcome" data-panel="bulk_resend_outcome_panel"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	// ------------------------------------------------------------------
	// AC-15: asset enqueue (JS + localize)
	// ------------------------------------------------------------------

	public function enqueueAssets( string $hookSuffix ): void
	{
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}

		$screenId = isset( $screen->id ) ? (string) $screen->id : '';
		if ( ! $this->isOrderListScreenId( $screenId ) ) {
			return;
		}

		$pluginDir = dirname( __DIR__, 2 );
		$jsRelPath = 'assets/js/order-list.js';
		$jsUrl     = plugins_url( $jsRelPath, $pluginDir . '/spreadconnect-pod.php' );

		wp_register_script(
			self::JS_HANDLE,
			$jsUrl,
			array( 'jquery' ),
			self::JS_VERSION,
			true
		);
		wp_enqueue_script( self::JS_HANDLE );

		wp_localize_script(
			self::JS_HANDLE,
			self::JS_OBJECT,
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( OrderActions::NONCE_ACTION ),
				'actions'         => array(
					'preflight' => self::AJAX_ACTION_PREFLIGHT,
				),
				'bulkActionSlug'  => self::BULK_ACTION_RESEND,
				'i18n'            => array(
					/* translators: 1: will-resend count, 2: will-skip count */
					'preflightSummary'  => __( 'Will re-send: %1$d · Will skip: %2$d', self::TEXT_DOMAIN ),
					'preflightZero'     => __( 'No eligible orders in the selection (FAILED state required).', self::TEXT_DOMAIN ),
					'preflightLoading'  => __( 'Calculating eligible orders…', self::TEXT_DOMAIN ),
					'preflightError'    => __( 'Pre-flight check failed. Please try again.', self::TEXT_DOMAIN ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// AC-10: pre-flight AJAX
	// ------------------------------------------------------------------

	/**
	 * `wp_ajax_spreadconnect_bulk_resend_preflight` handler.
	 *
	 * Cap+Nonce+Param ordering identical to slice-32 AC-7:
	 *   1. Nonce  → 403 on failure.
	 *   2. Cap    → 403 on failure.
	 *   3. Params → 400 on missing/empty `order_ids`.
	 *
	 * Delegates the actual classification to
	 * {@see BulkResendCoordinator::preflight()} (AC-11) and emits the
	 * result via `wp_send_json_success`.
	 */
	public function handlePreflightAjax(): void
	{
		if ( false === check_ajax_referer( OrderActions::NONCE_ACTION, '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please reload the page and try again.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', self::TEXT_DOMAIN ),
				),
				403
			);
			return;
		}

		$orderIds = $this->resolveOrderIdsFromRequest();
		if ( array() === $orderIds ) {
			wp_send_json_error(
				array(
					'message' => __( 'No order ids supplied.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		$result = $this->coordinator->preflight( $orderIds );

		wp_send_json_success(
			array(
				'will_resend'  => isset( $result['will_resend'] ) ? (int) $result['will_resend'] : 0,
				'will_skip'    => isset( $result['will_skip'] ) ? (int) $result['will_skip'] : 0,
				'eligible_ids' => isset( $result['eligible_ids'] ) && is_array( $result['eligible_ids'] )
					? array_values( array_map( 'intval', $result['eligible_ids'] ) )
					: array(),
				'skipped'      => isset( $result['skipped'] ) && is_array( $result['skipped'] )
					? $result['skipped']
					: array(),
			)
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Read and sanitize the `?sc_state=` query-var. Returns `''` for
	 * unknown values.
	 */
	private function readFilterQueryVar(): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no mutation.
		$raw = isset( $_GET[ self::FILTER_QUERY_VAR ] ) ? $_GET[ self::FILTER_QUERY_VAR ] : '';
		$raw = is_string( $raw ) ? $raw : '';

		// `sanitize_key()` lowercases — the persistent states are upper-case,
		// so we normalize against the whitelist with case-insensitive
		// matching while preserving the canonical literal.
		$lower = strtolower( $raw );
		foreach ( self::ALLOWED_FILTER_STATES as $allowed ) {
			if ( strtolower( $allowed ) === $lower ) {
				return $allowed;
			}
		}

		return '';
	}

	/**
	 * Build a `meta_query` clause for one whitelisted filter value.
	 *
	 * @return array<string, mixed>|null Clause, or null when filter is no-op.
	 */
	private function buildFilterMetaClause( string $filter ): ?array
	{
		switch ( $filter ) {
			case self::PSEUDO_STATE_PENDING:
				return array(
					'key'     => self::META_STATE,
					'compare' => 'NOT EXISTS',
				);

			case self::PSEUDO_STATE_NEEDS_ACTION:
				return array(
					'key'     => self::META_NEEDS_ACTION,
					'value'   => '1',
					'compare' => '=',
				);

			case 'submitting':
			case 'NEW':
			case 'CONFIRMED':
			case 'PROCESSED':
			case 'CANCELLED':
			case 'failed_to_submit':
				return array(
					'key'     => self::META_STATE,
					'value'   => $filter,
					'compare' => '=',
				);
		}

		return null;
	}

	/**
	 * Merge a new `meta_query` clause into an existing tree, forcing
	 * `relation=AND` on the top level (slice-33 AC-7 contract).
	 *
	 * @param array<int|string, mixed> $existing
	 * @param array<string, mixed>     $newClause
	 *
	 * @return array<int|string, mixed>
	 */
	private function mergeMetaQuery( array $existing, array $newClause ): array
	{
		if ( array() === $existing ) {
			return array(
				'relation' => 'AND',
				$newClause,
			);
		}

		// Strip any existing `relation` key (it could be 'OR') and re-set
		// to 'AND'; preserve all other clauses by integer-reindexing.
		$preservedClauses = array();
		foreach ( $existing as $key => $value ) {
			if ( 'relation' === $key ) {
				continue;
			}
			$preservedClauses[] = $value;
		}

		$preservedClauses[] = $newClause;

		return array_merge( array( 'relation' => 'AND' ), $preservedClauses );
	}

	/**
	 * Resolve a `WC_Order` from an order-id; null on missing.
	 */
	private function resolveOrder( int $orderId ): ?WC_Order
	{
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Resolve the SC backend URL for a given SC-Order-Id.
	 *
	 * Default URL source is the option
	 * `spreadconnect_sc_backend_url_template` (a literal containing the
	 * placeholder `{id}`). Default is `'#'`. The filter
	 * `spreadconnect/sc_backend_order_url` lets other plugins/themes
	 * customize the URL.
	 */
	private function resolveBackendOrderUrl( string $scOrderId ): string
	{
		$template = '';
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( 'spreadconnect_sc_backend_url_template', '' );
			$template = is_string( $option ) ? $option : '';
		}

		if ( '' === $template ) {
			$url = '#';
		} elseif ( false !== strpos( $template, '{id}' ) ) {
			$url = str_replace( '{id}', rawurlencode( $scOrderId ), $template );
		} else {
			$url = $template . rawurlencode( $scOrderId );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'spreadconnect/sc_backend_order_url', $url, $scOrderId );
			if ( is_string( $filtered ) && '' !== $filtered ) {
				$url = $filtered;
			}
		}

		return $url;
	}

	/**
	 * Read + sanitize `order_ids` from the AJAX POST body. Returns an
	 * empty array on missing / non-array / all-zero input.
	 *
	 * @return int[]
	 */
	private function resolveOrderIdsFromRequest(): array
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		if ( ! isset( $_POST['order_ids'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( $_POST['order_ids'] );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_map( 'intval', $raw );
		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);

		return $ids;
	}

	/**
	 * True when the bulk-dropdown / filter-dropdown context is the
	 * order-list. Accepts both legacy `$post_type='shop_order'` and HPOS
	 * `$screen_id='woocommerce_page_wc-orders'`.
	 */
	private function isOrderListScreenContext( string $screenOrPostType ): bool
	{
		if ( self::LEGACY_POST_TYPE === $screenOrPostType ) {
			return true;
		}

		if ( self::HPOS_SCREEN_ID === $screenOrPostType ) {
			return true;
		}

		// Some HPOS variants pass an empty string and rely on the screen
		// already being the right one — fall back to `get_current_screen()`.
		if ( '' === $screenOrPostType ) {
			return $this->isOrderListAdminScreen();
		}

		return false;
	}

	/**
	 * True when `get_current_screen()` reports an order-list context.
	 */
	private function isOrderListAdminScreen(): bool
	{
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}

		$screenId = isset( $screen->id ) ? (string) $screen->id : '';
		return $this->isOrderListScreenId( $screenId );
	}

	/**
	 * True when the given screen-id is the legacy or HPOS order-list.
	 */
	private function isOrderListScreenId( string $screenId ): bool
	{
		if ( self::LEGACY_SCREEN_ID === $screenId ) {
			return true;
		}

		if ( self::HPOS_SCREEN_ID === $screenId ) {
			return true;
		}

		// Resolve at runtime via the WC helper for environments where the
		// HPOS screen-id is hashed differently.
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$resolved = wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $resolved ) && $resolved !== '' && $resolved === $screenId ) {
				return true;
			}
		}

		return false;
	}
}
