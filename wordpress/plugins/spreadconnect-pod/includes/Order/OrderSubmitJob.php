<?php
/**
 * Action-Scheduler handler for `spreadconnect/create_order`.
 *
 * Implements the outbound `POST /orders` job from architecture.md
 * "Business Logic Flow — Outbound Order Submit (Flow C)" Z. 401-430:
 *   1. Pre-check `_spreadconnect_order_id` meta — bail when already set
 *      (race-safe: webhook or duplicate-job may have written it between
 *      enqueue and execution).
 *   2. CAS `''` -> `submitting` via {@see OrderStateMachine}. CAS-loss
 *      means another worker/webhook beat us — bail without retry.
 *   3. Build {@see OrderCreate} DTO from the WC order (validation throws
 *      `\InvalidArgumentException` -> permanent-fail path, mirroring 4xx).
 *   4. Call {@see SpreadconnectClient::createOrder()}. Three branches:
 *      - 2xx: persist `_spreadconnect_order_id`, CAS `submitting -> NEW`
 *        (CAS-loss is OK — race-aware order-note); private order-note;
 *        return cleanly.
 *      - 4xx ({@see SpreadconnectClientError}): CAS `submitting ->
 *        failed_to_submit`; emit failed-ops logging stub
 *        (`failed_op_pending_record`); private order-note; do NOT rethrow
 *        (AS must not retry permanent failures).
 *      - 5xx / 429-after-inner-retry / network ({@see
 *        SpreadconnectTransientError}): leave state at `submitting`,
 *        rethrow unchanged so AS applies the 1m/5m/15m retry cascade.
 *
 * The DTO build helper validates via Slice-09 factories ({@see
 * Address::fromArray} + {@see OrderItem::fromArray} + {@see
 * OrderCreate::fromArray}). Validation failures
 * (`\InvalidArgumentException`) are treated as permanent-fail: state
 * advances to `failed_to_submit` and a failed-ops record is logged.
 *
 * Out of scope (later slices):
 *   - Auto-Confirm timer schedule on success — slice-31.
 *   - Real `Failure\FailedOpsRepo` insertion — slice-37 (the retry-policy
 *     listener consumes the `failed_op_pending_record` log tag).
 *   - Pre-submit shipping-type pre-check — slice-29.
 *   - `FailureNotifier` / Admin-Notice surface — slice-39.
 *
 * @package SpreadconnectPod\Order
 */

declare(strict_types=1);

namespace SpreadconnectPod\Order;

use InvalidArgumentException;
use SpreadconnectPod\Api\Dto\Address;
use SpreadconnectPod\Api\Dto\OrderCreate;
use SpreadconnectPod\Api\Dto\OrderItem;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use WC_Logger;
use WC_Order;

/**
 * Action-Scheduler hook handler for the `spreadconnect/create_order` job.
 *
 * Marked `final` per architecture decision (Service Map Z. 366 —
 * Application Layer; not extended). Constructor-injectable with the
 * SC-API client, the state-machine and an optional `WC_Logger`. All
 * collaborators are passed in so the test surface can mock every
 * external boundary.
 */
final class OrderSubmitJob
{
	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID set by the
	 * 2xx-path. Mirrored from {@see OrderHandler} as a local copy to keep
	 * this class independent of the handler's surface.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

	/**
	 * Logger source string for `wc_get_logger()` — shared with
	 * {@see OrderHandler} and {@see OrderStateMachine} so Failed-Ops
	 * dashboards can filter the entire order-service log stream.
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * Logging tag the slice-37 `RetryPolicyListener` will pivot on to
	 * insert real `wp_spreadconnect_failed_ops` rows. Until then the
	 * 4xx-path emits an `error`-level log entry carrying this tag in the
	 * context payload.
	 */
	private const LOG_TAG_FAILED_OP_PENDING = 'failed_op_pending_record';

	/**
	 * `op_type` value recorded by {@see Failure\FailedOpsRepo} (slice-37)
	 * for failed `POST /orders` submissions. The string literal is
	 * contract-bound — slice-32/33/38 resend-paths filter on this value.
	 */
	private const OP_TYPE_CREATE_ORDER = 'create_order';

	/**
	 * `related_entity_type` value paired with the WC-Order ID for the
	 * `wp_spreadconnect_failed_ops` row.
	 */
	private const RELATED_ENTITY_ORDER = 'order';

