<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// Slice 35 testet `Inline\ProductListColumns` (Product-List-Columns + Filter
// + Sort) gegen die GIVEN/WHEN/THEN ACs der Slice-Spec.
//
// Mocking-Strategy `mock_external` (laut Slice-Spec Z. 28):
//
//   - Brain\Monkey fuer alle WP-Funktionen (`add_filter`, `add_action`,
//     `get_post_meta`, `wc_get_product`, `wc_price`, `current_user_can`,
//     `is_admin`, `selected`, `esc_html`, `esc_attr`, `wp_kses_post`,
//     `sanitize_key`, `__`, `esc_attr__`).
//   - `WP_Query` ist eine Mockery-Mock-Klasse — der Test ruft die statischen
//     Hooks direkt auf einem Mockery-Mock auf (kein WP-Bootstrap, kein
//     DB-Roundtrip).
//   - `WC_Product` Stub-Klasse mit `get_price()` aus tests/stubs/wc-classes.php.
//   - `$_GET['sc_filter']` wird im Test-Setup pro Case gesetzt.
//
// Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Query', false ) ) {
		/**
		 * Minimal `WP_Query` stub used as a Mockery mock-target.
		 *
		 * Production code probes:
		 *   - `is_main_query(): bool`
		 *   - `get(string $key): mixed`
		 *   - `set(string $key, mixed $value): void`
		 *
		 * We declare the stub so `instanceof WP_Query` works inside
		 * `preGetPostsStatic()` and so Mockery can mock the methods.
		 */
		class WP_Query
		{
			/** @var array<string, mixed> */
			public array $query_vars = [];

			public function is_main_query(): bool
			{
				return true;
			}

			/**
			 * @return mixed
			 */
			public function get( string $key, $default = '' )
			{
				return $this->query_vars[ $key ] ?? $default;
			}

			/**
			 * @param mixed $value
			 */
			public function set( string $key, $value ): void
			{
				$this->query_vars[ $key ] = $value;
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Inline\ProductListColumns;
	use WC_Product;
	use WP_Query;

	/**
	 * Slice 35 — Product-List-Spalten + Filter + Sort.
	 *
	 * Acceptance Tests gegen `slice-35-product-list-columns.md`. Es werden
	 * ausschliesslich die statischen Public-Methoden aus AC-1, 2/3/4/5, 6,
	 * 7, 8, 9, 10 + Bootstrap-Hooks aus AC-11 getestet. Render-Tests sind
	 * Output-buffered (die Methoden echo'n).
	 */
	final class Slice35ProductListColumnsTest extends TestCase
	{
		/**
		 * Backing store for `get_post_meta` lookups (per test).
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private array $postMeta = [];

		/**
		 * Backing for `wc_get_product` -> WC_Product (or stub double).
		 *
		 * @var array<int, mixed>
		 */
		private array $wcProductsById = [];

		/**
		 * Whether `current_user_can('manage_woocommerce')` returns true.
		 */
		private bool $canManageWoocommerce = true;

		/**
		 * Whether `is_admin()` returns true.
		 */
		private bool $isAdmin = true;

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->postMeta             = [];
			$this->wcProductsById       = [];
			$this->canManageWoocommerce = true;
			$this->isAdmin              = true;

			$_GET = [];

			// ---- i18n + escape passthrough -------------------------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( '_e' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'wp_kses_post' )->returnArg( 1 );

			// ---- Sanitize helpers ---------------------------------------------
			Functions\when( 'sanitize_key' )->alias(
				static function ( $key ) {
					if ( ! is_string( $key ) ) {
						return '';
					}
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
				}
			);

			// ---- Postmeta read -------------------------------------------------
			$postMeta = & $this->postMeta;
			Functions\when( 'get_post_meta' )->alias(
				static function ( $post_id, $key = '', $single = false ) use ( &$postMeta ) {
					$pid = (int) $post_id;
					if ( ! isset( $postMeta[ $pid ] ) ) {
						return '';
					}
					return $postMeta[ $pid ][ (string) $key ] ?? '';
				}
			);

			// ---- WC-Product lookup --------------------------------------------
			$wcProducts = & $this->wcProductsById;
			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) use ( &$wcProducts ) {
					$pid = (int) $id;
					return $wcProducts[ $pid ] ?? null;
				}
			);

			// ---- wc_price: emit a `<span class="woocommerce-Price-amount">…</span>`
			//      that contains the formatted amount + currency. The tests probe
			//      for the numeric portion + the currency code.
			Functions\when( 'wc_price' )->alias(
				static function ( $price, $args = [] ) {
					$currency = is_array( $args ) && isset( $args['currency'] ) ? (string) $args['currency'] : 'EUR';
					return sprintf(
						'<span class="woocommerce-Price-amount" data-currency="%s">%s</span>',
						htmlspecialchars( $currency, ENT_QUOTES ),
						number_format( (float) $price, 2, '.', '' )
					);
				}
			);

			// ---- selected(): WP-core helper -- echoes/returns ` selected="selected"`
			Functions\when( 'selected' )->alias(
				static function ( $current, $value, $echo = true ) {
					$out = ( (string) $current === (string) $value ) ? ' selected="selected"' : '';
					if ( $echo ) {
						echo $out;
					}
					return $out;
				}
			);

			// ---- Capability gate (per-test mutable) ---------------------------
			$canManage = & $this->canManageWoocommerce;
			Functions\when( 'current_user_can' )->alias(
				static function ( $cap ) use ( &$canManage ) {
					return $cap === 'manage_woocommerce' ? $canManage : false;
				}
			);

			// ---- is_admin gate (per-test mutable) ------------------------------
			$isAdmin = & $this->isAdmin;
			Functions\when( 'is_admin' )->alias(
				static function () use ( &$isAdmin ) {
					return $isAdmin;
				}
			);
		}

		protected function tearDown(): void
		{
			$_GET = [];
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------

		/**
		 * Build a Mockery `WP_Query` mock pre-configured with query-vars.
		 *
		 * @param array<string, mixed> $vars      Initial query-vars (returned by `get()`).
		 * @param bool                  $isMain   Return value of `is_main_query()`.
		 *
		 * @return WP_Query&\Mockery\MockInterface
		 */
		private function makeQuery( array $vars = [], bool $isMain = true ): WP_Query
		{
			/** @var WP_Query&\Mockery\MockInterface $mock */
			$mock = Mockery::mock( WP_Query::class )->makePartial();
			$mock->shouldReceive( 'is_main_query' )->andReturn( $isMain )->byDefault();
			$mock->query_vars = $vars;

			$mock->shouldReceive( 'get' )->andReturnUsing(
				static function ( string $key, $default = '' ) use ( $mock ) {
					return $mock->query_vars[ $key ] ?? $default;
				}
			)->byDefault();

			$mock->shouldReceive( 'set' )->andReturnUsing(
				static function ( string $key, $value ) use ( $mock ): void {
					$mock->query_vars[ $key ] = $value;
				}
			)->byDefault();

			return $mock;
		}

		/**
		 * Configure a fake `wc_get_product()` lookup. Uses a Mockery mock
		 * of WC_Product so we can stub `get_price()` per product.
		 */
		private function fakeProductPrice( int $productId, ?string $price ): void
		{
			$mock = Mockery::mock( WC_Product::class );
			$mock->shouldReceive( 'get_price' )->andReturn( $price ?? '' );
			$this->wcProductsById[ $productId ] = $mock;
		}

		/**
		 * Capture echo'd output of a render method.
		 */
		private function captureOutput( callable $fn ): string
		{
			ob_start();
			try {
				$fn();
			} catch ( \Throwable $e ) {
				ob_end_clean();
				throw $e;
			}
			return (string) ob_get_clean();
		}

		// ===================================================================
		// AC-1: Drei Spalten in korrekter Reihenfolge nach `price` registriert
		// ===================================================================

		// AC-1: GIVEN Plugin-Bootstrap (Slice 02) ist initialisiert und ein
		//       Admin oeffnet wp-admin/edit.php?post_type=product
		//       WHEN der Hook manage_edit-product_columns feuert
		//       THEN registriert ProductListColumns::registerColumns drei neue
		//            Spalten in dieser Reihenfolge nach der Spalte `price`:
		//            sc_linked, sc_cost, sc_margin.
		public function test_register_columns_inserts_three_columns_after_price(): void
		{
			$existing = [
				'cb'         => '<input type="checkbox" />',
				'thumb'      => 'Image',
				'name'       => 'Name',
				'sku'        => 'SKU',
				'price'      => 'Price',
				'categories' => 'Categories',
				'tags'       => 'Tags',
				'date'       => 'Date',
			];

			$result = ProductListColumns::registerColumns( $existing );

			// Three new columns present.
			$this->assertArrayHasKey(
				'sc_linked',
				$result,
				'AC-1: sc_linked-Spalte MUSS registriert sein.'
			);
			$this->assertArrayHasKey(
				'sc_cost',
				$result,
				'AC-1: sc_cost-Spalte MUSS registriert sein.'
			);
			$this->assertArrayHasKey(
				'sc_margin',
				$result,
				'AC-1: sc_margin-Spalte MUSS registriert sein.'
			);

			// Headers exact (passthrough `__()` returns key untouched).
			$this->assertSame( 'SC-Linked', $result['sc_linked'], 'AC-1: Header sc_linked MUSS "SC-Linked" sein.' );
			$this->assertSame( 'SC-Cost', $result['sc_cost'], 'AC-1: Header sc_cost MUSS "SC-Cost" sein.' );
			$this->assertSame( 'Margin', $result['sc_margin'], 'AC-1: Header sc_margin MUSS "Margin" sein.' );

			// Order: sc_linked, sc_cost, sc_margin must come immediately after `price`.
			$keys      = array_keys( $result );
			$priceIdx  = array_search( 'price', $keys, true );
			$linkedIdx = array_search( 'sc_linked', $keys, true );
			$costIdx   = array_search( 'sc_cost', $keys, true );
			$marginIdx = array_search( 'sc_margin', $keys, true );

			$this->assertIsInt( $priceIdx );
			$this->assertSame( $priceIdx + 1, $linkedIdx, 'AC-1: sc_linked MUSS direkt NACH price stehen.' );
			$this->assertSame( $priceIdx + 2, $costIdx, 'AC-1: sc_cost MUSS auf sc_linked folgen.' );
			$this->assertSame( $priceIdx + 3, $marginIdx, 'AC-1: sc_margin MUSS auf sc_cost folgen.' );

			// Existing order preserved.
			foreach ( array_keys( $existing ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$result,
					sprintf( 'AC-1: Bestehende Spalte "%s" MUSS erhalten bleiben.', $key )
				);
			}

			// `cb`, `thumb`, `name`, `sku` must precede `price` in the output.
			$cbIdx   = array_search( 'cb', $keys, true );
			$nameIdx = array_search( 'name', $keys, true );
			$this->assertLessThan( $priceIdx, $cbIdx, 'AC-1: cb MUSS vor price stehen.' );
			$this->assertLessThan( $priceIdx, $nameIdx, 'AC-1: name MUSS vor price stehen.' );
		}

		// AC-1: Fallback — wenn `price` fehlt, werden die Spalten VOR `categories` eingefuegt.
		public function test_register_columns_falls_back_to_before_categories_when_price_missing(): void
		{
			$existing = [
				'cb'         => '',
				'name'       => 'Name',
				'categories' => 'Categories',
				'date'       => 'Date',
			];

			$result = ProductListColumns::registerColumns( $existing );
			$keys   = array_keys( $result );

			$catIdx    = array_search( 'categories', $keys, true );
			$linkedIdx = array_search( 'sc_linked', $keys, true );
			$costIdx   = array_search( 'sc_cost', $keys, true );
			$marginIdx = array_search( 'sc_margin', $keys, true );

			$this->assertIsInt( $catIdx );
			$this->assertSame( $catIdx - 3, $linkedIdx, 'AC-1: sc_linked MUSS 3 Plaetze VOR categories stehen.' );
			$this->assertSame( $catIdx - 2, $costIdx );
			$this->assertSame( $catIdx - 1, $marginIdx );
		}

		// ===================================================================
		// AC-2: sc_linked rendert Check-Mark fuer linked, Dash fuer unlinked
		// ===================================================================

		// AC-2: GIVEN ein WC-Variable-Product mit Postmeta _spreadconnect_article_id='88421'
		//       WHEN manage_product_posts_custom_column mit column_name='sc_linked' und
		//            post_id=42 feuert
		//       THEN echo'd renderColumn '<span class="sc-linked-yes" aria-label="linked">✓</span>'
		public function test_render_sc_linked_renders_checkmark_when_article_id_present(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
			];

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_linked', 42 )
			);

			$this->assertStringContainsString(
				'sc-linked-yes',
				$html,
				'AC-2: Linked-State MUSS Klasse "sc-linked-yes" emittieren.'
			);
			$this->assertStringContainsString(
				'aria-label="linked"',
				$html,
				'AC-2: Linked-State MUSS aria-label="linked" enthalten.'
			);
			$this->assertStringContainsString(
				'✓',
				$html,
				'AC-2: Linked-State MUSS Check-Mark "✓" enthalten.'
			);
		}

		// AC-2: sc_linked Dash + sc-linked-no Klasse fuer unlinked Produkt
		public function test_render_sc_linked_renders_dash_when_unlinked(): void
		{
			// kein _spreadconnect_article_id gesetzt -> unlinked
			$this->postMeta[42] = [];

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_linked', 42 )
			);

			$this->assertStringContainsString(
				'sc-linked-no',
				$html,
				'AC-2: Unlinked-State MUSS Klasse "sc-linked-no" emittieren.'
			);
			$this->assertStringContainsString(
				'aria-label="unlinked"',
				$html,
				'AC-2: Unlinked-State MUSS aria-label="unlinked" enthalten.'
			);
			$this->assertStringContainsString(
				'—',
				$html,
				'AC-2: Unlinked-State MUSS Dash "—" enthalten.'
			);
			$this->assertStringNotContainsString(
				'✓',
				$html,
				'AC-2: Unlinked-State darf KEIN Check-Mark enthalten.'
			);
		}

		// ===================================================================
		// AC-3: sc_cost nutzt wc_price() mit Currency aus Postmeta
		// ===================================================================

		// AC-3: GIVEN Produkt mit _spreadconnect_cost='12.34', _spreadconnect_cost_currency='EUR'
		//       WHEN manage_product_posts_custom_column mit column_name='sc_cost' feuert
		//       THEN echo'd der Handler den Cost via wc_price(12.34, ['currency'=>'EUR']).
		public function test_render_sc_cost_formats_with_wc_price_and_currency(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id'    => '88421',
				'_spreadconnect_cost'          => '12.34',
				'_spreadconnect_cost_currency' => 'EUR',
			];

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_cost', 42 )
			);

			// `wc_price` stub formats with two decimals + currency attribute.
			$this->assertStringContainsString(
				'12.34',
				$html,
				'AC-3: sc_cost MUSS den formatierten Cost-Wert enthalten.'
			);
			$this->assertStringContainsString(
				'data-currency="EUR"',
				$html,
				'AC-3: wc_price MUSS mit currency=EUR aus Postmeta aufgerufen werden.'
			);
			$this->assertStringContainsString(
				'woocommerce-Price-amount',
				$html,
				'AC-3: Output MUSS aus wc_price() stammen (Marker-Class).'
			);
		}

		// AC-3: sc_cost rendert Dash bei fehlendem Postmeta (Cache-Miss / unlinked).
		public function test_render_sc_cost_renders_dash_when_missing(): void
		{
			$this->postMeta[42] = [];

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_cost', 42 )
			);

			$this->assertStringContainsString(
				'—',
				$html,
				'AC-3: Bei fehlendem _spreadconnect_cost MUSS Dash "—" emittiert werden.'
			);
			$this->assertStringNotContainsString(
				'woocommerce-Price-amount',
				$html,
				'AC-3: Bei Cache-Miss darf wc_price NICHT aufgerufen werden.'
			);
		}

		// AC-3: cost='0' -> wc_price(0) (NICHT Dash, Null ist gueltiger Cost-Wert).
		public function test_render_sc_cost_renders_wc_price_for_zero_cost(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_cost'          => '0',
				'_spreadconnect_cost_currency' => 'EUR',
			];

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_cost', 42 )
			);

			$this->assertStringContainsString(
				'0.00',
				$html,
				'AC-3: cost="0" MUSS via wc_price(0) -> "0.00" gerendert werden, NICHT als Dash.'
			);
			$this->assertStringContainsString(
				'woocommerce-Price-amount',
				$html,
				'AC-3: cost="0" MUSS durch wc_price() laufen.'
			);
		}

		// ===================================================================
		// AC-4: sc_margin berechnet euro+pct + Klassen-Mapping
		// ===================================================================

		// AC-4: GIVEN cost=12.34, WC-Price=29.90 -> margin_eur=17.56, margin_pct=58.7
		//       WHEN sc_margin-Handler feuert
		//       THEN echo'd '<span class="sc-margin-high">17.56 € (58.7%) ●</span>'.
		public function test_render_sc_margin_emits_high_class_above_40_percent(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];
			$this->fakeProductPrice( 42, '29.90' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-high',
				$html,
				'AC-4: margin_pct ~58.7% (>40) MUSS sc-margin-high-Klasse erhalten.'
			);
			$this->assertStringContainsString(
				'17.56',
				$html,
				'AC-4: margin_eur=29.90-12.34=17.56 MUSS gerendert werden.'
			);
			$this->assertMatchesRegularExpression(
				'/58\.[67]/',
				$html,
				'AC-4: margin_pct~58.7 MUSS gerendert werden (1 Decimal).'
			);
		}

		// AC-4: 20-40% -> sc-margin-mid
		public function test_render_sc_margin_emits_mid_class_between_20_and_40(): void
		{
			// cost=20, price=29.90 -> margin=9.90, pct~33.1% -> mid
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '20.00',
			];
			$this->fakeProductPrice( 42, '29.90' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-mid',
				$html,
				'AC-4: margin_pct ~33% (zwischen 20 und 40) MUSS sc-margin-mid sein.'
			);
			$this->assertStringNotContainsString(
				'sc-margin-low',
				$html,
				'AC-4: 20-40%-Range darf NICHT sc-margin-low sein.'
			);
			$this->assertStringNotContainsString(
				'sc-margin-high',
				$html,
				'AC-4: 20-40%-Range darf NICHT sc-margin-high sein.'
			);
		}

		// AC-4: <20% -> sc-margin-low
		public function test_render_sc_margin_emits_low_class_below_20(): void
		{
			// cost=25, price=29.90 -> margin=4.90, pct~16.4% -> low
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '25.00',
			];
			$this->fakeProductPrice( 42, '29.90' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-low',
				$html,
				'AC-4: margin_pct ~16.4% (<20) MUSS sc-margin-low sein.'
			);
		}

		// AC-4: wcPrice<=0 -> sc-margin-unknown (kein Division-by-Zero).
		public function test_render_sc_margin_emits_unknown_class_on_zero_price(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];
			$this->fakeProductPrice( 42, '0' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-unknown',
				$html,
				'AC-4: wcPrice=0 MUSS sc-margin-unknown emittieren (kein Division-by-Zero).'
			);
			$this->assertStringContainsString(
				'—',
				$html,
				'AC-4: wcPrice=0 MUSS Dash "—" rendern.'
			);
		}

		// AC-4: fehlender _spreadconnect_article_id -> sc-margin-unknown
		public function test_render_sc_margin_emits_unknown_when_article_id_missing(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_cost' => '12.34',
				// kein article_id
			];
			$this->fakeProductPrice( 42, '29.90' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-unknown',
				$html,
				'AC-4: Fehlender article_id -> sc-margin-unknown.'
			);
		}

		// AC-4: cost==null -> sc-margin-unknown
		public function test_render_sc_margin_emits_unknown_when_cost_missing(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				// kein cost
			];
			$this->fakeProductPrice( 42, '29.90' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-unknown',
				$html,
				'AC-4: Fehlender cost -> sc-margin-unknown.'
			);
		}

		// ===================================================================
		// AC-5: sc_margin liest Variable-Product-Min-Price via WC_Product::get_price()
		// ===================================================================

		// AC-5: GIVEN Variable-Product ohne eigenen Hauptpreis aber mit Variations-Preisen
		//       WHEN sc_margin-Handler feuert
		//       THEN liest get_price() (Variable-Products liefern Min-Preis).
		public function test_render_sc_margin_reads_variable_product_min_price(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];

			// `WC_Product_Variable::get_price()` returns the min variation price.
			// We simulate via the same Mockery WC_Product mock — production code
			// only calls `get_price()` so the polymorphism is invisible.
			$mock = Mockery::mock( WC_Product::class );
			$mock->shouldReceive( 'get_price' )
				->once()
				->andReturn( '19.90' );  // simulated Min-Variation-Price

			$this->wcProductsById[42] = $mock;

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			// margin = 19.90 - 12.34 = 7.56, pct ~38.0% -> mid
			$this->assertStringContainsString(
				'7.56',
				$html,
				'AC-5: margin_eur (Min-Variation-Price 19.90 - cost 12.34 = 7.56) MUSS gerendert sein.'
			);
			$this->assertStringContainsString(
				'sc-margin-mid',
				$html,
				'AC-5: 19.90 vs. cost 12.34 -> ~38% -> sc-margin-mid.'
			);
		}

		// AC-5: get_price() liefert leeren String -> sc-margin-unknown.
		public function test_render_sc_margin_falls_back_to_unknown_when_get_price_empty(): void
		{
			$this->postMeta[42] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];
			$this->fakeProductPrice( 42, '' );

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderColumn( 'sc_margin', 42 )
			);

			$this->assertStringContainsString(
				'sc-margin-unknown',
				$html,
				'AC-5: get_price() leerer String -> sc-margin-unknown.'
			);
		}

		// ===================================================================
		// AC-6: Sortable-Columns registriert sc_linked + sc_cost + sc_margin
		// ===================================================================

		// AC-6: GIVEN manage_edit-product_sortable_columns feuert
		//       WHEN registerSortableColumns greift
		//       THEN sind sc_linked, sc_cost, sc_margin als sortable markiert
		//            mit Sort-Keys gleich Spaltennamen.
		public function test_register_sortable_columns_marks_three_columns_sortable(): void
		{
			$existing = [
				'name'  => 'name',
				'price' => 'price',
				'date'  => 'date',
			];

			$result = ProductListColumns::registerSortableColumns( $existing );

			$this->assertSame(
				'sc_linked',
				$result['sc_linked'] ?? null,
				'AC-6: sc_linked-Sort-Key MUSS = "sc_linked" sein.'
			);
			$this->assertSame(
				'sc_cost',
				$result['sc_cost'] ?? null,
				'AC-6: sc_cost-Sort-Key MUSS = "sc_cost" sein.'
			);
			$this->assertSame(
				'sc_margin',
				$result['sc_margin'] ?? null,
				'AC-6: sc_margin-Sort-Key MUSS = "sc_margin" sein.'
			);

			// Existing sortable columns preserved unchanged.
			$this->assertSame( 'name', $result['name'] ?? null, 'AC-6: bestehende Sortable-Columns bleiben unveraendert.' );
			$this->assertSame( 'price', $result['price'] ?? null );
			$this->assertSame( 'date', $result['date'] ?? null );
		}

		// ===================================================================
		// AC-7: pre_get_posts mit orderby=sc_cost setzt meta_key+orderby
		// ===================================================================

		// AC-7: GIVEN ?orderby=sc_cost&order=ASC (Main-Query, Admin, post_type=product)
		//       WHEN pre_get_posts feuert
		//       THEN setzt $query->set('meta_key','_spreadconnect_cost')
		//                  + $query->set('orderby','meta_value_num').
		public function test_pre_get_posts_applies_sort_for_sc_cost(): void
		{
			$query = $this->makeQuery( [
				'post_type' => 'product',
				'orderby'   => 'sc_cost',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertSame(
				'_spreadconnect_cost',
				$query->query_vars['meta_key'] ?? null,
				'AC-7: orderby=sc_cost MUSS meta_key="_spreadconnect_cost" setzen.'
			);
			$this->assertSame(
				'meta_value_num',
				$query->query_vars['orderby'] ?? null,
				'AC-7: orderby=sc_cost MUSS orderby="meta_value_num" setzen (numeric sort).'
			);
		}

		// AC-7: orderby=sc_linked -> meta_key="_spreadconnect_article_id", orderby="meta_value"
		public function test_pre_get_posts_applies_sort_for_sc_linked(): void
		{
			$query = $this->makeQuery( [
				'post_type' => 'product',
				'orderby'   => 'sc_linked',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertSame(
				'_spreadconnect_article_id',
				$query->query_vars['meta_key'] ?? null,
				'AC-7: orderby=sc_linked MUSS meta_key="_spreadconnect_article_id" setzen.'
			);
			$this->assertSame(
				'meta_value',
				$query->query_vars['orderby'] ?? null,
				'AC-7: orderby=sc_linked MUSS orderby="meta_value" setzen.'
			);
		}

		// AC-7: orderby=sc_margin -> meta_key="_spreadconnect_cost", orderby="meta_value_num"
		public function test_pre_get_posts_applies_sort_for_sc_margin(): void
		{
			$query = $this->makeQuery( [
				'post_type' => 'product',
				'orderby'   => 'sc_margin',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertSame(
				'_spreadconnect_cost',
				$query->query_vars['meta_key'] ?? null,
				'AC-7: orderby=sc_margin MUSS auf meta_key="_spreadconnect_cost" mappen (Cost-Approximation).'
			);
			$this->assertSame(
				'meta_value_num',
				$query->query_vars['orderby'] ?? null
			);
		}

		// AC-7: Non-Main-Query -> NO-OP (kein Sort-Mutate).
		public function test_pre_get_posts_skips_non_main_queries(): void
		{
			$query = $this->makeQuery(
				[
					'post_type' => 'product',
					'orderby'   => 'sc_cost',
				],
				false /* is_main_query=false */
			);

			ProductListColumns::preGetPosts( $query );

			$this->assertArrayNotHasKey(
				'meta_key',
				$query->query_vars,
				'AC-7: Non-Main-Query darf KEIN meta_key setzen.'
			);
			$this->assertSame(
				'sc_cost',
				$query->query_vars['orderby'] ?? null,
				'AC-7: Non-Main-Query darf orderby NICHT mutieren.'
			);
		}

		// AC-7 Constraints: Non-Admin -> NO-OP.
		public function test_pre_get_posts_skips_non_admin_screens(): void
		{
			$this->isAdmin = false;

			$query = $this->makeQuery( [
				'post_type' => 'product',
				'orderby'   => 'sc_cost',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertArrayNotHasKey(
				'meta_key',
				$query->query_vars,
				'AC-7: Non-Admin Query darf KEIN meta_key setzen.'
			);
		}

		// AC-7 Constraints: Non-product post_type -> NO-OP.
		public function test_pre_get_posts_skips_non_product_post_type(): void
		{
			$query = $this->makeQuery( [
				'post_type' => 'shop_order',
				'orderby'   => 'sc_cost',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertArrayNotHasKey(
				'meta_key',
				$query->query_vars,
				'AC-7: post_type=shop_order darf KEIN meta_key setzen.'
			);
		}

		// AC-7 Constraints: ohne manage_woocommerce-Cap -> NO-OP.
		public function test_pre_get_posts_skips_when_user_lacks_capability(): void
		{
			$this->canManageWoocommerce = false;

			$query = $this->makeQuery( [
				'post_type' => 'product',
				'orderby'   => 'sc_cost',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertArrayNotHasKey(
				'meta_key',
				$query->query_vars,
				'AC-7: Cap-Failure -> KEIN meta_key Set (Capability-Guard).'
			);
		}

		// ===================================================================
		// AC-8: Filter-Dropdown rendert vier Optionen mit selected-State
		// ===================================================================

		// AC-8: GIVEN restrict_manage_posts feuert auf post_type='product'
		//       WHEN renderFilterDropdown greift
		//       THEN echo'd <select name="sc_filter">-Element mit vier Optionen.
		public function test_render_filter_dropdown_emits_four_options(): void
		{
			$_GET['sc_filter'] = 'linked';

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderFilterDropdown( 'product' )
			);

			$this->assertStringContainsString(
				'<select',
				$html,
				'AC-8: Output MUSS ein <select>-Element enthalten.'
			);
			$this->assertStringContainsString(
				'name="sc_filter"',
				$html,
				'AC-8: <select> MUSS name="sc_filter" haben.'
			);

			// Vier Options mit definierten value-Attributen.
			$this->assertMatchesRegularExpression(
				'/<option[^>]*value=""/',
				$html,
				'AC-8: Option mit value="" (All) MUSS vorhanden sein.'
			);
			$this->assertMatchesRegularExpression(
				'/<option[^>]*value="linked"/',
				$html,
				'AC-8: Option mit value="linked" MUSS vorhanden sein.'
			);
			$this->assertMatchesRegularExpression(
				'/<option[^>]*value="unlinked"/',
				$html,
				'AC-8: Option mit value="unlinked" MUSS vorhanden sein.'
			);
			$this->assertMatchesRegularExpression(
				'/<option[^>]*value="low_margin"/',
				$html,
				'AC-8: Option mit value="low_margin" MUSS vorhanden sein.'
			);

			// Labels (passthrough `__()` returns key untouched).
			$this->assertStringContainsString( 'Spreadconnect: All', $html );
			$this->assertStringContainsString( 'Linked', $html );
			$this->assertStringContainsString( 'Unlinked', $html );
			$this->assertStringContainsString( 'Margin <20%', $html );

			// Selected-State korrekt: value="linked" MUSS selected="selected" tragen.
			$this->assertMatchesRegularExpression(
				'/<option[^>]*value="linked"[^>]*selected=["\']selected["\']/',
				$html,
				'AC-8: Aktueller Wert ?sc_filter=linked MUSS selected="selected" tragen.'
			);
		}

		// AC-8: shop_order-Screen -> NO-OP (Early Return).
		public function test_render_filter_dropdown_skips_non_product_post_type(): void
		{
			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderFilterDropdown( 'shop_order' )
			);

			$this->assertSame(
				'',
				$html,
				'AC-8: Non-product post_type MUSS NO-OP sein (kein Output).'
			);
		}

		// AC-8: kein selected-State, wenn ?sc_filter nicht gesetzt — All ist default.
		public function test_render_filter_dropdown_marks_default_all_when_no_filter_set(): void
		{
			$_GET = [];  // explizit kein sc_filter

			$html = $this->captureOutput(
				static fn() => ProductListColumns::renderFilterDropdown( 'product' )
			);

			$this->assertMatchesRegularExpression(
				'/<option[^>]*value=""[^>]*selected=["\']selected["\']/',
				$html,
				'AC-8: Default = All-Option MUSS selected sein, wenn kein ?sc_filter.'
			);
		}

		// ===================================================================
		// AC-9: sc_filter=linked setzt meta_query EXISTS
		// ===================================================================

		// AC-9: GIVEN ?sc_filter=linked, Main-Query, Admin, post_type=product
		//       WHEN applyFilter im pre_get_posts-Hook greift
		//       THEN $query->set('meta_query', [['key'=>'_spreadconnect_article_id','compare'=>'EXISTS']]).
		public function test_apply_filter_linked_sets_meta_query_exists(): void
		{
			$_GET['sc_filter'] = 'linked';

			$query = $this->makeQuery( [
				'post_type' => 'product',
			] );

			ProductListColumns::preGetPosts( $query );

			$metaQuery = $query->query_vars['meta_query'] ?? null;
			$this->assertIsArray(
				$metaQuery,
				'AC-9: linked MUSS meta_query setzen.'
			);

			// Suche nach einer Klausel mit key=_spreadconnect_article_id + compare=EXISTS.
			$found = false;
			foreach ( $metaQuery as $clause ) {
				if ( is_array( $clause )
					&& ( $clause['key'] ?? null ) === '_spreadconnect_article_id'
					&& ( $clause['compare'] ?? null ) === 'EXISTS'
				) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'AC-9: meta_query MUSS Klausel [key=_spreadconnect_article_id, compare=EXISTS] enthalten.'
			);
		}

		// AC-9: sc_filter=unlinked setzt meta_query NOT EXISTS
		public function test_apply_filter_unlinked_sets_meta_query_not_exists(): void
		{
			$_GET['sc_filter'] = 'unlinked';

			$query = $this->makeQuery( [
				'post_type' => 'product',
			] );

			ProductListColumns::preGetPosts( $query );

			$metaQuery = $query->query_vars['meta_query'] ?? null;
			$this->assertIsArray( $metaQuery, 'AC-9: unlinked MUSS meta_query setzen.' );

			$found = false;
			foreach ( $metaQuery as $clause ) {
				if ( is_array( $clause )
					&& ( $clause['key'] ?? null ) === '_spreadconnect_article_id'
					&& ( $clause['compare'] ?? null ) === 'NOT EXISTS'
				) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'AC-9: meta_query MUSS [key=_spreadconnect_article_id, compare=NOT EXISTS] enthalten.'
			);
		}

		// AC-9 Constraints: existing meta_query-Eintraege werden mit relation=AND erhalten.
		public function test_apply_filter_preserves_existing_meta_query_with_and_relation(): void
		{
			$_GET['sc_filter'] = 'linked';

			$existingClause = [
				[
					'key'     => '_some_other_meta',
					'value'   => 'foo',
					'compare' => '=',
				],
			];
			$query = $this->makeQuery( [
				'post_type'  => 'product',
				'meta_query' => $existingClause,
			] );

			ProductListColumns::preGetPosts( $query );

			$metaQuery = $query->query_vars['meta_query'] ?? null;
			$this->assertIsArray( $metaQuery );

			// `relation` MUSS auf AND gesetzt sein (oder eine AND-Klausel enthalten).
			$this->assertSame(
				'AND',
				$metaQuery['relation'] ?? null,
				'AC-9 Constraints: meta_query-relation MUSS "AND" sein, wenn wir Klauseln hinzufuegen.'
			);

			// Die existierende Klausel (key=_some_other_meta) MUSS erhalten bleiben.
			$existingFound = false;
			foreach ( $metaQuery as $clause ) {
				if ( is_array( $clause ) && ( $clause['key'] ?? null ) === '_some_other_meta' ) {
					$existingFound = true;
					break;
				}
			}
			$this->assertTrue(
				$existingFound,
				'AC-9 Constraints: bestehende meta_query-Eintraege duerfen NICHT verworfen werden.'
			);
		}

		// AC-9: invalider sc_filter-Wert -> NO-OP (Whitelist-Check).
		public function test_apply_filter_ignores_unknown_filter_value(): void
		{
			$_GET['sc_filter'] = 'pwn3d-attack-vector';

			$query = $this->makeQuery( [
				'post_type' => 'product',
			] );

			ProductListColumns::preGetPosts( $query );

			$this->assertArrayNotHasKey(
				'meta_query',
				$query->query_vars,
				'AC-9 Constraints: Whitelist-Check verhindert beliebige sc_filter-Werte.'
			);
		}

		// ===================================================================
		// AC-10: sc_filter=low_margin filtert nur Produkte mit Cost+WC-Preis
		// ===================================================================

		// AC-10: GIVEN ?sc_filter=low_margin
		//        WHEN applyFilter greift
		//        THEN meta_query mit zwei Klauseln (cost EXISTS AND article_id EXISTS).
		public function test_apply_filter_low_margin_excludes_products_without_cost_or_article(): void
		{
			$_GET['sc_filter'] = 'low_margin';

			$query = $this->makeQuery( [
				'post_type' => 'product',
			] );

			ProductListColumns::preGetPosts( $query );

			$metaQuery = $query->query_vars['meta_query'] ?? null;
			$this->assertIsArray(
				$metaQuery,
				'AC-10: low_margin MUSS meta_query setzen.'
			);

			// `relation=AND` erforderlich (zwei Klauseln verkettet).
			$this->assertSame(
				'AND',
				$metaQuery['relation'] ?? null,
				'AC-10: low_margin meta_query MUSS relation="AND" haben.'
			);

			// Cost-EXISTS-Klausel.
			$costExists      = false;
			$articleIdExists = false;
			foreach ( $metaQuery as $clause ) {
				if ( ! is_array( $clause ) ) {
					continue;
				}
				if ( ( $clause['key'] ?? null ) === '_spreadconnect_cost'
					&& ( $clause['compare'] ?? null ) === 'EXISTS'
				) {
					$costExists = true;
				}
				if ( ( $clause['key'] ?? null ) === '_spreadconnect_article_id'
					&& ( $clause['compare'] ?? null ) === 'EXISTS'
				) {
					$articleIdExists = true;
				}
			}
			$this->assertTrue(
				$costExists,
				'AC-10: low_margin MUSS [_spreadconnect_cost, EXISTS]-Klausel enthalten.'
			);
			$this->assertTrue(
				$articleIdExists,
				'AC-10: low_margin MUSS [_spreadconnect_article_id, EXISTS]-Klausel enthalten.'
			);
		}

		// AC-10: low_margin Margin-Berechnung filtert nur <20%.
		// Die Implementer-Wahl (b) registriert einen `the_posts`-Post-Filter via add_filter().
		// Wir verifizieren ueber Brain\Monkey, dass der Hook registriert wurde.
		public function test_apply_filter_low_margin_registers_post_query_filter(): void
		{
			$_GET['sc_filter'] = 'low_margin';

			$query = $this->makeQuery( [
				'post_type' => 'product',
			] );

			ProductListColumns::preGetPosts( $query );

			// Implementer-Wahl (a) `posts_clauses` ODER (b) `the_posts`.
			$thePosts     = Filters\has( 'the_posts' );
			$postsClauses = Filters\has( 'posts_clauses' );

			$this->assertTrue(
				false !== $thePosts || false !== $postsClauses,
				'AC-10: low_margin MUSS einen post-query Filter (the_posts ODER posts_clauses) registrieren, '
				. 'um die <20%-Margin-Bedingung anzuwenden.'
			);
		}

		// ===================================================================
		// AC-11: Bootstrap registriert alle 5 Hooks
		// ===================================================================

		// AC-11: GIVEN Plugin-Bootstrap (Slice 02) wird initialisiert
		//        WHEN init_actions() laeuft
		//        THEN sind 5 Hooks registriert: manage_edit-product_columns,
		//             manage_product_posts_custom_column,
		//             manage_edit-product_sortable_columns,
		//             pre_get_posts, restrict_manage_posts.
		public function test_bootstrap_registers_all_required_hooks(): void
		{
			// Reset Plugin static state so init() can run again.
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			// (a) manage_edit-product_columns -> registerColumnsStatic
			$this->assertNotFalse(
				Filters\has(
					'manage_edit-product_columns',
					[ ProductListColumns::class, 'registerColumnsStatic' ]
				),
				'AC-11 (a): manage_edit-product_columns MUSS auf registerColumnsStatic gemappt sein.'
			);

			// (b) manage_product_posts_custom_column -> renderColumnStatic
			$this->assertNotFalse(
				Actions\has(
					'manage_product_posts_custom_column',
					[ ProductListColumns::class, 'renderColumnStatic' ]
				),
				'AC-11 (b): manage_product_posts_custom_column MUSS auf renderColumnStatic gemappt sein.'
			);

			// (c) manage_edit-product_sortable_columns -> registerSortableColumnsStatic
			$this->assertNotFalse(
				Filters\has(
					'manage_edit-product_sortable_columns',
					[ ProductListColumns::class, 'registerSortableColumnsStatic' ]
				),
				'AC-11 (c): manage_edit-product_sortable_columns MUSS auf registerSortableColumnsStatic gemappt sein.'
			);

			// (d) pre_get_posts -> preGetPostsStatic
			$this->assertNotFalse(
				Actions\has(
					'pre_get_posts',
					[ ProductListColumns::class, 'preGetPostsStatic' ]
				),
				'AC-11 (d): pre_get_posts MUSS auf preGetPostsStatic gemappt sein.'
			);

			// (e) restrict_manage_posts -> renderFilterDropdownStatic
			$this->assertNotFalse(
				Actions\has(
					'restrict_manage_posts',
					[ ProductListColumns::class, 'renderFilterDropdownStatic' ]
				),
				'AC-11 (e): restrict_manage_posts MUSS auf renderFilterDropdownStatic gemappt sein.'
			);
		}

		// AC-11: Static bridges sind public + static
		public function test_static_bridges_are_public_static(): void
		{
			$rc = new ReflectionClass( ProductListColumns::class );

			$bridges = [
				'registerColumnsStatic',
				'renderColumnStatic',
				'registerSortableColumnsStatic',
				'preGetPostsStatic',
				'renderFilterDropdownStatic',
			];

			foreach ( $bridges as $bridge ) {
				$this->assertTrue(
					$rc->hasMethod( $bridge ),
					sprintf( 'AC-11: ProductListColumns::%s() MUSS existieren.', $bridge )
				);
				$method = $rc->getMethod( $bridge );
				$this->assertTrue( $method->isStatic(), sprintf( 'AC-11: %s MUSS statisch sein.', $bridge ) );
				$this->assertTrue( $method->isPublic(), sprintf( 'AC-11: %s MUSS public sein.', $bridge ) );
			}
		}

		// ===================================================================
		// Zusatz: Source-Constraint-Checks (Slice-35 Constraints)
		// ===================================================================

		public function test_product_list_columns_class_is_final(): void
		{
			$rc = new ReflectionClass( ProductListColumns::class );
			$this->assertTrue(
				$rc->isFinal(),
				'Constraint: ProductListColumns MUSS `final class` sein.'
			);
		}
	}
}
