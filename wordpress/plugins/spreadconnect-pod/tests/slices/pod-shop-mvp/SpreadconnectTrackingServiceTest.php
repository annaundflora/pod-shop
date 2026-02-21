<?php
/**
 * TrackingService Tests fuer Slice 05: POD-Anbindung (Spreadconnect).
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
use SpreadconnectPod\SpreadconnectTrackingService;

class SpreadconnectTrackingServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * AC-7: GIVEN Spreadconnect sendet einen Webhook an POST /wp-json/spreadconnect/v1/webhook
     *       WHEN der Payload { "wcOrderId": 42, "trackingNumber": "DE123456789", "trackingUrl": "https://..." } enthaelt
     *       THEN werden _spreadconnect_tracking_number und _spreadconnect_tracking_url als Post Meta gesetzt,
     *            der WooCommerce-Bestellstatus wechselt auf "completed".
     *
     * AC-8: GIVEN der WooCommerce-Bestellstatus wird auf "completed" gesetzt
     *       WHEN $order->update_status('completed') aufgerufen wird
     *       THEN versendet WooCommerce automatisch die Standard-Versandbenachrichtigungs-E-Mail
     *            (WooCommerce built-in Verhalten -- hier verifizieren wir, dass update_status('completed') aufgerufen wird).
     */
    public function test_ac7_ac8_apply_tracking_sets_post_meta_and_updates_status_to_completed(): void {
        // Arrange
        $updated_meta   = [];
        $updated_status = null;

        Functions\when( 'get_post_meta' )->justReturn( '' );

        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        Functions\when( 'esc_url' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_html' )->alias( fn( $v ) => $v );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 42 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function ( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );
        Functions\when( 'error_log' )->justReturn( null );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service     = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 42, 'DE123456789', 'https://tracking.example.com/DE123456789' );

        // Assert -- AC-7: Post Meta gesetzt
        $this->assertEquals( 'DE123456789', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/DE123456789', $updated_meta['_spreadconnect_tracking_url'] );
        // Assert -- AC-8: Status auf completed gesetzt (loest WooCommerce E-Mail aus)
        $this->assertEquals( 'completed', $updated_status );
    }

    /**
     * Idempotenz-Test: GIVEN Tracking ist bereits identisch gesetzt
     *                  WHEN apply_tracking() erneut mit gleicher Tracking-Nummer aufgerufen wird
     *                  THEN wird update_status() NICHT erneut aufgerufen.
     */
    public function test_apply_tracking_skips_if_tracking_already_set(): void {
        // Arrange
        Functions\when( 'get_post_meta' )->justReturn( 'DE123456789' );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->expects( $this->never() )->method( 'update_status' );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $service     = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->apply_tracking( 77, 'DE123456789', 'https://tracking.example.com' );

        // Assert -- update_status wurde nicht aufgerufen
        $this->assertTrue( true );
    }

    /**
     * AC-9: GIVEN das Spreadconnect Plugin ist aktiviert
     *       WHEN GET /wp-json/spreadconnect/v1/health aufgerufen wird
     *       THEN antwortet der Endpoint mit HTTP 200 und { "status": "ok", "plugin": "spreadconnect-pod" }.
     *
     * Hinweis: register_rest_route erfordert WordPress-Bootstrap.
     * Dieser Test ist als markTestIncomplete dokumentiert, weil der Health-Endpoint
     * nur in einer laufenden WordPress-Instanz vollstaendig testbar ist.
     * Die Callback-Logik ist jedoch trivial (statische Response).
     */
    public function test_ac9_health_endpoint_returns_ok(): void {
        $this->markTestIncomplete(
            'AC-9: Health Endpoint GET /wp-json/spreadconnect/v1/health -- '
            . 'Test erfordert laufende WordPress-Instanz (register_rest_route). '
            . 'Acceptance: curl http://localhost:8080/wp-json/spreadconnect/v1/health | grep "ok"'
        );
    }

    /**
     * AC-10: GIVEN eine Spreadconnect Order ID ist als _spreadconnect_order_id gespeichert
     *        WHEN SpreadconnectTrackingService::poll_order_tracking() per WP Cron aufgerufen wird
     *        THEN wird GET /orders/{sc_order_id} gegen die Spreadconnect API gesendet,
     *             und falls trackingNumber in der Response vorhanden ist, wird apply_tracking() aufgerufen.
     */
    public function test_ac10_poll_order_tracking_calls_apply_tracking_when_tracking_available(): void {
        // Arrange
        $updated_meta   = [];
        $updated_status = null;

        $mock_client = $this->createMock( SpreadconnectApiClient::class );
        $mock_client->expects( $this->once() )
            ->method( 'get_order' )
            ->with( 'sc-order-abc' )
            ->willReturn( [
                'trackingNumber' => 'TN-456',
                'trackingUrl'    => 'https://tracking.example.com/TN-456',
            ] );

        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_url_raw' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_url' )->alias( fn( $v ) => $v );
        Functions\when( 'esc_html' )->alias( fn( $v ) => $v );
        Functions\when( 'error_log' )->justReturn( null );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[ $key ] = $value;
        } );

        $mock_order = $this->createMock( \WC_Order::class );
        $mock_order->method( 'get_id' )->willReturn( 42 );
        $mock_order->expects( $this->once() )->method( 'add_order_note' );
        $mock_order->expects( $this->once() )
            ->method( 'update_status' )
            ->with( 'completed', $this->anything() )
            ->willReturnCallback( function ( $status ) use ( &$updated_status ) {
                $updated_status = $status;
            } );

        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        $service = new SpreadconnectTrackingService( $mock_client );

        // Act
        $service->poll_order_tracking( 42, 'sc-order-abc' );

        // Assert -- apply_tracking() wurde aufgerufen: Post Meta und Status gesetzt
        $this->assertEquals( 'TN-456', $updated_meta['_spreadconnect_tracking_number'] );
        $this->assertEquals( 'https://tracking.example.com/TN-456', $updated_meta['_spreadconnect_tracking_url'] );
        $this->assertEquals( 'completed', $updated_status );
    }
}
