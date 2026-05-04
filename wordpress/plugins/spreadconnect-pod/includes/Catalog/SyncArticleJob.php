<?php
/**
 * Per-article sync orchestrator (Action-Scheduler hook handler).
 *
 * Verkettet die in slice-10/21/22 etablierten Bausteine zur
 * Per-Article-Sync-Sequenz: liest pro Article-ID Article-Detail +
 * ProductType (cached) + Previews aus Spreadconnect, sideloaded Bilder,
 * ruft `ProductMapper::upsert()` auf und schreibt einen
 * `sync_history.details[]`-JSON-Eintrag.
 *
 * Sequence (per slice-23 AC-1):
 *   1. `SpreadconnectClient::getArticle($articleId)` → ArticleDetail DTO.
 *   2. Pre-lookup `_spreadconnect_article_id` postmeta to classify
 *      `created` vs. `updated` BEFORE upsert (AC-2 / Constraints
 *      "Status-Klassifikation").
 *   3. Resolve ProductType via 24-h transient `sc_pt_{id}` (AC-7). Cache
 *      miss ⇒ `getProductType()` → `set_transient(sc_pt_{id}, $body, DAY_IN_SECONDS)`.
 *   4. `getProductTypeViews()` + `getHotspot()` to obtain the `viewIds`
 *      and `hotspotId` used by `createPreviews()` — these are the slim
 *      identifiers SC requires for the previews body. (Per architecture
 *      Z. 114/118/119; the article DTO may also carry these fields when
 *      already populated by SC, in which case the calls are skipped.)
 *   5. `createPreviews($ptId, $designId, $hotspotId, $viewIds)` → `Preview[]`.
 *   6. Per preview URL: `ImageSideloader::sideload($url, 0)` (Pre-Attach).
 *      Successful int IDs are collected; `WP_Error` returns are counted
 *      and recorded for `partial`-status decision (AC-3).
 *   7. `ProductMapper::upsert($article, $productType, $attachmentIds)` —
 *      mapper handles attribute provisioning, parent + variations,
 *      `_spreadconnect_*` meta. (slice-22 AC-1).
 *   8. If sideload failures occurred ⇒ `update_post_meta($pid,
 *      '_spreadconnect_sync_state', 'partial')` (AC-3 c).
 *   9. If `run_id !== null` ⇒ `SyncHistoryRepo::appendDetail($runId, [...])`.
 *
 * Failure mapping (architecture.md "Failure Mode Map"):
 *   - `SpreadconnectClientError` (4xx) ⇒ history-detail `status='error'` +
 *     re-throw (AS marks job failed; no AS retry — AC-4).
 *   - `SpreadconnectTransientError` (5xx/network/429) ⇒ NO history append
 *     (job will be retried; detail would otherwise duplicate) + re-throw
 *     (AC-5).
 *   - `ProductMapperException` (e.g. empty variants) ⇒ history-detail
 *     `status='error'` + re-throw (AC-6).
 *   - Image sideload `WP_Error` ⇒ history-detail `status='partial'`,
 *     `_spreadconnect_sync_state='partial'`, sync continues (AC-3).
 *
 * Architecture references:
 *   - "Service Map" → `Catalog\SyncArticleJob` (Application).
 *   - "Action Scheduler — Hook Inventory" → `spreadconnect/sync_article`
 *     (Concurrency 5, Retry 1m/5m/15m on 5xx, Fail-fast on 4xx).
 *   - "Outbound Endpoints" Z. 113 (transient `sc_pt_{id}` 24 h).
 *   - "wp_spreadconnect_sync_history" + "details JSON shape".
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use SpreadconnectPod\Api\Dto\ArticleDetail;
use SpreadconnectPod\Api\Dto\Preview;
use SpreadconnectPod\Api\Dto\ProductTypeDetail;
use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use Throwable;
use WP_Error;

/**
 * Action-Scheduler handler for the `spreadconnect/sync_article` hook.
 *
 * Stateful (constructor-injected dependencies, no static collaborators) so
 * the job can be tested in isolation with Mockery mocks of every external
 * boundary. The static {@see self::handleStatic()} bridge is the
 * `add_action()` callable — it instantiates production-default
 * collaborators and delegates to {@see self::handle()}.
 *
 * `final` per slice-23 constraints (no subclassing — the static bridge is
 * the extension point if a future slice needs alternate wiring).
 */
