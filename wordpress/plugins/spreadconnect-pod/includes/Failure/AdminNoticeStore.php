<?php
/**
 * AdminNoticeStore — option-backed persistent admin-notice list (slice-39).
 *
 * Persists "needs-action" notices in the `spreadconnect_admin_notices`
 * Option (autoload=false) so they survive page-loads and request
 * boundaries until the admin explicitly resolves / dismisses them. The
 * `admin_notices`-hook callback {@see self::renderAll()} renders one
 * `<div class="notice notice-{severity}">` per stored entry on every
 * WP-Admin page (gated on `manage_woocommerce`).
 *
 * Notice lifecycle:
 *   1. `add($failedOpRow)` — slice-39 producers (RetryPolicyListener after
 *      `record()`-insert, slice-30 Order.needs-action handler, slice-31
 *      Auto-Confirm-Pre-Check-Failure listener) push a notice.
 *   2. `renderAll()` — `admin_notices` hook callback emits HTML on every
 *      WP-Admin page.
 *   3. `removeByFailedOpId($id)` / `removeByNoticeId($noticeId)` — slice-38
 *      Failed-Ops UI invokes these on Resolve / Dismiss to retract a notice.
 *
 * Per-op-type policy:
 *   - `severity` (slice-39 AC-7) controls the WP-notice CSS class.
 *   - `dismiss_policy` (slice-39 AC-8) controls the action-button set
 *     rendered by {@see self::renderAll()}.
 *
 * Architecture refs:
 *   - architecture.md "Service Map" Z. 390 — `Failure\AdminNoticeStore`
 *     responsibility row (option-backed list with per-op-type dismiss
 *     policy).
 *   - architecture.md "Quality Attrs" Z. 680 — `email + Admin-Notice` as
 *     the Done-Signal for the Failure-Recovery pipeline.
 *   - architecture.md "Error Handling" Z. 607-608 — `4xx -> Admin-Notice`,
 *     `5xx after 3rd attempt -> Failed-Ops + email + Admin-Notice`.
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

/**
 * Stateless option-store wrapper for persistent admin-notices.
 *
 * `final` per slice-39 Constraints. No constructor parameters — the
 * class reads/writes the option directly via WP API and computes
 * severity/dismiss_policy from per-op-type maps.
 */
final class AdminNoticeStore
{
	/**
	 * Option key used for the notice list. `autoload=false` is mandatory
	 * (slice-39 Constraints) so the option is NOT loaded on every WP
	 * page-load when no admin context is active.
	 */
	public const OPTION_KEY = 'spreadconnect_admin_notices';

	/**
	 * Severity enum literals (slice-39 Provides-To).
	 */
	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_INFO    = 'info';

	/**
	 * Dismiss-policy enum literals (slice-39 Provides-To).
	 */
	public const DISMISS_POLICY_REQUIRES_RESOLUTION = 'requires_resolution';
	public const DISMISS_POLICY_MARK_RESOLVED       = 'mark_resolved';
	public const DISMISS_POLICY_DISMISSIBLE         = 'dismissible';

	/**
	 * Op-Type → severity map (slice-39 AC-7).
	 *
	 * Order pipeline failures escalate to `'error'`; sync + webhook
	 * pipelines stay at `'warning'`. Unknown op-types fall back to
	 * `'warning'` (defensive default — see {@see self::resolveSeverity()}).
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_SEVERITY = array(
		'create_order'           => self::SEVERITY_ERROR,
		'confirm_order'          => self::SEVERITY_ERROR,
		'cancel_order_mirror'    => self::SEVERITY_ERROR,
		'fetch_tracking'         => self::SEVERITY_ERROR,

		'sync_catalog'           => self::SEVERITY_WARNING,
		'sync_article'           => self::SEVERITY_WARNING,
		'handle_article_removed' => self::SEVERITY_WARNING,
		'scheduled_stock_sync'   => self::SEVERITY_WARNING,

		'handle_webhook'         => self::SEVERITY_WARNING,
	);

	/**
	 * Op-Type → dismiss_policy map (slice-39 AC-8).
	 *
	 * `create_order` requires the slice-38 3-Choice-Modal (the order may
	 * still be confirmed manually); the other order-pipeline ops are
	 * one-click resolvable; sync + webhook ops are plain-dismissible.
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_DISMISS_POLICY = array(
		'create_order'           => self::DISMISS_POLICY_REQUIRES_RESOLUTION,

		'confirm_order'          => self::DISMISS_POLICY_MARK_RESOLVED,
		'cancel_order_mirror'    => self::DISMISS_POLICY_MARK_RESOLVED,
		'fetch_tracking'         => self::DISMISS_POLICY_MARK_RESOLVED,

		'sync_catalog'           => self::DISMISS_POLICY_DISMISSIBLE,
		'sync_article'           => self::DISMISS_POLICY_DISMISSIBLE,
		'handle_article_removed' => self::DISMISS_POLICY_DISMISSIBLE,
		'scheduled_stock_sync'   => self::DISMISS_POLICY_DISMISSIBLE,

		'handle_webhook'         => self::DISMISS_POLICY_DISMISSIBLE,
	);

	/**
	 * Op-Type → human-readable label map for the rendered notice
	 * paragraph. Wrapped in `__()` at use-time for slice-46 i18n.
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_LABEL = array(
		'create_order'           => 'Order failed',
		'confirm_order'          => 'Order confirm failed',
		'cancel_order_mirror'    => 'Order cancel-mirror failed',
		'fetch_tracking'         => 'Tracking-fetch failed',
		'sync_catalog'           => 'Catalog-sync failed',
		'sync_article'           => 'Article-sync failed',
		'handle_article_removed' => 'Article-removal failed',
		'scheduled_stock_sync'   => 'Stock-sync failed',
		'handle_webhook'         => 'Webhook-processing failed',
	);

	/**
	 * Public no-op constructor. Bootstrapping is parameterless because
	 * the class reads/writes the WP-Option directly — DI for the option
	 * channel is unnecessary and would defeat the slice-39 Provides-To
	 * contract that the consumer can `new AdminNoticeStore()` without
	 * arguments.
	 */
	public function __construct()
	{
	}

