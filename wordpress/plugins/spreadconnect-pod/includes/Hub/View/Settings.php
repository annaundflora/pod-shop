<?php
/**
 * Settings sub-page renderer (Hub Section "Settings").
 *
 * Mounts the WP Settings API for the 17 user-editable `spreadconnect_*`
 * options. Sections ① API Connection, ⑥ Order Behavior, ⑦ Catalog Sync and
 * ⑧ Failure Notifications are wired here. Sections ② Test-Connection
 * (slice-12), ③ Webhook Security (slice-14), ⑨ Footer Export/Import
 * (slice-45) and the conditional Dev-Tools section (slice-44) are reserved
 * via clearly marked extension slots in {@see self::render()} — those
 * follow-up slices append non-overlapping output blocks at the marked
 * positions. They do NOT register their own settings fields against the
 * `spreadconnect_settings` group from slice-11.
 *
 * @package SpreadconnectPod\Hub\View
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\View;

use SpreadconnectPod\Bootstrap\OptionsDefaults;
use SpreadconnectPod\Settings\SettingsValidator;

/**
 * Stateless renderer + Settings-API registrar for the Settings sub-page.
 *
 * Two static entry-points:
 *   - {@see self::registerSettings()} — call on `admin_init`. Registers the
 *     `spreadconnect_settings` group, four sections and 17 fields with
 *     {@see SettingsValidator::sanitize()} as the sanitiser for each.
 *   - {@see self::render()} — call from the Hub Controller (slice-13)
 *     when `?section=settings`. Capability-gated on `manage_woocommerce`.
 *
 * Final + only static methods because the page is stateless: every call
 * reads fresh from the options table and writes back through the Settings
 * API. The `cb*` callbacks are public so WP can invoke them through the
 * `[ self::class, 'cbName' ]` array-callable form.
 */
final class Settings
{
	/**
	 * Slug of the Settings group / page registered with the WP Settings API.
	 *
	 * Used as the first argument to `register_setting()`,
	 * `add_settings_section()`, `add_settings_field()` and
	 * `settings_fields()` so all four call sites share a single source of
	 * truth.
	 */
	public const OPTION_GROUP = 'spreadconnect_settings';

	/**
	 * Text-domain for translation wrappers.
	 *
	 * Centralised so a future rename (slice-46+) only edits one constant
	 * rather than ~40 inlined string literals.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Section IDs for the four sections this slice owns.
	 *
	 * Out-of-scope sections (`section_test_connection` from slice-12,
	 * `section_webhook_security` from slice-14, `section_dev_tools` from
	 * slice-44, `section_footer` from slice-45) are NOT registered via
	 * {@see add_settings_section()} here — they are inline output slots
	 * inside {@see self::render()}.
	 */
	private const SECTION_API           = 'spreadconnect_section_api';
	private const SECTION_ORDER         = 'spreadconnect_section_order';
	private const SECTION_CATALOG       = 'spreadconnect_section_catalog';
	private const SECTION_NOTIFICATIONS = 'spreadconnect_section_notifications';