final class SyncArticleJob
{
	/**
	 * Postmeta key for the SC article-ID anchor (reverse-lookup target for
	 * the created/updated status classification — AC-2).
	 *
	 * Mirrored from {@see ProductMapper}: kept as a local copy so this
	 * class does not reach into the mapper's private constants.
	 */
	private const META_ARTICLE_ID = '_spreadconnect_article_id';

	/**
	 * Postmeta key for the multi-state sync result enum
	 * (`synced`/`partial`/`error`/`removed_in_sc`).
	 */
	private const META_SYNC_STATE = '_spreadconnect_sync_state';

	/**
	 * Sync-state value written when at least one image-sideload failed
	 * (AC-3). The mapper writes `synced` on its own; this job overwrites
	 * with `partial` only when a `WP_Error` came back from the sideloader.
	 */
	private const SYNC_STATE_PARTIAL = 'partial';

	/**
	 * Cache-key prefix for ProductType-detail responses. The full key
	 * format `sc_pt_{id}` is contract-bound (architecture.md Z. 113) —
	 * slice-36 stock-cache reads the same key.
	 */
	private const TRANSIENT_PT_PREFIX = 'sc_pt_';

	/**
	 * 24-hour TTL for the ProductType transient. `DAY_IN_SECONDS` is a WP
	 * core constant (= 86400). Defined here as a fallback for non-WP test
	 * contexts where the constant may be missing.
	 */
	private const TRANSIENT_PT_TTL_SECONDS = 86400;

	/**
	 * Detail-status enum values (architecture.md "details JSON shape" —
	 * `status` field is one of `created|updated|skipped|error|partial`).
	 */
	private const STATUS_CREATED = 'created';
	private const STATUS_UPDATED = 'updated';
	private const STATUS_PARTIAL = 'partial';
	private const STATUS_ERROR   = 'error';

	public function __construct(
		private readonly SpreadconnectClient $client,
		private readonly ImageSideloader $sideloader,
		private readonly ProductMapper $mapper,
		private readonly SyncHistoryRepo $historyRepo,
	) {
	}

	/**
	 * Static bridge for `add_action('spreadconnect/sync_article', …)`.
	 *
	 * Action-Scheduler invokes the registered hook with the args-array as
	 * the first parameter. This bridge instantiates the production-default
	 * collaborator chain and delegates to {@see self::handle()}.
	 *
	 * Slice-23 AC-8: the `Bootstrap\Plugin` hook registration calls this
	 * method via `[ self::class, 'handleStatic' ]`.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['article_id'=>string, 'run_id'=>?int]`.
	 */
	public static function handleStatic( array $args ): void
	{
		$job = new self(
			new SpreadconnectClient(),
			new ImageSideloader(),
			new ProductMapper(),
			new SyncHistoryRepo()
		);

		$job->handle( $args );
	}

