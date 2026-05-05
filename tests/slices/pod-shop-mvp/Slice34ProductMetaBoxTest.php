<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// Slice 34 testet `Inline\ProductMetaBox` (server-side meta-box render +
// register / enqueueAssets) und `Hub\Ajax\ProductActions` (search / link /
// unlink / refreshStock AJAX-Handler).
//
// Mocking-Strategy `mock_external` (laut Slice-Spec Z. 28):
//
//   - Brain\Monkey fuer alle WP-Funktionen (`add_meta_box`, `add_action`,
//     `wp_enqueue_script`, `wp_localize_script`, `current_user_can`,
//     `check_ajax_referer`, `wp_send_json_*`, `get_post_meta`,
//     `update_post_meta`, `delete_post_meta`, `get_transient`,
//     `set_transient`, `as_enqueue_async_action`, `wc_get_product`,
//     `wp_create_nonce`, `admin_url`, `plugins_url`, `wp_unslash`,
//     `sanitize_text_field`).
//   - `SpreadconnectClient` ist NICHT final -> klassischer `Mockery::mock()`,
//     in den Konstruktor von `ProductActions` injiziert.
//   - `WC_Product_Variable` / `WC_Product_Variation` als minimale Stub-
//     Klassen (idempotent gegenueber Slice 22).
//   - `wp_send_json_*` werfen `Slice34JsonResponseSentinel` damit Tests die
//     Response inspizieren koennen ohne dass der PHPUnit-Prozess via `die()`
//     terminiert.
//
// Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Post', false ) ) {
		/**
		 * Minimal `WP_Post` stub — only the `ID` field is read by
		 * `ProductMetaBox::render()`.
		 */
		class WP_Post
		{
			public int $ID = 0;

			public function __construct( int $id = 0 ) {
				$this->ID = $id;
			}
		}
	}

	if ( ! class_exists( 'WC_Product_Variable', false ) ) {
		/**
		 * Minimal `WC_Product_Variable` stub. Tests configure the per-instance
		 * children + price via constructor / setter.
		 */
		class WC_Product_Variable
		{
			/** @var int[] */
			public array $children = [];
			public string $price   = '';

			public function __construct( int $id = 0 ) {
				$this->id = $id;
			}

			public int $id = 0;

			public function get_children(): array {
				return $this->children;
			}

			public function get_price(): string {
				return $this->price;
			}

			public function get_id(): int {
				return $this->id;
			}
		}
	}

	if ( ! class_exists( 'WC_Product_Variation', false ) ) {
		/**
		 * Minimal `WC_Product_Variation` stub. Production code reads `get_sku()`
		 * + `get_price()`.
		 */
		class WC_Product_Variation extends WC_Product
		{
			public string $sku   = '';
			public string $price = '';

			public function __construct( int $id = 0, string $sku = '', string $price = '' ) {
				$this->id    = $id;
				$this->sku   = $sku;
				$this->price = $price;
			}

			public int $id = 0;

			public function get_sku( string $context = 'view' ): string {
				return $this->sku;
			}

			public function get_price(): string {
				return $this->price;
			}

			public function get_id(): int {
				return $this->id;
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\Dto\ArticleSummary;
	use SpreadconnectPod\Api\Dto\StockEntry;
	use SpreadconnectPod\Api\Dto\Variant;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Hub\Ajax\ProductActions;
	use SpreadconnectPod\Inline\ProductMetaBox;
	use WC_Product_Variable;
	use WC_Product_Variation;
	use WP_Post;

	/**
	 * Sentinel exception thrown by the `wp_send_json_*` stubs so tests can
	 * inspect the captured payload + status.
	 */
	final class Slice34JsonResponseSentinel extends \RuntimeException
	{
		/** @var array<string,mixed> */
		public array $payload;
		public bool $success;
		public ?int $status;

		/**
		 * @param array<string,mixed> $payload
		 */
		public function __construct( bool $success, array $payload, ?int $status )
		{
			parent::__construct( $success ? 'wp_send_json_success' : 'wp_send_json_error' );
			$this->success = $success;
			$this->payload = $payload;
			$this->status  = $status;
		}
	}

	/**
	 * Slice 34 — Product-Edit Meta-Box (Article-Picker + Margin + Stock).
	 *
	 * Acceptance Tests gegen `slice-34-product-meta-box-margin-stock.md`.
	 * JS-Tests sind explizit out-of-scope (kein JS-Runner); Margin-Live-Recalc
	 * + 300ms-Debounce werden via Manual-QA / Browser geprueft.
	 */
	final class Slice34ProductMetaBoxTest extends TestCase
	{
		/**
		 * Backing store for `get_post_meta` lookups (per test).
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private array $postMeta = [];

		/**
		 * Captured `update_post_meta` calls — list of [post_id, key, value].
		 *
		 * @var list<array{0:int,1:string,2:mixed}>
		 */
		private array $metaUpdates = [];

		/**
		 * Captured `delete_post_meta` calls — list of [post_id, key].
		 *
		 * @var list<array{0:int,1:string}>
		 */
		private array $metaDeletes = [];

		/**
		 * Backing store for `get_transient` lookups.
		 *
		 * @var array<string, mixed>
		 */
		private array $transientStore = [];

		/**
		 * Captured `set_transient` calls — list of [key, value, ttl].
		 *
		 * @var list<array{0:string,1:mixed,2:int}>
		 */
		private array $transientWrites = [];

		/**
		 * Captured `as_enqueue_async_action` calls — list of [hook, args, group].
		 *
		 * @var list<array{0:string,1:array,2:string}>
		 */
		private array $asEnqueues = [];

		/**
		 * Backing for `wc_get_product` -> WC_Product_Variable / WC_Product_Variation.
		 *
		 * @var array<int, mixed>
		 */
		private array $wcProductsById = [];

		/**
		 * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
		 */
		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->postMeta        = [];
			$this->metaUpdates     = [];
			$this->metaDeletes     = [];
			$this->transientStore  = [];
			$this->transientWrites = [];
			$this->asEnqueues      = [];
			$this->wcProductsById  = [];

			// ---- i18n + escape passthrough -------------------------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( '_e' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_html_e' )->alias( static function ( $text, $domain = '' ) {
				echo $text;
			} );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'wp_date' )->alias( static fn( $fmt, $ts ) => date( $fmt, (int) $ts ) );

			// ---- Sanitize helpers ---------------------------------------------
			Functions\when( 'wp_unslash' )->alias( static function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			} );
			Functions\when( 'sanitize_text_field' )->alias( static function ( $value ) {
				if ( ! is_string( $value ) ) {
					return '';
				}
				$clean = strip_tags( $value );
				$clean = preg_replace( '/[\r\n\t]+/', ' ', $clean ) ?? '';
				return trim( $clean );
			} );

			// ---- Postmeta read / write ----------------------------------------
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

			$metaUpdates = & $this->metaUpdates;
			$postMetaRef = & $this->postMeta;
			Functions\when( 'update_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$metaUpdates, &$postMetaRef ) {
					$pid                              = (int) $post_id;
					$metaUpdates[]                    = [ $pid, (string) $key, $value ];
					$postMetaRef[ $pid ][ (string) $key ] = $value;
					return true;
				}
			);

			$metaDeletes  = & $this->metaDeletes;
			$postMetaRef2 = & $this->postMeta;
			Functions\when( 'delete_post_meta' )->alias(
				static function ( $post_id, $key, $value = '' ) use ( &$metaDeletes, &$postMetaRef2 ) {
					$pid           = (int) $post_id;
					$metaDeletes[] = [ $pid, (string) $key ];
					if ( isset( $postMetaRef2[ $pid ][ (string) $key ] ) ) {
						unset( $postMetaRef2[ $pid ][ (string) $key ] );
					}
					return true;
				}
			);

			// ---- Transients ----------------------------------------------------
			$transientStore = & $this->transientStore;
			Functions\when( 'get_transient' )->alias(
				static function ( $key ) use ( &$transientStore ) {
					return $transientStore[ (string) $key ] ?? false;
				}
			);

			$transientWrites = & $this->transientWrites;
			$transientStore2 = & $this->transientStore;
			Functions\when( 'set_transient' )->alias(
				static function ( $key, $value, $ttl = 0 ) use ( &$transientWrites, &$transientStore2 ) {
					$transientWrites[]                    = [ (string) $key, $value, (int) $ttl ];
					$transientStore2[ (string) $key ] = $value;
					return true;
				}
			);

			// ---- Action-Scheduler enqueue spy ---------------------------------
			$asEnqueues = & $this->asEnqueues;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( $hook, $args = [], $group = '' ) use ( &$asEnqueues ) {
					$asEnqueues[] = [ (string) $hook, (array) $args, (string) $group ];
					return 1;
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

			// ---- WP misc / URL helpers ----------------------------------------
			Functions\when( 'admin_url' )->alias( static function ( $path = '' ) {
				return 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' );
			} );
			Functions\when( 'plugins_url' )->alias( static function ( $path = '', $plugin = '' ) {
				return 'http://example.test/wp-content/plugins/spreadconnect-pod/' . ltrim( (string) $path, '/' );
			} );
			Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'NONCE_' . (string) $action );

			Functions\when( 'add_meta_box' )->justReturn( null );
			Functions\when( 'wp_enqueue_script' )->justReturn( null );
			Functions\when( 'wp_localize_script' )->justReturn( null );

			// ---- wp_send_json_* — sentinels so tests can catch the response ----
			Functions\when( 'wp_send_json_success' )->alias(
				static function ( $data = null, $status = null ) {
					throw new Slice34JsonResponseSentinel( true, (array) $data, $status );
				}
			);
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data = null, $status = null ) {
					throw new Slice34JsonResponseSentinel( false, (array) $data, $status );
				}
			);

			// ---- Default cap + nonce: pass (individual tests override) ---------
			Functions\when( 'current_user_can' )->alias(
				static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
			);
			Functions\when( 'check_ajax_referer' )->justReturn( true );
		}

		protected function tearDown(): void
		{
			$_POST = [];
			$_GET  = [];
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------

		/**
		 * Build a `ProductActions` with a Mockery-mocked `SpreadconnectClient`.
		 *
		 * @return array{0:ProductActions, 1:SpreadconnectClient&\Mockery\MockInterface}
		 */
		private function buildHandler(): array
		{
			/** @var SpreadconnectClient&\Mockery\MockInterface $client */
			$client  = Mockery::mock( SpreadconnectClient::class );
			$handler = new ProductActions( $client );
			return [ $handler, $client ];
		}

		/**
		 * Configure a variable WC product with N variation children.
		 *
		 * @param array<int, array{sku:string, price?:string}> $variations
		 *        Map of variation-id -> SKU/price.
		 */
		private function fakeVariableProduct( int $productId, array $variations, string $parentPrice = '' ): void
		{
			$parent           = new WC_Product_Variable( $productId );
			$parent->price    = $parentPrice;
			$parent->children = array_keys( $variations );

			$this->wcProductsById[ $productId ] = $parent;

			foreach ( $variations as $varId => $row ) {
				$variation = new WC_Product_Variation(
					$varId,
					(string) ( $row['sku'] ?? '' ),
					(string) ( $row['price'] ?? '' )
				);
				$this->wcProductsById[ $varId ] = $variation;
			}
		}

		/**
		 * Build a minimal `ArticleSummary` DTO.
		 *
		 * @param Variant[] $variants
		 */
		private function makeArticleSummary( string $id = 'A-1', string $title = 'Demo Tee', array $variants = [] ): ArticleSummary
		{
			if ( empty( $variants ) ) {
				$variants = [ new Variant( sku: 'SKU-1', sizeId: 'sz-S', colorId: 'co-RED' ) ];
			}
			return new ArticleSummary(
				id: $id,
				title: $title,
				productTypeId: 'PT-1',
				variants: $variants,
			);
		}

		/**
		 * Render the meta-box for `$post` and return the captured output.
		 */
		private function renderMetaBox( WP_Post $post ): string
		{
			ob_start();
			try {
				ProductMetaBox::render( $post );
			} catch ( \Throwable $e ) {
				ob_end_clean();
				throw $e;
			}
			return (string) ob_get_clean();
		}

		// ===================================================================
		// AC-1: Meta-Box wird auf product-Screen registriert
		// ===================================================================

		public function test_register_adds_meta_box_to_product_screen(): void
		{
			$captured = [];
			Functions\when( 'add_meta_box' )->alias(
				static function ( $id, $title, $cb, $screen, $context = 'advanced', $priority = 'default' ) use ( &$captured ) {
					$captured[] = [
						'id'       => $id,
						'title'    => $title,
						'callback' => $cb,
						'screen'   => $screen,
						'context'  => $context,
						'priority' => $priority,
					];
				}
			);

			ProductMetaBox::register();

			$this->assertCount( 1, $captured, 'AC-1: register() MUSS GENAU EINEN add_meta_box-Call ausloesen.' );
			$call = $captured[0];

			$this->assertSame(
				'spreadconnect_product_meta_box',
				$call['id'],
				'AC-1: Meta-Box-ID MUSS exakt "spreadconnect_product_meta_box" sein.'
			);
			$this->assertSame( 'Spreadconnect', $call['title'], 'AC-1: Titel MUSS "Spreadconnect" sein.' );
			$this->assertSame( 'product', $call['screen'], 'AC-1: Screen MUSS "product" sein.' );
			$this->assertSame( 'side', $call['context'], 'AC-1: Position/Context MUSS "side" sein.' );
			$this->assertSame( 'default', $call['priority'], 'AC-1: Priority MUSS "default" sein.' );
			$this->assertIsArray( $call['callback'], 'AC-1: callback MUSS ein [class, method]-Array sein.' );
			$this->assertSame(
				'render',
				$call['callback'][1],
				'AC-1: callback MUSS auf ProductMetaBox::render zeigen.'
			);
		}

		// AC-1: Unlinked-State rendert KEINE Cost/Margin/Stock-Blocks
		public function test_render_unlinked_omits_cost_margin_stock_blocks(): void
		{
			// product 42, no `_spreadconnect_article_id` set => unlinked
			$post = new WP_Post( 42 );

			$html = $this->renderMetaBox( $post );

			$this->assertStringContainsString(
				'sc-state-unlinked',
				$html,
				'AC-1: Markup MUSS data-state="unlinked" Marker enthalten.'
			);
			$this->assertMatchesRegularExpression(
				'/<input[^>]*type=["\']search["\'][^>]*sc-picker-search/i',
				$html,
				'AC-1: Markup MUSS einen Search-Input fuer den Picker enthalten.'
			);
			$this->assertStringContainsString(
				'sc-picker-results',
				$html,
				'AC-1: Markup MUSS einen Result-Container fuer die Treffer enthalten.'
			);
			$this->assertStringNotContainsString(
				'data-sc-cost',
				$html,
				'AC-1: Im unlinked-State darf KEIN data-sc-cost-Attribut gerendert werden.'
			);
			$this->assertStringNotContainsString(
				'sc-block-cost-margin',
				$html,
				'AC-1: Im unlinked-State darf KEIN Cost/Margin-Block gerendert werden.'
			);
			$this->assertStringNotContainsString(
				'sc-block-stock',
				$html,
				'AC-1: Im unlinked-State darf KEIN Stock-Block gerendert werden.'
			);
		}

		// ===================================================================
		// AC-2: searchArticles ruft getArticles(0,20,$search) und liefert items+total
		// ===================================================================

		public function test_search_articles_calls_client_and_returns_items(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$summary = $this->makeArticleSummary(
				id: '88421',
				title: 'Some Tee',
				variants: [
					new Variant( sku: 'TS-S-RED', sizeId: 'sz-S', colorId: 'co-RED' ),
					new Variant( sku: 'TS-M-RED', sizeId: 'sz-M', colorId: 'co-RED' ),
					new Variant( sku: 'TS-S-BLUE', sizeId: 'sz-S', colorId: 'co-BLUE' ),
				]
			);

			$client->shouldReceive( 'getArticles' )
				->once()
				->with( 0, 20, 'shirt' )
				->andReturn( [
					'items' => [ $summary ],
					'page'  => 0,
					'size'  => 20,
					'total' => 1,
				] );

			$_POST = [
				'nonce'  => 'NONCE_spreadconnect_product_actions',
				'search' => 'shirt',
			];

			$thrown = null;
			try {
				$handler->searchArticles();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown, 'AC-2: Handler MUSS via wp_send_json_* terminieren.' );
			$this->assertTrue( $thrown->success, 'AC-2: 200-Pfad MUSS wp_send_json_success sein.' );
			$this->assertArrayHasKey( 'items', $thrown->payload, 'AC-2: Response MUSS items-Key tragen.' );
			$this->assertArrayHasKey( 'total', $thrown->payload, 'AC-2: Response MUSS total-Key tragen.' );
			$this->assertCount( 1, $thrown->payload['items'] );

			$item = $thrown->payload['items'][0];
			$this->assertSame( '88421', $item['id'] ?? null, 'AC-2: items[0].id MUSS = "88421" sein.' );
			$this->assertSame( 'Some Tee', $item['title'] ?? null );
		}

		// AC-2: Empty Search -> null an Client durchreichen (Top-20 most-recent)
		public function test_search_articles_passes_null_for_empty_search(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$client->shouldReceive( 'getArticles' )
				->once()
				->with( 0, 20, null )
				->andReturn( [
					'items' => [],
					'total' => 0,
				] );

			$_POST = [ 'nonce' => 'NONCE_x', 'search' => '' ];

			try {
				$handler->searchArticles();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			// Mockery `with(null)`-Assertion deckt "leerer search → null" ab.
			$this->addToAssertionCount( 1 );
		}

		// ===================================================================
		// AC-3: linkArticle schreibt _spreadconnect_article_id und enqueued sync_article
		// ===================================================================

		public function test_link_article_writes_meta_and_enqueues_sync_job(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$_POST = [
				'nonce'      => 'NONCE_x',
				'product_id' => '42',
				'article_id' => '88421',
			];

			$thrown = null;
			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown, 'AC-3: Handler MUSS via wp_send_json_success terminieren.' );
			$this->assertTrue( $thrown->success, 'AC-3: 200-Pfad MUSS success=true sein.' );

			// (c) update_post_meta(42, '_spreadconnect_article_id', '88421')
			$articleIdWrites = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[0] === 42 && $row[1] === '_spreadconnect_article_id'
			);
			$this->assertNotEmpty(
				$articleIdWrites,
				'AC-3 (c): update_post_meta(42, "_spreadconnect_article_id", ...) MUSS aufgerufen werden.'
			);
			$lastWrite = end( $articleIdWrites );
			$this->assertSame(
				'88421',
				$lastWrite[2],
				'AC-3 (c): _spreadconnect_article_id MUSS auf "88421" gesetzt werden.'
			);

			// (d) as_enqueue_async_action('spreadconnect/sync_article', [['article_id'=>'88421']], 'spreadconnect')
			$this->assertCount(
				1,
				$this->asEnqueues,
				'AC-3 (d): as_enqueue_async_action MUSS GENAU EINMAL aufgerufen werden.'
			);
			[ $hook, $args, $group ] = $this->asEnqueues[0];
			$this->assertSame(
				'spreadconnect/sync_article',
				$hook,
				'AC-3 (d): Hook-Name MUSS "spreadconnect/sync_article" sein.'
			);
			$this->assertSame( 'spreadconnect', $group, 'AC-3 (d): AS-Group MUSS "spreadconnect" sein.' );
			// Outer-array wraps the assoc-array (slice-23 AC-10 webhook-Pfad).
			$this->assertIsArray( $args[0] ?? null, 'AC-3 (d): args MUSS [[assoc]]-Shape haben.' );
			$this->assertSame(
				'88421',
				$args[0]['article_id'] ?? null,
				'AC-3 (d): args MUSS ["article_id"=>"88421"] enthalten.'
			);
			$this->assertArrayNotHasKey(
				'run_id',
				$args[0],
				'AC-3 (d): KEIN run_id (webhook-getriggerter Pfad aus Slice 23 AC-10).'
			);

			// (e) Response = ['linked'=>true, 'article_id'=>'88421', ...]
			$this->assertTrue( $thrown->payload['linked'] ?? null );
			$this->assertSame( '88421', $thrown->payload['article_id'] ?? null );
		}

		// AC-3: linkArticle ohne manage_woocommerce -> 403
		public function test_link_article_rejects_user_without_capability(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			Functions\when( 'current_user_can' )->alias( static fn () => false );

			$_POST = [
				'nonce'      => 'NONCE_x',
				'product_id' => '42',
				'article_id' => '88421',
			];

			$thrown = null;
			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success, 'AC-3: Cap-Failure MUSS wp_send_json_error sein.' );
			$this->assertSame( 403, $thrown->status, 'AC-3: HTTP-Status MUSS 403 sein wenn die Cap fehlt.' );

			$this->assertEmpty(
				$this->metaUpdates,
				'AC-3: KEIN update_post_meta darf laufen wenn der Cap-Gate failt.'
			);
			$this->assertEmpty(
				$this->asEnqueues,
				'AC-3: KEIN as_enqueue_async_action darf laufen wenn der Cap-Gate failt.'
			);
		}

		// AC-3: linkArticle mit invalid Nonce -> 403
		public function test_link_article_rejects_invalid_nonce(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			Functions\when( 'check_ajax_referer' )->justReturn( false );

			$_POST = [
				'nonce'      => 'BAD',
				'product_id' => '42',
				'article_id' => '88421',
			];

			$thrown = null;
			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame(
				403,
				$thrown->status,
				'AC-3: HTTP-Status MUSS 403 sein wenn die Nonce invalid ist.'
			);
			$this->assertEmpty( $this->metaUpdates );
			$this->assertEmpty( $this->asEnqueues );
		}

		// AC-3: check_ajax_referer wird mit korrektem Action-String + die=false aufgerufen
		public function test_link_article_calls_check_ajax_referer_with_shared_nonce_action(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$captured = [];
			Functions\when( 'check_ajax_referer' )->alias(
				static function ( $action, $field = '_wpnonce', $die = true ) use ( &$captured ) {
					$captured[] = [ 'action' => $action, 'field' => $field, 'die' => $die ];
					return true;
				}
			);

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => '42', 'article_id' => '88421' ];

			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			$this->assertCount( 1, $captured );
			$this->assertSame(
				'spreadconnect_product_actions',
				$captured[0]['action'],
				'Constraint: Nonce-Action-String MUSS exakt "spreadconnect_product_actions" sein '
				. '(shared by all four AJAX handlers).'
			);
			$this->assertSame( 'nonce', $captured[0]['field'], 'Localize-Script schreibt den Token unter `nonce`.' );
			$this->assertFalse(
				$captured[0]['die'],
				'die-Flag MUSS false sein damit der Helper nicht selbst wp_die-t.'
			);
		}

		// ===================================================================
		// AC-4: Linked-State rendert Cost/Margin-Container mit data-sc-cost
		// ===================================================================

		public function test_render_linked_emits_cost_data_attribute(): void
		{
			$productId           = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id'    => '88421',
				'_spreadconnect_cost'          => '12.34',
				'_spreadconnect_cost_currency' => 'EUR',
				'_spreadconnect_last_sync'     => 1714723200,
				'_spreadconnect_sync_state'    => 'synced',
			];

			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED', 'price' => '29.90' ],
					102 => [ 'sku' => 'TS-M-RED', 'price' => '29.90' ],
					103 => [ 'sku' => 'TS-L-RED', 'price' => '29.90' ],
				],
				parentPrice: '29.90'
			);

			$html = $this->renderMetaBox( new WP_Post( $productId ) );

			// (a) Article-ID + Unlink-Button
			$this->assertStringContainsString(
				'88421',
				$html,
				'AC-4 (a): Markup MUSS Article-ID 88421 enthalten.'
			);
			$this->assertMatchesRegularExpression(
				'/sc-action-unlink/i',
				$html,
				'AC-4 (a): Unlink-Button MUSS gerendert werden.'
			);
			// (b) Cost-Display
			$this->assertStringContainsString( '12.34', $html, 'AC-4 (b): Markup MUSS Cost 12.34 enthalten.' );
			$this->assertStringContainsString( 'EUR', $html, 'AC-4 (b): Markup MUSS Cost-Currency enthalten.' );
			// (d) Margin als data-sc-cost Attribut
			$this->assertMatchesRegularExpression(
				'/data-sc-cost=["\']12\.34["\']/',
				$html,
				'AC-4 (d): Markup MUSS data-sc-cost="12.34" als JS-Hook enthalten.'
			);
			// (f) Re-Sync Button
			$this->assertMatchesRegularExpression(
				'/sc-action-resync/i',
				$html,
				'AC-4 (f): Re-Sync-Button MUSS gerendert werden.'
			);
		}

		// AC-4: Stock-Tabelle mit Variation-SKUs
		public function test_render_linked_renders_stock_table_per_variation_sku(): void
		{
			$productId                    = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];

			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED' ],
					102 => [ 'sku' => 'TS-M-RED' ],
					103 => [ 'sku' => 'TS-L-RED' ],
				]
			);

			$html = $this->renderMetaBox( new WP_Post( $productId ) );

			$this->assertStringContainsString( 'TS-S-RED', $html, 'AC-4 (e): SKU TS-S-RED MUSS in der Stock-Tabelle stehen.' );
			$this->assertStringContainsString( 'TS-M-RED', $html, 'AC-4 (e): SKU TS-M-RED MUSS in der Stock-Tabelle stehen.' );
			$this->assertStringContainsString( 'TS-L-RED', $html, 'AC-4 (e): SKU TS-L-RED MUSS in der Stock-Tabelle stehen.' );
		}

		// ===================================================================
		// AC-6: Stock-Render liest Transient sc_stock_{sku}; KEIN API-Call
		// ===================================================================

		public function test_render_reads_stock_from_transient_without_api_call(): void
		{
			$productId                    = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id' => '88421',
				'_spreadconnect_cost'       => '12.34',
			];

			// Cache-Hit fuer S, Cache-Miss fuer M.
			$this->transientStore['sc_stock_TS-S-RED'] = 42;
			// (TS-M-RED bewusst unsetzt -> get_transient liefert false)

			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED' ],
					102 => [ 'sku' => 'TS-M-RED' ],
				]
			);

			// Wenn der Render einen API-Call macht, MUSS er ueber den injected
			// Client laufen — den hat ProductMetaBox aber gar nicht (purer
			// Renderer). Wir verifizieren durch Abwesenheit eines neuen
			// transient-Writes UND durch Cache-Marker im Markup.
			$html = $this->renderMetaBox( new WP_Post( $productId ) );

			$this->assertStringContainsString(
				'TS-S-RED',
				$html,
				'AC-6: Cache-Hit-SKU MUSS in der Tabelle gerendert werden.'
			);
			$this->assertMatchesRegularExpression(
				'/TS-S-RED.*?(?:Available|42)/s',
				$html,
				'AC-6: Bei Cache-Hit (qty=42 -> >=10 -> Available-Badge) MUSS die Tabellenzeile den Wert/Badge tragen.'
			);
			$this->assertMatchesRegularExpression(
				'/TS-M-RED.*?(?:…|sc-stock-loading)/s',
				$html,
				'AC-6: Cache-Miss MUSS als Loading-Placeholder ("…" / sc-stock-loading) gerendert werden.'
			);

			$this->assertEmpty(
				$this->transientWrites,
				'AC-6: Beim Render darf KEIN set_transient laufen (kein synchroner API-Call beim Page-Render).'
			);
		}

		// ===================================================================
		// AC-7: refreshStock ruft getStock(null,$skus) bulk und schreibt Transients
		// ===================================================================

		public function test_refresh_stock_bulk_call_writes_transients(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$productId = 42;
			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED' ],
					102 => [ 'sku' => 'TS-M-RED' ],
					103 => [ 'sku' => 'TS-L-RED' ],
				]
			);

			$client->shouldReceive( 'getStock' )
				->once()
				->with( null, [ 'TS-S-RED', 'TS-M-RED', 'TS-L-RED' ] )
				->andReturn( [
					new StockEntry( sku: 'TS-S-RED', quantity: 42 ),
					new StockEntry( sku: 'TS-M-RED', quantity: 3 ),
					new StockEntry( sku: 'TS-L-RED', quantity: 0 ),
				] );

			$_POST = [
				'nonce'      => 'NONCE_x',
				'product_id' => (string) $productId,
			];

			try {
				$handler->refreshStock();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			// Transient-Schluessel pro SKU + 5min TTL.
			$writeKeys = array_column( $this->transientWrites, 0 );
			$this->assertContains( 'sc_stock_TS-S-RED', $writeKeys, 'AC-7: sc_stock_TS-S-RED MUSS geschrieben sein.' );
			$this->assertContains( 'sc_stock_TS-M-RED', $writeKeys, 'AC-7: sc_stock_TS-M-RED MUSS geschrieben sein.' );
			$this->assertContains( 'sc_stock_TS-L-RED', $writeKeys, 'AC-7: sc_stock_TS-L-RED MUSS geschrieben sein.' );
			$this->assertContains(
				'sc_stock_refresh_' . $productId,
				$writeKeys,
				'AC-7: sc_stock_refresh_{productId} Refresh-Timestamp MUSS geschrieben sein.'
			);

			foreach ( $this->transientWrites as [$key, $value, $ttl] ) {
				$this->assertSame(
					300,
					$ttl,
					sprintf( 'AC-7: Transient-TTL fuer "%s" MUSS 300s (5min) sein.', $key )
				);
			}

			// Werte pro SKU.
			$bySku = [];
			foreach ( $this->transientWrites as [$key, $value, $ttl] ) {
				$bySku[ $key ] = $value;
			}
			$this->assertSame( 42, $bySku['sc_stock_TS-S-RED'] ?? null );
			$this->assertSame( 3, $bySku['sc_stock_TS-M-RED'] ?? null );
			$this->assertSame( 0, $bySku['sc_stock_TS-L-RED'] ?? null );
		}

		// AC-7: refreshStock liefert {stock:{sku:qty}, refreshed_at} JSON
		public function test_refresh_stock_returns_stock_map_and_timestamp(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$productId = 42;
			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED' ],
					102 => [ 'sku' => 'TS-M-RED' ],
				]
			);

			$client->shouldReceive( 'getStock' )
				->once()
				->andReturn( [
					new StockEntry( sku: 'TS-S-RED', quantity: 42 ),
					new StockEntry( sku: 'TS-M-RED', quantity: 3 ),
				] );

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => (string) $productId ];

			$thrown = null;
			$before = time();
			try {
				$handler->refreshStock();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}
			$after = time();

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-7: Erfolgreicher Refresh MUSS wp_send_json_success sein.' );

			$this->assertArrayHasKey( 'stock', $thrown->payload, 'AC-7: Response MUSS stock-Key tragen.' );
			$stock = (array) $thrown->payload['stock'];
			$this->assertSame( 42, $stock['TS-S-RED'] ?? null, 'AC-7: stock["TS-S-RED"] MUSS = 42 sein.' );
			$this->assertSame( 3, $stock['TS-M-RED'] ?? null, 'AC-7: stock["TS-M-RED"] MUSS = 3 sein.' );

			$this->assertArrayHasKey(
				'refreshed_at',
				$thrown->payload,
				'AC-7: Response MUSS refreshed_at-Key tragen (Unix-Time).'
			);
			$ts = (int) $thrown->payload['refreshed_at'];
			$this->assertGreaterThanOrEqual( $before, $ts );
			$this->assertLessThanOrEqual( $after, $ts );
		}

		// AC-7: refreshStock Cap+Nonce-Hard-Gate
		public function test_refresh_stock_rejects_without_capability(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			Functions\when( 'current_user_can' )->alias( static fn () => false );
			$client->shouldNotReceive( 'getStock' );

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => '42' ];

			$thrown = null;
			try {
				$handler->refreshStock();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 403, $thrown->status, 'AC-7: Cap-Failure -> 403.' );
			$this->assertEmpty( $this->transientWrites, 'AC-7: Cap-Hard-Gate -> KEIN Transient-Write.' );
		}

		// ===================================================================
		// AC-8: unlinkArticle delete'd alle _spreadconnect_*-Meta-Keys
		// ===================================================================

		public function test_unlink_article_deletes_all_spreadconnect_meta_keys(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$productId = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id'      => '88421',
				'_spreadconnect_product_type_id' => 'PT-7',
				'_spreadconnect_cost'            => '12.34',
				'_spreadconnect_cost_currency'   => 'EUR',
				'_spreadconnect_last_sync'       => 1714723200,
				'_spreadconnect_sync_state'      => 'synced',
			];

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => (string) $productId ];

			$thrown = null;
			try {
				$handler->unlinkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-8: Unlink MUSS wp_send_json_success sein.' );
			$this->assertTrue( $thrown->payload['unlinked'] ?? null );

			$deletedKeys = array_column(
				array_filter( $this->metaDeletes, static fn( $row ) => $row[0] === $productId ),
				1
			);

			$mustDelete = [
				'_spreadconnect_article_id',
				'_spreadconnect_product_type_id',
				'_spreadconnect_cost',
				'_spreadconnect_cost_currency',
				'_spreadconnect_last_sync',
				'_spreadconnect_sync_state',
			];

			foreach ( $mustDelete as $key ) {
				$this->assertContains(
					$key,
					$deletedKeys,
					sprintf( 'AC-8: delete_post_meta MUSS fuer "%s" aufgerufen werden.', $key )
				);
			}
		}

		// AC-8: unlinkArticle macht KEINEN API-Call zu Spreadconnect
		public function test_unlink_article_does_not_call_spreadconnect_api(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			$productId                    = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id' => '88421',
			];

			// CRITICAL: keine Methode des Clients darf aufgerufen werden — der
			// Mockery-Mock wirft sonst NoMatchingExpectation. `shouldNotReceive`
			// auf den haeufigsten Surface-Methods.
			$client->shouldNotReceive( 'getArticle' );
			$client->shouldNotReceive( 'getArticles' );
			$client->shouldNotReceive( 'getStock' );
			$client->shouldNotReceive( 'createPreviews' );
			$client->shouldNotReceive( 'authenticate' );

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => (string) $productId ];

			try {
				$handler->unlinkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			// AS-Enqueue darf auch nicht laufen (Constraint "Kein
			// Subscription-Handling beim Unlink").
			$this->assertEmpty(
				$this->asEnqueues,
				'AC-8: Unlink darf KEIN as_enqueue_async_action triggern (rein WP-lokal).'
			);
		}

		// AC-8: Cap+Nonce-Hard-Gate fuer Unlink
		public function test_unlink_article_rejects_without_capability(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			Functions\when( 'current_user_can' )->alias( static fn () => false );

			$_POST = [ 'nonce' => 'NONCE_x', 'product_id' => '42' ];

			$thrown = null;
			try {
				$handler->unlinkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 403, $thrown->status );
			$this->assertEmpty(
				$this->metaDeletes,
				'AC-8: Cap-Hard-Gate -> KEIN delete_post_meta.'
			);
		}

		// ===================================================================
		// AC-9: Re-Sync triggert as_enqueue_async_action('spreadconnect/sync_article')
		// ===================================================================

		public function test_resync_enqueues_sync_article_action(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			// Re-Sync: same handler as Link with `force=true`. Spec sagt explizit
			// dass auch ein separater Handler erlaubt waere — Tests pruefen den
			// enqueue-Aufruf, NICHT den Handler-Namen.
			$_POST = [
				'nonce'      => 'NONCE_x',
				'product_id' => '42',
				'article_id' => '88421',
				'force'      => 'true',
			];

			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			$this->assertCount(
				1,
				$this->asEnqueues,
				'AC-9: Re-Sync MUSS as_enqueue_async_action genau einmal triggern.'
			);
			[ $hook, $args, $group ] = $this->asEnqueues[0];
			$this->assertSame( 'spreadconnect/sync_article', $hook );
			$this->assertSame( 'spreadconnect', $group );
			$this->assertSame( '88421', $args[0]['article_id'] ?? null );
		}

		// AC-9: Idempotenter meta-write — auch wenn die Meta bereits stimmt,
		//       darf kein Fehler auftreten und der Enqueue MUSS laufen.
		public function test_resync_is_idempotent_when_meta_already_set(): void
		{
			[ $handler, $client ] = $this->buildHandler();

			// Meta steht bereits — der Handler MUSS trotzdem die Sync-Action
			// erneut enqueuen (Re-Sync ist genau dieser Use-Case).
			$this->postMeta[ 42 ] = [
				'_spreadconnect_article_id' => '88421',
			];

			$_POST = [
				'nonce'      => 'NONCE_x',
				'product_id' => '42',
				'article_id' => '88421',
			];

			try {
				$handler->linkArticle();
			} catch ( Slice34JsonResponseSentinel $e ) {
				// expected
			}

			$this->assertCount(
				1,
				$this->asEnqueues,
				'AC-9: Re-Sync mit identischer Meta MUSS trotzdem die Sync-Action enqueuen (idempotent).'
			);
		}

		// ===================================================================
		// AC-10: removed_in_sc-State rendert Banner + Re-Create-Button
		// ===================================================================

		public function test_render_removed_in_sc_renders_banner_and_recreate_button(): void
		{
			$productId                    = 42;
			$this->postMeta[ $productId ] = [
				'_spreadconnect_article_id'    => '88421',
				'_spreadconnect_cost'          => '12.34',
				'_spreadconnect_cost_currency' => 'EUR',
				'_spreadconnect_sync_state'    => 'removed_in_sc',
			];

			$this->fakeVariableProduct(
				$productId,
				[
					101 => [ 'sku' => 'TS-S-RED' ],
				]
			);

			$html = $this->renderMetaBox( new WP_Post( $productId ) );

			// Banner-Marker (rot, top of box).
			$this->assertMatchesRegularExpression(
				'/sc-removed-banner|removed_in_sc/i',
				$html,
				'AC-10: Markup MUSS einen sc-removed-banner / data-state="removed_in_sc" Marker enthalten.'
			);
			$this->assertStringContainsString(
				'Article removed in Spreadconnect',
				$html,
				'AC-10: Banner-Text MUSS "Article removed in Spreadconnect" enthalten.'
			);

			// Re-Create / Re-Sync button.
			$this->assertMatchesRegularExpression(
				'/sc-action-resync/i',
				$html,
				'AC-10: Re-Sync (re-create) Button MUSS gerendert sein.'
			);

			// Greying CSS class on the wrapping info-blocks.
			$this->assertMatchesRegularExpression(
				'/sc-greyed/i',
				$html,
				'AC-10: Cost/Margin/Stock/Sync-Bloecke MUSSEN sc-greyed-Klasse tragen.'
			);

			// Unlink-Button bleibt verfuegbar (User kann manuell entkoppeln).
			$this->assertMatchesRegularExpression(
				'/sc-action-unlink/i',
				$html,
				'AC-10: Unlink-Button MUSS auch im removed_in_sc-State verfuegbar bleiben.'
			);
		}

		// ===================================================================
		// AC-11: Bootstrap registriert add_meta_boxes + admin_enqueue_scripts +
		//        4 wp_ajax_*-Hooks. Alle Hooks gehen ueber die *Static-Bridges.
		// ===================================================================

		public function test_bootstrap_registers_all_required_hooks(): void
		{
			// Reset Plugin static state so init() can run again.
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			// (a) add_meta_boxes -> ProductMetaBox::register
			$this->assertNotFalse(
				Actions\has( 'add_meta_boxes', [ ProductMetaBox::class, 'register' ] ),
				'AC-11 (a): add_action("add_meta_boxes", [ProductMetaBox::class, "register"]) MUSS registriert sein.'
			);

			// (b) admin_enqueue_scripts -> ProductMetaBox::enqueueAssets
			$this->assertNotFalse(
				Actions\has( 'admin_enqueue_scripts', [ ProductMetaBox::class, 'enqueueAssets' ] ),
				'AC-11 (b): add_action("admin_enqueue_scripts", [ProductMetaBox::class, "enqueueAssets"]) MUSS registriert sein.'
			);

			// (c)-(f) Vier wp_ajax_*-Hooks via *Static-Bridges.
			$this->assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_search_articles', [ ProductActions::class, 'searchArticlesStatic' ] ),
				'AC-11 (f): wp_ajax_spreadconnect_search_articles MUSS auf ProductActions::searchArticlesStatic gemappt sein.'
			);
			$this->assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_link_article', [ ProductActions::class, 'linkArticleStatic' ] ),
				'AC-11 (c): wp_ajax_spreadconnect_link_article MUSS auf ProductActions::linkArticleStatic gemappt sein.'
			);
			$this->assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_unlink_article', [ ProductActions::class, 'unlinkArticleStatic' ] ),
				'AC-11 (d): wp_ajax_spreadconnect_unlink_article MUSS auf ProductActions::unlinkArticleStatic gemappt sein.'
			);
			$this->assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_refresh_stock', [ ProductActions::class, 'refreshStockStatic' ] ),
				'AC-11 (e): wp_ajax_spreadconnect_refresh_stock MUSS auf ProductActions::refreshStockStatic gemappt sein.'
			);

			// Static bridges existieren + sind statisch + public.
			foreach ( [ 'searchArticlesStatic', 'linkArticleStatic', 'unlinkArticleStatic', 'refreshStockStatic' ] as $bridge ) {
				$this->assertTrue(
					method_exists( ProductActions::class, $bridge ),
					sprintf( 'AC-11: ProductActions::%s() MUSS existieren.', $bridge )
				);
				$method = ( new ReflectionClass( ProductActions::class ) )->getMethod( $bridge );
				$this->assertTrue( $method->isStatic(), sprintf( 'AC-11: %s MUSS statisch sein.', $bridge ) );
				$this->assertTrue( $method->isPublic(), sprintf( 'AC-11: %s MUSS public sein.', $bridge ) );
			}
		}

		// AC-11: KEINE wp_ajax_nopriv_*-Variante — Defense-in-Depth
		public function test_bootstrap_does_not_register_nopriv_actions(): void
		{
			// Reset.
			$reflection = new ReflectionClass( Plugin::class );
			$reflection->getProperty( 'initialized' )->setValue( null, false );
			$reflection->getProperty( 'pluginFile' )->setValue( null, '' );

			Plugin::init( '/tmp/fake.php' );

			$nopriv = [
				'wp_ajax_nopriv_spreadconnect_search_articles',
				'wp_ajax_nopriv_spreadconnect_link_article',
				'wp_ajax_nopriv_spreadconnect_unlink_article',
				'wp_ajax_nopriv_spreadconnect_refresh_stock',
			];
			foreach ( $nopriv as $hook ) {
				$this->assertFalse(
					Actions\has( $hook ),
					sprintf( 'AC-11: %s darf NIE registriert sein (admin-only).', $hook )
				);
			}
		}

		// AC-11: Source-Analyse — ProductActions.php enthaelt keinen
		//        wp_ajax_nopriv_*-String-Literal in Code.
		public function test_product_actions_source_does_not_reference_nopriv_action(): void
		{
			$source = (string) file_get_contents(
				self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/ProductActions.php'
			);
			$this->assertNotSame( '', $source );

			// Strip doc-blocks / line-comments.
			$codeOnly = preg_replace( '#/\*[\s\S]*?\*/#', '', $source ) ?? '';
			$codeOnly = preg_replace( '#//.*$#m', '', $codeOnly ) ?? '';

			$this->assertDoesNotMatchRegularExpression(
				"/['\"]wp_ajax_nopriv_/",
				$codeOnly,
				'AC-11: ProductActions.php darf "wp_ajax_nopriv_" NICHT als String-Literal in Code verwenden.'
			);
		}

		// ===================================================================
		// Zusatz: Source-Constraint-Checks (Slice-34 Constraints)
		// ===================================================================

		public function test_product_meta_box_class_is_final(): void
		{
			$rc = new ReflectionClass( ProductMetaBox::class );
			$this->assertTrue(
				$rc->isFinal(),
				'Constraint: ProductMetaBox MUSS `final class` sein.'
			);
		}

		public function test_product_actions_class_is_final(): void
		{
			$rc = new ReflectionClass( ProductActions::class );
			$this->assertTrue(
				$rc->isFinal(),
				'Constraint: ProductActions MUSS `final class` sein.'
			);
		}

		public function test_product_actions_constructor_accepts_spreadconnect_client(): void
		{
			$rc = new ReflectionClass( ProductActions::class );
			$ctor = $rc->getConstructor();
			$this->assertNotNull( $ctor, 'Constraint: ProductActions MUSS einen Konstruktor haben.' );

			$params = $ctor->getParameters();
			$this->assertGreaterThanOrEqual(
				1,
				count( $params ),
				'Constraint: ProductActions::__construct MUSS SpreadconnectClient als ersten Parameter erhalten.'
			);
			$type = $params[0]->getType();
			$this->assertNotNull( $type );
			$this->assertSame(
				SpreadconnectClient::class,
				method_exists( $type, 'getName' ) ? $type->getName() : '',
				'Constraint: Erster Konstruktor-Parameter MUSS SpreadconnectClient sein (DI fuer Tests).'
			);
		}
	}
}
