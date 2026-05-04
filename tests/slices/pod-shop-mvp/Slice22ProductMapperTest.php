<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `ProductMapper` instantiates `WC_Product_Variable`, `WC_Product_Variation`
// and `WC_Product_Attribute` directly via `new`. We do NOT load WordPress /
// WooCommerce in unit tests (Mocking Strategy `mock_external` per
// slice-22 spec) — instead, we provide minimal stub classes that track
// every set_*() call into a per-test global registry so assertions can
// inspect them.
//
// Brain\Monkey is used to alias the global WP/WC functions the mapper
// calls (`get_posts`, `term_exists`, `wp_insert_term`, `update_post_meta`,
// `wc_get_product`, `sanitize_title`, `time`).
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WC_Product_Variable', false ) ) {
		/**
		 * Minimal stub mirroring the public surface used by ProductMapper:
		 *   set_status / set_name / set_description / set_attributes /
		 *   set_image_id / set_gallery_image_ids / save / get_id
		 *
		 * Every call is recorded in `$GLOBALS['__test_wc_state']['parents']`
		 * keyed by the instance object-hash so multiple instances inside one
		 * test stay distinct.
		 */
		class WC_Product_Variable
		{
			private int $id;
			private string $hash;

			/**
			 * Children variation IDs — public so slice-34 tests can configure
			 * `$parent->children = [...]` directly without touching the
			 * global state-tracker. Slice-22's mapper does not read this
			 * field; it is purely a slice-34 affordance shared via the
			 * canonical stub.
			 *
			 * @var int[]
			 */
			public array $children = [];

			/**
			 * Public price field — slice-34 tests configure
			 * `$parent->price = '19.99'` directly. Slice-22's mapper writes
			 * variant-level prices via `WC_Product_Variation::set_price()`
			 * and never reads this field.
			 */
			public string $price = '';

			public function __construct( int $id = 0 ) {
				$this->id = $id;

				// Use a stable per-instance counter rather than spl_object_hash —
				// PHP reuses object hashes once an instance is garbage collected,
				// so two short-lived variations in a loop would otherwise share
				// a key in `$GLOBALS['__test_wc_state']`.
				$seq        = ( $GLOBALS['__test_wc_state']['parent_seq'] ?? 0 ) + 1;
				$GLOBALS['__test_wc_state']['parent_seq'] = $seq;
				$this->hash = 'parent_' . $seq;

				$GLOBALS['__test_wc_state']['parents'][ $this->hash ] = [
					'id'            => $id,
					'status'        => null,
					'name'          => null,
					'description'   => null,
					'attributes'    => null,
					'image_id'      => null,
					'gallery_ids'   => null,
					'saves'         => 0,
					'set_calls'     => [],
					'instance_seq'  => $seq,
				];
			}

			public function get_id(): int {
				return $this->id;
			}

			/**
			 * Returns the configured children IDs. Slice-22 does not call
			 * this method (mapper writes children via WC_Product_Variation
			 * `set_parent_id`), but slice-34's `ProductMetaBox` /
			 * `ProductActions` enumerate variations through `get_children()`.
			 *
			 * @return int[]
			 */
			public function get_children(): array {
				return $this->children;
			}

			/**
			 * Returns the configured price string. Slice-34 reads this via
			 * `wc_get_product()->get_price()` for the meta-box render.
			 */
			public function get_price(): string {
				return $this->price;
			}

			public function set_status( $status ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['status']      = $status;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_status', $status ];
			}

			public function set_name( $name ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['name']        = $name;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_name', $name ];
			}

			public function set_description( $description ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['description'] = $description;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_description', $description ];
			}

			public function set_attributes( array $attributes ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['attributes']  = $attributes;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_attributes', $attributes ];
			}

			public function set_image_id( $image_id ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['image_id']    = $image_id;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_image_id', $image_id ];
			}

			public function set_gallery_image_ids( array $ids ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['gallery_ids'] = $ids;
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_gallery_image_ids', $ids ];
			}

			public function set_regular_price( $price ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][]   = [ 'set_regular_price', $price ];
				$GLOBALS['__test_wc_state']['banned_calls']['set_regular_price_parent'] = ( $GLOBALS['__test_wc_state']['banned_calls']['set_regular_price_parent'] ?? 0 ) + 1;
			}

			public function set_price( $price ): void {
				$GLOBALS['__test_wc_state']['parents'][ $this->hash ]['set_calls'][] = [ 'set_price', $price ];
				$GLOBALS['__test_wc_state']['banned_calls']['set_price_parent']      = ( $GLOBALS['__test_wc_state']['banned_calls']['set_price_parent'] ?? 0 ) + 1;
			}

			/**
			 * Returns the configured save-id (incremented per fresh instance via
			 * a counter so tests can assert the resulting WC-Product-ID).
			 */
			public function save(): int {
				$state                = & $GLOBALS['__test_wc_state']['parents'][ $this->hash ];
				$state['saves']      += 1;
				if ( $this->id > 0 ) {
					return $this->id;
				}
				$next      = ( $GLOBALS['__test_wc_state']['next_parent_id'] ?? 1000 ) + 1;
				$GLOBALS['__test_wc_state']['next_parent_id'] = $next;
				$this->id  = $next;
				$state['id'] = $next;
				return $next;
			}
		}
	}

	if ( ! class_exists( 'WC_Product_Variation', false ) ) {
		/**
		 * Stub mirroring the public surface used by ProductMapper.
		 * Tracks all set_*() calls per-instance. Extends `WC_Product`
		 * (canonical stub from `tests/stubs/wc-classes.php`) so that
		 * production code's `instanceof WC_Product` checks
		 * (e.g. slice-34 `ProductActions::collectVariationSkus`) succeed
		 * when slice-22 registers the stub first.
		 */
		class WC_Product_Variation extends \WC_Product
		{
			private int $id;
			private string $hash;

			/**
			 * Public sku/price fields — slice-34 tests construct via
			 * `new WC_Product_Variation($id, $sku, $price)` and read back
			 * via `get_sku()` / `get_price()`. Slice-22 ignores these
			 * fields and goes through the set_*() trackers below.
			 */
			public string $sku   = '';
			public string $price = '';

			public function __construct( int $id = 0, string $sku = '', string $price = '' ) {
				$this->id    = $id;
				$this->sku   = $sku;
				$this->price = $price;

				// Stable per-instance counter — see WC_Product_Variable::__construct
				// for the rationale behind not using spl_object_hash().
				$seq        = ( $GLOBALS['__test_wc_state']['variation_seq'] ?? 0 ) + 1;
				$GLOBALS['__test_wc_state']['variation_seq'] = $seq;
				$this->hash = 'variation_' . $seq;

				$GLOBALS['__test_wc_state']['variations'][ $this->hash ] = [
					'id'         => $id,
					'parent_id'  => null,
					'sku'        => null,
					'status'     => null,
					'attributes' => null,
					'saves'      => 0,
					'set_calls'  => [],
					'instance_seq' => $seq,
				];
			}

			public function get_id(): int {
				return $this->id;
			}

			/**
			 * Slice-34 reads `get_sku()` after constructing the variation
			 * via the third constructor argument. Slice-22 mocks
			 * `wc_get_product` to return `null`, so this method is never
			 * exercised in slice-22's own tests.
			 */
			public function get_sku( string $context = 'view' ): string {
				return $this->sku;
			}

			/**
			 * Slice-34 reads `get_price()` for the meta-box render. Slice-22
			 * does not exercise this getter.
			 */
			public function get_price(): string {
				return $this->price;
			}

			public function set_parent_id( $parent_id ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['parent_id'] = $parent_id;
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_parent_id', $parent_id ];
			}

			public function set_sku( $sku ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['sku']         = $sku;
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_sku', $sku ];
			}

			public function set_status( $status ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['status']     = $status;
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_status', $status ];
			}

			public function set_attributes( array $attributes ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['attributes'] = $attributes;
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_attributes', $attributes ];
			}

			public function set_regular_price( $price ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][]    = [ 'set_regular_price', $price ];
				$GLOBALS['__test_wc_state']['banned_calls']['set_regular_price_variation'] = ( $GLOBALS['__test_wc_state']['banned_calls']['set_regular_price_variation'] ?? 0 ) + 1;
			}

			public function set_price( $price ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_price', $price ];
				$GLOBALS['__test_wc_state']['banned_calls']['set_price_variation']      = ( $GLOBALS['__test_wc_state']['banned_calls']['set_price_variation'] ?? 0 ) + 1;
			}

			public function set_sale_price( $price ): void {
				$GLOBALS['__test_wc_state']['variations'][ $this->hash ]['set_calls'][] = [ 'set_sale_price', $price ];
				$GLOBALS['__test_wc_state']['banned_calls']['set_sale_price_variation'] = ( $GLOBALS['__test_wc_state']['banned_calls']['set_sale_price_variation'] ?? 0 ) + 1;
			}

			public function save(): int {
				$state                = & $GLOBALS['__test_wc_state']['variations'][ $this->hash ];
				$state['saves']      += 1;
				if ( $this->id > 0 ) {
					return $this->id;
				}
				$next     = ( $GLOBALS['__test_wc_state']['next_variation_id'] ?? 5000 ) + 1;
				$GLOBALS['__test_wc_state']['next_variation_id'] = $next;
				$this->id   = $next;
				$state['id'] = $next;
				return $next;
			}
		}
	}

	if ( ! class_exists( 'WC_Product_Attribute', false ) ) {
		/**
		 * Stub mirroring the public surface used by ProductMapper.
		 * Stores all setters into public arrays so tests can read them back.
		 */
		class WC_Product_Attribute
		{
			public ?string $name      = null;
			public array $options     = [];
			public bool $visible      = false;
			public bool $variation    = false;

			public function set_name( $name ): void {
				$this->name = $name;
			}

			public function set_options( array $options ): void {
				$this->options = $options;
			}

			public function set_visible( bool $visible ): void {
				$this->visible = $visible;
			}

			public function set_variation( bool $variation ): void {
				$this->variation = $variation;
			}

			public function get_name(): ?string {
				return $this->name;
			}

			public function get_options(): array {
				return $this->options;
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Api\Dto\ArticleDetail;
	use SpreadconnectPod\Api\Dto\Money;
	use SpreadconnectPod\Api\Dto\ProductTypeColor;
	use SpreadconnectPod\Api\Dto\ProductTypeDetail;
	use SpreadconnectPod\Api\Dto\ProductTypeSize;
	use SpreadconnectPod\Api\Dto\Variant;
	use SpreadconnectPod\Catalog\ProductMapper;
	use SpreadconnectPod\Catalog\ProductMapperException;

	/**
	 * Slice 22 — Catalog\ProductMapper (Article + ProductType -> WC-Variable-Product).
	 *
	 * Acceptance Tests gegen `slice-22-product-mapper.md`.
	 *
	 * Mocking Strategy: `mock_external` (laut Slice-Spec):
	 *   - Brain\Monkey aliases `get_posts`, `term_exists`, `wp_insert_term`,
	 *     `update_post_meta`, `wc_get_product`, `sanitize_title`, `get_post_meta`.
	 *   - WC-Klassen sind via Bootstrap-Stubs (siehe oben) verfuegbar; jeder
	 *     `set_*`-Call wird in `$GLOBALS['__test_wc_state']` getrackt.
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
	 */
	final class Slice22ProductMapperTest extends TestCase
	{
		/**
		 * Captures all `update_post_meta`-Aufrufe als list of [post_id, key, value].
		 *
		 * @var list<array{0:int,1:string,2:mixed}>
		 */
		private array $metaUpdates = [];

		/**
		 * Captures alle `wp_insert_term`-Aufrufe.
		 *
		 * @var list<array{0:string,1:string,2:array<string,mixed>}>
		 */
		private array $insertedTerms = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$GLOBALS['__test_wc_state'] = [
				'parents'           => [],
				'variations'        => [],
				'banned_calls'      => [],
				'next_parent_id'    => 1000,
				// 6000 instead of 5000 so freshly-allocated variation IDs never
				// collide with the 5001/5002 ids tests use for "existing" variations.
				'next_variation_id' => 6000,
				'parent_seq'        => 0,
				'variation_seq'     => 0,
			];

			$this->metaUpdates   = [];
			$this->insertedTerms = [];

			$metaUpdates = & $this->metaUpdates;
			Functions\when( 'update_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$metaUpdates ) {
					$metaUpdates[] = [ (int) $post_id, (string) $key, $value ];
					return true;
				}
			);

			$insertedTerms = & $this->insertedTerms;
			Functions\when( 'wp_insert_term' )->alias(
				static function ( $name, $taxonomy, $args = [] ) use ( &$insertedTerms ) {
					$insertedTerms[] = [ (string) $name, (string) $taxonomy, (array) $args ];
					return [ 'term_id' => 100 + count( $insertedTerms ), 'term_taxonomy_id' => 100 ];
				}
			);

			// Default: keine bestehenden Terms — wp_insert_term wird genutzt.
			Functions\when( 'term_exists' )->justReturn( null );

			// Default: keine bestehenden Produkte (Reverse-Lookup leer; existing-variations leer).
			Functions\when( 'get_posts' )->justReturn( [] );

			// Default: keine bestehenden Produkt-Loads.
			Functions\when( 'wc_get_product' )->justReturn( null );

			// Default: get_post_meta leer (existing-variation-Index findet nichts).
			Functions\when( 'get_post_meta' )->justReturn( '' );

			// sanitize_title: deterministisch lower+dash.
			Functions\when( 'sanitize_title' )->alias(
				static function ( $title ) {
					$title = strtolower( (string) $title );
					$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
					return trim( (string) $title, '-' );
				}
			);
		}

		protected function tearDown(): void
		{
			Monkey\tearDown();
			unset( $GLOBALS['__test_wc_state'] );
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers — DTO-Builder fuer die Test-Cases.
		// -------------------------------------------------------------------

		/**
		 * @param list<array{sku:string,sizeId:string,colorId:string,price?:?Money}> $variants
		 */
		private function makeArticle(
			string $id,
			string $title,
			string $productTypeId,
			array $variants,
			?string $description = null
		): ArticleDetail {
			$variantObjs = [];
			foreach ( $variants as $v ) {
				$variantObjs[] = new Variant(
					sku: $v['sku'],
					sizeId: $v['sizeId'],
					colorId: $v['colorId'],
					priceCalculation: $v['price'] ?? null,
				);
			}

			return new ArticleDetail(
				id: $id,
				title: $title,
				productTypeId: $productTypeId,
				variants: $variantObjs,
				description: $description,
			);
		}

		/**
		 * @param list<array{id:string,label:string}> $sizes
		 * @param list<array{id:string,label:string}> $colors
		 */
		private function makeProductType( array $sizes, array $colors ): ProductTypeDetail {
			$sizeObjs  = array_map( static fn( $s ) => new ProductTypeSize( id: $s['id'], label: $s['label'] ), $sizes );
			$colorObjs = array_map( static fn( $c ) => new ProductTypeColor( id: $c['id'], label: $c['label'] ), $colors );

			return new ProductTypeDetail(
				id: 'PT-X',
				sizes: $sizeObjs,
				colors: $colorObjs,
			);
		}

		/**
		 * Find the parent stub-state by save() result id (return of upsert()).
		 *
		 * @return array<string,mixed>|null
		 */
		private function findParentStateById( int $id ): ?array
		{
			foreach ( $GLOBALS['__test_wc_state']['parents'] as $state ) {
				if ( ( $state['id'] ?? 0 ) === $id ) {
					return $state;
				}
			}
			return null;
		}

		/**
		 * Filter metaUpdates by post-id.
		 *
		 * @return list<array{0:int,1:string,2:mixed}>
		 */
		private function metaUpdatesFor( int $postId ): array
		{
			$out = [];
			foreach ( $this->metaUpdates as $row ) {
				if ( $row[0] === $postId ) {
					$out[] = $row;
				}
			}
			return $out;
		}

		// ===================================================================
		// AC-1: Neuer Article -> WC_Product_Variable + Variations + Meta angelegt.
		// ===================================================================

		public function test_ac1_upsert_creates_new_variable_product_with_variations(): void
		{
			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[
					[ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '12.50', 'EUR' ) ],
					[ 'sku' => 'SC-M-RED', 'sizeId' => 'sz-M', 'colorId' => 'co-RED', 'price' => new Money( '12.50', 'EUR' ) ],
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ], [ 'id' => 'sz-M', 'label' => 'M' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt, [] );

			$this->assertGreaterThan( 0, $id, 'AC-1: upsert() MUSS positive WC-Product-ID liefern.' );

			// Parent — name + status gesetzt, save() aufgerufen.
			$parent = $this->findParentStateById( $id );
			$this->assertNotNull( $parent, 'AC-1: Parent-Stub-State MUSS existieren.' );
			$this->assertSame( 'T-Shirt Demo', $parent['name'], 'AC-1: Parent-Name MUSS aus DTO uebernommen werden.' );
			$this->assertSame( 'publish', $parent['status'], 'AC-1: Parent-Status MUSS publish sein.' );
			$this->assertGreaterThanOrEqual( 1, $parent['saves'], 'AC-1: Parent muss save()-d sein.' );

			// Genau 2 Variations (SKU SC-S-RED + SC-M-RED).
			$skus = [];
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( null !== $v['sku'] ) {
					$skus[] = $v['sku'];
				}
			}
			$this->assertContains( 'SC-S-RED', $skus, 'AC-1: Variation mit SKU SC-S-RED MUSS angelegt sein.' );
			$this->assertContains( 'SC-M-RED', $skus, 'AC-1: Variation mit SKU SC-M-RED MUSS angelegt sein.' );
		}

		public function test_ac1_upsert_writes_tracking_meta_on_parent(): void
		{
			$startTs = time();

			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[
					[ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '12.50', 'EUR' ) ],
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt );

			$parentMeta = $this->metaUpdatesFor( $id );

			// Build map [key => value] (last-wins).
			$meta = [];
			foreach ( $parentMeta as $row ) {
				$meta[ $row[1] ] = $row[2];
			}

			$this->assertSame(
				'ART-1',
				$meta['_spreadconnect_article_id'] ?? null,
				'AC-1: Meta _spreadconnect_article_id MUSS = "ART-1" sein.'
			);
			$this->assertSame(
				'PT-42',
				$meta['_spreadconnect_product_type_id'] ?? null,
				'AC-1: Meta _spreadconnect_product_type_id MUSS = "PT-42" sein.'
			);
			$this->assertSame(
				'12.50',
				$meta['_spreadconnect_cost'] ?? null,
				'AC-1: Meta _spreadconnect_cost MUSS = "12.50" sein.'
			);
			$this->assertSame(
				'EUR',
				$meta['_spreadconnect_cost_currency'] ?? null,
				'AC-1: Meta _spreadconnect_cost_currency MUSS = "EUR" sein.'
			);
			$this->assertSame(
				'synced',
				$meta['_spreadconnect_sync_state'] ?? null,
				'AC-1: Meta _spreadconnect_sync_state MUSS = "synced" sein.'
			);
			$this->assertArrayHasKey(
				'_spreadconnect_last_sync',
				$meta,
				'AC-1: Meta _spreadconnect_last_sync MUSS gesetzt sein.'
			);
			$this->assertGreaterThanOrEqual(
				$startTs,
				(int) $meta['_spreadconnect_last_sync'],
				'AC-1: _spreadconnect_last_sync MUSS >= Test-Startzeit sein (Unix-Timestamp).'
			);
		}

		// ===================================================================
		// AC-2: Idempotenz via meta_query reverse-lookup.
		// ===================================================================

		public function test_ac2_upsert_is_idempotent_via_article_id_reverse_lookup(): void
		{
			// Reverse-Lookup findet ein bestehendes Produkt mit ID 777.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					if ( ( $args['post_type'] ?? '' ) === 'product_variation' ) {
						return [];
					}
					return [];
				}
			);

			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					if ( 777 === (int) $id ) {
						return new \WC_Product_Variable( 777 );
					}
					return null;
				}
			);

			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '12.50', 'EUR' ) ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt );

			$this->assertSame( 777, $id, 'AC-2: Reverse-Lookup MUSS bestehende Product-ID 777 zurueckliefern.' );

			// Bei Re-Sync darf KEIN frischer Parent mit auto-generierter ID
			// (>= next_parent_id+1 = 1001) angelegt werden — der vorhandene
			// Parent (id=777) MUSS via wc_get_product() geladen sein.
			$freshParentsAutoId = 0;
			$loadedExistingParent = 0;
			foreach ( $GLOBALS['__test_wc_state']['parents'] as $state ) {
				if ( ( $state['id'] ?? 0 ) >= 1001 ) {
					$freshParentsAutoId++;
				}
				if ( ( $state['id'] ?? 0 ) === 777 ) {
					$loadedExistingParent++;
				}
			}
			$this->assertGreaterThanOrEqual(
				1,
				$loadedExistingParent,
				'AC-2: Existierender Parent (id=777) MUSS via wc_get_product() geladen sein.'
			);
			$this->assertSame(
				0,
				$freshParentsAutoId,
				'AC-2: Bei Re-Sync darf KEIN frischer Parent (auto-id >= 1001) angelegt werden.'
			);
		}

		public function test_ac2_upsert_returns_same_product_id_on_repeat_call(): void
		{
			// 1. Aufruf — frisch.
			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '12.50', 'EUR' ) ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id1    = $mapper->upsert( $article, $pt );

			// 2. Aufruf — Reverse-Lookup soll dieselbe ID liefern.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) use ( $id1 ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ $id1 ];
					}
					return [];
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				static function ( $loadId ) use ( $id1 ) {
					if ( (int) $loadId === $id1 ) {
						return new \WC_Product_Variable( $id1 );
					}
					return null;
				}
			);

			$id2 = $mapper->upsert( $article, $pt );

			$this->assertSame(
				$id1,
				$id2,
				'AC-2: Wiederholte upsert()-Calls MUESSEN dieselbe Product-ID liefern (Idempotenz).'
			);
		}

		// ===================================================================
		// AC-3: _regular_price niemals geschrieben; _spreadconnect_cost ueberschrieben.
		// ===================================================================

		public function test_ac3_upsert_never_writes_wc_price_on_resync(): void
		{
			// Re-Sync: bestehender Parent wird geladen.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					if ( ( $args['post_type'] ?? '' ) === 'product_variation' ) {
						return [ 5001 ]; // bestehende Variation
					}
					return [];
				}
			);

			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					if ( 777 === (int) $id ) {
						return new \WC_Product_Variable( 777 );
					}
					if ( 5001 === (int) $id ) {
						return new \WC_Product_Variation( 5001 );
					}
					return null;
				}
			);

			Functions\when( 'get_post_meta' )->alias(
				static function ( $id, $key, $single = false ) {
					if ( 5001 === (int) $id && '_spreadconnect_sku' === $key ) {
						return 'SC-S-RED';
					}
					return '';
				}
			);

			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '15.00', 'EUR' ) ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$mapper->upsert( $article, $pt );

			$banned = $GLOBALS['__test_wc_state']['banned_calls'] ?? [];
			$this->assertSame(
				0,
				$banned['set_regular_price_parent'] ?? 0,
				'AC-3: Mapper darf set_regular_price() auf Parent NIEMALS aufrufen.'
			);
			$this->assertSame(
				0,
				$banned['set_regular_price_variation'] ?? 0,
				'AC-3: Mapper darf set_regular_price() auf Variation NIEMALS aufrufen.'
			);
			$this->assertSame(
				0,
				$banned['set_price_parent'] ?? 0,
				'AC-3: Mapper darf set_price() auf Parent NIEMALS aufrufen.'
			);
			$this->assertSame(
				0,
				$banned['set_price_variation'] ?? 0,
				'AC-3: Mapper darf set_price() auf Variation NIEMALS aufrufen.'
			);
			$this->assertSame(
				0,
				$banned['set_sale_price_variation'] ?? 0,
				'AC-3: Mapper darf set_sale_price() auf Variation NIEMALS aufrufen.'
			);
		}

		public function test_ac3_upsert_updates_spreadconnect_cost_on_resync(): void
		{
			// Re-Sync mit neuem Cost-Wert.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					return [];
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					return 777 === (int) $id ? new \WC_Product_Variable( 777 ) : null;
				}
			);

			$article = $this->makeArticle(
				'ART-1',
				'T-Shirt Demo',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED', 'price' => new Money( '15.00', 'EUR' ) ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt );

			$meta = [];
			foreach ( $this->metaUpdatesFor( $id ) as $row ) {
				$meta[ $row[1] ] = $row[2];
			}

			$this->assertSame(
				'15.00',
				$meta['_spreadconnect_cost'] ?? null,
				'AC-3: _spreadconnect_cost MUSS bei Re-Sync mit neuem priceCalculation.amount ueberschrieben werden.'
			);
		}

		// ===================================================================
		// AC-4: pa_groesse + pa_farbe Attributes + 6 Variations.
		// ===================================================================

		public function test_ac4_upsert_sets_variation_attributes_on_parent(): void
		{
			$article = $this->makeArticle(
				'ART-2',
				'Tee',
				'PT-42',
				[
					[ 'sku' => 'SC-S-BLK', 'sizeId' => 'sz-S', 'colorId' => 'co-BLK' ],
					[ 'sku' => 'SC-M-BLK', 'sizeId' => 'sz-M', 'colorId' => 'co-BLK' ],
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ], [ 'id' => 'sz-M', 'label' => 'M' ] ],
				[ [ 'id' => 'co-BLK', 'label' => 'Schwarz' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt );

			$parent = $this->findParentStateById( $id );
			$this->assertNotNull( $parent );

			$attributes = $parent['attributes'];
			$this->assertIsArray( $attributes );
			$this->assertCount( 2, $attributes, 'AC-4: Parent MUSS genau 2 Attribute haben (pa_groesse + pa_farbe).' );

			$names = array_map( static fn( $a ) => $a->name, $attributes );
			$this->assertContains( 'pa_groesse', $names, 'AC-4: Parent-Attribute MUSS pa_groesse enthalten.' );
			$this->assertContains( 'pa_farbe', $names, 'AC-4: Parent-Attribute MUSS pa_farbe enthalten.' );

			foreach ( $attributes as $attr ) {
				$this->assertTrue( $attr->variation, 'AC-4: Attribute ' . $attr->name . ' MUSS variation=true haben.' );
				$this->assertTrue( $attr->visible, 'AC-4: Attribute ' . $attr->name . ' MUSS visible=true haben.' );
			}
		}

		public function test_ac4_upsert_creates_one_variation_per_size_color_combination(): void
		{
			$article = $this->makeArticle(
				'ART-3',
				'Tee 6V',
				'PT-42',
				[
					[ 'sku' => 'SC-S-BLK', 'sizeId' => 'sz-S', 'colorId' => 'co-BLK' ],
					[ 'sku' => 'SC-M-BLK', 'sizeId' => 'sz-M', 'colorId' => 'co-BLK' ],
					[ 'sku' => 'SC-L-BLK', 'sizeId' => 'sz-L', 'colorId' => 'co-BLK' ],
					[ 'sku' => 'SC-S-WHT', 'sizeId' => 'sz-S', 'colorId' => 'co-WHT' ],
					[ 'sku' => 'SC-M-WHT', 'sizeId' => 'sz-M', 'colorId' => 'co-WHT' ],
					[ 'sku' => 'SC-L-WHT', 'sizeId' => 'sz-L', 'colorId' => 'co-WHT' ],
				]
			);
			$pt = $this->makeProductType(
				[
					[ 'id' => 'sz-S', 'label' => 'S' ],
					[ 'id' => 'sz-M', 'label' => 'M' ],
					[ 'id' => 'sz-L', 'label' => 'L' ],
				],
				[
					[ 'id' => 'co-BLK', 'label' => 'Schwarz' ],
					[ 'id' => 'co-WHT', 'label' => 'Weiss' ],
				]
			);

			$mapper = new ProductMapper();
			$mapper->upsert( $article, $pt );

			// Genau 6 Variations gespeichert.
			$savedVariations = 0;
			$skuMap          = [];
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( $v['saves'] >= 1 && null !== $v['sku'] ) {
					$savedVariations++;
					$skuMap[ $v['sku'] ] = $v;
				}
			}

			$this->assertSame( 6, $savedVariations, 'AC-4: 3 Sizes x 2 Colors MUSS exakt 6 Variations ergeben.' );
			$this->assertCount( 6, $skuMap, 'AC-4: Alle 6 SKUs MUESSEN distinkt sein.' );

			// Pro Variation pa_groesse + pa_farbe gesetzt.
			foreach ( $skuMap as $sku => $variation ) {
				$attr = $variation['attributes'];
				$this->assertIsArray( $attr );
				$this->assertArrayHasKey( 'pa_groesse', $attr, "AC-4: Variation {$sku} MUSS pa_groesse-Attribute haben." );
				$this->assertArrayHasKey( 'pa_farbe', $attr, "AC-4: Variation {$sku} MUSS pa_farbe-Attribute haben." );
			}
		}

		public function test_ac4_upsert_writes_variation_meta_per_variation(): void
		{
			$article = $this->makeArticle(
				'ART-4',
				'Tee',
				'PT-42',
				[
					[ 'sku' => 'SC-S-BLK', 'sizeId' => 'sz-S', 'colorId' => 'co-BLK' ],
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-BLK', 'label' => 'Schwarz' ] ]
			);

			$mapper = new ProductMapper();
			$mapper->upsert( $article, $pt );

			// Find variation id from saves.
			$variationId = 0;
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( 'SC-S-BLK' === $v['sku'] ) {
					$variationId = $v['id'];
				}
			}
			$this->assertGreaterThan( 0, $variationId, 'AC-4: Variation MUSS save()-d sein und positive ID haben.' );

			$meta = [];
			foreach ( $this->metaUpdatesFor( $variationId ) as $row ) {
				$meta[ $row[1] ] = $row[2];
			}

			$this->assertSame( 'SC-S-BLK', $meta['_spreadconnect_sku'] ?? null, 'AC-4: Variation _spreadconnect_sku MUSS gesetzt sein.' );
			$this->assertSame( 'sz-S', $meta['_spreadconnect_size_id'] ?? null, 'AC-4: Variation _spreadconnect_size_id MUSS gesetzt sein.' );
			$this->assertSame( 'co-BLK', $meta['_spreadconnect_color_id'] ?? null, 'AC-4: Variation _spreadconnect_color_id MUSS gesetzt sein.' );
		}

		// ===================================================================
		// AC-5: Featured + gallery images NUR bei isNew && attachmentIds.
		// ===================================================================

		public function test_ac5_upsert_sets_featured_and_gallery_images_on_create(): void
		{
			$article = $this->makeArticle(
				'ART-5',
				'Tee',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt, [ 101, 102, 103 ] );

			$parent = $this->findParentStateById( $id );
			$this->assertNotNull( $parent );

			$this->assertSame( 101, $parent['image_id'], 'AC-5: Featured Image MUSS attachmentIds[0]=101 sein.' );
			$this->assertSame( [ 102, 103 ], $parent['gallery_ids'], 'AC-5: Gallery Images MUSS [102,103] sein.' );
		}

		public function test_ac5_upsert_skips_images_when_attachment_ids_empty(): void
		{
			// Re-Sync mit bestehendem Produkt + leeren Attachment-IDs.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					return [];
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					return 777 === (int) $id ? new \WC_Product_Variable( 777 ) : null;
				}
			);

			$article = $this->makeArticle(
				'ART-5',
				'Tee',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt, [] );

			$parent = $this->findParentStateById( $id );
			$this->assertNotNull( $parent );

			$this->assertNull( $parent['image_id'], 'AC-5: image_id darf bei leerer attachment-IDs-Liste NICHT gesetzt werden.' );
			$this->assertNull( $parent['gallery_ids'], 'AC-5: gallery_ids darf bei leerer attachment-IDs-Liste NICHT gesetzt werden.' );

			// Also explicitly: KEIN set_image_id-Call im set_calls-Log.
			$calls = array_column( $parent['set_calls'], 0 );
			$this->assertNotContains( 'set_image_id', $calls, 'AC-5: set_image_id darf NIE aufgerufen werden bei leerer Liste.' );
			$this->assertNotContains( 'set_gallery_image_ids', $calls, 'AC-5: set_gallery_image_ids darf NIE aufgerufen werden bei leerer Liste.' );
		}

		public function test_ac5_upsert_skips_images_on_resync_even_with_attachment_ids(): void
		{
			// Re-Sync (existing parent) — auch mit attachmentIds darf der Mapper Bilder NICHT anfassen.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					return [];
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					return 777 === (int) $id ? new \WC_Product_Variable( 777 ) : null;
				}
			);

			$article = $this->makeArticle(
				'ART-5',
				'Tee',
				'PT-42',
				[ [ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ] ]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt, [ 101, 102 ] );

			$parent = $this->findParentStateById( $id );
			$this->assertNotNull( $parent );

			$calls = array_column( $parent['set_calls'], 0 );
			$this->assertNotContains(
				'set_image_id',
				$calls,
				'AC-5: Bei Re-Sync (vorhandenes Produkt) darf Mapper set_image_id NICHT erneut aufrufen.'
			);
			$this->assertNotContains(
				'set_gallery_image_ids',
				$calls,
				'AC-5: Bei Re-Sync (vorhandenes Produkt) darf Mapper set_gallery_image_ids NICHT erneut aufrufen.'
			);
		}

		// ===================================================================
		// AC-6: Variant ohne priceCalculation -> _spreadconnect_cost nicht gesetzt.
		// ===================================================================

		public function test_ac6_upsert_handles_variant_without_price_calculation(): void
		{
			$article = $this->makeArticle(
				'ART-6',
				'Tee NoPrice',
				'PT-42',
				[
					[ 'sku' => 'SC-S-NP', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ], // kein price
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$id     = $mapper->upsert( $article, $pt );

			$meta = [];
			foreach ( $this->metaUpdatesFor( $id ) as $row ) {
				$meta[ $row[1] ] = $row[2];
			}

			// _spreadconnect_cost darf NICHT gesetzt sein.
			$this->assertArrayNotHasKey(
				'_spreadconnect_cost',
				$meta,
				'AC-6: Bei Variant ohne priceCalculation darf _spreadconnect_cost NICHT gesetzt werden.'
			);
			$this->assertArrayNotHasKey(
				'_spreadconnect_cost_currency',
				$meta,
				'AC-6: Bei Variant ohne priceCalculation darf _spreadconnect_cost_currency NICHT gesetzt werden.'
			);

			// Andere Felder MUESSEN trotzdem gesetzt sein.
			$this->assertSame( 'ART-6', $meta['_spreadconnect_article_id'] ?? null );
			$this->assertSame( 'PT-42', $meta['_spreadconnect_product_type_id'] ?? null );
			$this->assertSame( 'synced', $meta['_spreadconnect_sync_state'] ?? null );

			// Variation MUSS trotzdem angelegt sein.
			$skus = [];
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( null !== $v['sku'] ) {
					$skus[] = $v['sku'];
				}
			}
			$this->assertContains( 'SC-S-NP', $skus, 'AC-6: Variation MUSS auch ohne priceCalculation angelegt sein.' );
		}

		// ===================================================================
		// AC-7: Leere Variants-Liste -> ProductMapperException.
		// ===================================================================

		public function test_ac7_upsert_throws_on_empty_variants_list(): void
		{
			$article = $this->makeArticle(
				'ART-7',
				'Empty',
				'PT-42',
				[]
			);
			$pt = $this->makeProductType( [], [] );

			$mapper = new ProductMapper();

			try {
				$mapper->upsert( $article, $pt );
				$this->fail( 'AC-7: upsert() MUSS bei leerer Variants-Liste ProductMapperException werfen.' );
			} catch ( ProductMapperException $e ) {
				$this->assertInstanceOf(
					\RuntimeException::class,
					$e,
					'AC-7: ProductMapperException MUSS \\RuntimeException erweitern (Action-Scheduler permanent failure).'
				);
				$this->assertStringContainsString(
					'no variants',
					$e->getMessage(),
					'AC-7: Exception-Message MUSS Substring "no variants" enthalten.'
				);
			}

			// KEIN Parent angelegt, KEIN Variation, KEIN Meta.
			$this->assertSame( [], $GLOBALS['__test_wc_state']['parents'] ?? [], 'AC-7: KEIN Parent darf angelegt sein.' );
			$this->assertSame( [], $GLOBALS['__test_wc_state']['variations'] ?? [], 'AC-7: KEINE Variation darf angelegt sein.' );
			$this->assertSame( [], $this->metaUpdates, 'AC-7: KEIN update_post_meta darf erfolgt sein.' );
		}

		// ===================================================================
		// AC-8: Obsolete variations -> status='private'.
		// ===================================================================

		public function test_ac8_upsert_archives_obsolete_variations_instead_of_deleting(): void
		{
			// Bestehende Variations: 5001 (SC-S-RED), 5002 (SC-M-RED).
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					if ( ( $args['post_type'] ?? '' ) === 'product_variation' ) {
						return [ 5001, 5002 ];
					}
					return [];
				}
			);

			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					if ( 777 === (int) $id ) {
						return new \WC_Product_Variable( 777 );
					}
					if ( 5001 === (int) $id ) {
						return new \WC_Product_Variation( 5001 );
					}
					if ( 5002 === (int) $id ) {
						return new \WC_Product_Variation( 5002 );
					}
					return null;
				}
			);

			Functions\when( 'get_post_meta' )->alias(
				static function ( $id, $key, $single = false ) {
					if ( '_spreadconnect_sku' === $key ) {
						if ( 5001 === (int) $id ) {
							return 'SC-S-RED';
						}
						if ( 5002 === (int) $id ) {
							return 'SC-M-RED';
						}
					}
					return '';
				}
			);

			// Neuer Article: SC-M-RED fehlt, SC-L-RED ist neu.
			$article = $this->makeArticle(
				'ART-1',
				'Tee',
				'PT-42',
				[
					[ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ],
					[ 'sku' => 'SC-L-RED', 'sizeId' => 'sz-L', 'colorId' => 'co-RED' ],
				]
			);
			$pt = $this->makeProductType(
				[
					[ 'id' => 'sz-S', 'label' => 'S' ],
					[ 'id' => 'sz-L', 'label' => 'L' ],
				],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$mapper->upsert( $article, $pt );

			// Variation 5002 (SC-M-RED) MUSS auf status='private' gesetzt UND saved.
			$archivedFound = false;
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( 5002 === (int) ( $v['id'] ?? 0 ) ) {
					$this->assertSame(
						'private',
						$v['status'],
						'AC-8: Veraltete Variation MUSS status=private bekommen, nicht delete.'
					);
					$this->assertGreaterThanOrEqual( 1, $v['saves'], 'AC-8: Archivierte Variation MUSS save()-d sein.' );
					$archivedFound = true;
				}
			}
			$this->assertTrue( $archivedFound, 'AC-8: Variation 5002 (SC-M-RED) MUSS gefunden + archiviert werden.' );
		}

		public function test_ac8_upsert_adds_new_variation_without_touching_unchanged_ones(): void
		{
			// Bestehende Variations: 5001 (SC-S-RED).
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product' ) {
						return [ 777 ];
					}
					if ( ( $args['post_type'] ?? '' ) === 'product_variation' ) {
						return [ 5001 ];
					}
					return [];
				}
			);

			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) {
					if ( 777 === (int) $id ) {
						return new \WC_Product_Variable( 777 );
					}
					if ( 5001 === (int) $id ) {
						return new \WC_Product_Variation( 5001 );
					}
					return null;
				}
			);

			Functions\when( 'get_post_meta' )->alias(
				static function ( $id, $key, $single = false ) {
					if ( 5001 === (int) $id && '_spreadconnect_sku' === $key ) {
						return 'SC-S-RED';
					}
					return '';
				}
			);

			$article = $this->makeArticle(
				'ART-1',
				'Tee',
				'PT-42',
				[
					[ 'sku' => 'SC-S-RED', 'sizeId' => 'sz-S', 'colorId' => 'co-RED' ],
					[ 'sku' => 'SC-L-RED', 'sizeId' => 'sz-L', 'colorId' => 'co-RED' ], // neu
				]
			);
			$pt = $this->makeProductType(
				[ [ 'id' => 'sz-S', 'label' => 'S' ], [ 'id' => 'sz-L', 'label' => 'L' ] ],
				[ [ 'id' => 'co-RED', 'label' => 'Rot' ] ]
			);

			$mapper = new ProductMapper();
			$mapper->upsert( $article, $pt );

			// Sammle alle Variations nach SKU.
			$bySku = [];
			foreach ( $GLOBALS['__test_wc_state']['variations'] as $v ) {
				if ( null !== $v['sku'] ) {
					$bySku[ $v['sku'] ] = $v;
				}
			}

			$this->assertArrayHasKey( 'SC-S-RED', $bySku, 'AC-8: bestehende SC-S-RED MUSS gefunden werden.' );
			$this->assertArrayHasKey( 'SC-L-RED', $bySku, 'AC-8: neue SC-L-RED MUSS angelegt werden.' );

			// SC-S-RED behaelt id=5001 (kein Re-Insert).
			$this->assertSame( 5001, $bySku['SC-S-RED']['id'], 'AC-8: bestehende Variation MUSS dieselbe ID behalten.' );

			// SC-L-RED bekommt eine NEUE, frisch generierte ID (>= 6001 — der
			// Test-Stub vergibt frische IDs ab `next_variation_id`+1 = 6001 aufwaerts,
			// damit Kollisionen mit den hardcoded 5001/5002 fuer existing-vars
			// ausgeschlossen sind).
			$this->assertGreaterThanOrEqual(
				6001,
				$bySku['SC-L-RED']['id'],
				'AC-8: neue Variation MUSS eine frisch generierte ID bekommen (>= 6001).'
			);
			$this->assertNotSame( 5001, $bySku['SC-L-RED']['id'], 'AC-8: neue Variation darf NICHT die ID der bestehenden uebernehmen.' );
		}
	}
}