	/**
	 * Render the Settings page.
	 *
	 * Wired via the Hub Controller in slice-13 (`?page=spreadconnect&section=settings`).
	 * Capability-gated on `manage_woocommerce` — users without that cap are
	 * rejected with `wp_die()` per slice-11 AC-9 (the WC-canonical
	 * permission for the SC POD admin surface).
	 *
	 * Layout follows wireframes.md "Screen 7: Settings (Hub Sub-Page)":
	 *   ① API Connection (WP fields + slice-12 Test-Connection slot)
	 *   ③ Webhook Security (slice-14 slot — empty here)
	 *   ⑥ Order Behavior (WP fields)
	 *   ⑦ Catalog Sync (WP fields)
	 *   ⑧ Failure Notifications (WP fields)
	 *   Dev-Tools (slice-44 slot, only when staging — empty here)
	 *   ⑨ Footer (WP submit + slice-45 export/import slot)
	 *
	 * @return void
	 */
	public static function render(): void
	{
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', self::TEXT_DOMAIN )
			);
		}

		echo '<div class="wrap spreadconnect-settings">';
		echo '<h1>' . esc_html__( 'Spreadconnect Settings', self::TEXT_DOMAIN ) . '</h1>';

		echo '<form method="post" action="options.php" class="spreadconnect-settings-form">';
		settings_fields( self::OPTION_GROUP );

		// WP renders all four registered sections + their fields here.
		// Sections appear in registration order: API > Order > Catalog >
		// Notifications. The slot blocks below interleave with this output
		// in slice-12/14/44/45 via dedicated `do_action()` extension points.
		do_settings_sections( 'spreadconnect-settings' );

		// ---- Extension slot: section ② Test Connection (slice-12) ----
		// Slice-12 hooks into this action to render the [Test This Key]
		// button + status pane next to the API-Connection section. Slice-11
		// fires the action with no listeners attached.
		do_action( 'spreadconnect_settings_section_test_connection' );

		// ---- Extension slot: section ③ Webhook Security (slice-14) ----
		// Slice-14 hooks here to render the Webhook URL + masked HMAC
		// secret + [Regenerate Secret] panel.
		do_action( 'spreadconnect_settings_section_webhook_security' );

		// ---- Extension slot: Dev-Tools (slice-44) ----
		// Slice-44 hooks here and conditionally renders the Simulate-* test
		// buttons when `spreadconnect_use_staging` is true.
		do_action( 'spreadconnect_settings_section_dev_tools' );

		// ---- Footer ⑨ : core Save button + slice-45 Export/Import slot.
		echo '<p class="submit">';
		submit_button(
			esc_html__( 'Save Changes', self::TEXT_DOMAIN ),
			'primary',
			'submit',
			false
		);
		// Slice-45 hooks here to append [Export Settings JSON] +
		// [Import Settings JSON] buttons.
		do_action( 'spreadconnect_settings_section_footer' );
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Register the Settings group, sections and fields.
	 *
	 * Hooked from `Bootstrap\Plugin` (slice-13) on `admin_init`. One
	 * `register_setting()` call per managed option keeps the storage layout
	 * 1:1 with the architecture-spec table (`architecture.md` Z. 323-340)
	 * and lets slice-45 export/import each key independently.
	 *
	 * Capability gate is enforced upstream — `admin_init` only fires for
	 * authenticated admin requests, and slice-11 AC-9 places the
	 * `manage_woocommerce` check at {@see self::render()}.
	 *
	 * @return void
	 */
	public static function registerSettings(): void
	{
		// --- Sections ----------------------------------------------------.
		add_settings_section(
			self::SECTION_API,
			esc_html__( 'API Connection', self::TEXT_DOMAIN ),
			array( self::class, 'cbSectionApiIntro' ),
			'spreadconnect-settings'
		);

		add_settings_section(
			self::SECTION_ORDER,
			esc_html__( 'Order Behavior', self::TEXT_DOMAIN ),
			array( self::class, 'cbSectionOrderIntro' ),
			'spreadconnect-settings'
		);

		add_settings_section(
			self::SECTION_CATALOG,
			esc_html__( 'Catalog Sync', self::TEXT_DOMAIN ),
			array( self::class, 'cbSectionCatalogIntro' ),
			'spreadconnect-settings'
		);

		add_settings_section(
			self::SECTION_NOTIFICATIONS,
			esc_html__( 'Failure Notifications', self::TEXT_DOMAIN ),
			array( self::class, 'cbSectionNotificationsIntro' ),
			'spreadconnect-settings'
		);

		// --- Per-option `register_setting()` + field rows ----------------.
		// One sanitize_callback per option, all pointing to the SAME
		// `SettingsValidator::sanitize` (slice-11 AC-1). The cross-field
		// auto-confirm gating happens inside the sanitiser, so it does not
		// matter which specific option's save triggered the call.
		$sanitize = array( SettingsValidator::class, 'sanitize' );

		// ① API Connection.
		self::registerOption( 'spreadconnect_api_key', $sanitize, 'string' );
		self::addField(
			'spreadconnect_api_key',
			esc_html__( 'API Key', self::TEXT_DOMAIN ),
			array( self::class, 'cbApiKey' ),
			self::SECTION_API
		);

		self::registerOption( 'spreadconnect_use_staging', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_use_staging',
			esc_html__( 'Use Staging API', self::TEXT_DOMAIN ),
			array( self::class, 'cbUseStaging' ),
			self::SECTION_API
		);

		// ⑥ Order Behavior.
		self::registerOption( 'spreadconnect_default_shipping_type', $sanitize, 'string' );
		self::addField(
			'spreadconnect_default_shipping_type',
			esc_html__( 'Default Shipping Type', self::TEXT_DOMAIN ),
			array( self::class, 'cbDefaultShippingType' ),
			self::SECTION_ORDER
		);

		self::registerOption( 'spreadconnect_auto_confirm', $sanitize, 'string' );
		self::addField(
			'spreadconnect_auto_confirm',
			esc_html__( 'Auto-Confirm', self::TEXT_DOMAIN ),
			array( self::class, 'cbAutoConfirm' ),
			self::SECTION_ORDER
		);

		self::registerOption( 'spreadconnect_auto_confirm_minutes', $sanitize, 'integer' );
		self::addField(
			'spreadconnect_auto_confirm_minutes',
			esc_html__( 'Auto-Confirm Minutes', self::TEXT_DOMAIN ),
			array( self::class, 'cbAutoConfirmMinutes' ),
			self::SECTION_ORDER
		);

		self::registerOption( 'spreadconnect_auto_cancel_mirror', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_auto_cancel_mirror',
			esc_html__( 'Auto-Cancel Mirror', self::TEXT_DOMAIN ),
			array( self::class, 'cbAutoCancelMirror' ),
			self::SECTION_ORDER
		);

		// ⑦ Catalog Sync.
		self::registerOption( 'spreadconnect_pull_images', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_pull_images',
			esc_html__( 'Pull Images on Sync', self::TEXT_DOMAIN ),
			array( self::class, 'cbPullImages' ),
			self::SECTION_CATALOG
		);

		self::registerOption( 'spreadconnect_force_repull_images', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_force_repull_images',
			esc_html__( 'Force Re-Pull Images on Next Run', self::TEXT_DOMAIN ),
			array( self::class, 'cbForceRepullImages' ),
			self::SECTION_CATALOG
		);

		self::registerOption( 'spreadconnect_stock_sync_interval', $sanitize, 'string' );
		self::addField(
			'spreadconnect_stock_sync_interval',
			esc_html__( 'Periodic Stock-Sync Interval', self::TEXT_DOMAIN ),
			array( self::class, 'cbStockSyncInterval' ),
			self::SECTION_CATALOG
		);

		self::registerOption( 'spreadconnect_low_stock_threshold', $sanitize, 'integer' );
		self::addField(
			'spreadconnect_low_stock_threshold',
			esc_html__( 'Low-Stock Threshold', self::TEXT_DOMAIN ),
			array( self::class, 'cbLowStockThreshold' ),
			self::SECTION_CATALOG
		);

		self::registerOption( 'spreadconnect_live_cache_ttl_seconds', $sanitize, 'integer' );
		self::addField(
			'spreadconnect_live_cache_ttl_seconds',
			esc_html__( 'Live-Cache TTL (seconds)', self::TEXT_DOMAIN ),
			array( self::class, 'cbLiveCacheTtl' ),
			self::SECTION_CATALOG
		);

		// ⑧ Failure Notifications.
		self::registerOption( 'spreadconnect_notify_emails', $sanitize, 'string' );
		self::addField(
			'spreadconnect_notify_emails',
			esc_html__( 'Recipients (comma-separated)', self::TEXT_DOMAIN ),
			array( self::class, 'cbNotifyEmails' ),
			self::SECTION_NOTIFICATIONS
		);

		self::registerOption( 'spreadconnect_notify_on_order_failure', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_notify_on_order_failure',
			esc_html__( 'Notify on Order Failure', self::TEXT_DOMAIN ),
			array( self::class, 'cbNotifyOnOrderFailure' ),
			self::SECTION_NOTIFICATIONS
		);

		self::registerOption( 'spreadconnect_notify_on_sync_failure', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_notify_on_sync_failure',
			esc_html__( 'Notify on Sync Failure', self::TEXT_DOMAIN ),
			array( self::class, 'cbNotifyOnSyncFailure' ),
			self::SECTION_NOTIFICATIONS
		);

		self::registerOption( 'spreadconnect_notify_on_webhook_failure', $sanitize, 'boolean' );
		self::addField(
			'spreadconnect_notify_on_webhook_failure',
			esc_html__( 'Notify on Webhook Failure', self::TEXT_DOMAIN ),
			array( self::class, 'cbNotifyOnWebhookFailure' ),
			self::SECTION_NOTIFICATIONS
		);

		self::registerOption( 'spreadconnect_failed_ops_retention_days', $sanitize, 'integer' );
		self::addField(
			'spreadconnect_failed_ops_retention_days',
			esc_html__( 'Failed-Ops Retention (days)', self::TEXT_DOMAIN ),
			array( self::class, 'cbFailedOpsRetentionDays' ),
			self::SECTION_NOTIFICATIONS
		);

		self::registerOption( 'spreadconnect_webhook_log_retention_days', $sanitize, 'integer' );
		self::addField(
			'spreadconnect_webhook_log_retention_days',
			esc_html__( 'Webhook-Log Retention (days)', self::TEXT_DOMAIN ),
			array( self::class, 'cbWebhookLogRetentionDays' ),
			self::SECTION_NOTIFICATIONS
		);
	}

	/**
	 * Convenience wrapper around `register_setting()`.
	 *
	 * Centralises the `option_group`, `sanitize_callback` and `type`
	 * arguments so each call site reads as a single line. Slice-11 AC-1
	 * mandates that EVERY option uses the SAME sanitiser
	 * ({@see SettingsValidator::sanitize}).
	 *
	 * @param string                $option_name Full `spreadconnect_*` key.
	 * @param array{0:string,1:string} $sanitize  `[ class, method ]` callable.
	 * @param string                $type        Hint for WP REST: `'string'`,
	 *                                           `'boolean'` or `'integer'`.
	 */
	private static function registerOption( string $option_name, array $sanitize, string $type ): void
	{
		register_setting(
			self::OPTION_GROUP,
			$option_name,
			array(
				'type'              => $type,
				'sanitize_callback' => $sanitize,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Convenience wrapper around `add_settings_field()`.
	 *
	 * @param string                  $option_name Field id (= option key).
	 * @param string                  $label       Translated field label.
	 * @param array{0:string,1:string} $callback   Render callback.
	 * @param string                  $section     Section id.
	 */
	private static function addField( string $option_name, string $label, array $callback, string $section ): void
	{
		add_settings_field(
			$option_name,
			$label,
			$callback,
			'spreadconnect-settings',
			$section,
			array( 'label_for' => $option_name )
		);
	}

	/**
	 * Look up an option, falling back to the defaults table on miss.
	 *
	 * Centralises the `get_option( $key, OptionsDefaults::DEFAULTS[ $key ] )`
	 * pattern from slice-11 Integration Contract so the field renderers stay
	 * single-line.
	 *
	 * @return string|int|bool
	 */
	private static function getOption( string $key ): string|int|bool
	{
		return get_option( $key, OptionsDefaults::DEFAULTS[ $key ] );
	}

	// =====================================================================
	// Section intro callbacks
	// =====================================================================

	/**
	 * Render the API Connection section intro paragraph.
	 *
	 * @return void
	 */
	public static function cbSectionApiIntro(): void
	{
		echo '<p>' . esc_html__(
			'Configure the credentials used for outbound calls to Spreadconnect. The API key is stored masked; click [Show] to reveal it.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Order Behavior section intro paragraph.
	 *
	 * @return void
	 */
	public static function cbSectionOrderIntro(): void
	{
		echo '<p>' . esc_html__(
			'Choose how WooCommerce orders are pre-filled before submission to Spreadconnect. Auto-Confirm requires a default shipping type to be set.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Catalog Sync section intro paragraph.
	 *
	 * @return void
	 */
	public static function cbSectionCatalogIntro(): void
	{
		echo '<p>' . esc_html__(
			'Control how often stock is pulled from Spreadconnect and how images are synchronised on catalog sync runs.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Failure Notifications section intro paragraph.
	 *
	 * @return void
	 */
	public static function cbSectionNotificationsIntro(): void
	{
		echo '<p>' . esc_html__(
			'Configure email recipients and retention windows for permanently failed operations.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	// =====================================================================
	// Field render callbacks (① API Connection)
	// =====================================================================

	/**
	 * Render the API-Key text input (masked).
	 *
	 * @return void
	 */
	public static function cbApiKey(): void
	{
		$value = (string) self::getOption( 'spreadconnect_api_key' );
		printf(
			'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( 'spreadconnect_api_key' ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__(
			'Bearer token issued in the Spreadconnect partner dashboard. Stored as plaintext in `wp_options`; rotate via the partner dashboard if leaked.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Use-Staging checkbox.
	 *
	 * @return void
	 */
	public static function cbUseStaging(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_use_staging' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_use_staging' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Route requests to the Spreadconnect staging environment.', self::TEXT_DOMAIN )
		);
	}

	// =====================================================================
	// Field render callbacks (⑥ Order Behavior)
	// =====================================================================

	/**
	 * Render the Default-Shipping-Type input.
	 *
	 * Slice-11 accepts any non-empty string here; slice-12/29 will replace
	 * this with a populated dropdown based on `GET /shippingTypes`.
	 *
	 * @return void
	 */
	public static function cbDefaultShippingType(): void
	{
		$value = (string) self::getOption( 'spreadconnect_default_shipping_type' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( 'spreadconnect_default_shipping_type' ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__(
			'Spreadconnect shippingType.id (e.g. STANDARD, PREMIUM, EXPRESS). Leave empty to require per-order selection; required to enable Auto-Confirm.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Auto-Confirm radio group.
	 *
	 * @return void
	 */
	public static function cbAutoConfirm(): void
	{
		$value = (string) self::getOption( 'spreadconnect_auto_confirm' );

		$options = array(
			'off'           => __( 'Off (recommended)', self::TEXT_DOMAIN ),
			'immediate'     => __( 'Immediately after submit', self::TEXT_DOMAIN ),
			'after_minutes' => __( 'After N minutes (see below)', self::TEXT_DOMAIN ),
		);

		echo '<fieldset>';
		foreach ( $options as $option_value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( 'spreadconnect_auto_confirm' ),
				esc_attr( $option_value ),
				$value === $option_value ? ' checked="checked"' : '',
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__(
			'Forced to "Off" while Default Shipping Type is empty.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Auto-Confirm-Minutes integer input.
	 *
	 * @return void
	 */
	public static function cbAutoConfirmMinutes(): void
	{
		$value = (int) self::getOption( 'spreadconnect_auto_confirm_minutes' );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="0" step="1" class="small-text" />',
			esc_attr( 'spreadconnect_auto_confirm_minutes' ),
			$value
		);
		echo '<p class="description">' . esc_html__(
			'Only relevant when Auto-Confirm is set to "After N minutes".',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Auto-Cancel-Mirror checkbox.
	 *
	 * @return void
	 */
	public static function cbAutoCancelMirror(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_auto_cancel_mirror' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_auto_cancel_mirror' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Cancel the Spreadconnect order automatically when the WooCommerce order is cancelled (only while SC state = NEW).', self::TEXT_DOMAIN )
		);
	}

	// =====================================================================
	// Field render callbacks (⑦ Catalog Sync)
	// =====================================================================

	/**
	 * Render the Pull-Images checkbox.
	 *
	 * @return void
	 */
	public static function cbPullImages(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_pull_images' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_pull_images' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Pull product images during catalog sync.', self::TEXT_DOMAIN )
		);
	}

	/**
	 * Render the Force-Repull-Images checkbox.
	 *
	 * @return void
	 */
	public static function cbForceRepullImages(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_force_repull_images' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_force_repull_images' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'On the next catalog sync, re-pull all images even when the local copy is up-to-date.', self::TEXT_DOMAIN )
		);
	}

	/**
	 * Render the Stock-Sync-Interval dropdown.
	 *
	 * @return void
	 */
	public static function cbStockSyncInterval(): void
	{
		$value = (string) self::getOption( 'spreadconnect_stock_sync_interval' );

		$options = array(
			'1h'  => __( 'Every hour', self::TEXT_DOMAIN ),
			'4h'  => __( 'Every 4 hours', self::TEXT_DOMAIN ),
			'6h'  => __( 'Every 6 hours (recommended)', self::TEXT_DOMAIN ),
			'12h' => __( 'Every 12 hours', self::TEXT_DOMAIN ),
			'24h' => __( 'Every 24 hours', self::TEXT_DOMAIN ),
		);

		echo '<select id="' . esc_attr( 'spreadconnect_stock_sync_interval' ) . '" name="' . esc_attr( 'spreadconnect_stock_sync_interval' ) . '">';
		foreach ( $options as $option_value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $option_value ),
				$value === $option_value ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render the Low-Stock-Threshold integer input.
	 *
	 * @return void
	 */
	public static function cbLowStockThreshold(): void
	{
		$value = (int) self::getOption( 'spreadconnect_low_stock_threshold' );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="0" step="1" class="small-text" />',
			esc_attr( 'spreadconnect_low_stock_threshold' ),
			$value
		);
		echo '<p class="description">' . esc_html__(
			'Stock units below this number are flagged in the WooCommerce admin.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Live-Cache-TTL integer input (60..900 seconds).
	 *
	 * @return void
	 */
	public static function cbLiveCacheTtl(): void
	{
		$value = (int) self::getOption( 'spreadconnect_live_cache_ttl_seconds' );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="60" max="900" step="1" class="small-text" />',
			esc_attr( 'spreadconnect_live_cache_ttl_seconds' ),
			$value
		);
		echo '<p class="description">' . esc_html__(
			'Time-to-live for the per-SKU live-stock transient cache. Allowed range: 60–900 seconds.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	// =====================================================================
	// Field render callbacks (⑧ Failure Notifications)
	// =====================================================================

	/**
	 * Render the Notify-Emails text input (comma-separated).
	 *
	 * @return void
	 */
	public static function cbNotifyEmails(): void
	{
		$value = (string) self::getOption( 'spreadconnect_notify_emails' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( 'spreadconnect_notify_emails' ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__(
			'Comma-separated list of recipients. Invalid tokens are dropped on save.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Notify-On-Order-Failure checkbox.
	 *
	 * @return void
	 */
	public static function cbNotifyOnOrderFailure(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_notify_on_order_failure' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_notify_on_order_failure' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Send an email when an order submission permanently fails.', self::TEXT_DOMAIN )
		);
	}

	/**
	 * Render the Notify-On-Sync-Failure checkbox.
	 *
	 * @return void
	 */
	public static function cbNotifyOnSyncFailure(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_notify_on_sync_failure' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_notify_on_sync_failure' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Send an email when a catalog or stock sync run permanently fails.', self::TEXT_DOMAIN )
		);
	}

	/**
	 * Render the Notify-On-Webhook-Failure checkbox.
	 *
	 * @return void
	 */
	public static function cbNotifyOnWebhookFailure(): void
	{
		$checked = (bool) self::getOption( 'spreadconnect_notify_on_webhook_failure' );
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( 'spreadconnect_notify_on_webhook_failure' ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Send an email when an inbound webhook permanently fails.', self::TEXT_DOMAIN )
		);
	}

	/**
	 * Render the Failed-Ops-Retention-Days integer input (7..365).
	 *
	 * @return void
	 */
	public static function cbFailedOpsRetentionDays(): void
	{
		$value = (int) self::getOption( 'spreadconnect_failed_ops_retention_days' );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="7" max="365" step="1" class="small-text" />',
			esc_attr( 'spreadconnect_failed_ops_retention_days' ),
			$value
		);
		echo '<p class="description">' . esc_html__(
			'Days to retain rows in `wp_spreadconnect_failed_ops` before purging. Allowed range: 7–365 days.',
			self::TEXT_DOMAIN
		) . '</p>';
	}

	/**
	 * Render the Webhook-Log-Retention-Days integer input (7..365).
	 *
	 * @return void
	 */
	public static function cbWebhookLogRetentionDays(): void
	{
		$value = (int) self::getOption( 'spreadconnect_webhook_log_retention_days' );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="7" max="365" step="1" class="small-text" />',
			esc_attr( 'spreadconnect_webhook_log_retention_days' ),
			$value
		);
		echo '<p class="description">' . esc_html__(
			'Days to retain rows in `wp_spreadconnect_webhook_log` before purging. Allowed range: 7–365 days.',
			self::TEXT_DOMAIN
		) . '</p>';
	}
}
