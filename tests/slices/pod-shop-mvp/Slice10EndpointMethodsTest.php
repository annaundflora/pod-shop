<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `SpreadconnectClient` references the `WP_Error` class for network-level
// failure detection (Slice 07 baseline). Slice 10's wrappers do NOT touch
// `WP_Error` directly — they only build path/body and delegate to
// `request()`. We still load the same `WP_Error` stub the Slice07 test uses
// so the file load order remains compatible.
//
// Slice 10 tests do NOT exercise `wp_remote_request` at all: a Test-Subclass
// override of `request()` (`RequestSpyClient`) captures every wrapper-level
// dispatch and dispenses a canned response, so no Brain\Monkey aliases for
// `wp_remote_*` / `get_option` / `wc_get_logger` are required.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
		/**
		 * Minimal WP_Error stub. Slice 10 doesn't touch it directly but
		 * SpreadconnectClient imports the symbol at parse time.
		 */
		class WP_Error
		{
			public string $code;
			public string $message;
			public mixed $data;

			public function __construct( string $code = '', string $message = '', mixed $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		/**
		 * Minimal `wp_json_encode` stub.
		 */
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}
}

namespace SpreadconnectPod\Tests {

	use InvalidArgumentException;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Api\Dto\Address;
	use SpreadconnectPod\Api\Dto\ArticleDetail;
	use SpreadconnectPod\Api\Dto\AuthOk;
	use SpreadconnectPod\Api\Dto\Money;
	use SpreadconnectPod\Api\Dto\OrderCreate;
	use SpreadconnectPod\Api\Dto\OrderItem;
	use SpreadconnectPod\Api\Dto\Preview;
	use SpreadconnectPod\Api\Dto\ShippingType;
	use SpreadconnectPod\Api\Dto\StockEntry;
	use SpreadconnectPod\Api\Dto\Subscription;
	use SpreadconnectPod\Api\NotImplementedError;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use Throwable;

	/**
	 * Test-Subclass that overrides {@see SpreadconnectClient::request()} and
	 * captures every invocation in a public `$calls` array. Per-call response
	 * tuples are pre-loaded into `$responses` (FIFO) — fall back to the
	 * `$defaultResponse` when the queue is empty.
	 *
	 * Optional `$throwOnNext` lets a single test inject an exception (or
	 * Throwable) that the next `request()` call will re-throw, so we can
	 * verify Slice-10 AC-12 (exception pass-through) without going through
	 * the real Slice-07/08 dispatch path.
	 */
	final class RequestSpyClient extends SpreadconnectClient
	{
		/**
		 * Captured calls — list of `['method' => string, 'path' => string, 'body' => mixed]`.
		 *
		 * @var list<array{method:string,path:string,body:mixed}>
		 */
		public array $calls = [];

		/**
		 * FIFO response queue — each entry is the Slice-07 tuple shape
		 * `['status'=>int,'body'=>array,'headers'=>array]`.
		 *
		 * @var list<array{status:int,body:array<int|string,mixed>,headers:array<string,string>}>
		 */
		public array $responses = [];

		/**
		 * Default response used when the queue is empty.
		 *
		 * @var array{status:int,body:array<int|string,mixed>,headers:array<string,string>}
		 */
		public array $defaultResponse = [
			'status'  => 200,
			'body'    => [],
			'headers' => [],
		];

		/**
		 * If non-null, the next `request()` call rethrows this Throwable.
		 */
		public ?Throwable $throwOnNext = null;

		public function __construct() {
			parent::__construct( 'TEST_API_KEY_NEVER_USED' );
		}

		public function request( string $method, string $path, ?array $body = null ): array
		{
			$this->calls[] = [
				'method' => $method,
				'path'   => $path,
				'body'   => $body,
			];

			if ( null !== $this->throwOnNext ) {
				$toThrow            = $this->throwOnNext;
				$this->throwOnNext  = null;
				throw $toThrow;
			}

			if ( ! empty( $this->responses ) ) {
				return array_shift( $this->responses );
			}

			return $this->defaultResponse;
		}

