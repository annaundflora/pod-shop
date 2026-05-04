<?php
/**
 * Hub Sidebar — shared navigation rendered above every section view.
 *
 * Slice-13 deliverable. The Sidebar is rendered by
 * {@see \SpreadconnectPod\Hub\Controller::dispatch()} immediately before
 * the per-section `View::render()` call, so individual view classes never
 * have to repeat the navigation markup.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Hub\Controller;

/**
 * Stateless renderer for the 8-item Hub navigation.
 *
 * Final + only static methods — markup-only helper with no instance state
 * (architecture.md "Adapter — Admin Page" / Z. 529).
 *
 * Slug-to-label ordering is locked to the slice-13 spec table (Z. 42-51):
 * Dashboard, Catalog, Orders, Webhooks, Failed Operations, Logs,
 * Settings, Subscriptions. Slice 11 (Settings page) and slice-13 (this
 * file) are the first consumers; future view-slices reuse this helper
 * verbatim by reading `Controller::MENU_SLUG`.
 */
final class Sidebar
{
	/**
	 * Plugin text-domain for label translation.
	 *
	 * Same constant string as in {@see Controller::TEXT_DOMAIN}; duplicated
	 * here to keep the helper self-contained without a `use` of a private
	 * constant.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Ordered list of `[slug, label-string]` pairs for the 8 nav-items.
	 *
	 * Order is the autoritative single-source-of-truth from the slice-13
	 * spec — DO NOT reorder. The `label` strings here are English source
	 * strings; the German translations live in `languages/spreadconnect-pod-de_DE.po`
	 * (slice-06).
	 *
	 * @var list<array{slug:string, label:string}>
	 */
	private const NAV_ITEMS = array(
		array( 'slug' => 'dashboard',     'label' => 'Dashboard' ),
		array( 'slug' => 'catalog',       'label' => 'Catalog' ),
		array( 'slug' => 'orders',        'label' => 'Orders' ),
		array( 'slug' => 'webhooks',      'label' => 'Webhooks' ),
		array( 'slug' => 'failed',        'label' => 'Failed Operations' ),
		array( 'slug' => 'logs',          'label' => 'Logs' ),
		array( 'slug' => 'settings',      'label' => 'Settings' ),
		array( 'slug' => 'subscriptions', 'label' => 'Subscriptions' ),
	);

	/**
	 * Render the sidebar `<nav>` markup.
	 *
	 * Emits a `<nav class="spreadconnect-hub-nav">` containing a single
	 * `<ul>` with exactly 8 `<li>` items in {@see self::NAV_ITEMS} order.
	 * Each item carries an `<a href>` to
	 * `admin.php?page=spreadconnect&section={slug}` and the `<li>` whose
	 * slug equals `$active_slug` carries an additional `is-active`
	 * CSS-class hook.
	 *
	 * Exactly one item is `is-active` whenever `$active_slug` is one of
	 * the 8 known slugs. If the caller passes an unknown slug (which
	 * should never happen — `Controller::dispatch()` only invokes this
	 * with a whitelisted slug), no item is `is-active` and the nav still
	 * renders correctly.
	 *
	 * @param string $active_slug Slug of the currently rendered section.
	 *
	 * @return void
	 */
	public static function render( string $active_slug ): void
	{
		$base_url = admin_url( 'admin.php?page=' . Controller::MENU_SLUG );

		echo '<nav class="spreadconnect-hub-nav" aria-label="' . esc_attr__( 'Spreadconnect Sections', self::TEXT_DOMAIN ) . '">';
		echo '<ul class="spreadconnect-hub-nav__list">';

		foreach ( self::NAV_ITEMS as $item ) {
			$slug      = $item['slug'];
			$is_active = ( $slug === $active_slug );
			$li_class  = $is_active
				? 'spreadconnect-hub-nav__item is-active'
				: 'spreadconnect-hub-nav__item';

			$href = add_query_arg( 'section', $slug, $base_url );

			printf(
				'<li class="%1$s"><a href="%2$s" class="spreadconnect-hub-nav__link">%3$s</a></li>',
				esc_attr( $li_class ),
				esc_url( $href ),
				esc_html( __( $item['label'], self::TEXT_DOMAIN ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			);
		}

		echo '</ul>';
		echo '</nav>';
	}
}
