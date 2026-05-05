<?php
/**
 * WooCommerce attribute taxonomy provisioner.
 *
 * Ensures the two product-attribute taxonomies the plugin depends on for
 * variation upserts (`pa_groesse` and `pa_farbe`) exist in WooCommerce.
 *
 * Slugs are intentionally hard-coded (German) — the frontend
 * `variant-utils.ts` reads exactly these slugs (Codebase-Scan #15 REUSE), and
 * the architecture decision "`pa_groesse`/`pa_farbe` fixed (not configurable)"
 * keeps the plugin-side and frontend-side in lock-step.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

/**
 * Idempotent provisioner for the `pa_groesse` / `pa_farbe` WC attribute
 * taxonomies.
 *
 * Called from the plugin activation hook and as a pre-run guard from the
 * catalog/article sync jobs (slice-22/23/24). Creates only what is missing,
 * never mutates existing taxonomies, and fails fast if WooCommerce is not
 * loaded or the WC API rejects a `wc_create_attribute()` call.
 *
 * Stateless and final by design — no DI, no instance state.
 */
final class AttributeProvisioner
{
	/**
	 * Canonical, ordered list of taxonomies the plugin requires.
	 *
	 * Iteration-order is significant: AC-1 of slice-20 mandates that the
	 * `created` result list reflects this order (`pa_groesse`, `pa_farbe`).
	 *
	 * Slugs follow the WC `wc_create_attribute()` contract: the array key is
	 * the full taxonomy name (`pa_*`), the `slug` argument is WITHOUT the
	 * `pa_` prefix — WC prepends it internally.
	 *
	 * Names are not wrapped in `__()` here on purpose: this method runs in
	 * the activation-hook context, before `plugins_loaded` and therefore
	 * before the plugin's text-domain is loaded (slice-06). Slice-46 will
	 * provide the localized strings via `de_DE.po`.
	 *
	 * @var array<string, array{name:string, slug:string, type:string, order_by:string, has_archives:bool}>
	 */
	private const TAXONOMIES = [
		'pa_groesse' => [
			'name'         => 'Groesse',
			'slug'         => 'groesse',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		],
		'pa_farbe'   => [
			'name'         => 'Farbe',
			'slug'         => 'farbe',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		],
	];

	/**
	 * Ensure the required attribute taxonomies exist.
	 *
	 * Idempotent: only missing taxonomies are created; pre-existing ones are
	 * skipped without modification (no term inserts, no arg overwrites).
	 *
	 * Fail-fast: a single `WP_Error` from `wc_create_attribute()` aborts the
	 * remaining iteration — the catalog-sync MUST surface a missing required
	 * slug rather than continue silently.
	 *
	 * Signature is intentionally parameterless (AC-7); the slug list is the
	 * private `TAXONOMIES` constant.
	 *
	 * @return array{created:string[], skipped:string[]} Provisioning result —
	 *               `created` lists newly created taxonomy names in iteration
	 *               order, `skipped` lists pre-existing ones.
	 *
	 * @throws AttributeProvisionerException When WooCommerce is not loaded or
	 *                                       `wc_create_attribute()` returns a
	 *                                       `WP_Error`.
	 */
	public static function ensure(): array
	{
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			throw new AttributeProvisionerException( 'WooCommerce not loaded' );
		}

		$existing = self::existingTaxonomyNames();

		$created = [];
		$skipped = [];

		foreach ( self::TAXONOMIES as $taxonomy => $args ) {
			if ( in_array( $taxonomy, $existing, true ) ) {
				$skipped[] = $taxonomy;
				continue;
			}

			$result = wc_create_attribute( $args );

			if ( is_wp_error( $result ) ) {
				throw new AttributeProvisionerException( $result->get_error_message() );
			}

			$created[] = $taxonomy;
		}

		return [
			'created' => $created,
			'skipped' => $skipped,
		];
	}

	/**
	 * Build the list of currently-registered WC attribute taxonomy names.
	 *
	 * Uses `wc_get_attribute_taxonomies()` (the WC-API source of truth, which
	 * caches internally) instead of querying the
	 * `wp_woocommerce_attribute_taxonomies` table directly. The returned
	 * objects expose the slug in `attribute_name` WITHOUT the `pa_` prefix,
	 * so we re-prepend it to compare against our `TAXONOMIES` keys.
	 *
	 * @return string[] Taxonomy names with `pa_` prefix.
	 */
	private static function existingTaxonomyNames(): array
	{
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return [];
		}

		$taxonomies = wc_get_attribute_taxonomies();
		if ( ! is_array( $taxonomies ) ) {
			return [];
		}

		$names = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( is_object( $taxonomy ) && isset( $taxonomy->attribute_name ) ) {
				$names[] = 'pa_' . $taxonomy->attribute_name;
			}
		}

		return $names;
	}
}

/**
 * Raised when the attribute provisioner cannot guarantee the required
 * taxonomies exist.
 *
 * Extends `\RuntimeException` (rather than introducing a new base type) so
 * Action-Scheduler retry logic in slice-23/24/37 classifies it as a permanent
 * failure — consistent with the `SpreadconnectClientError` pattern from
 * slice-07.
 */
final class AttributeProvisionerException extends \RuntimeException
{
}
