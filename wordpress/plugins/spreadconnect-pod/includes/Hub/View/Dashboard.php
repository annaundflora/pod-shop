<?php
/**
 * Dashboard sub-page renderer (Hub Section "Dashboard", default route).
 *
 * Slice-46 fills the 5 card-slots that Slice-13 left as stubs with real
 * aggregate-counts read from the per-domain Repos / cached Transients
 * (architecture.md "Operational Visibility", Z. 686). The Slice-13 markup
 * scaffold (`<div class="spreadconnect-card spreadconnect-card--{slug}">…`)
 * stays unchanged so the per-card CSS hooks survive the upgrade.
 *
 * Cards rendered (in order, matching wireframes.md Screen 1 ⑤-⑨):
 *   1. Connection      — `sc_health` transient (Slice 12 writer).
 *   2. Catalog         — `SyncHistoryRepo::findLatest()` (Slice 24).
 *   3. Orders          — 30-day `_spreadconnect_state` aggregate query (HPOS-aware).
 *   4. Webhooks        — Subscriptions cache + `WebhookLogRepo::findLatest()`.
 *   5. Failed Operations — `FailedOpsRepo::count('unresolved')` (Slice 37) + Severity-Banner.
 *
 * Each card body is wrapped in its own `try/catch (\Throwable $e)` so a
 * single mis-behaving repo / transient never crashes the entire admin page.
 * Failures are logged via {@see WcLoggerAdapter::error()} on the
 * `spreadconnect-failure` source so the Logs sub-page (Slice 42) surfaces
 * them.
 *
 * No live API calls happen here — all data is read from local persistence
 * (transients + custom tables) per Slice-46 AC-8 (Constraints).
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Catalog\SyncHistoryRepo;
use SpreadconnectPod\Failure\AdminNoticeStore;
use SpreadconnectPod\Failure\FailedOpsRepo;
use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;
use SpreadconnectPod\Subscription\SubscriptionManager;
use SpreadconnectPod\Webhook\WebhookLogRepo;

/**
 * Stateless renderer for the Dashboard sub-page.
 *
 * `final` + only static methods (architecture.md "Adapter — Admin Page" /
 * Z. 529). The `render()` signature carries over from Slice 13 unchanged.
 */
final class Dashboard
{
	/**
	 * Plugin text-domain for label translation.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Order of card-slots, matching wireframes.md Screen 1 Cards ⑤-⑨.
	 *
	 * The `slug` is the BEM modifier on the surrounding `<div>` (the
	 * Slice-13 markup contract — never change this), the `title` is the
	 * `<h2>` label.
	 *
	 * @var list<array{slug:string, title:string, render:string}>
	 */
	private const CARDS = array(
		array(
			'slug'   => 'connection',
			'title'  => 'Connection',
			'render' => 'renderConnectionCard',
		),
		array(
			'slug'   => 'catalog',
			'title'  => 'Catalog',
			'render' => 'renderCatalogCard',
		),
		array(
			'slug'   => 'orders',
			'title'  => 'Orders',
			'render' => 'renderOrdersCard',
		),
		array(
			'slug'   => 'webhooks',
			'title'  => 'Webhooks',
			'render' => 'renderWebhooksCard',
		),
		array(
			'slug'   => 'failed-ops',
			'title'  => 'Failed Operations',
			'render' => 'renderFailedOpsCard',
		),
	);

	/**
	 * 30 days expressed in seconds — used by the Orders card to bound the
	 * `_spreadconnect_state` aggregate query (Slice 46 AC-10).
	 */
	private const ORDERS_WINDOW_SECONDS = 2592000;

