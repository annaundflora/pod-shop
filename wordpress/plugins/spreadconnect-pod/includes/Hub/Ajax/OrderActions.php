<?php
/**
 * Admin-AJAX front-controller for the WC-Order-Edit "Spreadconnect" meta-box (slice-32).
 *
 * Five `wp_ajax_*` actions, all gated by `manage_woocommerce` and the shared
 * `spreadconnect_admin` nonce (architecture.md Z. 84):
 *
 *   - `spreadconnect_confirm_order`        → enqueue `spreadconnect/confirm_order`
 *                                            AS-job (slice-29 consumer).
 *   - `spreadconnect_cancel_order`         → enqueue `spreadconnect/cancel_order`
 *                                            AS-job (slice-29 consumer).
 *   - `spreadconnect_refresh_order_state`  → synchronous `GET /orders/{id}` +
 *                                            persist `_spreadconnect_state` /
 *                                            `_spreadconnect_last_event` HPOS-meta.
 *   - `spreadconnect_save_shipping_type`   → synchronous `POST /orders/{id}/shippingType` +
 *                                            persist `_spreadconnect_shipping_type`.
 *   - `spreadconnect_cancel_auto_confirm`  → `as_unschedule_action` for the
 *                                            slice-31 auto-confirm timer.
 *
 * Cap+Nonce+Param-Reihenfolge in **every** handler (slice-32 AC-7):
 *   1. `check_ajax_referer('spreadconnect_admin', '_ajax_nonce', false)` — 403.
 *   2. `current_user_can('manage_woocommerce')`                          — 403.
 *   3. Param validation (`order_id` int>0, `shipping_type` non-empty)    — 400.
 *
 * Out of scope (later slices):
 *   - `spreadconnect_resend_failed_op` handler — slice-38.
 *   - Auto-Confirm-Timer schedule — slice-31 (this slice only `unschedule`s).
 *   - Real `Failure\FailedOpsRepo` write — slice-37.
 *
 * Architecture references:
 *   - architecture.md "AJAX Action Inventory" Z. 152-156 (5-action table).
 *   - architecture.md Z. 84 (nonce-action `spreadconnect_admin`).
 *   - architecture.md Z. 305-315 (Order-Meta keys).
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use Throwable;
use WC_Logger;
use WC_Order;

/**
 * Stateful AJAX front-controller for the Order-Edit meta-box.
 *
 * Constructor-DI on {@see SpreadconnectClient} — production wiring uses
 * {@see self::register()} which builds defaults via the static bridges. Slice
 * 32 mirrors the slice-34 `ProductActions` adapter pattern.
 *
 * `final` per slice-32 Constraints.
 */
final class OrderActions
{
	/**
	 * Shared nonce-action name for all five AJAX handlers
	 * (architecture.md Z. 84).
	 */
	public const NONCE_ACTION = 'spreadconnect_admin';

	/**
	 * POST field carrying the nonce. WP's `check_ajax_referer()` defaults to
	 * `_ajax_nonce` when the third argument is left at the default; we name
	 * it explicitly so the localized JS can rely on the same key.
	 */
	private const NONCE_FIELD = '_ajax_nonce';

	/**
	 * Capability gate (slice-32 Constraints + AC-7).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Action-Scheduler hook + group constants (mirror of slice-29 conventions).
	 */
	private const AS_HOOK_CONFIRM = 'spreadconnect/confirm_order';
	private const AS_HOOK_CANCEL  = 'spreadconnect/cancel_order';
	private const AS_GROUP        = 'spreadconnect';

	/**
	 * HPOS Order-Meta keys (architecture.md Z. 305-315).
	 */
	private const META_ORDER_ID      = '_spreadconnect_order_id';
	private const META_STATE         = '_spreadconnect_state';
	private const META_LAST_EVENT    = '_spreadconnect_last_event';
	private const META_SHIPPING_TYPE = '_spreadconnect_shipping_type';

	/**
	 * Logger source — shared across the order-service stack so slice-37
	 * Failed-Ops dashboards filter the entire stream by one string.
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	private SpreadconnectClient $client;

	private ?WC_Logger $logger;

	public function __construct(
		SpreadconnectClient $client,
		?WC_Logger $logger = null
	) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Register all five `wp_ajax_*` actions via static bridges.
	 *
	 * Called from `Bootstrap\Plugin::init()`. Only the authenticated
	 * `wp_ajax_*` variant is registered — admin-only (no `nopriv_*`).
	 */
	public static function register(): void
	{
		add_action( 'wp_ajax_spreadconnect_confirm_order', array( self::class, 'handleConfirmStatic' ) );
		add_action( 'wp_ajax_spreadconnect_cancel_order', array( self::class, 'handleCancelStatic' ) );
		add_action( 'wp_ajax_spreadconnect_refresh_order_state', array( self::class, 'handleRefreshStateStatic' ) );
		add_action( 'wp_ajax_spreadconnect_save_shipping_type', array( self::class, 'handleSaveShippingTypeStatic' ) );
		add_action( 'wp_ajax_spreadconnect_cancel_auto_confirm', array( self::class, 'handleCancelAutoConfirmStatic' ) );
	}

