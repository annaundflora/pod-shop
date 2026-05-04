<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// Slice 18 testet `Subscription\SubscriptionManager` — den Webhook-Subscription-
// Lifecycle-Service. Mocking-Strategy laut Slice-Spec Z. 28: `mock_external`.
//
//   - `SpreadconnectClient` ist NICHT final -> klassischer `Mockery::mock()`.
//   - `SubscriptionManager` exponiert `protected static makeClient()` als
//     einzige Test-Seam. Eine Test-Subklasse (`StubbedSubscriptionManager`)
//     erbt davon und ueberschreibt `makeClient()` so dass der Mockery-Mock
//     injiziert wird.
//   - WP-/Action-Scheduler-Funktionen (`get_option`, `update_option`,
//     `home_url`, `do_action`, `add_action`, `as_schedule_recurring_action`,
//     `as_next_scheduled_action`, `__`, `is_ssl`, `wc_get_logger`) via
//     Brain\Monkey aliased.
//   - `WebhookSecretManager` exponiert `peek()` und `generate()` als statische
//     Methoden — `peek()` lesen wir ueber den `get_option`-Stub (Slice-14
//     persistiert in `spreadconnect_webhook_secret`), `generate()` wird in
//     einem AC-7-Test als realer Aufruf aktiviert (mit Stubs fuer
//     update_option + do_action).
//
// `WP_Error` als minimale Stub-Klasse (idempotent gegenueber anderen Slices).
// `WEEK_IN_SECONDS` als Konstante, falls noch nicht definiert.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
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

	if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
		// Mirror der WP-Konstante (architecture.md / wp-includes/default-constants.php).
		define( 'WEEK_IN_SECONDS', 7 * 24 * 60 * 60 );
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\Dto\Subscription;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Subscription\SubscriptionManager;
	use SpreadconnectPod\Subscription\WebhookSecretManager;

	/**
	 * Test-Subklasse mit Override fuer `makeClient()` (slice-18 Constraints —
	 * "test seam"). Das Mockery-Mock-Objekt wird ueber eine statische Property
	 * injiziert; der Override gibt es zurueck, sodass die Production-Code-Pfade
	 * (`diff()`, `register()`, `removeOrphans()`, `resubscribeAll()`,
	 * `driftCheck()`, `onApiKeySaved()`) das Mock statt eines realen
	 * `SpreadconnectClient` sehen.
	 */
	final class StubbedSubscriptionManager extends SubscriptionManager
	{
		/**
		 * Mock-Inject-Slot. Pro Test in `setUp()` mit dem Mockery-Mock befuellt;
		 * in `tearDown()` zurueck auf `null` gesetzt damit das Mock nicht in
		 * den naechsten Test leakt.
		 */
		public static ?SpreadconnectClient $mockClient = null;

		protected static function makeClient(): SpreadconnectClient
		{
			if ( null === self::$mockClient ) {
				throw new \LogicException(
					'StubbedSubscriptionManager::$mockClient is null — set it in the test setUp(). ' .
					'No real SpreadconnectClient may be constructed in slice-18 tests.'
				);
			}
			return self::$mockClient;
		}
	}

	/**
	 * Slice 18 — Subscription-Manager + Auto-Register on Settings-Save.
	 *
	 * Acceptance Tests gegen `slice-18-subscription-manager.md`. Tests laufen
	 * NICHT in separate Prozessen — `SpreadconnectClient` ist nicht final,
	 * daher reicht klassisches `Mockery::mock()` ohne `overload:`-Trick.
	 *
	 * Strategie pro AC:
	 *
	 *   - AC-1: `diff()` klassifiziert active/missing/orphans korrekt (URL-
	 *     Match-only fuer orphans, fremde URLs werden silently ignoriert).
	 *
	 *   - AC-2: veraltete URL gleicher Plugin-Namespace -> orphan + missing
	 *     parallel.
	 *
	 *   - AC-3: `register()` bei leerer SC-Liste registriert genau 7 Events;
	 *     jeder Call traegt `home_url(...)` callbackUrl + Secret aus
	 *     `WebhookSecretManager::peek()`.
	 *
	 *   - AC-4: `register()` bei vollstaendiger Remote-Liste skippt alle
	 *     (Idempotenz). Summary `added=0, removed=0, skipped=7, errors=[]`.
	 *
	 *   - AC-5: `removeOrphans()` loescht nur eigene veraltete URLs. Fremde
	 *     URLs werden niemals geloescht (architecture.md Z. 108).
	 *
	 *   - AC-6: 4xx waehrend `createSubscription` -> sammeln in errors[],
	 *     Loop laeuft weiter. 5xx/Transient -> re-thrown (AS-Retry-Pfad).
	 *
	 *   - AC-7: `onApiKeySaved()` orchestriert authenticate -> generate ->
	 *     register; bei authenticate-Failure wird register NICHT aufgerufen.
	 *
	 *   - AC-8: Listener auf `spreadconnect/webhook_secret_rotated` triggert
	 *     `resubscribeAll()` mit DELETE-then-POST-Reihenfolge.
	 *
	 *   - AC-9: `as_schedule_recurring_action` wird genau einmal geplant
	 *     (Idempotenz bei Re-Activate).
	 *
	 *   - AC-10: `driftCheck()` bei missing/orphans laeuft `register()` +
	 *     Admin-Notice; bei Sync schreibt es kein Notice.
	 *
	 *   - AC-12: Logger-Calls enthalten KEINEN Plaintext-Secret.
	 */
	final class Slice18SubscriptionManagerTest extends TestCase
	{
		/**
		 * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
		 */
		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		/**
		 * Captured `update_option`-Aufrufe als list of [key, value].
		 *
		 * @var list<array{0:string,1:mixed}>
		 */
		private array $optionWrites = [];

		/**
		 * Backing store fuer `get_option`-Lookups.
		 *
		 * @var array<string,mixed>
		 */
		private array $optionStore = [];

		/**
		 * Captured Logger-Eintraege fuer AC-12.
		 *
		 * @var list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		private array $loggerEntries = [];

		/**
		 * Aktuelle home_url() Webhook-URL (slice-18 currentCallbackUrl()).
		 *
		 * Bewusst der Default-Production-Wert "https://shop.example/...". Tests
		 * die einen anderen Host brauchen (AC-2 stale URL) ueberschreiben den
		 * Stub lokal.
		 */
		private const HOME_URL = 'https://shop.example/wp-json/spreadconnect/v1/webhook';

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->optionWrites  = [];
			$this->optionStore   = [];
			$this->loggerEntries = [];

			// ---- i18n / escape — passthrough -------------------------------.
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );

			// ---- get_option / update_option --------------------------------.
			$store = & $this->optionStore;
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = false ) use ( &$store ) {
					if ( array_key_exists( (string) $name, $store ) ) {
						return $store[ (string) $name ];
					}
					return $default;
				}
			);
			$writes = & $this->optionWrites;
			Functions\when( 'update_option' )->alias(
				static function ( $name, $value ) use ( &$writes, &$store ): bool {
					$writes[]                  = [ (string) $name, $value ];
					$store[ (string) $name ]   = $value;
					return true;
				}
			);

			// ---- home_url / is_ssl ----------------------------------------.
			Functions\when( 'is_ssl' )->justReturn( true );
			Functions\when( 'home_url' )->alias(
				static function ( string $path = '', $scheme = null ): string {
					$base = 'https://shop.example';
					if ( '' === $path ) {
						return $base;
					}
					if ( str_starts_with( $path, '/' ) ) {
						return $base . $path;
					}
					return $base . '/' . $path;
				}
			);

			// ---- Logger-Capture -------------------------------------------.
			$entries = & $this->loggerEntries;
			Functions\when( 'wc_get_logger' )->alias( static function () use ( &$entries ) {
				return new class( $entries ) {
					/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
					private array $entries;
					public function __construct( array &$entries ) {
						$this->entries = &$entries;
					}
					public function log( string $level, string $message, array $context = [] ): void {
						$this->entries[] = [
							'level'   => $level,
							'message' => $message,
							'context' => $context,
						];
					}
					public function info( string $message, array $context = [] ): void {
						$this->entries[] = [ 'level' => 'info', 'message' => $message, 'context' => $context ];
					}
					public function warning( string $message, array $context = [] ): void {
						$this->entries[] = [ 'level' => 'warning', 'message' => $message, 'context' => $context ];
					}
					public function error( string $message, array $context = [] ): void {
						$this->entries[] = [ 'level' => 'error', 'message' => $message, 'context' => $context ];
					}
					public function debug( string $message, array $context = [] ): void {
						$this->entries[] = [ 'level' => 'debug', 'message' => $message, 'context' => $context ];
					}
				};
			} );
		}

		protected function tearDown(): void
		{
			StubbedSubscriptionManager::$mockClient = null;
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------

		/**
		 * Build a Subscription DTO with the given event + URL combo.
		 */
		private function sub( string $id, string $eventType, string $callbackUrl ): Subscription
		{
			return new Subscription(
				id: $id,
				eventType: $eventType,
				callbackUrl: $callbackUrl,
				state: 'active',
			);
		}

		/**
		 * Inject a fresh Mockery mock as the active client; return it for
		 * expectation setup.
		 */
		private function mockClient(): \Mockery\MockInterface
		{
			$mock = Mockery::mock( SpreadconnectClient::class );
			StubbedSubscriptionManager::$mockClient = $mock;
			return $mock;
		}

		// ===================================================================
		// AC-1: GIVEN getSubscriptions() liefert 3 active Subscriptions auf
		//       unsere callbackUrl + 1 fremde URL.
		//       WHEN  diff() laeuft.
		//       THEN  result.missing enthaelt die 4 fehlenden Events,
		//             result.active enthaelt die 3 active eventTypes,
		//             result.orphans ist leer (fremde URLs niemals).
		// ===================================================================

		public function test_diff_classifies_subscriptions_into_three_disjoint_lists(): void
		{
			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->once()
				->andReturn( [
					$this->sub( 'sub-1', 'Article.added',   self::HOME_URL ),
					$this->sub( 'sub-2', 'Article.updated', self::HOME_URL ),
					$this->sub( 'sub-3', 'Order.processed', self::HOME_URL ),
					// Fremde callback URL — MUSS silently ignoriert werden,
					// niemals als orphan gemeldet (architecture.md Z. 108).
					$this->sub( 'sub-foreign', 'Order.cancelled', 'https://other-shop.example/webhook' ),
				] );

			$result = StubbedSubscriptionManager::diff();

			$this->assertSame(
				[ 'Article.added', 'Article.updated', 'Order.processed' ],
				$result['active'],
				'AC-1: active MUSS exakt die 3 active eventTypes auf unserer URL enthalten.'
			);

			sort( $result['missing'] );
			$expected_missing = [
				'Article.removed',
				'Order.cancelled',
				'Order.needs-action',
				'Shipment.sent',
			];
			sort( $expected_missing );
			$this->assertSame(
				$expected_missing,
				$result['missing'],
				'AC-1: missing MUSS die 4 fehlenden Events enthalten ' .
				'(Article.removed, Order.cancelled, Order.needs-action, Shipment.sent).'
			);

			$this->assertSame(
				[],
				$result['orphans'],
				'AC-1: orphans MUSS LEER sein — fremde callback URLs werden NIEMALS in orphans gelistet ' .
				'(architecture.md Z. 108 "Never deletes foreign URLs").'
			);
		}

		// ===================================================================
		// AC-2: GIVEN getSubscriptions() liefert eine Subscription auf
		//       Article.added mit veralteter callbackUrl
		//       (http://localhost:8080/wp-json/... statt https://shop.example/...).
		//       WHEN  diff() laeuft.
		//       THEN  Sub. wird als orphan klassifiziert UND Article.added
		//             ist gleichzeitig in missing.
		// ===================================================================

		public function test_diff_treats_outdated_callback_url_as_orphan_plus_missing(): void
		{
			$staleUrl = 'http://localhost:8080/wp-json/spreadconnect/v1/webhook';

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->once()
				->andReturn( [
					// Stale URL — anderer Host UND anderes Schema, aber selber
					// REST-Namespace-Path. URL-Match per `===` schlaegt fehl,
					// also: orphan (URL belongs to us per Namespace-Match).
					$this->sub( 'sub-stale', 'Article.added', $staleUrl ),
				] );

			$result = StubbedSubscriptionManager::diff();

			// Article.added MUSS als orphan (mit stale URL) UND in missing landen.
			$this->assertCount(
				1,
				$result['orphans'],
				'AC-2: stale URL auf eigener Plugin-Namespace MUSS in orphans landen.'
			);
			$this->assertSame(
				'sub-stale',
				$result['orphans'][0]['id'],
				'AC-2: orphan-Eintrag MUSS die original-id der stale subscription tragen.'
			);
			$this->assertSame(
				$staleUrl,
				$result['orphans'][0]['callbackUrl'],
				'AC-2: orphan-Eintrag MUSS die original (stale) callbackUrl tragen — ' .
				'NICHT die aktuelle home_url(). Repair muss DELETE+POST nacheinander.'
			);
			$this->assertSame(
				'Article.added',
				$result['orphans'][0]['eventType'],
				'AC-2: orphan-Eintrag MUSS den original eventType tragen.'
			);

			$this->assertContains(
				'Article.added',
				$result['missing'],
				'AC-2: Article.added MUSS gleichzeitig in missing sein — der Diff-Algorithmus ' .
				'vergleicht URLs strict (===), nicht per Substring-Match.'
			);

			$this->assertSame(
				[],
				$result['active'],
				'AC-2: active MUSS leer sein — die stale URL zaehlt NICHT als active.'
			);
		}

		// ===================================================================
		// AC-3: GIVEN getSubscriptions() liefert leere Liste, peek() liefert
		//       ein gueltiges Secret.
		//       WHEN  register() laeuft.
		//       THEN  createSubscription() wird genau 7-mal aufgerufen, jeder
		//             Call traegt einen der 7 EXPECTED_EVENTS, callbackUrl =
		//             home_url-URL, secret = peek()-Wert.
		// ===================================================================

		public function test_register_creates_seven_subscriptions_on_empty_remote_state(): void
		{
			$secret = 'BASE64_SECRET_FROM_PEEK_42';
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = $secret;

			$captured = [];
			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->once()
				->andReturn( [] );
			$mock->shouldReceive( 'createSubscription' )
				->times( 7 )
				->andReturnUsing( function ( string $eventType, string $callbackUrl, string $secretArg ) use ( &$captured ) {
					$captured[] = [
						'eventType'   => $eventType,
						'callbackUrl' => $callbackUrl,
						'secret'      => $secretArg,
					];
					return new Subscription(
						id: 'new-' . count( $captured ),
						eventType: $eventType,
						callbackUrl: $callbackUrl,
					);
				} );

			$summary = StubbedSubscriptionManager::register();

			$this->assertCount(
				7,
				$captured,
				'AC-3: createSubscription MUSS GENAU 7-mal aufgerufen werden.'
			);

			$capturedEvents = array_map( static fn ( array $c ): string => $c['eventType'], $captured );
			sort( $capturedEvents );
			$expected = SubscriptionManager::EXPECTED_EVENTS;
			sort( $expected );
			$this->assertSame(
				$expected,
				$capturedEvents,
				'AC-3: Die 7 eventType-Strings MUESSEN exakt EXPECTED_EVENTS abdecken.'
			);

			$this->assertSame(
				7,
				$summary['added'],
				'AC-3: summary.added MUSS 7 sein.'
			);
		}

		public function test_register_passes_home_url_and_peek_secret_to_each_call(): void
		{
			$secret = 'BASE64_PEEK_SECRET_xyz';
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = $secret;

			$captured = [];
			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )->andReturn( [] );
			$mock->shouldReceive( 'createSubscription' )
				->times( 7 )
				->andReturnUsing( function ( string $eventType, string $callbackUrl, string $secretArg ) use ( &$captured ) {
					$captured[] = [ $eventType, $callbackUrl, $secretArg ];
					return new Subscription(
						id: 'new',
						eventType: $eventType,
						callbackUrl: $callbackUrl,
					);
				} );

			StubbedSubscriptionManager::register();

			$expectedUrl = SubscriptionManager::currentCallbackUrl();
			$this->assertSame(
				self::HOME_URL,
				$expectedUrl,
				'AC-3 sanity: currentCallbackUrl() MUSS dem home_url-Stub entsprechen.'
			);

			foreach ( $captured as $idx => [ $eventType, $callbackUrl, $secretArg ] ) {
				$this->assertSame(
					$expectedUrl,
					$callbackUrl,
					sprintf(
						'AC-3: callbackUrl in Call #%d (eventType=%s) MUSS exakt currentCallbackUrl() sein.',
						$idx,
						$eventType
					)
				);
				$this->assertSame(
					$secret,
					$secretArg,
					sprintf(
						'AC-3: secret in Call #%d MUSS exakt WebhookSecretManager::peek() sein.',
						$idx
					)
				);
			}
		}

		// ===================================================================
		// AC-4: GIVEN getSubscriptions() liefert alle 7 expected Subscriptions
		//       auf unsere URL.
		//       WHEN  register() laeuft.
		//       THEN  createSubscription/deleteSubscription werden NIEMALS
		//             aufgerufen; summary.skipped == 7.
		// ===================================================================

		public function test_register_is_idempotent_when_all_seven_already_active(): void
		{
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = 'whatever';

			$existing = [];
			foreach ( SubscriptionManager::EXPECTED_EVENTS as $i => $event ) {
				$existing[] = $this->sub( 'sub-' . $i, $event, self::HOME_URL );
			}

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->once()
				->andReturn( $existing );
			$mock->shouldNotReceive( 'createSubscription' );
			$mock->shouldNotReceive( 'deleteSubscription' );

			$summary = StubbedSubscriptionManager::register();

			$this->assertSame(
				0,
				$summary['added'],
				'AC-4: summary.added MUSS 0 sein (alles bereits aktiv).'
			);
			$this->assertSame(
				0,
				$summary['removed'],
				'AC-4: summary.removed MUSS 0 sein (kein Orphan vorhanden).'
			);
			$this->assertSame(
				7,
				$summary['skipped'],
				'AC-4: summary.skipped MUSS 7 sein — Idempotenz-Verifikation.'
			);
			$this->assertSame(
				[],
				$summary['errors'],
				'AC-4: summary.errors MUSS leer sein.'
			);
		}

		// ===================================================================
		// AC-5: GIVEN getSubscriptions() liefert 2 Subs auf veraltete eigene
		//       URL + 1 Sub auf fremde URL.
		//       WHEN  removeOrphans() laeuft.
		//       THEN  deleteSubscription wird genau 2-mal mit unseren orphan-IDs
		//             aufgerufen; fremde URL wird NIEMALS geloescht.
		// ===================================================================

		public function test_remove_orphans_never_deletes_foreign_callback_urls(): void
		{
			$staleUrl   = 'http://localhost:8080/wp-json/spreadconnect/v1/webhook';
			$foreignUrl = 'https://other-shop.example/webhook';

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->once()
				->andReturn( [
					$this->sub( 'orph-A', 'Article.added',   $staleUrl ),
					$this->sub( 'orph-B', 'Article.updated', $staleUrl ),
					$this->sub( 'foreign', 'Order.cancelled', $foreignUrl ),
				] );

			$deleted = [];
			$mock->shouldReceive( 'deleteSubscription' )
				->twice()
				->andReturnUsing( static function ( string $id ) use ( &$deleted ): void {
					$deleted[] = $id;
				} );

			$count = StubbedSubscriptionManager::removeOrphans();

			$this->assertSame(
				2,
				$count,
				'AC-5: removeOrphans MUSS 2 zurueckliefern — nur die 2 eigenen Orphans.'
			);

			sort( $deleted );
			$this->assertSame(
				[ 'orph-A', 'orph-B' ],
				$deleted,
				'AC-5: deleteSubscription MUSS exakt die 2 eigenen Orphan-IDs erhalten.'
			);

			$this->assertNotContains(
				'foreign',
				$deleted,
				'AC-5: Fremde URL DARF NIEMALS geloescht werden ' .
				'(architecture.md Z. 108 — "Never deletes foreign URLs"). PFLICHT.'
			);
		}

		// ===================================================================
		// AC-6: GIVEN register() laeuft, ein einzelner createSubscription-Call
		//       wirft SpreadconnectClientError (4xx).
		//       WHEN  Manager das Error verarbeitet.
		//       THEN  Loop laeuft weiter, errors[] enthaelt Eintrag fuer das
		//             fehlgeschlagene Event, restliche Events werden registriert.
		// ===================================================================

		public function test_register_continues_on_client_error_and_collects_into_summary(): void
		{
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = 'secret';

			$failingEvent = 'Order.cancelled';
			$callCount = 0;

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )->andReturn( [] );
			$mock->shouldReceive( 'createSubscription' )
				->times( 7 )
				->andReturnUsing( function ( string $eventType, string $url, string $sec ) use ( $failingEvent, &$callCount ) {
					++$callCount;
					if ( $eventType === $failingEvent ) {
						throw new SpreadconnectClientError(
							'http_4xx',
							'POST /subscriptions -> 409 conflict',
							409,
							'/subscriptions'
						);
					}
					return new Subscription( id: 'new', eventType: $eventType, callbackUrl: $url );
				} );

			$summary = StubbedSubscriptionManager::register();

			$this->assertSame(
				7,
				$callCount,
				'AC-6: ALLE 7 createSubscription-Calls MUESSEN ausgefuehrt worden sein — ' .
				'der Loop bricht beim 4xx NICHT ab.'
			);

			$this->assertSame(
				6,
				$summary['added'],
				'AC-6: summary.added MUSS 6 sein (1 Failure von 7).'
			);

			$this->assertCount(
				1,
				$summary['errors'],
				'AC-6: summary.errors MUSS GENAU 1 Eintrag enthalten — den 4xx-Failure.'
			);

			$err = $summary['errors'][0];
			$this->assertSame(
				$failingEvent,
				$err['eventType'],
				'AC-6: error.eventType MUSS das fehlgeschlagene Event sein.'
			);
			$this->assertArrayHasKey(
				'message',
				$err,
				'AC-6: error MUSS message-Key haben — Spec contract `[eventType, message]`.'
			);
			$this->assertSame(
				'Subscription registration failed',
				$err['message'],
				'AC-6: error.message MUSS exakt der __()-uebersetzte String "Subscription registration failed" sein.'
			);
		}

		public function test_register_rethrows_transient_error_for_action_scheduler_retry(): void
		{
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = 'secret';

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )->andReturn( [] );
			$mock->shouldReceive( 'createSubscription' )
				->andThrow(
					new SpreadconnectTransientError(
						'http_5xx',
						'POST /subscriptions -> 503',
						503,
						'/subscriptions'
					)
				);

			$this->expectException( SpreadconnectTransientError::class );
			StubbedSubscriptionManager::register();
		}

		// ===================================================================
		// AC-7: GIVEN onApiKeySaved() wird mit gueltiger Connection aufgerufen.
		//       WHEN  authenticate() success + secret leer.
		//       THEN  authenticate -> generate -> register werden in dieser
		//             Reihenfolge aufgerufen (oder generate fired bereits den
		//             rotated-hook der resubscribeAll triggered).
		// ===================================================================

		public function test_settings_save_hook_orchestrates_authenticate_then_generate_then_register(): void
		{
			// Initial-Setup-Branch: secret leer.
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = '';

			$mock = $this->mockClient();

			// authenticate() MUSS aufgerufen werden — als erster Call.
			$mock->shouldReceive( 'authenticate' )
				->once()
				->andReturn( new \SpreadconnectPod\Api\Dto\AuthOk(
					pointOfSaleId: 'pos_1',
					accountId: 'acc_1',
				) );

			// `WebhookSecretManager::generate()` ruft intern do_action +
			// update_option. Wir capturen nur, dass es passiert ist —
			// sobald `generate()` lief, ist die Initial-Branch-Spec erfuellt
			// (architecture.md spec sagt: bei Initial-Setup laeuft generate(),
			// das das `webhook_secret_rotated`-Hook feuert; resubscribeAll
			// uebernimmt dann den Subscribe-Sweep — `register()` wird in
			// diesem Branch NICHT zusaetzlich aufgerufen).
			Functions\when( 'do_action' )->alias(
				static function () {
					// No-op — der ACTION_ROTATED-Hook wird in dieser Test-
					// Variante nicht in einen Listener gefuettert.
				}
			);

			// random_bytes-Fallback: WebhookSecretManager::generateRandomBytes
			// nutzt random_bytes() — das ist im Test-Bootstrap verfuegbar.

			StubbedSubscriptionManager::onApiKeySaved();

			// authenticate MUSS aufgerufen worden sein (Mockery prueft das via
			// `once()`; wir fragen zusaetzlich den Recorder ab).
			$this->addToAssertionCount( 1 ); // Mockery `once()` als impliziter Assert.

			// generate() hat das Secret geschrieben → optionStore enthaelt es.
			$this->assertArrayHasKey(
				WebhookSecretManager::OPTION_SECRET,
				$this->optionStore,
				'AC-7: WebhookSecretManager::generate() MUSS das Secret persistiert haben (Initial-Setup-Branch).'
			);
			$this->assertNotSame(
				'',
				$this->optionStore[ WebhookSecretManager::OPTION_SECRET ],
				'AC-7: persistiertes Secret darf nicht leer sein nach generate().'
			);
		}

		public function test_settings_save_hook_skips_register_when_authenticate_fails(): void
		{
			// Secret bereits gesetzt — wir wollen sehen, dass register/createSubscription
			// im authenticate-Failure-Pfad NICHT aufgerufen wird.
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = 'existing-secret';

			$mock = $this->mockClient();

			$mock->shouldReceive( 'authenticate' )
				->once()
				->andThrow(
					new SpreadconnectClientError(
						'http_4xx',
						'GET /authentication -> 401 unauthorized',
						401,
						'/authentication'
					)
				);

			// register() darf NICHT laufen → getSubscriptions / createSubscription /
			// deleteSubscription duerfen NICHT aufgerufen werden.
			$mock->shouldNotReceive( 'getSubscriptions' );
			$mock->shouldNotReceive( 'createSubscription' );
			$mock->shouldNotReceive( 'deleteSubscription' );

			// onApiKeySaved schluckt die Exception silent (AC-7 — Save bleibt
			// erfolgreich, kein Subscribe).
			StubbedSubscriptionManager::onApiKeySaved();

			$this->addToAssertionCount( 1 );
		}

		// ===================================================================
		// AC-8: GIVEN spreadconnect/webhook_secret_rotated feuert mit neuem
		//       Secret.
		//       WHEN  Listener resubscribeAll($newSecret) ruft.
		//       THEN  alle existierenden Subs auf unsere URL werden zuerst
		//             per deleteSubscription entfernt; danach 7 createSubscription
		//             mit $newSecret. Reihenfolge: DELETE-Phase vor POST-Phase.
		// ===================================================================

		public function test_secret_rotation_listener_deletes_then_recreates_with_new_secret(): void
		{
			$newSecret = 'NEW_ROTATED_SECRET_BASE64';

			// 3 existierende Subs auf unsere URL + 1 fremde (NICHT zu loeschen).
			$existing = [
				$this->sub( 'old-1', 'Article.added',   self::HOME_URL ),
				$this->sub( 'old-2', 'Order.processed', self::HOME_URL ),
				$this->sub( 'old-3', 'Shipment.sent',   self::HOME_URL ),
				$this->sub( 'foreign', 'Order.cancelled', 'https://other-shop.example/webhook' ),
			];

			$callOrder    = [];
			$deletedIds   = [];
			$createdEvts  = [];
			$createdSecs  = [];

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )
				->andReturn( $existing );

			$mock->shouldReceive( 'deleteSubscription' )
				->andReturnUsing( static function ( string $id ) use ( &$callOrder, &$deletedIds ): void {
					$callOrder[]  = 'DELETE:' . $id;
					$deletedIds[] = $id;
				} );

			$mock->shouldReceive( 'createSubscription' )
				->andReturnUsing( function ( string $eventType, string $url, string $sec ) use ( &$callOrder, &$createdEvts, &$createdSecs ) {
					$callOrder[]   = 'CREATE:' . $eventType;
					$createdEvts[] = $eventType;
					$createdSecs[] = $sec;
					return new Subscription( id: 'new', eventType: $eventType, callbackUrl: $url );
				} );

			$summary = StubbedSubscriptionManager::resubscribeAll( $newSecret );

			// DELETE-Phase: nur die 3 eigenen IDs, NICHT die fremde.
			sort( $deletedIds );
			$this->assertSame(
				[ 'old-1', 'old-2', 'old-3' ],
				$deletedIds,
				'AC-8: DELETE-Phase MUSS exakt die 3 eigenen alten Subs entfernen — fremde URL bleibt.'
			);

			// POST-Phase: alle 7 EXPECTED_EVENTS mit $newSecret.
			sort( $createdEvts );
			$expectedEvents = SubscriptionManager::EXPECTED_EVENTS;
			sort( $expectedEvents );
			$this->assertSame(
				$expectedEvents,
				$createdEvts,
				'AC-8: POST-Phase MUSS alle 7 EXPECTED_EVENTS mit dem neuen Secret registrieren.'
			);

			// Jeder createSubscription-Call MUSS $newSecret tragen — niemals das alte.
			foreach ( $createdSecs as $idx => $sec ) {
				$this->assertSame(
					$newSecret,
					$sec,
					sprintf(
						'AC-8: createSubscription Call #%d MUSS $newSecret tragen — kein Alt-Secret-Mix.',
						$idx
					)
				);
			}

			// Reihenfolge-Pflicht: alle DELETEs MUESSEN vor jedem CREATE landen.
			$lastDeleteIdx = -1;
			$firstCreateIdx = PHP_INT_MAX;
			foreach ( $callOrder as $idx => $entry ) {
				if ( str_starts_with( $entry, 'DELETE:' ) ) {
					$lastDeleteIdx = max( $lastDeleteIdx, $idx );
				}
				if ( str_starts_with( $entry, 'CREATE:' ) ) {
					$firstCreateIdx = min( $firstCreateIdx, $idx );
				}
			}
			$this->assertGreaterThan(
				$lastDeleteIdx,
				$firstCreateIdx,
				'AC-8 PFLICHT: DELETE-Phase MUSS vollstaendig abgeschlossen sein BEVOR die erste ' .
				'createSubscription laeuft — sonst Mix aus altem und neuem Secret.'
			);

			$this->assertSame(
				7,
				$summary['added'],
				'AC-8: summary.added MUSS 7 sein.'
			);
			$this->assertSame(
				3,
				$summary['removed'],
				'AC-8: summary.removed MUSS 3 sein (die 3 eigenen Alt-Subs).'
			);
		}

		// ===================================================================
		// AC-9: GIVEN Plugin-Aktivierung (scheduleRecurringDriftCheck()).
		//       WHEN  Manager seine Recurring-Action registriert.
		//       THEN  as_next_scheduled_action wird geprueft; falls nicht
		//             geplant, wird as_schedule_recurring_action GENAU EINMAL
		//             aufgerufen. Bei Re-Aktivierung KEIN doppeltes Schedule.
		// ===================================================================

		public function test_recurring_drift_check_scheduled_exactly_once(): void
		{
			$nextScheduledCalls = [];
			$scheduleRecurringCalls = [];

			// Erster Aufruf: AS sagt "noch nicht geplant" -> schedule darf laufen.
			Functions\when( 'as_next_scheduled_action' )->alias(
				static function ( string $hook, $args = null, $group = null ) use ( &$nextScheduledCalls, &$scheduleRecurringCalls ) {
					$nextScheduledCalls[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
					// Wenn schon ein Schedule angelegt wurde, geben wir true (Timestamp) zurueck.
					return empty( $scheduleRecurringCalls ) ? false : 99999;
				}
			);
			Functions\when( 'as_schedule_recurring_action' )->alias(
				static function ( $timestamp, $interval, $hook, $args = [], $group = '' ) use ( &$scheduleRecurringCalls ): int {
					$scheduleRecurringCalls[] = [
						'timestamp' => $timestamp,
						'interval'  => $interval,
						'hook'      => $hook,
						'args'      => $args,
						'group'     => $group,
					];
					return 1;
				}
			);

			// Erster Aufruf — schedule MUSS laufen.
			SubscriptionManager::scheduleRecurringDriftCheck();
			$this->assertCount(
				1,
				$scheduleRecurringCalls,
				'AC-9: as_schedule_recurring_action MUSS beim ersten Aufruf GENAU EINMAL laufen.'
			);

			$call = $scheduleRecurringCalls[0];
			$this->assertSame(
				'spreadconnect/auto_subscription_check',
				$call['hook'],
				'AC-9: Hook MUSS exakt "spreadconnect/auto_subscription_check" sein.'
			);
			$this->assertSame(
				WEEK_IN_SECONDS,
				$call['interval'],
				'AC-9: Interval MUSS WEEK_IN_SECONDS sein (kein Magic-Number).'
			);
			$this->assertSame(
				'spreadconnect',
				$call['group'],
				'AC-9: AS-Group-Slug MUSS "spreadconnect" sein (architecture.md Z. 558).'
			);
			$this->assertSame(
				[],
				$call['args'],
				'AC-9: Args MUSS leeres Array sein.'
			);

			// Zweiter Aufruf (Re-Activate) — as_next_scheduled_action liefert
			// nun eine Timestamp -> schedule DARF NICHT erneut laufen.
			SubscriptionManager::scheduleRecurringDriftCheck();
			$this->assertCount(
				1,
				$scheduleRecurringCalls,
				'AC-9 PFLICHT: Re-Activate DARF KEIN zweites as_schedule_recurring_action ausloesen — Idempotenz.'
			);
		}

		// ===================================================================
		// AC-10: GIVEN driftCheck() laeuft mit Drift (missing oder orphans).
		//        WHEN  Handler ausgefuehrt wird.
		//        THEN  register() laeuft; Persistent-Admin-Notice mit
		//              "Subscriptions out of sync — auto-repaired" wird
		//              geschrieben (in spreadconnect_admin_notices Option).
		// ===================================================================

		public function test_drift_check_triggers_self_heal_and_writes_admin_notice(): void
		{
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = 'secret';

			// Initial-State: 1 active + missing.
			$mock = $this->mockClient();

			// 3x getSubscriptions (driftCheck() -> diff(); register() -> diff();
			// removeOrphans() -> diff()) — wir geben jedes Mal denselben State.
			$mock->shouldReceive( 'getSubscriptions' )
				->andReturn( [
					$this->sub( 'sub-1', 'Article.added', self::HOME_URL ),
				] );

			// register() ruft createSubscription fuer die 6 fehlenden Events.
			$mock->shouldReceive( 'createSubscription' )
				->andReturnUsing( static function ( string $eventType, string $url, string $sec ) {
					return new Subscription( id: 'new', eventType: $eventType, callbackUrl: $url );
				} );

			StubbedSubscriptionManager::driftCheck();

			// Persistent-Admin-Notice MUSS via update_option('spreadconnect_admin_notices', ...) gesetzt sein.
			$this->assertArrayHasKey(
				'spreadconnect_admin_notices',
				$this->optionStore,
				'AC-10: driftCheck() MUSS bei Drift einen Eintrag in spreadconnect_admin_notices schreiben.'
			);

			$notices = $this->optionStore['spreadconnect_admin_notices'];
			$this->assertIsArray( $notices, 'AC-10: spreadconnect_admin_notices MUSS ein Array sein.' );
			$this->assertNotEmpty( $notices, 'AC-10: spreadconnect_admin_notices DARF NICHT leer sein.' );

			$lastEntry = end( $notices );
			$message   = is_array( $lastEntry ) ? ( $lastEntry['message'] ?? '' ) : (string) $lastEntry;

			$this->assertStringContainsString(
				'out of sync',
				(string) $message,
				'AC-10: Notice-Message MUSS "out of sync" enthalten ' .
				'(spec: "Subscriptions out of sync — auto-repaired").'
			);
			$this->assertStringContainsString(
				'auto-repaired',
				(string) $message,
				'AC-10: Notice-Message MUSS "auto-repaired" enthalten.'
			);
		}

		public function test_drift_check_writes_no_notice_when_in_sync(): void
		{
			// Vollstaendig sync: alle 7 EXPECTED_EVENTS bereits aktiv.
			$active = [];
			foreach ( SubscriptionManager::EXPECTED_EVENTS as $i => $event ) {
				$active[] = $this->sub( 'sub-' . $i, $event, self::HOME_URL );
			}

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )->andReturn( $active );
			$mock->shouldNotReceive( 'createSubscription' );
			$mock->shouldNotReceive( 'deleteSubscription' );

			StubbedSubscriptionManager::driftCheck();

			$this->assertArrayNotHasKey(
				'spreadconnect_admin_notices',
				$this->optionStore,
				'AC-10: Bei vollstaendiger Sync DARF KEIN Admin-Notice geschrieben werden — silent no-op.'
			);
		}

		// ===================================================================
		// AC-12: Logger schreibt KEINEN Plaintext-Secret.
		// ===================================================================

		public function test_logger_does_not_emit_plaintext_secret(): void
		{
			$secret = 'BASE64_SECRET_DO_NOT_LEAK_xyz789';
			$this->optionStore[ WebhookSecretManager::OPTION_SECRET ] = $secret;

			$mock = $this->mockClient();
			$mock->shouldReceive( 'getSubscriptions' )->andReturn( [] );
			$mock->shouldReceive( 'createSubscription' )
				->andReturnUsing( static function ( string $eventType, string $url, string $sec ) {
					return new Subscription( id: 'new', eventType: $eventType, callbackUrl: $url );
				} );

			StubbedSubscriptionManager::register();

			// Pruefe alle Logger-Eintraege — KEINER darf den Secret enthalten.
			$this->assertNotEmpty(
				$this->loggerEntries,
				'AC-12 sanity: Manager schreibt mind. einen Logger-Eintrag pro register()-Run.'
			);

			foreach ( $this->loggerEntries as $entry ) {
				$this->assertStringNotContainsString(
					$secret,
					(string) $entry['message'],
					sprintf(
						'AC-12: Plaintext-Secret darf NIEMALS in Logger-Message auftauchen ' .
						'(level=%s, message=%s).',
						$entry['level'],
						$entry['message']
					)
				);
				$contextJson = json_encode( $entry['context'] ) ?: '';
				$this->assertStringNotContainsString(
					$secret,
					$contextJson,
					sprintf(
						'AC-12: Plaintext-Secret darf NIEMALS in Logger-Context auftauchen ' .
						'(level=%s, context=%s).',
						$entry['level'],
						$contextJson
					)
				);
			}
		}

		// ===================================================================
		// Static-source assertions: SubscriptionManager exponiert die
		// vorgeschriebenen Konstanten / Konventionen.
		// ===================================================================

		public function test_expected_events_constant_holds_seven_canonical_events(): void
		{
			$this->assertSame(
				[
					'Article.added',
					'Article.updated',
					'Article.removed',
					'Order.processed',
					'Order.cancelled',
					'Order.needs-action',
					'Shipment.sent',
				],
				SubscriptionManager::EXPECTED_EVENTS,
				'EXPECTED_EVENTS MUSS exakt die 7 Events aus architecture.md Z. 41 in genau dieser Form enthalten.'
			);
		}

		public function test_make_client_seam_is_protected_static(): void
		{
			$reflection = new ReflectionClass( SubscriptionManager::class );
			$this->assertTrue(
				$reflection->hasMethod( 'makeClient' ),
				'SubscriptionManager MUSS eine makeClient()-Test-Seam exponieren ' .
				'(slice-18 spec — protected static fuer Test-Subclass-Override).'
			);
			$method = $reflection->getMethod( 'makeClient' );
			$this->assertTrue(
				$method->isProtected(),
				'makeClient() MUSS protected sein (Test-Seam, kein Public API).'
			);
			$this->assertTrue(
				$method->isStatic(),
				'makeClient() MUSS static sein (Stateless-Service-Pattern).'
			);
		}
	}
}