	private SpreadconnectClient $client;

	private OrderStateMachine $stateMachine;

	private ?WC_Logger $logger;

	/**
	 * @param SpreadconnectClient $client       SC-API HTTP client (slice-10).
	 * @param OrderStateMachine   $stateMachine CAS service for the
	 *                                          `_spreadconnect_state` meta
	 *                                          (slice-27).
	 * @param WC_Logger|null      $logger       Optional logger override.
	 */
	public function __construct(
		SpreadconnectClient $client,
		OrderStateMachine $stateMachine,
		?WC_Logger $logger = null
	) {
		$this->client       = $client;
		$this->stateMachine = $stateMachine;
		$this->logger       = $logger;
	}

	/**
	 * Action-Scheduler entry point. AS dispatches the registered hook with
	 * the args-array as the first parameter; this method validates the
	 * payload, loads the WC-Order and dispatches into {@see self::run()}.
	 *
	 * Invalid payloads (missing / non-positive `order_id`, or
	 * `wc_get_order()` returning falsy) produce a still-and-silent return
	 * to prevent an AS retry-loop on a permanently broken job.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['order_id' => int]`.
	 */
	public function handle( array $args ): void
	{
		$orderId = (int) ( $args['order_id'] ?? 0 );
		if ( $orderId <= 0 ) {
			$this->log(
				'warning',
				'OrderSubmitJob: invalid args — missing or non-positive order_id; bailing.'
			);
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			// Defensive: WC not loaded. Bail silently — the AS-runner
			// will retry on the next request once WC is back.
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! $order instanceof WC_Order ) {
			$this->log(
				'warning',
				sprintf(
					'OrderSubmitJob: wc_get_order(%d) returned no WC_Order; bailing.',
					$orderId
				)
			);
			return;
		}

		$this->run( $order );
	}

	/**
	 * Body of the job once the `WC_Order` has been resolved.
	 *
	 * Split out so future slices (slice-32 Resend-AJAX, slice-38 Failed-Ops
	 * resend) can call into the same flow without re-resolving the order
	 * from `wc_get_order()`.
	 */
	private function run( WC_Order $order ): void
	{
		$orderId = (int) $order->get_id();

		// AC-7: Internal idempotency. If `_spreadconnect_order_id` is set,
		// a previous run / parallel webhook already submitted this order.
		// Skip ALL side-effects — no API call, no state mutation, no note.
		$existingScOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' !== $existingScOrderId ) {
			$this->log(
				'info',
				sprintf(
					'OrderSubmitJob: job skipped, order already submitted — order_id=%d, sc_order_id=%s',
					$orderId,
					$existingScOrderId
				)
			);
			return;
		}

		// State-transition `pending` (no meta row) -> `submitting`. CAS-loss
		// here means another worker raced ahead — bail silently so we do
		// not double-submit.
		$casOk = $this->stateMachine->compareAndSet(
			$order,
			'',
			OrderStateMachine::STATE_SUBMITTING
		);
		if ( ! $casOk ) {
			$this->log(
				'info',
				sprintf(
					'OrderSubmitJob: CAS pending->submitting rejected — order_id=%d; another worker won the race.',
					$orderId
				)
			);
			return;
		}

		// Build the OrderCreate DTO. Validation failures from Slice-09
		// factories surface as `InvalidArgumentException` and are treated
		// as permanent fail (analogous to 4xx — see AC-10).
		try {
			$dto = $this->buildOrderCreateDto( $order );
		} catch ( InvalidArgumentException $e ) {
			$this->handlePermanentFailure( $order, null, $e->getMessage(), 'dto_validation' );
			return;
		}

		// Submit to Spreadconnect. Three branches per Flow C.
		try {
			$response = $this->client->createOrder( $dto );
		} catch ( SpreadconnectClientError $e ) {
			// AC-5: Permanent failure (4xx). Catch BEFORE
			// SpreadconnectTransientError per PHP catch-order rules.
			$this->handlePermanentFailure(
				$order,
				$dto,
				$e->getMessage(),
				$e->getAppCode()
			);
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-6: Transient failure (5xx / network / 429-after-inner-retry).
			// Leave state at `submitting`; rethrow unchanged so AS retries.
			$this->log(
				'warning',
				sprintf(
					'OrderSubmitJob: transient error, AS retry — order_id=%d, code=%s, message=%s',
					$orderId,
					$e->getAppCode(),
					$e->getMessage()
				)
			);
			throw $e;
		}

