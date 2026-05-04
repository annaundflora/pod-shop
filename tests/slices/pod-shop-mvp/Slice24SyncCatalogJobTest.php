<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 24 — Catalog\SyncCatalogJob (Catalog-Sync Producer) + Counter
// Lifecycle on SyncHistoryRepo.
//
// Mocking strategy (per Slice-Spec Z. 28 `mock_external`):
//   - `SpreadconnectClient` is NOT final -> classic `Mockery::mock()`.
//   - `SyncHistoryRepo` is `final` -> we use real instances against an
//      injected `$wpdb` stub. The slice-23 `overload:`-pattern is NOT used
//      here so AC-7 (atomic single-statement counter UPDATEs) can be
//      asserted against the actual repo SQL.
//   - WP functions (`as_enqueue_async_action`, `current_time`,
//     `set_transient`, `get_transient`) via Brain\Monkey aliased.
//
// Each test maps 1:1 to a GIVEN/WHEN/THEN AC. Slice-23 overload-tests live
// in a separate process and are unaffected by this file.
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\Dto\ArticleSummary;
	use SpreadconnectPod\Api\Dto\Variant;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Catalog\SyncCatalogJob;
	use SpreadconnectPod\Catalog\SyncHistoryRepo;

	/**
	 * Slice 24 — Catalog\SyncCatalogJob acceptance tests.
	 *
	 * Acceptance tests against `slice-24-sync-catalog-job.md`. SyncHistoryRepo
	 * is exercised against a {@see SyncCatalogJobFakeWpdb} stub so the AC-7
	 * atomic-UPDATE assertions can read the actual SQL emitted by the repo.
	 */
	final class Slice24SyncCatalogJobTest extends TestCase
	{
		/**
		 * Captures every `as_enqueue_async_action` invocation as
		 * `[hook, args, group]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>,2:string}>
		 */
		private array $enqueuedActions = [];

		/**
		 * Captures every `set_transient` write as `[key, value, ttl]`.
		 *
		 * @var list<array{0:string,1:mixed,2:int}>
		 */
		private array $transientWrites = [];

		/**
		 * Backing store for `get_transient` lookups.
		 *
		 * @var array<string, mixed>
		 */
		private array $transientStore = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->enqueuedActions = [];
			$this->transientWrites = [];
			$this->transientStore  = [];

			$enqueued = & $this->enqueuedActions;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = [], string $group = '' ) use ( &$enqueued ): int {
					$enqueued[] = [ $hook, $args, $group ];
					return count( $enqueued );
				}
			);

			Functions\when( 'current_time' )->alias(
				static fn( string $type = 'mysql' ): string => '2026-05-04 12:00:00'
			);

			$transientStore = & $this->transientStore;
			Functions\when( 'get_transient' )->alias(
				static function ( $key ) use ( &$transientStore ) {
					return $transientStore[ (string) $key ] ?? false;
				}
			);

			$transientWrites = & $this->transientWrites;
			Functions\when( 'set_transient' )->alias(
				static function ( $key, $value, $ttl = 0 ) use ( &$transientWrites, &$transientStore ): bool {
					$transientWrites[]               = [ (string) $key, $value, (int) $ttl ];
					$transientStore[ (string) $key ] = $value;
					return true;
				}
			);

			Functions\when( 'wp_json_encode' )->alias(
				static fn( $value ) => json_encode( $value )
			);
		}

		protected function tearDown(): void
		{
			unset( $GLOBALS['wpdb'] );
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers — DTO + collaborator builders.
		// -------------------------------------------------------------------

		/**
		 * Build an ArticleSummary list of size $count starting at $startId.
		 *
		 * @return ArticleSummary[]
		 */
		private function makeArticleSummaries( int $count, int $startId = 1 ): array
		{
			$out = [];
			for ( $i = 0; $i < $count; $i++ ) {
				$id    = $startId + $i;
				$out[] = new ArticleSummary(
					id: 'ART-' . $id,
					title: 'Article #' . $id,
					productTypeId: 'PT-7',
					variants: [ new Variant( sku: 'SKU-' . $id, sizeId: 'sz-S', colorId: 'co-RED' ) ],
				);
			}
			return $out;
		}

		/**
		 * Wrap an `ArticleSummary[]` into the `getArticles()` paginated-wrap
		 * shape `['items'=>…, 'page'=>…, 'size'=>…, 'total'=>…]`.
		 *
		 * @param ArticleSummary[] $items
		 *
		 * @return array<string,mixed>
		 */
		private function pageResponse( array $items, int $page, int $size ): array
		{
			return [
				'items' => $items,
				'page'  => $page,
				'size'  => $size,
				'total' => count( $items ),
			];
		}

		/**
		 * Install a fresh fake-`$wpdb` and return the SUT pair.
		 *
		 * @return array{0:SyncCatalogJob,1:SpreadconnectClient&\Mockery\MockInterface,2:SyncCatalogJobFakeWpdb}
		 */
		private function buildJob( int $insertId = 42 ): array
		{
			$wpdb           = new SyncCatalogJobFakeWpdb();
			$wpdb->prefix   = 'wp_';
			$wpdb->insert_id = $insertId;
			$GLOBALS['wpdb'] = $wpdb;

			/** @var SpreadconnectClient&\Mockery\MockInterface $client */
			$client = Mockery::mock( SpreadconnectClient::class );

			$repo = new SyncHistoryRepo();
			$job  = new SyncCatalogJob( $client, $repo );

			return [ $job, $client, $wpdb ];
		}

		// ===================================================================
		// AC-1: handle() inserts EXACTLY one history row in state=in_progress
		// with all counters at 0, details='[]', trigger='manual'.
		// ===================================================================

		public function test_handle_inserts_single_history_row_with_in_progress_state(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			// Empty catalog so handle() short-circuits cleanly after the INSERT.
			$client->shouldReceive( 'getArticles' )
				->once()
				->andReturn( $this->pageResponse( [], 1, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertCount(
				1,
				$wpdb->insertCalls,
				'AC-1: $wpdb->insert MUSS exakt 1x aufgerufen werden.'
			);
			$insert = $wpdb->insertCalls[0];
			$this->assertSame(
				'wp_spreadconnect_sync_history',
				$insert['table'],
				'AC-1: INSERT MUSS auf wp_spreadconnect_sync_history erfolgen.'
			);
			$this->assertSame( 'in_progress', $insert['data']['state'] ?? null, 'AC-1: state MUSS = "in_progress" sein.' );
			$this->assertSame( 'manual', $insert['data']['trigger'] ?? null, 'AC-1: trigger MUSS = "manual" sein.' );
			$this->assertSame( 0, $insert['data']['created_count'] ?? null, 'AC-1: created_count MUSS = 0 sein.' );
			$this->assertSame( 0, $insert['data']['updated_count'] ?? null, 'AC-1: updated_count MUSS = 0 sein.' );
			$this->assertSame( 0, $insert['data']['skipped_count'] ?? null, 'AC-1: skipped_count MUSS = 0 sein.' );
			$this->assertSame( 0, $insert['data']['error_count'] ?? null, 'AC-1: error_count MUSS = 0 sein.' );
			$this->assertSame( '[]', $insert['data']['details'] ?? null, 'AC-1: details MUSS leerer JSON-Array "[]" sein.' );
			$this->assertSame(
				'2026-05-04 12:00:00',
				$insert['data']['started_at'] ?? null,
				'AC-1: started_at MUSS aus current_time("mysql") kommen.'
			);
		}

		// ===================================================================
		// AC-2: 50 articles across 2 pages -> 50 sync_article actions enqueued
		// with run_id forwarded to each.
		// ===================================================================

		public function test_handle_enqueues_one_sync_article_action_per_article_with_run_id(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			$page1 = $this->makeArticleSummaries( 50, startId: 1 );  // PAGE_SIZE=50 returned -> continue
			$page2 = $this->makeArticleSummaries( 0, startId: 51 );  // empty sentinel -> terminate

			$client->shouldReceive( 'getArticles' )
				->with( 1, 50 )
				->once()
				->andReturn( $this->pageResponse( $page1, 1, 50 ) );
			$client->shouldReceive( 'getArticles' )
				->with( 2, 50 )
				->once()
				->andReturn( $this->pageResponse( $page2, 2, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertCount(
				50,
				$this->enqueuedActions,
				'AC-2: Genau 50 Articles -> 50 as_enqueue_async_action-Calls.'
			);

			foreach ( $this->enqueuedActions as $idx => $row ) {
				[ $hook, $args, $group ] = $row;
				$this->assertSame( 'spreadconnect/sync_article', $hook, 'AC-2: Hook MUSS "spreadconnect/sync_article" sein.' );
				$this->assertArrayHasKey( 'article_id', $args, 'AC-2: Args MUSS article_id enthalten.' );
				$this->assertArrayHasKey( 'run_id', $args, 'AC-2: Args MUSS run_id enthalten.' );
				$this->assertSame( 42, $args['run_id'], 'AC-2: run_id MUSS = $wpdb->insert_id sein.' );
				$this->assertSame(
					'ART-' . ( $idx + 1 ),
					$args['article_id'],
					'AC-2: Reihenfolge MUSS deterministisch der Server-Reihenfolge entsprechen.'
				);
			}
		}

		// AC-2: Enqueue uses Action-Scheduler group "spreadconnect".
		public function test_handle_uses_spreadconnect_action_scheduler_group(): void
		{
			[ $job, $client ] = $this->buildJob( insertId: 42 );

			$client->shouldReceive( 'getArticles' )
				->once()
				->andReturn( $this->pageResponse( $this->makeArticleSummaries( 3 ), 1, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertCount( 3, $this->enqueuedActions );
			foreach ( $this->enqueuedActions as $row ) {
				$this->assertSame(
					'spreadconnect',
					$row[2],
					'AC-2: Group MUSS "spreadconnect" sein.'
				);
			}
		}

		// ===================================================================
		// AC-3: Total persisted as transient `sc_sync_total_{run_id}`.
		// ===================================================================

		public function test_handle_persists_total_article_count_for_run(): void
		{
			[ $job, $client ] = $this->buildJob( insertId: 42 );

			$articles = $this->makeArticleSummaries( 7 );
			$client->shouldReceive( 'getArticles' )
				->once()
				->andReturn( $this->pageResponse( $articles, 1, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$totalKey = 'sc_sync_total_42';
			$keys     = array_column( $this->transientWrites, 0 );
			$this->assertContains(
				$totalKey,
				$keys,
				'AC-3: Total MUSS via Transient sc_sync_total_{run_id} persistiert werden.'
			);

			$totalWrite = null;
			foreach ( $this->transientWrites as $write ) {
				if ( $write[0] === $totalKey ) {
					$totalWrite = $write;
					break;
				}
			}
			$this->assertNotNull( $totalWrite );
			$this->assertSame( 7, $totalWrite[1], 'AC-3: Total-Wert MUSS = enqueued count sein.' );
			$this->assertSame( 86400, $totalWrite[2], 'AC-3: TTL MUSS 24h (DAY_IN_SECONDS = 86400) sein.' );
		}

		// ===================================================================
		// AC-4: Empty catalog -> NO enqueue, row immediately moved to complete.
		// ===================================================================

		public function test_handle_marks_complete_immediately_when_catalog_is_empty(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			$client->shouldReceive( 'getArticles' )
				->once()
				->andReturn( $this->pageResponse( [], 1, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertSame(
				[],
				$this->enqueuedActions,
				'AC-4: Empty catalog -> KEIN as_enqueue_async_action.'
			);

			$completeQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'complete'" )
					|| str_contains( $sql, 'state = "complete"' )
			);
			$this->assertNotEmpty(
				$completeQueries,
				'AC-4: markComplete MUSS state="complete"-UPDATE absetzen.'
			);

			$lastComplete = end( $completeQueries );
			$this->assertStringContainsString(
				'finished_at',
				$lastComplete,
				'AC-4: complete-UPDATE MUSS finished_at setzen.'
			);
		}

		// ===================================================================
		// AC-5: SpreadconnectClientError (4xx) -> markFailed, NO enqueue, re-thrown.
		// ===================================================================

		public function test_handle_marks_failed_and_rethrows_on_client_error(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			$exception = new SpreadconnectClientError(
				'http_4xx',
				'Invalid API key (401)',
				401,
				'/articles'
			);

			$client->shouldReceive( 'getArticles' )->once()->andThrow( $exception );

			$thrown = null;
			try {
				$job->handle( [ 'trigger' => 'manual' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				SpreadconnectClientError::class,
				$thrown,
				'AC-5: SpreadconnectClientError MUSS unveraendert re-thrown werden.'
			);

			$this->assertSame(
				[],
				$this->enqueuedActions,
				'AC-5: KEIN as_enqueue_async_action darf erfolgen.'
			);

			$failedQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'failed'" )
					|| str_contains( $sql, 'state = "failed"' )
			);
			$this->assertNotEmpty(
				$failedQueries,
				'AC-5: markFailed MUSS state="failed"-UPDATE absetzen.'
			);
		}

		// ===================================================================
		// AC-6: SpreadconnectTransientError (5xx) on page 2 -> row stays
		// in_progress, exception re-thrown for AS retry cascade.
		// ===================================================================

		public function test_handle_keeps_in_progress_state_and_rethrows_on_transient_error(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			$page1 = $this->makeArticleSummaries( 50, startId: 1 );

			$client->shouldReceive( 'getArticles' )
				->with( 1, 50 )
				->once()
				->andReturn( $this->pageResponse( $page1, 1, 50 ) );

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'SC upstream 503',
				503,
				'/articles'
			);
			$client->shouldReceive( 'getArticles' )
				->with( 2, 50 )
				->once()
				->andThrow( $transient );

			$thrown = null;
			try {
				$job->handle( [ 'trigger' => 'manual' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				SpreadconnectTransientError::class,
				$thrown,
				'AC-6: SpreadconnectTransientError MUSS unveraendert re-thrown werden.'
			);

			// Row MUST stay in 'in_progress' — no markFailed, no markComplete.
			$failedQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'failed'" )
			);
			$completeQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'complete'" )
			);
			$this->assertEmpty( $failedQueries, 'AC-6: markFailed darf NICHT aufgerufen werden.' );
			$this->assertEmpty( $completeQueries, 'AC-6: markComplete darf NICHT aufgerufen werden.' );

			// Page-1 articles WERE enqueued (slice-23 idempotency tolerates double-schedule on retry).
			$this->assertCount(
				50,
				$this->enqueuedActions,
				'AC-6: Page-1-Articles MUESSEN bereits enqueued sein bevor Page 2 fehlschlaegt.'
			);
		}

		// ===================================================================
		// AC-7: Counter-Increment uses atomic single-statement UPDATE
		// (col=col+1 WHERE id=…), NO read-modify-write.
		// ===================================================================

		public function test_increment_created_uses_atomic_single_update_statement(): void
		{
			$wpdb            = new SyncCatalogJobFakeWpdb();
			$wpdb->prefix    = 'wp_';
			$GLOBALS['wpdb'] = $wpdb;

			$repo = new SyncHistoryRepo();
			$repo->incrementCreated( 42 );

			// First $wpdb->query() call MUST be the atomic increment.
			$this->assertNotEmpty( $wpdb->queryCalls, 'AC-7: incrementCreated MUSS mindestens 1x $wpdb->query absetzen.' );

			$incrementSql = $wpdb->queryCalls[0];
			$this->assertStringContainsString(
				'UPDATE',
				$incrementSql,
				'AC-7: Counter-Increment MUSS UPDATE-Statement sein.'
			);
			$this->assertMatchesRegularExpression(
				'/created_count\s*=\s*created_count\s*\+\s*1/i',
				$incrementSql,
				'AC-7: Counter-Increment MUSS Single-Statement (col = col + 1) sein, kein Read-Modify-Write.'
			);
			$this->assertStringContainsString(
				'WHERE id = 42',
				$incrementSql,
				'AC-7: WHERE-Klausel MUSS id=42 sein.'
			);
		}

		// AC-7: Once counter sum reaches total -> CAS flip to state='complete'.
		public function test_repo_marks_complete_when_counter_sum_equals_total(): void
		{
			$wpdb               = new SyncCatalogJobFakeWpdb();
			$wpdb->prefix       = 'wp_';
			$GLOBALS['wpdb']    = $wpdb;

			// Persist total=1 — single increment should trigger completion.
			$this->transientStore['sc_sync_total_42'] = 1;

			// Stub the SUM SELECT: after the single increment, sum=1 == total.
			$wpdb->sumByRunId = [ 42 => 1 ];

			$repo = new SyncHistoryRepo();
			$repo->incrementCreated( 42 );

			$completeQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'complete'" )
			);
			$this->assertNotEmpty(
				$completeQueries,
				'AC-7: Bei sum == total MUSS state="complete" gesetzt werden.'
			);

			$lastComplete = end( $completeQueries );
			$this->assertStringContainsString(
				'finished_at',
				$lastComplete,
				'AC-7: complete-UPDATE MUSS finished_at setzen.'
			);
			$this->assertStringContainsString(
				"state = 'in_progress'",
				$lastComplete,
				'AC-7: CAS-WHERE-Klausel MUSS state="in_progress" enthalten (Doppel-Flip-Schutz).'
			);
		}

		// AC-7: Before sum reaches total the row stays in_progress.
		public function test_repo_keeps_in_progress_until_counter_sum_equals_total(): void
		{
			$wpdb            = new SyncCatalogJobFakeWpdb();
			$wpdb->prefix    = 'wp_';
			$GLOBALS['wpdb'] = $wpdb;

			$this->transientStore['sc_sync_total_42'] = 50;

			// After single increment sum=1 < total=50.
			$wpdb->sumByRunId = [ 42 => 1 ];

			$repo = new SyncHistoryRepo();
			$repo->incrementCreated( 42 );

			$completeQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'complete'" )
			);
			$this->assertEmpty(
				$completeQueries,
				'AC-7: Vor Erreichen total darf KEIN state="complete"-UPDATE erfolgen.'
			);
		}

		// ===================================================================
		// AC-8: SyncArticleJob's writeHistoryDetail() invokes the matching
		// counter-increment AFTER appendDetail. Validated indirectly via the
		// status-mapping switch (created -> incrementCreated etc).
		// ===================================================================

		public function test_sync_article_job_invokes_counter_increment_after_append_detail(): void
		{
			// We exercise the private status-mapping in SyncArticleJob by
			// reflecting on the source — the contract is "after appendDetail
			// the matching incrementXxx is called". Slice-23-tests validate
			// that appendDetail is called; here we assert the source code
			// contains the four counter-mappings.
			$source = file_get_contents(
				__DIR__ . '/../../../wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php'
			);
			$this->assertIsString( $source, 'AC-8: SyncArticleJob source MUSS lesbar sein.' );

			$this->assertStringContainsString(
				'incrementCreated',
				$source,
				'AC-8: SyncArticleJob MUSS incrementCreated() aufrufen.'
			);
			$this->assertStringContainsString(
				'incrementUpdated',
				$source,
				'AC-8: SyncArticleJob MUSS incrementUpdated() aufrufen.'
			);
			$this->assertStringContainsString(
				'incrementSkipped',
				$source,
				'AC-8: SyncArticleJob MUSS incrementSkipped() aufrufen.'
			);
			$this->assertStringContainsString(
				'incrementError',
				$source,
				'AC-8: SyncArticleJob MUSS incrementError() aufrufen.'
			);

			// Order check: the increment dispatch MUST live after appendDetail
			// in the file (textual proxy for "after" execution order).
			$appendPos    = strpos( $source, 'appendDetail( $runId, $detail )' );
			$incrementPos = strpos( $source, 'incrementCounterForStatus' );
			$this->assertNotFalse( $appendPos, 'AC-8: appendDetail-Call MUSS in SyncArticleJob existieren.' );
			$this->assertNotFalse( $incrementPos, 'AC-8: increment-Dispatch MUSS in SyncArticleJob existieren.' );
			$this->assertGreaterThan(
				$appendPos,
				$incrementPos,
				'AC-8: Counter-Increment MUSS NACH appendDetail() erfolgen.'
			);
		}

		// AC-8: When run_id=null, neither appendDetail NOR increment is invoked.
		public function test_sync_article_job_skips_increment_when_run_id_is_null(): void
		{
			// Reflect on writeHistoryDetail() — its first guard is `if (null===$runId) return;`.
			$source = file_get_contents(
				__DIR__ . '/../../../wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php'
			);
			$this->assertIsString( $source );

			$this->assertMatchesRegularExpression(
				'/null\s*===\s*\$runId\s*\)\s*\{\s*return;/i',
				$source,
				'AC-8: writeHistoryDetail MUSS bei run_id=null sofort return — KEIN appendDetail, KEIN increment.'
			);
		}

		// ===================================================================
		// AC-9: Bootstrap registers add_action('spreadconnect/sync_catalog').
		// ===================================================================

		public function test_bootstrap_registers_sync_catalog_action_hook(): void
		{
			// Reset Plugin static state so init() can run again.
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			$priority = Actions\has(
				'spreadconnect/sync_catalog',
				[ SyncCatalogJob::class, 'handleStatic' ]
			);
			$this->assertNotFalse(
				$priority,
				'AC-9: add_action("spreadconnect/sync_catalog", [SyncCatalogJob::class, "handleStatic"]) MUSS registriert sein.'
			);
			$this->assertSame( 10, $priority, 'AC-9: Hook-Prioritaet MUSS 10 sein.' );

			$this->assertTrue(
				method_exists( SyncCatalogJob::class, 'handleStatic' ),
				'AC-9: SyncCatalogJob::handleStatic() MUSS existieren.'
			);
			$method = ( new ReflectionClass( SyncCatalogJob::class ) )->getMethod( 'handleStatic' );
			$this->assertTrue( $method->isStatic(), 'AC-9: handleStatic MUSS statisch sein.' );
			$this->assertTrue( $method->isPublic(), 'AC-9: handleStatic MUSS public sein.' );
		}

		// ===================================================================
		// AC-10: Pagination terminates on partial last page (count < PAGE_SIZE).
		// ===================================================================

		public function test_pagination_terminates_on_partial_last_page(): void
		{
			[ $job, $client ] = $this->buildJob( insertId: 42 );

			// Page 1: 50 items (full page) -> continue.
			// Page 2: 17 items (partial) -> terminate after enqueueing.
			$page1 = $this->makeArticleSummaries( 50, startId: 1 );
			$page2 = $this->makeArticleSummaries( 17, startId: 51 );

			$client->shouldReceive( 'getArticles' )
				->with( 1, 50 )
				->once()
				->andReturn( $this->pageResponse( $page1, 1, 50 ) );
			$client->shouldReceive( 'getArticles' )
				->with( 2, 50 )
				->once()
				->andReturn( $this->pageResponse( $page2, 2, 50 ) );

			// CRITICAL: Page 3 MUST NOT be requested.
			$client->shouldNotReceive( 'getArticles' )->with( 3, 50 );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertCount(
				67,
				$this->enqueuedActions,
				'AC-10: 50 + 17 = 67 enqueues; Pagination MUSS bei Partial-Page terminieren.'
			);
		}

		// AC-10: MAX_PAGES safety cap -> markFailed with pagination_max_pages_exceeded.
		public function test_pagination_aborts_with_failed_state_when_max_pages_exceeded(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			// Always-full pages -> loop never terminates organically.
			$fullPage = $this->makeArticleSummaries( 50 );
			$client->shouldReceive( 'getArticles' )
				->andReturn( $this->pageResponse( $fullPage, 1, 50 ) );

			$thrown = null;
			try {
				$job->handle( [ 'trigger' => 'manual' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				\RuntimeException::class,
				$thrown,
				'AC-10: MAX_PAGES-Ueberschreitung MUSS RuntimeException werfen.'
			);

			$failedQueries = array_filter(
				$wpdb->queryCalls,
				static fn( string $sql ) => str_contains( $sql, "state = 'failed'" )
			);
			$this->assertNotEmpty(
				$failedQueries,
				'AC-10: Bei MAX_PAGES-Ueberschreitung MUSS state="failed" gesetzt werden.'
			);

			// markFailed appends a __error__ detail with the error message.
			$detailUpdates = array_filter(
				$wpdb->updateCalls,
				static fn( array $u ) => isset( $u['data']['details'] )
			);
			$this->assertNotEmpty( $detailUpdates, 'AC-10: __error__-Detail MUSS angefuegt werden.' );

			$lastDetailUpdate = end( $detailUpdates );
			$decoded          = json_decode( $lastDetailUpdate['data']['details'] ?? 'null', true );
			$this->assertIsArray( $decoded );
			$messages = array_column( $decoded, 'notes' );
			$this->assertContains(
				'pagination_max_pages_exceeded',
				$messages,
				'AC-10: error_message MUSS = "pagination_max_pages_exceeded" sein.'
			);
		}

		// ===================================================================
		// AC-11: Done-Signal — 50 articles -> 50 enqueues + 1 history INSERT.
		// ===================================================================

		public function test_done_signal_50_articles_yield_50_enqueues_and_one_history_row(): void
		{
			[ $job, $client, $wpdb ] = $this->buildJob( insertId: 42 );

			// 50 articles → Page 1 returns the full PAGE_SIZE (50 items),
			// Page 2 returns the empty end-sentinel. The loop terminates on
			// page 2 (count===0). Pagination contract: termination fires on
			// EITHER `count < PAGE_SIZE` OR `count === 0`.
			$page1 = $this->makeArticleSummaries( 50, startId: 1 );
			$page2 = $this->makeArticleSummaries( 0 );

			$client->shouldReceive( 'getArticles' )
				->with( 1, 50 )
				->once()
				->andReturn( $this->pageResponse( $page1, 1, 50 ) );
			$client->shouldReceive( 'getArticles' )
				->with( 2, 50 )
				->once()
				->andReturn( $this->pageResponse( $page2, 2, 50 ) );

			$job->handle( [ 'trigger' => 'manual' ] );

			$this->assertCount(
				50,
				$this->enqueuedActions,
				'AC-11: Done-Signal — exakt 50 Enqueues.'
			);

			foreach ( $this->enqueuedActions as $row ) {
				$this->assertSame( 'spreadconnect/sync_article', $row[0] );
				$this->assertSame( 'spreadconnect', $row[2] );
			}

			$this->assertCount(
				1,
				$wpdb->insertCalls,
				'AC-11: Done-Signal — exakt 1 INSERT auf wp_spreadconnect_sync_history.'
			);
			$this->assertSame(
				'wp_spreadconnect_sync_history',
				$wpdb->insertCalls[0]['table'] ?? null,
				'AC-11: INSERT-Target MUSS wp_spreadconnect_sync_history sein.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Helper: minimal $wpdb stub for slice-24 tests.
	//
	// Captures `insert`, `update` and `query` calls so the AC assertions can
	// verify INSERT/UPDATE shape, atomic-counter SQL, CAS-completion etc.
	// -----------------------------------------------------------------------
	final class SyncCatalogJobFakeWpdb
	{
		public string $prefix = 'wp_';
		public int $insert_id  = 0;
		public string $last_error = '';

		/** @var list<array{table:string,data:array,format:array|string|null}> */
		public array $insertCalls = [];

		/** @var list<array{table:string,data:array,where:array}> */
		public array $updateCalls = [];

		/** @var list<string> Captured prepared SQL for $wpdb->query(). */
		public array $queryCalls = [];

		/**
		 * SUM-by-runId stub: when SyncHistoryRepo issues a SELECT-sum query
		 * to evaluate completion, return this configured value.
		 *
		 * @var array<int, int>
		 */
		public array $sumByRunId = [];

		/**
		 * Stub `details` JSON column per run_id (used by markFailed's
		 * appendDetail() call).
		 *
		 * @var array<int, string>
		 */
		public array $detailsByRunId = [];

		public function insert( string $table, array $data, $format = null ): int
		{
			$this->insertCalls[] = [
				'table'  => $table,
				'data'   => $data,
				'format' => $format,
			];
			// insert_id pre-set on the SUT instance; do not overwrite here.
			return 1;
		}

		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int
		{
			$this->updateCalls[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];
			return 1;
		}

		public function prepare( string $sql, ...$args ): string
		{
			$out = $sql;
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg )
					? (string) $arg
					: "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_var( string $sql ): ?string
		{
			// Return the configured SUM if this is the counter-sum SELECT.
			if ( str_contains( $sql, '+ updated_count + skipped_count + error_count' )
				|| str_contains( $sql, 'created_count + updated_count' )
			) {
				if ( preg_match( '/id\s*=\s*(\d+)/', $sql, $m ) ) {
					$runId = (int) $m[1];
					return isset( $this->sumByRunId[ $runId ] )
						? (string) $this->sumByRunId[ $runId ]
						: '0';
				}
				return '0';
			}

			// Fallback: details-column SELECT (used by markFailed's appendDetail).
			if ( str_contains( $sql, 'SELECT details' ) ) {
				if ( preg_match( '/id\s*=\s*(\d+)/', $sql, $m ) ) {
					$runId = (int) $m[1];
					return $this->detailsByRunId[ $runId ] ?? '[]';
				}
				return '[]';
			}

			return null;
		}

		public function query( string $sql ): int
		{
			$this->queryCalls[] = $sql;
			return 1;
		}
	}
}
