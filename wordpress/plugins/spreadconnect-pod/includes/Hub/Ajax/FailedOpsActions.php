<?php
/**
 * Admin-AJAX front-controller for the Failed-Ops sub-page (Slice 38).
 *
 * Three `wp_ajax_*` actions, all gated by `manage_woocommerce` and the shared
 * `spreadconnect_failed_ops` nonce (slice-38 Constraints):
 *
 *   - `spreadconnect_resend_failed_op`         → re-enqueue the matching
 *                                                Action-Scheduler hook for the
 *                                                stored `op_type` and mark
 *                                                the row resolved.
 *   - `spreadconnect_dismiss_failed_op`        → mark dismissed; Server-side
 *                                                refused for `op_type='create_order'`
 *                                                because plain dismiss must
 *                                                go through the resolution
 *                                                modal.
 *   - `spreadconnect_resolve_create_order`    → 3-choice resolution flow for
 *                                                `op_type='create_order'`:
 *                                                `resend` / `cancel_wc` /
 *                                                `submitted_externally`.
 *
 * Cap+Nonce+Param-Reihenfolge (slice-38 AC-5):
 *   1. `check_ajax_referer('spreadconnect_failed_ops', 'nonce', false)` — 403.
 *   2. `current_user_can('manage_woocommerce')`                          — 403.
 *   3. Param validation (`failed_op_id` int>0)                           — 400.
 *   4. Repo lookup + business rules (`wrong_op_type`, `resolution_required`,
 *       `external_id_required`, `invalid_resolution`)                    — 422.
 *
 * WC-Order mutations (HPOS-konform, architecture.md Z. 305-315):
 *   - Status flips via `$order->update_status('cancelled', $note)`.
 *   - Meta writes via `$order->update_meta_data() + $order->save()`. NEVER
 *     `update_post_meta()` against an HPOS order id.
 *
 * Logging via {@see WcLoggerAdapter} with source {@see Sources::FAILURE} —
 * raw `error_log()` is forbidden (architecture.md Z. 687).
 *
 * Op-Type-zu-AS-Hook-Mapping (slice-37 AC-9 inverted) is exposed as a
 * `private const` for slice-40 (BulkResendCoordinator) and slice-32
 * (Order-Meta-Box Resend) to mirror.
 *
 * @package SpreadconnectPod\Hub\Ajax
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Ajax;

use SpreadconnectPod\Failure\FailedOpsRepo;
use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;
use Throwable;

/**
 * Stateful AJAX front-controller for the Failed-Ops sub-page.
 *
 * Constructor-DI on {@see FailedOpsRepo} per slice-38 Provides-Section. The
 * production wiring path is `Bootstrap\Plugin::init()` which constructs the
 * repo lazily inside the AS-listener closure (mirrors the slice-37 fix
 * pattern).
 *
 * `final` per slice-38 Constraints.
 */
final class FailedOpsActions
{
	/**
	 * Shared nonce-action name for all three AJAX handlers
	 * (slice-38 Constraints; slice-40 will reuse it).
	 */
	public const NONCE_ACTION = 'spreadconnect_failed_ops';

	/**
	 * POST field carrying the nonce. The View's `wp_localize_script` payload
	 * exposes the literal under the `nonce` key, so the JS asset POSTs it
	 * with that exact field name.
	 */
	private const NONCE_FIELD = 'nonce';

	/**
	 * Capability gate.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Action-Scheduler group (architecture.md Z. 542).
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * HPOS Order-Meta keys (architecture.md Z. 305-315).
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';
	private const META_STATE    = '_spreadconnect_state';

	/**
	 * Whitelist of accepted resolution values (slice-38 AC-12 — strict;
	 * unknown values produce a 422 with `code='invalid_resolution'`).
	 *
	 * @var list<string>
	 */
	private const RESOLUTIONS = array(
		'resend',
		'cancel_wc',
		'submitted_externally',
	);

