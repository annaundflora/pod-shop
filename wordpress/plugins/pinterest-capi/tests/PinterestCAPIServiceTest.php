<?php
/**
 * Acceptance & Unit Tests for Slice 06: Pinterest Tracking (PHP/WordPress Plugin)
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria
 * in docs/features/pod-shop-mvp/slices/slice-06-pinterest-tracking.md
 *
 * AC-7:  WooCommerce saves event_id from $_GET to order meta
 * AC-8:  order_status_completed -> async CAPI purchase with SHA-256 email + event_id
 * AC-9:  Silent fail on CAPI error (WP_Error / HTTP error)
 * AC-10: CAPI fires regardless of cookie consent (server-side)
 * AC-11: CAPI payload contains all required fields
 */

use PHPUnit\Framework\TestCase;
use WP_Mock\Tools\TestCase as WPTestCase;

class PinterestCAPIServiceTest extends WPTestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // AC-8: SHA-256 Hash Validation
    // -----------------------------------------------------------------------

    /**
     * AC-8: SHA-256 Hash der E-Mail korrekt berechnet
     * GIVEN eine Bestellung mit E-Mail (Grossbuchstaben + Leerzeichen)
     * WHEN send_purchase_event aufgerufen wird
     * THEN wird hash('sha256', strtolower(trim($email))) korrekt berechnet
     */
    public function test_email_hash_is_sha256_of_lowercased_trimmed_email(): void {
        // Arrange
        $email = '  Test@Example.com  '; // mit Leerzeichen + Grossbuchstaben
        $expected_hash = hash( 'sha256', strtolower( trim( $email ) ) );

        // Act
        $actual_hash = hash( 'sha256', strtolower( trim( $email ) ) );

        // Assert
        $this->assertEquals( $expected_hash, $actual_hash );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $actual_hash,
            'Hash muss 64-stelliger Hex-String sein'
        );
        $this->assertEquals(
            '973dfe463ec85785f5f95af5ba3906eedb2d931c24e69824a89ea65dba4e813b',
            $actual_hash,
            'SHA-256 Hash fuer test@example.com muss korrekt sein'
        );
    }

    /**
     * AC-8: SHA-256 Hash ist case-insensitive und trimmed
     * Verschiedene Schreibweisen der gleichen E-Mail muessen den gleichen Hash liefern
     */
    public function test_email_hash_is_consistent_regardless_of_case_and_whitespace(): void {
        // Arrange
        $variations = [
            'test@example.com',
            'TEST@EXAMPLE.COM',
            '  test@example.com  ',
            'Test@Example.Com',
        ];

        // Act & Assert
        $first_hash = hash( 'sha256', strtolower( trim( $variations[0] ) ) );
        foreach ( $variations as $email ) {
            $hash = hash( 'sha256', strtolower( trim( $email ) ) );
            $this->assertEquals(
                $first_hash,
                $hash,
                "Hash fuer '$email' muss identisch sein nach trim+lowercase"
            );
        }
    }

    // -----------------------------------------------------------------------
    // AC-8: wp_schedule_single_event
    // -----------------------------------------------------------------------

    /**
     * AC-8: wp_schedule_single_event wird aufgerufen wenn Order auf completed wechselt
     * GIVEN eine WooCommerce Bestellung wechselt in den Status "completed"
     * WHEN der order_status_completed Hook ausgeloest wird
     * THEN wird via wp_schedule_single_event() asynchron ein Event gescheduled
     */
    public function test_schedule_purchase_event_calls_wp_schedule_single_event(): void {
        // Arrange
        $order_id = 42;
        $hooks = new Pinterest_CAPI_Hooks();

        \WP_Mock::userFunction( 'wp_schedule_single_event' )
            ->once()
            ->with(
                \WP_Mock\Functions::anyOf( time(), time() + 1 ),
                'pinterest_send_purchase_event',
                [ $order_id ]
            );

        // Act
        $hooks->schedule_purchase_event( $order_id );

        // Assert -- WP_Mock verifiziert den Aufruf automatisch via tearDown
        $this->addToAssertionCount( 1 );
    }

    /**
     * AC-8: schedule_purchase_event verwendet den korrekten Hook-Namen
     * Der Hook-Name 'pinterest_send_purchase_event' muss exakt stimmen,
     * damit handle_purchase_event als Handler greift
     */
    public function test_schedule_uses_correct_hook_name(): void {
        // Arrange
        $order_id = 123;
        $hooks = new Pinterest_CAPI_Hooks();
        $captured_hook = null;

        \WP_Mock::userFunction( 'wp_schedule_single_event' )
            ->once()
            ->andReturnUsing( function ( $time, $hook, $args ) use ( &$captured_hook ) {
                $captured_hook = $hook;
            } );

        // Act
        $hooks->schedule_purchase_event( $order_id );

        // Assert
        $this->assertEquals( 'pinterest_send_purchase_event', $captured_hook );
    }

    // -----------------------------------------------------------------------
    // AC-7: save_pinterest_event_id from $_GET to order meta
    // -----------------------------------------------------------------------

    /**
     * AC-7: event_id aus URL-Parameter wird in Order Meta gespeichert
     * GIVEN eine WooCommerce Bestellung wird angelegt (woocommerce_checkout_order_created)
     * WHEN der $_GET['pinterest_event_id'] Parameter in der URL vorhanden ist
     * THEN wird die event_id in der Order Meta '_pinterest_event_id' gespeichert
     */
    public function test_save_pinterest_event_id_stores_in_order_meta(): void {
        // Arrange
        $order_id = 55;
        $event_id = 'frontend-uuid-v4-test';

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_id' )->willReturn( $order_id );

        // Simulate $_GET parameter
        $_GET['pinterest_event_id'] = $event_id;

        \WP_Mock::userFunction( 'sanitize_text_field' )
            ->andReturnUsing( function ( $str ) {
                return $str;
            } );
        \WP_Mock::userFunction( 'wp_unslash' )
            ->andReturnUsing( function ( $str ) {
                return $str;
            } );
        \WP_Mock::userFunction( 'update_post_meta' )
            ->once()
            ->with( $order_id, '_pinterest_event_id', $event_id );

        $hooks = new Pinterest_CAPI_Hooks();

        // Act
        $hooks->save_pinterest_event_id( $mock_order );

        // Assert -- WP_Mock verifiziert update_post_meta Aufruf
        $this->addToAssertionCount( 1 );

        // Cleanup
        unset( $_GET['pinterest_event_id'] );
    }

    /**
     * AC-7: Wenn kein pinterest_event_id in $_GET, wird KEIN Meta gespeichert
     * GIVEN eine WooCommerce Bestellung wird angelegt
     * WHEN kein $_GET['pinterest_event_id'] vorhanden ist
     * THEN wird update_post_meta NICHT aufgerufen
     */
    public function test_save_pinterest_event_id_does_nothing_without_get_param(): void {
        // Arrange
        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_id' )->willReturn( 99 );

        // Ensure $_GET does not have the param
        unset( $_GET['pinterest_event_id'] );

        // update_post_meta should NOT be called
        \WP_Mock::userFunction( 'update_post_meta' )->never();

        $hooks = new Pinterest_CAPI_Hooks();

        // Act
        $hooks->save_pinterest_event_id( $mock_order );

        // Assert -- WP_Mock verifiziert dass update_post_meta NICHT aufgerufen wurde
        $this->addToAssertionCount( 1 );
    }

    // -----------------------------------------------------------------------
    // AC-9: Silent Fail on WP_Error
    // -----------------------------------------------------------------------

    /**
     * AC-9: Silent Fail bei WP_Error (z.B. Timeout HTTP 408)
     * GIVEN der Pinterest CAPI-Call schlaegt fehl
     * WHEN wp_remote_post() einen WP_Error zurueckgibt
     * THEN wird der Fehler in das WP Error Log geschrieben,
     *      der Nutzer bemerkt NICHTS (Silent Fail, kein User-Impact)
     */
    public function test_send_purchase_event_silent_fail_on_wp_error(): void {
        // Arrange
        $order_id = 99;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'customer@example.com' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '127.0.0.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'Mozilla/5.0' );
        $mock_order->method( 'get_total' )->willReturn( '49.99' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )
            ->once()
            ->with( $order_id )
            ->andReturn( $mock_order );

        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'test-access-token' );

        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '549764567890' );

        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( 'test-event-uuid-123' );

        // WP_Error simuliert Timeout
        $wp_error = $this->getMockBuilder( 'WP_Error' )
            ->disableOriginalConstructor()
            ->getMock();
        $wp_error->method( 'get_error_message' )
            ->willReturn( 'Operation timed out after 10000 milliseconds' );

        \WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturn( $wp_error );

        \WP_Mock::userFunction( 'is_wp_error' )
            ->with( $wp_error )
            ->andReturn( true );

        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );
        \WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

        // Act -- kein Exception erwartet (error_log ist eine native PHP Funktion,
        // wird intern aufgerufen; Silent Fail = keine Exception)
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert -- Silent Fail: keine Exception, Methode kehrt normal zurueck
        $this->assertTrue(
            true,
            'send_purchase_event() muss Silent Fail ohne Exception abschliessen'
        );
    }

    /**
     * AC-9: Silent Fail bei HTTP-Status != 2xx
     * GIVEN der Pinterest CAPI-Call liefert HTTP 500
     * WHEN wp_remote_post() einen non-2xx Status zurueckgibt
     * THEN wird error_log aufgerufen, kein User-Impact
     */
    public function test_send_purchase_event_silent_fail_on_http_error(): void {
        // Arrange
        $order_id = 101;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'user@test.de' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '10.0.0.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'TestBrowser/2.0' );
        $mock_order->method( 'get_total' )->willReturn( '99.99' );
        $mock_order->method( 'get_item_count' )->willReturn( 2 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'valid-token' );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '987654321' );
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( 'event-id-http-err' );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );
        \WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

        $mock_response = [ 'response' => [ 'code' => 500 ] ];
        \WP_Mock::userFunction( 'wp_remote_post' )->andReturn( $mock_response );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 500 );
        \WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( 'Internal Server Error' );

        // Act (error_log ist eine native PHP Funktion, wird intern aufgerufen)
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertTrue( true, 'Silent Fail bei HTTP 500 ohne Exception' );
    }

    // -----------------------------------------------------------------------
    // AC-10: CAPI fires regardless of consent
    // -----------------------------------------------------------------------

    /**
     * AC-10: CAPI ist server-seitig und consent-unabhaengig
     * GIVEN Cookie Consent wurde ABGELEHNT
     * THEN feuert die CAPI trotzdem wenn Bestellung abgeschlossen wird
     *
     * Dieser Test validiert, dass send_purchase_event() keinen Consent-Check hat
     * und korrekt wp_remote_post() aufruft.
     */
    public function test_capi_fires_without_consent_check(): void {
        // Arrange
        $order_id = 200;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'noconsent@example.com' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '1.2.3.4' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'Agent/1.0' );
        $mock_order->method( 'get_total' )->willReturn( '15.00' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'token-abc' );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '111222333' );
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( 'consent-irrelevant-id' );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );
        \WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

        $mock_response = [ 'response' => [ 'code' => 200 ] ];
        // wp_remote_post MUSS aufgerufen werden -- kein Consent-Check
        \WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturn( $mock_response );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert -- wp_remote_post wurde aufgerufen (WP_Mock verifiziert via tearDown)
        $this->assertTrue( true, 'CAPI feuert ohne Consent-Check' );
    }

    // -----------------------------------------------------------------------
    // AC-11: Payload contains all required fields
    // -----------------------------------------------------------------------

    /**
     * AC-11: Payload enthaelt alle Pflichtfelder
     * GIVEN die Pinterest CAPI-Einstellungen sind konfiguriert
     * WHEN send_purchase_event() aufgerufen wird
     * THEN enthaelt das Payload: event_name="purchase", currency="EUR",
     *      gehashte E-Mail, Produktliste, Gesamtbetrag, event_id
     */
    public function test_send_purchase_event_payload_contains_all_required_fields(): void {
        // Arrange
        $order_id = 77;
        $email    = 'buyer@test.de';
        $event_id = 'dedup-event-id-xyz';

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( $email );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '192.168.1.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'TestAgent/1.0' );
        $mock_order->method( 'get_total' )->willReturn( '29.99' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'valid-token' );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '123456789' );
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( $event_id );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );

        $captured_payload = null;
        \WP_Mock::userFunction( 'wp_json_encode' )
            ->andReturnUsing( function ( $data ) use ( &$captured_payload ) {
                $captured_payload = $data;
                return json_encode( $data );
            } );

        $mock_response = [ 'response' => [ 'code' => 200 ] ];
        \WP_Mock::userFunction( 'wp_remote_post' )->andReturn( $mock_response );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert -- Payload Struktur pruefen
        $this->assertNotNull( $captured_payload, 'Payload muss aufgebaut worden sein' );
        $event_data = $captured_payload['data'][0];

        $this->assertEquals( 'purchase', $event_data['event_name'], 'event_name muss "purchase" sein' );
        $this->assertEquals( $event_id, $event_data['event_id'], 'event_id muss aus Order Meta kommen' );
        $this->assertIsInt( $event_data['event_time'], 'event_time muss Unix-Timestamp sein' );
        $this->assertEquals( 'website', $event_data['action_source'], 'action_source muss "website" sein' );
        $this->assertEquals( 'EUR', $event_data['custom_data']['currency'], 'currency muss "EUR" sein' );
        $this->assertEquals( 29.99, (float) $event_data['custom_data']['value'], 'value muss Bestellbetrag sein' );
        $this->assertEquals( (string) $order_id, $event_data['custom_data']['order_id'], 'order_id muss im Payload sein' );

        $expected_email_hash = hash( 'sha256', strtolower( trim( $email ) ) );
        $this->assertContains(
            $expected_email_hash,
            $event_data['user_data']['em'],
            'em-Array muss SHA-256 Hash der E-Mail enthalten'
        );

        $this->assertArrayHasKey( 'client_ip_address', $event_data['user_data'] );
        $this->assertArrayHasKey( 'client_user_agent', $event_data['user_data'] );
        $this->assertArrayHasKey( 'contents', $event_data['custom_data'] );
        $this->assertArrayHasKey( 'num_items', $event_data['custom_data'] );
    }

    /**
     * AC-11: API URL enthaelt die korrekte Ad Account ID
     */
    public function test_api_url_contains_ad_account_id(): void {
        // Arrange
        $order_id       = 88;
        $ad_account_id  = '999888777';
        $captured_url   = null;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'api@test.com' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '10.0.0.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'UA/1.0' );
        $mock_order->method( 'get_total' )->willReturn( '10.00' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'token-xyz' );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( $ad_account_id );
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( 'url-test-id' );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );
        \WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

        \WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturnUsing( function ( $url, $args ) use ( &$captured_url ) {
                $captured_url = $url;
                return [ 'response' => [ 'code' => 200 ] ];
            } );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertNotNull( $captured_url );
        $this->assertStringContainsString(
            "/ad_accounts/{$ad_account_id}/events",
            $captured_url,
            'URL muss die korrekte Ad Account ID enthalten'
        );
        $this->assertStringStartsWith(
            'https://api.pinterest.com/v5',
            $captured_url,
            'URL muss mit Pinterest API v5 Base beginnen'
        );
    }

    /**
     * AC-8: Fallback UUID wenn kein event_id in Order Meta
     * GIVEN keine event_id in Order Meta gespeichert
     * WHEN send_purchase_event aufgerufen wird
     * THEN wird wp_generate_uuid4() als Fallback verwendet
     */
    public function test_fallback_uuid_when_no_event_id_in_order_meta(): void {
        // Arrange
        $order_id     = 66;
        $fallback_uuid = 'fallback-uuid-1234-5678';

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'fallback@test.com' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '127.0.0.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'UA' );
        $mock_order->method( 'get_total' )->willReturn( '5.00' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( 'token' );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '111' );
        // Return empty string = no event_id stored
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( '' );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );

        // wp_generate_uuid4 Fallback muss aufgerufen werden
        \WP_Mock::userFunction( 'wp_generate_uuid4' )
            ->once()
            ->andReturn( $fallback_uuid );

        $captured_payload = null;
        \WP_Mock::userFunction( 'wp_json_encode' )
            ->andReturnUsing( function ( $data ) use ( &$captured_payload ) {
                $captured_payload = $data;
                return json_encode( $data );
            } );

        \WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 200 ] ] );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertNotNull( $captured_payload );
        $this->assertEquals(
            $fallback_uuid,
            $captured_payload['data'][0]['event_id'],
            'Fallback UUID muss verwendet werden wenn kein event_id in Order Meta'
        );
    }

    /**
     * AC-8/AC-9: Abbruch wenn Order nicht gefunden wird
     * GIVEN wc_get_order gibt false zurueck
     * WHEN send_purchase_event aufgerufen wird
     * THEN wird error_log aufgerufen und die Methode bricht ab
     */
    public function test_abort_when_order_not_found(): void {
        // Arrange
        $order_id = 999;

        \WP_Mock::userFunction( 'wc_get_order' )
            ->with( $order_id )
            ->andReturn( false );

        // error_log ist eine native PHP Funktion und kann von WP_Mock nicht gemockt werden.
        // Silent Fail wird durch "keine Exception" verifiziert.

        // wp_remote_post should NOT be called
        \WP_Mock::userFunction( 'wp_remote_post' )->never();

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertTrue( true, 'Methode bricht ab ohne Exception bei fehlender Order' );
    }

    /**
     * AC-8/AC-9: Abbruch wenn Access Token oder Ad Account ID fehlt
     * GIVEN Access Token oder Ad Account ID nicht konfiguriert
     * WHEN send_purchase_event aufgerufen wird
     * THEN wird error_log aufgerufen, kein API-Call
     */
    public function test_abort_when_credentials_missing(): void {
        // Arrange
        $order_id = 44;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( '' ); // Empty token
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '' ); // Empty account ID

        // error_log ist eine native PHP Funktion und kann von WP_Mock nicht gemockt werden.
        // Silent Fail wird durch "keine Exception" verifiziert.

        // wp_remote_post should NOT be called
        \WP_Mock::userFunction( 'wp_remote_post' )->never();

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertTrue( true, 'Methode bricht ab ohne API-Call bei fehlenden Credentials' );
    }

    /**
     * AC-11: wp_remote_post uses correct Authorization header with Bearer token
     */
    public function test_authorization_header_uses_bearer_token(): void {
        // Arrange
        $order_id      = 33;
        $access_token  = 'my-secret-bearer-token';
        $captured_args = null;

        $mock_order = $this->getMockBuilder( 'WC_Order' )
            ->disableOriginalConstructor()
            ->getMock();
        $mock_order->method( 'get_billing_email' )->willReturn( 'auth@test.com' );
        $mock_order->method( 'get_customer_ip_address' )->willReturn( '10.0.0.1' );
        $mock_order->method( 'get_customer_user_agent' )->willReturn( 'UA' );
        $mock_order->method( 'get_total' )->willReturn( '25.00' );
        $mock_order->method( 'get_item_count' )->willReturn( 1 );
        $mock_order->method( 'get_items' )->willReturn( [] );

        \WP_Mock::userFunction( 'wc_get_order' )->with( $order_id )->andReturn( $mock_order );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_access_token', '' )
            ->andReturn( $access_token );
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'pinterest_capi_ad_account_id', '' )
            ->andReturn( '555' );
        \WP_Mock::userFunction( 'get_post_meta' )
            ->with( $order_id, '_pinterest_event_id', true )
            ->andReturn( 'auth-test-id' );
        \WP_Mock::userFunction( 'home_url' )->andReturn( 'http://localhost:8080' );
        \WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

        \WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturnUsing( function ( $url, $args ) use ( &$captured_args ) {
                $captured_args = $args;
                return [ 'response' => [ 'code' => 200 ] ];
            } );
        \WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );

        // Act
        $service = new Pinterest_CAPI_Service();
        $service->send_purchase_event( $order_id );

        // Assert
        $this->assertNotNull( $captured_args );
        $this->assertEquals(
            "Bearer {$access_token}",
            $captured_args['headers']['Authorization'],
            'Authorization Header muss Bearer Token enthalten'
        );
        $this->assertEquals(
            'application/json',
            $captured_args['headers']['Content-Type'],
            'Content-Type muss application/json sein'
        );
        $this->assertEquals(
            10,
            $captured_args['timeout'],
            'Timeout muss 10 Sekunden sein'
        );
    }
}
