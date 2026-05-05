<?php
/**
 * Spreadconnect Hub — admin stylesheet enqueue adapter.
 *
 * Screen-gated wrapper around `wp_enqueue_style()` that loads
 * `assets/css/spreadconnect-hub.css` exclusively on the
 * `woocommerce_page_spreadconnect` admin page (the slug WordPress assigns
 * to the WC submenu registered by {@see \SpreadconnectPod\Hub\Controller::registerMenu()}).
 *
 * Matches the architecture's "Adapter — Admin Page" pattern (architecture.md
 * Z. 529): a stateless static class composed by `Bootstrap\Plugin::init()`
 * via `add_action( 'admin_enqueue_scripts', [ … ] )`.
 *
 * @package SpreadconnectPod\Hub
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub;

/**
 * Enqueue adapter for the Hub stylesheet.
 *
 * Lifecycle:
 *   1. `Bootstrap\Plugin::init()` registers
 *      `add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] )`.
 *   2. WP fires `admin_enqueue_scripts` on every admin page-load and
 *      passes the current screen's `$hook_suffix`. {@see self::enqueue()}
 *      early-returns on every value other than the Hub page slug
 *      `woocommerce_page_spreadconnect`, so the stylesheet is loaded
 *      exclusively on the page that needs it.
 *
 * The plugin-file path is resolved from THIS file's location via
 * `dirname(__DIR__, 2) . '/spreadconnect-pod.php'` — the same pattern used
 * by sibling adapters (see {@see \SpreadconnectPod\Inline\ProductMetaBox::enqueueAssets()}).
 * No bootstrap-side injector is needed.
 */
final class Assets
{
	/**
	 * Hook-suffix WordPress assigns to the Spreadconnect Hub submenu.
	 *
	 * The pattern `{parent_slug}_page_{menu_slug}` is documented in
	 * `add_submenu_page()`. Our parent is `woocommerce` (see
	 * {@see \SpreadconnectPod\Hub\Controller::registerMenu()}) and the
	 * menu slug is `spreadconnect` ({@see \SpreadconnectPod\Hub\Controller::MENU_SLUG}),
	 * so WP produces `woocommerce_page_spreadconnect` and passes that
	 * value to every `admin_enqueue_scripts` listener.
	 */
	public const HOOK_SUFFIX = 'woocommerce_page_spreadconnect';

	/**
	 * Style handle registered with `wp_enqueue_style()`.
	 *
	 * Stable identifier so other code (tests, other adapters) can refer
	 * to the stylesheet by handle rather than re-deriving the URL.
	 */
	public const HANDLE = 'spreadconnect-hub';

	/**
	 * Relative path (from the plugin root) of the stylesheet on disk.
	 *
	 * Used both as the second argument to `plugins_url()` (so the URL
	 * ends in `assets/css/spreadconnect-hub.css`) and to compute the
	 * cache-buster via `filemtime()`.
	 */
	private const CSS_REL_PATH = 'assets/css/spreadconnect-hub.css';

	/**
	 * Enqueue the Hub stylesheet — but ONLY on the Hub admin page.
	 *
	 * Screen-gating: the stylesheet is only relevant inside the
	 * `<div class="wrap spreadconnect-hub …">` markup the
	 * {@see Controller::dispatch()} dispatcher emits, which itself only
	 * runs when the current admin screen's hook suffix matches
	 * {@see self::HOOK_SUFFIX}. Loading it elsewhere would needlessly
	 * inflate the asset count on every WP-Admin page load.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix as
	 *                            passed by WordPress to every
	 *                            `admin_enqueue_scripts` listener
	 *                            (e.g. `'index.php'`, `'edit.php'`,
	 *                            `'woocommerce_page_spreadconnect'`).
	 *
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void
	{
		// AC-4: hard early-return on every screen except the Hub page.
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		// Resolve the plugin-file path from THIS file's location. The
		// pattern mirrors `Inline\ProductMetaBox::enqueueAssets()` and
		// avoids the previous bootstrap-side injector entirely.
		// `dirname(__DIR__, 2)` walks up from `includes/Hub/Assets.php`
		// to the plugin root `wordpress/plugins/spreadconnect-pod/`.
		$pluginFile = dirname( __DIR__, 2 ) . '/spreadconnect-pod.php';

		// `plugins_url( $css_rel_path, $plugin_file )` resolves to
		// `<plugins_root>/spreadconnect-pod/assets/css/spreadconnect-hub.css`
		// — the URL ends in `assets/css/spreadconnect-hub.css` as
		// asserted by AC-3.
		$src = plugins_url( self::CSS_REL_PATH, $pluginFile );

		// AC-5: cache-buster derived from the on-disk mtime so a CSS
		// edit immediately invalidates browser caches without bumping
		// the plugin-version constant. Guard with `is_readable()` first
		// so `filemtime()` runs un-suppressed (zero `@`-operator usage
		// is a codebase-wide convention).
		$cssPath = plugin_dir_path( $pluginFile ) . self::CSS_REL_PATH;
		$ver     = '0';
		if ( is_readable( $cssPath ) ) {
			$mtime = filemtime( $cssPath );
			if ( false !== $mtime ) {
				$ver = (string) $mtime;
			}
		}

		wp_enqueue_style(
			self::HANDLE,
			$src,
			array(),
			$ver
		);
	}
}
