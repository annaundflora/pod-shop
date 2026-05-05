<?php
/**
 * OrderCreate request DTO.
 *
 * Composite DTO sent as body of `POST /orders`. Holds the WC-Order
 * reference, the line items, both addresses, and optional shipping/tax
 * metadata.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable OrderCreate request DTO.
 *
 * Validation:
 *   - `externalOrderReference` non-empty string (= WC-Order-ID).
 *   - `orderItems` is an array with >= 1 element; each element is an OrderItem.
 *   - `billingAddress` and `shippingAddress` are Address instances.
 *   - `shippingType`, `customerEmail`, `phone`, `taxType` optional, may be null.
 *
 * Note: `taxType` is intentionally optional. Per Open Q3 in architecture.md,
 * Spreadconnect picks the tax-type based on the shipping address; the
 * field is exposed here purely as a fallback for callers that need to
 * override that default.
 */
final readonly class OrderCreate
{
	/**
	 * @param OrderItem[] $orderItems
	 */
	public function __construct(
		public string $externalOrderReference,
		public array $orderItems,
		public Address $billingAddress,
		public Address $shippingAddress,
		public ?string $shippingType = null,
		public ?string $customerEmail = null,
		public ?string $phone = null,
		public ?string $taxType = null,
	) {
	}

	/**
	 * Construct from a snake_case or camelCase array.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self
	{
		$data = DtoMapper::snakeToCamel( $data );

		$external_order_reference = $data['externalOrderReference'] ?? null;
		if ( ! is_string( $external_order_reference ) || '' === $external_order_reference ) {
			throw new InvalidArgumentException(
				'OrderCreate: field "externalOrderReference" must be a non-empty string.'
			);
		}

		$order_items_raw = $data['orderItems'] ?? null;
		if ( ! is_array( $order_items_raw ) || array() === $order_items_raw ) {
			throw new InvalidArgumentException(
				'OrderCreate: field "orderItems" must be a non-empty list (>= 1 element).'
			);
		}

		$order_items = array();
		foreach ( $order_items_raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException(
					sprintf( 'OrderCreate: field "orderItems[%s]" must be an array.', (string) $index )
				);
			}
			$order_items[] = OrderItem::fromArray( $item );
		}

		$billing_address_raw = $data['billingAddress'] ?? null;
		if ( ! is_array( $billing_address_raw ) ) {
			throw new InvalidArgumentException(
				'OrderCreate: field "billingAddress" must be an array convertible to Address.'
			);
		}
		$billing_address = Address::fromArray( $billing_address_raw );

		$shipping_address_raw = $data['shippingAddress'] ?? null;
		if ( ! is_array( $shipping_address_raw ) ) {
			throw new InvalidArgumentException(
				'OrderCreate: field "shippingAddress" must be an array convertible to Address.'
			);
		}
		$shipping_address = Address::fromArray( $shipping_address_raw );

		$shipping_type = $data['shippingType'] ?? null;
		if ( null !== $shipping_type && ! is_string( $shipping_type ) ) {
			throw new InvalidArgumentException( 'OrderCreate: field "shippingType" must be a string or null.' );
		}

		$customer_email = $data['customerEmail'] ?? null;
		if ( null !== $customer_email && ! is_string( $customer_email ) ) {
			throw new InvalidArgumentException( 'OrderCreate: field "customerEmail" must be a string or null.' );
		}

		$phone = $data['phone'] ?? null;
		if ( null !== $phone && ! is_string( $phone ) ) {
			throw new InvalidArgumentException( 'OrderCreate: field "phone" must be a string or null.' );
		}

		$tax_type = $data['taxType'] ?? null;
		if ( null !== $tax_type && ! is_string( $tax_type ) ) {
			throw new InvalidArgumentException( 'OrderCreate: field "taxType" must be a string or null.' );
		}

		return new self(
			externalOrderReference: $external_order_reference,
			orderItems: $order_items,
			billingAddress: $billing_address,
			shippingAddress: $shipping_address,
			shippingType: $shipping_type,
			customerEmail: $customer_email,
			phone: $phone,
			taxType: $tax_type,
		);
	}
}