		// AC-4 + AC-8: 2xx success path.
		$scOrderId = $this->extractOrderIdFromResponse( $response );

		if ( '' === $scOrderId ) {
			// 2xx with no usable id is a server-side contract violation.
			// Treat as permanent failure so the order does not get stuck
			// in `submitting` forever.
			$this->handlePermanentFailure(
				$order,
				$dto,
				'Spreadconnect 2xx response missing order id',
				'invalid_response'
			);
			return;
		}

		// Persist `_spreadconnect_order_id` BEFORE the CAS attempt — even
		// if the CAS loses (AC-8: webhook already advanced state to
		// PROCESSED), the SC-Order-ID is side-effect-free identifying
		// metadata and should be preserved.
		$order->update_meta_data( self::META_ORDER_ID, $scOrderId );
		$order->save();

		$casToNew = $this->stateMachine->compareAndSet(
			$order,
			OrderStateMachine::STATE_SUBMITTING,
			OrderStateMachine::STATE_NEW
		);

		if ( $casToNew ) {
			$order->add_order_note(
				sprintf( 'Submitted to Spreadconnect (#SC-%s)', $scOrderId ),
				false,
				false
			);
			$this->log(
				'info',
				sprintf(
					'OrderSubmitJob: submit success — order_id=%d, sc_order_id=%s, state=NEW',
					$orderId,
					$scOrderId
				)
			);
			return;
		}

