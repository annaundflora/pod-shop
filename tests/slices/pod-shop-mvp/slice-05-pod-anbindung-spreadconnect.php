<?php
// tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SpreadconnectPod\SpreadconnectApiClient;
use SpreadconnectPod\SpreadconnectOrderService;
use SpreadconnectPod\SpreadconnectTrackingService;

class SpreadconnectApiClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_wp_error_after_max_retries_on_500(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 500 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Internal Server Error' );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act – 3 Versuche werden unternommen, dann WP_Error (sleep wird in Tests gemockt)
        // Hinweis: sleep() im Test via Monkey\Functions mocken
        Functions\when( 'sleep' )->justReturn( null );

        $result = $client->create_order( [ 'items' => [] ] );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_max_retries', $result->get_error_code() );
    }

    public function test_returns_data_on_http_201(): void {
        // Arrange
        $mock_response_data = [ 'orderId' => 'sc-abc-123' ];
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 201 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '59' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $mock_response_data ) );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [ [ 'articleId' => 'art1', 'sizeId' => 'M', 'quantity' => 1 ] ] ] );

        // Assert
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-abc-123', $result['orderId'] );
    }

    public function test_uses_retry_after_header_on_429(): void {
        // Arrange – 429 beim ersten Versuch, 201 beim zweiten
        $call_count = 0;
        Functions\when( 'wp_remote_request' )->alias( function() use ( &$call_count ) {
            $call_count++;
            return [];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function() use ( &$call_count ) {
            return $call_count === 1 ? 429 : 201;
        } );
        Functions\when( 'wp_remote_retrieve_headers' )->alias( function() use ( &$call_count ) {
            if ( $call_count === 1 ) {
                return [ 'x-ratelimit-retry-after-seconds' => '5' ];
            }
            return [ 'x-ratelimit-remaining' => '50' ];
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function() use ( &$call_count ) {
            return $call_count === 1 ? '' : json_encode( [ 'orderId' => 'sc-retry-ok' ] );
        } );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'sleep' )->justReturn( null );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [] ] );

        // Assert – zweiter Versuch erfolgreich
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-retry-ok', $result['orderId'] );
    }

    public function test_returns_error_on_non_json_response(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '50' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not-valid-json{{' );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [] );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_json_error', $result->get_error_code() );
    }
}

class SpreadconnectOrderServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_order_items_returns_error_when_article_id_missing(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( '' ); // Keine article_id
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 42 );
        $mock_item->method( 'get_variation_id' )->willReturn( 0 );
        $mock_item->method( 'get_quantity' )->willReturn( 1 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_missing_article_id', $result->get_error_code() );
    }

    public function test_build_order_items_returns_dto_with_correct_fields(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single ) {
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );
        Functions\when( 'wc_get_product' )->alias( function( $id ) {
            $mock_variation = \Mockery::mock( \WC_Product_Variation::class );
            $mock_variation->shouldReceive( 'get_attribute' )
                ->with( 'pa_size' )->andReturn( 'L' );
            return $mock_variation;
        } );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 10 );
        $mock_item->method( 'get_variation_id' )->willReturn( 20 );
        $mock_item->method( 'get_quantity' )->willReturn( 2 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert
        $this->assertIsArray( $result );
        $this->assertCount( 1, $result );
        $this->assertEquals( 'art-shirt-001', $result[0]['articleId'] );
        $this->assertEquals( 'L', $result[0]['sizeId'] );
        $this->assertEquals( 2, $result[0]['quantity'] );
    }

    public function test_notify_admin_on_failure_sends_email_with_correct_subject(): void {
        // Arrange
        Functions\when( 'get_option' )->alias( function( $key ) {
            return $key === 'admin_email' ? 'admin@test.de' : '';
        } );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/post.php?post=99&action=edit' );

        $sent_to      = null;
        $sent_subject = null;
        Functions\when( 'wp_mail' )->alias( function( $to, $subject, $message ) use ( &$sent_to, &$sent_subject ) {
            $sent_to      = $to;
            $sent_subject = $subject;
        } );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->notify_admin_on_failure( 99, 'Netzwerkfehler nach 3 Versuchen.' );

        // Assert
        $this->assertEquals( 'admin@test.de', $sent_to );
        $this->assertStringContainsString( 'Spreadconnect-Fehler', $sent_subject );
        $this->assertStringContainsString( '99', $sent_subject );
    }

    public function test_handle_order_processing_skips_if_already_forwarded(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( 'existing-sc-order-id' ); // Bereits weitergeleitet
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->never() )->method( 'create_order' );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 55 );

        // Assert – create_order wurde nicht aufgerufen (never-Expectation oben)
        $this->assertTrue( true ); // Kein Fehler = Test bestanden
    }

    public function test_handle_order_processing_stores_sc_order_id_on_success(): void {
        // Arrange – AC-2: Erfolgs-Pfad: orderId wird als Post Meta gespeichert
        $updated_meta = [];
        $order_notes  = [];

        Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single ) {
            // Noch nicht weitergeleitet (kein bestehender SC Order ID)
            if ( $key === '_spreadconnect_order_id' ) {
                return '';
            }
            // _spreadconnect_article_id auf Produkt gesetzt
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );
        Functions\when( 'error_log' )->justReturn( null );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/' );
        Functions\when( 'wc_get_product' )->alias( function( $id ) {
            $mock_variation = \Mockery::mock( \WC_Product_Variation::class );
            $mock_variation->shouldReceive( 'get_attribute' )
                ->with( 'pa_size' )->andReturn( 'M' );
            return $mock_variation;
        } );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 100 );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 10 );
        $mock_item->method( 'get_variation_id' )->willReturn( 20 );
        $mock_item->method( 'get_quantity' )->willReturn( 1 );

        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );
        $mock_order->method( 'get_shipping_first_name' )->willReturn( 'Max' );
        $mock_order->method( 'get_shipping_last_name' )->willReturn( 'Mustermann' );
        $mock_order->method( 'get_shipping_address_1' )->willReturn( 'Musterstr. 1' );
        $mock_order->method( 'get_shipping_address_2' )->willReturn( '' );
        $mock_order->method( 'get_shipping_city' )->willReturn( 'Berlin' );
        $mock_order->method( 'get_shipping_postcode' )->willReturn( '10115' );
        $mock_order->method( 'get_shipping_country' )->willReturn( 'DE' );
        $mock_order->method( 'get_billing_email' )->willReturn( 'max@test.de' );
        $mock_order->method( 'get_billing_phone' )->willReturn( '' );
        $mock_order->method( 'get_billing_first_name' )->willReturn( 'Max' );
        $mock_order->method( 'get_billing_last_name' )->willReturn( 'Mustermann' );
        $mock_order->method( 'get_billing_address_1' )->willReturn( 'Musterstr. 1' );
        $mock_order->method( 'get_billing_address_2' )->willReturn( '' );
        $mock_order->method( 'get_billing_city' )->willReturn( 'Berlin' );
        $mock_order->method( 'get_billing_postcode' )->willReturn( '10115' );
        $mock_order->method( 'get_billing_country' )->willReturn( 'DE' );
        $mock_order->expects( $this->once() )
            ->method( 'add_order_note' )
            ->willReturnCallback( function( $note ) use ( &$order_notes ) {
                $order_notes[] = $note;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'create_order' )
            ->willReturn( [ 'orderId' => 'sc-123' ] );
        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 100 );

        // Assert – AC-2: _spreadconnect_order_id wurde mit 'sc-123' gespeichert
        $this->assertEquals( 'sc-123', $updated_meta['_spreadconnect_order_id'] );
        // Assert – AC-2: Order Note enthaelt 'Spreadconnect Order erstellt: sc-123'
        $this->assertCount( 1, $order_notes );
        $this->assertStringContainsString( 'Spreadconnect Order erstellt: sc-123', $order_notes[0] );
    }
}

class SpreadconnectTrackingServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_apply_tracking_sets_post_meta_and_updates_status(): void {
        // Arrange
        $updated_meta   = [];
        $updated_status = null;

        Functions\when( 'get_post_meta' )->justReturn( '' ); // Kein vorhandenes Tracking

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'esc_url' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 77 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 77, 'DE123456789', 'https://tracking.example.com/DE123456789' );

        // Assert
        $this->assertEquals( 'DE123456789', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/DE123456789', $updated_meta['_spreadconnect_tracking_url'] );
        $this->assertEquals( 'completed', $updated_status );
    }

    public function test_apply_tracking_skips_if_tracking_already_set(): void {
        // Arrange – Tracking ist bereits identisch gesetzt
        Functions\when( 'get_post_meta' )->justReturn( 'DE123456789' ); // Gleiche Tracking-Nummer

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->expects( $this->never() )->method( 'update_status' );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 77, 'DE123456789', 'https://tracking.example.com' );

        // Assert – update_status wurde nicht aufgerufen (never-Expectation)
        $this->assertTrue( true );
    }

    public function test_health_endpoint_returns_ok(): void {
        $this->markTestIncomplete(
            'Health Endpoint: GET /wp-json/spreadconnect/v1/health -- Test gegen laufende WordPress-Instanz noetig (register_rest_route erfordert WordPress-Bootstrap).'
        );
    }

    public function test_poll_order_tracking_calls_apply_tracking_when_tracking_available(): void {
        // Arrange – AC-10: poll_order_tracking() ruft get_order() auf und delegiert an apply_tracking()
        $updated_meta   = [];
        $updated_status = null;

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'get_order' )
            ->with( 'sc-order-abc' )
            ->willReturn( [ 'trackingNumber' => 'TN-456', 'trackingUrl' => 'https://tracking.example.com/TN-456' ] );

        Functions\when( 'is_wp_error' )->alias( fn($v) => $v instanceof \WP_Error );
        Functions\when( 'sanitize_text_field' )->alias( fn($v) => $v );
        Functions\when( 'esc_url_raw' )->alias( fn($v) => $v );
        Functions\when( 'esc_url' )->alias( fn($v) => $v );
        Functions\when( 'esc_html' )->alias( fn($v) => $v );
        Functions\when( 'error_log' )->justReturn( null );

        // Kein vorhandenes Tracking (apply_tracking Idempotenz-Check passiert)
        Functions\when( 'get_post_meta' )->justReturn( '' );

        Functions\when( 'update_post_meta' )->alias( function( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 42 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->poll_order_tracking( 42, 'sc-order-abc' );

        // Assert – apply_tracking() wurde aufgerufen: Post Meta fuer Tracking wurde gesetzt
        $this->assertEquals( 'TN-456', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/TN-456', $updated_meta['_spreadconnect_tracking_url'] );
        $this->assertEquals( 'completed', $updated_status );
    }
}
