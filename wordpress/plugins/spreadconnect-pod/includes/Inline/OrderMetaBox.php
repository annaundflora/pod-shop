<?php
/**
 * WC-Order-Edit "Spreadconnect" sidebar meta-box (slice-32).
 *
 * The sole admin-UX surface for the manual order-lifecycle on the
 * WC-Order-Edit screen. Rendered in the sidebar (`'side'` context) on BOTH
 * the HPOS screen `'woocommerce_page_wc-orders'` and the legacy
 * `'shop_order'` screen — `register()` adapts to the `add_meta_boxes`-hook
 * argument so a single registration call covers both screens.
 *
 * The meta-box markup contains five `data-block`-qualified containers
 * (architecture.md "Service Map" Z. 395 + Wireframes Screen 11):
 *
 *   1. `data-block="state"`            — SC-OrderID + state-badge + last-event
 *                                         timestamp + Refresh-button.
 *   2. `data-block="shipping-type"`    — Lazy-load dropdown + Save-button.
 *   3. `data-block="actions"`          — State-aware Confirm / Cancel /
 *                                         Refresh / Resend buttons.
 *   4. `data-block="shipments"`        — Tracking-link OR placeholder
 *                                         (purely meta-driven).
 *   5. `data-block="webhook-activity"` — Last-5 inbound events for the order
 *                                         (`WebhookLogRepo::findRecentForOrder`).
 *
 * Render-pfad invariants (slice-32 Constraints):
 *   - **No `SpreadconnectClient` calls**. The shipping-types dropdown is
 *     populated lazily by the JS via AJAX on focus (AC-3).
 *   - All Order-Meta reads via `WC_Order::get_meta()` (HPOS-aware,
 *     architecture.md Z. 637).
 *   - Markup-output via `printf()` with `esc_*()` wrappers — no raw
 *     interpolation.
 *
 * Mount-points: {@see \SpreadconnectPod\Bootstrap\Plugin::init()} registers
 *   - `add_meta_boxes` -> {@see self::registerOnAddMetaBoxes()}
 *   - `admin_enqueue_scripts` -> {@see self::enqueueAssets()}
 *
 * Architecture references:
 *   - architecture.md Z. 395 (`Inline\OrderMetaBox` adapter).
 *   - architecture.md Z. 305-315 (Order-Meta keys).
 *   - architecture.md Z. 152-156 (5 AJAX actions consumed by JS).
 *   - architecture.md Z. 641 (WC dual hook-sets — HPOS + legacy).
 *   - wireframes.md Screen 11 (layout).
 *
 * @package SpreadconnectPod\Inline
 */

declare(strict_types=1);

namespace SpreadconnectPod\Inline;

use SpreadconnectPod\Hub\Ajax\OrderActions;
use SpreadconnectPod\Webhook\WebhookLogRepo;
use WC_Order;
use WP_Post;

/**
 * Stateless renderer for the WC-Order-Edit "Spreadconnect" meta-box.
 *
 * `final` per slice-32 Constraints. All entry-points are static so the
 * class can act directly as a WP-hook callable.
 */
final class OrderMetaBox
{
	/**
	 * WP meta-box ID.
	 */
	public const META_BOX_ID = 'spreadconnect_order_meta_box';

	/**
	 * HPOS Order-Meta keys (architecture.md Z. 305-315).
	 */
	private const META_ORDER_ID       = '_spreadconnect_order_id';
	private const META_STATE          = '_spreadconnect_state';
	private const META_LAST_EVENT     = '_spreadconnect_last_event';
	private const META_SHIPPING_TYPE  = '_spreadconnect_shipping_type';
	private const META_TRACKING_NO    = '_spreadconnect_tracking_number';
	private const META_TRACKING_URL   = '_spreadconnect_tracking_url';

