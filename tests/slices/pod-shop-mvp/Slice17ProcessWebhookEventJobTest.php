<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 17 — `Webhook\ProcessWebhookEventJob` Dispatcher.
//
// Acceptance Tests gegen `slice-17-process-webhook-event-job.md` (AC-1..AC-10).
//
// Mocking Strategy: `mock_external` (per Slice-Spec):
//   - Brain\Monkey aliases fuer `wc_get_logger`, `__()`, sowie Hook-Inspektion
//     ueber `Brain\Monkey\Actions\has()`.
//   - `WebhookLogRepo`, `OrderEventHandler` und `ArticleEventHandler` sind
//     `final class` (Job-Pattern, architecture.md Z. 532). Mockery's
//     `overload:` koennte sie ersetzen, aber die generierten Mock-Klassen
//     verlieren die Klassen-Konstanten (`STATUS_SUCCESS`/`STATUS_ERROR` etc.)
//     — und der Dispatcher referenziert exakt diese Konstanten.
//
//     Loesung: Wir eval()-en in `#[RunInSeparateProcess]`-Tests stub-Klassen
//     mit identischer Public-API (Konstanten + statische Methoden), die
//     Aufrufe in statische Arrays sammeln. Das umgeht den Overload-Generator
//     komplett und gibt uns vollstaendige Kontrolle ueber Argumente +
//     Rueckgabewerte. Die echten Klassen werden im separaten Process NIEMALS
//     autoloaded, weil unsere eval()-Stubs sie unter demselben FQN deklarieren.
//
//   - `wc_get_logger()` liefert ein `LoggerSpy`, das `info`/`warning`/`error`/
//     `log`-Aufrufe sammelt — Tests assertieren auf Source-String + Format.
//   - `Plugin::init()`-Tests (AC-1) laufen in-process und nutzen Brain\Monkey's
//     `Actions\has()` zur Hook-Inspektion.
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use RuntimeException;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Bootstrap\Plugin;

	/**
	 * Logger-Spy fuer `wc_get_logger()`. Sammelt alle Log-Aufrufe in einem
	 * Instance-Array, das pro Test im setUp() neu erzeugt wird. Implementiert
	 * `info`/`warning`/`error`/`log` damit der Dispatcher und der
	 * recordFailedOp-Stub beide Methoden-Familien aufrufen koennen.
	 */
	final class Slice17LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		public array $entries = [];

		public function log( string $level, string $message, array $context = [] ): void
		{
			$this->entries[] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];
		}

		public function info( string $message, array $context = [] ): void
		{
			$this->log( 'info', $message, $context );
		}

		public function warning( string $message, array $context = [] ): void
		{
			$this->log( 'warning', $message, $context );
		}

		public function error( string $message, array $context = [] ): void
		{
			$this->log( 'error', $message, $context );
		}

		public function debug( string $message, array $context = [] ): void
		{
			$this->log( 'debug', $message, $context );
		}

		/**
		 * @return list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		public function entriesForSource( string $source ): array
		{
			$out = [];
			foreach ( $this->entries as $entry ) {
				if ( ( $entry['context']['source'] ?? null ) === $source ) {
					$out[] = $entry;
				}
			}
			return $out;
		}
	}

	/**
	 * Slice 17 — Process-Webhook-Event-Job (Dispatcher) Acceptance Tests.
	 *
	 * Jeder Test mappt 1:1 auf ein GIVEN/WHEN/THEN aus der Slice-Spec
	 * (`docs/.../slice-17-process-webhook-event-job.md`).
	 *
	 * Tests, die den `ProcessWebhookEventJob::handle()`-Pfad exerzieren,
	 * laufen in `#[RunInSeparateProcess]`, weil dort eval()-Stubs fuer
	 * `WebhookLogRepo` / `OrderEventHandler` / `ArticleEventHandler` deklariert
	 * werden, BEVOR die SUT-Klasse autoloaded wird (sonst greifen die
	 * statischen Aufrufe an die echten finalen Klassen durch).
	 */
	final class Slice17ProcessWebhookEventJobTest extends TestCase
	{
		private Slice17LoggerSpy $loggerSpy;

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->loggerSpy = new Slice17LoggerSpy();

			// ---- i18n passthrough ------------------------------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );

			// ---- wc_get_logger() returns the spy ---------------------------
			$spy = $this->loggerSpy;
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ): Slice17LoggerSpy {
				return $spy;
			} );

			// Reset Plugin-internal state so AC-1 idempotency tests stay clean.
			$this->resetPluginState();
		}

		protected function tearDown(): void
		{
			$this->resetPluginState();
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private function resetPluginState(): void
		{
			$pluginFqcn = Plugin::class;
			if ( class_exists( $pluginFqcn ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$ref->getProperty( 'initialized' )->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$ref->getProperty( 'pluginFile' )->setValue( null, '' );
				}
			}
		}

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		private static function pluginMainFile(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
		}

		/**
		 * Eval the three Webhook-namespace stubs (Repo + Order/Article
		 * handlers) BEFORE the SUT autoload kicks in.
		 *
		 * The stub classes:
		 *   - `WebhookLogRepo`: holds the `STATUS_*` constants the dispatcher
		 *     references; its static `find()` reads from a programmable
		 *     `$findReturns` map and `updateProcessingStatus()` records all
		 *     calls in `$updateCalls`.
		 *   - `OrderEventHandler` / `ArticleEventHandler`: each has a static
		 *     `$throwOnHandle` switch (an `\Throwable` instance to throw) and
		 *     a `$calls` log of `[$payload, ...]` invocations.
		 *
		 * Each stub is a `final class` with the same public surface as the
		 * production code (signatures + return types) so the Dispatcher's
		 * static call sites resolve identically. Tests configure the stubs
		 * directly via static-property writes — no Mockery `overload:` needed.
		 *
		 * Idempotent: re-declaration is gated via `class_exists(..., false)`
		 * so #[RunInSeparateProcess] re-loads do not blow up on duplicate
		 * declarations (the bootstrap is replayed per process).
		 */
		private function declareWebhookStubs(): void
		{
			$webhookNs = 'SpreadconnectPod\\Webhook';

			if ( ! class_exists( $webhookNs . '\\WebhookLogRepo', false ) ) {
				eval(
					'namespace SpreadconnectPod\\Webhook;
					final class WebhookLogRepo {
						public const STATUS_PENDING   = "pending";
						public const STATUS_SUCCESS   = "success";
						public const STATUS_ERROR     = "error";
						public const STATUS_DUPLICATE = "duplicate";
						public const STATUS_INSERTED  = "inserted";
						public const ENTITY_TYPE_ORDER = "order";

						/** @var array<int, array<string,mixed>|null> */
						public static array $findReturns = [];
						/** @var list<array{0:int,1:string,2:?string}> */
						public static array $updateCalls = [];
						/** @var list<int> */
						public static array $findCalls = [];

						public static function reset(): void {
							self::$findReturns = [];
							self::$updateCalls = [];
							self::$findCalls   = [];
						}

						public static function find( int $logId ): ?array {
							self::$findCalls[] = $logId;
							if ( array_key_exists( $logId, self::$findReturns ) ) {
								return self::$findReturns[ $logId ];
							}
							return null;
						}

						public static function updateProcessingStatus(
							int $logId,
							string $status,
							?string $error = null
						): void {
							self::$updateCalls[] = [ $logId, $status, $error ];
						}

						/* unused in slice-17 — defensive no-ops so callers never
						   accidentally crash if the dispatcher grows future
						   helper-method calls before slice-37 lands. */
						public static function insertOrIgnore( array $row ): array {
							return [ "status" => self::STATUS_INSERTED, "log_id" => 0 ];
						}
						public static function findRecentForOrder( string $id, int $limit = 5 ): array {
							return [];
						}
					}'
				);
			}

			if ( ! class_exists( $webhookNs . '\\OrderEventHandler', false ) ) {
				eval(
					'namespace SpreadconnectPod\\Webhook;
					final class OrderEventHandler {
						/** @var list<array<string,mixed>> */
						public static array $calls = [];
						public static ?\\Throwable $throwOnHandle = null;

						public static function reset(): void {
							self::$calls = [];
							self::$throwOnHandle = null;
						}

						public static function handle( array $payload ): void {
							self::$calls[] = $payload;
							if ( null !== self::$throwOnHandle ) {
								throw self::$throwOnHandle;
							}
						}
					}'
				);
			}

			if ( ! class_exists( $webhookNs . '\\ArticleEventHandler', false ) ) {
				eval(
					'namespace SpreadconnectPod\\Webhook;
					final class ArticleEventHandler {
						/** @var list<array<string,mixed>> */
						public static array $calls = [];
						public static ?\\Throwable $throwOnHandle = null;

						public static function reset(): void {
							self::$calls = [];
							self::$throwOnHandle = null;
						}

						public static function handle( array $payload ): void {
							self::$calls[] = $payload;
							if ( null !== self::$throwOnHandle ) {
								throw self::$throwOnHandle;
							}
						}
					}'
				);
			}

			// Per-test reset on the stub static state.
			\SpreadconnectPod\Webhook\WebhookLogRepo::reset();
			\SpreadconnectPod\Webhook\OrderEventHandler::reset();
			\SpreadconnectPod\Webhook\ArticleEventHandler::reset();
		}

		/**
		 * Build the canonical webhook-log row shape returned by
		 * {@see WebhookLogRepo::find()}.
		 *
		 * @param array<string,mixed> $payload Decoded payload to JSON-encode
		 *                                     into the row.
		 * @return array<string,mixed>
		 */
		private static function makeRow(
			int $logId,
			array $payload,
			string $hmacStatus = 'valid',
			string $processingStatus = 'pending'
		): array {
			return [
				'id'                  => $logId,
				'event_type'          => (string) ( $payload['eventType'] ?? '' ),
				'event_id'            => 'sha256-' . str_pad( (string) $logId, 8, '0', STR_PAD_LEFT ),
				'related_entity_type' => null,
				'related_entity_id'   => null,
				'payload'             => json_encode( $payload ),
				'hmac_status'         => $hmacStatus,
				'processing_status'   => $processingStatus,
				'processing_error'    => null,
				'received_at'         => '2026-05-04 12:00:00',
			];
		}

		/**
		 * Build a row whose `payload` is the literal raw JSON string supplied
		 * (used to inject malformed JSON for AC-9).
		 */
		private static function makeRawRow(
			int $logId,
			string $rawPayload,
			string $processingStatus = 'pending'
		): array {
			return [
				'id'                  => $logId,
				'event_type'          => 'unknown',
				'event_id'            => 'sha256-raw-' . $logId,
				'related_entity_type' => null,
				'related_entity_id'   => null,
				'payload'             => $rawPayload,
				'hmac_status'         => 'valid',
				'processing_status'   => $processingStatus,
				'processing_error'    => null,
				'received_at'         => '2026-05-04 12:00:00',
			];
		}

		// ===================================================================
		// AC-1: Plugin::init() registriert add_action('spreadconnect/process_webhook_event',
		//        [ProcessWebhookEventJob, 'handle'], 10, 1).
		// ===================================================================

		/**
		 * AC-1: GIVEN Plugin::init() laeuft beim Plugin-Load
		 *        WHEN die Hook-Registrierung laeuft
		 *        THEN registriert sie add_action('spreadconnect/process_webhook_event',
		 *             [ProcessWebhookEventJob::class, 'handle'], 10, 1).
		 */
		public function test_plugin_init_registers_process_webhook_event_action(): void
		{
			$processJobFqcn = 'SpreadconnectPod\\Webhook\\ProcessWebhookEventJob';

			self::assertFalse(
				Actions\has( 'spreadconnect/process_webhook_event' ),
				'AC-1 (precondition): no listener for the AS hook before init().'
			);

			Plugin::init( self::pluginMainFile() );

			$priority = Actions\has(
				'spreadconnect/process_webhook_event',
				[ $processJobFqcn, 'handle' ]
			);

			$this->assertNotFalse(
				$priority,
				'AC-1: Plugin::init() MUSS add_action("spreadconnect/process_webhook_event", '
				. '[ProcessWebhookEventJob::class, "handle"], 10, 1) registrieren.'
			);
			$this->assertSame(
				10,
				$priority,
				'AC-1: Hook-Priority MUSS exakt 10 sein (architecture.md Z. 553).'
			);
		}

		/**
		 * AC-1: GIVEN Plugin::init() ist bereits einmal aufgerufen
		 *        WHEN init() ein zweites Mal laeuft
		 *        THEN registriert es den process_webhook_event-Listener nicht doppelt
		 *             (Idempotenz-Guard aus Slice 02 AC-5 muss greifen).
		 */
		public function test_plugin_init_is_idempotent_for_action_registration(): void
		{
			$processJobFqcn = 'SpreadconnectPod\\Webhook\\ProcessWebhookEventJob';

			Plugin::init( self::pluginMainFile() );
			$priorityFirst = Actions\has(
				'spreadconnect/process_webhook_event',
				[ $processJobFqcn, 'handle' ]
			);

			Plugin::init( self::pluginMainFile() );
			$prioritySecond = Actions\has(
				'spreadconnect/process_webhook_event',
				[ $processJobFqcn, 'handle' ]
			);

			$this->assertSame(
				$priorityFirst,
				$prioritySecond,
				'AC-1: Doppelter init()-Call DARF die Hook-Registrierung NICHT '
				. 'duplizieren (Idempotenz-Guard aus Slice 02 AC-5).'
			);

			// Defense-in-depth: count registrations explicitly via HookStorage.
			$count = self::countRegistrationsForActionHook(
				'spreadconnect/process_webhook_event',
				[ $processJobFqcn, 'handle' ]
			);

			if ( -1 !== $count ) {
				$this->assertSame(
					1,
					$count,
					'AC-1: Doppelter Plugin::init() DARF den process_webhook_event-Hook '
					. 'GENAU EINMAL registrieren — has_action() liefert sonst eine '
					. 'falsche Priority-Auskunft.'
				);
			}
		}

		// ===================================================================
		// AC-2: find -> dispatch (Order.* -> OrderEventHandler) ->
		//        updateProcessingStatus('success', null).
		// ===================================================================

		/**
		 * AC-2: GIVEN existierende Log-Row mit pending-status und gueltigem
		 *             JSON-payload mit eventType + data.entity
		 *        WHEN ProcessWebhookEventJob::handle($logId) laeuft
		 *        THEN find($logId) -> dispatch -> updateProcessingStatus('success', null).
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_handle_dispatches_and_marks_success(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-42' ] ],
			];

			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[42] = self::makeRow( 42, $payload );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 42 );

			// AC-2: find() wurde mit logId=42 aufgerufen.
			$this->assertSame(
				[ 42 ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$findCalls,
				'AC-2: WebhookLogRepo::find($logId) MUSS exakt einmal mit log_id=42 aufgerufen werden.'
			);

			// AC-2: OrderEventHandler::handle($payload) wurde aufgerufen.
			$this->assertSame(
				[ $payload ],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-2: OrderEventHandler::handle($payload) MUSS mit dem dekodierten '
				. 'payload aufgerufen werden.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-2: ArticleEventHandler DARF NICHT aufgerufen werden (Order.* matcht).'
			);

			// AC-2: updateProcessingStatus(42, 'success', null).
			$this->assertSame(
				[ [ 42, 'success', null ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-2: updateProcessingStatus MUSS GENAU einmal mit (42, "success", null) '
				. 'aufgerufen werden.'
			);
		}

		// ===================================================================
		// AC-3: find() liefert null -> Warning-Log + early Return; keine update-Call,
		//        keine Exception.
		// ===================================================================

		/**
		 * AC-3: GIVEN find($logId) liefert null (Row geloescht oder log_id ungueltig)
		 *        WHEN handle() laeuft
		 *        THEN keine Exception, Warning-Log mit "log_id={N} not found",
		 *             KEIN updateProcessingStatus-Call.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_handle_returns_early_on_missing_row(): void
		{
			$this->declareWebhookStubs();

			// findReturns ist leer => find(999) -> null.
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns = [];

			// MUST NOT throw — AS retry would be counterproductive (row is gone).
			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 999 );

			$this->assertSame(
				[ 999 ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$findCalls,
				'AC-3: find($logId) MUSS einmal aufgerufen werden, BEVOR der Job frueh returned.'
			);

			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-3: updateProcessingStatus DARF NICHT aufgerufen werden — es gibt keine Row.'
			);

			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-3: OrderEventHandler DARF NICHT aufgerufen werden bei missing row.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-3: ArticleEventHandler DARF NICHT aufgerufen werden bei missing row.'
			);

			// AC-3: Warning log mit "log_id=999 not found" + source webhook-receiver.
			$entries = $this->loggerSpy->entries;
			$matched = false;
			foreach ( $entries as $entry ) {
				if (
					$entry['level'] === 'warning'
					&& str_contains( $entry['message'], 'log_id=999' )
					&& str_contains( $entry['message'], 'not found' )
					&& ( $entry['context']['source'] ?? '' ) === 'spreadconnect-webhook-receiver'
				) {
					$matched = true;
					break;
				}
			}
			$this->assertTrue(
				$matched,
				'AC-3: bei find()=null MUSS eine warning-Log-Zeile mit Source '
				. '"spreadconnect-webhook-receiver" und Format "log_id=999 not found" '
				. 'geschrieben werden. Got: ' . json_encode( $entries )
			);
		}

		// ===================================================================
		// AC-4: prefix-match dispatch (Order.* / Article.* / Shipment.*).
		// ===================================================================

		/**
		 * AC-4: GIVEN eventType="Order.processed"
		 *        WHEN dispatch laeuft
		 *        THEN OrderEventHandler::handle($payload) wird aufgerufen.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_dispatch_routes_order_prefix_to_order_event_handler(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-1' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[1] = self::makeRow( 1, $payload );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 1 );

			$this->assertSame(
				[ $payload ],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-4: Order.processed MUSS auf OrderEventHandler::handle gemapped werden.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-4: ArticleEventHandler DARF bei Order.* NICHT getriggert werden.'
			);
			$this->assertSame(
				[ [ 1, 'success', null ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-4: Bei erfolgreichem Order.*-Dispatch MUSS status auf "success" flippen.'
			);
		}

		/**
		 * AC-4: GIVEN eventType="Article.added"
		 *        WHEN dispatch laeuft
		 *        THEN ArticleEventHandler::handle($payload) wird aufgerufen.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_dispatch_routes_article_prefix_to_article_event_handler(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Article.added',
				'data'      => [ 'entity' => [ 'id' => 'ART-1' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[7] = self::makeRow( 7, $payload );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 7 );

			$this->assertSame(
				[ $payload ],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-4: Article.added MUSS auf ArticleEventHandler::handle gemapped werden.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-4: OrderEventHandler DARF bei Article.* NICHT getriggert werden.'
			);
			$this->assertSame(
				[ [ 7, 'success', null ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-4: Bei erfolgreichem Article.*-Dispatch MUSS status auf "success" flippen.'
			);
		}

		/**
		 * AC-4: GIVEN eventType="Shipment.sent"
		 *        WHEN dispatch laeuft
		 *        THEN OrderEventHandler::handle($payload) wird aufgerufen
		 *             (architecture.md Z. 381 — Shipment.* gehoert zur Order-Domain).
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_dispatch_routes_shipment_prefix_to_order_event_handler(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Shipment.sent',
				'data'      => [ 'entity' => [ 'id' => 'SHP-1' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[11] = self::makeRow( 11, $payload );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 11 );

			// Shipment.* MUSS in der Order-Domain landen (NICHT Article).
			$this->assertSame(
				[ $payload ],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-4: Shipment.* MUSS auf OrderEventHandler::handle gemapped werden '
				. '(architecture.md Z. 381 — Shipment.sent ist Order-Domain).'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-4: ArticleEventHandler DARF NICHT fuer Shipment.* getriggert werden.'
			);
			$this->assertSame(
				[ [ 11, 'success', null ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-4: Bei erfolgreichem Shipment.*-Dispatch MUSS status auf "success" flippen.'
			);
		}

		// ===================================================================
		// AC-5: Unknown eventType -> updateProcessingStatus('error', 'unknown_event_type'),
		//        kein Re-Throw.
		// ===================================================================

		/**
		 * AC-5: GIVEN eventType passt zu KEINEM der drei bekannten Prefixes
		 *             (z. B. "Foo.bar")
		 *        WHEN handle() laeuft
		 *        THEN updateProcessingStatus($logId, 'error', 'unknown_event_type'),
		 *             error-Log, KEIN handler-dispatch, KEIN Re-Throw.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unknown_event_type_writes_error_status(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Foo.bar',
				'data'      => [ 'entity' => [ 'id' => 'X' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[5] = self::makeRow( 5, $payload );

			// MUST NOT re-throw — permanent error.
			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 5 );

			$this->assertSame(
				[ [ 5, 'error', 'unknown_event_type' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-5: updateProcessingStatus MUSS GENAU einmal mit '
				. '(5, "error", "unknown_event_type") aufgerufen werden.'
			);

			// Handlers MUST NOT be invoked.
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-5: OrderEventHandler DARF NICHT bei unknown_event_type triggern.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-5: ArticleEventHandler DARF NICHT bei unknown_event_type triggern.'
			);

			// AC-5: error-log enthaelt "unknown event_type=Foo.bar log_id=5".
			$matched = false;
			foreach ( $this->loggerSpy->entries as $entry ) {
				if (
					$entry['level'] === 'error'
					&& str_contains( $entry['message'], 'unknown' )
					&& str_contains( $entry['message'], 'Foo.bar' )
					&& str_contains( $entry['message'], 'log_id=5' )
					&& ( $entry['context']['source'] ?? '' ) === 'spreadconnect-webhook-receiver'
				) {
					$matched = true;
					break;
				}
			}
			$this->assertTrue(
				$matched,
				'AC-5: error-Log MUSS Format "unknown event_type=Foo.bar log_id=5" '
				. 'mit Source "spreadconnect-webhook-receiver" enthalten. '
				. 'Got: ' . json_encode( $this->loggerSpy->entries )
			);
		}

		// ===================================================================
		// AC-6: Generische \Throwable -> updateProcessingStatus('error', message)
		//        + recordFailedOp-Stub (wc_get_logger source=spreadconnect-failure);
		//        kein Re-Throw.
		// ===================================================================

		/**
		 * AC-6: GIVEN Domain-Handler wirft generische \Throwable
		 *        WHEN handle() den Throwable faengt
		 *        THEN updateProcessingStatus('error', $exception->getMessage()),
		 *             error-Log mit context['exception'], recordFailedOp-Stub
		 *             (source=spreadconnect-failure), KEIN Re-Throw.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_handler_throwable_writes_error_and_invokes_failed_ops_stub(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-X' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[60] = self::makeRow( 60, $payload );

			$boom = new RuntimeException( 'unexpected handler failure' );
			\SpreadconnectPod\Webhook\OrderEventHandler::$throwOnHandle = $boom;

			// MUST NOT re-throw — generic Throwable is permanent.
			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 60 );

			$this->assertSame(
				[ [ 60, 'error', 'unexpected handler failure' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-6: updateProcessingStatus MUSS mit (60, "error", $exception->getMessage()) aufgerufen werden.'
			);

			// AC-6: error-Log mit Source=webhook-receiver + exception-Klasse im Context.
			$dispatcherErrors = $this->loggerSpy->entriesForSource( 'spreadconnect-webhook-receiver' );
			$dispatcherMatch  = false;
			foreach ( $dispatcherErrors as $entry ) {
				if (
					$entry['level'] === 'error'
					&& str_contains( $entry['message'], 'log_id=60' )
					&& ( $entry['context']['exception'] ?? '' ) === RuntimeException::class
				) {
					$dispatcherMatch = true;
					break;
				}
			}
			$this->assertTrue(
				$dispatcherMatch,
				'AC-6: dispatcher MUSS error-Log mit log_id=60 + exception-Klasse '
				. 'in context schreiben. Got: ' . json_encode( $this->loggerSpy->entries )
			);

			// AC-6: recordFailedOp-Stub schreibt zusaetzlich auf Source=spreadconnect-failure.
			$failureLogs = $this->loggerSpy->entriesForSource( 'spreadconnect-failure' );
			$this->assertNotEmpty(
				$failureLogs,
				'AC-6: recordFailedOp-Stub MUSS einen Log-Eintrag mit Source '
				. '"spreadconnect-failure" produzieren (Slice-37-Placeholder).'
			);
			$failureMatch = false;
			foreach ( $failureLogs as $entry ) {
				if (
					$entry['level'] === 'error'
					&& str_contains( $entry['message'], 'handle_webhook' )
					&& ( $entry['context']['op_type'] ?? '' ) === 'handle_webhook'
				) {
					$failureMatch = true;
					break;
				}
			}
			$this->assertTrue(
				$failureMatch,
				'AC-6: recordFailedOp-Stub MUSS op_type="handle_webhook" referenzieren '
				. '(architecture.md Z. 723). Got: ' . json_encode( $failureLogs )
			);
		}

		/**
		 * AC-6: GIVEN SpreadconnectClientError (4xx, permanent) wird vom Handler geworfen
		 *        WHEN handle() faengt die Exception
		 *        THEN updateProcessingStatus('error', message) + recordFailedOp-Stub,
		 *             KEIN Re-Throw (Action-Scheduler retried NICHT).
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_client_error_is_permanent_and_invokes_failed_ops_stub(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Article.added',
				'data'      => [ 'entity' => [ 'id' => 'ART-X' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[71] = self::makeRow( 71, $payload );

			$clientErr = new SpreadconnectClientError(
				'http_4xx',
				'validation failed (article missing)',
				400,
				'/articles/ART-X'
			);
			\SpreadconnectPod\Webhook\ArticleEventHandler::$throwOnHandle = $clientErr;

			$caught = null;
			try {
				\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 71 );
			} catch ( \Throwable $e ) {
				$caught = $e;
			}
			$this->assertNull(
				$caught,
				'AC-6: SpreadconnectClientError DARF NICHT re-thrown werden — '
				. 'Action-Scheduler retried bei permanenten 4xx NICHT.'
			);

			$this->assertSame(
				[ [ 71, 'error', 'validation failed (article missing)' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-6: updateProcessingStatus MUSS bei SpreadconnectClientError mit '
				. '($logId, "error", $exception->getMessage()) aufgerufen werden.'
			);

			// recordFailedOp-Stub schreibt auf Source=spreadconnect-failure.
			$failureLogs = $this->loggerSpy->entriesForSource( 'spreadconnect-failure' );
			$matched     = false;
			foreach ( $failureLogs as $entry ) {
				if (
					$entry['level'] === 'error'
					&& ( $entry['context']['op_type'] ?? '' ) === 'handle_webhook'
					&& ( $entry['context']['exception'] ?? '' ) === SpreadconnectClientError::class
				) {
					$matched = true;
					break;
				}
			}
			$this->assertTrue(
				$matched,
				'AC-6: recordFailedOp-Stub MUSS bei SpreadconnectClientError '
				. 'einen Log mit op_type=handle_webhook + exception-Klasse=' . SpreadconnectClientError::class
				. ' schreiben. Got: ' . json_encode( $failureLogs )
			);
		}

		// ===================================================================
		// AC-7: SpreadconnectTransientError -> updateProcessingStatus('error', ...)
		//        + Re-Throw fuer AS-Retry; KEIN recordFailedOp-Stub.
		// ===================================================================

		/**
		 * AC-7: GIVEN Domain-Handler wirft SpreadconnectTransientError (5xx/Network)
		 *        WHEN handle() faengt die Exception
		 *        THEN updateProcessingStatus('error', message) UND re-throw
		 *             (Action-Scheduler retried 1m/5m/15m).
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_transient_error_updates_status_and_rethrows(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-T' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[80] = self::makeRow( 80, $payload );

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'spreadconnect upstream 503',
				503,
				'/orders/ORD-T'
			);
			\SpreadconnectPod\Webhook\OrderEventHandler::$throwOnHandle = $transient;

			$caught = null;
			try {
				\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 80 );
			} catch ( \Throwable $e ) {
				$caught = $e;
			}

			$this->assertNotNull(
				$caught,
				'AC-7: SpreadconnectTransientError MUSS re-thrown werden, damit '
				. 'Action-Scheduler den Job 1m/5m/15m retryt.'
			);
			$this->assertInstanceOf(
				SpreadconnectTransientError::class,
				$caught,
				'AC-7: re-thrown exception MUSS dieselbe SpreadconnectTransientError-'
				. 'Instanz sein (Identitaet erhalten).'
			);
			$this->assertSame(
				$transient,
				$caught,
				'AC-7: re-thrown Exception MUSS exakt die urspruengliche Instanz sein.'
			);

			$this->assertSame(
				[ [ 80, 'error', 'spreadconnect upstream 503' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-7: updateProcessingStatus MUSS bei TransientError VOR dem Re-Throw '
				. 'mit (80, "error", $message) aufgerufen werden.'
			);
		}

		/**
		 * AC-7: GIVEN Transient-Error-Pfad
		 *        WHEN handle() faengt + re-throwt
		 *        THEN recordFailedOp-Stub wird NICHT aufgerufen
		 *             (Slice-37 RetryPolicyListener uebernimmt nach 3-Retries).
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_transient_error_does_not_invoke_failed_ops_stub(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-T2' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[81] = self::makeRow( 81, $payload );

			$transient = new SpreadconnectTransientError(
				'network_error',
				'connection refused',
				null,
				'/orders/ORD-T2'
			);
			\SpreadconnectPod\Webhook\OrderEventHandler::$throwOnHandle = $transient;

			try {
				\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 81 );
				$this->fail( 'AC-7: handle() MUSS die TransientError re-throwen.' );
			} catch ( SpreadconnectTransientError $e ) {
				// expected — re-thrown.
			}

			// AC-7: KEIN recordFailedOp-Stub-Log auf Source=spreadconnect-failure.
			$failureLogs = $this->loggerSpy->entriesForSource( 'spreadconnect-failure' );
			$this->assertSame(
				[],
				$failureLogs,
				'AC-7: Transient-Pfad DARF recordFailedOp-Stub NICHT triggern — '
				. 'Slice-37 RetryPolicyListener entscheidet nach 3 Retries. '
				. 'Got: ' . json_encode( $failureLogs )
			);
		}

		// ===================================================================
		// AC-8: Re-Run einer bereits 'success'-Row -> normale Verarbeitung
		//        (KEIN early Return, KEINE Idempotenz-Pruefung im Job).
		// ===================================================================

		/**
		 * AC-8: GIVEN eine Row mit processing_status='success' (Re-Run nach manueller
		 *             Wiederholung)
		 *        WHEN handle() startet
		 *        THEN verarbeitet die Row trotzdem normal — kein vorzeitiger Return.
		 *             Idempotenz-Barriere liegt im Domain-Handler (Slice 25/30 CAS),
		 *             NICHT im Dispatcher.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_handle_does_not_short_circuit_on_already_processed_row(): void
		{
			$this->declareWebhookStubs();

			$payload = [
				'eventType' => 'Order.processed',
				'data'      => [ 'entity' => [ 'id' => 'ORD-RR' ] ],
			];
			// Row ist bereits 'success' (Bulk-Resend in Slice 40).
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[90] =
				self::makeRow( 90, $payload, 'valid', 'success' );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 90 );

			// AC-8: Handler MUSS aufgerufen werden — kein early-Return.
			$this->assertSame(
				[ $payload ],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-8: Bei processing_status="success" MUSS der Handler trotzdem '
				. 'invoziert werden — der Dispatcher ist dumm; Idempotenz liegt '
				. 'im Domain-Handler (Slice 25/30 CAS).'
			);

			// AC-8: trotzdem updateProcessingStatus aufrufen (Re-Run ist gueltig).
			$this->assertSame(
				[ [ 90, 'success', null ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-8: updateProcessingStatus MUSS auch bei bereits-success-Row erneut '
				. 'aufgerufen werden (mehrfaches Update ist per Slice 16 AC-11 erlaubt).'
			);
		}

		// ===================================================================
		// AC-9: Invalid JSON / missing eventType -> 'invalid_payload'-Error,
		//        kein Handler-Dispatch, kein Re-Throw.
		// ===================================================================

		/**
		 * AC-9: GIVEN payload ist nicht-JSON-decodierbar
		 *        WHEN dispatcher Pflichtfelder pruefen will
		 *        THEN updateProcessingStatus('error', 'invalid_payload'),
		 *             warning-Log mit "payload_preview=..." (first 200 chars),
		 *             KEIN Handler-Dispatch, KEIN Re-Throw.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_invalid_json_payload_writes_invalid_payload_error(): void
		{
			$this->declareWebhookStubs();

			$rawPayload = 'this-is-not-{valid-json}';
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[100] =
				self::makeRawRow( 100, $rawPayload );

			// Kein Re-Throw (permanent error).
			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 100 );

			$this->assertSame(
				[ [ 100, 'error', 'invalid_payload' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-9: updateProcessingStatus MUSS bei malformed JSON mit '
				. '(100, "error", "invalid_payload") aufgerufen werden.'
			);

			// Kein Handler-Dispatch.
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-9: OrderEventHandler DARF bei invalid_payload NICHT aufgerufen werden.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-9: ArticleEventHandler DARF bei invalid_payload NICHT aufgerufen werden.'
			);

			// AC-9: warning-Log mit "log_id=100 payload_preview=...".
			$matched = false;
			foreach ( $this->loggerSpy->entries as $entry ) {
				if (
					$entry['level'] === 'warning'
					&& str_contains( $entry['message'], 'invalid payload' )
					&& str_contains( $entry['message'], 'log_id=100' )
					&& str_contains( $entry['message'], 'payload_preview=' )
					&& ( $entry['context']['source'] ?? '' ) === 'spreadconnect-webhook-receiver'
				) {
					$matched = true;
					break;
				}
			}
			$this->assertTrue(
				$matched,
				'AC-9: warning-Log MUSS Format "invalid payload log_id=100 '
				. 'payload_preview=..." haben. Got: ' . json_encode( $this->loggerSpy->entries )
			);
		}

		/**
		 * AC-9: GIVEN decodierter payload ohne eventType-Key
		 *        WHEN dispatcher Pflichtfelder pruefen will
		 *        THEN updateProcessingStatus('error', 'invalid_payload') —
		 *             gleiche permanent-error Behandlung wie malformed JSON.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_payload_without_event_type_writes_invalid_payload_error(): void
		{
			$this->declareWebhookStubs();

			// Valides JSON-Object — aber OHNE eventType.
			$payload = [
				'data' => [ 'entity' => [ 'id' => 'X' ] ],
			];
			\SpreadconnectPod\Webhook\WebhookLogRepo::$findReturns[101] =
				self::makeRawRow( 101, json_encode( $payload ) );

			\SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle( 101 );

			$this->assertSame(
				[ [ 101, 'error', 'invalid_payload' ] ],
				\SpreadconnectPod\Webhook\WebhookLogRepo::$updateCalls,
				'AC-9: Decoded payload OHNE eventType-Key MUSS denselben '
				. '"invalid_payload"-Error wie malformed JSON produzieren.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\OrderEventHandler::$calls,
				'AC-9: Handler DARF bei missing eventType NICHT aufgerufen werden.'
			);
			$this->assertSame(
				[],
				\SpreadconnectPod\Webhook\ArticleEventHandler::$calls,
				'AC-9: Handler DARF bei missing eventType NICHT aufgerufen werden.'
			);
		}

		// ===================================================================
		// AC-10: Stub-Handler-Klassen-Signatur (final, public static handle(array): void).
		// ===================================================================

		/**
		 * AC-10: GIVEN OrderEventHandler / ArticleEventHandler werden in dieser
		 *              Slice angelegt (Stubs in Slice 17, full impl in Slice 25/30)
		 *         WHEN der Dispatcher sie aufruft
		 *         THEN beide Klassen sind `final class` mit public static
		 *              `handle(array $payload): void`-Signatur und liegen in
		 *              `includes/Webhook/`. Class-shape ist Vertrag fuer Slice 25/30.
		 */
		public function test_stub_handlers_log_info_and_return_void(): void
		{
			$orderRefl   = new ReflectionClass( 'SpreadconnectPod\\Webhook\\OrderEventHandler' );
			$articleRefl = new ReflectionClass( 'SpreadconnectPod\\Webhook\\ArticleEventHandler' );

			// Final class — Job-Pattern.
			$this->assertTrue(
				$orderRefl->isFinal(),
				'AC-10: OrderEventHandler MUSS `final class` sein (Job-Pattern, '
				. 'architecture.md Z. 532).'
			);
			$this->assertTrue(
				$articleRefl->isFinal(),
				'AC-10: ArticleEventHandler MUSS `final class` sein.'
			);

			// Methode handle() ist public static, void-return, ein array-Arg.
			foreach (
				[
					'OrderEventHandler'   => $orderRefl,
					'ArticleEventHandler' => $articleRefl,
				] as $name => $refl
			) {
				$this->assertTrue(
					$refl->hasMethod( 'handle' ),
					sprintf( 'AC-10: %s MUSS Methode handle() besitzen.', $name )
				);
				$method = $refl->getMethod( 'handle' );
				$this->assertTrue(
					$method->isPublic(),
					sprintf( 'AC-10: %s::handle MUSS public sein.', $name )
				);
				$this->assertTrue(
					$method->isStatic(),
					sprintf( 'AC-10: %s::handle MUSS static sein (Job-Pattern).', $name )
				);

				$params = $method->getParameters();
				$this->assertCount(
					1,
					$params,
					sprintf( 'AC-10: %s::handle MUSS GENAU einen Parameter haben.', $name )
				);
				$paramType = $params[0]->getType();
				$this->assertNotNull( $paramType );
				$this->assertSame(
					'array',
					(string) $paramType,
					sprintf( 'AC-10: %s::handle($payload) MUSS array-typed sein.', $name )
				);

				$returnType = $method->getReturnType();
				$this->assertNotNull(
					$returnType,
					sprintf( 'AC-10: %s::handle MUSS return-type-deklariert sein.', $name )
				);
				$this->assertSame(
					'void',
					(string) $returnType,
					sprintf( 'AC-10: %s::handle MUSS void-return-Type haben.', $name )
				);
			}

			// AC-10: Beide Dateien liegen in includes/Webhook/.
			$orderFile = $orderRefl->getFileName();
			$this->assertNotFalse( $orderFile );
			$this->assertStringContainsString(
				'/includes/Webhook/OrderEventHandler.php',
				str_replace( '\\', '/', (string) $orderFile ),
				'AC-10: OrderEventHandler MUSS in includes/Webhook/ leben.'
			);
			$articleFile = $articleRefl->getFileName();
			$this->assertNotFalse( $articleFile );
			$this->assertStringContainsString(
				'/includes/Webhook/ArticleEventHandler.php',
				str_replace( '\\', '/', (string) $articleFile ),
				'AC-10: ArticleEventHandler MUSS in includes/Webhook/ leben.'
			);
		}

		// ===================================================================
		// Helper: Brain\Monkey HookStorage introspection (mirror of Slice 15).
		// ===================================================================

		/**
		 * Count registrations of a given (hook, callable) pair in
		 * Brain\Monkey's internal HookStorage.
		 *
		 * Returns -1 when the storage shape isn't introspectable (library
		 * version drift); callers should `markTestIncomplete` in that case.
		 *
		 * @param array{0:string,1:string}|callable $targetCallback
		 */
		private static function countRegistrationsForActionHook(
			string $hookName,
			callable|array $targetCallback
		): int {
			$hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
			$refl        = new ReflectionClass( $hookStorage );

			if ( ! $refl->hasProperty( 'storage' ) ) {
				return -1;
			}

			$prop = $refl->getProperty( 'storage' );
			$all  = $prop->getValue( $hookStorage );

			$added   = $all[ \Brain\Monkey\Hook\HookStorage::ADDED ] ?? null;
			$actions = $added[ \Brain\Monkey\Hook\HookStorage::ACTIONS ] ?? null;
			$forHook = $actions[ $hookName ] ?? null;

			if ( ! is_array( $forHook ) ) {
				return 0;
			}

			$targetForm = (string) new \Brain\Monkey\Name\CallbackStringForm( $targetCallback );

			$count = 0;
			foreach ( $forHook as $registration ) {
				if ( ! is_array( $registration ) || ! isset( $registration[0] ) ) {
					continue;
				}
				$cb = $registration[0];
				if ( $cb instanceof \Brain\Monkey\Name\CallbackStringForm
					&& (string) $cb === $targetForm
				) {
					$count++;
				}
			}

			return $count;
		}
	}
}
