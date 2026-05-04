<?php
/**
 * Hub-Page controller — registers the WC submenu and dispatches `?section=…`
 * requests to the appropriate `Hub\View\*::render()` implementation.
 *
 * Slice-13 deliverable. The controller is the **only** entry point for the
 * Spreadconnect admin-area UI: every URL into the Hub-Page is of the form
 * `wp-admin/admin.php?page=spreadconnect[&section=<slug>]` and is routed
 * here by WordPress's submenu callback.
 *
 * @package SpreadconnectPod\Hub
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub;

use SpreadconnectPod\Hub\View\Catalog;
use SpreadconnectPod\Hub\View\Dashboard;
use SpreadconnectPod\Hub\View\FailedOps;
use SpreadconnectPod\Hub\View\Logs;
use SpreadconnectPod\Hub\View\Orders;
use SpreadconnectPod\Hub\View\Settings;
use SpreadconnectPod\Hub\View\Sidebar;
use SpreadconnectPod\Hub\View\Subscriptions;
use SpreadconnectPod\Hub\View\Webhooks;

/**
 * Stateless controller for the Spreadconnect Hub admin sub-page.
 *
 * Final + only static methods — the controller is stateless per the
 * "Adapter — Admin Page" pattern (architecture.md Z. 529). Every entry
 * point reads request state freshly and dispatches to a static
 * `Hub\View\*::render()`.
 *
 * Three responsibilities:
 *   1. {@see self::registerMenu()} — hooked to `admin_menu` from
 *      {@see \SpreadconnectPod\Bootstrap\Plugin::init()}; wires the
 *      `WooCommerce → Spreadconnect` submenu entry.
 *   2. {@see self::dispatch()} — submenu page-callback; capability-gates,
 *      sanitises `?section=`, renders Sidebar + chosen View.
 *   3. {@see self::ensureCapability()} — shared capability helper consumed
 *      by per-AJAX slices (slice-12 TestConnection, slice-14 RegenerateSecret,
 *      slice-19 RepairSubscriptions, …).
 */
final class Controller
{
	/**
	 * Submenu slug under `admin.php?page=…`.
	 *
	 * Re-used by {@see View\Sidebar::render()} when constructing
	 * `admin_url('admin.php?page=spreadconnect&section=…')` href values.
	 */
	public const MENU_SLUG = 'spreadconnect';

	/**
	 * Capability required for any Hub-Page interaction (browse + every AJAX
	 * action wired by follow-up slices).
	 *
	 * Mirrors `architecture.md` Z. 646 ("`manage_woocommerce` capability for
	 * all Hub UI + AJAX"). Any change here must be reflected in the security
	 * table of architecture.md.
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Default `?section=` slug when the parameter is missing or invalid.
	 *
	 * Single source of truth — never spell `'dashboard'` inline elsewhere in
	 * the dispatcher.
	 */
	private const DEFAULT_SECTION = 'dashboard';

	/**
	 * Plugin text-domain for `__()` / `esc_html__()` wrappers.
	 *
	 * Matches the domain loaded by slice-06 (`plugins_loaded`). Centralised
	 * so a future rename only edits one constant.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Section-slug → view-FQCN whitelist (Single Source of Truth).
	 *
	 * The 8-entry map from the slice-13 spec. Adding a new section means
	 * adding exactly one row here AND in {@see View\Sidebar::SECTIONS} —
	 * the dispatcher and the sidebar share the slug list by construction
	 * (Sidebar reads its labels independently; ordering matches this map).
	 *
	 * NEVER concatenate a slug into a class name dynamically — the lookup
	 * is a literal whitelist; unknown slugs fall back to {@see self::DEFAULT_SECTION}.
	 *
	 * @var array<string, class-string>
	 */
	private const SECTIONS = array(
		'dashboard'     => Dashboard::class,
		'catalog'       => Catalog::class,
		'orders'        => Orders::class,
		'webhooks'      => Webhooks::class,
		'failed'        => FailedOps::class,
		'logs'          => Logs::class,
		'settings'      => Settings::class,
		'subscriptions' => Subscriptions::class,
	);