	/**
	 * Run the per-article sync sequence.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; required key
	 *                                   `article_id` (string), optional
	 *                                   `run_id` (int|null).
	 */
	public function handle( array $args ): void
	{
		$articleId = $this->resolveArticleId( $args );
		$runId     = $this->resolveRunId( $args );

		// AC-4 / AC-5: getArticle() may throw either the permanent
		// SpreadconnectClientError (4xx — no AS retry) or the transient
		// SpreadconnectTransientError (5xx/network — AS retry cascade).
		// Per AC-5 we MUST NOT write a history detail on transient failures
		// (the job will run again and would otherwise double-emit).
		try {
			$article = $this->client->getArticle( $articleId );
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			$this->writeErrorDetail( $runId, $articleId, null, $e->getMessage() );
			throw $e;
		}

		// AC-2: classify created vs. updated BEFORE the mapper runs, using
		// the same reverse-lookup pattern as slice-22's ProductMapper. We
		// do not call into the mapper's private finder — the spec wants a
		// SINGLE-source-of-truth lookup, but the mapper's private finder
		// is functionally equivalent (same meta-key, same compare). Doing
		// the lookup here keeps the status decision adjacent to the
		// history-write — easier to reason about than mid-mapper hooks.
		$existingProductId = $this->findProductIdByArticleId( $articleId );
		$initialStatus     = ( null === $existingProductId )
			? self::STATUS_CREATED
			: self::STATUS_UPDATED;

		// Resolve ProductType via the contract-bound 24-h transient (AC-7).
		// A second article from the same product-type within the same
		// worker process MUST NOT trigger a second `getProductType()` call.
		try {
			$productType = $this->resolveProductType( $article->productTypeId );
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			$this->writeErrorDetail( $runId, $articleId, $article->title, $e->getMessage() );
			throw $e;
		}

		// Resolve hotspotId + viewIds. Preferred path: the SC article DTO
		// already carries them (slice-23 test setup wires them into
		// ArticleDetail). Fallback: fetch via the dedicated endpoints.
		try {
			[ $hotspotId, $viewIds ] = $this->resolveHotspotAndViews( $article );
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			$this->writeErrorDetail( $runId, $articleId, $article->title, $e->getMessage() );
			throw $e;
		}

		// Preview generation. Same exception triage: 4xx ⇒ history error +
		// re-throw, 5xx ⇒ no history, re-throw.
		try {
			$previews = $this->fetchPreviews(
				$article->productTypeId,
				$article->designId,
				$hotspotId,
				$viewIds
			);
		} catch ( SpreadconnectTransientError $e ) {
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			$this->writeErrorDetail( $runId, $articleId, $article->title, $e->getMessage() );
			throw $e;
		}

		// Sideload every preview into the WP media library. Failures are
		// tracked separately so the mapper still sees the successful IDs
		// and the job can branch into the `partial` status (AC-3).
		[ $attachmentIds, $sideloadErrors ] = $this->sideloadPreviews( $previews );

		// Mapper invocation. Empty variants (slice-22 AC-7) throw
		// ProductMapperException — surfaced as history `status='error'`
		// per AC-6, then re-thrown.
		try {
			$productId = $this->mapper->upsert( $article, $productType, $attachmentIds );
		} catch ( ProductMapperException $e ) {
			$this->writeErrorDetail( $runId, $articleId, $article->title, $e->getMessage() );
			throw $e;
		}

		// `partial` short-circuit (AC-3): even though the mapper succeeded
		// and wrote `_spreadconnect_sync_state='synced'`, we override to
		// `partial` to surface the image-sideload failure on the WC
		// product. The history detail is also written with `status='partial'`.
		if ( ! empty( $sideloadErrors ) ) {
			$notes = implode( '; ', $sideloadErrors );

			update_post_meta( $productId, self::META_SYNC_STATE, self::SYNC_STATE_PARTIAL );

			$this->writeHistoryDetail(
				$runId,
				$articleId,
				$article->title,
				self::STATUS_PARTIAL,
				$notes
			);

			return;
		}

		// Happy path: write the created/updated detail.
		$this->writeHistoryDetail(
			$runId,
			$articleId,
			$article->title,
			$initialStatus,
			null
		);
	}

	/**
	 * Coerce the `article_id` arg to a non-empty string.
	 *
	 * Defensive: AS args are arbitrary serialised arrays. A missing or
	 * non-string value indicates a programmer / scheduler error and must
	 * fail loudly (no history write — there is no article to log).
	 *
	 * @param array<string, mixed> $args
	 */
	private function resolveArticleId( array $args ): string
	{
		$articleId = $args['article_id'] ?? null;

		if ( ! is_string( $articleId ) || '' === $articleId ) {
			throw new \InvalidArgumentException(
				'SyncArticleJob: missing or empty "article_id" in args.'
			);
		}

		return $articleId;
	}

	/**
	 * Resolve the optional `run_id` arg. Anything other than a positive
	 * integer is treated as `null` (AC-10: webhook-triggered runs without
	 * a catalog-run).
	 *
	 * @param array<string, mixed> $args
	 */
	private function resolveRunId( array $args ): ?int
	{
		if ( ! array_key_exists( 'run_id', $args ) ) {
			return null;
		}

		$runId = $args['run_id'];

		if ( ! is_int( $runId ) ) {
			return null;
		}

		return $runId > 0 ? $runId : null;
	}

