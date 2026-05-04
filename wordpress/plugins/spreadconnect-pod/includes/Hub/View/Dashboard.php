<?php
/**
 * Dashboard sub-page renderer (Hub Section "Dashboard", default route).
 *
 * Slice-13 deliverable. Renders the 5-card-slot stub layout matching
 * wireframes.md Screen 1 (Cards ⑤-⑨: Connection / Catalog / Orders /
 * Webhooks / Failed Operations).
 *
 * Each slot is a markup-only placeholder — slice-46 fills the slots with
 * aggregate-query data; intermediate slices (slice-12 connection-cache,
 * slice-19 subscriptions) only register `do_action()` extension points
 * around the relevant slots without modifying this file.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

/**
 * Stateless renderer for the Dashboard sub-page.
 *
 * Final + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529). The renderer performs **no** data queries — all aggregate
 * counts (catalog count, orders queue depth, webhook drift, failed-ops
 * backlog) arrive in slice-46.
 */
final class Dashboard
{
	/**
	 * Plugin text-domain for label translation.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Ordered list of card-slot definitions.
	 *
	 * Order matches wireframes.md Screen 1 Cards ⑤-⑨ (Connection,
	 * Catalog, Orders, Webhooks, Failed Operations) and slice-13 AC-8.
	 *
	 * `slug` is the BEM modifier on the surrounding `<div>`,
	 * `title` is the `<h2>` label, and `populated_in_slice` is the
	 * follow-up slice number that supplies real data — surfaced in the
	 * placeholder paragraph so the QA reader knows when to expect each
	 * slot to fill.
	 *
	 * @var list<array{slug:string, title:string, populated_in_slice:int}>
	 */
	private const CARDS = array(
		array(
			'slug'               => 'connection',
			'title'              => 'Connection',
			'populated_in_slice' => 12,
		),
		array(
			'slug'               => 'catalog',
			'title'              => 'Catalog',
			'populated_in_slice' => 26,
		),
		array(
			'slug'               => 'orders',
			'title'              => 'Orders',
			'populated_in_slice' => 46,
		),
		array(
			'slug'               => 'webhooks',
			'title'              => 'Webhooks',
			'populated_in_slice' => 41,
		),
		array(
			'slug'               => 'failed-ops',
			'title'              => 'Failed Operations',
			'populated_in_slice' => 38,
		),
	);

	/**
	 * Render the Dashboard sub-page.
	 *
	 * Wired via {@see \SpreadconnectPod\Hub\Controller::dispatch()} when
	 * `?section=dashboard` (or no `?section=` at all — Dashboard is the
	 * default route per slice-13 AC-2).
	 *
	 * Emits a `<h1>` header followed by exactly 5 card-slot containers
	 * with placeholder copy. No data queries are executed (slice-13 AC-8).
	 *
	 * @return void
	 */
	public static function render(): void
	{
		echo '<h1 class="spreadconnect-hub__title">' . esc_html__( 'Spreadconnect Dashboard', self::TEXT_DOMAIN ) . '</h1>';

		echo '<div class="spreadconnect-dashboard__cards">';

		foreach ( self::CARDS as $card ) {
			printf(
				'<div class="spreadconnect-card spreadconnect-card--%1$s">',
				esc_attr( $card['slug'] )
			);
			printf(
				'<h2 class="spreadconnect-card__title">%1$s</h2>',
				esc_html( __( $card['title'], self::TEXT_DOMAIN ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			);
			printf(
				'<p class="spreadconnect-card__placeholder">%1$s</p>',
				esc_html(
					sprintf(
						/* translators: %d is the slice number that will populate this dashboard card. */
						__( 'Wird in Slice %d befüllt', self::TEXT_DOMAIN ),
						$card['populated_in_slice']
					)
				)
			);
			echo '</div>';
		}

		echo '</div>'; // .spreadconnect-dashboard__cards
	}
}