	/**
	 * Push one Failed-Op row onto the notice list.
	 *
	 * Idempotent (slice-39 AC-6): a `notice_id` collision (same
	 * `failed_op_id`) is a silent no-op — `update_option()` is NOT
	 * called a second time and the method returns `false`.
	 *
	 * Side-effect: writes `update_option(..., $list, false)` with
	 * `autoload=false` (slice-39 Constraints — the option must not
	 * be loaded on every front-end page-load).
	 *
	 * @param array<string, mixed> $failedOpRow Row from
	 *                                          {@see FailedOpsRepo::findById()}.
	 *
	 * @return bool `true` on persist, `false` on duplicate / invalid input.
	 */
	public function add( array $failedOpRow ): bool
	{
		$failedOpId = isset( $failedOpRow['id'] ) ? (int) $failedOpRow['id'] : 0;
		if ( $failedOpId <= 0 ) {
			return false;
		}

		$noticeId = $this->buildNoticeId( $failedOpId );
		$list     = $this->loadList();

		// AC-6: idempotency guard — same notice_id → no-op.
		foreach ( $list as $existing ) {
			if ( isset( $existing['notice_id'] ) && $existing['notice_id'] === $noticeId ) {
				return false;
			}
		}

		$opType = isset( $failedOpRow['op_type'] ) ? (string) $failedOpRow['op_type'] : '';

		$entry = array(
			'notice_id'           => $noticeId,
			'failed_op_id'        => $failedOpId,
			'op_type'             => $opType,
			'related_entity_type' => isset( $failedOpRow['related_entity_type'] )
				? (string) $failedOpRow['related_entity_type']
				: '',
			'related_entity_id'   => isset( $failedOpRow['related_entity_id'] )
				? (string) $failedOpRow['related_entity_id']
				: '',
			'error_message'       => isset( $failedOpRow['error_message'] )
				? (string) $failedOpRow['error_message']
				: '',
			'error_code'          => isset( $failedOpRow['error_code'] )
				? (string) $failedOpRow['error_code']
				: '',
			'created_at'          => isset( $failedOpRow['created_at'] )
				? (string) $failedOpRow['created_at']
				: '',
			'severity'            => $this->resolveSeverity( $opType ),
			'dismiss_policy'      => $this->resolveDismissPolicy( $opType ),
		);

		$list[] = $entry;

		update_option( self::OPTION_KEY, $list, false );

		return true;
	}

	/**
	 * Return the full list of notices (sorted `created_at DESC`).
	 *
	 * Returns an empty array when the option is missing — never throws.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(): array
	{
		$list = $this->loadList();

		usort(
			$list,
			static function ( array $a, array $b ): int {
				$aCreated = isset( $a['created_at'] ) ? (string) $a['created_at'] : '';
				$bCreated = isset( $b['created_at'] ) ? (string) $b['created_at'] : '';
				return strcmp( $bCreated, $aCreated );
			}
		);

		return array_values( $list );
	}

	/**
	 * Count notices, optionally filtered by severity.
	 *
	 * @param string|null $severity One of `error`/`warning`/`info`,
	 *                              or `null` for the unfiltered count.
	 */
	public function count( ?string $severity = null ): int
	{
		$list = $this->loadList();

		if ( null === $severity ) {
			return count( $list );
		}

		$matches = 0;
		foreach ( $list as $entry ) {
			$entrySeverity = isset( $entry['severity'] ) ? (string) $entry['severity'] : '';
			if ( $entrySeverity === $severity ) {
				$matches++;
			}
		}

		return $matches;
	}

