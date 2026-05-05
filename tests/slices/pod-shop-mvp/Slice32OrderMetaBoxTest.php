<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice-32 — Order-Edit Meta-Box (Inline\OrderMetaBox + Hub\Ajax\OrderActions)
//   + WebhookLogRepo (Slice-32 AC-5 findRecentForOrder
//                     + Slice-16 AC-5/AC-6 insertOrIgnore-Contract).
// ---------------------------------------------------------------------------
//
// Covers:
//   - Inline\OrderMetaBox::registerOnAddMetaBoxes (AC-1)
//   - Inline\OrderMetaBox::render                  (AC-2..AC-6)
//   - Inline\OrderMetaBox::enqueueAssets           (AC-13)
//   - Hub\Ajax\OrderActions::handleConfirm         (AC-7, AC-8)
//   - Hub\Ajax\OrderActions::handleCancel          (AC-9)
//   - Hub\Ajax\OrderActions::handleRefreshState    (AC-10)
//   - Hub\Ajax\OrderActions::handleSaveShippingType(AC-11)
//   - Hub\Ajax\OrderActions::handleCancelAutoConfirm(AC-12)
//   - Bootstrap\Plugin::init() hook-wiring         (AC-14)
//   - Webhook\WebhookLogRepo::findRecentForOrder   (AC-5 read-path)
//   - Webhook\WebhookLogRepo::insertOrIgnore       (Slice-16 AC-5/AC-6 binary contract)
//
// Mocking-Strategy `mock_external` (laut Slice-Spec Z. 28):
//
//   - Brain\Monkey fuer alle WP-Funktionen (`add_meta_box`, `add_action`,
//     `wp_register_script`, `wp_enqueue_script`, `wp_localize_script`,
//     `wp_create_nonce`, `wp_verify_nonce`, `current_user_can`,
//     `check_ajax_referer`, `wp_send_json_*`, `wc_get_order`,
//     `wc_get_page_screen_id`, `as_enqueue_async_action`,
//     `as_unschedule_action`, `get_current_screen`, `admin_url`,
//     `plugins_url`, `wc_get_logger`, `wp_unslash`, `sanitize_text_field`,
//     `wp_date`, `esc_*`).
//   - SpreadconnectClient ist NICHT final -> klassischer Mockery::mock(),
//     in OrderActions::__construct injiziert.
//   - WC_Order ist NICHT final -> Mockery doubles.
//   - WebhookLogRepo: production-class (static) — Tests setzen `$wpdb`
//     global zu einem WebhookLogRepoFakeWpdb-Mock und steuern dessen
//     INSERT/SELECT-Resultate.
//   - wp_send_json_* werfen Slice32JsonResponseSentinel damit Tests die
//     Response inspizieren koennen ohne dass der PHPUnit-Prozess via
//     wp_die() / die() terminiert.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Post', false ) ) {
		/**
		 * Minimal `WP_Post` stub — only the `ID` field is read by the
		 * legacy `OrderMetaBox::render()` path.
		 */
		class WP_Post
		{
			public int $ID = 0;

			public function __construct( int $id = 0 ) {
				$this->ID = $id;
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
	use RuntimeException;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Hub\Ajax\OrderActions;
	use SpreadconnectPod\Inline\OrderMetaBox;
	use SpreadconnectPod\Webhook\WebhookLogRepo;
	use WC_Order;
	use WP_Post;

	/**
	 * Sentinel exception thrown by the `wp_send_json_*` stubs so tests can
	 * inspect the captured payload + status without the PHPUnit process
	 * dying.
	 */
	final class Slice32JsonResponseSentinel extends RuntimeException
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
	 * Programmable `wpdb`-fake for `WebhookLogRepo` tests. Captures every
	 * `prepare()` / `insert()` / `update()` / `get_var()` / `get_results()`
	 * call so tests can assert on them.
	 *
	 * Slice-32 AC-5 + Slice-16 AC-5/AC-6 contract — only the surface needed
	 * for those Acceptance Tests is implemented (no full $wpdb emulation).
	 */
	final class WebhookLogRepoFakeWpdb
	{
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $insert_id = 0;

		/** @var list<array{0:string,1:array<int|string,mixed>}> */
		public array $prepareCalls = [];

		/** @var list<array{table:string, data:array<string,mixed>}> */
		public array $insertCalls = [];

		/** @var list<array{table:string, data:array<string,mixed>, where:array<string,mixed>}> */
		public array $updateCalls = [];

		/** @var list<string> */
		public array $getVarCalls = [];

		/** @var list<string> */
		public array $getResultsCalls = [];

		/** @var false|int Next return value for `insert()` (false = error). */
		public bool|int $insertResult = 1;

		/** @var mixed Next return value for `get_var()`. */
		public $getVarResult = null;

		/** @var array<int, array<string, mixed>>|null Next return value for `get_results()`. */
		public ?array $getResultsResult = null;

		public function prepare( string $query, ...$args ): string
		{
			// Brain\Monkey-style: serialize args into the query for assertion.
			$flat = [];
			foreach ( $args as $a ) {
				$flat[] = is_array( $a ) ? implode( ',', $a ) : (string) $a;
			}
			$this->prepareCalls[] = [ $query, $args ];
			return $query . '|' . implode( '|', $flat );
		}

		/**
		 * @param array<string, mixed> $data
		 */
		public function insert( string $table, array $data, $format = null ): false|int
		{
			$this->insertCalls[] = [ 'table' => $table, 'data' => $data ];
			return $this->insertResult;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 */
		public function update( string $table, array $data, array $where, $dataFormat = null, $whereFormat = null ): false|int
		{
			$this->updateCalls[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
			return 1;
		}

		public function get_var( string $query ): mixed
		{
			$this->getVarCalls[] = $query;
			return $this->getVarResult;
		}

		/**
		 * @return array<int, array<string,mixed>>|null
		 */
		public function get_results( string $query, $output = ARRAY_A ): ?array
		{
			$this->getResultsCalls[] = $query;
			return $this->getResultsResult;
		}
	}

	/**
	 * Slice-32 — Order-Edit Meta-Box + AJAX + WebhookLogRepo (Slice-16 contract).
	 *
	 * Acceptance Tests gegen `slice-32-order-meta-box.md` (AC-1..AC-14) sowie
	 * den Slice-16 `WebhookLogRepo::insertOrIgnore`-Contract (AC-5/AC-6).
	 */
	final class Slice32OrderMetaBoxTest extends TestCase
	{
		/**
		 * Captured `as_enqueue_async_action` calls — list of [hook, args, group].
		 *
		 * @var list<array{0:string,1:array,2:string}>
		 */
		private array $asEnqueues = [];

		/**
		 * Captured `as_unschedule_action` calls — list of [hook, args, group].
		 *
		 * @var list<array{0:string,1:array,2:string}>
		 */
		private array $asUnschedules = [];

		/**
		 * Captured `add_meta_box` calls.
		 *
		 * @var list<array<string,mixed>>
		 */
		private array $addMetaBoxCalls = [];

		/**
		 * Captured `wp_register_script` calls.
		 *
		 * @var list<array<string,mixed>>
		 */
		private array $registeredScripts = [];

		/**
		 * Captured `wp_enqueue_script` calls.
		 *
		 * @var list<string>
		 */
		private array $enqueuedScripts = [];

		/**
		 * Captured `wp_localize_script` calls.
		 *
		 * @var list<array<string,mixed>>
		 */
		private array $localizedScripts = [];

		/**
		 * Backing for `wc_get_order($id)` lookups.
		 *
		 * @var array<int, mixed>
		 */
		private array $wcOrders = [];

		/**
		 * Backing for `WebhookLogRepo::findRecentForOrder()` lookups.
		 *
		 * Used by tests that bypass the real $wpdb global and inject rows
		 * directly through a Brain\Monkey alias on the repo class.
		 *
		 * @var array<string, array<int, array<string,mixed>>>
		 */
		private array $webhookRowsByScOrderId = [];

		/**
		 * Active `get_current_screen()` return value (per test).
		 */
		private ?object $currentScreen = null;

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

			$this->asEnqueues             = [];
			$this->asUnschedules          = [];
			$this->addMetaBoxCalls        = [];
			$this->registeredScripts      = [];
			$this->enqueuedScripts        = [];
			$this->localizedScripts       = [];
			$this->wcOrders               = [];
			$this->webhookRowsByScOrderId = [];
			$this->currentScreen          = null;

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
			Functions\when( 'esc_url' )->returnArg( 1 );
			Functions\when( 'wp_date' )->alias(
				static fn( $fmt, $ts ) => date( (string) $fmt, (int) $ts )
			);

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

			// ---- WC helpers ---------------------------------------------------
			$wcOrders = & $this->wcOrders;
			Functions\when( 'wc_get_order' )->alias(
				static function ( $id ) use ( &$wcOrders ) {
					if ( $id instanceof WC_Order ) {
						return $id;
					}
					$pid = (int) $id;
					return $wcOrders[ $pid ] ?? null;
				}
			);
			Functions\when( 'wc_get_page_screen_id' )->alias(
				static function ( string $page ) {
					if ( 'shop-order' === $page ) {
						return 'woocommerce_page_wc-orders';
					}
					return '';
				}
			);
			Functions\when( 'wc_get_logger' )->alias( static function () {
				return new \WC_Logger();
			} );

			// ---- get_current_screen ------------------------------------------
			$currentScreen = & $this->currentScreen;
			Functions\when( 'get_current_screen' )->alias(
				static function () use ( &$currentScreen ) {
					return $currentScreen;
				}
			);

			// ---- add_meta_box spy --------------------------------------------
			$addMetaBoxCalls = & $this->addMetaBoxCalls;
			Functions\when( 'add_meta_box' )->alias(
				static function (
					$id, $title, $cb,
					$screen = null, $context = 'advanced', $priority = 'default'
				) use ( &$addMetaBoxCalls ) {
					$addMetaBoxCalls[] = [
						'id'       => $id,
						'title'    => $title,
						'callback' => $cb,
						'screen'   => $screen,
						'context'  => $context,
						'priority' => $priority,
					];
				}
			);

			// ---- Asset enqueue spies -----------------------------------------
			$registered = & $this->registeredScripts;
			Functions\when( 'wp_register_script' )->alias(
				static function (
					$handle, $src = '', $deps = [], $ver = false, $in_footer = false
				) use ( &$registered ) {
					$registered[] = [
						'handle'    => $handle,
						'src'       => $src,
						'deps'      => $deps,
						'ver'       => $ver,
						'in_footer' => $in_footer,
					];
					return true;
				}
			);
			$enqueued = & $this->enqueuedScripts;
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( $handle ) use ( &$enqueued ) {
					$enqueued[] = (string) $handle;
				}
			);
			$localized = & $this->localizedScripts;
			Functions\when( 'wp_localize_script' )->alias(
				static function ( $handle, $object, $data ) use ( &$localized ) {
					$localized[] = [
						'handle' => $handle,
						'object' => $object,
						'data'   => $data,
					];
					return true;
				}
			);

			Functions\when( 'admin_url' )->alias( static function ( $path = '' ) {
				return 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' );
			} );
			Functions\when( 'plugins_url' )->alias( static function ( $path = '', $plugin = '' ) {
				return 'http://example.test/wp-content/plugins/spreadconnect-pod/' . ltrim( (string) $path, '/' );
			} );
			Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'NONCE_' . (string) $action );

			// ---- Action-Scheduler spies --------------------------------------
			$asEnqueues = & $this->asEnqueues;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( $hook, $args = [], $group = '' ) use ( &$asEnqueues ) {
					$asEnqueues[] = [ (string) $hook, (array) $args, (string) $group ];
					return 1;
				}
			);
			$asUnschedules = & $this->asUnschedules;
			Functions\when( 'as_unschedule_action' )->alias(
				static function ( $hook, $args = [], $group = '' ) use ( &$asUnschedules ) {
					$asUnschedules[] = [ (string) $hook, (array) $args, (string) $group ];
					return 1;
				}
			);

			// ---- wp_send_json_* — sentinel-throwing variants -----------------
			Functions\when( 'wp_send_json_success' )->alias(
				static function ( $data = null, $status = null ) {
					throw new Slice32JsonResponseSentinel( true, (array) $data, $status );
				}
			);
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data = null, $status = null ) {
					throw new Slice32JsonResponseSentinel( false, (array) $data, $status );
				}
			);

			// ---- Default cap + nonce: pass (individual tests override) -------
			Functions\when( 'current_user_can' )->alias(
				static fn ( string $cap ): bool => 'manage_woocommerce' === $cap
			);
			Functions\when( 'check_ajax_referer' )->justReturn( 1 );

			// Stub for other init-time WP calls that may be invoked by Plugin::init.
			Functions\when( 'register_rest_route' )->justReturn( true );
			Functions\when( 'as_next_scheduled_action' )->justReturn( false );
			Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );
		}

		protected function tearDown(): void
		{
			$_POST = [];
			$_GET  = [];
			global $wpdb;
			$wpdb = null;

			// Reset Plugin static state so AC-14 idempotency tests stay clean
			// across the suite.
			$pluginFqcn = Plugin::class;
			if ( class_exists( $pluginFqcn ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$prop = $ref->getProperty( 'initialized' );
					$prop->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$prop = $ref->getProperty( 'pluginFile' );
					$prop->setValue( null, '' );
				}
			}

			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------

		/**
		 * Build an OrderActions instance with a Mockery-mocked SpreadconnectClient.
		 *
		 * @return array{0:OrderActions, 1:SpreadconnectClient&\Mockery\MockInterface}
		 */
		private function buildHandler(): array
		{
			/** @var SpreadconnectClient&\Mockery\MockInterface $client */
			$client  = Mockery::mock( SpreadconnectClient::class );
			$handler = new OrderActions( $client );
			return [ $handler, $client ];
		}

		/**
		 * Build a Mockery WC_Order double whose `get_meta()` returns per-key
		 * values from `$metaValues`.
		 *
		 * @param array<string,mixed> $metaValues
		 *
		 * @return WC_Order&\Mockery\MockInterface
		 */
		private function mockOrder( int $id = 7, array $metaValues = [] ): WC_Order
		{
			/** @var WC_Order&\Mockery\MockInterface $order */
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( $id )->byDefault();
			$order->shouldReceive( 'get_meta' )
				->andReturnUsing( static function ( string $key ) use ( $metaValues ) {
					return $metaValues[ $key ] ?? '';
				} )
				->byDefault();
			$order->shouldReceive( 'update_meta_data' )->andReturnNull()->byDefault();
			$order->shouldReceive( 'save' )->andReturn( $id )->byDefault();
			$order->shouldReceive( 'add_order_note' )->andReturn( 1 )->byDefault();
			return $order;
		}

		/**
		 * Render the meta-box with the given context and return captured output.
		 *
		 * @param mixed $context
		 */
		private function renderMetaBox( $context ): string
		{
			ob_start();
			try {
				OrderMetaBox::render( $context );
			} catch ( \Throwable $e ) {
				ob_end_clean();
				throw $e;
			}
			return (string) ob_get_clean();
		}

		// ===================================================================
		// AC-1: Dual-Screen-Registration (HPOS + Legacy)
		// ===================================================================

		/**
		 * AC-1 (a) — GIVEN HPOS-Screen-Argument WHEN add_meta_boxes feuert
		 * THEN add_meta_box wird mit korrekter ID + Title + side + default
		 * Priority aufgerufen.
		 */
		public function test_register_adds_meta_box_on_hpos_screen(): void
		{
			OrderMetaBox::registerOnAddMetaBoxes( 'woocommerce_page_wc-orders' );

			$this->assertCount(
				1,
				$this->addMetaBoxCalls,
				'AC-1 (a): registerOnAddMetaBoxes MUSS auf HPOS-Screen GENAU EINEN add_meta_box-Call ausloesen.'
			);
			$call = $this->addMetaBoxCalls[0];

			$this->assertSame(
				'spreadconnect_order_meta_box',
				$call['id'],
				'AC-1: Meta-Box-ID MUSS exakt "spreadconnect_order_meta_box" sein.'
			);
			$this->assertSame(
				'Spreadconnect',
				$call['title'],
				'AC-1: Title MUSS via __() und String "Spreadconnect" sein.'
			);
			$this->assertSame(
				'woocommerce_page_wc-orders',
				$call['screen'],
				'AC-1: Screen MUSS HPOS-screen-id sein.'
			);
			$this->assertSame( 'side', $call['context'], 'AC-1: Context MUSS "side" sein.' );
			$this->assertSame( 'default', $call['priority'], 'AC-1: Priority MUSS "default" sein.' );
			$this->assertSame(
				[ OrderMetaBox::class, 'render' ],
				$call['callback'],
				'AC-1: Callback MUSS [OrderMetaBox::class, "render"] sein.'
			);
		}

		/**
		 * AC-1 (b) — GIVEN Legacy `shop_order` Post-Type-Argument WHEN
		 * add_meta_boxes feuert THEN add_meta_box wird auf legacy-Screen
		 * registriert.
		 */
		public function test_register_adds_meta_box_on_legacy_shop_order_screen(): void
		{
			OrderMetaBox::registerOnAddMetaBoxes( 'shop_order' );

			$this->assertCount(
				1,
				$this->addMetaBoxCalls,
				'AC-1 (b): registerOnAddMetaBoxes MUSS auf legacy-Screen ebenfalls add_meta_box ausloesen.'
			);
			$call = $this->addMetaBoxCalls[0];
			$this->assertSame( 'shop_order', $call['screen'] );
			$this->assertSame( 'spreadconnect_order_meta_box', $call['id'] );
		}

		/**
		 * AC-1 (c) — GIVEN unrelated screen-id (z.B. `product`) WHEN
		 * add_meta_boxes feuert THEN add_meta_box wird NICHT aufgerufen.
		 */
		public function test_register_skips_unrelated_screens(): void
		{
			OrderMetaBox::registerOnAddMetaBoxes( 'product' );
			OrderMetaBox::registerOnAddMetaBoxes( 'page' );
			OrderMetaBox::registerOnAddMetaBoxes( 'post' );
			OrderMetaBox::registerOnAddMetaBoxes( '' );

			$this->assertEmpty(
				$this->addMetaBoxCalls,
				'AC-1: Auf nicht-Order-Screens DARF KEIN add_meta_box ausgeloest werden.'
			);
		}

		// ===================================================================
		// AC-2: State-Block render
		// ===================================================================

		/**
		 * AC-2 — GIVEN Order mit sc-id, state, last_event WHEN render() laeuft
		 * THEN data-block="state" enthaelt SC-OrderID-Link, State-Badge mit
		 * data-state, last-action-Timestamp und Refresh-Button mit
		 * data-action="refresh_order_state".
		 */
		public function test_render_emits_state_block_with_sc_order_id_badge_and_refresh_button(): void
		{
			global $wpdb;
			$wpdb = new WebhookLogRepoFakeWpdb();
			$wpdb->getResultsResult = [];

			$orderId   = 7;
			$lastEvent = sprintf( '%d:Order.created', strtotime( '2026-05-03T14:14:00Z' ) ?: 1714657560 );

			$order = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_order_id'   => 'sc_77194',
					'_spreadconnect_state'      => 'NEW',
					'_spreadconnect_last_event' => $lastEvent,
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			// (a) data-block="state" container
			$this->assertMatchesRegularExpression(
				'/data-block=["\']state["\']/',
				$html,
				'AC-2: Markup MUSS data-block="state" container enthalten.'
			);
			// (a) SC-OrderID with prefix "SC-"
			$this->assertStringContainsString(
				'SC-sc_77194',
				$html,
				'AC-2 (a): Markup MUSS escaped "SC-sc_77194" enthalten.'
			);
			// (a) External link target="_blank"
			$this->assertMatchesRegularExpression(
				'/target=["\']_blank["\']/',
				$html,
				'AC-2 (a): SC-Order-Link MUSS target="_blank" tragen.'
			);
			// (b) State-Badge data-state="NEW" + spreadconnect-state-NEW class
			$this->assertMatchesRegularExpression(
				'/data-state=["\']NEW["\']/',
				$html,
				'AC-2 (b): State-Badge MUSS data-state="NEW" tragen.'
			);
			$this->assertMatchesRegularExpression(
				'/spreadconnect-state-NEW/',
				$html,
				'AC-2 (b): State-Badge MUSS CSS-Klasse spreadconnect-state-NEW tragen.'
			);
			// (d) Refresh-Button data-action="refresh_order_state"
			$this->assertMatchesRegularExpression(
				'/data-action=["\']refresh_order_state["\']/',
				$html,
				'AC-2 (d): Refresh-Button MUSS data-action="refresh_order_state" tragen.'
			);
		}

		/**
		 * AC-2 — escape contract: dynamic strings must pass through esc_*().
		 */
		public function test_render_escapes_dynamic_strings(): void
		{
			$called = [
				'esc_html' => 0,
				'esc_attr' => 0,
				'esc_url'  => 0,
			];
			Functions\when( 'esc_html' )->alias( static function ( $v ) use ( &$called ) {
				$called['esc_html']++;
				return $v;
			} );
			Functions\when( 'esc_attr' )->alias( static function ( $v ) use ( &$called ) {
				$called['esc_attr']++;
				return $v;
			} );
			Functions\when( 'esc_url' )->alias( static function ( $v ) use ( &$called ) {
				$called['esc_url']++;
				return $v;
			} );

			global $wpdb;
			$wpdb = new WebhookLogRepoFakeWpdb();
			$wpdb->getResultsResult = [];

			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_order_id'   => 'sc_77194',
					'_spreadconnect_state'      => 'NEW',
					'_spreadconnect_last_event' => '1714657560:Order.created',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$this->renderMetaBox( $orderId );

			$this->assertGreaterThan( 0, $called['esc_html'], 'AC-2: esc_html MUSS fuer dynamische Texte verwendet werden.' );
			$this->assertGreaterThan( 0, $called['esc_attr'], 'AC-2: esc_attr MUSS fuer Attribute verwendet werden.' );
			$this->assertGreaterThan( 0, $called['esc_url'], 'AC-2: esc_url MUSS fuer den SC-Order-Link verwendet werden.' );
		}

		// ===================================================================
		// AC-3: Shipping-Block lazy
		// ===================================================================

		/**
		 * AC-3 — Shipping-Type-Dropdown rendert mit data-loaded="false" und
		 * Save-Button mit data-action="save_shipping_type".
		 */
		public function test_render_emits_shipping_dropdown_with_data_loaded_false(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_state'         => 'NEW',
					'_spreadconnect_shipping_type' => 'STANDARD',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertMatchesRegularExpression(
				'/<select[^>]*data-block=["\']shipping-type["\'][^>]*data-loaded=["\']false["\']/',
				$html,
				'AC-3: Shipping-Dropdown MUSS data-block="shipping-type" + data-loaded="false" tragen.'
			);
			$this->assertMatchesRegularExpression(
				'/data-action=["\']save_shipping_type["\']/',
				$html,
				'AC-3: Save-Shipping-Type-Button MUSS data-action="save_shipping_type" tragen.'
			);
			// Current value rendered as default option placeholder.
			$this->assertStringContainsString(
				'STANDARD',
				$html,
				'AC-3: Default-Option MUSS aktueller Wert "STANDARD" sein.'
			);
		}

		/**
		 * AC-3 — Render-Pfad MUSS leistungsfrei laufen: KEIN
		 * SpreadconnectClient::getShippingTypes()-Aufruf im PHP-Render.
		 *
		 * Wir verifizieren das indirekt: OrderMetaBox::render() greift NICHT
		 * auf einen SpreadconnectClient zu (kein Konstruktor-DI). Wenn der
		 * Render-Pfad einen Client benoetigen wuerde, muesste der Bootstrap
		 * ihn passen — er tut es nicht. Source-Analyse stellt sicher, dass
		 * `getShippingTypes` nirgendwo in OrderMetaBox.php auftaucht.
		 */
		public function test_render_does_not_call_get_shipping_types_synchronously(): void
		{
			$source = (string) file_get_contents(
				self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/includes/Inline/OrderMetaBox.php'
			);
			$this->assertNotSame( '', $source, 'OrderMetaBox source MUSS lesbar sein.' );

			$codeOnly = preg_replace( '#/\*[\s\S]*?\*/#', '', $source ) ?? '';
			$codeOnly = preg_replace( '#//.*$#m', '', $codeOnly ) ?? '';

			$this->assertDoesNotMatchRegularExpression(
				'/->getShippingTypes\(/',
				$codeOnly,
				'AC-3: OrderMetaBox.php darf KEINEN ->getShippingTypes()-Call im Render-Pfad enthalten (Lazy via JS).'
			);
		}

		/**
		 * AC-3 — Bei State CONFIRMED/PROCESSED/CANCELLED MUSS der
		 * Shipping-Block-Wrapper data-disabled="true" tragen.
		 */
		public function test_render_marks_shipping_block_disabled_for_confirmed_state(): void
		{
			foreach ( [ 'CONFIRMED', 'PROCESSED', 'CANCELLED' ] as $state ) {
				$this->addMetaBoxCalls = [];

				$orderId = 7;
				$order   = $this->mockOrder(
					$orderId,
					[
						'_spreadconnect_state'         => $state,
						'_spreadconnect_shipping_type' => 'STANDARD',
					]
				);
				$this->wcOrders[ $orderId ] = $order;

				$html = $this->renderMetaBox( $orderId );

				$this->assertMatchesRegularExpression(
					'/data-disabled=["\']true["\']/',
					$html,
					sprintf( 'AC-3: Bei State %s MUSS data-disabled="true" gesetzt sein.', $state )
				);
			}
		}

		// ===================================================================
		// AC-4: Actions-Block (state-aware buttons)
		// ===================================================================

		/**
		 * AC-4 (a) — State NEW + shipping-type set: Confirm enabled, Cancel
		 * enabled, Refresh enabled, Resend hidden.
		 */
		public function test_render_actions_block_state_new_with_shipping(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_state'         => 'NEW',
					'_spreadconnect_shipping_type' => 'STANDARD',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertMatchesRegularExpression(
				'/data-action=["\']confirm_order["\']/',
				$html,
				'AC-4 (a): NEW+shipping MUSS Confirm-Button rendern.'
			);
			$this->assertMatchesRegularExpression(
				'/data-action=["\']cancel_order["\']/',
				$html,
				'AC-4 (a): NEW MUSS Cancel-Button rendern.'
			);
			$this->assertMatchesRegularExpression(
				'/data-action=["\']refresh_order_state["\']/',
				$html,
				'AC-4 (a): NEW MUSS Refresh-Button rendern.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/data-action=["\']resend_failed_op["\']/',
				$html,
				'AC-4 (a): NEW DARF KEINEN Resend-Button rendern.'
			);
		}

		/**
		 * AC-4 (b) — State NEW ohne shipping-type: Confirm disabled mit
		 * aria-disabled="true" und title="Set shipping type first".
		 */
		public function test_render_actions_block_state_new_without_shipping_disables_confirm(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_state'         => 'NEW',
					'_spreadconnect_shipping_type' => '',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			// Confirm-Button is rendered but in disabled state.
			$this->assertMatchesRegularExpression(
				'/data-action=["\']confirm_order["\'][^>]*aria-disabled=["\']true["\']/s',
				$html,
				'AC-4 (b): NEW ohne shipping_type MUSS Confirm-Button mit aria-disabled="true" rendern.'
			);
			$this->assertStringContainsString(
				'Set shipping type first',
				$html,
				'AC-4 (b): Disabled-Confirm MUSS Title-Hint "Set shipping type first" tragen.'
			);
		}

		/**
		 * AC-4 (c) — CONFIRMED/PROCESSED: Confirm + Cancel hidden, only Refresh.
		 */
		public function test_render_actions_block_state_confirmed_hides_confirm_and_cancel(): void
		{
			foreach ( [ 'CONFIRMED', 'PROCESSED' ] as $state ) {
				$orderId = 7;
				$order   = $this->mockOrder(
					$orderId,
					[
						'_spreadconnect_state'         => $state,
						'_spreadconnect_shipping_type' => 'STANDARD',
					]
				);
				$this->wcOrders[ $orderId ] = $order;

				$html = $this->renderMetaBox( $orderId );

				$this->assertDoesNotMatchRegularExpression(
					'/data-action=["\']confirm_order["\']/',
					$html,
					sprintf( 'AC-4 (c): State %s DARF KEINEN Confirm-Button rendern.', $state )
				);
				$this->assertDoesNotMatchRegularExpression(
					'/data-action=["\']cancel_order["\']/',
					$html,
					sprintf( 'AC-4 (c): State %s DARF KEINEN Cancel-Button rendern.', $state )
				);
				$this->assertMatchesRegularExpression(
					'/data-action=["\']refresh_order_state["\']/',
					$html,
					sprintf( 'AC-4 (c): State %s MUSS Refresh-Button rendern.', $state )
				);
			}
		}

		/**
		 * AC-4 (e) — failed_to_submit: Resend-Button + Refresh-Button.
		 */
		public function test_render_actions_block_state_failed_to_submit_shows_resend(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_state'         => 'failed_to_submit',
					'_spreadconnect_shipping_type' => 'STANDARD',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertMatchesRegularExpression(
				'/data-action=["\']resend_failed_op["\']/',
				$html,
				'AC-4 (e): failed_to_submit MUSS Resend-Button rendern.'
			);
			$this->assertMatchesRegularExpression(
				'/data-action=["\']refresh_order_state["\']/',
				$html,
				'AC-4 (e): failed_to_submit MUSS Refresh-Button rendern.'
			);
		}

		// ===================================================================
		// AC-5: Webhook-Activity-Block — Last-5 aus WebhookLogRepo
		// ===================================================================

		/**
		 * AC-5 — render() liest WebhookLogRepo::findRecentForOrder()
		 * mit dem konfigurierten Limit von 5.
		 *
		 * WebhookLogRepo ist statisch + final — wir testen die Integration
		 * via realer findRecentForOrder()-Methode, gefuettert mit einem
		 * Programmable $wpdb-Fake.
		 */
		public function test_render_webhook_activity_calls_repo_with_limit_5(): void
		{
			global $wpdb;
			$fake                       = new WebhookLogRepoFakeWpdb();
			$fake->getResultsResult     = [
				[ 'event_type' => 'Order.created',   'received_at' => '2026-05-03 12:00:00', 'processing_status' => 'success' ],
				[ 'event_type' => 'Order.updated',   'received_at' => '2026-05-03 12:01:00', 'processing_status' => 'success' ],
				[ 'event_type' => 'Order.processed', 'received_at' => '2026-05-03 12:02:00', 'processing_status' => 'success' ],
			];
			$wpdb = $fake;

			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_order_id' => 'sc_77194',
					'_spreadconnect_state'    => 'NEW',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			// Container exists.
			$this->assertMatchesRegularExpression(
				'/data-block=["\']webhook-activity["\']/',
				$html,
				'AC-5: Webhook-Activity-Block MUSS data-block="webhook-activity" tragen.'
			);

			// One get_results call with %s, %s, 5 (limit).
			$this->assertNotEmpty(
				$fake->getResultsCalls,
				'AC-5: WebhookLogRepo MUSS get_results() einmal aufrufen.'
			);
			$this->assertNotEmpty(
				$fake->prepareCalls,
				'AC-5: WebhookLogRepo MUSS prepare() vor SELECT aufrufen.'
			);

			// One of the prepare-calls MUST contain the order-meta sc id + limit 5.
			$found = false;
			foreach ( $fake->prepareCalls as [ $_q, $args ] ) {
				if ( in_array( 'sc_77194', $args, true ) && in_array( 5, $args, true ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'AC-5: prepare() MUSS sc_77194 + Limit 5 als Args erhalten.'
			);

			// Rendered event-types appear in markup.
			$this->assertStringContainsString( 'Order.created', $html );
			$this->assertStringContainsString( 'Order.processed', $html );
		}

		/**
		 * AC-5 — Bei <5 Eintraegen werden Platzhalter-`<li>` mit `—`
		 * bis Slot 5 aufgefuellt.
		 */
		public function test_render_webhook_activity_pads_to_5_slots_when_fewer_entries(): void
		{
			global $wpdb;
			$fake                   = new WebhookLogRepoFakeWpdb();
			$fake->getResultsResult = [
				[ 'event_type' => 'Order.created', 'received_at' => '2026-05-03 12:00:00', 'processing_status' => 'success' ],
				[ 'event_type' => 'Order.updated', 'received_at' => '2026-05-03 12:01:00', 'processing_status' => 'success' ],
			];
			$wpdb = $fake;

			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_order_id' => 'sc_77194',
					'_spreadconnect_state'    => 'NEW',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			// Count `<li>` elements within webhook-activity block (rough match).
			$liCount = preg_match_all( '/<li[^>]*sc-webhook-activity-row/', $html );
			$this->assertSame(
				5,
				$liCount,
				'AC-5: Bei <5 Eintraegen MUSS auf 5 li-Slots aufgefuellt werden.'
			);
			$this->assertStringContainsString(
				'—',
				$html,
				'AC-5: Platzhalter-Slots MUSSEN den Em-Dash "—" enthalten.'
			);
		}

		/**
		 * AC-5 — Bei fehlendem `_spreadconnect_order_id`-Meta wird
		 * "Not yet submitted" gerendert; Repo-Call entfaellt.
		 */
		public function test_render_webhook_activity_skipped_without_sc_order_id(): void
		{
			global $wpdb;
			$fake = new WebhookLogRepoFakeWpdb();
			$wpdb = $fake;

			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_order_id' => '',
					'_spreadconnect_state'    => '',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertStringContainsString(
				'Not yet submitted',
				$html,
				'AC-5: Ohne sc_order_id MUSS "Not yet submitted" gerendert werden.'
			);
			$this->assertEmpty(
				$fake->getResultsCalls,
				'AC-5: Ohne sc_order_id DARF KEIN Repo-Call (get_results) erfolgen.'
			);
		}

		// ===================================================================
		// AC-6: Shipments-Block — Stub-Read aus Order-Meta
		// ===================================================================

		/**
		 * AC-6 (a) — Tracking-Number + Tracking-URL gesetzt: Link gerendert.
		 */
		public function test_render_shipments_block_shows_tracking_link_when_meta_set(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_tracking_number' => '1Z999AA10123456784',
					'_spreadconnect_tracking_url'    => 'https://tracking.example/1Z999AA10123456784',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertMatchesRegularExpression(
				'/data-block=["\']shipments["\']/',
				$html,
				'AC-6: Shipments-Block MUSS data-block="shipments" tragen.'
			);
			$this->assertStringContainsString(
				'1Z999AA10123456784',
				$html,
				'AC-6 (a): Tracking-Number MUSS gerendert werden.'
			);
			$this->assertStringContainsString(
				'https://tracking.example/1Z999AA10123456784',
				$html,
				'AC-6 (a): Tracking-URL MUSS in href stehen.'
			);
			$this->assertMatchesRegularExpression(
				'/<a[^>]*target=["\']_blank["\']/',
				$html,
				'AC-6 (a): Tracking-Link MUSS target="_blank" tragen.'
			);
		}

		/**
		 * AC-6 (b) — Ohne Tracking-Meta: Placeholder "no shipments recorded".
		 */
		public function test_render_shipments_block_shows_placeholder_when_no_tracking_meta(): void
		{
			$orderId = 7;
			$order   = $this->mockOrder(
				$orderId,
				[
					'_spreadconnect_tracking_number' => '',
					'_spreadconnect_tracking_url'    => '',
				]
			);
			$this->wcOrders[ $orderId ] = $order;

			$html = $this->renderMetaBox( $orderId );

			$this->assertStringContainsString(
				'no shipments recorded',
				$html,
				'AC-6 (b): Ohne Tracking-Meta MUSS "no shipments recorded" gerendert werden.'
			);
		}

		// ===================================================================
		// AC-7: Cap+Nonce-Gate fuer alle 5 Handler
		// ===================================================================

		/**
		 * AC-7 (a) — handleConfirm mit invalid Nonce -> 403, kein Enqueue.
		 */
		public function test_handle_confirm_returns_403_on_invalid_nonce(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'BAD' ];

			$thrown = null;
			try {
				$handler->handleConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown, 'AC-7: Handler MUSS via wp_send_json_error terminieren.' );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 403, $thrown->status, 'AC-7: Invalid nonce MUSS HTTP 403 sein.' );
			$this->assertEmpty( $this->asEnqueues, 'AC-7: KEIN as_enqueue_async_action bei nonce-fail.' );
		}

		/**
		 * AC-7 (b) — handleConfirm ohne manage_woocommerce -> 403.
		 */
		public function test_handle_confirm_returns_403_when_user_lacks_manage_woocommerce(): void
		{
			Functions\when( 'current_user_can' )->alias( static fn () => false );

			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 403, $thrown->status, 'AC-7: Cap-fail MUSS HTTP 403 sein.' );
			$this->assertEmpty( $this->asEnqueues, 'AC-7: KEIN as_enqueue bei cap-fail.' );
		}

		/**
		 * AC-7 (c) — handleConfirm ohne order_id -> 400.
		 */
		public function test_handle_confirm_returns_400_on_missing_order_id(): void
		{
			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ '_ajax_nonce' => 'NONCE_spreadconnect_admin' ]; // no order_id

			$thrown = null;
			try {
				$handler->handleConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 400, $thrown->status, 'AC-7: Missing order_id MUSS HTTP 400 sein.' );
			$this->assertEmpty( $this->asEnqueues );
		}

		/**
		 * AC-7 — handleCancel hat identische Cap+Nonce-Chain.
		 */
		public function test_handle_cancel_enforces_same_cap_and_nonce_chain(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'BAD' ];

			$thrown = null;
			try {
				$handler->handleCancel();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertSame( 403, $thrown->status );
			$this->assertEmpty( $this->asEnqueues );
		}

		/**
		 * AC-7 — handleRefreshState identische Cap+Nonce-Chain.
		 */
		public function test_handle_refresh_state_enforces_same_cap_and_nonce_chain(): void
		{
			Functions\when( 'current_user_can' )->alias( static fn () => false );

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldNotReceive( 'getOrder' );

			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleRefreshState();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertSame( 403, $thrown->status );
		}

		/**
		 * AC-7 — handleSaveShippingType identische Cap+Nonce-Chain.
		 */
		public function test_handle_save_shipping_type_enforces_same_cap_and_nonce_chain(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldNotReceive( 'setShippingType' );

			$_POST = [
				'order_id'      => '7',
				'shipping_type' => 'EXPRESS',
				'_ajax_nonce'   => 'BAD',
			];

			$thrown = null;
			try {
				$handler->handleSaveShippingType();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertSame( 403, $thrown->status );
		}

		/**
		 * AC-7 — handleCancelAutoConfirm identische Cap+Nonce-Chain.
		 */
		public function test_handle_cancel_auto_confirm_enforces_same_cap_and_nonce_chain(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'BAD' ];

			$thrown = null;
			try {
				$handler->handleCancelAutoConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertSame( 403, $thrown->status );
			$this->assertEmpty( $this->asUnschedules, 'AC-7: KEIN as_unschedule bei nonce-fail.' );
		}

		/**
		 * AC-7 — Bei Gate-Failure DARF weder Enqueue noch Client-Call laufen.
		 */
		public function test_failed_gate_does_not_enqueue_or_call_client(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldNotReceive( 'getOrder' );
			$client->shouldNotReceive( 'setShippingType' );
			$client->shouldNotReceive( 'getShippingTypes' );

			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'BAD' ];

			foreach ( [ 'handleConfirm', 'handleCancel', 'handleRefreshState', 'handleSaveShippingType', 'handleCancelAutoConfirm' ] as $method ) {
				try {
					$handler->{$method}();
				} catch ( Slice32JsonResponseSentinel $e ) {
					// expected
				}
			}

			$this->assertEmpty( $this->asEnqueues, 'AC-7: Bei nonce-fail KEIN as_enqueue.' );
			$this->assertEmpty( $this->asUnschedules, 'AC-7: Bei nonce-fail KEIN as_unschedule.' );
		}

		// ===================================================================
		// AC-8: Confirm enqueues Slice-29-Hook
		// ===================================================================

		/**
		 * AC-8 — handleConfirm enqueues spreadconnect/confirm_order
		 * mit ['order_id' => 7] und Group "spreadconnect".
		 */
		public function test_handle_confirm_enqueues_spreadconnect_confirm_order_with_order_id_arg(): void
		{
			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-8: Success-Pfad MUSS wp_send_json_success sein.' );
			$this->assertTrue( $thrown->payload['queued'] ?? null, 'AC-8: Response MUSS queued=true tragen.' );

			$this->assertCount( 1, $this->asEnqueues, 'AC-8: GENAU EINMAL as_enqueue_async_action.' );
			[ $hook, $args, $group ] = $this->asEnqueues[0];
			$this->assertSame( 'spreadconnect/confirm_order', $hook, 'AC-8: Hook-Name MUSS Slice-29-Convention treffen.' );
			$this->assertSame( [ 'order_id' => 7 ], $args, 'AC-8: Args MUSSEN ["order_id"=>7] sein (assoc, kein outer wrap).' );
			$this->assertSame( 'spreadconnect', $group, 'AC-8: Group MUSS "spreadconnect" sein.' );
		}

		// ===================================================================
		// AC-9: Cancel enqueues Slice-29-Cancel-Hook
		// ===================================================================

		public function test_handle_cancel_enqueues_spreadconnect_cancel_order_with_order_id_arg(): void
		{
			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleCancel();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success );
			$this->assertTrue( $thrown->payload['queued'] ?? null );

			$this->assertCount( 1, $this->asEnqueues );
			[ $hook, $args, $group ] = $this->asEnqueues[0];
			$this->assertSame( 'spreadconnect/cancel_order', $hook, 'AC-9: Hook-Name MUSS Cancel-Slice-29-Convention treffen.' );
			$this->assertSame( [ 'order_id' => 7 ], $args );
			$this->assertSame( 'spreadconnect', $group );
		}

		// ===================================================================
		// AC-10: Refresh-State synchron
		// ===================================================================

		/**
		 * AC-10 — handleRefreshState ruft getOrder() einmal, persistiert
		 * State-Meta + last_event und antwortet {ok, state, last_event}.
		 */
		public function test_handle_refresh_state_calls_get_order_and_persists_state_meta(): void
		{
			$orderId    = 7;
			$capturedMeta = [];
			$savedTimes   = 0;

			$order = $this->mockOrder( $orderId, [ '_spreadconnect_order_id' => 'sc_42' ] );
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $k, $v ) use ( &$capturedMeta ): void {
					$capturedMeta[ $k ] = $v;
				} );
			$order->shouldReceive( 'save' )
				->andReturnUsing( static function () use ( &$savedTimes, $orderId ): int {
					$savedTimes++;
					return $orderId;
				} );
			$this->wcOrders[ $orderId ] = $order;

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldReceive( 'getOrder' )
				->once()
				->with( 'sc_42' )
				->andReturn( [
					'id'        => 'sc_42',
					'state'     => 'CONFIRMED',
					'updatedAt' => '2026-05-03T14:14:00Z',
				] );

			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleRefreshState();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-10: Success-Pfad MUSS wp_send_json_success sein.' );
			$this->assertSame(
				'CONFIRMED',
				$thrown->payload['state'] ?? null,
				'AC-10: Response MUSS state="CONFIRMED" tragen.'
			);
			$this->assertArrayHasKey(
				'last_event',
				$thrown->payload,
				'AC-10: Response MUSS last_event-Key tragen.'
			);

			$this->assertSame(
				'CONFIRMED',
				$capturedMeta['_spreadconnect_state'] ?? null,
				'AC-10: _spreadconnect_state MUSS persistiert sein.'
			);
			$this->assertGreaterThan( 0, $savedTimes, 'AC-10: $order->save() MUSS aufgerufen werden.' );
		}

		/**
		 * AC-10 — Bei SpreadconnectClientError -> HTTP 502, kein Meta-Write.
		 */
		public function test_handle_refresh_state_returns_502_on_client_error(): void
		{
			$orderId = 7;
			$writes  = 0;

			$order = $this->mockOrder( $orderId, [ '_spreadconnect_order_id' => 'sc_42' ] );
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function () use ( &$writes ): void {
					$writes++;
				} );
			$this->wcOrders[ $orderId ] = $order;

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldReceive( 'getOrder' )
				->once()
				->with( 'sc_42' )
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'Bad request', 422, '/orders/sc_42' ) );

			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleRefreshState();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success );
			$this->assertSame( 502, $thrown->status, 'AC-10: Upstream-Error MUSS HTTP 502 sein.' );
			$this->assertSame( 0, $writes, 'AC-10: Bei Client-Error DARF KEIN update_meta_data laufen.' );
		}

		// ===================================================================
		// AC-11: Save-Shipping-Type
		// ===================================================================

		/**
		 * AC-11 — handleSaveShippingType ruft setShippingType() und
		 * persistiert _spreadconnect_shipping_type.
		 */
		public function test_handle_save_shipping_type_calls_set_shipping_type_and_persists_meta(): void
		{
			$orderId      = 7;
			$capturedMeta = [];

			$order = $this->mockOrder( $orderId, [ '_spreadconnect_order_id' => 'sc_42' ] );
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $k, $v ) use ( &$capturedMeta ): void {
					$capturedMeta[ $k ] = $v;
				} );
			$this->wcOrders[ $orderId ] = $order;

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldReceive( 'setShippingType' )
				->once()
				->with( 'sc_42', 'EXPRESS' )
				->andReturn( [ 'shippingType' => 'EXPRESS' ] );

			$_POST = [
				'order_id'      => '7',
				'shipping_type' => 'EXPRESS',
				'_ajax_nonce'   => 'NONCE_spreadconnect_admin',
			];

			$thrown = null;
			try {
				$handler->handleSaveShippingType();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-11: 2xx-Pfad MUSS wp_send_json_success.' );
			$this->assertSame( 'EXPRESS', $thrown->payload['shipping_type'] ?? null, 'AC-11: Response MUSS shipping_type="EXPRESS".' );
			$this->assertSame(
				'EXPRESS',
				$capturedMeta['_spreadconnect_shipping_type'] ?? null,
				'AC-11: _spreadconnect_shipping_type MUSS persistiert sein.'
			);
		}

		/**
		 * AC-11 — Bei SpreadconnectClientError -> kein Meta-Write, HTTP 502.
		 */
		public function test_handle_save_shipping_type_skips_meta_write_on_client_error(): void
		{
			$orderId      = 7;
			$capturedMeta = [];

			$order = $this->mockOrder( $orderId, [ '_spreadconnect_order_id' => 'sc_42' ] );
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $k, $v ) use ( &$capturedMeta ): void {
					$capturedMeta[ $k ] = $v;
				} );
			$this->wcOrders[ $orderId ] = $order;

			[ $handler, $client ] = $this->buildHandler();
			$client->shouldReceive( 'setShippingType' )
				->once()
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'Bad', 400, '/orders/sc_42/shippingType' ) );

			$_POST = [
				'order_id'      => '7',
				'shipping_type' => 'EXPRESS',
				'_ajax_nonce'   => 'NONCE_spreadconnect_admin',
			];

			$thrown = null;
			try {
				$handler->handleSaveShippingType();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertFalse( $thrown->success, 'AC-11: Error-Pfad MUSS wp_send_json_error.' );
			$this->assertSame( 502, $thrown->status, 'AC-11: Client-Error MUSS HTTP 502 sein.' );
			$this->assertArrayNotHasKey(
				'_spreadconnect_shipping_type',
				$capturedMeta,
				'AC-11: Bei Client-Error DARF KEIN _spreadconnect_shipping_type geschrieben werden.'
			);
		}

		// ===================================================================
		// AC-12: Cancel-Auto-Confirm via AS-Unschedule
		// ===================================================================

		/**
		 * AC-12 — handleCancelAutoConfirm ruft as_unschedule_action mit
		 * spreadconnect/confirm_order, ['order_id'=>7], 'spreadconnect'.
		 */
		public function test_handle_cancel_auto_confirm_calls_as_unschedule_action_with_order_id_arg(): void
		{
			[ $handler, $client ] = $this->buildHandler();
			$_POST = [ 'order_id' => '7', '_ajax_nonce' => 'NONCE_spreadconnect_admin' ];

			$thrown = null;
			try {
				$handler->handleCancelAutoConfirm();
			} catch ( Slice32JsonResponseSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue( $thrown->success, 'AC-12: Success-Pfad MUSS wp_send_json_success.' );
			$this->assertTrue( $thrown->payload['unscheduled'] ?? null );

			$this->assertCount( 1, $this->asUnschedules, 'AC-12: GENAU EINMAL as_unschedule_action.' );
			[ $hook, $args, $group ] = $this->asUnschedules[0];
			$this->assertSame( 'spreadconnect/confirm_order', $hook );
			$this->assertSame( [ 'order_id' => 7 ], $args );
			$this->assertSame( 'spreadconnect', $group );

			// AC-12: Slice 32 ruft NICHT direkt einen Slice-29-Job auf.
			$this->assertEmpty( $this->asEnqueues, 'AC-12: KEIN direkter as_enqueue_async_action.' );
		}

		// ===================================================================
		// AC-13: Asset-Enqueue auf Order-Edit-Screen
		// ===================================================================

		/**
		 * AC-13 — Auf HPOS-Order-Edit-Screen MUSS Script registriert,
		 * enqueued und localized werden.
		 */
		public function test_enqueue_assets_registers_script_only_on_order_edit_screens(): void
		{
			$this->currentScreen = (object) [
				'id'        => 'woocommerce_page_wc-orders',
				'post_type' => '',
			];

			OrderMetaBox::enqueueAssets( 'woocommerce_page_wc-orders' );

			$this->assertCount( 1, $this->registeredScripts, 'AC-13: Script MUSS einmal registriert sein.' );
			$reg = $this->registeredScripts[0];

			$this->assertSame( 'spreadconnect-order-meta-box', $reg['handle'], 'AC-13: Handle MUSS "spreadconnect-order-meta-box" sein.' );
			$this->assertContains( 'jquery', (array) $reg['deps'], 'AC-13: Deps MUSS jquery enthalten.' );
			$this->assertTrue( (bool) $reg['in_footer'], 'AC-13: in_footer MUSS true sein.' );

			$this->assertContains( 'spreadconnect-order-meta-box', $this->enqueuedScripts, 'AC-13: Script MUSS enqueued sein.' );
		}

		/**
		 * AC-13 — wp_localize_script erhaelt ajaxUrl, nonce, actions, i18n.
		 */
		public function test_enqueue_assets_localizes_ajax_url_nonce_and_actions(): void
		{
			$this->currentScreen = (object) [
				'id'        => 'woocommerce_page_wc-orders',
				'post_type' => '',
			];

			OrderMetaBox::enqueueAssets( 'woocommerce_page_wc-orders' );

			$this->assertCount( 1, $this->localizedScripts, 'AC-13: localize MUSS einmal gerufen werden.' );
			$loc = $this->localizedScripts[0];

			$this->assertSame( 'SpreadconnectOrderMetaBox', $loc['object'], 'AC-13: JS-Objekt-Name MUSS "SpreadconnectOrderMetaBox" sein.' );

			$data = (array) $loc['data'];
			$this->assertArrayHasKey( 'ajaxUrl', $data, 'AC-13: localize MUSS ajaxUrl enthalten.' );
			$this->assertSame( 'http://example.test/wp-admin/admin-ajax.php', $data['ajaxUrl'] );

			$this->assertArrayHasKey( 'nonce', $data, 'AC-13: localize MUSS nonce enthalten.' );
			$this->assertSame( 'NONCE_spreadconnect_admin', $data['nonce'], 'AC-13: Nonce-Action-Name MUSS "spreadconnect_admin" sein.' );

			$this->assertArrayHasKey( 'actions', $data, 'AC-13: localize MUSS actions-Map enthalten.' );
			$actions = (array) $data['actions'];
			$this->assertSame( 'spreadconnect_confirm_order', $actions['confirm_order'] ?? null );
			$this->assertSame( 'spreadconnect_cancel_order', $actions['cancel_order'] ?? null );
			$this->assertSame( 'spreadconnect_refresh_order_state', $actions['refresh_order_state'] ?? null );
			$this->assertSame( 'spreadconnect_save_shipping_type', $actions['save_shipping_type'] ?? null );
			$this->assertSame( 'spreadconnect_cancel_auto_confirm', $actions['cancel_auto_confirm'] ?? null );

			$this->assertArrayHasKey( 'i18n', $data, 'AC-13: localize MUSS i18n-Map enthalten.' );
			$this->assertIsArray( $data['i18n'] );
		}

		/**
		 * AC-13 — Auf anderen Admin-Screens KEIN Enqueue.
		 */
		public function test_enqueue_assets_skips_other_admin_screens(): void
		{
			$this->currentScreen = (object) [
				'id'        => 'edit-page',
				'post_type' => 'page',
			];

			OrderMetaBox::enqueueAssets( 'edit.php' );

			$this->assertEmpty( $this->registeredScripts, 'AC-13: Auf Page-Screen DARF KEIN register_script laufen.' );
			$this->assertEmpty( $this->enqueuedScripts, 'AC-13: Auf Page-Screen DARF KEIN enqueue laufen.' );
			$this->assertEmpty( $this->localizedScripts );
		}

		// ===================================================================
		// AC-14: Bootstrap-Wiring
		// ===================================================================

		/**
		 * AC-14 (a/b) — Plugin::init() registriert add_meta_boxes +
		 * admin_enqueue_scripts.
		 */
		public function test_plugin_init_registers_add_meta_boxes_and_admin_enqueue_hooks(): void
		{
			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			$this->assertNotFalse(
				Actions\has( 'add_meta_boxes', [ OrderMetaBox::class, 'registerOnAddMetaBoxes' ] ),
				'AC-14 (a): add_action("add_meta_boxes", [OrderMetaBox::class, "registerOnAddMetaBoxes"]) MUSS registriert sein.'
			);

			$this->assertNotFalse(
				Actions\has( 'admin_enqueue_scripts', [ OrderMetaBox::class, 'enqueueAssets' ] ),
				'AC-14 (b): add_action("admin_enqueue_scripts", [OrderMetaBox::class, "enqueueAssets"]) MUSS registriert sein.'
			);
		}

		/**
		 * AC-14 (c) — Alle 5 wp_ajax_*-Hooks fuer die Order-Actions sind
		 * registriert (admin-only, kein nopriv).
		 */
		public function test_plugin_init_registers_all_five_wp_ajax_hooks(): void
		{
			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			$expected = [
				'wp_ajax_spreadconnect_confirm_order',
				'wp_ajax_spreadconnect_cancel_order',
				'wp_ajax_spreadconnect_refresh_order_state',
				'wp_ajax_spreadconnect_save_shipping_type',
				'wp_ajax_spreadconnect_cancel_auto_confirm',
			];

			foreach ( $expected as $hook ) {
				$this->assertNotFalse(
					Actions\has( $hook ),
					sprintf( 'AC-14 (c): %s MUSS registriert sein.', $hook )
				);
			}

			// Defense-in-Depth: KEIN nopriv.
			foreach ( $expected as $hook ) {
				$nopriv = str_replace( 'wp_ajax_', 'wp_ajax_nopriv_', $hook );
				$this->assertFalse(
					Actions\has( $nopriv ),
					sprintf( 'AC-14: %s DARF NIE registriert sein (admin-only).', $nopriv )
				);
			}
		}

		/**
		 * AC-14 — Doppelter init() fuegt Hooks NICHT doppelt hinzu.
		 */
		public function test_plugin_init_hook_registration_is_idempotent(): void
		{
			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			// Capture priority of first registration.
			$priorityFirst = Actions\has( 'add_meta_boxes', [ OrderMetaBox::class, 'registerOnAddMetaBoxes' ] );
			$this->assertNotFalse( $priorityFirst );

			// Second init() — should be a no-op due to self::$initialized guard.
			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			$priorityAfter = Actions\has( 'add_meta_boxes', [ OrderMetaBox::class, 'registerOnAddMetaBoxes' ] );
			$this->assertSame(
				$priorityFirst,
				$priorityAfter,
				'AC-14: Doppelter init() DARF die Hook-Registrierung NICHT veraendern (idempotent).'
			);
		}

		// ===================================================================
		// WebhookLogRepo (Slice-32 AC-5 read-path + Slice-16 AC-5/AC-6 contract)
		// ===================================================================

		/**
		 * Slice-32 AC-5 (read) — findRecentForOrder($scOrderId, $limit)
		 * baut einen LIMIT-prepared SELECT (related_entity_type='order',
		 * related_entity_id=$scOrderId, ORDER BY received_at DESC LIMIT $limit).
		 */
		public function test_webhook_log_repo_find_recent_for_order_returns_rows(): void
		{
			global $wpdb;
			$fake                   = new WebhookLogRepoFakeWpdb();
			$fake->getResultsResult = [
				[ 'event_type' => 'Order.created', 'received_at' => '2026-05-03 12:00:00', 'processing_status' => 'success' ],
				[ 'event_type' => 'Order.updated', 'received_at' => '2026-05-03 12:01:00', 'processing_status' => 'success' ],
			];
			$wpdb = $fake;

			$rows = WebhookLogRepo::findRecentForOrder( 'sc_42', 5 );

			$this->assertCount( 2, $rows, 'Slice-32 AC-5: Repo MUSS Repo-Rows als Array zurueckgeben.' );
			$this->assertSame( 'Order.created', $rows[0]['event_type'] ?? null );

			$this->assertCount( 1, $fake->prepareCalls, 'Slice-32 AC-5: prepare() MUSS einmal aufgerufen werden.' );
			[ $sql, $args ] = $fake->prepareCalls[0];
			$this->assertStringContainsString( 'related_entity_type', $sql );
			$this->assertStringContainsString( 'related_entity_id', $sql );
			$this->assertStringContainsString( 'received_at DESC', $sql );
			$this->assertStringContainsString( 'LIMIT', $sql );
			$this->assertContains( 'order', $args, 'Slice-32 AC-5: prepare-args MUSSEN entity-type "order" enthalten.' );
			$this->assertContains( 'sc_42', $args, 'Slice-32 AC-5: prepare-args MUSSEN sc_order_id enthalten.' );
			$this->assertContains( 5, $args, 'Slice-32 AC-5: prepare-args MUSSEN $limit enthalten.' );
		}

		/**
		 * Slice-32 AC-5 — Bei leerem $scOrderId-String wird KEIN
		 * SQL-Query abgesetzt (Repo gibt direkt [] zurueck).
		 */
		public function test_webhook_log_repo_find_recent_for_order_short_circuits_on_empty(): void
		{
			global $wpdb;
			$fake = new WebhookLogRepoFakeWpdb();
			$wpdb = $fake;

			$rows = WebhookLogRepo::findRecentForOrder( '', 5 );

			$this->assertSame( [], $rows );
			$this->assertEmpty( $fake->prepareCalls );
			$this->assertEmpty( $fake->getResultsCalls );
		}

		/**
		 * Slice-16 AC-5 — insertOrIgnore liefert {status:'inserted', log_id}
		 * bei erfolgreichem INSERT.
		 */
		public function test_webhook_log_repo_insert_or_ignore_returns_inserted_on_success(): void
		{
			global $wpdb;
			$fake               = new WebhookLogRepoFakeWpdb();
			$fake->insertResult = 1;
			$fake->insert_id    = 42;
			$wpdb               = $fake;

			$row = [
				'event_id'   => 'abc123',
				'event_type' => 'Order.created',
				'payload'    => '{"foo":1}',
			];

			$result = WebhookLogRepo::insertOrIgnore( $row );

			$this->assertSame(
				WebhookLogRepo::STATUS_INSERTED,
				$result['status'] ?? null,
				'Slice-16 AC-5: status MUSS "inserted" sein.'
			);
			$this->assertSame( 42, $result['log_id'] ?? null, 'Slice-16 AC-5: log_id MUSS insert_id sein.' );

			$this->assertCount( 1, $fake->insertCalls );
			$this->assertSame( 'wp_spreadconnect_webhook_log', $fake->insertCalls[0]['table'] );
		}

		/**
		 * Slice-16 AC-6 — Bei UNIQUE-Conflict (Duplicate entry) liefert
		 * insertOrIgnore {status:'duplicate', log_id} und MARKIERT die
		 * existierende Row via UPDATE als duplicate (downstream-Marker).
		 */
		public function test_webhook_log_repo_insert_or_ignore_returns_duplicate_on_unique_conflict(): void
		{
			global $wpdb;
			$fake                = new WebhookLogRepoFakeWpdb();
			$fake->insertResult  = false;
			$fake->last_error    = "Duplicate entry 'abc123' for key 'event_id'";
			$fake->getVarResult  = '7';
			$wpdb                = $fake;

			$row = [
				'event_id'   => 'abc123',
				'event_type' => 'Order.created',
				'payload'    => '{}',
			];

			$result = WebhookLogRepo::insertOrIgnore( $row );

			$this->assertSame(
				WebhookLogRepo::STATUS_DUPLICATE,
				$result['status'] ?? null,
				'Slice-16 AC-6: status MUSS "duplicate" sein bei UNIQUE-Conflict.'
			);
			$this->assertSame( 7, $result['log_id'] ?? null, 'Slice-16 AC-6: log_id MUSS auf existing row zeigen.' );

			// Slice-16 AC-6 mark-existing-as-duplicate UPDATE
			$this->assertNotEmpty( $fake->updateCalls, 'Slice-16 AC-6: existing row MUSS via UPDATE markiert werden.' );
			$update = $fake->updateCalls[0];
			$this->assertSame(
				WebhookLogRepo::STATUS_DUPLICATE,
				$update['data']['processing_status'] ?? null,
				'Slice-16 AC-6: UPDATE MUSS processing_status="duplicate" setzen.'
			);
			$this->assertSame( 7, $update['where']['id'] ?? null );
		}

		/**
		 * Slice-16 AC-5 — Bei NICHT-UNIQUE-Insert-Failure (z.B. Schema-Mismatch
		 * ohne "Duplicate entry"-Marker) MUSS RuntimeException fliegen.
		 *
		 * Binary-Return-Contract: status ist ENTWEDER 'inserted' ODER
		 * 'duplicate' — ein dritter Zustand existiert nicht.
		 */
		public function test_webhook_log_repo_insert_or_ignore_throws_runtime_exception_on_other_insert_failure(): void
		{
			global $wpdb;
			$fake               = new WebhookLogRepoFakeWpdb();
			$fake->insertResult = false;
			$fake->last_error   = 'Some unrelated MySQL error: Lost connection during query';
			$wpdb               = $fake;

			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'spreadconnect_webhook_log_insert_failed' );

			WebhookLogRepo::insertOrIgnore( [ 'event_id' => 'x', 'event_type' => 'Order.x' ] );
		}

		// ===================================================================
		// Source-Constraints (slice-32)
		// ===================================================================

		public function test_order_meta_box_class_is_final(): void
		{
			$rc = new ReflectionClass( OrderMetaBox::class );
			$this->assertTrue(
				$rc->isFinal(),
				'Constraint: OrderMetaBox MUSS `final class` sein.'
			);
		}

		public function test_order_actions_class_is_final(): void
		{
			$rc = new ReflectionClass( OrderActions::class );
			$this->assertTrue(
				$rc->isFinal(),
				'Constraint: OrderActions MUSS `final class` sein.'
			);
		}

		public function test_order_actions_constructor_accepts_spreadconnect_client(): void
		{
			$rc   = new ReflectionClass( OrderActions::class );
			$ctor = $rc->getConstructor();
			$this->assertNotNull( $ctor, 'Constraint: OrderActions MUSS einen Konstruktor haben.' );

			$params = $ctor->getParameters();
			$this->assertGreaterThanOrEqual( 1, count( $params ) );

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