	/**
	 * Op-Type → Action-Scheduler hook mapping (slice-37 AC-9 inverted, all
	 * 9 entries per slice-38 Provides-Section).
	 *
	 * Re-used by Slice 40 (BulkResendCoordinator) and Slice 32 (Order-Meta-Box
	 * Resend) — those slices import this class and read the same constant
	 * literal so the mapping is single-sourced.
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_TO_HOOK = array(
		'create_order'           => 'spreadconnect/create_order',
		'confirm_order'          => 'spreadconnect/confirm_order',
		'cancel_order_mirror'    => 'spreadconnect/cancel_order_mirror',
		'fetch_tracking'         => 'spreadconnect/fetch_tracking',
		'sync_article'           => 'spreadconnect/sync_article',
		'sync_catalog'           => 'spreadconnect/sync_catalog',
		'handle_article_removed' => 'spreadconnect/handle_article_removed',
		'handle_webhook'         => 'spreadconnect/process_webhook_event',
		'scheduled_stock_sync'   => 'spreadconnect/scheduled_stock_sync',
	);

	/**
	 * The injected repo. Constructor-DI per slice-38 Provides-Section.
	 */
	private FailedOpsRepo $repo;

	public function __construct( FailedOpsRepo $repo )
	{
		$this->repo = $repo;
	}

	/**
	 * Register the three `wp_ajax_*` hooks.
	 *
	 * Called from `Bootstrap\Plugin::init()` via a lazy closure — production
	 * wiring constructs the repo on hook-fire so the global `$wpdb` is always
	 * live (mirrors slice-37's lazy listener pattern). Only the authenticated
	 * `wp_ajax_*` variant is registered; admin-only.
	 *
	 * @return void
	 */
	public function register(): void
	{
		add_action(
			'wp_ajax_spreadconnect_resend_failed_op',
			array( $this, 'resend' )
		);
		add_action(
			'wp_ajax_spreadconnect_dismiss_failed_op',
			array( $this, 'dismiss' )
		);
		add_action(
			'wp_ajax_spreadconnect_resolve_create_order',
			array( $this, 'resolve' )
		);
	}

	// =========================================================================
	// Resend handler (AC-4 + AC-5)
	// =========================================================================