	/**
	 * Register the WC-submenu entry `WooCommerce → Spreadconnect`.
	 *
	 * Hooked from {@see \SpreadconnectPod\Bootstrap\Plugin::init()} via
	 * `add_action('admin_menu', […])`. The `add_submenu_page()` call
	 * registers the page slug `'spreadconnect'` under WC's parent slug
	 * `'woocommerce'`, gates it on `manage_woocommerce`, and routes the
	 * page-callback to {@see self::dispatch()}.
	 *
	 * Idempotency: WP de-duplicates identical `add_submenu_page()` calls,
	 * but the upstream `Bootstrap\Plugin::init()` guard ensures the action
	 * is registered exactly once per request anyway.
	 *
	 * @return void
	 */
	public static function registerMenu(): void
	{
		add_submenu_page(
			'woocommerce',
			__( 'Spreadconnect', self::TEXT_DOMAIN ),
			__( 'Spreadconnect', self::TEXT_DOMAIN ),
			self::REQUIRED_CAP,
			self::MENU_SLUG,
			array( self::class, 'dispatch' )
		);
	}

	/**
	 * Submenu page-callback — capability-gate + section-routing.
	 *
	 * Resolution order (slice-13 AC-2/3/4/6, Constraints "Section-Resolution"):
	 *   1. {@see self::ensureCapability()} — `wp_die()` on missing cap.
	 *   2. Read `$_GET['section']`, run through `sanitize_key()`.
	 *   3. Match the sanitised slug against {@see self::SECTIONS} keys —
	 *      unknown / empty / unsanitised values fall back to
	 *      {@see self::DEFAULT_SECTION}.
	 *   4. Defensive `class_exists()` check on the resolved view-FQCN —
	 *      any of the 6 stub view-classes (Catalog/Orders/Webhooks/
	 *      FailedOps/Logs/Subscriptions) that have not yet shipped in
	 *      a follow-up slice would otherwise trigger a class-not-found
	 *      fatal. Missing class → fall back to Dashboard (slice-13 AC-4
	 *      "implicit"; Constraints "Stub-Klassen-Strategie").
	 *   5. Render the page wrapper + Sidebar (with the resolved slug as
	 *      the active marker) + view via `($fqcn)::render()`.
	 *
	 * The dispatcher emits the surrounding `<div class="wrap">` and calls
	 * the Sidebar **before** the view's render, so view classes (including
	 * slice-11 `Settings`) do not need to re-render the navigation
	 * themselves (slice-13 AC-10).
	 *
	 * @return void
	 */
	public static function dispatch(): void
	{
		self::ensureCapability();

		$slug = self::resolveSection();
		$fqcn = self::SECTIONS[ $slug ];

		// Defensive guard — a slug that maps to a not-yet-existing view
		// class (slice-13 ships only Dashboard + Settings in production)
		// must not fatal. AC-4 fallback applies.
		if ( ! class_exists( $fqcn ) ) {
			$slug = self::DEFAULT_SECTION;
			$fqcn = self::SECTIONS[ $slug ];
		}

		echo '<div class="wrap spreadconnect-hub spreadconnect-hub--' . esc_attr( $slug ) . '">';
		Sidebar::render( $slug );
		echo '<div class="spreadconnect-hub__content">';

		// Static dispatch — no Reflection, no dynamic-method magic.
		// PHP 8.0+ supports `($fqcn)::render()` syntax for class-string
		// callables; this keeps the call-site auditable as a literal
		// `View\*::render` invocation.
		( $fqcn )::render();

		echo '</div>'; // .spreadconnect-hub__content
		echo '</div>'; // .wrap
	}

	/**
	 * Shared capability gate.
	 *
	 * Consumed by slice-13 itself ({@see self::dispatch()}) and by the
	 * per-AJAX slices (slice-12 `TestConnection`, slice-14 `RegenerateSecret`,
	 * slice-19 `RepairSubscriptions`, …) so every Hub adapter speaks
	 * exactly the same `manage_woocommerce` rule. On `false` the method
	 * terminates the request via `wp_die()` with a localised message and
	 * does **not** return.
	 *
	 * @return void
	 */
	public static function ensureCapability(): void
	{
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', self::TEXT_DOMAIN )
			);
		}
	}

	/**
	 * Resolve the active `?section=` slug.
	 *
	 * Pure-function helper: reads `$_GET['section']`, runs it through
	 * `sanitize_key()`, and matches the result against
	 * {@see self::SECTIONS} keys. Returns {@see self::DEFAULT_SECTION} on
	 * any mismatch. The literal `$_GET` value is NEVER returned — the
	 * output is always one of the 8 whitelisted slugs.
	 *
	 * @return string One of the 8 keys of {@see self::SECTIONS}.
	 */
	private static function resolveSection(): string
	{
		$raw = isset( $_GET['section'] ) ? $_GET['section'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $raw ) ) {
			return self::DEFAULT_SECTION;
		}

		$slug = sanitize_key( wp_unslash( $raw ) );
		if ( '' === $slug || ! array_key_exists( $slug, self::SECTIONS ) ) {
			return self::DEFAULT_SECTION;
		}

		return $slug;
	}
}