	/**
	 * Persistent-state literals used for visibility branching (mirror of
	 * `OrderStateMachine` constants — duplicated here to avoid an Inline →
	 * Order layer dependency on a class only meant for state-machine writes).
	 */
	private const STATE_NEW              = 'NEW';
	private const STATE_CONFIRMED        = 'CONFIRMED';
	private const STATE_PROCESSED        = 'PROCESSED';
	private const STATE_CANCELLED        = 'CANCELLED';
	private const STATE_FAILED_TO_SUBMIT = 'failed_to_submit';

	/**
	 * Webhook-Activity block: how many entries the block tries to display
	 * (slice-32 AC-5 — pad to 5 slots when fewer rows exist).
	 */
	private const WEBHOOK_ACTIVITY_LIMIT = 5;

	/**
	 * JS-asset handle / localized object name (slice-32 AC-13).
	 */
	private const JS_HANDLE  = 'spreadconnect-order-meta-box';
	private const JS_OBJECT  = 'SpreadconnectOrderMetaBox';
	private const JS_VERSION = '1.0.0';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * The HPOS Order-Edit screen-id WC ships in WC ≥ 8.2.
	 *
	 * Resolved at runtime via `wc_get_page_screen_id('shop-order')` when the
	 * helper is available; the literal is the fallback (architecture.md
	 * Z. 641 + slice-32 AC-1).
	 */
	private const HPOS_SCREEN_ID = 'woocommerce_page_wc-orders';

	/**
	 * The legacy custom-post-type screen-id (pre-HPOS sites and HPOS sites
	 * that still have legacy enabled).
	 */
	private const LEGACY_SCREEN_ID = 'shop_order';

	// ------------------------------------------------------------------
	// Hook callbacks
	// ------------------------------------------------------------------

