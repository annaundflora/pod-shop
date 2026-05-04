<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 25 — Catalog\ArticleRemovedJob + Webhook\ArticleEventHandler
//
// Mocking strategy `mock_external` (per slice-25 Z. 28):
//   - Brain\Monkey aliases for `as_enqueue_async_action`, `wp_update_post`,
//     `update_post_meta`, `get_posts`, `wc_get_logger`, `time`.
//   - WC_Logger via WP_Logger spy stub for log capture.
//   - WP_Error stub from sibling slice tests (idempotent class_exists).
//
// Each test maps 1:1 to a GIVEN/WHEN/THEN AC from the spec.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
		/**
		 * Minimal WP_Error stub. Only the surface used by ArticleRemovedJob is
		 * implemented (`get_error_message()`).
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
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use ReflectionMethod;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Catalog\ArticleRemovedException;
	use SpreadconnectPod\Catalog\ArticleRemovedJob;
	use SpreadconnectPod\Webhook\ArticleEventHandler;
	use WP_Error;

	/**
	 * Slice 25 — ArticleEventHandler (webhook dispatch) + ArticleRemovedJob
	 * (status-flip consumer) acceptance tests.
	 *
	 * AC matrix (all from `slice-25-article-removed-job.md`):
	 *
	 *   AC-1  Article.added       -> sync_article enqueued
	 *   AC-2  Article.updated     -> sync_article enqueued (same path)
	 *   AC-3  Article.removed     -> handle_article_removed enqueued
	 *   AC-4  Missing/empty entity.id -> warning + no enqueue (no throw)
	 *   AC-5  Reverse-lookup match -> wp_update_post draft + meta writes
	 *   AC-6  Reverse-lookup empty -> info-log + early return
	 *   AC-7  Already-draft       -> idempotent (no delete, no trash)
	 *   AC-8  WP_Error or 0       -> ArticleRemovedException
	 *   AC-9  Bootstrap registers add_action('spreadconnect/handle_article_removed')
	 *   AC-10 Class signature unchanged from slice-17 stub contract
	 *   AC-11 Slice-17 dispatcher untouched (verified via signature contract)
	 */
	final class Slice25ArticleRemovedJobTest extends TestCase
	{
		/**
		 * Captures every `as_enqueue_async_action` invocation as
		 * `[hook, args, group]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>,2:string}>
		 */
		private array $enqueuedActions = [];

		/**
		 * Captures every `wp_update_post` invocation as
		 * `[postarr, wp_error_flag]`.
		 *
		 * @var list<array{0:array<string,mixed>,1:bool}>
		 */
		private array $updatePostCalls = [];

		/**
		 * Captures every `update_post_meta` invocation as
		 * `[post_id, key, value]`.
		 *
		 * @var list<array{0:int,1:string,2:mixed}>
		 */
		private array $metaUpdates = [];

		/**
		 * Captures `get_posts` queries for assertion. Each entry is the raw
		 * `$args` array passed by the SUT.
		 *
		 * @var list<array<string,mixed>>
		 */
		private array $getPostsCalls = [];

		/**
		 * Captures every `WC_Logger->log()` call as
		 * `[level, message, context]`.
		 *
		 * @var list<array{0:string,1:string,2:array<string,mixed>}>
		 */
		private array $loggedLines = [];

		/**
		 * Default `wp_update_post` return value — overridden per test.
		 */
		private mixed $updatePostReturn = 1;

		/**
		 * Default `get_posts` return — overridden per test. Empty array =
		 * "no WC product found" branch.
		 *
		 * @var int[]|array<int,int>
		 */
		private array $getPostsReturn = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->enqueuedActions  = [];
			$this->updatePostCalls  = [];
			$this->metaUpdates      = [];
			$this->getPostsCalls    = [];
			$this->loggedLines      = [];
			$this->updatePostReturn = 1;
			$this->getPostsReturn   = [];

			$enqueued = & $this->enqueuedActions;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = [], string $group = '' ) use ( &$enqueued ): int {
					$enqueued[] = [ $hook, $args, $group ];
					return count( $enqueued );
				}
			);

			$updatePostCalls  = & $this->updatePostCalls;
			$updatePostReturn = & $this->updatePostReturn;
			Functions\when( 'wp_update_post' )->alias(
				static function ( $postarr = [], $wp_error = false ) use ( &$updatePostCalls, &$updatePostReturn ) {
					$updatePostCalls[] = [ (array) $postarr, (bool) $wp_error ];
					return $updatePostReturn;
				}
			);

			$metaUpdates = & $this->metaUpdates;
			Functions\when( 'update_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$metaUpdates ) {
					$metaUpdates[] = [ (int) $post_id, (string) $key, $value ];
					return true;
				}
			);

			$getPostsCalls  = & $this->getPostsCalls;
			$getPostsReturn = & $this->getPostsReturn;
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) use ( &$getPostsCalls, &$getPostsReturn ) {
					$getPostsCalls[] = (array) $args;
					return $getPostsReturn;
				}
			);

			$loggedLines = & $this->loggedLines;
			$logger      = new class( $loggedLines ) {
				/** @param list<array{0:string,1:string,2:array<string,mixed>}> $sink */
				public function __construct( private array & $sink ) {}

				public function log( string $level, string $message, array $context = [] ): void {
					$this->sink[] = [ $level, $message, $context ];
				}
			};

			Functions\when( 'wc_get_logger' )->alias(
				static fn() => $logger
			);
		}

		protected function tearDown(): void
		{
			Monkey\tearDown();
			parent::tearDown();
		}

		// =================================================================
		// AC-1 — Article.added -> sync_article enqueued (article_id, run_id=null)
		// =================================================================
		// GIVEN a webhook payload with eventType='Article.added' and
		// data.entity.id='ART-99'
		// WHEN ArticleEventHandler::handle($payload) runs
		// THEN it calls as_enqueue_async_action('spreadconnect/sync_article',
		//      ['article_id'=>'ART-99','run_id'=>null], 'spreadconnect') exactly once.
		// =================================================================

		public function test_article_added_enqueues_sync_article_with_null_run_id(): void
		{
			$payload = [
				'eventType' => 'Article.added',
				'data'      => [ 'entity' => [ 'id' => 'ART-99' ] ],
			];

			ArticleEventHandler::handle( $payload );

			$this->assertCount(
				1,
				$this->enqueuedActions,
				'AC-1: Genau ein as_enqueue_async_action-Call MUSS erfolgen.'
			);

			[ $hook, $args, $group ] = $this->enqueuedActions[0];

			$this->assertSame(
				'spreadconnect/sync_article',
				$hook,
				'AC-1: Hook MUSS "spreadconnect/sync_article" sein.'
			);
			$this->assertSame(
				'ART-99',
				$args['article_id'] ?? null,
				'AC-1: article_id MUSS aus data.entity.id stammen.'
			);
			$this->assertArrayHasKey(
				'run_id',
				$args,
				'AC-1: run_id-Key MUSS in args vorhanden sein.'
			);
			$this->assertNull(
				$args['run_id'],
				'AC-1: run_id MUSS null sein (webhook-getriggerter Per-Article-Sync ohne Catalog-Run).'
			);
			$this->assertSame(
				'spreadconnect',
				$group,
				'AC-1: Action-Scheduler-Group MUSS "spreadconnect" sein.'
			);
		}

		// =================================================================
		// AC-2 — Article.updated -> sync_article enqueued (gleicher Pfad)
		// =================================================================

		public function test_article_updated_enqueues_sync_article_identical_to_added(): void
		{
			$payload = [
				'eventType' => 'Article.updated',
				'data'      => [ 'entity' => [ 'id' => 'ART-12' ] ],
			];

			ArticleEventHandler::handle( $payload );

			$this->assertCount(
				1,
				$this->enqueuedActions,
				'AC-2: Genau ein as_enqueue_async_action-Call MUSS erfolgen.'
			);

			[ $hook, $args, $group ] = $this->enqueuedActions[0];

			$this->assertSame( 'spreadconnect/sync_article', $hook, 'AC-2: Hook MUSS "spreadconnect/sync_article" sein.' );
			$this->assertSame( 'ART-12', $args['article_id'] ?? null );
			$this->assertArrayHasKey( 'run_id', $args, 'AC-2: run_id-Key MUSS in args vorhanden sein.' );
			$this->assertNull( $args['run_id'], 'AC-2: run_id MUSS null sein.' );
			$this->assertSame( 'spreadconnect', $group, 'AC-2: Group MUSS "spreadconnect" sein.' );
		}

		// =================================================================
		// AC-3 — Article.removed -> handle_article_removed enqueued
		// =================================================================
		// THEN it calls as_enqueue_async_action(
		//   'spreadconnect/handle_article_removed', ['article_id'=>'ART-77'],
		//   'spreadconnect') exactly once.
		// AND no direct wp_update_post call in handler.
		// =================================================================

		public function test_article_removed_enqueues_handle_article_removed(): void
		{
			$payload = [
				'eventType' => 'Article.removed',
				'data'      => [ 'entity' => [ 'id' => 'ART-77' ] ],
			];

			ArticleEventHandler::handle( $payload );

			$this->assertCount(
				1,
				$this->enqueuedActions,
				'AC-3: Genau ein as_enqueue_async_action-Call MUSS erfolgen.'
			);

			[ $hook, $args, $group ] = $this->enqueuedActions[0];

			$this->assertSame(
				'spreadconnect/handle_article_removed',
				$hook,
				'AC-3: Hook MUSS "spreadconnect/handle_article_removed" sein.'
			);
			$this->assertSame( 'ART-77', $args['article_id'] ?? null, 'AC-3: article_id MUSS aus payload stammen.' );
			$this->assertArrayNotHasKey(
				'run_id',
				$args,
				'AC-3: handle_article_removed args MUSS KEINE run_id enthalten (no-Catalog-Run-Context).'
			);
			$this->assertSame( 'spreadconnect', $group, 'AC-3: Group MUSS "spreadconnect" sein.' );

			// Handler darf KEINEN direkten wp_update_post-Call ausloesen — Status-Flip ist Job-Verantwortung.
			$this->assertSame(
				[],
				$this->updatePostCalls,
				'AC-3: ArticleEventHandler darf KEIN wp_update_post aufrufen — Status-Flip macht der Job.'
			);
		}

		public function test_handler_uses_spreadconnect_action_scheduler_group(): void
		{
			foreach ( [ 'Article.added', 'Article.updated', 'Article.removed' ] as $eventType ) {
				$this->enqueuedActions = [];

				ArticleEventHandler::handle( [
					'eventType' => $eventType,
					'data'      => [ 'entity' => [ 'id' => 'ART-X' ] ],
				] );

				$this->assertCount( 1, $this->enqueuedActions, "AC-3: Enqueue MUSS bei {$eventType} erfolgen." );
				$this->assertSame(
					'spreadconnect',
					$this->enqueuedActions[0][2],
					"AC-3: Group MUSS 'spreadconnect' sein bei eventType={$eventType}."
				);
			}
		}

		// =================================================================
		// AC-4 — Missing / empty / non-string entity.id
		// =================================================================
		// THEN handler logs warning, skips enqueue, and does NOT throw.
		// =================================================================

		public function test_handler_logs_warning_and_skips_when_entity_id_missing(): void
		{
			$payload = [
				'eventType' => 'Article.added',
				'data'      => [ 'entity' => [] ], // id fehlt
			];

			$thrown = null;
			try {
				ArticleEventHandler::handle( $payload );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertNull(
				$thrown,
				'AC-4: Handler darf KEINE Exception werfen — kein AS-Retry sinnvoll.'
			);
			$this->assertSame(
				[],
				$this->enqueuedActions,
				'AC-4: KEIN as_enqueue_async_action darf erfolgen.'
			);
			$this->assertNotEmpty(
				$this->loggedLines,
				'AC-4: Mindestens eine Warning-Log-Zeile MUSS geschrieben werden.'
			);

			$warning = $this->findLogLine( 'warning' );
			$this->assertNotNull(
				$warning,
				'AC-4: Es MUSS eine "warning"-Level Log-Zeile vorhanden sein.'
			);
			$this->assertStringContainsString(
				'ArticleEventHandler: missing or invalid entity.id',
				$warning[1],
				'AC-4: Warning-Message MUSS Format "ArticleEventHandler: missing or invalid entity.id event_type={...}" einhalten.'
			);
			$this->assertStringContainsString(
				'event_type=Article.added',
				$warning[1],
				'AC-4: Warning-Message MUSS event_type-Token enthalten.'
			);
			$this->assertSame(
				'spreadconnect-webhook-receiver',
				$warning[2]['source'] ?? null,
				'AC-4: Log-Source MUSS "spreadconnect-webhook-receiver" sein.'
			);
		}

		public function test_handler_skips_when_entity_id_is_empty_string(): void
		{
			$payload = [
				'eventType' => 'Article.added',
				'data'      => [ 'entity' => [ 'id' => '' ] ],
			];

			ArticleEventHandler::handle( $payload );

			$this->assertSame( [], $this->enqueuedActions, 'AC-4: Leerer String-id -> KEIN Enqueue.' );
			$this->assertNotNull( $this->findLogLine( 'warning' ), 'AC-4: Warning MUSS geloggt werden.' );
		}

		public function test_handler_skips_when_entity_id_is_non_string(): void
		{
			$payload = [
				'eventType' => 'Article.removed',
				'data'      => [ 'entity' => [ 'id' => 12345 ] ], // int statt string
			];

			ArticleEventHandler::handle( $payload );

			$this->assertSame( [], $this->enqueuedActions, 'AC-4: Non-String-id -> KEIN Enqueue.' );
			$this->assertNotNull( $this->findLogLine( 'warning' ), 'AC-4: Warning MUSS geloggt werden.' );
		}

		// =================================================================
		// AC-5 — Existing WC product -> wp_update_post(draft) + sync_state meta
		// =================================================================
		// THEN job:
		//   - reverse-lookup via get_posts(meta_key=_spreadconnect_article_id, ...)
		//   - wp_update_post(['ID'=>$pid,'post_status'=>'draft'], true)
		//   - update_post_meta(_spreadconnect_sync_state, 'removed_in_sc')
		//   - update_post_meta(_spreadconnect_last_sync, time())
		//   - _spreadconnect_article_id meta NOT touched (audit anchor)
		// =================================================================

		public function test_remove_job_sets_wc_product_to_draft_via_reverse_lookup(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$this->assertCount( 1, $this->updatePostCalls, 'AC-5: wp_update_post MUSS exakt 1x aufgerufen werden.' );

			$call = $this->updatePostCalls[0];
			$this->assertSame(
				555,
				$call[0]['ID'] ?? null,
				'AC-5: wp_update_post MUSS mit ID=555 (aus reverse-lookup) aufgerufen werden.'
			);
			$this->assertSame(
				'draft',
				$call[0]['post_status'] ?? null,
				'AC-5: post_status MUSS auf "draft" gesetzt werden.'
			);
			$this->assertTrue(
				$call[1],
				'AC-5: Zweiter Argument von wp_update_post MUSS true sein (WP_Error-Return aktivieren).'
			);
		}

		public function test_remove_job_uses_correct_reverse_lookup_args(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$this->assertCount( 1, $this->getPostsCalls, 'AC-5: get_posts MUSS exakt 1x aufgerufen werden.' );

			$args = $this->getPostsCalls[0];
			$this->assertSame(
				'product',
				$args['post_type'] ?? null,
				'AC-5: post_type MUSS "product" sein.'
			);
			$this->assertSame(
				1,
				$args['numberposts'] ?? null,
				'AC-5: numberposts MUSS 1 sein.'
			);

			// Either flat meta_key/meta_value OR meta_query — beide Patterns sind valid laut Slice 22.
			$matchedFlat   = ( ( $args['meta_key'] ?? null ) === '_spreadconnect_article_id' )
				&& ( ( $args['meta_value'] ?? null ) === 'ART-77' );
			$mq            = $args['meta_query'][0] ?? null;
			$matchedNested = is_array( $mq )
				&& ( ( $mq['key'] ?? null ) === '_spreadconnect_article_id' )
				&& ( ( $mq['value'] ?? null ) === 'ART-77' );

			$this->assertTrue(
				$matchedFlat || $matchedNested,
				'AC-5: Reverse-Lookup MUSS auf _spreadconnect_article_id=ART-77 zielen (flat oder meta_query[0]).'
			);

			$statuses = (array) ( $args['post_status'] ?? [] );
			$this->assertContains( 'publish', $statuses, 'AC-5: post_status-Filter MUSS "publish" enthalten.' );
			$this->assertContains( 'draft', $statuses, 'AC-5: post_status-Filter MUSS "draft" enthalten (already-draft re-lookup).' );
			$this->assertContains( 'private', $statuses, 'AC-5: post_status-Filter MUSS "private" enthalten.' );
		}

		public function test_remove_job_writes_sync_state_removed_in_sc(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$syncStateUpdates = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[1] === '_spreadconnect_sync_state'
			);
			$this->assertNotEmpty(
				$syncStateUpdates,
				'AC-5: update_post_meta(_spreadconnect_sync_state, ...) MUSS aufgerufen werden.'
			);

			$last = end( $syncStateUpdates );
			$this->assertSame( 555, $last[0], 'AC-5: sync_state-Meta MUSS auf product_id=555 geschrieben werden.' );
			$this->assertSame(
				'removed_in_sc',
				$last[2],
				'AC-5: sync_state-Wert MUSS "removed_in_sc" sein (architecture.md Z. 292 enum).'
			);
		}

		public function test_remove_job_writes_last_sync_timestamp(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$before = time();

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$after = time();

			$lastSync = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[1] === '_spreadconnect_last_sync'
			);
			$this->assertNotEmpty(
				$lastSync,
				'AC-5: update_post_meta(_spreadconnect_last_sync, time()) MUSS aufgerufen werden.'
			);

			$entry = end( $lastSync );
			$this->assertSame( 555, $entry[0], 'AC-5: last_sync-Meta MUSS auf product_id=555 geschrieben werden.' );
			$this->assertIsInt( $entry[2], 'AC-5: last_sync-Wert MUSS Unix-Timestamp (int) sein.' );
			$this->assertGreaterThanOrEqual( $before, $entry[2], 'AC-5: Timestamp >= test-start.' );
			$this->assertLessThanOrEqual( $after + 1, $entry[2], 'AC-5: Timestamp <= test-end (mit 1s Toleranz).' );
		}

		public function test_remove_job_retains_article_id_meta_for_audit(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$articleIdWrites = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[1] === '_spreadconnect_article_id'
			);
			$this->assertSame(
				[],
				$articleIdWrites,
				'AC-5: _spreadconnect_article_id MUSS UNVERAENDERT bleiben (audit anchor; architecture.md Z. 281).'
			);
		}

		// =================================================================
		// AC-6 — Reverse-lookup empty -> info-log + early return, NO update
		// =================================================================

		public function test_remove_job_skips_when_no_wc_product_found(): void
		{
			$this->getPostsReturn = []; // no WC product

			$thrown = null;
			$job    = new ArticleRemovedJob();
			try {
				$job->handle( [ 'article_id' => 'ART-XX' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertNull( $thrown, 'AC-6: Job darf KEINE Exception werfen wenn kein Produkt gefunden.' );
			$this->assertSame(
				[],
				$this->updatePostCalls,
				'AC-6: KEIN wp_update_post-Call wenn reverse-lookup leer.'
			);
			$this->assertSame(
				[],
				$this->metaUpdates,
				'AC-6: KEINE update_post_meta-Calls wenn reverse-lookup leer.'
			);

			$info = $this->findLogLine( 'info' );
			$this->assertNotNull( $info, 'AC-6: Eine "info"-Log-Zeile MUSS geschrieben werden.' );
			$this->assertStringContainsString(
				'ArticleRemovedJob: no WC product found',
				$info[1],
				'AC-6: Info-Message MUSS Format "ArticleRemovedJob: no WC product found article_id={...} — skipping" einhalten.'
			);
			$this->assertStringContainsString( 'ART-XX', $info[1], 'AC-6: Info-Message MUSS article_id enthalten.' );
			$this->assertSame(
				'spreadconnect-sync-job',
				$info[2]['source'] ?? null,
				'AC-6: Log-Source MUSS "spreadconnect-sync-job" sein (architecture.md Z. 532).'
			);
		}

		// =================================================================
		// AC-7 — Already draft -> idempotent re-write, NEVER delete/trash
		// =================================================================

		public function test_remove_job_is_idempotent_on_already_draft_product(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$this->assertCount(
				1,
				$this->updatePostCalls,
				'AC-7: wp_update_post darf erneut aufgerufen werden (No-Op).'
			);
			$call = $this->updatePostCalls[0];
			$this->assertSame(
				'draft',
				$call[0]['post_status'] ?? null,
				'AC-7: post_status MUSS auf "draft" gesetzt werden — NIEMALS "trash" oder "auto-draft".'
			);
			$this->assertNotSame( 'trash', $call[0]['post_status'] ?? null, 'AC-7: post_status DARF NICHT "trash" sein.' );
			$this->assertNotSame( 'auto-draft', $call[0]['post_status'] ?? null, 'AC-7: post_status DARF NICHT "auto-draft" sein.' );

			$syncStateRewrite = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[1] === '_spreadconnect_sync_state' && $row[2] === 'removed_in_sc'
			);
			$this->assertNotEmpty(
				$syncStateRewrite,
				'AC-7: sync_state="removed_in_sc" wird re-written (Idempotenz).'
			);
		}

		public function test_remove_job_never_calls_wp_delete_or_wp_trash_post(): void
		{
			// Spy auf wp_delete_post / wp_trash_post — beide MUESSEN nie aufgerufen werden.
			$deletes = 0;
			$trashes = 0;
			Functions\when( 'wp_delete_post' )->alias(
				static function () use ( &$deletes ) {
					$deletes++;
					return true;
				}
			);
			Functions\when( 'wp_trash_post' )->alias(
				static function () use ( &$trashes ) {
					$trashes++;
					return true;
				}
			);

			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 555;

			$job = new ArticleRemovedJob();
			$job->handle( [ 'article_id' => 'ART-77' ] );

			$this->assertSame(
				0,
				$deletes,
				'AC-7: wp_delete_post DARF NIEMALS aufgerufen werden (architecture.md Z. 736 Anti-Pattern).'
			);
			$this->assertSame(
				0,
				$trashes,
				'AC-7: wp_trash_post DARF NIEMALS aufgerufen werden (architecture.md Z. 736 Anti-Pattern).'
			);
		}

		// =================================================================
		// AC-8 — wp_update_post returns WP_Error or 0 -> ArticleRemovedException
		// =================================================================

		public function test_remove_job_throws_on_wp_update_post_wp_error(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = new WP_Error( 'invalid_product_type', 'WC plugin rejected update' );

			$thrown = null;
			$job    = new ArticleRemovedJob();
			try {
				$job->handle( [ 'article_id' => 'ART-77' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				ArticleRemovedException::class,
				$thrown,
				'AC-8: WP_Error-Return MUSS ArticleRemovedException werfen (AS retried 1m/5m/15m).'
			);
			$this->assertInstanceOf(
				\RuntimeException::class,
				$thrown,
				'AC-8: ArticleRemovedException MUSS \RuntimeException-kompatibel sein.'
			);
			$this->assertStringContainsString(
				'wp_update_post failed',
				$thrown->getMessage(),
				'AC-8: Message MUSS "wp_update_post failed for article_id={...} product_id={...}: {error}" Format einhalten.'
			);
			$this->assertStringContainsString( 'ART-77', $thrown->getMessage(), 'AC-8: Message MUSS article_id enthalten.' );
			$this->assertStringContainsString( '555', $thrown->getMessage(), 'AC-8: Message MUSS product_id enthalten.' );
			$this->assertStringContainsString(
				'WC plugin rejected update',
				$thrown->getMessage(),
				'AC-8: Message MUSS WP_Error->get_error_message() enthalten.'
			);
		}

		public function test_remove_job_throws_on_wp_update_post_zero(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = 0; // update failed silently

			$thrown = null;
			$job    = new ArticleRemovedJob();
			try {
				$job->handle( [ 'article_id' => 'ART-77' ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				ArticleRemovedException::class,
				$thrown,
				'AC-8: 0-Return von wp_update_post MUSS ArticleRemovedException werfen.'
			);
			$this->assertStringContainsString(
				'ART-77',
				$thrown->getMessage(),
				'AC-8: Message MUSS article_id enthalten.'
			);
			$this->assertStringContainsString(
				'555',
				$thrown->getMessage(),
				'AC-8: Message MUSS product_id enthalten.'
			);
		}

		public function test_remove_job_does_not_write_meta_when_update_fails(): void
		{
			$this->getPostsReturn   = [ 555 ];
			$this->updatePostReturn = new WP_Error( 'foo', 'bar' );

			try {
				( new ArticleRemovedJob() )->handle( [ 'article_id' => 'ART-77' ] );
			} catch ( \Throwable $e ) {
				// expected
			}

			$this->assertSame(
				[],
				$this->metaUpdates,
				'AC-8: KEINE update_post_meta-Calls duerfen erfolgen wenn wp_update_post fehlschlaegt (Throw vor Meta-Writes laut Constraint).'
			);
		}

		// =================================================================
		// AC-9 — Bootstrap registers add_action('spreadconnect/handle_article_removed')
		// =================================================================
		// THEN add_action('spreadconnect/handle_article_removed',
		//      [ArticleRemovedJob::class,'handleStatic'], 10, 1) is registered.
		// =================================================================

		public function test_bootstrap_registers_handle_article_removed_action_hook(): void
		{
			// Reset Plugin static state so init() can run again (analog Slice23/24).
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			$priority = Actions\has(
				'spreadconnect/handle_article_removed',
				[ ArticleRemovedJob::class, 'handleStatic' ]
			);

			$this->assertNotFalse(
				$priority,
				'AC-9: add_action("spreadconnect/handle_article_removed", [ArticleRemovedJob::class,"handleStatic"]) MUSS registriert sein.'
			);
			$this->assertSame(
				10,
				$priority,
				'AC-9: Hook-Prioritaet MUSS 10 sein.'
			);

			$this->assertTrue(
				method_exists( ArticleRemovedJob::class, 'handleStatic' ),
				'AC-9: ArticleRemovedJob::handleStatic() MUSS existieren.'
			);

			$method = new ReflectionMethod( ArticleRemovedJob::class, 'handleStatic' );
			$this->assertTrue( $method->isStatic(), 'AC-9: handleStatic MUSS static sein.' );
			$this->assertTrue( $method->isPublic(), 'AC-9: handleStatic MUSS public sein.' );
			$this->assertSame(
				1,
				$method->getNumberOfParameters(),
				'AC-9: handleStatic MUSS exakt einen Parameter haben (args-array).'
			);
		}

		// =================================================================
		// AC-10 — Class signature unchanged from slice-17 stub contract
		// =================================================================
		// THEN final class ArticleEventHandler with public static
		// function handle(array $payload): void.
		// =================================================================

		public function test_article_event_handler_signature_matches_slice_17_contract(): void
		{
			$this->assertTrue(
				class_exists( ArticleEventHandler::class ),
				'AC-10: ArticleEventHandler-Klasse MUSS existieren.'
			);

			$reflection = new ReflectionClass( ArticleEventHandler::class );

			$this->assertTrue(
				$reflection->isFinal(),
				'AC-10: ArticleEventHandler MUSS final sein.'
			);
			$this->assertSame(
				'SpreadconnectPod\\Webhook',
				$reflection->getNamespaceName(),
				'AC-10: Namespace MUSS unveraendert SpreadconnectPod\\Webhook sein.'
			);

			$this->assertTrue(
				$reflection->hasMethod( 'handle' ),
				'AC-10: handle()-Method MUSS existieren.'
			);

			$method = $reflection->getMethod( 'handle' );

			$this->assertTrue( $method->isStatic(), 'AC-10: handle MUSS static sein.' );
			$this->assertTrue( $method->isPublic(), 'AC-10: handle MUSS public sein.' );

			$params = $method->getParameters();
			$this->assertCount( 1, $params, 'AC-10: handle MUSS exakt einen Parameter haben.' );
			$this->assertSame(
				'payload',
				$params[0]->getName(),
				'AC-10: Parameter-Name MUSS "payload" sein (Slice-17-Vertrag).'
			);

			$paramType = $params[0]->getType();
			$this->assertNotNull( $paramType, 'AC-10: $payload MUSS einen Type-Hint haben.' );
			$this->assertSame(
				'array',
				(string) $paramType,
				'AC-10: Parameter-Type MUSS array sein (Slice-17-Vertrag).'
			);

			$returnType = $method->getReturnType();
			$this->assertNotNull( $returnType, 'AC-10: handle MUSS einen Return-Type haben.' );
			$this->assertSame(
				'void',
				(string) $returnType,
				'AC-10: Return-Type MUSS void sein (Slice-17-Vertrag).'
			);
		}

		// =================================================================
		// AC-11 — Slice-17 ProcessWebhookEventJob continues to work without
		// edit: dispatcher dispatch via FQN ArticleEventHandler::handle($payload).
		// =================================================================

		public function test_slice_17_dispatcher_uses_article_event_handler_via_fqn(): void
		{
			$dispatcherFile = realpath( __DIR__ . '/../../..' )
				. '/wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php';

			$this->assertFileExists(
				$dispatcherFile,
				'AC-11: ProcessWebhookEventJob.php MUSS existieren (Slice-17-Vertrag).'
			);

			$source = (string) file_get_contents( $dispatcherFile );

			$this->assertStringContainsString(
				'ArticleEventHandler::handle',
				$source,
				'AC-11: ProcessWebhookEventJob MUSS ArticleEventHandler::handle aufrufen (FQN-Dispatch laut Slice-17 AC-4).'
			);
		}

		// =================================================================
		// Helpers
		// =================================================================

		/**
		 * Find the FIRST captured log line at the given level.
		 *
		 * @return array{0:string,1:string,2:array<string,mixed>}|null
		 */
		private function findLogLine( string $level ): ?array
		{
			foreach ( $this->loggedLines as $entry ) {
				if ( ( $entry[0] ?? '' ) === $level ) {
					return $entry;
				}
			}
			return null;
		}
	}
}
