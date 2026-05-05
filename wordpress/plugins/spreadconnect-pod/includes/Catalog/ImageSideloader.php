<?php
/**
 * Cron-context-safe image sideloader.
 *
 * Wraps WP-Core's `media_sideload_image()` with a runtime guard that loads
 * the three admin-includes (`file.php`, `media.php`, `image.php`) on first
 * use. This is required because Action-Scheduler workers (Slice 23
 * `SyncArticleJob`) execute outside the admin bootstrap, where these files
 * are not auto-loaded — calling `media_sideload_image()` without them
 * would trigger a fatal `Call to undefined function`.
 *
 * Discovery: `discovery.md` -> Slice 4 ("Cron-Context-Includes").
 * Architecture: `architecture.md` -> "Service Map" -> `Catalog\ImageSideloader`.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use WP_Error;

/**
 * Cron-context-safe wrapper around `media_sideload_image()`.
 *
 * Responsibility: download a remote image URL into the WP Media Library
 * and return the resulting attachment ID. Idempotent admin-includes guard
 * ensures the service is safe to call from any execution context (admin,
 * REST, AS worker, WP-Cron).
 *
 * Out-of-scope (handled by callers):
 *   - Featured-image / gallery wiring on `WC_Product` (Slice 22 ProductMapper).
 *   - Preview-URL fetch (`POST /productTypes/{id}/previews`) (Slice 10/23).
 *   - `partial`-state mapping in `sync_history` (Slice 23 SyncArticleJob).
 *   - "Force re-pull images" toggle (Slice 23 caller logic).
 */
final class ImageSideloader
{
	/**
	 * In-process guard preventing redundant `function_exists()` checks and
	 * `require_once`-Filesystem-IO once the admin-includes have been loaded.
	 *
	 * Static + private + no-setter by design (see Constraints "non-resetbar"):
	 * the guard is process-scoped and intentionally not testable via a public
	 * reset hook — a fresh PHP process always starts with `false`, which is
	 * exactly what AC-1 requires.
	 */
	private static bool $includesLoaded = false;

	/**
	 * Idempotently load the three WP-Core admin-includes that
	 * `media_sideload_image()` depends on.
	 *
	 * Loading order matches the WP-Core dependency chain documented in
	 * discovery.md (Slice 4 "Cron-Context-Includes"):
	 *   1. `wp-admin/includes/file.php`  — provides `download_url()`.
	 *   2. `wp-admin/includes/media.php` — provides `media_sideload_image()`.
	 *   3. `wp-admin/includes/image.php` — provides `wp_read_image_metadata()`.
	 *
	 * Idempotency: the static `$includesLoaded` guard short-circuits any
	 * subsequent call within the same PHP process (AC-3). On first entry we
	 * additionally honour an existing `media_sideload_image()` definition
	 * (e.g. when running inside the WP admin where the file is already
	 * loaded), avoiding any `require_once` Filesystem-IO (AC-2).
	 *
	 * No `try/catch` — a missing WP-Core include indicates a broken WP setup
	 * and must surface as a fatal (per Constraints).
	 */
	public static function ensureAdminIncludesLoaded(): void
	{
		if ( self::$includesLoaded ) {
			return;
		}

		if ( function_exists( 'media_sideload_image' ) ) {
			self::$includesLoaded = true;
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		self::$includesLoaded = true;
	}

	/**
	 * Download a remote image URL into the WP Media Library and attach it
	 * to a WC product post.
	 *
	 * Sequence:
	 *   1. Pre-check: reject empty URL or non-positive product-id with a
	 *      `WP_Error` (`spreadconnect_invalid_sideload_args`) **before** any
	 *      Filesystem-IO or WP-Core API-call (AC-6).
	 *   2. Ensure admin-includes are loaded (AC-4 — call before
	 *      `media_sideload_image()`).
	 *   3. Delegate to `media_sideload_image($url, $product_id, null, 'id')`
	 *      with return-mode `'id'` so we receive an integer attachment-ID
	 *      (rather than HTML markup).
	 *   4. Pass the result through unchanged: integer attachment-id on
	 *      success (AC-4), `WP_Error` on failure (AC-5). The caller
	 *      (Slice 23 SyncArticleJob) decides on `partial`-state semantics.
	 *
	 * @param string $url        Remote (typically https://) image URL —
	 *                           e.g. a Spreadconnect preview URL from
	 *                           `Api\Dto\Preview::imageUrl`.
	 * @param int    $product_id WP post-ID of the parent WC product (`> 0`).
	 *
	 * @return int|WP_Error Attachment-ID on success, `WP_Error` on failure.
	 */
	public function sideload( string $url, int $product_id ): int|WP_Error
	{
		if ( '' === $url || $product_id <= 0 ) {
			return new WP_Error(
				'spreadconnect_invalid_sideload_args',
				'ImageSideloader::sideload() requires a non-empty URL and a positive product-ID.',
				array(
					'url'        => $url,
					'product_id' => $product_id,
				)
			);
		}

		self::ensureAdminIncludesLoaded();

		// Return-mode `'id'` -> integer attachment-id; `null` description
		// keeps WP's default behaviour (no caption override).
		// On success: integer attachment-id is returned by value (AC-4).
		// On failure: WP_Error passes through unchanged (AC-5).
		// FIXME(slice-42): surface WP_Error via WcLoggerAdapter once available.
		return media_sideload_image( $url, $product_id, null, 'id' );
	}
}
