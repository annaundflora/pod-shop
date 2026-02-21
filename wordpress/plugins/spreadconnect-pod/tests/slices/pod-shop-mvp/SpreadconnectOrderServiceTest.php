<?php
/**
 * OrderService Tests fuer Slice 05: POD-Anbindung (Spreadconnect).
 *
 * Abgeleitet aus GIVEN/WHEN/THEN Acceptance Criteria in der Slice-Spec.
 * Mocking-Strategie: mock_external -- Spreadconnect API wird via Brain\Monkey
 * und PHPUnit Mocks ersetzt. KEINE echten API-Calls.
 *
 * Spec: docs/features/pod-shop-mvp/slices/slice-05-pod-anbindung-spreadconnect.md
 *
 * @package SpreadconnectPod\Tests
 */

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SpreadconnectPod\SpreadconnectApiClient;
use SpreadconnectPod\SpreadconnectOrderService;

class SpreadconnectOrderServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * AC-5: GIVEN ein Produkt hat KEINE _spreadconnect_article_id gesetzt
     *       WHEN eine Bestellung mit diesem Produkt eingereicht wird
     *       THEN wird die Bestellung NICHT weitergeleitet (WP_Error spreadconnect_missing_article_id).
     */
    public function test_ac5_build_order_items_returns_error_when_article_id_missing(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 42 );
        $mock_item->method( 'get_variation_id' )->willReturn( 0 );
        $mock_item->method( 'get_quantity' )->willReturn( 1 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service     = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_missing_article_id', $result->get_error_code() );
    }

    /**
     * AC-1: GIVEN ein WooCommerce-Produkt hat _spreadconnect_article_id als Custom Meta gesetzt
     *       WHEN eine neue Bestellung mit diesem Produkt den Status "processing" erhaelt
     *       THEN sendet SpreadconnectOrderService einen POST-Request an Spreadconnect
     *            mit korrekten SpreadconnectOrderItem-DTOs (articleId, sizeId, quantity).
     */
    public function test_ac1_build_order_items_returns_dto_with_correct_fields(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $mock_variation = $this->createStub( \WC_Product_Variation::class );
        $mock_variation->method( 'get_attribute' )->willReturnCallback( function ( $attr ) {
            return $attr === 'pa_size' ? 'L' : '';
        } );
        Functions\when( 'wc_get_product' )->justReturn( $mock_variation );

        $mock_item = $this->createMock( \WC_Order_Item_Product::class );
        $mock_item->method( 'get_product_id' )->willReturn( 10 );
        $mock_item->method( 'get_variation_id' )->willReturn( 20 );
        $mock_item->method( 'get_quantity' )->willReturn( 2 );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_items' )->willReturn( [ $mock_item ] );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service     = new SpreadconnectOrderService( $mock_client );

        // Act
        $result = $service->build_order_items( $mock_order );

        // Assert -- DTO enthaelt articleId, sizeId, quantity
        $this->assertIsArray( $result );
        $this->assertCount( 1, $result );
        $this->assertEquals( 'art-shirt-001', $result[0]['articleId'] );
        $this->assertEquals( 'L', $result[0]['sizeId'] );
        $this->assertEquals( 2, $result[0]['quantity'] );
    }

    /**
     * AC-6: GIVEN die Bestellweiterleitung nach 3 Retries fehlschlaegt
     *       WHEN notify_admin_on_failure() aufgerufen wird
     *       THEN erhaelt die Admin-E-Mail-Adresse eine E-Mail mit Subject
     *            "[POD Shop] Spreadconnect-Fehler: Bestellung #X".
     */
    public function test_ac6_notify_admin_on_failure_sends_email_with_correct_subject(): void {
        // Arrange
        Functions\when( 'get_option' )->alias( function ( $key ) {
            return $key === 'admin_email' ? 'admin@test.de' : '';
        } );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/post.php?post=99&action=edit' );

        $sent_to      = null;
        $sent_subject = null;
        $sent_message = null;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $message ) use ( &$sent_to, &$sent_subject, &$sent_message ) {
            $sent_to      = $to;
            $sent_subject = $subject;
            $sent_message = $message;
        } );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service     = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->notify_admin_on_failure( 99, 'Netzwerkfehler nach 3 Versuchen.' );

        // Assert
        $this->assertEquals( 'admin@test.de', $sent_to );
        $this->assertEquals( '[POD Shop] Spreadconnect-Fehler: Bestellung #99', $sent_subject );
        $this->assertStringContainsString( 'Netzwerkfehler nach 3 Versuchen.', $sent_message );
    }

    /**
     * Idempotenz-Test: GIVEN eine Bestellung hat bereits eine _spreadconnect_order_id
     *                  WHEN handle_order_processing() erneut aufgerufen wird
     *                  THEN wird create_order() NICHT aufgerufen (doppelte Weiterleitung verhindert).
     */
    public function test_handle_order_processing_skips_if_already_forwarded(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( 'existing-sc-order-id' );
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->never() )->method( 'create_order' );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 55 );

        // Assert -- create_order wurde nicht aufgerufen (never-Expectation)
        $this->assertTrue( true );
    }

    /**
     * AC-2: GIVEN die Spreadconnect API antwortet mit HTTP 200 und { "orderId": "sc-123" }
     *       WHEN die Bestellung weitergeleitet wurde
     *       THEN ist get_post_meta($order_id, '_spreadconnect_order_id', true) gleich "sc-123"
     *            und die WooCommerce-Bestellnotiz enthaelt "Spreadconnect Order erstellt: sc-123".
     */
    public function test_ac2_handle_order_processing_stores_sc_order_id_on_success(): void {
        // Arrange
        $updated_meta = [];
        $order_notes  = [];

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_spreadconnect_order_id' ) {
                return '';
            }
            if ( $key === '_spreadconnect_article_id' ) {
                return 'art-shirt-001';
            }
            return '';
        } );

        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_html' )->alias( fn( $v ) => $v );
        Functions\when( 'error_log' )->justReturn( null );
        Functions\when( 'admin_url' )->justReturn( 'http://localhost:8080/wp-admin/' );
        $mock_variation = $this->createStub( \WC_Product_Variation::class );
        $mock_variation->method( 'get_attribute' )->willReturnCallback( function ( $attr ) {
            return $attr === 'pa_size' ? 'M' : '';
        } );
        Functions\when( 'wc_get_product' )->justReturn( $mock_variation );

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
            ->willReturnCallback( function ( $note ) use ( &$order_notes ) {
                $order_notes[] = $note;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'create_order' )
            ->willReturn( [ 'orderId' => 'sc-123' ] );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

        $service = new SpreadconnectOrderService( $mock_client );

        // Act
        $service->handle_order_processing( 100 );

        // Assert -- AC-2: _spreadconnect_order_id wurde mit 'sc-123' gespeichert
        $this->assertEquals( 'sc-123', $updated_meta['_spreadconnect_order_id'] );
        // Assert -- AC-2: Order Note enthaelt 'Spreadconnect Order erstellt: sc-123'
        $this->assertCount( 1, $order_notes );
        $this->assertStringContainsString( 'Spreadconnect Order erstellt: sc-123', $order_notes[0] );
    }
}