		/**
		 * Convenience: enqueue a 200-tuple with the given body.
		 *
		 * @param array<int|string,mixed> $body
		 */
		public function pushResponse( array $body, int $status = 200 ): void
		{
			$this->responses[] = [
				'status'  => $status,
				'body'    => $body,
				'headers' => [],
			];
		}

		/**
		 * Convenience: assertion-friendly accessor for the most recent call.
		 *
		 * @return array{method:string,path:string,body:mixed}
		 */
		public function lastCall(): array
		{
			$count = count( $this->calls );
			if ( 0 === $count ) {
				throw new \RuntimeException( 'RequestSpyClient: no request() call was made.' );
			}
			return $this->calls[ $count - 1 ];
		}
	}

	/**
	 * Slice 10 — Endpoint-Wrapper-Methoden (27 typed Methods + 4 reserved).
	 *
	 * Acceptance Tests gegen `slice-10-endpoint-methods.md`.
	 *
	 * Mocking-Strategy: `mock_external` (Spec). Statt Brain\Monkey-Aliase
	 * fuer `wp_remote_*` setzen wir auf eine **Test-Subclass**
	 * {@see RequestSpyClient}, die nur `request()` ueberschreibt. Damit
	 * bleiben Slice 07/08 Tests vollstaendig unangetastet (keine Re-Asserts
	 * auf Header / Sleep / etc.) und wir testen exklusiv die Slice-10
	 * Adapter-Logik (Path-Building, Body-Shape, DTO-Mapping).
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
	 */
	final class Slice10EndpointMethodsTest extends TestCase
	{
		// ===================================================================
		// AC-1: GIVEN ein Mock auf request(), der bei ('GET','/authentication',null)
		//             ein AuthOk-konformes Body-Array zurueckgibt
		//       WHEN authenticate() aufgerufen wird
		//       THEN uebergibt der Wrapper exakt 'GET','/authentication',null;
		//            der Returnwert ist eine AuthOk-Instanz; bei Throw
		//            propagiert der Wrapper unveraendert.
		// ===================================================================

		public function test_ac1_authenticate_calls_get_authentication_and_maps_to_auth_ok_dto(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'pointOfSaleId' => 'pos_42', 'accountId' => 'acc_7' ] );

			$result = $client->authenticate();

			$this->assertCount( 1, $client->calls, 'AC-1: authenticate() MUSS request() genau einmal aufrufen.' );
			$this->assertSame( 'GET', $client->calls[0]['method'], 'AC-1: HTTP-Verb MUSS GET sein.' );
			$this->assertSame( '/authentication', $client->calls[0]['path'], 'AC-1: Pfad MUSS exakt /authentication sein.' );
			$this->assertNull( $client->calls[0]['body'], 'AC-1: Body MUSS null sein (body-less GET).' );

