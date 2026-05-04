<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `SyncArticleJob` references `WP_Error` (Catalog\ImageSideloader return) and
// uses Action-Scheduler / WordPress globals. Mocking-Strategy `mock_external`:
//   - Brain\Monkey alias for `get_posts`, `get_transient`, `set_transient`,
//     `update_post_meta`, `wp_json_encode`, `add_action` (verified via
//     Brain\Monkey hook spy).
//   - `WP_Error` stub class (in case Slice 21 bootstrap has not been loaded
//     in this test process) so the partial-sideload AC-3 path can return a
//     real `instanceof WP_Error` instance.
//   - Mockery doubles for `SpreadconnectClient`, `ImageSideloader`,
//     `ProductMapper`, `SyncHistoryRepo` (all 4 Konstruktor-injectables).
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
		/**
		 * Minimal WP_Error stub. Only the surface used by SyncArticleJob is
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

	// `SyncArticleJob::sideloadPreviews()` calls
	// `ImageSideloader::ensureAdminIncludesLoaded()` (static). The real method
	// is harmless in tests — `function_exists('media_sideload_image')` short-
	// circuits the require_once chain when the Slice 21 bootstrap has run.
	// To be safe across test-isolation boundaries, we declare the function as
	// a no-op so the static method never tries to require the WP admin files.
	if ( ! function_exists( 'media_sideload_image' ) ) {
		function media_sideload_image( string $url, int $post_id = 0, $desc = null, string $return_mode = 'html' ) {
			return 0;
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\Dto\ArticleDetail;
	use SpreadconnectPod\Api\Dto\Preview;
	use SpreadconnectPod\Api\Dto\ProductTypeDetail;
	use SpreadconnectPod\Api\Dto\Variant;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Catalog\ImageSideloader;
	use SpreadconnectPod\Catalog\ProductMapper;
	use SpreadconnectPod\Catalog\ProductMapperException;
	use SpreadconnectPod\Catalog\SyncArticleJob;
	use SpreadconnectPod\Catalog\SyncHistoryRepo;
	use WP_Error;

	/**
	 * Slice 23 — Catalog\SyncArticleJob (Per-Article-Sync Orchestrator).
	 *
	 * Acceptance Tests gegen `slice-23-sync-article-job.md`. Mocking-Strategy
	 * `mock_external` (laut Slice-Spec Z. 28):
	 *   - Brain\Monkey aliases `get_posts`, `get_transient`, `set_transient`,
	 *     `update_post_meta`, `wp_json_encode`, `add_action` (Spy via
	 *     `\Brain\Monkey\Actions\has`).
	 *   - Mockery-Doubles fuer SpreadconnectClient, ImageSideloader,
	 *     ProductMapper, SyncHistoryRepo (DI-Inject in `SyncArticleJob`).
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
	 */
	final class Slice23SyncArticleJobTest extends TestCase
	{
		/**
		 * Captures all `update_post_meta`-Aufrufe als list of [post_id, key, value].
		 *
		 * @var list<array{0:int,1:string,2:mixed}>
		 */
		private array $metaUpdates = [];

		/**
		 * Captures `set_transient` calls als list of [key, value, ttl].
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

			$this->metaUpdates     = [];
			$this->transientWrites = [];
			$this->transientStore  = [];

			// Defaults: spy-style aliases, tracking every call into the
			// per-test arrays so assertions can inspect call-counts and args.
			$metaUpdates = & $this->metaUpdates;
			Functions\when( 'update_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$metaUpdates ) {
					$metaUpdates[] = [ (int) $post_id, (string) $key, $value ];
					return true;
				}
			);

			$transientStore = & $this->transientStore;
			Functions\when( 'get_transient' )->alias(
				static function ( $key ) use ( &$transientStore ) {
					return $transientStore[ (string) $key ] ?? false;
				}
			);

			$transientWrites = & $this->transientWrites;
			Functions\when( 'set_transient' )->alias(
				static function ( $key, $value, $ttl = 0 ) use ( &$transientWrites, &$transientStore ) {
					$transientWrites[]              = [ (string) $key, $value, (int) $ttl ];
					$transientStore[ (string) $key ] = $value;
					return true;
				}
			);

			// Default: no existing WC-Product (status='created' branch).
			Functions\when( 'get_posts' )->justReturn( [] );

			Functions\when( 'wp_json_encode' )->alias(
				static fn( $value ) => json_encode( $value )
			);

			// `ProductTypeDetail::fromResponse()` validates `id` as non-empty
			// string. We do not stub this — the real DTO is constructed.
		}

		protected function tearDown(): void
		{
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// -------------------------------------------------------------------
		// Helpers — DTO + Mock builders.
		// -------------------------------------------------------------------

		/**
		 * Build an ArticleDetail DTO with realistic defaults for slice-23 ACs.
		 *
		 * @param Variant[] $variants
		 * @param string[]  $viewIds
		 */
		private function makeArticle(
			string $id = 'ART-1',
			string $title = 'Demo T-Shirt',
			string $productTypeId = 'PT-7',
			?string $designId = 'D-1',
			?string $hotspotId = 'H-1',
			array $viewIds = [ 'V-FRONT', 'V-BACK' ],
			?array $variants = null,
		): ArticleDetail {
			$variants = $variants ?? [
				new Variant( sku: 'SKU-1', sizeId: 'sz-S', colorId: 'co-RED' ),
			];

			return new ArticleDetail(
				id: $id,
				title: $title,
				productTypeId: $productTypeId,
				variants: $variants,
				description: null,
				designId: $designId,
				state: null,
				hotspotId: $hotspotId,
				viewIds: $viewIds,
			);
		}

		/**
		 * Build a Preview-DTO list for `createPreviews()` mock returns.
		 *
		 * @param string[] $urls
		 *
		 * @return Preview[]
		 */
		private function makePreviews( array $urls ): array
		{
			$out = [];
			foreach ( $urls as $i => $url ) {
				$out[] = new Preview( viewId: 'V-' . $i, imageUrl: $url );
			}
			return $out;
		}

		/**
		 * Raw productType-response shape for `getProductType()` mock returns.
		 * `ProductTypeDetail::fromResponse()` requires non-empty `id`.
		 *
		 * @return array<string, mixed>
		 */
		private function rawProductType( string $id = 'PT-7' ): array
		{
			return [
				'id'     => $id,
				'sizes'  => [],
				'colors' => [],
			];
		}

		/**
		 * Build a fully-mocked SyncArticleJob with default `null` expectations.
		 * Tests configure call expectations on the returned mocks before the
		 * `handle()` call.
		 *
		 * @return array{0:SyncArticleJob,
		 *               1:SpreadconnectClient&\Mockery\MockInterface,
		 *               2:ImageSideloader&\Mockery\MockInterface,
		 *               3:ProductMapper&\Mockery\MockInterface,
		 *               4:SyncHistoryRepo&\Mockery\MockInterface}
		 */
		private function buildJob(): array
		{
			/** @var SpreadconnectClient&\Mockery\MockInterface $client */
			$client = Mockery::mock( SpreadconnectClient::class );

			/** @var ImageSideloader&\Mockery\MockInterface $sideloader */
			$sideloader = Mockery::mock( ImageSideloader::class );

			/** @var ProductMapper&\Mockery\MockInterface $mapper */
			$mapper = Mockery::mock( ProductMapper::class );

			/** @var SyncHistoryRepo&\Mockery\MockInterface $repo */
			$repo = Mockery::mock( SyncHistoryRepo::class );

			$job = new SyncArticleJob( $client, $sideloader, $mapper, $repo );

			return [ $job, $client, $sideloader, $mapper, $repo ];
		}

		// ===================================================================
		// AC-1: Sequenz getArticle -> getProductType -> createPreviews ->
		//       sideload -> upsert; Detail status=created.
		// ===================================================================

		public function test_handle_executes_full_sequence_and_writes_created_detail(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article  = $this->makeArticle();
			$rawPt    = $this->rawProductType( 'PT-7' );
			$previews = $this->makePreviews( [
				'https://sc.test/p1.jpg',
				'https://sc.test/p2.jpg',
			] );

			$client->shouldReceive( 'getArticle' )->once()->with( 'ART-1' )->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->with( 'PT-7' )->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )
				->once()
				->with( 'PT-7', 'D-1', 'H-1', [ 'V-FRONT', 'V-BACK' ] )
				->andReturn( $previews );

			// Sideload returns the integer attachment ID for both URLs.
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/p1.jpg', 0 )->once()->andReturn( 101 );
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/p2.jpg', 0 )->once()->andReturn( 102 );

			// Mapper receives the article, productType DTO and attachment IDs.
			$capturedAttachmentIds = null;
			$mapper->shouldReceive( 'upsert' )
				->once()
				->andReturnUsing(
					static function ( $a, $p, $attachIds ) use ( &$capturedAttachmentIds ): int {
						$capturedAttachmentIds = $attachIds;
						return 999;
					}
				);

			// History append with status='created'.
			$capturedDetail = null;
			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing(
					static function ( int $runId, array $detail ) use ( &$capturedDetail ): void {
						$capturedDetail = [ $runId, $detail ];
					}
				);

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );

			$this->assertSame( [ 101, 102 ], $capturedAttachmentIds, 'AC-1: Mapper MUSS die sideloaded Attachment-IDs erhalten.' );
			$this->assertNotNull( $capturedDetail );
			$this->assertSame( 42, $capturedDetail[0], 'AC-1: appendDetail MUSS mit run_id=42 aufgerufen werden.' );
			$this->assertSame( 'ART-1', $capturedDetail[1]['article_id'] ?? null );
			$this->assertSame( 'Demo T-Shirt', $capturedDetail[1]['title'] ?? null );
			$this->assertSame( 'created', $capturedDetail[1]['status'] ?? null, 'AC-1: status MUSS = "created" sein wenn kein bestehendes Produkt.' );
			$this->assertNull( $capturedDetail[1]['notes'] ?? 'unset' );
		}

		public function test_handle_calls_endpoints_in_documented_order(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$callOrder = [];

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$preview = $this->makePreviews( [ 'https://sc.test/p.jpg' ] );

			$client->shouldReceive( 'getArticle' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder, $article ) {
					$callOrder[] = 'getArticle';
					return $article;
				} );

			$client->shouldReceive( 'getProductType' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder, $rawPt ) {
					$callOrder[] = 'getProductType';
					return $rawPt;
				} );

			$client->shouldReceive( 'createPreviews' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder, $preview ) {
					$callOrder[] = 'createPreviews';
					return $preview;
				} );

			$sideloader->shouldReceive( 'sideload' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder ) {
					$callOrder[] = 'sideload';
					return 101;
				} );

			$mapper->shouldReceive( 'upsert' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder ): int {
					$callOrder[] = 'upsert';
					return 555;
				} );

			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing( static function () use ( &$callOrder ): void {
					$callOrder[] = 'appendDetail';
				} );

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );

			$this->assertSame(
				[ 'getArticle', 'getProductType', 'createPreviews', 'sideload', 'upsert', 'appendDetail' ],
				$callOrder,
				'AC-1: Reihenfolge MUSS getArticle -> getProductType -> createPreviews -> sideload -> upsert -> appendDetail sein.'
			);
		}

		// ===================================================================
		// AC-2: Bestehendes Produkt mit _spreadconnect_article_id -> updated.
		// ===================================================================

		public function test_handle_writes_updated_detail_when_product_exists(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			// Reverse-Lookup: existing product 777 with article_id=ART-1.
			Functions\when( 'get_posts' )->alias(
				static function ( $args ) {
					if ( ( $args['post_type'] ?? '' ) === 'product'
						&& ( $args['meta_query'][0]['key'] ?? '' ) === '_spreadconnect_article_id'
						&& ( $args['meta_query'][0]['value'] ?? '' ) === 'ART-1'
					) {
						return [ 777 ];
					}
					return [];
				}
			);

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$preview = $this->makePreviews( [ 'https://sc.test/p.jpg' ] );

			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $preview );
			$sideloader->shouldReceive( 'sideload' )->once()->andReturn( 101 );
			$mapper->shouldReceive( 'upsert' )->once()->andReturn( 777 );

			$capturedDetail = null;
			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing(
					static function ( int $runId, array $detail ) use ( &$capturedDetail ): void {
						$capturedDetail = $detail;
					}
				);

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );

			$this->assertNotNull( $capturedDetail );
			$this->assertSame(
				'updated',
				$capturedDetail['status'] ?? null,
				'AC-2: Bei bestehendem Produkt MUSS status = "updated" sein.'
			);
			$this->assertSame( 'ART-1', $capturedDetail['article_id'] ?? null );
		}

		// ===================================================================
		// AC-3: Image-Sideload-Failure -> partial; Mapper trotzdem aufgerufen;
		//       _spreadconnect_sync_state=partial; KEINE Exception.
		// ===================================================================

		public function test_handle_writes_partial_detail_on_image_sideload_failure(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$previews = $this->makePreviews( [
				'https://sc.test/ok.jpg',
				'https://sc.test/fail.jpg',
			] );

			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $previews );

			// First URL OK, second returns WP_Error.
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/ok.jpg', 0 )->once()->andReturn( 101 );
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/fail.jpg', 0 )->once()
				->andReturn( new WP_Error( 'http_404', 'Preview download failed (404)' ) );

			$mapper->shouldReceive( 'upsert' )->once()->andReturn( 555 );

			$capturedDetail = null;
			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing(
					static function ( int $runId, array $detail ) use ( &$capturedDetail ): void {
						$capturedDetail = $detail;
					}
				);

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );

			$this->assertNotNull( $capturedDetail );
			$this->assertSame(
				'partial',
				$capturedDetail['status'] ?? null,
				'AC-3: Bei Image-Sideload-Failure MUSS status = "partial" sein.'
			);
			$this->assertIsString( $capturedDetail['notes'] ?? null, 'AC-3: notes MUSS String mit Fehlermeldung sein.' );
			$this->assertStringContainsString(
				'Preview download failed (404)',
				(string) ( $capturedDetail['notes'] ?? '' ),
				'AC-3: notes MUSS WP_Error->get_error_message() enthalten.'
			);

			// _spreadconnect_sync_state='partial' wurde auf das WC-Produkt geschrieben.
			$syncStateMeta = array_filter(
				$this->metaUpdates,
				static fn( $row ) => $row[1] === '_spreadconnect_sync_state' && $row[0] === 555
			);
			$this->assertNotEmpty(
				$syncStateMeta,
				'AC-3: update_post_meta(productId, "_spreadconnect_sync_state", "partial") MUSS auf product=555 erfolgen.'
			);
			$lastValue = end( $syncStateMeta )[2] ?? null;
			$this->assertSame(
				'partial',
				$lastValue,
				'AC-3: _spreadconnect_sync_state MUSS auf "partial" gesetzt werden.'
			);
		}

		public function test_handle_calls_mapper_with_partial_attachment_list(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article  = $this->makeArticle();
			$rawPt    = $this->rawProductType( 'PT-7' );
			$previews = $this->makePreviews( [
				'https://sc.test/ok.jpg',
				'https://sc.test/fail.jpg',
				'https://sc.test/ok2.jpg',
			] );

			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $previews );

			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/ok.jpg', 0 )->once()->andReturn( 101 );
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/fail.jpg', 0 )->once()
				->andReturn( new WP_Error( 'http_500', 'boom' ) );
			$sideloader->shouldReceive( 'sideload' )
				->with( 'https://sc.test/ok2.jpg', 0 )->once()->andReturn( 103 );

			$capturedAttachmentIds = null;
			$mapper->shouldReceive( 'upsert' )
				->once()
				->andReturnUsing(
					static function ( $a, $p, $attachIds ) use ( &$capturedAttachmentIds ): int {
						$capturedAttachmentIds = $attachIds;
						return 555;
					}
				);

			$repo->shouldReceive( 'appendDetail' )->once();

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );

			$this->assertSame(
				[ 101, 103 ],
				$capturedAttachmentIds,
				'AC-3: Mapper MUSS mit nur den ERFOLGREICHEN Attachment-IDs aufgerufen werden (Fail wird ausgelassen).'
			);
		}

		public function test_handle_does_not_throw_on_image_sideload_failure(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$preview = $this->makePreviews( [ 'https://sc.test/fail.jpg' ] );

			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $preview );

			$sideloader->shouldReceive( 'sideload' )
				->once()
				->andReturn( new WP_Error( 'http_500', 'all failed' ) );

			$mapper->shouldReceive( 'upsert' )->once()->andReturn( 555 );
			$repo->shouldReceive( 'appendDetail' )->once();

			$threw = false;
			try {
				$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );
			} catch ( \Throwable $e ) {
				$threw = true;
			}

			$this->assertFalse(
				$threw,
				'AC-3: Job darf bei Image-Sideload-Failure KEINE Exception werfen — Sync laeuft fuer andere Articles weiter.'
			);
		}

		// ===================================================================
		// AC-4: SpreadconnectClientError (4xx) -> status=error, re-thrown.
		// ===================================================================

		public function test_handle_writes_error_detail_and_rethrows_client_error(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$exception = new SpreadconnectClientError(
				'http_4xx',
				'Article ART-1 not found in SC (404)',
				404,
				'/articles/ART-1'
			);

			$client->shouldReceive( 'getArticle' )->once()->with( 'ART-1' )->andThrow( $exception );

			// No further calls.
			$client->shouldNotReceive( 'getProductType' );
			$client->shouldNotReceive( 'createPreviews' );
			$sideloader->shouldNotReceive( 'sideload' );
			$mapper->shouldNotReceive( 'upsert' );

			$capturedDetail = null;
			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing(
					static function ( int $runId, array $detail ) use ( &$capturedDetail ): void {
						$capturedDetail = $detail;
					}
				);

			$thrown = null;
			try {
				$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown, 'AC-4: SpreadconnectClientError MUSS re-thrown werden.' );
			$this->assertInstanceOf(
				\RuntimeException::class,
				$thrown,
				'AC-4: Re-thrown exception MUSS \\RuntimeException-kompatibel sein (Action-Scheduler markiert Job als failed).'
			);

			$this->assertNotNull( $capturedDetail );
			$this->assertSame( 'error', $capturedDetail['status'] ?? null, 'AC-4: status MUSS = "error" sein.' );
			$this->assertSame(
				'Article ART-1 not found in SC (404)',
				$capturedDetail['notes'] ?? null,
				'AC-4: notes MUSS = $e->getMessage() sein.'
			);
			$this->assertSame( 'ART-1', $capturedDetail['article_id'] ?? null );
		}

		// ===================================================================
		// AC-5: SpreadconnectTransientError (5xx) -> KEIN Detail, re-thrown.
		// ===================================================================

		public function test_handle_rethrows_transient_error_without_detail(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$exception = new SpreadconnectTransientError(
				'http_5xx',
				'SC upstream 503',
				503,
				'/articles/ART-1'
			);

			$client->shouldReceive( 'getArticle' )->once()->andThrow( $exception );

			// CRITICAL: appendDetail darf NICHT aufgerufen werden — Job wird
			// retried, doppelte Detail-Eintraege waeren fatal.
			$repo->shouldNotReceive( 'appendDetail' );

			$mapper->shouldNotReceive( 'upsert' );
			$sideloader->shouldNotReceive( 'sideload' );

			$thrown = null;
			try {
				$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				SpreadconnectTransientError::class,
				$thrown,
				'AC-5: Transient-Error MUSS unveraendert re-thrown werden (AS retry-cascade).'
			);
		}

		// ===================================================================
		// AC-6: ProductMapperException -> status=error, re-thrown.
		// ===================================================================

		public function test_handle_writes_error_detail_on_product_mapper_exception(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$preview = $this->makePreviews( [ 'https://sc.test/p.jpg' ] );

			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $preview );
			$sideloader->shouldReceive( 'sideload' )->once()->andReturn( 101 );

			$mapperException = new ProductMapperException( 'ART-1: no variants — refusing to upsert.' );
			$mapper->shouldReceive( 'upsert' )->once()->andThrow( $mapperException );

			$capturedDetail = null;
			$repo->shouldReceive( 'appendDetail' )
				->once()
				->andReturnUsing(
					static function ( int $runId, array $detail ) use ( &$capturedDetail ): void {
						$capturedDetail = $detail;
					}
				);

			$thrown = null;
			try {
				$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf(
				ProductMapperException::class,
				$thrown,
				'AC-6: ProductMapperException MUSS re-thrown werden.'
			);

			$this->assertNotNull( $capturedDetail );
			$this->assertSame( 'error', $capturedDetail['status'] ?? null, 'AC-6: status MUSS = "error" sein.' );
			$this->assertSame(
				'ART-1: no variants — refusing to upsert.',
				$capturedDetail['notes'] ?? null,
				'AC-6: notes MUSS = ProductMapperException::getMessage() sein.'
			);
		}

		// ===================================================================
		// AC-7: ProductType-Cache via Transient sc_pt_{id}.
		// ===================================================================

		public function test_handle_caches_product_type_via_transient(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article  = $this->makeArticle( id: 'ART-1', productTypeId: 'PT-7' );
			$article2 = $this->makeArticle( id: 'ART-2', productTypeId: 'PT-7' );
			$rawPt    = $this->rawProductType( 'PT-7' );

			// First call: getArticle for ART-1, then getProductType('PT-7') ONCE,
			// then createPreviews + sideload + upsert.
			$client->shouldReceive( 'getArticle' )->with( 'ART-1' )->once()->andReturn( $article );
			$client->shouldReceive( 'getArticle' )->with( 'ART-2' )->once()->andReturn( $article2 );

			// CRITICAL: getProductType('PT-7') MUSS exakt 1x aufgerufen werden.
			$client->shouldReceive( 'getProductType' )->with( 'PT-7' )->once()->andReturn( $rawPt );

			$preview = $this->makePreviews( [ 'https://sc.test/p.jpg' ] );
			$client->shouldReceive( 'createPreviews' )->twice()->andReturn( $preview );

			$sideloader->shouldReceive( 'sideload' )->twice()->andReturn( 101 );
			$mapper->shouldReceive( 'upsert' )->twice()->andReturn( 555 );
			$repo->shouldReceive( 'appendDetail' )->twice();

			$job->handle( [ 'article_id' => 'ART-1', 'run_id' => 42 ] );
			$job->handle( [ 'article_id' => 'ART-2', 'run_id' => 42 ] );

			// Verify the transient was written with key `sc_pt_PT-7`.
			$keys = array_column( $this->transientWrites, 0 );
			$this->assertContains(
				'sc_pt_PT-7',
				$keys,
				'AC-7: Cache-Key MUSS exakt "sc_pt_PT-7" sein (architecture.md Z. 113).'
			);

			// Verify the stored TTL is 24 hours (86400 seconds).
			foreach ( $this->transientWrites as $write ) {
				if ( $write[0] === 'sc_pt_PT-7' ) {
					$this->assertSame(
						86400,
						$write[2],
						'AC-7: Transient-TTL MUSS 24h (DAY_IN_SECONDS = 86400) sein.'
					);
				}
			}
		}

		// ===================================================================
		// AC-8: Bootstrap registriert add_action('spreadconnect/sync_article').
		// ===================================================================

		public function test_bootstrap_registers_sync_article_action_hook(): void
		{
			// Reset Plugin static state so init() can run again.
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			// Run init — this registers all add_action hooks via Brain\Monkey.
			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			// Brain\Monkey records every add_action call. AC-8 requires the
			// `spreadconnect/sync_article` hook to be registered with priority
			// 10 and exactly 1 accepted argument, calling
			// SyncArticleJob::handleStatic.
			$this->assertTrue(
				Actions\has(
					'spreadconnect/sync_article',
					[ SyncArticleJob::class, 'handleStatic' ]
				),
				'AC-8: add_action("spreadconnect/sync_article", [SyncArticleJob::class, "handleStatic"]) MUSS registriert sein.'
			);

			// Verify static bridge method is callable (signature contract).
			$this->assertTrue(
				method_exists( SyncArticleJob::class, 'handleStatic' ),
				'AC-8: SyncArticleJob::handleStatic() MUSS existieren.'
			);
			$method = ( new ReflectionClass( SyncArticleJob::class ) )->getMethod( 'handleStatic' );
			$this->assertTrue( $method->isStatic(), 'AC-8: handleStatic MUSS statisch sein.' );
			$this->assertTrue( $method->isPublic(), 'AC-8: handleStatic MUSS public sein.' );
		}

		// ===================================================================
		// AC-9: SyncHistoryRepo::appendDetail merged neuen Eintrag in
		//       bestehende details-JSON.
		// ===================================================================

		public function test_sync_history_repo_appends_detail_to_existing_json_array(): void
		{
			$existing = json_encode( [
				[ 'article_id' => 'ART-OLD', 'title' => 'Old', 'status' => 'created', 'notes' => null ],
			] );

			$wpdbMock         = new SyncHistoryRepoFakeWpdb();
			$wpdbMock->prefix = 'wp_';
			$wpdbMock->detailsByRunId = [ 42 => $existing ];

			$GLOBALS['wpdb'] = $wpdbMock;

			$repo = new SyncHistoryRepo();
			$repo->appendDetail(
				42,
				[ 'article_id' => 'ART-NEW', 'title' => 'New', 'status' => 'updated', 'notes' => null ]
			);

			$this->assertCount( 1, $wpdbMock->updateCalls, 'AC-9: $wpdb->update() MUSS exakt 1x aufgerufen werden.' );
			$update = $wpdbMock->updateCalls[0];

			$this->assertSame( 'wp_spreadconnect_sync_history', $update['table'], 'AC-9: Update MUSS auf wp_spreadconnect_sync_history erfolgen.' );
			$this->assertSame( [ 'id' => 42 ], $update['where'], 'AC-9: WHERE-Klausel MUSS id=42 sein.' );

			$decoded = json_decode( $update['data']['details'] ?? 'null', true );
			$this->assertIsArray( $decoded, 'AC-9: details MUSS valid JSON-encodiertes Array sein.' );
			$this->assertCount(
				2,
				$decoded,
				'AC-9: Neuer Eintrag MUSS an bestehende details[]-Liste angefuegt werden — total = 2.'
			);
			$this->assertSame(
				'ART-OLD',
				$decoded[0]['article_id'] ?? null,
				'AC-9: bestehender Eintrag MUSS erhalten bleiben.'
			);
			$this->assertSame(
				'ART-NEW',
				$decoded[1]['article_id'] ?? null,
				'AC-9: neuer Eintrag MUSS am Ende stehen.'
			);

			unset( $GLOBALS['wpdb'] );
		}

		public function test_sync_history_repo_detail_schema_matches_architecture(): void
		{
			$wpdbMock         = new SyncHistoryRepoFakeWpdb();
			$wpdbMock->prefix = 'wp_';
			$wpdbMock->detailsByRunId = [ 7 => '[]' ];

			$GLOBALS['wpdb'] = $wpdbMock;

			$detail = [
				'article_id' => 'ART-1',
				'title'      => 'Tee',
				'status'     => 'partial',
				'notes'      => 'Image sideload failed (http_500)',
			];

			$repo = new SyncHistoryRepo();
			$repo->appendDetail( 7, $detail );

			$decoded = json_decode( $wpdbMock->updateCalls[0]['data']['details'] ?? 'null', true );
			$this->assertIsArray( $decoded );
			$this->assertCount( 1, $decoded );

			$entry = $decoded[0];
			$this->assertArrayHasKey( 'article_id', $entry, 'AC-9: details-Schema MUSS "article_id" enthalten (architecture.md).' );
			$this->assertArrayHasKey( 'title', $entry, 'AC-9: details-Schema MUSS "title" enthalten.' );
			$this->assertArrayHasKey( 'status', $entry, 'AC-9: details-Schema MUSS "status" enthalten.' );
			$this->assertArrayHasKey( 'notes', $entry, 'AC-9: details-Schema MUSS "notes" enthalten.' );

			$this->assertSame( 'ART-1', $entry['article_id'] );
			$this->assertSame( 'Tee', $entry['title'] );
			$this->assertSame( 'partial', $entry['status'] );
			$this->assertSame( 'Image sideload failed (http_500)', $entry['notes'] );

			$this->assertContains(
				$entry['status'],
				[ 'created', 'updated', 'skipped', 'error', 'partial' ],
				'AC-9: status MUSS einer der dokumentierten Enum-Werte sein (architecture.md "details JSON shape").'
			);

			unset( $GLOBALS['wpdb'] );
		}

		// ===================================================================
		// AC-10: run_id=null -> KEIN appendDetail-Call, Sequenz laeuft trotzdem.
		// ===================================================================

		public function test_handle_skips_history_when_run_id_is_null(): void
		{
			[ $job, $client, $sideloader, $mapper, $repo ] = $this->buildJob();

			$article = $this->makeArticle();
			$rawPt   = $this->rawProductType( 'PT-7' );
			$preview = $this->makePreviews( [ 'https://sc.test/p.jpg' ] );

			// Full sequence MUSS run.
			$client->shouldReceive( 'getArticle' )->once()->andReturn( $article );
			$client->shouldReceive( 'getProductType' )->once()->andReturn( $rawPt );
			$client->shouldReceive( 'createPreviews' )->once()->andReturn( $preview );
			$sideloader->shouldReceive( 'sideload' )->once()->andReturn( 101 );
			$mapper->shouldReceive( 'upsert' )->once()->andReturn( 555 );

			// CRITICAL: KEIN appendDetail darf aufgerufen werden.
			$repo->shouldNotReceive( 'appendDetail' );

			// `handle()` darf nicht werfen.
			$job->handle( [ 'article_id' => 'ART-1' ] );

			// Implicit assertion via Mockery::close() — `shouldNotReceive`
			// would fail if appendDetail had been called. We add an explicit
			// assertion so PHPUnit reports a passing test rather than risky.
			$this->addToAssertionCount( 1 );
		}
	}

	// -----------------------------------------------------------------------
	// Helper: minimal $wpdb stub with prepare/get_var/update spies.
	// Used by AC-9 tests to verify SyncHistoryRepo::appendDetail() behaviour
	// without loading WordPress core. Implemented as a class to avoid Mockery
	// magic-method clashes with `$wpdb->prefix` (string property).
	// -----------------------------------------------------------------------
	final class SyncHistoryRepoFakeWpdb
	{
		public string $prefix = 'wp_';

		/** @var array<int, string> */
		public array $detailsByRunId = [];

		/** @var list<array{table:string,data:array,where:array}> */
		public array $updateCalls = [];

		public string $last_error = '';

		public function prepare( string $sql, ...$args ): string
		{
			// Naive substitution for assertion readability — the actual SQL
			// goes back to get_var() which uses $sql to extract the run-id
			// for the lookup. We just return the SQL with placeholders
			// replaced.
			$out = $sql;
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_var( string $sql ): ?string
		{
			// Extract `id = N` from the SQL to look up the stored details JSON.
			if ( preg_match( '/id\s*=\s*(\d+)/', $sql, $m ) ) {
				$runId = (int) $m[1];
				return $this->detailsByRunId[ $runId ] ?? null;
			}
			return null;
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
	}
}
