<?php
/**
 * ApiClient Tests fuer Slice 05: POD-Anbindung (Spreadconnect).
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

class SpreadconnectApiClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * AC-3: GIVEN die Spreadconnect API gibt dreimal in Folge HTTP 500 zurueck
     *       WHEN SpreadconnectApiClient::create_order() aufgerufen wird
     *       THEN werden genau 3 Versuche mit Backoff unternommen, danach wird
     *            WP_Error mit Code spreadconnect_max_retries zurueckgegeben.
     */
    public function test_ac3_returns_wp_error_after_max_retries_on_500(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 500 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Internal Server Error' );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'sleep' )->justReturn( null );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [] ] );

        // Assert -- WP_Error mit Code spreadconnect_max_retries
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_max_retries', $result->get_error_code() );
    }

    /**
     * AC-1 / AC-2 (Voraussetzung): GIVEN die Spreadconnect API antwortet mit HTTP 201
     *       WHEN create_order() aufgerufen wird
     *       THEN werden die dekodierten Response-Daten (inkl. orderId) zurueckgegeben.
     */
    public function test_ac1_returns_data_on_http_201(): void {
        // Arrange
        $mock_response_data = [ 'orderId' => 'sc-abc-123' ];
        Functions\when( 'wp_remote_request' )->justReturn( [ 'response' => [ 'code' => 201 ] ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '59' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $mock_response_data ) );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [
            'items' => [ [ 'articleId' => 'art1', 'sizeId' => 'M', 'quantity' => 1 ] ],
        ] );

        // Assert
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-abc-123', $result['orderId'] );
    }

    /**
     * AC-4: GIVEN die Spreadconnect API gibt HTTP 429 mit Header
     *            X-RateLimit-Retry-After-Seconds: 10 zurueck
     *       WHEN SpreadconnectApiClient::request_with_retry() diesen Status empfaengt
     *       THEN wartet der Client (Wert aus Header) und versucht erneut.
     */
    public function test_ac4_uses_retry_after_header_on_429(): void {
        // Arrange -- 429 beim ersten Versuch, 201 beim zweiten
        $call_count = 0;
        Functions\when( 'wp_remote_request' )->alias( function () use ( &$call_count ) {
            $call_count++;
            return [];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function () use ( &$call_count ) {
            return $call_count === 1 ? 429 : 201;
        } );
        Functions\when( 'wp_remote_retrieve_headers' )->alias( function () use ( &$call_count ) {
            if ( $call_count === 1 ) {
                return [ 'x-ratelimit-retry-after-seconds' => '10' ];
            }
            return [ 'x-ratelimit-remaining' => '50' ];
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function () use ( &$call_count ) {
            return $call_count === 1 ? '' : json_encode( [ 'orderId' => 'sc-retry-ok' ] );
        } );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $sleep_values = [];
        Functions\when( 'sleep' )->alias( function ( $seconds ) use ( &$sleep_values ) {
            $sleep_values[] = $seconds;
        } );
        Functions\when( 'error_log' )->justReturn( null );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [ 'items' => [] ] );

        // Assert -- zweiter Versuch erfolgreich
        $this->assertIsArray( $result );
        $this->assertEquals( 'sc-retry-ok', $result['orderId'] );
        // Assert -- sleep wurde mit dem Wert aus dem Header (10) aufgerufen
        $this->assertContains( 10, $sleep_values );
    }

    /**
     * Zusaetzlicher Robustness-Test: Ungueltige JSON-Antwort bei HTTP 200
     * ergibt WP_Error mit Code spreadconnect_json_error.
     */
    public function test_returns_error_on_non_json_response(): void {
        // Arrange
        Functions\when( 'wp_remote_request' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [ 'x-ratelimit-remaining' => '50' ] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not-valid-json{{' );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $client = new SpreadconnectApiClient( 'test-key', true );

        // Act
        $result = $client->create_order( [] );

        // Assert
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'spreadconnect_json_error', $result->get_error_code() );
    }
}