	/**
	 * Re-enqueue the AS-job tied to the stored op_type and mark the row
	 * `resolved`.
	 *
	 * Behaviour:
	 *   - Cap+Nonce gates (AC-5) — 403 on miss.
	 *   - `failed_op_id` int>0 (Constraints) — 400 on miss.
	 *   - Repo `findById()` returns null → 404.
	 *   - `op_type` not in {@see self::OP_TYPE_TO_HOOK} → 422
	 *      (`unknown_op_type`).
	 *   - On success: `as_enqueue_async_action($hook, $payload, 'spreadconnect')`
	 *     + `markResolved()` + `wp_send_json_success`.
	 *
	 * @return void
	 */
	public function resend(): void
	{
		if ( ! $this->checkNonceAndCap() ) {
			return;
		}

		$failedOpId = $this->resolveFailedOpId();
		if ( $failedOpId <= 0 ) {
			$this->sendInvalidId();
			return;
		}

		$row = $this->repo->findById( $failedOpId );
		if ( null === $row ) {
			wp_send_json_error(
				array(
					'code'    => 'not_found',
					'message' => __( 'Failed operation not found.', self::TEXT_DOMAIN ),
				),
				404
			);
			return;
		}

		$opType  = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';
		$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

		if ( ! isset( self::OP_TYPE_TO_HOOK[ $opType ] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'unknown_op_type',
					'message' => __( 'Unknown operation type.', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		$hook = self::OP_TYPE_TO_HOOK[ $opType ];

		as_enqueue_async_action( $hook, $payload, self::AS_GROUP );

		$this->repo->markResolved( $failedOpId );

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf( 'failed_op_resend invoked id=%d op_type=%s hook=%s', $failedOpId, $opType, $hook ),
			array(
				'failed_op_id' => $failedOpId,
				'op_type'      => $opType,
				'hook'         => $hook,
			)
		);

		wp_send_json_success(
			array(
				'message'      => __( 'Operation resent successfully', self::TEXT_DOMAIN ),
				'failed_op_id' => $failedOpId,
			)
		);
	}

	// =========================================================================
	// Dismiss handler (AC-6 + AC-7)
	// =========================================================================

	/**
	 * Plain-dismiss path — only legal for non-`create_order` ops.
	 *
	 * For `op_type='create_order'`, plain-dismiss is server-side refused
	 * (AC-7); the JS asset opens the resolution modal so the operator picks
	 * one of the three resolution choices and POSTs to
	 * `spreadconnect_resolve_create_order` instead.
	 *
	 * @return void
	 */
	public function dismiss(): void
	{
		if ( ! $this->checkNonceAndCap() ) {
			return;
		}

		$failedOpId = $this->resolveFailedOpId();
		if ( $failedOpId <= 0 ) {
			$this->sendInvalidId();
			return;
		}

		$row = $this->repo->findById( $failedOpId );
		if ( null === $row ) {
			wp_send_json_error(
				array(
					'code'    => 'not_found',
					'message' => __( 'Failed operation not found.', self::TEXT_DOMAIN ),
				),
				404
			);
			return;
		}

		$opType = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';

		if ( 'create_order' === $opType ) {
			wp_send_json_error(
				array(
					'code'    => 'resolution_required',
					'message' => __( 'create_order entries require explicit resolution', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		$this->repo->markDismissed( $failedOpId );

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf( 'failed_op_dismiss invoked id=%d op_type=%s', $failedOpId, $opType ),
			array(
				'failed_op_id' => $failedOpId,
				'op_type'      => $opType,
			)
		);

		wp_send_json_success(
			array(
				'failed_op_id' => $failedOpId,
			)
		);
	}

	// =========================================================================
	// Resolve handler (AC-8 .. AC-13)
	// =========================================================================

	/**
	 * 3-choice resolution flow for `op_type='create_order'` rows.
	 *
	 * Resolution branches:
	 *   - `resend`               — `as_enqueue_async_action('spreadconnect/create_order', payload, …)`
	 *                              + `markResolved()` (mirrors {@see self::resend()}).
	 *   - `cancel_wc`            — `wc_get_order(payload['order_id'])->update_status('cancelled', $note)`
	 *                              + `markResolved()`. NO refund-API-call.
	 *   - `submitted_externally` — write `_spreadconnect_order_id` (from `external_sc_order_id`)
	 *                              + `_spreadconnect_state='NEW'` via `update_meta_data()` +
	 *                              `save()` + Order-Note + `markResolved()`.
	 *
	 * Refusal codes (HTTP 422):
	 *   - `wrong_op_type`         — row's op_type ≠ `create_order` (AC-13).
	 *   - `invalid_resolution`    — resolution ∉ {@see self::RESOLUTIONS} (AC-12).
	 *   - `external_id_required`  — empty trimmed `external_sc_order_id` (AC-11).
	 *   - `wc_order_missing`      — `wc_get_order()` returns false (AC-9 fail).
	 *
	 * Try-Catch around the WC-Order mutation per Constraints — any
	 * `\Throwable` becomes a 500 with `code='wc_mutation_failed'`.
	 *
	 * @return void
	 */
	public function resolve(): void
	{
		if ( ! $this->checkNonceAndCap() ) {
			return;
		}

		$failedOpId = $this->resolveFailedOpId();
		if ( $failedOpId <= 0 ) {
			$this->sendInvalidId();
			return;
		}

		$row = $this->repo->findById( $failedOpId );
		if ( null === $row ) {
			wp_send_json_error(
				array(
					'code'    => 'not_found',
					'message' => __( 'Failed operation not found.', self::TEXT_DOMAIN ),
				),
				404
			);
			return;
		}

		$opType  = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';
		$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

		// AC-13: `resolve_create_order` must only run for `op_type='create_order'`.
		if ( 'create_order' !== $opType ) {
			wp_send_json_error(
				array(
					'code'    => 'wrong_op_type',
					'message' => __(
						'Resolution flow is only available for create_order entries.',
						self::TEXT_DOMAIN
					),
				),
				422
			);
			return;
		}

		// AC-12: strict whitelist; unknown values are NOT defaulted to resend.
		$resolution = $this->resolveResolutionParam();
		if ( ! in_array( $resolution, self::RESOLUTIONS, true ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_resolution',
					'message' => __( 'Unknown resolution choice.', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		$orderId = isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0;

		switch ( $resolution ) {
			case 'resend':
				$this->resolveResend( $failedOpId, $payload );
				return;

			case 'cancel_wc':
				$this->resolveCancelWc( $failedOpId, $orderId );
				return;

			case 'submitted_externally':
				$this->resolveSubmittedExternally( $failedOpId, $orderId );
				return;
		}
	}

	/**
	 * AC-8: re-enqueue the `create_order` AS-job + mark resolved.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function resolveResend( int $failedOpId, array $payload ): void
	{
		as_enqueue_async_action(
			self::OP_TYPE_TO_HOOK['create_order'],
			$payload,
			self::AS_GROUP
		);

		$this->repo->markResolved( $failedOpId );

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf( 'failed_op_resolve invoked id=%d resolution=resend', $failedOpId ),
			array(
				'failed_op_id' => $failedOpId,
				'resolution'   => 'resend',
			)
		);

		wp_send_json_success(
			array(
				'message'      => __( 'Operation resent successfully', self::TEXT_DOMAIN ),
				'failed_op_id' => $failedOpId,
				'resolution'   => 'resend',
			)
		);
	}

	/**
	 * AC-9: flip the WC-order to `cancelled` (status + note only — NO refund).
	 */
	private function resolveCancelWc( int $failedOpId, int $orderId ): void
	{
		$order = $this->resolveWcOrder( $orderId );
		if ( null === $order ) {
			wp_send_json_error(
				array(
					'code'    => 'wc_order_missing',
					'message' => __( 'WooCommerce order not found.', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		try {
			$order->update_status(
				'cancelled',
				__( 'Resolved via Failed-Ops modal — admin chose Cancel WC order', self::TEXT_DOMAIN )
			);
		} catch ( Throwable $e ) {
			WcLoggerAdapter::error(
				Sources::FAILURE,
				sprintf(
					'failed_op_resolve cancel_wc threw id=%d order_id=%d message=%s',
					$failedOpId,
					$orderId,
					$e->getMessage()
				),
				array(
					'failed_op_id' => $failedOpId,
					'order_id'     => $orderId,
					'resolution'   => 'cancel_wc',
				)
			);
			wp_send_json_error(
				array(
					'code'    => 'wc_mutation_failed',
					'message' => __( 'Failed to update WooCommerce order.', self::TEXT_DOMAIN ),
				),
				500
			);
			return;
		}

		$this->repo->markResolved( $failedOpId );

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf( 'failed_op_resolve invoked id=%d resolution=cancel_wc order_id=%d', $failedOpId, $orderId ),
			array(
				'failed_op_id' => $failedOpId,
				'order_id'     => $orderId,
				'resolution'   => 'cancel_wc',
			)
		);

		wp_send_json_success(
			array(
				'message'      => __( 'WooCommerce order cancelled.', self::TEXT_DOMAIN ),
				'failed_op_id' => $failedOpId,
				'resolution'   => 'cancel_wc',
			)
		);
	}

	/**
	 * AC-10 + AC-11: write `_spreadconnect_order_id` + `_spreadconnect_state='NEW'`
	 * via `update_meta_data() + save()` (HPOS-konform), append an Order-Note
	 * mentioning the external SC-OrderID, and mark resolved.
	 *
	 * Empty/whitespace-only `external_sc_order_id` → 422
	 * (`code='external_id_required'`) BEFORE any meta write.
	 */
	private function resolveSubmittedExternally( int $failedOpId, int $orderId ): void
	{
		$externalIdRaw = isset( $_POST['external_sc_order_id'] ) ? wp_unslash( $_POST['external_sc_order_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verified at handler entry.
		$externalId    = is_string( $externalIdRaw )
			? trim( (string) sanitize_text_field( $externalIdRaw ) )
			: '';

		if ( '' === $externalId ) {
			wp_send_json_error(
				array(
					'code'    => 'external_id_required',
					'message' => __( 'External SC-OrderID is required for this resolution.', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		$order = $this->resolveWcOrder( $orderId );
		if ( null === $order ) {
			wp_send_json_error(
				array(
					'code'    => 'wc_order_missing',
					'message' => __( 'WooCommerce order not found.', self::TEXT_DOMAIN ),
				),
				422
			);
			return;
		}

		try {
			$order->update_meta_data( self::META_ORDER_ID, $externalId );
			$order->update_meta_data( self::META_STATE, 'NEW' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: external SC-OrderID */
					__(
						'Submitted manually outside plugin (SC-OrderID: %s)',
						self::TEXT_DOMAIN
					),
					$externalId
				)
			);
		} catch ( Throwable $e ) {
			WcLoggerAdapter::error(
				Sources::FAILURE,
				sprintf(
					'failed_op_resolve submitted_externally threw id=%d order_id=%d message=%s',
					$failedOpId,
					$orderId,
					$e->getMessage()
				),
				array(
					'failed_op_id' => $failedOpId,
					'order_id'     => $orderId,
					'resolution'   => 'submitted_externally',
				)
			);
			wp_send_json_error(
				array(
					'code'    => 'wc_mutation_failed',
					'message' => __( 'Failed to record external SC-OrderID.', self::TEXT_DOMAIN ),
				),
				500
			);
			return;
		}

		$this->repo->markResolved( $failedOpId );

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf(
				'failed_op_resolve invoked id=%d resolution=submitted_externally order_id=%d external_sc_order_id=%s',
				$failedOpId,
				$orderId,
				$externalId
			),
			array(
				'failed_op_id'         => $failedOpId,
				'order_id'             => $orderId,
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => $externalId,
			)
		);

		wp_send_json_success(
			array(
				'message'              => __(
					'External SC-OrderID recorded.',
					self::TEXT_DOMAIN
				),
				'failed_op_id'         => $failedOpId,
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => $externalId,
			)
		);
	}

	// =========================================================================
	// Shared gate / param helpers
	// =========================================================================

	/**
	 * Cap+Nonce-gate. Emits a uniform 403 + returns false on miss; returns
	 * true on pass.
	 *
	 * Gate-Reihenfolge per slice-38 AC-5: nonce first (cheap), then cap.
	 */
	private function checkNonceAndCap(): bool
	{
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __(
						'Security check failed. Please reload the page and try again.',
						self::TEXT_DOMAIN
					),
				),
				403
			);
			return false;
		}

		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __(
						'You do not have permission to perform this action.',
						self::TEXT_DOMAIN
					),
				),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Read `failed_op_id` from `$_POST` and run it through `absint`.
	 *
	 * Returns `0` when the value is missing / non-numeric / negative — the
	 * caller maps that to a 400 via {@see self::sendInvalidId()}.
	 */
	private function resolveFailedOpId(): int
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verified by checkNonceAndCap().
		if ( ! isset( $_POST['failed_op_id'] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return absint( wp_unslash( $_POST['failed_op_id'] ) );
	}

	/**
	 * Read `resolution` from `$_POST`, sanitise to a known-charset string and
	 * return it. Membership in {@see self::RESOLUTIONS} is enforced by the
	 * caller (so that the literal can flow through to `code='invalid_resolution'`).
	 */
	private function resolveResolutionParam(): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verified by checkNonceAndCap().
		if ( ! isset( $_POST['resolution'] ) || ! is_string( $_POST['resolution'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = sanitize_text_field( wp_unslash( $_POST['resolution'] ) );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Uniform 400 for missing / 0-ish `failed_op_id`.
	 */
	private function sendInvalidId(): void
	{
		wp_send_json_error(
			array(
				'code'    => 'invalid_id',
				'message' => __( 'Invalid failed_op_id.', self::TEXT_DOMAIN ),
			),
			400
		);
	}

	/**
	 * Resolve a WC-Order via `wc_get_order()`; returns null when WC returns
	 * `false` (deleted / never existed).
	 *
	 * @return \WC_Order|null
	 */
	private function resolveWcOrder( int $orderId ): ?\WC_Order
	{
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );

		return ( false === $order || null === $order || ! is_object( $order ) )
			? null
			: $order;
	}
}