	/**
	 * Register the meta-box on BOTH the HPOS and legacy Order-Edit screens
	 * (slice-32 AC-1).
	 *
	 * Wired to `add_meta_boxes` (no priority/args mod). On screens unrelated
	 * to orders (e.g. `'product'`) this method is a no-op — `add_meta_box()`
	 * is only called for the two known order-screen-ids.
	 *
	 * @param string $screenIdOrPostType The first argument WP / WC passes to
	 *                                   the `add_meta_boxes` action. WP core
	 *                                   passes the `post_type`; WC's HPOS
	 *                                   wrapper passes the screen-id. Both
	 *                                   reach this callback unchanged.
	 */
	public static function registerOnAddMetaBoxes( string $screenIdOrPostType ): void
	{
		$hposScreenId = self::resolveHposScreenId();

		if (
			$screenIdOrPostType !== self::LEGACY_SCREEN_ID
			&& $screenIdOrPostType !== $hposScreenId
		) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Spreadconnect', self::TEXT_DOMAIN ),
			array( self::class, 'render' ),
			$screenIdOrPostType,
			'side',
			'default'
		);
	}

	/**
	 * Enqueue the meta-box JS on Order-Edit screens only (slice-32 AC-13).
	 *
	 * @param string $hookSuffix Current admin screen hook (e.g. `post.php`,
	 *                           `woocommerce_page_wc-orders`).
	 */
	public static function enqueueAssets( string $hookSuffix ): void
	{
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}

		$screenId = isset( $screen->id ) ? (string) $screen->id : '';
		$postType = isset( $screen->post_type ) ? (string) $screen->post_type : '';

		$hposScreenId = self::resolveHposScreenId();

		$onLegacy = ( self::LEGACY_SCREEN_ID === $postType && ( 'post.php' === $hookSuffix || 'post-new.php' === $hookSuffix ) );
		$onHpos   = ( $hposScreenId === $screenId );

		if ( ! $onLegacy && ! $onHpos ) {
			return;
		}

		$pluginDir = dirname( __DIR__, 2 );
		$jsRelPath = 'assets/js/order-meta-box.js';
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( OrderActions::NONCE_ACTION ),
				'actions' => array(
					'confirm_order'        => 'spreadconnect_confirm_order',
					'cancel_order'         => 'spreadconnect_cancel_order',
					'refresh_order_state'  => 'spreadconnect_refresh_order_state',
					'save_shipping_type'   => 'spreadconnect_save_shipping_type',
					'cancel_auto_confirm'  => 'spreadconnect_cancel_auto_confirm',
					'resend_failed_op'     => 'spreadconnect_resend_failed_op',
				),
				'i18n'    => array(
					'confirmCancel'           => __( 'Cancel this order in Spreadconnect?', self::TEXT_DOMAIN ),
					'confirmCancelAutoConfirm' => __( 'Cancel the scheduled auto-confirm?', self::TEXT_DOMAIN ),
					'errorGeneric'            => __( 'Action failed. Please try again.', self::TEXT_DOMAIN ),
					'loadingShippingTypes'    => __( 'Loading shipping types...', self::TEXT_DOMAIN ),
				),
			)
		);
	}

	/**
	 * Render the meta-box body.
	 *
	 * Callback signature accepts both `\WC_Order` (HPOS — the WC-side
	 * registration on `'woocommerce_page_wc-orders'` passes the order
	 * directly) and `\WP_Post` (legacy — WP-core passes the post). Both are
	 * normalised through `wc_get_order()`.
	 *
	 * @param WC_Order|WP_Post|int|mixed $context HPOS WC_Order, legacy
	 *                                            WP_Post, or an order-id.
	 */
	public static function render( $context ): void
	{
		$order = self::normalizeOrder( $context );
		if ( null === $order ) {
			printf(
				'<p class="sc-meta-box-error">%s</p>',
				esc_html__( 'Order not available.', self::TEXT_DOMAIN )
			);
			return;
		}

		$orderId      = (int) $order->get_id();
		$scOrderId    = self::readMetaString( $order, self::META_ORDER_ID );
		$state        = self::readMetaString( $order, self::META_STATE );
		$lastEvent    = self::readMetaString( $order, self::META_LAST_EVENT );
		$shippingType = self::readMetaString( $order, self::META_SHIPPING_TYPE );
		$trackingNo   = self::readMetaString( $order, self::META_TRACKING_NO );
		$trackingUrl  = self::readMetaString( $order, self::META_TRACKING_URL );

		?>
		<div
			class="spreadconnect-order-meta-box"
			data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
		>
			<?php
			self::renderStateBlock( $orderId, $scOrderId, $state, $lastEvent );
			self::renderShippingBlock( $orderId, $shippingType, $state );
			self::renderActionsBlock( $orderId, $state, $shippingType );
			self::renderShipmentsBlock( $trackingNo, $trackingUrl );
			self::renderWebhookActivityBlock( $scOrderId );
			?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Block renderers
	// ------------------------------------------------------------------

	/**
	 * Block 1: SC-OrderID + state-badge + last-event-timestamp + Refresh.
	 *
	 * Slice-32 AC-2.
	 */
	private static function renderStateBlock( int $orderId, string $scOrderId, string $state, string $lastEvent ): void
	{
		$stateLabel = '' === $state ? 'pending' : $state;
		$cssState   = '' === $state ? 'pending' : $state;

		$lastTime = self::formatLastEventTime( $lastEvent );

		?>
		<div class="sc-block sc-block-state" data-block="state">
			<p class="sc-state-line">
				<?php if ( '' !== $scOrderId ) : ?>
					<a
						class="sc-order-id-link"
						href="<?php echo esc_url( 'https://app.spreadconnect.com/orders/' . rawurlencode( $scOrderId ) ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					><?php echo esc_html( 'SC-' . $scOrderId ); ?></a>
				<?php else : ?>
					<span class="sc-order-id-empty"><?php esc_html_e( 'Not yet submitted', self::TEXT_DOMAIN ); ?></span>
				<?php endif; ?>
				<span
					class="spreadconnect-state-badge spreadconnect-state-<?php echo esc_attr( $cssState ); ?>"
					data-state="<?php echo esc_attr( $stateLabel ); ?>"
				><?php echo esc_html( $stateLabel ); ?></span>
			</p>
			<p class="sc-last-event">
				<?php esc_html_e( 'Last action:', self::TEXT_DOMAIN ); ?>
				<span class="sc-last-event-time"><?php echo esc_html( '' === $lastTime ? '—' : $lastTime ); ?></span>
			</p>
			<p class="sc-state-actions">
				<button
					type="button"
					class="button button-secondary sc-action"
					data-action="refresh_order_state"
					data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
				><?php esc_html_e( 'Refresh State', self::TEXT_DOMAIN ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Block 2: Lazy-loaded shipping-type dropdown + Save-button.
	 *
	 * Slice-32 AC-3. Renders ONE option (the current value or a placeholder)
	 * with `data-loaded="false"` so the JS knows to fetch the full list on
	 * first focus. NO synchronous `getShippingTypes()` call here.
	 */
	private static function renderShippingBlock( int $orderId, string $shippingType, string $state ): void
	{
		$disabled = (
			self::STATE_CONFIRMED === $state
			|| self::STATE_PROCESSED === $state
			|| self::STATE_CANCELLED === $state
		);

		$wrapperAttrs = $disabled ? ' data-disabled="true"' : '';
		?>
		<div class="sc-block sc-block-shipping"<?php echo $wrapperAttrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<h4><?php esc_html_e( 'Shipping Type', self::TEXT_DOMAIN ); ?></h4>
			<p>
				<label for="sc-shipping-type-<?php echo esc_attr( (string) $orderId ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Shipping Type', self::TEXT_DOMAIN ); ?>
				</label>
				<select
					id="sc-shipping-type-<?php echo esc_attr( (string) $orderId ); ?>"
					class="sc-shipping-type-select widefat"
					data-block="shipping-type"
					data-loaded="false"
					data-current="<?php echo esc_attr( $shippingType ); ?>"
					<?php echo $disabled ? 'disabled="disabled"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				>
					<option value="<?php echo esc_attr( $shippingType ); ?>">
						<?php
						if ( '' === $shippingType ) {
							esc_html_e( '— Choose a shipping type —', self::TEXT_DOMAIN );
						} else {
							echo esc_html( $shippingType );
						}
						?>
					</option>
				</select>
			</p>
			<p>
				<button
					type="button"
					class="button button-secondary sc-action"
					data-action="save_shipping_type"
					data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
					<?php echo $disabled ? 'disabled="disabled"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				><?php esc_html_e( 'Save Shipping Type', self::TEXT_DOMAIN ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Block 3: state-aware action buttons (Confirm / Cancel / Refresh / Resend).
	 *
	 * Slice-32 AC-4 visibility matrix:
	 *   - NEW + shipping-type-set    -> Confirm enabled, Cancel enabled, Refresh
	 *   - NEW (no shipping-type)     -> Confirm disabled (aria-disabled, title)
	 *   - CONFIRMED / PROCESSED      -> Refresh only
	 *   - CANCELLED                  -> Refresh only
	 *   - failed_to_submit           -> Resend prominent + Refresh
	 */
	private static function renderActionsBlock( int $orderId, string $state, string $shippingType ): void
	{
		$isNew         = ( self::STATE_NEW === $state );
		$isConfirmed   = ( self::STATE_CONFIRMED === $state );
		$isProcessed   = ( self::STATE_PROCESSED === $state );
		$isCancelled   = ( self::STATE_CANCELLED === $state );
		$isFailed      = ( self::STATE_FAILED_TO_SUBMIT === $state );
		$hasShipping   = ( '' !== $shippingType );

		$showConfirm    = $isNew;
		$showCancel     = $isNew;
		$showRefresh    = ( $isNew || $isConfirmed || $isProcessed || $isCancelled || $isFailed );
		$showResend     = $isFailed;
		$showCancelAuto = $isNew;

		$confirmDisabled = ( $isNew && ! $hasShipping );
		?>
		<div class="sc-block sc-block-actions" data-block="actions">
			<h4><?php esc_html_e( 'Actions', self::TEXT_DOMAIN ); ?></h4>
			<p class="sc-action-buttons">
				<?php if ( $showConfirm ) : ?>
					<button
						type="button"
						class="button button-primary sc-action"
						data-action="confirm_order"
						data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
						<?php if ( $confirmDisabled ) : ?>
							aria-disabled="true"
							disabled="disabled"
							title="<?php echo esc_attr__( 'Set shipping type first', self::TEXT_DOMAIN ); ?>"
						<?php endif; ?>
					><?php esc_html_e( 'Confirm', self::TEXT_DOMAIN ); ?></button>
				<?php endif; ?>
				<?php if ( $showCancel ) : ?>
					<button
						type="button"
						class="button button-secondary sc-action"
						data-action="cancel_order"
						data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
					><?php esc_html_e( 'Cancel', self::TEXT_DOMAIN ); ?></button>
				<?php endif; ?>
				<?php if ( $showRefresh ) : ?>
					<button
						type="button"
						class="button button-secondary sc-action"
						data-action="refresh_order_state"
						data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
					><?php esc_html_e( 'Refresh', self::TEXT_DOMAIN ); ?></button>
				<?php endif; ?>
				<?php if ( $showResend ) : ?>
					<button
						type="button"
						class="button button-primary sc-action sc-action-resend"
						data-action="resend_failed_op"
						data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
					><?php esc_html_e( 'Resend', self::TEXT_DOMAIN ); ?></button>
				<?php endif; ?>
				<?php if ( $showCancelAuto ) : ?>
					<button
						type="button"
						class="button-link sc-action sc-action-cancel-auto"
						data-action="cancel_auto_confirm"
						data-order-id="<?php echo esc_attr( (string) $orderId ); ?>"
					><?php esc_html_e( 'Cancel auto-confirm', self::TEXT_DOMAIN ); ?></button>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Block 4: Shipments — purely meta-driven (slice-32 AC-6).
	 *
	 * If `_spreadconnect_tracking_number` AND `_spreadconnect_tracking_url`
	 * are non-empty: render a single linked entry. Otherwise: placeholder
	 * "no shipments recorded".
	 */
	private static function renderShipmentsBlock( string $trackingNo, string $trackingUrl ): void
	{
		$hasTracking = ( '' !== $trackingNo && '' !== $trackingUrl );
		?>
		<div class="sc-block sc-block-shipments" data-block="shipments">
			<h4><?php esc_html_e( 'Shipments', self::TEXT_DOMAIN ); ?></h4>
			<?php if ( $hasTracking ) : ?>
				<ul class="sc-shipments-list">
					<li>
						<a
							href="<?php echo esc_url( $trackingUrl ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						><?php echo esc_html( $trackingNo ); ?></a>
					</li>
				</ul>
			<?php else : ?>
				<p class="sc-shipments-empty description">
					<?php esc_html_e( 'no shipments recorded', self::TEXT_DOMAIN ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Block 5: last 5 webhook events for this SC-Order (slice-32 AC-5).
	 *
	 * Calls `WebhookLogRepo::findRecentForOrder($scOrderId, 5)`. Pads to 5
	 * `<li>` slots with `—` placeholders when fewer rows exist. Skipped
	 * entirely (Repo NOT called) when `_spreadconnect_order_id` is empty.
	 */
	private static function renderWebhookActivityBlock( string $scOrderId ): void
	{
		?>
		<div class="sc-block sc-block-webhook-activity" data-block="webhook-activity">
			<h4><?php esc_html_e( 'Webhook Activity (last 5)', self::TEXT_DOMAIN ); ?></h4>
			<?php if ( '' === $scOrderId ) : ?>
				<p class="sc-webhook-empty description">
					<?php esc_html_e( 'Not yet submitted', self::TEXT_DOMAIN ); ?>
				</p>
			<?php else : ?>
				<ul class="sc-webhook-activity-list">
					<?php
					$rows = WebhookLogRepo::findRecentForOrder( $scOrderId, self::WEBHOOK_ACTIVITY_LIMIT );

					$count = 0;
					foreach ( $rows as $row ) {
						if ( $count >= self::WEBHOOK_ACTIVITY_LIMIT ) {
							break;
						}
						$count++;
						self::renderWebhookActivityRow( $row );
					}
					for ( $i = $count; $i < self::WEBHOOK_ACTIVITY_LIMIT; $i++ ) {
						?>
						<li class="sc-webhook-activity-row sc-webhook-activity-empty">
							<span class="sc-webhook-activity-placeholder">—</span>
						</li>
						<?php
					}
					?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one webhook-activity row from a repo-row assoc array.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function renderWebhookActivityRow( array $row ): void
	{
		$eventType        = isset( $row['event_type'] ) && is_string( $row['event_type'] ) ? $row['event_type'] : '';
		$receivedAt       = isset( $row['received_at'] ) && is_string( $row['received_at'] ) ? $row['received_at'] : '';
		$processingStatus = isset( $row['processing_status'] ) && is_string( $row['processing_status'] ) ? $row['processing_status'] : '';

		$receivedTs    = '' === $receivedAt ? 0 : (int) strtotime( $receivedAt );
		$receivedLabel = $receivedTs > 0 ? wp_date( 'H:i:s', $receivedTs ) : $receivedAt;
		?>
		<li class="sc-webhook-activity-row">
			<span class="sc-webhook-event-type"><?php echo esc_html( $eventType ); ?></span>
			<span class="sc-webhook-received"><?php echo esc_html( (string) $receivedLabel ); ?></span>
			<span
				class="sc-webhook-status sc-webhook-status-<?php echo esc_attr( '' === $processingStatus ? 'unknown' : $processingStatus ); ?>"
			><?php echo esc_html( '' === $processingStatus ? '—' : $processingStatus ); ?></span>
		</li>
		<?php
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve the HPOS Order-Edit screen-id at runtime.
	 *
	 * `wc_get_page_screen_id('shop-order')` is the canonical helper introduced
	 * with HPOS; for environments where it is unavailable we fall back to the
	 * literal `'woocommerce_page_wc-orders'` (architecture.md Z. 641).
	 */
	private static function resolveHposScreenId(): string
	{
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$resolved = wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				return $resolved;
			}
		}
		return self::HPOS_SCREEN_ID;
	}

	/**
	 * Normalise heterogeneous `add_meta_box`-callback arguments into a
	 * `WC_Order` instance via `wc_get_order()`.
	 *
	 * Accepts: `WC_Order` (HPOS direct), `WP_Post` (legacy), `int` (order-id).
	 *
	 * @param mixed $context
	 */
	private static function normalizeOrder( $context ): ?WC_Order
	{
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		if ( $context instanceof WC_Order ) {
			return $context;
		}

		$order = wc_get_order( $context );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Read a string-typed Order-Meta value, fallback `''`.
	 */
	private static function readMetaString( WC_Order $order, string $key ): string
	{
		$value = $order->get_meta( $key );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Format the `_spreadconnect_last_event` value (`'<unix>:<event-type>'`)
	 * into a `H:i:s` localised time.
	 *
	 * Returns `''` when the meta is empty or the prefix is not numeric.
	 */
	private static function formatLastEventTime( string $lastEvent ): string
	{
		if ( '' === $lastEvent ) {
			return '';
		}

		$colon = strpos( $lastEvent, ':' );
		$tsRaw = false === $colon ? $lastEvent : substr( $lastEvent, 0, $colon );
		if ( ! ctype_digit( $tsRaw ) ) {
			return '';
		}

		$ts = (int) $tsRaw;
		if ( $ts <= 0 ) {
			return '';
		}

		return (string) wp_date( 'H:i:s', $ts );
	}
}