	/**
	 * Reverse-lookup the WC-Product associated with an SC article-ID.
	 *
	 * Mirrors {@see ProductMapper::findProductIdByArticleId()} verbatim:
	 * `get_posts()` with `meta_query` (no raw `$wpdb`), accepting
	 * `publish`/`draft`/`private` post statuses so a previously-archived
	 * product still matches.
	 *
	 * @return int|null Product-ID if found, else `null`.
	 */
	private function findProductIdByArticleId( string $articleId ): ?int
	{
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'key'     => self::META_ARTICLE_ID,
						'value'   => $articleId,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return null;
		}

		$first = (int) $posts[0];

		return $first > 0 ? $first : null;
	}

	/**
	 * Resolve a `ProductTypeDetail` DTO for the given product-type id,
	 * honouring the `sc_pt_{id}` 24-h transient cache.
	 *
	 * Cache miss path:
	 *   1. `client->getProductType($id)` → raw assoc array (slice-10
	 *      wrapper #21).
	 *   2. `ProductTypeDetail::fromResponse($body)` → DTO (slice-09).
	 *   3. `set_transient('sc_pt_'.$id, $body, DAY_IN_SECONDS)` — store
	 *      the RAW body, not the DTO, so future cache hits can still
	 *      validate via `fromResponse()` and so other slices reading the
	 *      same transient (slice-36) see the canonical shape.
	 */
	private function resolveProductType( string $productTypeId ): ProductTypeDetail
	{
		$key = self::TRANSIENT_PT_PREFIX . $productTypeId;

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return ProductTypeDetail::fromResponse( $cached );
		}

		$body = $this->client->getProductType( $productTypeId );

		set_transient( $key, $body, self::TRANSIENT_PT_TTL_SECONDS );

		return ProductTypeDetail::fromResponse( $body );
	}

	/**
	 * Resolve the `hotspotId` + `viewIds` required by `createPreviews()`.
	 *
	 * Preferred path: the article DTO already carries both fields (slice-23
	 * test fixtures populate them). Fallback: fetch the views via
	 * `getProductTypeViews()` and the hotspot via `getHotspot()` — these
	 * endpoints are cheap and the fallback keeps the job functional even
	 * when the SC `Article` payload omits the fields.
	 *
	 * @return array{0: string, 1: string[]} `[hotspotId, viewIds[]]`.
	 */
	private function resolveHotspotAndViews( ArticleDetail $article ): array
	{
		$hotspotId = $article->hotspotId;
		$viewIds   = $article->viewIds;

		if ( empty( $viewIds ) ) {
			$viewsRaw = $this->client->getProductTypeViews( $article->productTypeId );
			$viewIds  = $this->extractViewIds( $viewsRaw );
		}

		if ( ( null === $hotspotId || '' === $hotspotId ) && null !== $article->designId ) {
			$hotspot   = $this->client->getHotspot( $article->productTypeId, $article->designId );
			$hotspotId = $this->extractHotspotId( $hotspot );
		}

		return array( (string) $hotspotId, $viewIds );
	}

	/**
	 * Extract a flat `string[]` of view-ids from the raw views response.
	 *
	 * SC's `View[]` shape is documented as `{id, name, …}` items; we
	 * tolerate both top-level lists and the `items`/`content` wrapper
	 * shapes the client uses elsewhere.
	 *
	 * @param array<int|string, mixed> $body
	 *
	 * @return string[]
	 */
	private function extractViewIds( array $body ): array
	{
		$candidates = array();

		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			$candidates = $body['items'];
		} elseif ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			$candidates = $body['content'];
		} elseif ( function_exists( 'array_is_list' ) && array_is_list( $body ) ) {
			$candidates = $body;
		} else {
			$candidates = $body;
		}

		$ids = array();
		foreach ( $candidates as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = $entry['id'] ?? ( $entry['viewId'] ?? null );
			if ( is_string( $id ) && '' !== $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Extract the `id` field from a Hotspot response array. Falls back to
	 * empty string so the caller can decide whether to skip the previews
	 * call (an empty hotspot id will trigger a 4xx on createPreviews — by
	 * design, surfacing the data-quality issue as a `status='error'`).
	 *
	 * @param array<string, mixed> $body
	 */
	private function extractHotspotId( array $body ): string
	{
		$id = $body['id'] ?? ( $body['hotspotId'] ?? null );

		return is_string( $id ) ? $id : '';
	}

	/**
	 * Wrap `client->createPreviews()` so the AC sequence stays linear.
	 *
	 * @param string[] $viewIds
	 *
	 * @return Preview[]
	 */
	private function fetchPreviews(
		string $productTypeId,
		?string $designId,
		string $hotspotId,
		array $viewIds
	): array {
		// `createPreviews` requires a non-null designId per SC contract.
		// When the article has no designId we surface that as a 4xx-style
		// permanent error: history-detail `status='error'`. This branch
		// throws SpreadconnectClientError so the upstream try/catch in
		// `handle()` writes the detail and re-throws.
		if ( null === $designId || '' === $designId ) {
			throw new SpreadconnectClientError(
				'missing_design_id',
				sprintf(
					'SyncArticleJob: cannot fetch previews — article %s has no designId.',
					$productTypeId
				),
				null,
				'/productTypes/' . $productTypeId . '/previews'
			);
		}

		return $this->client->createPreviews( $productTypeId, $designId, $hotspotId, $viewIds );
	}

	/**
	 * Sideload every preview URL into the WP media library.
	 *
	 * Passes `0` as the parent-product-id (AC-1 step 4): the WC product is
	 * not known until the mapper has run. The mapper later wires
	 * `set_image_id()` / `set_gallery_image_ids()` itself (slice-22 AC-5).
	 *
	 * Successful int IDs are appended to `$attachmentIds`. Failures
	 * (`WP_Error`) contribute their `get_error_message()` to a separate
	 * collection used by AC-3 to compose the `partial` notes string.
	 *
	 * @param Preview[] $previews
	 *
	 * @return array{0: int[], 1: string[]} [attachmentIds, sideloadErrors]
	 */
	private function sideloadPreviews( array $previews ): array
	{
		// Cron-context safety: ensure WP-Core admin-includes are loaded
		// BEFORE any sideload call (per orchestrator-config Note 6 +
		// slice-21 AC-4). Idempotent — the second invocation is a no-op.
		ImageSideloader::ensureAdminIncludesLoaded();

		$attachmentIds = array();
		$errors        = array();

		foreach ( $previews as $preview ) {
			$result = $this->sideloader->sideload( $preview->imageUrl, 0 );

			if ( $result instanceof WP_Error ) {
				$errors[] = (string) $result->get_error_message();
				continue;
			}

			if ( is_int( $result ) && $result > 0 ) {
				$attachmentIds[] = $result;
			}
		}

		return array( $attachmentIds, $errors );
	}

	/**
	 * Write a `status='error'` detail entry — DRY helper for the four
	 * exception triage branches in {@see self::handle()}.
	 *
	 * Skips the write entirely when `$runId` is null (AC-10: webhook-
	 * triggered per-article syncs without a catalog-run).
	 */
	private function writeErrorDetail(
		?int $runId,
		string $articleId,
		?string $title,
		string $message
	): void {
		$this->writeHistoryDetail(
			$runId,
			$articleId,
			$title,
			self::STATUS_ERROR,
			$message
		);
	}

	/**
	 * Persist a `details[]` entry to the active sync-history row.
	 *
	 * Skips the write when `$runId` is null (AC-10) or when the repo
	 * itself raises (the job must not double-fail on a failed history
	 * write — log and continue; in slice-42 this becomes a structured
	 * WC_Logger entry).
	 */
	private function writeHistoryDetail(
		?int $runId,
		string $articleId,
		?string $title,
		string $status,
		?string $notes
	): void {
		if ( null === $runId ) {
			return;
		}

		$detail = array(
			'article_id' => $articleId,
			'title'      => $title,
			'status'     => $status,
			'notes'      => $notes,
		);

		try {
			$this->historyRepo->appendDetail( $runId, $detail );
		} catch ( Throwable $e ) {
			// Defensive: a history-append failure must NOT mask the
			// underlying exception that the caller is already about to
			// re-throw. FIXME(slice-42): surface via WcLoggerAdapter.
			error_log(
				sprintf(
					'spreadconnect-sync-job: failed to append sync_history detail for run_id=%d article_id=%s: %s',
					$runId,
					$articleId,
					$e->getMessage()
				)
			);
		}
	}
}