	// ------------------------------------------------------------------
	// Static bridges
	// ------------------------------------------------------------------

	public static function handleConfirmStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->handleConfirm();
	}

	public static function handleCancelStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->handleCancel();
	}

	public static function handleRefreshStateStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->handleRefreshState();
	}

	public static function handleSaveShippingTypeStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->handleSaveShippingType();
	}

	public static function handleCancelAutoConfirmStatic(): void
	{
		( new self( new SpreadconnectClient() ) )->handleCancelAutoConfirm();
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * Slice-32 AC-8: enqueue the slice-29 `spreadconnect/confirm_order`
	 * AS-job after Cap+Nonce+order_id validation.
	 */
	public function handleConfirm(): void
	{
		if ( ! $this->checkNonce() ) {
			return;
		}
		if ( ! $this->checkCap() ) {
			return;
		}

		$orderId = $this->resolveOrderId();
		if ( $orderId <= 0 ) {
			$this->sendInvalidParam( __( 'Invalid order id.', self::TEXT_DOMAIN ) );
			return;
		}

		as_enqueue_async_action(
			self::AS_HOOK_CONFIRM,
			array( 'order_id' => $orderId ),
			self::AS_GROUP
		);

		wp_send_json_success( array( 'queued' => true ) );
	}

	/**
	 * Slice-32 AC-9: enqueue the slice-29 `spreadconnect/cancel_order`
	 * AS-job after Cap+Nonce+order_id validation.
	 */
	public function handleCancel(): void
	{
		if ( ! $this->checkNonce() ) {
			return;
		}
		if ( ! $this->checkCap() ) {
			return;
		}

		$orderId = $this->resolveOrderId();
		if ( $orderId <= 0 ) {
			$this->sendInvalidParam( __( 'Invalid order id.', self::TEXT_DOMAIN ) );
			return;
		}

		as_enqueue_async_action(
			self::AS_HOOK_CANCEL,
			array( 'order_id' => $orderId ),
			self::AS_GROUP
		);

		wp_send_json_success( array( 'queued' => true ) );
	}

	/**
	 * Slice-32 AC-10: synchronous `GET /orders/{id}` + persist meta.
	 *
	 *   - `SpreadconnectClient::getOrder($sc_id)` → 1 call.
	 *   - On 2xx: write `_spreadconnect_state` + `_spreadconnect_last_event`
	 *     + `$order->save()`; respond `{ok, state, last_event}`.
	 *   - On `SpreadconnectClientError` (4xx): respond `{ok:false, ...}` with
	 *     HTTP 502.
	 *   - On `SpreadconnectTransientError` (5xx/network): respond 502 (no AS
	 *     retry — admin-triggered manual call, no job to retry).
	 */
	public function handleRefreshState(): void
	{
		if ( ! $this->checkNonce() ) {
			return;
		}
		if ( ! $this->checkCap() ) {
			return;
		}

		$orderId = $this->resolveOrderId();
		if ( $orderId <= 0 ) {
			$this->sendInvalidParam( __( 'Invalid order id.', self::TEXT_DOMAIN ) );
			return;
		}

		$order = $this->resolveOrder( $orderId );
		if ( null === $order ) {
			wp_send_json_error(
				array(
					'message' => __( 'Order not found.', self::TEXT_DOMAIN ),
				),
				404
			);
			return;
		}

		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			wp_send_json_error(
				array(
					'message' => __( 'Order has not been submitted to Spreadconnect yet.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		try {
			$response = $this->client->getOrder( $scOrderId );
		} catch ( SpreadconnectClientError | SpreadconnectTransientError $e ) {
			$this->logUpstream( 'ajax_action_failed', 'refresh_order_state', $orderId, $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				502
			);
			return;
		} catch ( Throwable $e ) {
			$this->logUpstream( 'ajax_action_failed', 'refresh_order_state', $orderId, $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
			return;
		}

		$state = isset( $response['state'] ) && is_string( $response['state'] ) ? $response['state'] : '';

		$updatedAt = '';
		if ( isset( $response['updatedAt'] ) && is_string( $response['updatedAt'] ) ) {
			$updatedAt = $response['updatedAt'];
		}
		$lastEvent = '';
		if ( '' !== $updatedAt ) {
			$ts        = strtotime( $updatedAt );
			$lastEvent = sprintf( '%d:%s', false === $ts ? time() : $ts, 'Order.refreshed' );
		} else {
			$lastEvent = sprintf( '%d:%s', time(), 'Order.refreshed' );
		}

		if ( '' !== $state ) {
			$order->update_meta_data( self::META_STATE, $state );
		}
		$order->update_meta_data( self::META_LAST_EVENT, $lastEvent );
		$order->save();

		wp_send_json_success(
			array(
				'state'      => $state,
				'last_event' => $lastEvent,
			)
		);
	}

	/**
	 * Slice-32 AC-11: synchronous `POST /orders/{id}/shippingType` + persist
	 * `_spreadconnect_shipping_type` HPOS-meta on 2xx.
	 *
	 * On `SpreadconnectClientError` no meta is written.
	 */
	public function handleSaveShippingType(): void
	{
		if ( ! $this->checkNonce() ) {
			return;
		}
		if ( ! $this->checkCap() ) {
			return;
		}

		$orderId      = $this->resolveOrderId();
		$shippingType = $this->resolvePostString( 'shipping_type' );

		if ( $orderId <= 0 ) {
			$this->sendInvalidParam( __( 'Invalid order id.', self::TEXT_DOMAIN ) );
			return;
		}
		if ( '' === $shippingType ) {
			$this->sendInvalidParam( __( 'Missing shipping type.', self::TEXT_DOMAIN ) );
			return;
		}

		$order = $this->resolveOrder( $orderId );
		if ( null === $order ) {
			wp_send_json_error(
				array(
					'message' => __( 'Order not found.', self::TEXT_DOMAIN ),
				),
				404
			);
			return;
		}

		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			wp_send_json_error(
				array(
					'message' => __( 'Order has not been submitted to Spreadconnect yet.', self::TEXT_DOMAIN ),
				),
				400
			);
			return;
		}

		try {
			$this->client->setShippingType( $scOrderId, $shippingType );
		} catch ( SpreadconnectClientError | SpreadconnectTransientError $e ) {
			$this->logUpstream( 'ajax_action_failed', 'save_shipping_type', $orderId, $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				502
			);
			return;
		} catch ( Throwable $e ) {
			$this->logUpstream( 'ajax_action_failed', 'save_shipping_type', $orderId, $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
			return;
		}

		$order->update_meta_data( self::META_SHIPPING_TYPE, $shippingType );
		$order->save();

		wp_send_json_success(
			array(
				'shipping_type' => $shippingType,
			)
		);
	}

	/**
	 * Slice-32 AC-12: cancel the slice-31 auto-confirm AS-job for one order.
	 */
	public function handleCancelAutoConfirm(): void
	{
		if ( ! $this->checkNonce() ) {
			return;
		}
		if ( ! $this->checkCap() ) {
			return;
		}

		$orderId = $this->resolveOrderId();
		if ( $orderId <= 0 ) {
			$this->sendInvalidParam( __( 'Invalid order id.', self::TEXT_DOMAIN ) );
			return;
		}

		as_unschedule_action(
			self::AS_HOOK_CONFIRM,
			array( 'order_id' => $orderId ),
			self::AS_GROUP
		);

		wp_send_json_success( array( 'unscheduled' => true ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Nonce-Gate: 403 on failure (slice-32 AC-7 step 1).
	 */
	private function checkNonce(): bool
	{
		if ( false === check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
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
	 * Cap-Gate: 403 on failure (slice-32 AC-7 step 2).
	 */
	private function checkCap(): bool
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
		return true;
	}

	/**
	 * Param-Gate: 400 on missing/invalid (slice-32 AC-7 step 3).
	 */
	private function sendInvalidParam( string $message ): void
	{
		wp_send_json_error(
			array(
				'message' => $message,
			),
			400
		);
	}

	/**
	 * Read an integer `order_id` POST field. Returns `0` on missing/non-numeric.
	 */
	private function resolveOrderId(): int
	{
		if ( ! isset( $_POST['order_id'] ) ) {
			return 0;
		}

		$raw = wp_unslash( $_POST['order_id'] );
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		if ( is_string( $raw ) && '' !== $raw && ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		return 0;
	}

	/**
	 * Read a sanitized string POST field. Returns `''` on missing/non-string.
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
	 * Resolve a `WC_Order` instance via the HPOS-aware accessor.
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
	 * Emit a single WC-Logger entry on upstream failures.
	 *
	 * Source `spreadconnect-order-service` (mirror of slice-29). Tag
	 * `ajax_action_failed` is reserved for slice-37/38 dashboards.
	 */
	private function logUpstream( string $tag, string $action, int $orderId, string $message ): void
	{
		$logger = $this->logger;
		if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
		}
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log(
			'warning',
			sprintf(
				'OrderActions::%s upstream failed — order_id=%d, message=%s',
				$action,
				$orderId,
				$message
			),
			array(
				'source' => self::LOG_SOURCE,
				'tag'    => $tag,
			)
		);
	}
}