		// AC-8: CAS-loss on `submitting -> NEW`. A parallel webhook
		// already advanced the state (e.g. to `PROCESSED`). Persist the
		// SC-Order-ID (already done above), drop a race-aware note, do
		// NOT rethrow.
		$order->add_order_note(
			sprintf(
				'Submitted to Spreadconnect (#SC-%s); state already advanced (race)',
				$scOrderId
			),
			false,
			false
		);
		$this->log(
			'info',
			sprintf(
				'OrderSubmitJob: submit success but state-CAS race-loss — order_id=%d, sc_order_id=%s',
				$orderId,
				$scOrderId
			)
		);
	}

	/**
	 * Build the {@see OrderCreate} request DTO from a WC-Order.
	 *
	 * Mapping (slice-28 AC-10 + architecture.md Z. 165):
	 *   - `externalOrderReference` = (string) WC-Order-ID.
	 *   - `orderItems`             = one {@see OrderItem} per WC line-item;
	 *                                `sku` from `WC_Order_Item_Product::get_product()->get_sku()`,
	 *                                `quantity` from `get_quantity()`.
	 *   - `billingAddress`         = {@see Address} from WC billing fields.
	 *   - `shippingAddress`        = {@see Address} from WC shipping fields,
	 *                                falling back to billing when shipping
	 *                                is empty (common WC pattern).
	 *   - `customerEmail`, `phone` = optional, omitted when empty.
	 *   - `shippingType`           = `null` in this slice; pre-submit
	 *                                wiring is slice-29/31.
	 *
	 * Validation throws `InvalidArgumentException` from the Slice-09
	 * factories — caller maps these to the permanent-fail path.
	 *
	 * @throws InvalidArgumentException From DTO factories on missing /
	 *                                  malformed fields.
	 */
	private function buildOrderCreateDto( WC_Order $order ): OrderCreate
	{
		$orderItemsRaw = array();

		foreach ( $order->get_items() as $item ) {
			// Defensive: WC_Order_Item is the base class; we want
			// products. Non-product line items (fees, shipping, taxes)
			// are not relevant to Spreadconnect's product-only contract.
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = '';
			if ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
				$sku = (string) $product->get_sku();
			}

			$quantity = method_exists( $item, 'get_quantity' )
				? (int) $item->get_quantity()
				: 0;

			$orderItemsRaw[] = array(
				'sku'      => $sku,
				'quantity' => $quantity,
			);
		}

		$billingAddress  = $this->extractAddress( $order, 'billing' );
		$shippingAddress = $this->extractAddress( $order, 'shipping' );

		// WC stores an empty shipping address (all fields blank) when the
		// customer ticks "ship to billing". Fall back to the billing
		// address so the SC payload remains valid.
		if ( $this->isEmptyAddress( $shippingAddress ) ) {
			$shippingAddress = $billingAddress;
		}

		$customerEmail = '';
		if ( method_exists( $order, 'get_billing_email' ) ) {
			$customerEmail = (string) $order->get_billing_email();
		}

		$phone = '';
		if ( method_exists( $order, 'get_billing_phone' ) ) {
			$phone = (string) $order->get_billing_phone();
		}

		$payload = array(
			'externalOrderReference' => (string) $order->get_id(),
			'orderItems'             => $orderItemsRaw,
			'billingAddress'         => $billingAddress,
			'shippingAddress'        => $shippingAddress,
		);

		if ( '' !== $customerEmail ) {
			$payload['customerEmail'] = $customerEmail;
		}
		if ( '' !== $phone ) {
			$payload['phone'] = $phone;
		}

		// Slice-09 factory validates each field and throws
		// InvalidArgumentException on missing / malformed entries.
		return OrderCreate::fromArray( $payload );
	}

	/**
	 * Extract a billing- or shipping-address from a WC-Order into the
	 * camelCase shape consumed by {@see Address::fromArray()}.
	 *
	 * @param string $type Either `'billing'` or `'shipping'`.
	 *
	 * @return array<string, mixed>
	 */
	private function extractAddress( WC_Order $order, string $type ): array
	{
		$method = static fn( string $field ): string => 'get_' . $type . '_' . $field;

		$firstName  = $this->safeOrderGet( $order, $method( 'first_name' ) );
		$lastName   = $this->safeOrderGet( $order, $method( 'last_name' ) );
		$address1   = $this->safeOrderGet( $order, $method( 'address_1' ) );
		$address2   = $this->safeOrderGet( $order, $method( 'address_2' ) );
		$postcode   = $this->safeOrderGet( $order, $method( 'postcode' ) );
		$city       = $this->safeOrderGet( $order, $method( 'city' ) );
		$country    = $this->safeOrderGet( $order, $method( 'country' ) );
		$state      = $this->safeOrderGet( $order, $method( 'state' ) );

		$payload = array(
			'firstName' => $firstName,
			'lastName'  => $lastName,
			'street'    => $address1,
			'zipCode'   => $postcode,
			'city'      => $city,
			'country'   => $country,
		);

		if ( '' !== $address2 ) {
			$payload['streetAnnex'] = $address2;
		}
		if ( '' !== $state ) {
			$payload['state'] = $state;
		}

		return $payload;
	}

	/**
	 * Call a getter on the WC-Order if it exists, returning `''` otherwise.
	 *
	 * Defence-in-depth against custom `WC_Order` subclasses that may not
	 * implement every billing/shipping accessor — keeps the DTO build
	 * resilient against partial WC stubs.
	 */
	private function safeOrderGet( WC_Order $order, string $method ): string
	{
		if ( ! method_exists( $order, $method ) ) {
			return '';
		}
		$value = $order->{$method}();
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Detect a WC shipping-address that is effectively empty (all required
	 * fields blank). Used to fall back to the billing address.
	 *
	 * @param array<string, mixed> $address
	 */
	private function isEmptyAddress( array $address ): bool
	{
		$required = array( 'firstName', 'lastName', 'street', 'zipCode', 'city', 'country' );
		foreach ( $required as $key ) {
			$value = $address[ $key ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Extract the SC-Order-ID from the `createOrder()` response body.
	 *
	 * Slice-10's `createOrder()` runs the body through `DtoMapper::snakeToCamel()`,
	 * so the canonical key is `id`. Cast defensively to string so callers
	 * always see a clean string contract.
	 *
	 * @param array<string, mixed> $response
	 */
	private function extractOrderIdFromResponse( array $response ): string
	{
		$id = $response['id'] ?? '';

		if ( is_string( $id ) || is_int( $id ) ) {
			return (string) $id;
		}

		return '';
	}

	/**
	 * Permanent-failure side-effect bundle (AC-5 + AC-10):
	 *   - CAS `submitting -> failed_to_submit` (CAS-loss is logged but
	 *     non-fatal; the state-machine emits its own info log).
	 *   - Emit the slice-37 logging stub (`failed_op_pending_record`) at
	 *     `error` level with the contract-bound context shape.
	 *   - Append an Order-Note marking the submit as permanently failed.
	 *   - Do NOT rethrow — Action Scheduler must not retry this job.
	 *
	 * @param OrderCreate|null $dto          DTO when build succeeded; `null`
	 *                                       when the failure originated in
	 *                                       DTO validation itself.
	 * @param string           $errorMessage Human-readable error message.
	 * @param string           $errorCode    Application-level code (e.g.
	 *                                       `http_4xx`, `dto_validation`).
	 */
	private function handlePermanentFailure(
		WC_Order $order,
		?OrderCreate $dto,
		string $errorMessage,
		string $errorCode
	): void {
		$orderId = (int) $order->get_id();

		$this->stateMachine->compareAndSet(
			$order,
			OrderStateMachine::STATE_SUBMITTING,
			OrderStateMachine::STATE_FAILED_TO_SUBMIT
		);

		// AC-5 (c): Failed-Ops logging stub. Slice 37's RetryPolicyListener
		// pivots on the `failed_op_pending_record` tag in the context to
		// insert a real `wp_spreadconnect_failed_ops` row.
		$payload = null === $dto
			? array( 'order_id' => $orderId )
			: $this->dtoToLogPayload( $dto );

		$context = array(
			'source'              => self::LOG_SOURCE,
			'tag'                 => self::LOG_TAG_FAILED_OP_PENDING,
			'op_type'             => self::OP_TYPE_CREATE_ORDER,
			'related_entity_type' => self::RELATED_ENTITY_ORDER,
			'related_entity_id'   => $orderId,
			'payload'             => $payload,
			'error_message'       => $errorMessage,
			'error_code'          => $errorCode,
			'state'               => 'unresolved',
		);

		$this->logWithContext(
			'error',
			sprintf(
				'OrderSubmitJob: %s — order_id=%d, code=%s, message=%s',
				self::LOG_TAG_FAILED_OP_PENDING,
				$orderId,
				$errorCode,
				$errorMessage
			),
			$context
		);

		$order->add_order_note(
			'Spreadconnect: submit failed (4xx) — see Failed-Ops',
			false,
			false
		);
	}

	/**
	 * Serialise the OrderCreate DTO into the payload form retained by the
	 * `wp_spreadconnect_failed_ops` row (slice-37). KISS: a flat array of
	 * the fields needed to reproduce the submit on retry.
	 *
	 * @return array<string, mixed>
	 */
	private function dtoToLogPayload( OrderCreate $dto ): array
	{
		return array(
			'externalOrderReference' => $dto->externalOrderReference,
			'orderItems'             => array_map(
				static fn( OrderItem $item ): array => array(
					'sku'      => $item->sku,
					'quantity' => $item->quantity,
				),
				$dto->orderItems
			),
			'billingAddress'         => $this->addressToLogArray( $dto->billingAddress ),
			'shippingAddress'        => $this->addressToLogArray( $dto->shippingAddress ),
			'shippingType'           => $dto->shippingType,
			'customerEmail'          => $dto->customerEmail,
			'phone'                  => $dto->phone,
			'taxType'                => $dto->taxType,
		);
	}

	/**
	 * Flatten an Address DTO for the failed-ops payload.
	 *
	 * @return array<string, mixed>
	 */
	private function addressToLogArray( Address $address ): array
	{
		return array(
			'firstName'   => $address->firstName,
			'lastName'    => $address->lastName,
			'street'      => $address->street,
			'streetAnnex' => $address->streetAnnex,
			'zipCode'     => $address->zipCode,
			'city'        => $address->city,
			'country'     => $address->country,
			'state'       => $address->state,
		);
	}

	/**
	 * Resolve the logger and dispatch a single entry with `source` only.
	 */
	private function log( string $level, string $message ): void
	{
		$this->logWithContext( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Resolve the logger and dispatch a single entry with a custom context.
	 *
	 * The `source` key MUST be present in `$context` for log-stream
	 * filtering. Slice-37's RetryPolicyListener reads the additional
	 * `tag`/`op_type`/`related_entity_*`/`payload` keys.
	 *
	 * @param array<string, mixed> $context
	 */
	private function logWithContext( string $level, string $message, array $context ): void
	{
		$logger = $this->logger;
		if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
		}
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		if ( ! isset( $context['source'] ) ) {
			$context['source'] = self::LOG_SOURCE;
		}

		$logger->log( $level, $message, $context );
	}
}