	/**
	 * Remove the notice associated with a specific Failed-Op id.
	 *
	 * Returns:
	 *   - `true`  when at least one entry was removed.
	 *   - `false` when no matching notice existed.
	 *
	 * Side-effect: persists the new list via `update_option(..., false)`.
	 * If the resulting list is empty, calls `delete_option()` instead so
	 * the row leaves the DB cleanly (slice-39 AC-9).
	 */
	public function removeByFailedOpId( int $id ): bool
	{
		if ( $id <= 0 ) {
			return false;
		}

		return $this->removeByPredicate(
			static function ( array $entry ) use ( $id ): bool {
				return isset( $entry['failed_op_id'] )
					&& (int) $entry['failed_op_id'] === $id;
			}
		);
	}

	/**
	 * Remove the notice with a specific `notice_id`.
	 *
	 * Same return-value + persistence semantics as
	 * {@see self::removeByFailedOpId()}.
	 */
	public function removeByNoticeId( string $noticeId ): bool
	{
		if ( '' === $noticeId ) {
			return false;
		}

		return $this->removeByPredicate(
			static function ( array $entry ) use ( $noticeId ): bool {
				return isset( $entry['notice_id'] )
					&& (string) $entry['notice_id'] === $noticeId;
			}
		);
	}

	/**
	 * `admin_notices`-hook callback (slice-39 AC-11 / AC-12).
	 *
	 * Renders ONE `<div class="notice notice-{severity}">` block per
	 * stored entry on every WP-Admin page. Capability-check is the very
	 * first statement — without `manage_woocommerce` the method emits
	 * nothing.
	 *
	 * Per-policy CTAs:
	 *   - `requires_resolution` → `[View in Failed-Ops]` link, NO
	 *     plain-dismiss (slice-38 3-Choice-Modal owns the resolve flow).
	 *   - `mark_resolved`       → `[Mark Resolved]` button + `[View Detail]`
	 *     link.
	 *   - `dismissible`         → WP-native `is-dismissible` class +
	 *     `[View Detail]` link.
	 *
	 * Slice 39 emits only the HTML markup + a `wp_create_nonce` hidden
	 * input; the AJAX wiring lives in slice-38.
	 */
	public function renderAll(): void
	{
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$list = $this->findAll();
		if ( array() === $list ) {
			return;
		}

		$nonce = function_exists( 'wp_create_nonce' )
			? (string) wp_create_nonce( 'spreadconnect_dismiss_notice' )
			: '';

		foreach ( $list as $entry ) {
			$this->renderOne( $entry, $nonce );
		}
	}

	/**
	 * Render one notice HTML block (private to keep
	 * {@see self::renderAll()} thin).
	 *
	 * @param array<string, mixed> $entry
	 */
	private function renderOne( array $entry, string $nonce ): void
	{
		$severity      = isset( $entry['severity'] ) ? (string) $entry['severity'] : self::SEVERITY_WARNING;
		$dismissPolicy = isset( $entry['dismiss_policy'] )
			? (string) $entry['dismiss_policy']
			: self::DISMISS_POLICY_DISMISSIBLE;
		$opType        = isset( $entry['op_type'] ) ? (string) $entry['op_type'] : '';
		$entityId      = isset( $entry['related_entity_id'] ) ? (string) $entry['related_entity_id'] : '';
		$errorMessage  = isset( $entry['error_message'] ) ? (string) $entry['error_message'] : '';
		$failedOpId    = isset( $entry['failed_op_id'] ) ? (int) $entry['failed_op_id'] : 0;
		$noticeId      = isset( $entry['notice_id'] ) ? (string) $entry['notice_id'] : '';

		$labelKey = self::OP_TYPE_LABEL[ $opType ] ?? 'Operation failed';
		$label    = __( $labelKey, 'spreadconnect-pod' );

		$cssClasses = array( 'notice', 'notice-' . $severity, 'spreadconnect-failure-notice' );
		if ( self::DISMISS_POLICY_DISMISSIBLE === $dismissPolicy ) {
			$cssClasses[] = 'is-dismissible';
		}

		$detailUrl = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=spreadconnect&section=failed&highlight=' . $failedOpId )
			: 'admin.php?page=spreadconnect&section=failed&highlight=' . $failedOpId;

		echo '<div class="' . esc_attr( implode( ' ', $cssClasses ) ) . '"'
			. ' data-notice-id="' . esc_attr( $noticeId ) . '"'
			. ' data-failed-op-id="' . esc_attr( (string) $failedOpId ) . '"'
			. ' data-dismiss-policy="' . esc_attr( $dismissPolicy ) . '"'
			. '>';

		echo '<p>';
		echo '<strong>[Spreadconnect]</strong> ';
		echo esc_html( $label );
		if ( '' !== $entityId ) {
			echo ' &mdash; #' . esc_html( $entityId );
		}
		if ( '' !== $errorMessage ) {
			echo '<br />';
			echo esc_html( $errorMessage );
		}
		echo '</p>';

		echo '<p class="actions">';
		$this->renderActions( $dismissPolicy, $failedOpId, $detailUrl );
		echo '</p>';

		// Hidden nonce hand-off for the slice-38 AJAX handlers.
		if ( '' !== $nonce ) {
			echo '<input type="hidden" class="spreadconnect-notice-nonce" value="'
				. esc_attr( $nonce ) . '" />';
		}

		echo '</div>';
	}