	/**
	 * `_spreadconnect_state` enum values reported on the Orders card.
	 *
	 * Maps the four meta-values to their human-readable labels (i18n-wrapped
	 * at render-time). Order matches Slice 46 AC-10 spec output.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_STATE_LABELS = array(
		'NEW'              => 'Pending',
		'CONFIRMED'        => 'Confirmed',
		'PROCESSED'        => 'Processed',
		'failed_to_submit' => 'Failed',
	);

	/**
	 * Render the Dashboard sub-page.
	 *
	 * Wired via {@see \SpreadconnectPod\Hub\Controller::dispatch()} when
	 * `?section=dashboard` (or no `?section=` at all — Dashboard is the
	 * default route per slice-13 AC-2).
	 *
	 * Each card body is rendered inside its own `try/catch (\Throwable $e)`
	 * so a failure in one card never propagates to the others (Slice-46
	 * AC-14).
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
				esc_html( self::cardTitle( $card['slug'] ) )
			);

			echo '<div class="spreadconnect-card-body">';
			try {
				$method = $card['render'];
				/** @var callable $callable */
				$callable = array( self::class, $method );
				call_user_func( $callable );
			} catch ( \Throwable $e ) {
				self::renderUnavailable( $card['slug'], $e );
			}
			echo '</div>';

			echo '</div>';
		}

		echo '</div>'; // .spreadconnect-dashboard__cards
	}

	/**
	 * Card 1 — Connection.
	 *
	 * Reads the `sc_health` transient (Slice-12 writer) and renders the
	 * resulting `status` string plus the `checked_at` timestamp. A missing
	 * transient yields `unknown` and a "Re-test"-button link to the
	 * Settings sub-page where the user can run Test-Connection again.
	 */
	private static function renderConnectionCard(): void
	{
		$health = function_exists( 'get_transient' ) ? get_transient( 'sc_health' ) : false;
		$status = 'unknown';
		$checkedAt = 0;

		if ( is_array( $health ) ) {
			if ( isset( $health['status'] ) && is_string( $health['status'] ) ) {
				$status = $health['status'];
			}
			if ( isset( $health['checked_at'] ) && is_numeric( $health['checked_at'] ) ) {
				$checkedAt = (int) $health['checked_at'];
			}
		}

		$label = self::statusLabel( $status );

		printf(
			'<p class="spreadconnect-card__status spreadconnect-card__status--%1$s">%2$s</p>',
			esc_attr( $status ),
			esc_html( $label )
		);

		if ( $checkedAt > 0 ) {
			$dateFormat = self::dateFormat();
			printf(
				'<p class="spreadconnect-card__meta">%1$s</p>',
				esc_html( sprintf(
					/* translators: %s is a date string. */
					__( 'Last check: %s', self::TEXT_DOMAIN ),
					date_i18n( $dateFormat, $checkedAt )
				) )
			);
		}

		if ( 'ok' !== $status ) {
			$retestUrl = function_exists( 'admin_url' )
				? admin_url( 'admin.php?page=spreadconnect&section=settings' )
				: '';
			printf(
				'<p class="spreadconnect-card__action"><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $retestUrl ),
				esc_html__( 'Test Connection', self::TEXT_DOMAIN )
			);
		}
	}

	/**
	 * Card 2 — Catalog.
	 *
	 * Reads the youngest `state='complete'` row from `SyncHistoryRepo` and
	 * renders `created_count + updated_count` linked products plus the
	 * localised `started_at` timestamp. Missing row → "no sync yet"
	 * empty-state.
	 */
	private static function renderCatalogCard(): void
	{
		$repo = new SyncHistoryRepo();
		$row  = $repo->findLatest();

		if ( null === $row ) {
			printf(
				'<p class="spreadconnect-card__empty">%1$s</p>',
				esc_html__( "No sync runs yet — click 'Sync now' to start.", self::TEXT_DOMAIN )
			);
			return;
		}

		$created = isset( $row['created_count'] ) ? (int) $row['created_count'] : 0;
		$updated = isset( $row['updated_count'] ) ? (int) $row['updated_count'] : 0;
		$linked  = $created + $updated;

		printf(
			'<p class="spreadconnect-card__count">%1$d</p>',
			(int) $linked
		);
		printf(
			'<p class="spreadconnect-card__meta">%1$s</p>',
			esc_html__( 'Linked', self::TEXT_DOMAIN )
		);

		$startedAt = isset( $row['started_at'] ) && is_string( $row['started_at'] )
			? $row['started_at']
			: '';
		if ( '' !== $startedAt ) {
			$ts = strtotime( $startedAt );
			if ( false !== $ts && $ts > 0 ) {
				printf(
					'<p class="spreadconnect-card__meta">%1$s %2$s</p>',
					esc_html__( 'Last sync:', self::TEXT_DOMAIN ),
					esc_html( date_i18n( self::dateFormat(), $ts ) )
				);
			}
		}
	}

	/**
	 * Card 3 — Orders (last 30 days).
	 *
	 * Performs ONE HPOS-aware aggregate query against `{prefix}wc_orders_meta`
	 * with `GROUP BY meta_value` to bucket orders into 4
	 * `_spreadconnect_state` counts (`NEW`, `CONFIRMED`, `PROCESSED`,
	 * `failed_to_submit`). The join against `{prefix}wc_orders` bounds the
	 * result to orders created within the last 30 days. Slice-46 AC-10
	 * mandates a single aggregate query (no per-state N+1) and HPOS-aware
	 * access (no direct `wp_postmeta` reads).
	 */
	private static function renderOrdersCard(): void
	{
		$counts = array_fill_keys( array_keys( self::ORDER_STATE_LABELS ), 0 );

		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$cutoffTs   = ( function_exists( 'time' ) ? time() : 0 ) - self::ORDERS_WINDOW_SECONDS;
			$dateAfter  = gmdate( 'Y-m-d H:i:s', $cutoffTs );
			$metaTable  = $wpdb->prefix . 'wc_orders_meta';
			$ordersTbl  = $wpdb->prefix . 'wc_orders';
			$stateKey   = '_spreadconnect_state';

			// Single aggregate query: GROUP BY meta_value bounded to the
			// 30-day window via JOIN on `wc_orders.date_created_gmt`.
			$sql = $wpdb->prepare(
				"SELECT m.meta_value AS state, COUNT(*) AS cnt
				 FROM `{$metaTable}` AS m
				 INNER JOIN `{$ordersTbl}` AS o ON o.id = m.order_id
				 WHERE m.meta_key = %s
				   AND o.date_created_gmt >= %s
				 GROUP BY m.meta_value",
				$stateKey,
				$dateAfter
			);

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$state = isset( $row['state'] ) ? (string) $row['state'] : '';
					$cnt   = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
					if ( array_key_exists( $state, $counts ) ) {
						$counts[ $state ] = $cnt;
					}
				}
			}
		}

		echo '<ul class="spreadconnect-card__counts">';
		foreach ( array_keys( self::ORDER_STATE_LABELS ) as $stateValue ) {
			$count = isset( $counts[ $stateValue ] ) ? (int) $counts[ $stateValue ] : 0;
			$label = self::orderStateLabel( $stateValue );
			printf(
				'<li><span class="spreadconnect-card__label">%1$s:</span> <span class="spreadconnect-card__count">%2$d</span></li>',
				esc_html( $label ),
				(int) $count
			);
		}
		echo '</ul>';
	}

	/**
	 * Card 4 — Webhooks.
	 *
	 * Reads (a) `SubscriptionManager::getCachedStatus()` for the
	 * `"X / 7 active"` line, and (b) `WebhookLogRepo::findLatest()` for
	 * the `received_at + event_type` of the last received event.
	 */
	private static function renderWebhooksCard(): void
	{
		$status = SubscriptionManager::getCachedStatus();
		$active = isset( $status['active'] ) ? (int) $status['active'] : 0;
		$total  = isset( $status['total'] ) ? (int) $status['total'] : 7;

		printf(
			'<p class="spreadconnect-card__count">%1$s</p>',
			esc_html(
				sprintf(
					/* translators: 1: active subscriptions, 2: total expected subscriptions. */
					__( '%1$d / %2$d active', self::TEXT_DOMAIN ),
					$active,
					$total
				)
			)
		);

		$latest = WebhookLogRepo::findLatest();
		if ( null === $latest ) {
			printf(
				'<p class="spreadconnect-card__empty">%1$s</p>',
				esc_html__( 'No event received yet.', self::TEXT_DOMAIN )
			);
			return;
		}

		$eventType  = isset( $latest['event_type'] ) && is_string( $latest['event_type'] )
			? $latest['event_type']
			: '';
		$receivedAt = isset( $latest['received_at'] ) && is_string( $latest['received_at'] )
			? $latest['received_at']
			: '';

		$ts = '' !== $receivedAt ? strtotime( $receivedAt ) : false;
		if ( false === $ts || $ts <= 0 ) {
			$ts = 0;
		}

		printf(
			'<p class="spreadconnect-card__meta">%1$s %2$s%3$s</p>',
			esc_html__( 'Received', self::TEXT_DOMAIN ),
			esc_html( $eventType ),
			$ts > 0
				? ' — ' . esc_html( date_i18n( self::dateFormat(), $ts ) )
				: ''
		);
	}

	/**
	 * Card 5 — Failed Operations.
	 *
	 * Reads `FailedOpsRepo::count('unresolved')` (Slice 37 — uses the
	 * `idx_state_op_type` index head per architecture.md Z. 208) and shows
	 * the count + a deep-link to the Failed-Ops sub-page. When at least one
	 * `error`-severity admin notice exists in addition, a red severity
	 * banner is rendered as a visual amplifier (Slice 39 AC-10).
	 */
	private static function renderFailedOpsCard(): void
	{
		global $wpdb;
		$repo  = new FailedOpsRepo( $wpdb );
		$count = $repo->count( FailedOpsRepo::STATE_UNRESOLVED );

		printf(
			'<p class="spreadconnect-card__count">%1$d</p>',
			(int) $count
		);
		printf(
			'<p class="spreadconnect-card__meta">%1$s</p>',
			esc_html__( 'Failed Operations', self::TEXT_DOMAIN )
		);

		$failedUrl = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=spreadconnect&section=failed' )
			: '';
		printf(
			'<p class="spreadconnect-card__action"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $failedUrl ),
			esc_html__( 'View in Failed-Ops', self::TEXT_DOMAIN )
		);

		if ( $count > 0 ) {
			$store    = new AdminNoticeStore();
			$errCount = $store->count( 'error' );
			if ( $errCount > 0 ) {
				printf(
					'<p class="spreadconnect-card__banner spreadconnect-card__banner--error">%1$s</p>',
					esc_html__( 'A permanent failure was recorded in the Failed-Ops queue.', self::TEXT_DOMAIN )
				);
			}
		}
	}

	/**
	 * Translate `sc_health.status` into the user-facing label.
	 */
	private static function statusLabel( string $status ): string
	{
		switch ( $status ) {
			case 'ok':
				return __( 'OK', self::TEXT_DOMAIN );
			case 'auth_failed':
				return __( 'Invalid Key — check value or environment', self::TEXT_DOMAIN );
			default:
				return __( 'unknown', self::TEXT_DOMAIN );
		}
	}

	/**
	 * Translate the dashboard card slug into its localised `<h2>` title.
	 *
	 * Each branch is a static-string `__()` call so the strings are
	 * extractable by `wp i18n make-pot` (Slice 46 AC-13). Adding a new card
	 * to {@see self::CARDS} requires a matching `case` here.
	 */
	private static function cardTitle( string $slug ): string
	{
		switch ( $slug ) {
			case 'connection':
				return __( 'Connection', self::TEXT_DOMAIN );
			case 'catalog':
				return __( 'Catalog', self::TEXT_DOMAIN );
			case 'orders':
				return __( 'Orders', self::TEXT_DOMAIN );
			case 'webhooks':
				return __( 'Webhooks', self::TEXT_DOMAIN );
			case 'failed-ops':
				return __( 'Failed Operations', self::TEXT_DOMAIN );
			default:
				return $slug;
		}
	}

	/**
	 * Translate the `_spreadconnect_state` enum value into its localised
	 * Orders-card label.
	 *
	 * Each branch is a static-string `__()` call so the strings are
	 * extractable by `wp i18n make-pot` (Slice 46 AC-13).
	 */
	private static function orderStateLabel( string $state ): string
	{
		switch ( $state ) {
			case 'NEW':
				return __( 'Pending', self::TEXT_DOMAIN );
			case 'CONFIRMED':
				return __( 'Confirmed', self::TEXT_DOMAIN );
			case 'PROCESSED':
				return __( 'Processed', self::TEXT_DOMAIN );
			case 'failed_to_submit':
				return __( 'Failed', self::TEXT_DOMAIN );
			default:
				return $state;
		}
	}

	/**
	 * Resolve the WP-configured date format (locale-aware via
	 * `get_option('date_format')`) with a sensible fallback for the unit-test
	 * bootstrap where `get_option` may be unstubbed.
	 */
	private static function dateFormat(): string
	{
		$value = function_exists( 'get_option' ) ? get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';
		return is_string( $value ) && '' !== $value ? $value : 'Y-m-d';
	}

	/**
	 * Render the per-card unavailable-fallback (Slice 46 AC-14).
	 *
	 * Logs the caught Throwable on the `spreadconnect-failure` source so
	 * operators can investigate via the Logs sub-page (Slice 42).
	 */
	private static function renderUnavailable( string $slug, \Throwable $e ): void
	{
		printf(
			'<p class="spreadconnect-card__error">%1$s</p>',
			esc_html__( 'An unexpected error occurred.', self::TEXT_DOMAIN )
		);

		WcLoggerAdapter::error(
			Sources::FAILURE,
			sprintf( 'Dashboard card "%s" render failed: %s', $slug, $e->getMessage() ),
			array( 'card' => $slug )
		);
	}
}
