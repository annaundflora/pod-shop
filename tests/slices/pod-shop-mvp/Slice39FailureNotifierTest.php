<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 39 — Failure-Notifier + Persistent Admin-Notice-Store
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-39-failure-notifier.md
//
// Mocking strategy per spec (`mock_external`):
//   - `wp_mail`, `is_email`, `get_option`, `update_option`, `delete_option`,
//     `current_user_can`, `is_admin`, `admin_url`, `wp_create_nonce`,
//     `esc_html`, `esc_attr`, `esc_url`, `esc_html__`, `__` aliased via
//     Brain\Monkey/Patchwork.
//   - `wc_get_logger` returns a logger-spy (anonymous class) so the
//     WcLoggerAdapter forwards into a recordable sink — slice-14/15 pattern.
//   - `FailedOpsRepo` doubled via Mockery for AC-13 listener wiring.
//
// Each test maps 1:1 to a slice-39 acceptance criterion via the docblock
// GIVEN/WHEN/THEN. AC-1..AC-4 cover the FailureNotifier; AC-5..AC-12 cover
// the AdminNoticeStore; AC-13 covers the Bootstrap wiring + RetryPolicyListener
// hook-handoff.
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Failure\AdminNoticeStore;
	use SpreadconnectPod\Failure\FailedOpsRepo;
	use SpreadconnectPod\Failure\FailureNotifier;
	use SpreadconnectPod\Failure\RetryPolicyListener;

	/**
	 * Slice 39 acceptance + integration tests.
	 *
	 * AC-1..AC-4 → FailureNotifier::dispatch().
	 * AC-5..AC-12 → AdminNoticeStore (add/findAll/count/remove/renderAll).
	 * AC-13 → Bootstrap admin_notices hook-registration + RetryPolicyListener
	 *         dispatch+add wiring after FailedOpsRepo::record() insert.
	 */
	final class Slice39FailureNotifierTest extends TestCase
	{
		/**
		 * In-memory option-store for `get_option`/`update_option`/`delete_option`
		 * spies. Cleared on every setUp().
		 *
		 * @var array<string, mixed>
		 */
		private array $optionStore = array();

		/**
		 * Captured `wp_mail()` calls.
		 *
		 * @var list<array{to:mixed,subject:string,message:string,headers:mixed}>
		 */
		private array $mailCalls = array();

		/**
		 * Captured `wc_get_logger()->log()` entries — see {@see Slice39LoggerSpy}.
		 *
		 * @var list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		private array $loggerEntries = array();

		/**
		 * Captured `echo` output from `renderAll()` rendering.
		 */
		private string $renderedHtml = '';

		/**
		 * Toggle for `current_user_can('manage_woocommerce')` — flip to false
		 * for AC-11 capability gate test.
		 */
		private bool $hasCap = true;

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->optionStore   = array();
			$this->mailCalls     = array();
			$this->loggerEntries = array();
			$this->renderedHtml  = '';
			$this->hasCap        = true;

			// ---- Option-Store -------------------------------------------
			$store = &$this->optionStore;

			Functions\when( 'get_option' )->alias(
				static function ( string $key, $default = false ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
				}
			);

			Functions\when( 'update_option' )->alias(
				static function ( string $key, $value, $autoload = null ) use ( &$store ): bool {
					$store[ $key ]              = $value;
					$store[ '__autoload__' . $key ] = $autoload;
					return true;
				}
			);

			Functions\when( 'delete_option' )->alias(
				static function ( string $key ) use ( &$store ): bool {
					if ( array_key_exists( $key, $store ) ) {
						unset( $store[ $key ] );
						return true;
					}
					return false;
				}
			);

			// ---- Mail spies ---------------------------------------------
			$mail = &$this->mailCalls;
			Functions\when( 'wp_mail' )->alias(
				static function ( $to, $subject, $message, $headers = '' ) use ( &$mail ): bool {
					$mail[] = array(
						'to'      => $to,
						'subject' => (string) $subject,
						'message' => (string) $message,
						'headers' => $headers,
					);
					return true;
				}
			);

			// ---- is_email — passes through to a simple check ------------
			Functions\when( 'is_email' )->alias(
				static function ( $candidate ) {
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						return false;
					}
					if ( false === filter_var( $candidate, FILTER_VALIDATE_EMAIL ) ) {
						return false;
					}
					return $candidate;
				}
			);

			// ---- admin_url ----------------------------------------------
			Functions\when( 'admin_url' )->alias(
				static function ( string $path = '' ): string {
					return 'https://shop.example.test/wp-admin/' . ltrim( $path, '/' );
				}
			);

			// ---- Capability gate ----------------------------------------
			$hasCap = &$this->hasCap;
			Functions\when( 'current_user_can' )->alias(
				static function ( string $cap ) use ( &$hasCap ): bool {
					return 'manage_woocommerce' === $cap ? $hasCap : false;
				}
			);

			Functions\when( 'is_admin' )->alias(
				static function (): bool {
					return true;
				}
			);

			Functions\when( 'wp_create_nonce' )->alias(
				static function ( string $action ): string {
					return 'nonce_' . md5( $action );
				}
			);

			// ---- esc_* / __ — passthroughs ------------------------------
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_url' )->returnArg( 1 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( '_e' )->returnArg( 1 );

			// ---- WC-Logger spy (slice-14/15 pattern) --------------------
			$loggerEntries = &$this->loggerEntries;
			Functions\when( 'wc_get_logger' )->alias(
				static function () use ( &$loggerEntries ) {
					return new Slice39LoggerSpy( $loggerEntries );
				}
			);

			// ---- current_time UTC (defensive) ---------------------------
			Functions\when( 'current_time' )->alias(
				static function ( string $type, $gmt = 0 ): string {
					return '2026-05-03 10:00:00';
				}
			);
		}

		protected function tearDown(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			if ( class_exists( $pluginFqcn ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$ref->getProperty( 'initialized' )->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$ref->getProperty( 'pluginFile' )->setValue( null, '' );
				}
			}

			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		/**
		 * Build a complete FailedOp-row matching the AC-1 sample.
		 *
		 * @param array<string, mixed> $overrides
		 * @return array<string, mixed>
		 */
		private function makeFailedOpRow( array $overrides = array() ): array
		{
			return array_merge(
				array(
					'id'                  => 42,
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'error_message'       => 'HTTP 400 invalid SKU mapping',
					'error_code'          => 'http_4xx',
					'created_at'          => '2026-05-03 10:00:00',
				),
				$overrides
			);
		}

		/**
		 * Seed Notify-Settings with sensible defaults.
		 */
		private function seedNotifySettings(
			string $emails = 'admin@shop.de',
			bool $orderFlag = true,
			bool $syncFlag = true,
			bool $webhookFlag = false
		): void {
			$this->optionStore['spreadconnect_notify_emails']            = $emails;
			$this->optionStore['spreadconnect_notify_on_order_failure']  = $orderFlag;
			$this->optionStore['spreadconnect_notify_on_sync_failure']   = $syncFlag;
			$this->optionStore['spreadconnect_notify_on_webhook_failure'] = $webhookFlag;
		}

		// ===================================================================
		// AC-1 — Notifier-Dispatch-Sends-Email
		// ===================================================================

		/**
		 * AC-1: GIVEN configured recipients + order-flag=true + valid row.
		 *       WHEN  dispatch($row).
		 *       THEN  wp_mail() called once with array recipients (trimmed,
		 *             original order). Returns true.
		 */
		public function test_dispatch_sends_email_with_correct_recipients(): void
		{
			$this->seedNotifySettings( 'admin@shop.de, ops@shop.de', true );
			$row = $this->makeFailedOpRow();

			$result = ( new FailureNotifier() )->dispatch( $row );

			self::assertTrue( $result, 'AC-1: dispatch() MUST return true on success.' );
			self::assertCount( 1, $this->mailCalls, 'AC-1: wp_mail() MUST be called exactly once.' );

			$call = $this->mailCalls[0];
			self::assertSame(
				array( 'admin@shop.de', 'ops@shop.de' ),
				$call['to'],
				'AC-1: $to MUST be array, trimmed, in original CSV order.'
			);
		}

		/**
		 * AC-1: subject contains [Spreadconnect] + op-type label + #entity-id.
		 */
		public function test_dispatch_subject_contains_op_type_label_and_entity_id(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );
			$row = $this->makeFailedOpRow();

			( new FailureNotifier() )->dispatch( $row );

			self::assertCount( 1, $this->mailCalls );
			$subject = $this->mailCalls[0]['subject'];

			self::assertStringContainsString( '[Spreadconnect]', $subject, 'AC-1: subject MUST carry "[Spreadconnect]" prefix.' );
			self::assertStringContainsString( 'Order failed', $subject, 'AC-1: subject MUST carry op-type label.' );
			self::assertStringContainsString( '#7', $subject, 'AC-1: subject MUST carry #entity-id.' );
		}

		/**
		 * AC-1: body contains error_message + error_code + created_at + Hub
		 * deeplink substring.
		 */
		public function test_dispatch_body_contains_error_and_hub_deeplink(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );
			$row = $this->makeFailedOpRow();

			( new FailureNotifier() )->dispatch( $row );

			$body = $this->mailCalls[0]['message'];

			self::assertStringContainsString( 'HTTP 400 invalid SKU mapping', $body );
			self::assertStringContainsString( 'http_4xx', $body );
			self::assertStringContainsString( '2026-05-03 10:00:00', $body );
			self::assertStringContainsString(
				'page=spreadconnect&section=failed',
				$body,
				'AC-1: body MUST embed the Hub-deeplink URL substring.'
			);
		}

		/**
		 * AC-1: $headers MUST include Content-Type: text/plain; charset=UTF-8.
		 */
		public function test_dispatch_headers_include_plain_text_content_type(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );

			( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );

			$headers = $this->mailCalls[0]['headers'];
			$flat    = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

			self::assertStringContainsString(
				'Content-Type: text/plain; charset=UTF-8',
				$flat,
				'AC-1: headers MUST contain "Content-Type: text/plain; charset=UTF-8".'
			);
		}

		// ===================================================================
		// AC-2 — Op-Type-Flag-Gating
		// ===================================================================

		/**
		 * AC-2: GIVEN order-flag=false.
		 *       WHEN  dispatch() with op_type='create_order'.
		 *       THEN  wp_mail not called; returns false.
		 */
		public function test_dispatch_skips_when_order_flag_is_off(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', false );

			$result = ( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );

			self::assertFalse( $result, 'AC-2: dispatch() MUST return false when flag is off.' );
			self::assertSame( array(), $this->mailCalls, 'AC-2: wp_mail() MUST NOT be called.' );
		}

		/**
		 * AC-2: GIVEN webhook-flag=false (the default).
		 *       WHEN  dispatch() with op_type='handle_webhook'.
		 *       THEN  wp_mail not called; returns false.
		 */
		public function test_dispatch_skips_when_webhook_flag_is_off_default(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true, true, false );
			$row = $this->makeFailedOpRow( array(
				'op_type'             => 'handle_webhook',
				'related_entity_type' => 'webhook',
				'related_entity_id'   => '99',
			) );

			$result = ( new FailureNotifier() )->dispatch( $row );

			self::assertFalse( $result );
			self::assertSame( array(), $this->mailCalls );
		}

		/**
		 * AC-2: GIVEN sync-flag=true.
		 *       WHEN  dispatch() with op_type='sync_article'.
		 *       THEN  wp_mail IS called.
		 */
		public function test_dispatch_sends_when_sync_flag_is_on_for_sync_article(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true, true, false );
			$row = $this->makeFailedOpRow( array(
				'op_type'             => 'sync_article',
				'related_entity_type' => 'article',
				'related_entity_id'   => '13',
			) );

			$result = ( new FailureNotifier() )->dispatch( $row );

			self::assertTrue( $result, 'AC-2: sync_article + sync_flag=true MUST send.' );
			self::assertCount( 1, $this->mailCalls );
		}

		/**
		 * AC-2: webhook-flag=true → handle_webhook DOES send.
		 */
		public function test_dispatch_sends_when_webhook_flag_is_on(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true, true, true );
			$row = $this->makeFailedOpRow( array(
				'op_type'             => 'handle_webhook',
				'related_entity_type' => 'webhook',
				'related_entity_id'   => '99',
			) );

			$result = ( new FailureNotifier() )->dispatch( $row );

			self::assertTrue( $result );
			self::assertCount( 1, $this->mailCalls );
		}

		// ===================================================================
		// AC-3 — Empty recipients → skip + warn-log
		// ===================================================================

		/**
		 * AC-3: GIVEN empty recipients + order-flag=true.
		 *       WHEN  dispatch().
		 *       THEN  wp_mail NOT called; warning log entry; returns false.
		 */
		public function test_dispatch_skips_when_recipients_empty(): void
		{
			$this->seedNotifySettings( '', true );

			$result = ( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );

			self::assertFalse( $result, 'AC-3: empty recipients MUST yield false.' );
			self::assertSame( array(), $this->mailCalls, 'AC-3: wp_mail() MUST NOT be called.' );
		}

		/**
		 * AC-3: warning log entry is emitted with source='spreadconnect-failure'.
		 */
		public function test_dispatch_logs_warning_when_recipients_empty(): void
		{
			$this->seedNotifySettings( '', true );

			( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );

			$warning = null;
			foreach ( $this->loggerEntries as $entry ) {
				if ( 'warning' === $entry['level'] ) {
					$warning = $entry;
					break;
				}
			}
			self::assertNotNull( $warning, 'AC-3: a warning log entry MUST be written.' );
			self::assertSame(
				'spreadconnect-failure',
				$warning['context']['source'] ?? null,
				'AC-3: log source MUST be "spreadconnect-failure".'
			);
			self::assertStringContainsString(
				'no notification recipients configured',
				$warning['message'],
				'AC-3: log message MUST contain the "no notification recipients configured" substring.'
			);
		}

		// ===================================================================
		// AC-4 — Throwable swallow
		// ===================================================================

		/**
		 * AC-4: GIVEN wp_mail() throws.
		 *       WHEN  dispatch().
		 *       THEN  no re-throw; error log entry; returns false.
		 */
		public function test_dispatch_swallows_throwable_from_wp_mail(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );

			Functions\when( 'wp_mail' )->alias(
				static function (): bool {
					throw new \RuntimeException( 'phpmailer init failed' );
				}
			);

			$thrown = null;
			$result = null;
			try {
				$result = ( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}

			self::assertNull( $thrown, 'AC-4: dispatch() MUST NOT re-throw.' );
			self::assertFalse( $result, 'AC-4: dispatch() MUST return false on throw.' );
		}

		/**
		 * AC-4: error log entry contains exception_message + 'wp_mail dispatch failed'.
		 */
		public function test_dispatch_logs_error_when_wp_mail_throws(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );

			Functions\when( 'wp_mail' )->alias(
				static function (): bool {
					throw new \RuntimeException( 'phpmailer init failed' );
				}
			);

			( new FailureNotifier() )->dispatch( $this->makeFailedOpRow() );

			$errorEntry = null;
			foreach ( $this->loggerEntries as $entry ) {
				if ( 'error' === $entry['level'] ) {
					$errorEntry = $entry;
					break;
				}
			}
			self::assertNotNull( $errorEntry, 'AC-4: an error log entry MUST be written.' );
			self::assertSame( 'spreadconnect-failure', $errorEntry['context']['source'] ?? null );
			self::assertStringContainsString(
				'wp_mail dispatch failed',
				$errorEntry['message'],
				'AC-4: error message MUST contain "wp_mail dispatch failed".'
			);
			self::assertSame(
				'phpmailer init failed',
				$errorEntry['context']['exception_message'] ?? null,
				'AC-4: context MUST carry exception_message.'
			);
		}

		// ===================================================================
		// AC-5 — AdminNoticeStore::add() persists with autoload=false
		// ===================================================================

		/**
		 * AC-5: GIVEN empty option.
		 *       WHEN  add($row).
		 *       THEN  update_option called with array containing one entry,
		 *             autoload=false.
		 */
		public function test_add_persists_notice_to_option(): void
		{
			$row = $this->makeFailedOpRow();

			$result = ( new AdminNoticeStore() )->add( $row );

			self::assertTrue( $result, 'AC-5: add() MUST return true on persist.' );

			$persisted = $this->optionStore['spreadconnect_admin_notices'] ?? null;
			self::assertIsArray( $persisted, 'AC-5: option MUST be persisted as array.' );
			self::assertCount( 1, $persisted, 'AC-5: list MUST contain exactly one entry.' );

			$entry = $persisted[0];
			self::assertSame( 'failed_op_42', $entry['notice_id'] );
			self::assertSame( 'create_order', $entry['op_type'] );
			self::assertSame( 'order', $entry['related_entity_type'] );
			self::assertSame( '7', $entry['related_entity_id'] );
			self::assertSame( 'HTTP 400 invalid SKU mapping', $entry['error_message'] );
			self::assertSame( 'http_4xx', $entry['error_code'] );
			self::assertSame( '2026-05-03 10:00:00', $entry['created_at'] );
			self::assertSame( 'error', $entry['severity'] );
			self::assertSame( 'requires_resolution', $entry['dismiss_policy'] );
		}

		/**
		 * AC-5: update_option's autoload parameter MUST be false.
		 */
		public function test_add_uses_autoload_false(): void
		{
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow() );

			$autoload = $this->optionStore['__autoload__spreadconnect_admin_notices'] ?? null;
			self::assertFalse( $autoload, 'AC-5: autoload param MUST be false (capture).' );
		}

		/**
		 * AC-5: notice_id MUST be deterministic 'failed_op_<id>'.
		 */
		public function test_add_notice_id_is_deterministic_failed_op_prefix(): void
		{
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow( array( 'id' => 99 ) ) );

			$persisted = $this->optionStore['spreadconnect_admin_notices'];
			self::assertSame( 'failed_op_99', $persisted[0]['notice_id'] );
		}

		// ===================================================================
		// AC-6 — Idempotent add()
		// ===================================================================

		/**
		 * AC-6: GIVEN existing notice with same notice_id.
		 *       WHEN  add($row) with same id.
		 *       THEN  list stays at 1 entry; returns false.
		 */
		public function test_add_is_idempotent_for_same_failed_op_id(): void
		{
			$store = new AdminNoticeStore();
			self::assertTrue( $store->add( $this->makeFailedOpRow() ) );

			// Reset update_option capture to detect a second write.
			$writeCountBefore = count( $this->optionStore['spreadconnect_admin_notices'] );

			$result = $store->add( $this->makeFailedOpRow() );

			self::assertFalse( $result, 'AC-6: second add() MUST return false (no-op).' );

			$writeCountAfter = count( $this->optionStore['spreadconnect_admin_notices'] );
			self::assertSame( $writeCountBefore, $writeCountAfter, 'AC-6: list MUST stay at one entry.' );
			self::assertSame( 1, $writeCountAfter );
		}

		// ===================================================================
		// AC-7 — Severity mapping
		// ===================================================================

		/**
		 * AC-7: order-pipeline op_types → severity='error'.
		 */
		public function test_severity_is_error_for_order_op_types(): void
		{
			$store = new AdminNoticeStore();

			foreach ( array( 'create_order', 'confirm_order', 'cancel_order_mirror', 'fetch_tracking' ) as $i => $opType ) {
				$row = $this->makeFailedOpRow( array(
					'id'      => 100 + $i,
					'op_type' => $opType,
				) );
				$store->add( $row );
			}

			$persisted = $this->optionStore['spreadconnect_admin_notices'];
			self::assertCount( 4, $persisted );
			foreach ( $persisted as $entry ) {
				self::assertSame(
					'error',
					$entry['severity'],
					sprintf( 'AC-7: op_type %s MUST yield severity=error.', $entry['op_type'] )
				);
			}
		}

		/**
		 * AC-7: sync-pipeline op_types → severity='warning'.
		 */
		public function test_severity_is_warning_for_sync_op_types(): void
		{
			$store = new AdminNoticeStore();

			foreach ( array( 'sync_catalog', 'sync_article', 'handle_article_removed', 'scheduled_stock_sync' ) as $i => $opType ) {
				$store->add( $this->makeFailedOpRow( array(
					'id'      => 200 + $i,
					'op_type' => $opType,
				) ) );
			}

			$persisted = $this->optionStore['spreadconnect_admin_notices'];
			foreach ( $persisted as $entry ) {
				self::assertSame(
					'warning',
					$entry['severity'],
					sprintf( 'AC-7: op_type %s MUST yield severity=warning.', $entry['op_type'] )
				);
			}
		}

		/**
		 * AC-7: unknown op_type → defensive default severity='warning'.
		 */
		public function test_severity_defaults_to_warning_for_unknown_op_type(): void
		{
			( new AdminNoticeStore() )->add(
				$this->makeFailedOpRow( array( 'op_type' => 'totally_unknown_op' ) )
			);

			$entry = $this->optionStore['spreadconnect_admin_notices'][0];
			self::assertSame( 'warning', $entry['severity'], 'AC-7: unknown op_type MUST fall back to warning.' );
		}

		// ===================================================================
		// AC-8 — Dismiss-Policy mapping
		// ===================================================================

		/**
		 * AC-8: create_order → 'requires_resolution'.
		 */
		public function test_dismiss_policy_is_requires_resolution_for_create_order(): void
		{
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow( array( 'op_type' => 'create_order' ) ) );

			$entry = $this->optionStore['spreadconnect_admin_notices'][0];
			self::assertSame( 'requires_resolution', $entry['dismiss_policy'] );
		}

		/**
		 * AC-8: confirm_order/cancel_order_mirror/fetch_tracking → 'mark_resolved'.
		 */
		public function test_dismiss_policy_is_mark_resolved_for_confirm_order(): void
		{
			$store = new AdminNoticeStore();
			foreach ( array( 'confirm_order', 'cancel_order_mirror', 'fetch_tracking' ) as $i => $opType ) {
				$store->add( $this->makeFailedOpRow( array(
					'id'      => 300 + $i,
					'op_type' => $opType,
				) ) );
			}

			foreach ( $this->optionStore['spreadconnect_admin_notices'] as $entry ) {
				self::assertSame(
					'mark_resolved',
					$entry['dismiss_policy'],
					sprintf( 'AC-8: op_type %s MUST yield dismiss_policy=mark_resolved.', $entry['op_type'] )
				);
			}
		}

		/**
		 * AC-8: sync_* / handle_webhook → 'dismissible'.
		 */
		public function test_dismiss_policy_is_dismissible_for_sync_article(): void
		{
			$store = new AdminNoticeStore();
			foreach ( array( 'sync_catalog', 'sync_article', 'handle_article_removed', 'scheduled_stock_sync', 'handle_webhook' ) as $i => $opType ) {
				$store->add( $this->makeFailedOpRow( array(
					'id'      => 400 + $i,
					'op_type' => $opType,
				) ) );
			}

			foreach ( $this->optionStore['spreadconnect_admin_notices'] as $entry ) {
				self::assertSame(
					'dismissible',
					$entry['dismiss_policy'],
					sprintf( 'AC-8: op_type %s MUST yield dismiss_policy=dismissible.', $entry['op_type'] )
				);
			}
		}

		// ===================================================================
		// AC-9 — removeByFailedOpId
		// ===================================================================

		/**
		 * AC-9: GIVEN two notices.
		 *       WHEN  removeByFailedOpId(42).
		 *       THEN  list contains only failed_op_43; update_option called
		 *             with autoload=false; returns true.
		 */
		public function test_remove_by_failed_op_id_drops_only_target(): void
		{
			$store = new AdminNoticeStore();
			$store->add( $this->makeFailedOpRow( array( 'id' => 42 ) ) );
			$store->add( $this->makeFailedOpRow( array( 'id' => 43, 'related_entity_id' => '8' ) ) );

			self::assertCount( 2, $this->optionStore['spreadconnect_admin_notices'] );

			$result = $store->removeByFailedOpId( 42 );

			self::assertTrue( $result, 'AC-9: removeByFailedOpId(42) MUST return true.' );
			$persisted = $this->optionStore['spreadconnect_admin_notices'];
			self::assertCount( 1, $persisted );
			self::assertSame( 'failed_op_43', $persisted[0]['notice_id'] );

			$autoload = $this->optionStore['__autoload__spreadconnect_admin_notices'] ?? null;
			self::assertFalse( $autoload, 'AC-9: autoload MUST stay false on update.' );
		}

		/**
		 * AC-9: When removal empties the list, delete_option is called instead
		 *       of update_option (option leaves the DB cleanly).
		 */
		public function test_remove_by_failed_op_id_deletes_option_when_list_empty(): void
		{
			$store = new AdminNoticeStore();
			$store->add( $this->makeFailedOpRow( array( 'id' => 42 ) ) );

			self::assertArrayHasKey( 'spreadconnect_admin_notices', $this->optionStore );

			$result = $store->removeByFailedOpId( 42 );

			self::assertTrue( $result );
			self::assertArrayNotHasKey(
				'spreadconnect_admin_notices',
				$this->optionStore,
				'AC-9: option MUST be deleted when list is empty.'
			);
		}

		/**
		 * AC-9: removing a non-existent id returns false (no-op).
		 */
		public function test_remove_by_failed_op_id_returns_false_when_missing(): void
		{
			$store = new AdminNoticeStore();
			$store->add( $this->makeFailedOpRow( array( 'id' => 42 ) ) );

			$result = $store->removeByFailedOpId( 999 );

			self::assertFalse( $result, 'AC-9: missing id MUST return false.' );
			self::assertCount(
				1,
				$this->optionStore['spreadconnect_admin_notices'],
				'AC-9: list MUST stay unchanged on no-op.'
			);
		}

		// ===================================================================
		// AC-10 — findAll() + count()
		// ===================================================================

		/**
		 * AC-10: findAll() returns notices sorted by created_at DESC.
		 */
		public function test_find_all_returns_notices_sorted_desc(): void
		{
			$this->optionStore['spreadconnect_admin_notices'] = array(
				array(
					'notice_id'      => 'failed_op_1',
					'failed_op_id'   => 1,
					'created_at'     => '2026-01-01 09:00:00',
					'severity'       => 'warning',
					'op_type'        => 'sync_article',
					'dismiss_policy' => 'dismissible',
				),
				array(
					'notice_id'      => 'failed_op_2',
					'failed_op_id'   => 2,
					'created_at'     => '2026-05-03 10:00:00',
					'severity'       => 'error',
					'op_type'        => 'create_order',
					'dismiss_policy' => 'requires_resolution',
				),
				array(
					'notice_id'      => 'failed_op_3',
					'failed_op_id'   => 3,
					'created_at'     => '2026-03-01 12:00:00',
					'severity'       => 'warning',
					'op_type'        => 'handle_webhook',
					'dismiss_policy' => 'dismissible',
				),
			);

			$rows = ( new AdminNoticeStore() )->findAll();

			self::assertCount( 3, $rows );
			self::assertSame( 'failed_op_2', $rows[0]['notice_id'], 'AC-10: newest MUST come first.' );
			self::assertSame( 'failed_op_3', $rows[1]['notice_id'] );
			self::assertSame( 'failed_op_1', $rows[2]['notice_id'], 'AC-10: oldest MUST come last.' );
		}

		/**
		 * AC-10: count() returns total; count('error') returns severity-filter.
		 */
		public function test_count_filters_by_severity(): void
		{
			$this->optionStore['spreadconnect_admin_notices'] = array(
				array( 'severity' => 'error',   'created_at' => '2026-05-01 00:00:00' ),
				array( 'severity' => 'warning', 'created_at' => '2026-05-02 00:00:00' ),
				array( 'severity' => 'warning', 'created_at' => '2026-05-03 00:00:00' ),
			);

			$store = new AdminNoticeStore();

			self::assertSame( 3, $store->count(), 'AC-10: count() MUST return total.' );
			self::assertSame( 1, $store->count( 'error' ), 'AC-10: count("error") MUST return 1.' );
			self::assertSame( 2, $store->count( 'warning' ) );
		}

		/**
		 * AC-10: missing option → findAll()=[]; count()=0.
		 */
		public function test_find_all_returns_empty_array_when_option_missing(): void
		{
			// optionStore intentionally empty.
			$store = new AdminNoticeStore();

			self::assertSame( array(), $store->findAll(), 'AC-10: missing option MUST yield empty array.' );
			self::assertSame( 0, $store->count(), 'AC-10: missing option MUST yield 0.' );
			self::assertSame( 0, $store->count( 'error' ) );
		}

		// ===================================================================
		// AC-11 — admin_notices renderAll() emits HTML per entry
		// ===================================================================

		/**
		 * AC-11: GIVEN two notices + manage_woocommerce capability.
		 *       WHEN  renderAll() runs.
		 *       THEN  one <div class="notice notice-{severity}"> per entry,
		 *             containing [Spreadconnect] prefix, op_type label,
		 *             entity-id, error_message.
		 */
		public function test_render_all_outputs_notice_markup_per_entry(): void
		{
			$this->hasCap = true;

			$store = new AdminNoticeStore();
			$store->add( $this->makeFailedOpRow( array( 'id' => 42, 'op_type' => 'create_order' ) ) );
			$store->add( $this->makeFailedOpRow( array(
				'id'                  => 43,
				'op_type'             => 'sync_article',
				'related_entity_type' => 'article',
				'related_entity_id'   => '13',
				'error_message'       => 'sync failed boom',
				'created_at'          => '2026-05-03 11:00:00',
			) ) );

			ob_start();
			$store->renderAll();
			$html = ob_get_clean();

			self::assertNotSame( '', $html, 'AC-11: renderAll() MUST emit output for ≥1 notice.' );

			// One <div class="notice ..."> per entry.
			$noticeBlocks = substr_count( $html, '<div class="' );
			self::assertSame( 2, $noticeBlocks, 'AC-11: MUST emit one notice <div> per entry.' );

			self::assertStringContainsString( 'notice-error', $html, 'AC-11: severity=error MUST appear as CSS class.' );
			self::assertStringContainsString( 'notice-warning', $html, 'AC-11: severity=warning MUST appear as CSS class.' );

			// Content checks.
			self::assertStringContainsString( '[Spreadconnect]', $html );
			self::assertStringContainsString( 'Order failed', $html );
			self::assertStringContainsString( 'Article-sync failed', $html );
			self::assertStringContainsString( '#7', $html );
			self::assertStringContainsString( '#13', $html );
			self::assertStringContainsString( 'HTTP 400 invalid SKU mapping', $html );
			self::assertStringContainsString( 'sync failed boom', $html );
		}

		/**
		 * AC-11: WITHOUT manage_woocommerce capability, renderAll() emits NOTHING.
		 */
		public function test_render_all_returns_early_without_manage_woocommerce_cap(): void
		{
			$this->hasCap = false;

			$store = new AdminNoticeStore();
			$store->add( $this->makeFailedOpRow() );

			ob_start();
			$store->renderAll();
			$html = ob_get_clean();

			self::assertSame( '', $html, 'AC-11: no capability MUST suppress all output.' );
		}

		// ===================================================================
		// AC-12 — Dismiss-policy → action buttons
		// ===================================================================

		/**
		 * AC-12: requires_resolution → [View in Failed-Ops] link, NO is-dismissible.
		 */
		public function test_render_no_plain_dismiss_for_requires_resolution(): void
		{
			$this->hasCap = true;
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow( array(
				'id'      => 42,
				'op_type' => 'create_order',
			) ) );

			ob_start();
			( new AdminNoticeStore() )->renderAll();
			$html = ob_get_clean();

			self::assertStringContainsString( 'View in Failed-Ops', $html, 'AC-12: requires_resolution MUST render [View in Failed-Ops].' );
			self::assertStringContainsString( 'page=spreadconnect&section=failed&highlight=42', $html, 'AC-12: link MUST carry highlight=<id>.' );
			self::assertStringNotContainsString( 'is-dismissible', $html, 'AC-12: requires_resolution MUST NOT be dismissible.' );
		}

		/**
		 * AC-12: mark_resolved → [Mark Resolved] button with data-failed-op-id +
		 *        [View Detail] link.
		 */
		public function test_render_emits_mark_resolved_button_with_data_attr(): void
		{
			$this->hasCap = true;
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow( array(
				'id'      => 77,
				'op_type' => 'confirm_order',
			) ) );

			ob_start();
			( new AdminNoticeStore() )->renderAll();
			$html = ob_get_clean();

			self::assertStringContainsString( 'Mark Resolved', $html );
			self::assertMatchesRegularExpression(
				'/data-failed-op-id="77"/',
				$html,
				'AC-12: [Mark Resolved] button MUST carry data-failed-op-id="77".'
			);
			self::assertStringContainsString( 'View Detail', $html, 'AC-12: mark_resolved MUST render [View Detail] link.' );
		}

		/**
		 * AC-12: nonce hidden input is rendered with action-name 'spreadconnect_dismiss_notice'.
		 */
		public function test_render_includes_dismiss_nonce(): void
		{
			$this->hasCap = true;
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow() );

			ob_start();
			( new AdminNoticeStore() )->renderAll();
			$html = ob_get_clean();

			$expectedNonce = 'nonce_' . md5( 'spreadconnect_dismiss_notice' );
			self::assertStringContainsString(
				$expectedNonce,
				$html,
				'AC-12: dismiss nonce hidden input MUST be present in markup.'
			);
		}

		/**
		 * AC-12: dismissible policy → is-dismissible class + [View Detail] link.
		 */
		public function test_render_emits_is_dismissible_for_sync_op_type(): void
		{
			$this->hasCap = true;
			( new AdminNoticeStore() )->add( $this->makeFailedOpRow( array(
				'id'      => 55,
				'op_type' => 'sync_article',
			) ) );

			ob_start();
			( new AdminNoticeStore() )->renderAll();
			$html = ob_get_clean();

			self::assertStringContainsString( 'is-dismissible', $html, 'AC-12: dismissible policy MUST emit is-dismissible class.' );
			self::assertStringContainsString( 'View Detail', $html );
		}

		// ===================================================================
		// AC-13 — Bootstrap wiring + Listener handoff
		// ===================================================================

		/**
		 * AC-13: GIVEN Plugin::init() runs.
		 *        THEN  admin_notices hook is registered.
		 */
		public function test_plugin_init_registers_admin_notices_hook(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$GLOBALS['wpdb'] = new \wpdb();

			self::assertFalse(
				Actions\has( 'admin_notices' ),
				'AC-13 (precondition): no admin_notices listener before init().'
			);

			$pluginFqcn::init( $pluginFile );

			self::assertNotFalse(
				Actions\has( 'admin_notices' ),
				'AC-13: admin_notices hook MUST be registered.'
			);
		}

		/**
		 * AC-13: re-entrant init() does NOT register the hook twice.
		 */
		public function test_plugin_init_does_not_register_admin_notices_hook_twice(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$GLOBALS['wpdb'] = new \wpdb();

			$pluginFqcn::init( $pluginFile );
			$first = Actions\has( 'admin_notices' );

			$pluginFqcn::init( $pluginFile );
			$second = Actions\has( 'admin_notices' );

			self::assertSame(
				$first,
				$second,
				'AC-13: second init() MUST NOT re-register admin_notices.'
			);
		}

		/**
		 * AC-13 (notifier-side): RetryPolicyListener invokes notifier->dispatch()
		 * AND noticeStore->add() AFTER a successful FailedOpsRepo::record() insert.
		 *
		 * `FailedOpsRepo` is `final` (slice-37 Constraints) so we cannot use
		 * Mockery — we wire a real repo with a recording `wpdb`-fake (slice-37
		 * pattern) and assert the integration end-to-end. The fake captures
		 * `insert()` (record) and `get_row()` (findById) so the listener's
		 * dispatch+add sequence runs against a real {@see FailedOpsRepo} and
		 * the real {@see FailureNotifier} + {@see AdminNoticeStore}.
		 */
		public function test_retry_policy_listener_calls_dispatch_after_record(): void
		{
			$this->seedNotifySettings( 'admin@shop.de', true );

			// Recording wpdb-fake — captures insert() and exposes get_row()
			// so FailedOpsRepo::findById($insertId) returns a row matching the
			// just-inserted record.
			$wpdb = new Slice39RecordingWpdb();

			// Real repo wired against the fake.
			$repo = new FailedOpsRepo( $wpdb );

			// Real notifier + store — observed via mailCalls / optionStore.
			$notifier = new FailureNotifier();
			$store    = new AdminNoticeStore();

			// Patch ActionScheduler stub so listener resolves a recognised hook.
			if ( ! class_exists( 'ActionScheduler', false ) ) {
				eval( 'final class ActionScheduler { public static $storeImpl=null; public static $loggerImpl=null; public static function store(): ?object { return self::$storeImpl; } public static function logger(): ?object { return self::$loggerImpl; } }' );
			}
			if ( ! class_exists( 'Slice39AsAction', false ) ) {
				eval( 'final class Slice39AsAction { public string $hook = "spreadconnect/create_order"; public array $args = ["order_id" => 7]; public function get_hook(): string { return $this->hook; } public function get_args(): array { return $this->args; } }' );
			}
			if ( ! class_exists( 'Slice39AsStore', false ) ) {
				eval( 'final class Slice39AsStore { public ?object $action = null; public function fetch_action(int $id): ?object { return $this->action; } }' );
			}
			if ( ! class_exists( 'Slice39AsLogger', false ) ) {
				eval( 'final class Slice39AsLogger { public array $logs = []; public function get_logs(int $id): array { return $this->logs; } }' );
			}

			$action            = new \Slice39AsAction();
			$asStore           = new \Slice39AsStore();
			$asStore->action   = $action;
			\ActionScheduler::$storeImpl = $asStore;

			$asLogger        = new \Slice39AsLogger();
			$asLogger->logs  = array(
				'action failed: SpreadconnectPod\\Api\\SpreadconnectClientError: HTTP 400 invalid SKU mapping',
			);
			\ActionScheduler::$loggerImpl = $asLogger;

			Functions\when( 'as_get_scheduled_actions' )->alias(
				static function (): array {
					return array(); // 0 prior failures — permanent classification still triggers record().
				}
			);
			Functions\when( 'wp_json_encode' )->alias(
				static function ( $data, int $options = 0, int $depth = 512 ) {
					return json_encode( $data, $options, $depth );
				}
			);

			$listener = new RetryPolicyListener( $repo, null, $notifier, $store );
			$listener->on_action_failed( 1 );

			// FailedOpsRepo::record() ran exactly once.
			self::assertCount(
				1,
				$wpdb->insertCalls,
				'AC-13: FailedOpsRepo::record() MUST run.'
			);

			// Notifier — wp_mail() called once.
			self::assertCount(
				1,
				$this->mailCalls,
				'AC-13: FailureNotifier::dispatch() MUST be invoked after record() insert.'
			);

			// Store — admin-notice option populated.
			$persisted = $this->optionStore['spreadconnect_admin_notices'] ?? null;
			self::assertIsArray( $persisted );
			self::assertCount(
				1,
				$persisted,
				'AC-13: AdminNoticeStore::add() MUST be invoked after record() insert.'
			);
			self::assertSame( 'failed_op_500', $persisted[0]['notice_id'] );

			\ActionScheduler::$storeImpl  = null;
			\ActionScheduler::$loggerImpl = null;
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}
	}

	/**
	 * Minimal `wc_get_logger()` spy. Captures level/message/context for the
	 * AC-3 / AC-4 log assertions.
	 *
	 * Mirrors the slice-14/15 anonymous-class spy pattern but is named so the
	 * Brain\Monkey alias can return a fresh instance each call without losing
	 * the shared $entriesRef.
	 */
	final class Slice39LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		public array $entriesRef;

		/**
		 * @param list<array{level:string,message:string,context:array<string,mixed>}> $entries
		 */
		public function __construct( array &$entries )
		{
			$this->entriesRef = &$entries;
		}

		public function log( string $level, string $message, array $context = array() ): void
		{
			$this->entriesRef[] = array(
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
		}

		public function info( string $message, array $context = array() ): void
		{
			$this->log( 'info', $message, $context );
		}

		public function warning( string $message, array $context = array() ): void
		{
			$this->log( 'warning', $message, $context );
		}

		public function error( string $message, array $context = array() ): void
		{
			$this->log( 'error', $message, $context );
		}

		public function debug( string $message, array $context = array() ): void
		{
			$this->log( 'debug', $message, $context );
		}
	}

	/**
	 * Recording `wpdb` double for the AC-13 listener-integration test.
	 *
	 * Captures `insert()` so `FailedOpsRepo::record()` produces a verifiable
	 * call list, and serves a synthesised row from `get_row()` so
	 * `FailedOpsRepo::findById()` round-trips back the just-inserted record
	 * (matching the slice-37 listener's `$repo->findById($insertId)` reload).
	 */
	final class Slice39RecordingWpdb extends \wpdb
	{
		public string $prefix    = 'wp_';

		public int $insert_id    = 500;

		public string $last_error = '';

		/** @var list<array{table:string,data:array<string,mixed>,format:array|string|null}> */
		public array $insertCalls = array();

		/** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
		public array $updateCalls = array();

		public int $updateResult = 0;

		public function __construct() // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		{
			// Skip parent ctor so we don't try to talk to MySQL.
		}

		public function insert( string $table, array $data, $format = null ): int
		{
			$this->insertCalls[] = array(
				'table'  => $table,
				'data'   => $data,
				'format' => $format,
			);
			return 1;
		}

		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int
		{
			$this->updateCalls[] = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);
			return $this->updateResult;
		}

		public function prepare( string $sql, ...$args ): string
		{
			$out = $sql;
			foreach ( $args as $arg ) {
				if ( is_array( $arg ) ) {
					foreach ( $arg as $inner ) {
						$replacement = is_int( $inner )
							? (string) $inner
							: "'" . str_replace( "'", "''", (string) $inner ) . "'";
						$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
					}
					continue;
				}
				$replacement = is_int( $arg )
					? (string) $arg
					: "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		/**
		 * @return mixed
		 */
		public function get_var( string $sql )
		{
			return null;
		}

		/**
		 * Synthesise a row matching the most-recent insert so
		 * `FailedOpsRepo::findById($insertId)` can round-trip the listener's
		 * dispatch+add hand-off.
		 *
		 * @param string $sql
		 * @param string $output
		 * @return array<string, mixed>|null
		 */
		public function get_row( string $sql, $output = 'OBJECT' )
		{
			if ( array() === $this->insertCalls ) {
				return null;
			}
			$inserted = end( $this->insertCalls )['data'];
			return array(
				'id'                  => (string) $this->insert_id,
				'op_type'             => (string) ( $inserted['op_type'] ?? '' ),
				'related_entity_type' => (string) ( $inserted['related_entity_type'] ?? '' ),
				'related_entity_id'   => (string) ( $inserted['related_entity_id'] ?? '' ),
				'payload'             => (string) ( $inserted['payload'] ?? '{}' ),
				'error_message'       => (string) ( $inserted['error_message'] ?? '' ),
				'error_code'          => (string) ( $inserted['error_code'] ?? '' ),
				'retries_used'        => (string) ( $inserted['retries_used'] ?? '0' ),
				'created_at'          => (string) ( $inserted['created_at'] ?? '2026-05-03 10:00:00' ),
				'last_attempt_at'     => (string) ( $inserted['last_attempt_at'] ?? '2026-05-03 10:00:00' ),
				'state'               => (string) ( $inserted['state'] ?? 'unresolved' ),
			);
		}

		/**
		 * @param string $sql
		 * @param string $output
		 * @return array<int, mixed>
		 */
		public function get_results( string $sql, $output = 'OBJECT' ): array
		{
			return array();
		}
	}
}
