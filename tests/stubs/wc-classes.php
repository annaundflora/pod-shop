<?php
/**
 * Minimal canonical stubs for WooCommerce / WordPress classes referenced
 * by the production code via type hints, `instanceof` checks and
 * `method_exists()` probes.
 *
 * Loaded from `tests/bootstrap.php` BEFORE any individual slice test file,
 * so test files can `Mockery::mock()` against these stub parents and
 * `method_exists()` checks inside the production code resolve consistently
 * across the whole suite.
 *
 * IMPORTANT: keep these stubs additive. If a follow-up slice exercises a
 * new WC method (`WC_Order::get_status()`, `WC_Order::get_payment_method()`,
 * …), add it here with a sensible default return so older slice tests do
 * not regress.
 *
 * Class declarations are guarded by `class_exists(..., false)` so running
 * this file twice (e.g. inside a `#[RunInSeparateProcess]` test) does not
 * trigger fatal errors on re-declaration.
 */

declare(strict_types=1);

if ( ! class_exists( 'wpdb', false ) ) {
	/**
	 * Minimal `wpdb` stub. Mockery doubles in slice tests override
	 * `prepare()`, `query()`, `get_var()` and read the `prefix` property.
	 */
	class wpdb // phpcs:ignore WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	{
		public string $prefix = 'wp_';

		public function prepare( string $query, ...$args ): string
		{
			return $query;
		}

		public function query( string $query ): int
		{
			return 0;
		}

		/**
		 * @return mixed
		 */
		public function get_var( string $query )
		{
			return null;
		}
	}
}

if ( ! class_exists( 'WC_Logger', false ) ) {
	/**
	 * Minimal `WC_Logger` stub. Mockery doubles in slice tests override
	 * `log()`.
	 */
	class WC_Logger
	{
		public function log( string $level, string $message, array $context = [] ): void
		{
			// no-op
		}
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Canonical `WC_Order` stub. Includes ALL the billing/shipping
	 * accessors that the production code probes via `method_exists()`
	 * inside the `OrderSubmitJob` DTO build (slice-28 AC-10) plus the
	 * order/meta/items accessors that slice-27 already relied on.
	 */
	class WC_Order
	{
		public function get_id(): int
		{
			return 0;
		}

		/**
		 * @return mixed
		 */
		public function get_meta( string $key, bool $single = true, string $context = 'view' )
		{
			return '';
		}

		public function update_meta_data( string $key, $value ): void
		{
			// no-op
		}

		public function save(): int
		{
			return 0;
		}

		public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): int
		{
			return 0;
		}

		/**
		 * @return array<int, mixed>
		 */
		public function get_items( $types = 'line_item' ): array
		{
			return [];
		}

		public function get_billing_first_name(): string { return ''; }
		public function get_billing_last_name(): string  { return ''; }
		public function get_billing_address_1(): string  { return ''; }
		public function get_billing_address_2(): string  { return ''; }
		public function get_billing_postcode(): string   { return ''; }
		public function get_billing_city(): string       { return ''; }
		public function get_billing_country(): string    { return ''; }
		public function get_billing_state(): string      { return ''; }
		public function get_billing_email(): string      { return ''; }
		public function get_billing_phone(): string      { return ''; }

		public function get_shipping_first_name(): string { return ''; }
		public function get_shipping_last_name(): string  { return ''; }
		public function get_shipping_address_1(): string  { return ''; }
		public function get_shipping_address_2(): string  { return ''; }
		public function get_shipping_postcode(): string   { return ''; }
		public function get_shipping_city(): string       { return ''; }
		public function get_shipping_country(): string    { return ''; }
		public function get_shipping_state(): string      { return ''; }
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	/**
	 * Minimal `WC_Product` stub. Slice-28 mocks override `get_sku()`.
	 */
	class WC_Product
	{
		public function get_sku( string $context = 'view' ): string
		{
			return '';
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	/**
	 * Minimal `WC_Order_Item_Product` stub. Slice-28 mocks override
	 * `get_product()` and `get_quantity()`.
	 */
	class WC_Order_Item_Product
	{
		/**
		 * @return mixed
		 */
		public function get_product()
		{
			return null;
		}

		public function get_quantity(): int
		{
			return 0;
		}
	}
}
