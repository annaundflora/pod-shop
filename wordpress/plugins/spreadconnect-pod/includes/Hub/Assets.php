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
 *   1. `Bootstrap\Plugin::init( $plugin_file )` calls
 *      {@see self::setPluginFile()} with the absolute path to
 *      `spreadconnect-pod.php`.
 *   2. `Bootstrap\Plugin::init()` registers
 *      `add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] )`.
 *   3. WP fires `admin_enqueue_scripts` on every admin page-load and
 *      passes the current screen's `$hook_suffix`. {@see self::enqueue()}
 *      early-returns on every value other than the Hub page slug
 *      `woocommerce_page_spreadconnect`, so the stylesheet is loaded
 *      exclusively on the page that needs it.
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
	 * Absolute path to the main plugin file (`spreadconnect-pod.php`).
	 *
	 * Populated by {@see self::setPluginFile()} during
	 * `Bootstrap\Plugin::init()`. The static-only adapter pattern can't
	 * resolve this from a constructor, so the bootstrap injects it.
	 */
	private static string $pluginFile = '';

	/**
	 * Inject the absolute path to the main plugin file.
	 *
	 * Invoked by {@see \SpreadconnectPod\Bootstrap\Plugin::init()} BEFORE
	 * the `admin_enqueue_scripts` action is registered, so by the time
	 * {@see self::enqueue()} fires the value is always set. Idempotent —
	 * re-calling with the same path is a no-op.
	 *
	 * @param string $plugin_file Absolute path to `spreadconnect-pod.php`.
	 */
	public static function setPluginFile( string $plugin_file ): void
	{
		self::$pluginFile = $plugin_file;
	}

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

		// Defensive: if Bootstrap forgot to inject the plugin-file path
		// the asset can't be located; silently skip rather than emit a
		// broken `<link>` tag pointing at the WP root.
		if ( '' === self::$pluginFile ) {
			return;
		}

		$pluginFile = self::$pluginFile;

		// `plugins_url( $css_rel_path, $plugin_file )` resolves to
		// `<plugins_root>/spreadconnect-pod/assets/css/spreadconnect-hub.css`
		// — the URL ends in `assets/css/spreadconnect-hub.css` as
		// asserted by AC-3.
		$src = plugins_url( self::CSS_REL_PATH, $pluginFile );

		// AC-5: cache-buster derived from the on-disk mtime so a CSS
		// edit immediately invalidates browser caches without bumping
		// the plugin-version constant. `filemtime()` returns false on
		// missing files; coerce to a stable string ('0') to keep
		// `$ver` non-empty (never `false`/`null`/`''`).
		$cssPath = plugin_dir_path( $pluginFile ) . self::CSS_REL_PATH;
		$mtime   = @filemtime( $cssPath );
		$ver     = ( false === $mtime ) ? '0' : (string) $mtime;

		wp_enqueue_style(
			self::HANDLE,
			$src,
			array(),
			$ver
		);
	}
}