	/**
	 * Render the action-button row per dismiss-policy (slice-39 AC-12).
	 */
	private function renderActions( string $dismissPolicy, int $failedOpId, string $detailUrl ): void
	{
		switch ( $dismissPolicy ) {
			case self::DISMISS_POLICY_REQUIRES_RESOLUTION:
				echo '<a class="button button-primary spreadconnect-view-failed-op" href="'
					. esc_url( $detailUrl ) . '">'
					. esc_html__( 'View in Failed-Ops', 'spreadconnect-pod' )
					. '</a>';
				return;

			case self::DISMISS_POLICY_MARK_RESOLVED:
				echo '<button type="button" class="button button-primary spreadconnect-mark-resolved"'
					. ' data-failed-op-id="' . esc_attr( (string) $failedOpId ) . '">'
					. esc_html__( 'Mark Resolved', 'spreadconnect-pod' )
					. '</button> ';
				echo '<a class="button spreadconnect-view-failed-op" href="'
					. esc_url( $detailUrl ) . '">'
					. esc_html__( 'View Detail', 'spreadconnect-pod' )
					. '</a>';
				return;

			case self::DISMISS_POLICY_DISMISSIBLE:
			default:
				echo '<a class="button spreadconnect-view-failed-op" href="'
					. esc_url( $detailUrl ) . '">'
					. esc_html__( 'View Detail', 'spreadconnect-pod' )
					. '</a>';
				return;
		}
	}

	/**
	 * Removal worker shared by {@see self::removeByFailedOpId()} and
	 * {@see self::removeByNoticeId()}.
	 *
	 * @param callable(array<string, mixed>):bool $predicate Returns true
	 *                                                       for entries
	 *                                                       to remove.
	 */
	private function removeByPredicate( callable $predicate ): bool
	{
		$list = $this->loadList();
		if ( array() === $list ) {
			return false;
		}

		$kept    = array();
		$removed = false;
		foreach ( $list as $entry ) {
			if ( $predicate( $entry ) ) {
				$removed = true;
				continue;
			}
			$kept[] = $entry;
		}

		if ( ! $removed ) {
			return false;
		}

		if ( array() === $kept ) {
			delete_option( self::OPTION_KEY );
		} else {
			update_option( self::OPTION_KEY, array_values( $kept ), false );
		}

		return true;
	}

	/**
	 * Resolve `op_type` → `severity`. Unknown op-types fall back to
	 * `'warning'` (slice-39 AC-7 defensive default).
	 */
	private function resolveSeverity( string $opType ): string
	{
		return self::OP_TYPE_SEVERITY[ $opType ] ?? self::SEVERITY_WARNING;
	}

	/**
	 * Resolve `op_type` → `dismiss_policy`. Unknown op-types fall back
	 * to `'dismissible'` (defensive default — same level of friction as
	 * sync/webhook ops).
	 */
	private function resolveDismissPolicy( string $opType ): string
	{
		return self::OP_TYPE_DISMISS_POLICY[ $opType ] ?? self::DISMISS_POLICY_DISMISSIBLE;
	}

	/**
	 * Deterministic notice-id (slice-39 AC-5 + Constraints).
	 */
	private function buildNoticeId( int $failedOpId ): string
	{
		return 'failed_op_' . $failedOpId;
	}

	/**
	 * Read the notice list from WP-Options.
	 *
	 * Returns an empty array when the option is missing or carries a
	 * non-array value (defensive — a hand-edited DB row should never
	 * crash the renderer).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function loadList(): array
	{
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = $entry;
			}
		}

		return $out;
	}
}