			$this->assertInstanceOf( AuthOk::class, $result, 'AC-1: Returnwert MUSS AuthOk-Instanz sein.' );
			$this->assertSame( 'pos_42', $result->pointOfSaleId );
			$this->assertSame( 'acc_7', $result->accountId );
		}

		// ===================================================================
		// AC-2: getArticles() / getArticle() — Query-String + Path-Param
		// ===================================================================

		public function test_ac2_get_articles_builds_paginated_query_string_without_search(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'items' => [], 'total' => 0 ] );

			$client->getArticles( 2, 50 );

			$this->assertSame( 'GET', $client->lastCall()['method'] );
			$this->assertSame(
				'/articles?page=2&size=50',
				$client->lastCall()['path'],
				'AC-2: Pfad ohne search MUSS exakt /articles?page=2&size=50 sein (kein search-Param).'
			);
			$this->assertNull( $client->lastCall()['body'] );
		}

		public function test_ac2_get_articles_includes_search_param_when_provided(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'items' => [] ] );

			$client->getArticles( 1, 25, 'shirt' );

			$path = $client->lastCall()['path'];
			$this->assertStringStartsWith( '/articles?', $path, 'AC-2: search-Variante MUSS Query-String enthalten.' );
			$this->assertStringContainsString( 'page=1', $path );
			$this->assertStringContainsString( 'size=25', $path );
			$this->assertStringContainsString( 'search=shirt', $path );
		}

		public function test_ac2_get_articles_url_encodes_search_with_spaces(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'items' => [] ] );

			$client->getArticles( 1, 50, 'tee shirt' );

			$path = $client->lastCall()['path'];
			$this->assertTrue(
				str_contains( $path, 'search=tee+shirt' ) || str_contains( $path, 'search=tee%20shirt' ),
				'AC-11: search-Param MUSS RFC-3986-konform encoded sein (tee+shirt oder tee%20shirt). Pfad: ' . $path
			);
		}

		public function test_ac2_get_article_calls_path_with_id_and_maps_to_article_detail(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					'id'            => 'art_42',
					'title'         => 'Demo',
					'productTypeId' => 'pt_1',
					'variants'      => [],
				]
			);

			$result = $client->getArticle( 'art_42' );

			$this->assertSame( 'GET', $client->lastCall()['method'] );
			$this->assertSame( '/articles/art_42', $client->lastCall()['path'] );
			$this->assertNull( $client->lastCall()['body'] );

			$this->assertInstanceOf( ArticleDetail::class, $result, 'AC-2: getArticle() MUSS ArticleDetail-Instanz liefern.' );
			$this->assertSame( 'art_42', $result->id );
			$this->assertSame( 'Demo', $result->title );
		}

		// ===================================================================
		// AC-3: Order-Lifecycle (create / get / confirm / cancel)
		// ===================================================================

		public function test_ac3_create_order_posts_camel_to_snake_converted_body(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					'id'    => 'ord_1',
					'state' => 'NEW',
				]
			);

			$dto = $this->makeValidOrderCreate();

			$client->createOrder( $dto );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders', $call['path'] );
			$this->assertIsArray( $call['body'], 'AC-3: createOrder-Body MUSS assoc Array sein, nicht null.' );

			// snake_case Top-Level-Keys.
			$this->assertArrayHasKey(
				'external_order_reference',
				$call['body'],
				'AC-3: Body MUSS snake_case Key external_order_reference enthalten.'
			);
			$this->assertSame( 'wc-7', $call['body']['external_order_reference'] );

			$this->assertArrayHasKey( 'order_items', $call['body'], 'AC-3: Body MUSS snake_case order_items enthalten.' );
			$this->assertArrayHasKey( 'billing_address', $call['body'], 'AC-3: Body MUSS snake_case billing_address enthalten.' );
			$this->assertArrayHasKey( 'shipping_address', $call['body'], 'AC-3: Body MUSS snake_case shipping_address enthalten.' );

			// Nested Address-Keys auch snake_case.
			$this->assertArrayHasKey(
				'first_name',
				$call['body']['billing_address'],
				'AC-3: Nested Address-Keys MUESSEN snake_case sein (first_name).'
			);
			$this->assertArrayHasKey(
				'zip_code',
				$call['body']['billing_address'],
				'AC-3: Address-zipCode MUSS zu zip_code konvertiert werden.'
			);
		}

		public function test_ac3_get_order_calls_get_orders_id(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_7', 'state' => 'NEW' ] );

			$result = $client->getOrder( 'ord_7' );

			$this->assertSame( 'GET', $client->lastCall()['method'] );
			$this->assertSame( '/orders/ord_7', $client->lastCall()['path'] );
			$this->assertNull( $client->lastCall()['body'] );
			$this->assertIsArray( $result );
		}

		public function test_ac3_confirm_order_posts_empty_body_to_confirm_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_7', 'state' => 'CONFIRMED' ] );

			$client->confirmOrder( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/confirm', $call['path'] );
			$this->assertSame( [], $call['body'], 'AC-3: confirmOrder MUSS leeres assoc Array [] schicken (NICHT null).' );
		}

		public function test_ac3_cancel_order_posts_empty_body_to_cancel_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_7', 'state' => 'CANCELLED' ] );

			$client->cancelOrder( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/cancel', $call['path'] );
			$this->assertSame( [], $call['body'], 'AC-3: cancelOrder MUSS leeres assoc Array [] schicken (NICHT null).' );
		}

		// ===================================================================
		// AC-4: Shipping (getShipments / getShippingTypes / setShippingType)
		// ===================================================================

		public function test_ac4_get_shipments_calls_correct_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getShipments( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/orders/ord_7/shipments', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		public function test_ac4_get_shipping_types_returns_shipping_type_dto_list(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					[
						'id'      => 'st_std',
						'company' => 'DPD',
						'name'    => 'Standard',
						'price'   => [ 'amount' => '4.99', 'currency' => 'EUR' ],
					],
					[
						'id'      => 'st_exp',
						'company' => 'DHL',
						'name'    => 'Express',
						'price'   => [ 'amount' => '9.99', 'currency' => 'EUR' ],
					],
				]
			);

			$types = $client->getShippingTypes( 'ord_7' );

			$this->assertSame( 'GET', $client->lastCall()['method'] );
			$this->assertSame( '/orders/ord_7/shippingTypes', $client->lastCall()['path'] );
			$this->assertNull( $client->lastCall()['body'] );

			$this->assertCount( 2, $types );
			$this->assertContainsOnlyInstancesOf( ShippingType::class, $types );
			$this->assertSame( 'st_std', $types[0]->id );
			$this->assertSame( 'st_exp', $types[1]->id );
		}

		public function test_ac4_set_shipping_type_posts_camel_case_body(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_7', 'state' => 'NEW' ] );

			$client->setShippingType( 'ord_7', 'STANDARD' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/shippingType', $call['path'] );
			$this->assertSame(
				[ 'shippingType' => 'STANDARD' ],
				$call['body'],
				'AC-4: setShippingType-Body MUSS exakt {shippingType:STANDARD} sein (camelCase Key).'
			);
		}

		// ===================================================================
		// AC-5: Subscriptions (get / create / delete)
		// ===================================================================

		public function test_ac5_get_subscriptions_returns_subscription_dto_list(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					[
						'id'          => 'sub_1',
						'eventType'   => 'Order.processed',
						'callbackUrl' => 'https://example.test/wh',
					],
				]
			);

			$subs = $client->getSubscriptions();

			$this->assertSame( 'GET', $client->lastCall()['method'] );
			$this->assertSame( '/subscriptions', $client->lastCall()['path'] );
			$this->assertNull( $client->lastCall()['body'] );

			$this->assertCount( 1, $subs );
			$this->assertContainsOnlyInstancesOf( Subscription::class, $subs );
			$this->assertSame( 'sub_1', $subs[0]->id );
		}

		public function test_ac5_create_subscription_posts_event_type_callback_secret(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					'id'          => 'sub_9',
					'eventType'   => 'Order.processed',
					'callbackUrl' => 'https://shop.example/wp-json/spreadconnect/v1/webhook',
				]
			);

			$result = $client->createSubscription(
				'Order.processed',
				'https://shop.example/wp-json/spreadconnect/v1/webhook',
				'sec123'
			);

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/subscriptions', $call['path'] );
			$this->assertSame(
				[
					'eventType'   => 'Order.processed',
					'callbackUrl' => 'https://shop.example/wp-json/spreadconnect/v1/webhook',
					'secret'      => 'sec123',
				],
				$call['body'],
				'AC-5: createSubscription-Body MUSS exakt {eventType,callbackUrl,secret} sein.'
			);

			$this->assertInstanceOf( Subscription::class, $result, 'AC-5: createSubscription MUSS Subscription-Instanz liefern.' );
		}

		public function test_ac5_delete_subscription_calls_delete_path_and_returns_void(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			// PHP: Methoden-Signatur void => kein Returnwert; wir verifizieren
			// per ReflectionMethod, dass der Return-Type tatsaechlich `void` ist.
			$client->deleteSubscription( 'sub_9' );

			$call = $client->lastCall();
			$this->assertSame( 'DELETE', $call['method'] );
			$this->assertSame( '/subscriptions/sub_9', $call['path'] );
			$this->assertNull( $call['body'], 'AC-5: deleteSubscription Body MUSS null sein (DELETE ohne Body).' );

			$reflection = new \ReflectionMethod( SpreadconnectClient::class, 'deleteSubscription' );
			$this->assertTrue( $reflection->hasReturnType() );
			/** @var \ReflectionNamedType $rt */
			$rt = $reflection->getReturnType();
			$this->assertSame( 'void', $rt->getName(), 'AC-5: deleteSubscription Return-Type MUSS void sein.' );
		}

		// ===================================================================
		// AC-6: Simulate-Endpoints (3 Methoden)
		// ===================================================================

		public function test_ac6_simulate_order_cancelled_posts_to_correct_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->simulateOrderCancelled( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/simulate/order-cancelled', $call['path'] );
			$this->assertSame( [], $call['body'], 'AC-6: simulateOrderCancelled MUSS leeren Body [] schicken.' );
		}

		public function test_ac6_simulate_order_processed_posts_to_correct_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->simulateOrderProcessed( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/simulate/order-processed', $call['path'] );
			$this->assertSame( [], $call['body'] );
		}

		public function test_ac6_simulate_shipment_sent_posts_to_correct_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->simulateShipmentSent( 'ord_7' );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/orders/ord_7/simulate/shipment-sent', $call['path'] );
			$this->assertSame( [], $call['body'] );
		}

		// ===================================================================
		// AC-7: ProductTypes (5 Methoden)
		// ===================================================================

		public function test_ac7_get_product_types_calls_index_path(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getProductTypes();

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/productTypes', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		public function test_ac7_get_product_type_calls_path_with_id(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'pt_12' ] );

			$client->getProductType( 'pt_12' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/productTypes/pt_12', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		public function test_ac7_get_product_type_views_calls_views_subpath(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getProductTypeViews( 'pt_12' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/productTypes/pt_12/views', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		public function test_ac7_get_product_type_size_chart_calls_size_chart_subpath(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getProductTypeSizeChart( 'pt_12' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/productTypes/pt_12/size-chart', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		public function test_ac7_get_hotspot_calls_design_subpath(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getHotspot( 'pt_12', 'des_88' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/productTypes/pt_12/hotspots/design/des_88', $call['path'] );
			$this->assertNull( $call['body'] );
		}

		// ===================================================================
		// AC-8: createPreviews (Body-Shape + Returnwert)
		// ===================================================================

		public function test_ac8_create_previews_posts_design_hotspot_views_body(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					[ 'viewId' => 'view_a', 'imageUrl' => 'https://cdn.example/a.png' ],
					[ 'viewId' => 'view_b', 'imageUrl' => 'https://cdn.example/b.png' ],
				]
			);

			$client->createPreviews( 'pt_12', 'des_88', 'hot_3', [ 'view_a', 'view_b' ] );

			$call = $client->lastCall();
			$this->assertSame( 'POST', $call['method'] );
			$this->assertSame( '/productTypes/pt_12/previews', $call['path'] );
			$this->assertSame(
				[
					'designId'  => 'des_88',
					'hotspotId' => 'hot_3',
					'viewIds'   => [ 'view_a', 'view_b' ],
				],
				$call['body'],
				'AC-8: createPreviews-Body MUSS exakt {designId,hotspotId,viewIds} mit camelCase-Keys sein.'
			);
		}

		public function test_ac8_create_previews_returns_preview_dto_list(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					[ 'viewId' => 'view_a', 'imageUrl' => 'https://cdn.example/a.png' ],
					[ 'viewId' => 'view_b', 'imageUrl' => 'https://cdn.example/b.png' ],
				]
			);

			$previews = $client->createPreviews( 'pt_12', 'des_88', 'hot_3', [ 'view_a', 'view_b' ] );

			$this->assertCount( 2, $previews );
			$this->assertContainsOnlyInstancesOf( Preview::class, $previews );
			$this->assertSame( 'view_a', $previews[0]->viewId );
			$this->assertSame( 'https://cdn.example/a.png', $previews[0]->imageUrl );
		}

		// ===================================================================
		// AC-9: Stock (3 Methoden + Filter-Validation)
		// ===================================================================

		public function test_ac9_get_stock_with_product_type_filter_builds_query(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getStock( 'pt_12', null );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame(
				'/stock?productTypeId=pt_12',
				$call['path'],
				'AC-9: getStock(pt_12,null) MUSS exakt /stock?productTypeId=pt_12 anrufen.'
			);
		}

		public function test_ac9_get_stock_with_skus_filter_builds_comma_separated_query(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->getStock( null, [ 'SKU-1', 'SKU-2' ] );

			$call = $client->lastCall();
			$this->assertSame(
				'/stock?skus=SKU-1,SKU-2',
				$call['path'],
				'AC-9: getStock(null,[SKU-1,SKU-2]) MUSS exakt /stock?skus=SKU-1,SKU-2 (komma-separiert, kein []-Suffix).'
			);
		}

		public function test_ac9_get_stock_without_filter_throws_invalid_argument(): void
		{
			$client = new RequestSpyClient();

			try {
				$client->getStock( null, null );
				$this->fail( 'AC-9: getStock(null,null) MUSS InvalidArgumentException werfen.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString(
					'productTypeId or skus required',
					$e->getMessage(),
					'AC-9: Exception-Message MUSS Substring "productTypeId or skus required" enthalten.'
				);
			}

			$this->assertCount(
				0,
				$client->calls,
				'AC-9: Bei filter-loser getStock() darf request() NICHT aufgerufen werden.'
			);
		}

		public function test_ac9_get_stock_by_sku_calls_path_and_returns_dto(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'sku' => 'SKU-1', 'quantity' => 17 ] );

			$result = $client->getStockBySku( 'SKU-1' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/stock/SKU-1', $call['path'] );
			$this->assertNull( $call['body'] );

			$this->assertInstanceOf( StockEntry::class, $result );
			$this->assertSame( 'SKU-1', $result->sku );
			$this->assertSame( 17, $result->quantity );
		}

		public function test_ac9_get_stock_by_product_type_calls_path_and_returns_list(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					[ 'sku' => 'SKU-1', 'quantity' => 5 ],
					[ 'sku' => 'SKU-2', 'quantity' => 0 ],
				]
			);

			$entries = $client->getStockByProductType( 'pt_12' );

			$call = $client->lastCall();
			$this->assertSame( 'GET', $call['method'] );
			$this->assertSame( '/stock/productType/pt_12', $call['path'] );
			$this->assertNull( $call['body'] );

			$this->assertCount( 2, $entries );
			$this->assertContainsOnlyInstancesOf( StockEntry::class, $entries );
		}

		// ===================================================================
		// AC-10: Reservierte Wrapper werfen NotImplementedError vor request()
		// ===================================================================

		public function test_ac10_reserved_wrappers_throw_not_implemented_without_calling_request(): void
		{
			$reserved = [
				'pushArticle'   => static fn ( SpreadconnectClient $c ) => $c->pushArticle(),
				'deleteArticle' => static fn ( SpreadconnectClient $c ) => $c->deleteArticle( 'art_42' ),
				'updateOrder'   => static fn ( SpreadconnectClient $c ) => $c->updateOrder( 'ord_7' ),
				'uploadDesign'  => static fn ( SpreadconnectClient $c ) => $c->uploadDesign(),
			];

			foreach ( $reserved as $name => $invoker ) {
				$client = new RequestSpyClient();

				try {
					$invoker( $client );
					$this->fail( "AC-10: {$name}() MUSS NotImplementedError werfen." );
				} catch ( NotImplementedError $e ) {
					$this->assertInstanceOf(
						\LogicException::class,
						$e,
						"AC-10: {$name}() Exception MUSS \\LogicException erweitern."
					);
					$this->assertStringContainsString(
						'out of MVP scope',
						$e->getMessage(),
						"AC-10: {$name}() Message MUSS Substring 'out of MVP scope' enthalten."
					);
				}

				$this->assertCount(
					0,
					$client->calls,
					"AC-10: {$name}() darf request() NICHT aufrufen — der Throw passiert VORHER."
				);
			}
		}

		public function test_ac10_not_implemented_error_is_not_subclass_of_client_or_transient_error(): void
		{
			$client = new RequestSpyClient();

			try {
				$client->pushArticle();
			} catch ( NotImplementedError $e ) {
				$this->assertNotInstanceOf(
					SpreadconnectClientError::class,
					$e,
					'AC-10: NotImplementedError DARF NICHT SpreadconnectClientError sein '
					. '(Action Scheduler darf den Pfad nicht als 4xx klassifizieren).'
				);
				$this->assertNotInstanceOf(
					SpreadconnectTransientError::class,
					$e,
					'AC-10: NotImplementedError DARF NICHT SpreadconnectTransientError sein '
					. '(Action Scheduler darf den Pfad nicht als 5xx/429 klassifizieren).'
				);
			}
		}

		// ===================================================================
		// AC-11: rawurlencode auf Path-Variablen
		// ===================================================================

		public function test_ac11_path_variables_are_rawurlencoded(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					'id'            => 'art id/42',
					'title'         => 't',
					'productTypeId' => 'pt',
					'variants'      => [],
				]
			);

			$client->getArticle( 'art id/42' );

			$path = $client->lastCall()['path'];
			$this->assertSame(
				'/articles/art%20id%2F42',
				$path,
				'AC-11: getArticle("art id/42") Pfad MUSS rawurlencoded sein -> /articles/art%20id%2F42.'
			);
		}

		public function test_ac11_path_variables_with_hash_and_special_chars(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'sku' => 'x', 'quantity' => 0 ] );

			$client->getStockBySku( 'SKU 1#weird' );

			$path = $client->lastCall()['path'];
			$this->assertSame(
				'/stock/SKU%201%23weird',
				$path,
				'AC-11: getStockBySku-Pfad MUSS Sonderzeichen rawurlencoden (Space -> %20, # -> %23).'
			);
		}

		// ===================================================================
		// AC-12: Exception-Pass-Through (Slice 07/08 Errors propagieren unveraendert)
		// ===================================================================

		public function test_ac12_request_client_error_propagates_unchanged_through_wrappers(): void
		{
			$client = new RequestSpyClient();
			$original = new SpreadconnectClientError( 'http_4xx', 'GET /authentication -> 401', 401, '/authentication' );
			$client->throwOnNext = $original;

			try {
				$client->authenticate();
				$this->fail( 'AC-12: authenticate() MUSS SpreadconnectClientError unveraendert weiterwerfen.' );
			} catch ( Throwable $e ) {
				$this->assertSame(
					$original,
					$e,
					'AC-12: Exception-Instanz MUSS dieselbe sein (kein Re-Wrap, kein Clone).'
				);
				$this->assertInstanceOf( SpreadconnectClientError::class, $e );
				$this->assertSame( 'http_4xx', $e->getAppCode() );
			}
		}

		public function test_ac12_request_transient_error_propagates_unchanged_through_wrappers(): void
		{
			$client   = new RequestSpyClient();
			$original = new SpreadconnectTransientError( 'http_429', 'GET /authentication -> 429', 429, '/authentication' );
			$client->throwOnNext = $original;

			try {
				$client->authenticate();
				$this->fail( 'AC-12: authenticate() MUSS SpreadconnectTransientError unveraendert weiterwerfen.' );
			} catch ( Throwable $e ) {
				$this->assertSame(
					$original,
					$e,
					'AC-12: Exception-Instanz MUSS dieselbe sein (kein Re-Wrap, kein Clone).'
				);
				$this->assertInstanceOf( SpreadconnectTransientError::class, $e );
				$this->assertSame( 'http_429', $e->getAppCode() );
			}
		}

		public function test_ac12_propagates_through_post_wrapper_too(): void
		{
			$client   = new RequestSpyClient();
			$original = new SpreadconnectTransientError( 'http_5xx', 'POST /orders -> 503', 503, '/orders' );
			$client->throwOnNext = $original;

			try {
				$client->createOrder( $this->makeValidOrderCreate() );
				$this->fail( 'AC-12: createOrder() MUSS Transient-Error unveraendert weiterwerfen.' );
			} catch ( Throwable $e ) {
				$this->assertSame( $original, $e );
			}
		}

		// ===================================================================
		// AC-13: Body enthaelt keine Bearer-/API-Key-Leaks
		// ===================================================================

		public function test_ac13_create_order_body_never_contains_api_key_or_bearer_substring(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_1' ] );

			$client->createOrder( $this->makeValidOrderCreate() );

			$bodyJson = json_encode( $client->lastCall()['body'] );
			$this->assertIsString( $bodyJson );
			$this->assertStringNotContainsString( 'TEST_API_KEY_NEVER_USED', $bodyJson, 'AC-13: createOrder Body darf API-Key-Wert NICHT enthalten.' );
			$this->assertStringNotContainsString( 'Bearer', $bodyJson, 'AC-13: createOrder Body darf "Bearer" NICHT enthalten.' );
			$this->assertStringNotContainsString(
				'Authorization',
				$bodyJson,
				'AC-13: createOrder Body darf "Authorization" NICHT enthalten — Auth ist Slice-07-Header-Verantwortung.'
			);
		}

		public function test_ac13_create_subscription_body_never_contains_bearer(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse(
				[
					'id'          => 'sub_1',
					'eventType'   => 'Order.processed',
					'callbackUrl' => 'https://example.test/wh',
				]
			);

			$client->createSubscription( 'Order.processed', 'https://example.test/wh', 'sec123' );

			$bodyJson = json_encode( $client->lastCall()['body'] );
			$this->assertIsString( $bodyJson );
			$this->assertStringNotContainsString( 'TEST_API_KEY_NEVER_USED', $bodyJson );
			$this->assertStringNotContainsString( 'Bearer', $bodyJson );
			$this->assertStringNotContainsString( 'Authorization', $bodyJson );
		}

		public function test_ac13_set_shipping_type_body_never_contains_bearer(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [ 'id' => 'ord_7', 'state' => 'NEW' ] );

			$client->setShippingType( 'ord_7', 'STANDARD' );

			$bodyJson = json_encode( $client->lastCall()['body'] );
			$this->assertIsString( $bodyJson );
			$this->assertStringNotContainsString( 'TEST_API_KEY_NEVER_USED', $bodyJson );
			$this->assertStringNotContainsString( 'Bearer', $bodyJson );
			$this->assertStringNotContainsString( 'Authorization', $bodyJson );
		}

		public function test_ac13_create_previews_body_never_contains_bearer(): void
		{
			$client = new RequestSpyClient();
			$client->pushResponse( [] );

			$client->createPreviews( 'pt_12', 'des_88', 'hot_3', [ 'view_a', 'view_b' ] );

			$bodyJson = json_encode( $client->lastCall()['body'] );
			$this->assertIsString( $bodyJson );
			$this->assertStringNotContainsString( 'TEST_API_KEY_NEVER_USED', $bodyJson );
			$this->assertStringNotContainsString( 'Bearer', $bodyJson );
			$this->assertStringNotContainsString( 'Authorization', $bodyJson );
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		/**
		 * Build a minimal-valid OrderCreate DTO for body-shape assertions.
		 */
		private function makeValidOrderCreate(): OrderCreate
		{
			$address = new Address(
				firstName: 'Anna',
				lastName: 'Schmidt',
				street: 'Hauptstrasse 1',
				zipCode: '10115',
				city: 'Berlin',
				country: 'DE',
			);

			$item = new OrderItem(
				sku: 'SKU-1',
				quantity: 1,
				customerPrice: new Money( amount: '19.99', currency: 'EUR' ),
			);

			return new OrderCreate(
				externalOrderReference: 'wc-7',
				orderItems: [ $item ],
				billingAddress: $address,
				shippingAddress: $address,
			);
		}
	}
}
