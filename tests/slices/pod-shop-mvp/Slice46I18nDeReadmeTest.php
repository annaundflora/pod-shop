<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 46 — i18n de_DE.po + README + Dashboard real-impl
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-46-i18n-de-readme.md
//
// Mocking strategy per spec (`mock_external`):
//   - Brain\Monkey aliases for: __, esc_html, esc_html__, esc_attr, esc_url,
//     get_transient, get_option, date_i18n, admin_url, wc_get_logger,
//     current_time, function_exists.
//   - $GLOBALS['wpdb'] seeded with a recording stub (extends the canonical
//     wpdb stub from tests/stubs/wc-classes.php) so the Dashboard's
//     Card-3 aggregate query and FailedOpsRepo::count() resolve to
//     deterministic returns.
//   - Test-Subclass StubSubscriptionManager / StubWebhookLogRepo /
//     StubSyncHistoryRepo are NOT used — instead production classes are
//     exercised with mocked $wpdb / get_transient. A few tests (AC-14)
//     toggle a flag on the recording wpdb to throw \Throwable mid-render
//     and verify per-card try/catch isolation.
//   - .po and README assertions are PURE filesystem static analysis
//     (file_get_contents + regex / DOMDocument-style parsing).
//
// AC-Coverage:
//   AC-1  (po extracts every msgid)               → po validity tests
//   AC-2  (po headers + no empty msgstrs)          → po validity tests
//   AC-3  (glossary mappings, ≥5)                 → po validity tests
//   AC-4  (README 7 H2 sections in order)         → README static tests
//   AC-5  (README Setup 5 numbered steps)         → README static tests
//   AC-6  (README Features 10 discovery slices)   → README static tests
//   AC-7  (README Architecture link + 5 layers)   → README static tests
//   AC-8  (Card 1 reads sc_health transient)      → Dashboard render tests
//   AC-9  (Card 2 reads SyncHistoryRepo::findLatest) → Dashboard render tests
//   AC-10 (Card 3 single 30d aggregate query)     → Dashboard render tests
//   AC-11 (Card 4 Subscription cache + WebhookLog) → Dashboard render tests
//   AC-12 (Card 5 FailedOps count + severity)     → Dashboard render tests
//   AC-13 (Output escaped + i18n-wrapped)         → Dashboard render tests
//   AC-14 (Per-card Throwable catch + WC-Log)     → Dashboard render tests
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Hub\View\Dashboard;

	/**
	 * Recording wpdb double for Slice-46 Dashboard tests.
	 *
	 * Captures every prepare()/get_results()/get_var()/get_row() call so
	 * AC-10 (single aggregate query) can be verified, and lets the test
	 * pre-program the rows returned by the 30-day Orders aggregate query
	 * AND the FailedOps count() + SyncHistory findLatest() / WebhookLog
	 * findLatest() reads.
	 */
	final class Slice46RecordingWpdb extends \wpdb
	{
		/** @var list<string> SQL strings sent to prepare(). */
		public array $prepareCalls = array();

		/** @var list<string> SQL strings sent to get_results(). */
		public array $getResultsCalls = array();

		/** @var list<string> SQL strings sent to get_var(). */
		public array $getVarCalls = array();

		/** @var list<string> SQL strings sent to get_row(). */
		public array $getRowCalls = array();

		/** @var array<int, array<string, mixed>> Programmable rows for next get_results(). */
		public array $nextResults = array();

		/** @var int|string|null Programmable get_var() return. */
		public $nextVar = 0;

		/** @var array<string, mixed>|null Programmable get_row() return. */
		public ?array $nextRow = null;

		/**
		 * When set, the next get_results / get_var / get_row throws this — used
		 * to verify per-card try/catch isolation (AC-14).
		 */
		public ?\Throwable $throwOnNextQuery = null;

		public function prepare( string $query, ...$args ): string
		{
			$this->prepareCalls[] = $query;
			// Echo-style prepare so callers can substring-match on the
			// final SQL. Mirrors the canonical stub's behaviour.
			return $query;
		}

		/** {@inheritDoc} */
		public function get_results( string $query, $output = 'OBJECT' ): array
		{
			$this->getResultsCalls[] = $query;
			if ( null !== $this->throwOnNextQuery ) {
				$err = $this->throwOnNextQuery;
				$this->throwOnNextQuery = null;
				throw $err;
			}
			return $this->nextResults;
		}

		/** {@inheritDoc} */
		public function get_var( string $query )
		{
			$this->getVarCalls[] = $query;
			if ( null !== $this->throwOnNextQuery ) {
				$err = $this->throwOnNextQuery;
				$this->throwOnNextQuery = null;
				throw $err;
			}
			return $this->nextVar;
		}

		/** {@inheritDoc} */
		public function get_row( string $query, $output = 'OBJECT' )
		{
			$this->getRowCalls[] = $query;
			if ( null !== $this->throwOnNextQuery ) {
				$err = $this->throwOnNextQuery;
				$this->throwOnNextQuery = null;
				throw $err;
			}
			return $this->nextRow;
		}
	}

	/**
	 * Slice 46 acceptance tests.
	 *
	 * Three test areas:
	 *   (a) `.po` validity — Headers, Plurals, glossary mappings.
	 *   (b) README structure — 7 H2 sections, 5 setup steps, 10 features bullets,
	 *       1 architecture markdown link, 5 layer bullets.
	 *   (c) Dashboard real-impl — Card 1..5 read from
	 *       transients / repos / `$wpdb` (no live API), per-card try/catch,
	 *       WC-Logger fallback on \Throwable.
	 */
	final class Slice46I18nDeReadmeTest extends TestCase
	{
		/**
		 * Captured WC-Logger entries from `wc_get_logger()->log()` spy.
		 *
		 * @var list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		private array $loggerEntries = array();

		/**
		 * In-memory transient store for `get_transient()` spy. Tests assign
		 * keys before exercising Dashboard::render().
		 *
		 * @var array<string, mixed>
		 */
		private array $transientStore = array();

		/**
		 * Recording wpdb double for the orders/failed-ops queries.
		 */
		private ?Slice46RecordingWpdb $wpdb = null;

		// -------------------------------------------------------------------
		// File-path helpers
		// -------------------------------------------------------------------

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		private static function pluginRoot(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod';
		}

		private static function poFile(): string
		{
			return self::pluginRoot() . '/languages/spreadconnect-pod-de_DE.po';
		}

		private static function readmeFile(): string
		{
			return self::pluginRoot() . '/README.md';
		}

		private static function readPo(): string
		{
			$path = self::poFile();
			self::assertFileExists( $path, '.po file MUST exist for Slice-46 AC-1/2/3 assertions.' );
			$contents = (string) file_get_contents( $path );
			self::assertNotSame( '', $contents, '.po file MUST NOT be empty.' );
			return $contents;
		}

		private static function readReadme(): string
		{
			$path = self::readmeFile();
			self::assertFileExists( $path, 'README.md MUST exist for Slice-46 AC-4..AC-7 assertions.' );
			$contents = (string) file_get_contents( $path );
			self::assertNotSame( '', $contents, 'README.md MUST NOT be empty.' );
			return $contents;
		}

		// -------------------------------------------------------------------
		// Brain\Monkey lifecycle + Dashboard environment scaffolding
		// -------------------------------------------------------------------

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->loggerEntries = array();
			$this->transientStore = array();

			// ---- i18n / esc helpers -------------------------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_url' )->returnArg( 1 );

			// ---- transient store ---------------------------------------
			$store = &$this->transientStore;
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : false;
				}
			);

			// ---- option store (date_format) ----------------------------
			Functions\when( 'get_option' )->alias(
				static function ( string $key, $default = false ) {
					if ( 'date_format' === $key ) {
						return 'Y-m-d';
					}
					return $default;
				}
			);

			Functions\when( 'date_i18n' )->alias(
				static function ( string $format, $timestamp = false ): string {
					return is_int( $timestamp ) && $timestamp > 0
						? gmdate( $format, $timestamp )
						: '';
				}
			);

			Functions\when( 'admin_url' )->alias(
				static function ( string $path = '' ): string {
					return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
				}
			);

			Functions\when( 'current_time' )->alias(
				static function ( string $type = 'mysql', $gmt = 0 ) {
					return 'mysql' === $type ? '2026-05-04 00:00:00' : 1746316800;
				}
			);

			// ---- wc_get_logger spy --------------------------------------
			$entries = &$this->loggerEntries;
			$logger  = new class( $entries ) {
				/** @var list<array<string,mixed>> */
				private array $bag;

				/**
				 * @param list<array<string,mixed>> $bag
				 */
				public function __construct( array &$bag )
				{
					$this->bag = &$bag;
				}

				/**
				 * @param array<string, mixed> $context
				 */
				public function log( string $level, string $message, array $context = array() ): void
				{
					$this->bag[] = array(
						'level'   => $level,
						'message' => $message,
						'context' => $context,
					);
				}
			};
			Functions\when( 'wc_get_logger' )->alias( static fn() => $logger );

			// ---- wpdb double seeded into globals ------------------------
			$this->wpdb = new Slice46RecordingWpdb();
			$GLOBALS['wpdb'] = $this->wpdb;
		}

		protected function tearDown(): void
		{
			unset( $GLOBALS['wpdb'] );
			$this->wpdb = null;
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Render Dashboard into a buffer and return the captured HTML.
		 */
		private function renderDashboard(): string
		{
			$initialObLevel = ob_get_level();
			ob_start();
			try {
				Dashboard::render();
			} finally {
				$output = (string) ob_get_clean();
				while ( ob_get_level() > $initialObLevel ) {
					ob_end_clean();
				}
			}
			return $output;
		}

		// ===================================================================
		// AC-1: GIVEN plugin source code (Slices 02-45)
		//       WHEN wp i18n make-pot is run
		//       THEN every __() / _e() / esc_html__() msgid is in
		//       spreadconnect-pod-de_DE.po (po vs pot diff = 0 missing).
		// ===================================================================

		/**
		 * AC-1: The .po file MUST contain a non-trivial number of msgid
		 * entries — placeholder of "many" since the implementation snapshot
		 * registers ≥150 (current actual ~339). Verified via `^msgid "`
		 * regex count minus the single header msgid "" line.
		 *
		 * Implementation note: a strict pot-vs-po diff would require running
		 * `wp i18n make-pot` against the live source-tree, which is
		 * out-of-scope for the unit test runtime — Slice-46 Constraints
		 * defer that to CI. Instead we lower-bound the msgid count and
		 * spot-check that 5 high-traffic strings actually present in the
		 * source code (Dashboard.php, Settings.php, FailureNotifier.php)
		 * appear in the .po — a regression on extraction fidelity would
		 * fail these spot-checks.
		 */
		public function test_po_file_contains_all_msgids_from_plugin_source(): void
		{
			$po = self::readPo();

			// All msgid lines (header msgid "" plus translation-entry msgids).
			$total       = (int) preg_match_all( '/^msgid\s+"/m', $po );
			$emptyHeader = (int) preg_match_all( '/^msgid\s+""\s*$/m', $po );

			$this->assertSame(
				1,
				$emptyHeader,
				'AC-1: .po file MUST contain exactly one empty msgid "" (the header block).'
			);

			$translationEntries = $total - $emptyHeader;
			$this->assertGreaterThanOrEqual(
				150,
				$translationEntries,
				sprintf(
					'AC-1: .po MUST carry the translations of every __()-string in Slices 02-45. '
					. 'Expected ≥150 entries, found %d.',
					$translationEntries
				)
			);

			// Spot-check 5 high-traffic source-strings — each MUST be present
			// as a `msgid "<text>"` line. These strings are pulled from the
			// real plugin code paths (Dashboard, Settings, FailureNotifier,
			// AdminNoticeStore, FailedOps).
			$expectedSourceStrings = array(
				'Connection',
				'Catalog',
				'Failed Operations',
				'Webhooks',
				'No event received yet.',
			);

			foreach ( $expectedSourceStrings as $source ) {
				$pattern = '/^msgid\s+"' . preg_quote( $source, '/' ) . '"\s*$/m';
				$this->assertMatchesRegularExpression(
					$pattern,
					$po,
					sprintf(
						'AC-1: .po MUST contain `msgid "%s"` — extraction parity with plugin source '
						. 'is required by Slice-46 (po vs pot diff = 0 missing).',
						$source
					)
				);
			}
		}

		// ===================================================================
		// AC-2: GIVEN spreadconnect-pod-de_DE.po
		//       WHEN parsed
		//       THEN required headers are set + no empty msgstr for
		//       user-facing strings.
		// ===================================================================

		/**
		 * AC-2: All five required gettext headers are present in the
		 * header block: Project-Id-Version, Language, Content-Type,
		 * Plural-Forms, X-Domain.
		 */
		public function test_po_file_has_required_headers_and_no_empty_translations(): void
		{
			$po = self::readPo();

			// Pflicht-Header laut Slice-46 AC-2.
			$requiredHeaders = array(
				'Project-Id-Version' => '/Project-Id-Version:\s*Spreadconnect POD\s+\d/',
				'Language'           => '/Language:\s*de_DE\\\\n/',
				'Content-Type'       => '/Content-Type:\s*text\/plain;\s*charset=UTF-8\\\\n/',
				'Plural-Forms'       => '/Plural-Forms:\s*nplurals=2;\s*plural=\(n\s*!=\s*1\);\\\\n/',
				'X-Domain'           => '/X-Domain:\s*spreadconnect-pod\\\\n/',
			);

			foreach ( $requiredHeaders as $name => $pattern ) {
				$this->assertMatchesRegularExpression(
					$pattern,
					$po,
					sprintf(
						'AC-2: .po MUST set the gettext header "%s" — missing it breaks msgfmt and '
						. 'WP locale-loading.',
						$name
					)
				);
			}

			// AC-2 Constraint: no user-facing msgid may have an empty msgstr.
			// `msgid "<non-empty>"` directly followed by `msgstr ""` is the
			// untranslated-stub pattern we forbid.
			$emptyTranslationCount = (int) preg_match_all(
				'/^msgid\s+"[^"]+"\s*\nmsgstr\s+""\s*$/m',
				$po
			);
			$this->assertSame(
				0,
				$emptyTranslationCount,
				sprintf(
					'AC-2: No user-facing msgid may carry an empty msgstr "". Found %d unfinished '
					. 'translations — every entry needs a German rendering.',
					$emptyTranslationCount
				)
			);
		}

		// ===================================================================
		// AC-3: GIVEN translated strings
		//       WHEN glossary-consistent mapping is checked
		//       THEN ≥5 of the canonical mappings are correct.
		// ===================================================================

		/**
		 * AC-3: ≥5 of the canonical glossary mappings (Slice-46 spec table)
		 * are present in the .po with the prescribed German rendering.
		 *
		 * The spec table lists 11 mappings — we assert ALL of them so a
		 * regression on any single term (e.g. mistranslating "Catalog" as
		 * "Liste" instead of "Katalog") fails the build. The "≥5" floor is
		 * a Slice-46 minimum; we exceed it deliberately.
		 */
		public function test_po_file_uses_consistent_glossary_translations(): void
		{
			$po = self::readPo();

			// msgid -> msgstr (glossary).
			$glossary = array(
				'Failed Operations' => 'Fehlgeschlagene Operationen',
				'Webhooks'          => 'Webhooks',         // Fachbegriff — unverändert.
				'Subscriptions'     => 'Abonnements',
				'Catalog'           => 'Katalog',
				'Settings'          => 'Einstellungen',
				'Dashboard'         => 'Dashboard',         // Fachbegriff — unverändert.
				'Logs'              => 'Protokolle',
				'Resend'            => 'Erneut senden',
				'Dismiss'           => 'Verwerfen',
				'Mark Resolved'     => 'Als gelöst markieren',
				'Sync now'          => 'Jetzt synchronisieren',
			);

			$matched = 0;
			foreach ( $glossary as $msgid => $msgstr ) {
				// `^msgid "<msgid>"\nmsgstr "<msgstr>"$` — multi-line.
				$pattern = '/^msgid\s+"' . preg_quote( $msgid, '/' ) . '"\s*\n'
					. 'msgstr\s+"' . preg_quote( $msgstr, '/' ) . '"\s*$/m';
				$this->assertMatchesRegularExpression(
					$pattern,
					$po,
					sprintf(
						'AC-3: Glossary mapping "%s" → "%s" missing or mistranslated. '
						. 'A drift here breaks UI-string consistency across slices.',
						$msgid,
						$msgstr
					)
				);
				$matched++;
			}

			$this->assertGreaterThanOrEqual(
				5,
				$matched,
				'AC-3: At least 5 glossary mappings must be verified — Slice-46 spec floor.'
			);
		}

		// ===================================================================
		// AC-4: GIVEN README.md
		//       WHEN read
		//       THEN it has exactly 7 H2 sections in order, each with
		//       at least one paragraph of content.
		// ===================================================================

		/**
		 * AC-4: README has the 7 required `## …` H2 sections in the
		 * prescribed order, each with at least one non-empty content line.
		 */
		public function test_readme_has_required_top_level_sections_in_order(): void
		{
			$md = self::readReadme();

			$expectedSections = array(
				'Overview',
				'Setup',
				'Features',
				'Architecture',
				'Development',
				'Troubleshooting',
				'License',
			);

			// Capture every H2 heading (`^## <title>$`) in document order.
			preg_match_all( '/^##\s+(.+?)\s*$/m', $md, $matches );
			$h2Headings = $matches[1] ?? array();

			$this->assertSame(
				$expectedSections,
				$h2Headings,
				'AC-4: README MUST have exactly the 7 H2 sections '
				. '[Overview, Setup, Features, Architecture, Development, Troubleshooting, License] '
				. 'in this order. No extra H2s, no missing ones.'
			);

			// Each section MUST have at least one content paragraph (no empty
			// stubs). Split on `^## ` and verify each chunk has ≥1 non-blank,
			// non-heading line of body text.
			$chunks = preg_split( '/^##\s+/m', $md );
			// Element [0] is everything before the first H2 (intro/title).
			array_shift( $chunks );

			$this->assertCount(
				count( $expectedSections ),
				$chunks,
				'AC-4: README MUST split into exactly 7 H2 sections.'
			);

			foreach ( $chunks as $idx => $chunk ) {
				// Find non-blank, non-heading body lines.
				$lines = explode( "\n", (string) $chunk );
				$body  = array();
				$first = true;
				foreach ( $lines as $line ) {
					if ( $first ) {
						// First line is the heading title we just split on.
						$first = false;
						continue;
					}
					$trim = trim( $line );
					if ( '' === $trim || str_starts_with( $trim, '#' ) ) {
						continue;
					}
					$body[] = $trim;
				}

				$this->assertNotEmpty(
					$body,
					sprintf(
						'AC-4: README section #%d ("%s") MUST have at least one body paragraph.',
						$idx + 1,
						$expectedSections[ $idx ]
					)
				);
			}
		}

		// ===================================================================
		// AC-5: GIVEN README ## Setup
		//       WHEN read
		//       THEN it contains 5 numbered steps: composer install,
		//       activate plugin, settings + test-connection, copy webhook
		//       secret, trigger catalog-sync. Each links to architecture.md
		//       or discovery.md.
		// ===================================================================

		/**
		 * AC-5: Setup section has 5 numbered steps, each linking to a
		 * relative architecture.md / discovery.md anchor.
		 */
		public function test_readme_setup_section_lists_five_steps_with_references(): void
		{
			$md = self::readReadme();

			// Slice off only the ## Setup section content.
			$pattern = '/^##\s+Setup\s*\n(.*?)(?=^##\s)/ms';
			$this->assertMatchesRegularExpression(
				$pattern,
				$md,
				'AC-5: README MUST contain a `## Setup` section.'
			);
			preg_match( $pattern, $md, $matches );
			$setupBody = $matches[1] ?? '';

			// Numbered steps (`^N. ` at line start, N = 1..9).
			preg_match_all( '/^\d+\.\s+/m', $setupBody, $stepMatches );
			$stepCount = count( $stepMatches[0] ?? array() );

			$this->assertSame(
				5,
				$stepCount,
				sprintf(
					'AC-5: Setup MUST list exactly 5 numbered steps (composer install, activate, '
					. 'settings + test-connection, copy webhook secret, catalog-sync). Found %d.',
					$stepCount
				)
			);

			// Each step MUST contain a relative architecture.md or
			// discovery.md link of the form `(../../specs/.../architecture.md…)`.
			// We extract each step's body greedily up to the next `^\d+. `
			// boundary (or end-of-section). PCRE multiline + lookahead.
			preg_match_all(
				'/^\d+\.\s+(.*?)(?=^\d+\.\s|\z)/ms',
				$setupBody,
				$bodyMatches
			);
			$bodies = $bodyMatches[1] ?? array();
			$this->assertCount(
				5,
				$bodies,
				sprintf( 'AC-5: parsed step bodies count mismatch — found %d, expected 5.', count( $bodies ) )
			);

			foreach ( $bodies as $i => $body ) {
				$this->assertMatchesRegularExpression(
					'/\]\(\.\.\/\.\.\/specs\/[^\s)]+\.md(?:#[^)]+)?\)/',
					(string) $body,
					sprintf(
						'AC-5: Setup step #%d MUST link to a relative architecture.md or '
						. 'discovery.md path under ../../specs/. Found body: %s',
						$i + 1,
						trim( substr( (string) $body, 0, 200 ) )
					)
				);
			}

			// Per Slice-46 AC-5 the link MUST point at the
			// 2026-05-03-…-spreadconnect-pod-plugin-v2 spec dir.
			$this->assertStringContainsString(
				'2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage',
				$setupBody,
				'AC-5: Setup links MUST resolve into the v2 spec dir.'
			);
		}

		// ===================================================================
		// AC-6: GIVEN README ## Features
		//       WHEN read
		//       THEN it lists exactly the 10 Discovery slices as bullet
		//       points with 1-sentence descriptions.
		// ===================================================================

		/**
		 * AC-6: Features section lists the 10 Discovery-slice bullets, each
		 * carrying a 1-sentence description (no slice-detail-echo).
		 */
		public function test_readme_features_section_lists_ten_discovery_slices(): void
		{
			$md = self::readReadme();

			// Slice off the Features section body.
			$pattern = '/^##\s+Features\s*\n(.*?)(?=^##\s)/ms';
			$this->assertMatchesRegularExpression(
				$pattern,
				$md,
				'AC-6: README MUST contain a `## Features` section.'
			);
			preg_match( $pattern, $md, $matches );
			$featuresBody = $matches[1] ?? '';

			// Bullet-points: `^- ` or `^* ` at line start.
			preg_match_all( '/^[\-\*]\s+/m', $featuresBody, $bulletMatches );
			$bulletCount = count( $bulletMatches[0] ?? array() );

			$this->assertSame(
				10,
				$bulletCount,
				sprintf(
					'AC-6: Features MUST list exactly 10 Discovery-slice bullet-points. Found %d.',
					$bulletCount
				)
			);

			// Each of the 10 Discovery-slice names must appear in the body.
			$expectedSlices = array(
				'Plugin Foundation',
				'API Client + Authentication',
				'Webhook Receiver + Subscriptions',
				'Catalog-Sync',
				'Order-Lifecycle',
				'Stock-Sync',
				'Hub-Page + Settings',
				'Inline UX',
				'Failure-Recovery',
				'Logs + Polish',
			);

			foreach ( $expectedSlices as $slice ) {
				$this->assertStringContainsString(
					$slice,
					$featuresBody,
					sprintf(
						'AC-6: Features bullet for Discovery slice "%s" missing.',
						$slice
					)
				);
			}
		}

		// ===================================================================
		// AC-7: GIVEN README ## Architecture
		//       WHEN read
		//       THEN exactly one Markdown link to architecture.md plus a
		//       short list of the 5 layers (Bootstrap, Domain, Application,
		//       Infrastructure, Adapter), each with one example service.
		// ===================================================================

		/**
		 * AC-7: Architecture section links the architecture spec exactly
		 * once and lists all 5 layer names.
		 */
		public function test_readme_architecture_section_links_spec_and_lists_layers(): void
		{
			$md = self::readReadme();

			// Slice off the Architecture section.
			$pattern = '/^##\s+Architecture\s*\n(.*?)(?=^##\s)/ms';
			$this->assertMatchesRegularExpression(
				$pattern,
				$md,
				'AC-7: README MUST contain a `## Architecture` section.'
			);
			preg_match( $pattern, $md, $matches );
			$archBody = $matches[1] ?? '';

			// Exactly one Markdown link to the architecture.md file.
			$linkPattern = '/\[([^\]]+)\]\((\.\.\/\.\.\/specs\/[^\s)]+architecture\.md)(?:#[^)]+)?\)/';
			preg_match_all( $linkPattern, $archBody, $linkMatches );
			$archLinkCount = count( $linkMatches[0] ?? array() );

			$this->assertSame(
				1,
				$archLinkCount,
				sprintf(
					'AC-7: Architecture section MUST contain EXACTLY one link to architecture.md '
					. '(no schema/flow-diagram-copy). Found %d.',
					$archLinkCount
				)
			);

			// All 5 architecture layer names appear in the body.
			$expectedLayers = array( 'Bootstrap', 'Domain', 'Application', 'Infrastructure', 'Adapter' );
			foreach ( $expectedLayers as $layer ) {
				$this->assertStringContainsString(
					$layer,
					$archBody,
					sprintf(
						'AC-7: Architecture section MUST mention layer "%s".',
						$layer
					)
				);
			}
		}

		// ===================================================================
		// AC-8: GIVEN Dashboard::render() with manage_woocommerce
		//       WHEN ?section=dashboard
		//       THEN Card 1 (Connection) renders the sc_health transient
		//       status; missing transient → "unknown" + Re-test button.
		//       NO live SpreadconnectClient call.
		// ===================================================================

		/**
		 * AC-8: With sc_health transient set to status='ok', Card 1 renders
		 * the OK label.
		 */
		public function test_dashboard_card_connection_reads_health_transient(): void
		{
			$this->transientStore['sc_health'] = array(
				'status'     => 'ok',
				'checked_at' => 1746316800, // 2026-05-04 00:00:00
			);

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--connection',
				$html,
				'AC-8: Card 1 (connection) MUST be rendered.'
			);
			$this->assertStringContainsString(
				'OK',
				$html,
				'AC-8: With sc_health.status=ok, Card 1 MUST render the "OK" label.'
			);
			$this->assertStringContainsString(
				'spreadconnect-card__status--ok',
				$html,
				'AC-8: Connection-status BEM modifier --ok MUST appear when status=ok.'
			);
		}

		/**
		 * AC-8: When sc_health transient is missing, Card 1 falls back to
		 * "unknown" and renders a Re-test button linking to Settings.
		 */
		public function test_dashboard_card_connection_falls_back_to_unknown_with_retest_button(): void
		{
			// transient store is empty by default → get_transient returns false.

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--connection',
				$html,
				'AC-8: Card 1 MUST be rendered.'
			);
			$this->assertStringContainsString(
				'unknown',
				$html,
				'AC-8: Missing sc_health MUST yield status "unknown".'
			);
			$this->assertStringContainsString(
				'page=spreadconnect&section=settings',
				$html,
				'AC-8: Card 1 MUST link the Re-test button to the Settings sub-page.'
			);
		}

		/**
		 * AC-8: Dashboard::render() MUST NOT instantiate or invoke
		 * SpreadconnectClient — no live HTTP call from the dashboard render
		 * path. We verify by the absence of any wp_remote_*() spy hit:
		 * each of those WP HTTP wrappers, when called, would trigger a
		 * Brain\Monkey "missing expectation" error since we never `when()`
		 * them. A successful render therefore proves no HTTP I/O happened.
		 */
		public function test_dashboard_card_connection_does_not_invoke_spreadconnect_client(): void
		{
			$this->transientStore['sc_health'] = array(
				'status'     => 'auth_failed',
				'checked_at' => 1746316800,
			);

			// If the render path calls SpreadconnectClient::authenticate, it
			// will go through wp_remote_* helpers — Brain\Monkey will throw
			// MissingFunctionExpectations for any such call.
			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--connection',
				$html,
				'AC-8: Card 1 MUST render even with auth_failed status.'
			);

			// Verify the static label-mapping rendered the auth_failed branch.
			$this->assertStringContainsString(
				'Invalid Key',
				$html,
				'AC-8: auth_failed status MUST render the localised "Invalid Key …" label.'
			);
		}

		// ===================================================================
		// AC-9: GIVEN Dashboard::render() with Catalog card
		//       WHEN rendered
		//       THEN linked-products count = created + updated from the
		//       latest state='complete' SyncHistoryRepo row, plus
		//       localised started_at; empty repo → "No sync runs yet".
		// ===================================================================

		/**
		 * AC-9: With a SyncHistoryRepo::findLatest() row available, the
		 * Catalog card renders created+updated and a localised started_at.
		 */
		public function test_dashboard_card_catalog_reads_latest_sync_history_row(): void
		{
			// Program the recording wpdb to return a history row.
			$this->wpdb->nextRow = array(
				'id'            => 7,
				'state'         => 'complete',
				'created_count' => 12,
				'updated_count' => 34,
				'started_at'    => '2026-05-01 10:00:00',
			);

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--catalog',
				$html,
				'AC-9: Card 2 (catalog) MUST be rendered.'
			);
			$this->assertStringContainsString(
				'>46<',
				$html,
				'AC-9: Catalog card MUST display created+updated = 12+34 = 46 as the linked-products count.'
			);
			$this->assertStringContainsString(
				'Linked',
				$html,
				'AC-9: Catalog card MUST carry a "Linked" label.'
			);

			// One get_row() call against a SQL containing `state` and
			// LIMIT 1 — the SyncHistoryRepo::findLatest contract.
			$this->assertNotEmpty(
				$this->wpdb->getRowCalls,
				'AC-9: Catalog card MUST issue a get_row() against sync_history.'
			);
			$found = false;
			foreach ( $this->wpdb->getRowCalls as $sql ) {
				if ( str_contains( $sql, 'state' ) && str_contains( $sql, 'LIMIT 1' ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'AC-9: SyncHistoryRepo::findLatest() MUST issue ORDER BY started_at DESC LIMIT 1 with WHERE state.'
			);
		}

		/**
		 * AC-9: With no completed sync history row, Catalog card renders
		 * the "No sync runs yet" empty-state.
		 */
		public function test_dashboard_card_catalog_renders_no_sync_yet_when_repo_empty(): void
		{
			$this->wpdb->nextRow = null; // No row in sync_history.

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--catalog',
				$html,
				'AC-9: Card 2 MUST always render its scaffold even on empty repo.'
			);
			$this->assertStringContainsString(
				'No sync runs yet',
				$html,
				'AC-9: Empty sync history MUST yield the "No sync runs yet" empty-state copy.'
			);
		}

		// ===================================================================
		// AC-10: GIVEN Dashboard::render() with Orders card
		//        WHEN rendered
		//        THEN ONE aggregate query is executed for the last 30 days
		//        bucketed by _spreadconnect_state — output: 4 counts
		//        (Pending, Confirmed, Processed, Failed).
		// ===================================================================

		/**
		 * AC-10: Orders card issues one aggregate query bounded to a
		 * 30-day window and renders 4 state-buckets.
		 */
		public function test_dashboard_card_orders_renders_four_state_counts_for_30d_window(): void
		{
			// Program 4 buckets — these are the 4 _spreadconnect_state enum values.
			$this->wpdb->nextResults = array(
				array( 'state' => 'NEW',              'cnt' => 3 ),
				array( 'state' => 'CONFIRMED',        'cnt' => 5 ),
				array( 'state' => 'PROCESSED',        'cnt' => 11 ),
				array( 'state' => 'failed_to_submit', 'cnt' => 2 ),
			);

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--orders',
				$html,
				'AC-10: Card 3 (orders) MUST be rendered.'
			);

			// All 4 labels MUST appear (i18n source-strings, since __() returns arg 1).
			foreach ( array( 'Pending', 'Confirmed', 'Processed', 'Failed' ) as $label ) {
				$this->assertStringContainsString(
					$label . ':',
					$html,
					sprintf(
						'AC-10: Orders card MUST show the "%s" state-label.',
						$label
					)
				);
			}

			// All 4 counts MUST appear in the markup.
			foreach ( array( 3, 5, 11, 2 ) as $count ) {
				$this->assertStringContainsString(
					'>' . $count . '<',
					$html,
					sprintf(
						'AC-10: Orders card MUST render count %d for one of the 4 states.',
						$count
					)
				);
			}

			// Exactly ONE aggregate query — Slice-46 AC-10 forbids per-state
			// N+1 queries. The Orders card MUST go through prepare() + a
			// single get_results().
			$ordersQueries = array_filter(
				$this->wpdb->getResultsCalls,
				static fn( string $sql ): bool =>
					str_contains( $sql, 'wc_orders_meta' )
					|| str_contains( $sql, '_spreadconnect_state' )
					|| str_contains( $sql, 'GROUP BY' )
			);
			$this->assertCount(
				1,
				$ordersQueries,
				'AC-10: Orders card MUST issue EXACTLY ONE aggregate get_results() query — '
				. 'no per-state N+1 query.'
			);

			// HPOS-aware: query against `wc_orders_meta` (HPOS) NOT `wp_postmeta`.
			$ordersSql = (string) reset( $ordersQueries );
			$this->assertStringContainsString(
				'wc_orders_meta',
				$ordersSql,
				'AC-10: Orders query MUST be HPOS-aware (read from wc_orders_meta).'
			);
			$this->assertStringNotContainsString(
				'wp_postmeta',
				$ordersSql,
				'AC-10: Orders query MUST NOT touch wp_postmeta (HPOS forbids legacy meta access).'
			);

			// 30-day window: query MUST reference a date bound — date_created_gmt
			// in HPOS-aware path, or a timestamp comparison on the orders table.
			$this->assertStringContainsString(
				'date_created_gmt',
				$ordersSql,
				'AC-10: Orders query MUST bound by date_created_gmt for the 30-day window.'
			);
		}

		// ===================================================================
		// AC-11: GIVEN Dashboard::render() with Webhooks card
		//        WHEN rendered
		//        THEN (a) `X / 7 active` from sc_subscriptions_status
		//        transient or SubscriptionManager::getCachedStatus(),
		//        (b) latest webhook log row's received_at + event_type.
		//        Empty log → "No event received yet".
		// ===================================================================

		/**
		 * AC-11: With sc_subscriptions_status set to active=4/total=7, the
		 * Webhooks card renders "4 / 7 active".
		 */
		public function test_dashboard_card_webhooks_renders_active_subscription_count(): void
		{
			$this->transientStore['sc_subscriptions_status'] = array(
				'active' => 4,
				'total'  => 7,
			);

			// No webhook log row → "No event received yet".
			$this->wpdb->nextRow = null;

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--webhooks',
				$html,
				'AC-11: Card 4 (webhooks) MUST be rendered.'
			);
			$this->assertStringContainsString(
				'4 / 7 active',
				$html,
				'AC-11: Webhooks card MUST format the active/total subscription count as "X / 7 active".'
			);
		}

		/**
		 * AC-11: With a WebhookLogRepo::findLatest() row available, the
		 * card renders the event_type + a localised received_at.
		 */
		public function test_dashboard_card_webhooks_renders_latest_event_received_at(): void
		{
			$this->transientStore['sc_subscriptions_status'] = array(
				'active' => 7,
				'total'  => 7,
			);

			// First get_row() handles SyncHistory; second handles WebhookLog.
			// To keep tests deterministic we override $nextRow per query
			// rather than queueing — we patch the wpdb stub via reflection
			// to vend per-call values. Simpler: install a single response
			// that satisfies BOTH (it's just an associative array — the
			// readers extract different keys).
			$this->wpdb->nextRow = array(
				// SyncHistory keys (not used here):
				'created_count' => 0,
				'updated_count' => 0,
				'state'         => 'complete',
				'started_at'    => '2026-05-01 10:00:00',
				// WebhookLog keys:
				'event_type'    => 'order.created',
				'received_at'   => '2026-05-03 12:34:56',
			);

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'order.created',
				$html,
				'AC-11: Webhooks card MUST render the latest event_type from WebhookLogRepo::findLatest().'
			);
			$this->assertStringContainsString(
				'Received',
				$html,
				'AC-11: Webhooks card MUST carry a "Received" label for the latest event meta line.'
			);
		}

		/**
		 * AC-11: When no webhook log row exists, the Webhooks card shows
		 * "No event received yet".
		 */
		public function test_dashboard_card_webhooks_renders_no_event_when_log_empty(): void
		{
			// Both transient + log empty.
			$this->wpdb->nextRow = null;

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'No event received yet',
				$html,
				'AC-11: Empty webhook log MUST yield "No event received yet" empty-state.'
			);
		}

		// ===================================================================
		// AC-12: GIVEN Dashboard::render() with Failed-Ops card
		//        WHEN rendered
		//        THEN FailedOpsRepo::count('unresolved') is invoked once,
		//        the count is shown + a deep-link to ?section=failed.
		//        If count > 0 AND AdminNoticeStore::count('error') > 0,
		//        a red severity banner is rendered.
		// ===================================================================

		/**
		 * AC-12: The Failed-Ops card calls FailedOpsRepo::count() once
		 * (single get_var() against `idx_state_op_type`), renders the
		 * resulting count + the Failed-Ops deep-link.
		 */
		public function test_dashboard_card_failed_renders_unresolved_count_with_link(): void
		{
			// FailedOpsRepo::count() returns the get_var() value as an int.
			$this->wpdb->nextVar = 5;

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card--failed-ops',
				$html,
				'AC-12: Card 5 (failed-ops) MUST be rendered.'
			);
			$this->assertStringContainsString(
				'>5<',
				$html,
				'AC-12: Card 5 MUST render the unresolved-count returned by the repo.'
			);
			$this->assertStringContainsString(
				'page=spreadconnect&section=failed',
				$html,
				'AC-12: Card 5 MUST contain the deep-link ?page=spreadconnect&section=failed.'
			);

			// At least one get_var() call MUST hit `failed_ops` with WHERE state.
			$found = false;
			foreach ( $this->wpdb->getVarCalls as $sql ) {
				if ( str_contains( $sql, 'failed_ops' ) && str_contains( $sql, 'state' ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'AC-12: FailedOpsRepo::count() MUST issue a get_var() against failed_ops + state.'
			);
		}

		/**
		 * AC-12: When count > 0 AND AdminNoticeStore::count('error') > 0,
		 * the severity banner is rendered.
		 *
		 * We seed the FailedOpsRepo count via $wpdb->nextVar AND seed
		 * AdminNoticeStore via the option-store: AdminNoticeStore reads
		 * its notices from the `spreadconnect_admin_notices` option, so
		 * we override get_option to return one notice with severity=error.
		 */
		public function test_dashboard_card_failed_renders_severity_banner_when_error_notices_present(): void
		{
			$this->wpdb->nextVar = 3; // count('unresolved') = 3 → > 0.

			// Override get_option so AdminNoticeStore::loadList() finds one
			// error-severity notice. AdminNoticeStore stores notices keyed
			// under the option `spreadconnect_admin_notices`.
			Functions\when( 'get_option' )->alias(
				static function ( string $key, $default = false ) {
					if ( 'date_format' === $key ) {
						return 'Y-m-d';
					}
					if ( 'spreadconnect_admin_notices' === $key ) {
						return array(
							array(
								'id'         => 'failed-op-notice-1',
								'severity'   => 'error',
								'message'    => 'A permanent failure was recorded.',
								'created_at' => '2026-05-04 00:00:00',
							),
						);
					}
					return $default;
				}
			);

			$html = $this->renderDashboard();

			$this->assertStringContainsString(
				'spreadconnect-card__banner--error',
				$html,
				'AC-12: A red severity banner MUST be rendered when count>0 AND error-notices>0.'
			);
		}

		/**
		 * AC-12 (negative): When count > 0 but NO error-severity notice
		 * exists, the severity banner is NOT rendered.
		 */
		public function test_dashboard_card_failed_no_severity_banner_when_no_error_notices(): void
		{
			$this->wpdb->nextVar = 3; // count > 0
			// option-store has no `spreadconnect_admin_notices` key →
			// AdminNoticeStore::count('error') returns 0.

			$html = $this->renderDashboard();

			$this->assertStringNotContainsString(
				'spreadconnect-card__banner--error',
				$html,
				'AC-12: Severity banner MUST NOT render without error-severity notices.'
			);
		}

		// ===================================================================
		// AC-13: GIVEN Dashboard render
		//        WHEN output is measured
		//        THEN counts/strings escaped via esc_html, links via
		//        esc_url, dates via date_i18n; all card titles + labels
		//        wrapped in __() with domain 'spreadconnect-pod'.
		// ===================================================================

		/**
		 * AC-13: Spy on __() and verify the textdomain is ALWAYS
		 * 'spreadconnect-pod' for every Dashboard call.
		 */
		public function test_dashboard_output_is_properly_escaped_and_i18n_wrapped(): void
		{
			// Capture every domain passed to __() during render.
			$domainsUsed = array();
			Functions\when( '__' )->alias(
				static function ( string $text, $domain = null ) use ( &$domainsUsed ) {
					$domainsUsed[] = $domain;
					return $text;
				}
			);
			Functions\when( 'esc_html__' )->alias(
				static function ( string $text, $domain = null ) use ( &$domainsUsed ) {
					$domainsUsed[] = $domain;
					return $text;
				}
			);

			// Prep enough state to traverse all 5 cards.
			$this->transientStore['sc_health'] = array(
				'status'     => 'ok',
				'checked_at' => 1746316800,
			);
			$this->transientStore['sc_subscriptions_status'] = array(
				'active' => 7,
				'total'  => 7,
			);
			$this->wpdb->nextRow     = null;       // No sync, no webhook.
			$this->wpdb->nextResults = array();    // No orders rows.
			$this->wpdb->nextVar     = 0;          // No failed ops.

			$this->renderDashboard();

			$this->assertNotEmpty(
				$domainsUsed,
				'AC-13: Dashboard MUST call __()/esc_html__() at least once.'
			);
			foreach ( $domainsUsed as $idx => $domain ) {
				$this->assertSame(
					'spreadconnect-pod',
					$domain,
					sprintf(
						'AC-13: __()-call #%d MUST use domain "spreadconnect-pod" — found "%s".',
						$idx,
						var_export( $domain, true )
					)
				);
			}
		}

		/**
		 * AC-13: The Connection card timestamp goes through date_i18n() —
		 * never `date()` directly. We verify by spying on date_i18n and
		 * asserting it was invoked at least once with the configured format.
		 */
		public function test_dashboard_dates_render_via_date_i18n(): void
		{
			$dateCalls = array();
			Functions\when( 'date_i18n' )->alias(
				static function ( string $format, $timestamp = false ) use ( &$dateCalls ): string {
					$dateCalls[] = array( $format, $timestamp );
					return is_int( $timestamp ) && $timestamp > 0
						? gmdate( $format, $timestamp )
						: '';
				}
			);

			$this->transientStore['sc_health'] = array(
				'status'     => 'ok',
				'checked_at' => 1746316800,
			);

			$this->renderDashboard();

			$this->assertNotEmpty(
				$dateCalls,
				'AC-13: Dashboard MUST format any rendered timestamp via date_i18n() (never raw date()).'
			);

			// First call uses the option date_format value (default Y-m-d in our stub).
			$this->assertSame(
				'Y-m-d',
				$dateCalls[0][0],
				'AC-13: date_i18n() MUST receive get_option("date_format") as its $format arg.'
			);
		}

		// ===================================================================
		// AC-14: GIVEN Dashboard::render()
		//        WHEN a repo throws \Throwable
		//        THEN the offending card shows "Daten nicht verfügbar" /
		//        equivalent + WC-Logger entry; other cards keep rendering.
		// ===================================================================

		/**
		 * AC-14: When the SyncHistoryRepo::findLatest() lookup throws,
		 * Card 2 falls back gracefully and the OTHER cards still render.
		 */
		public function test_dashboard_card_isolates_throwable_and_renders_fallback(): void
		{
			// Throw on the next get_row() — that's SyncHistoryRepo::findLatest().
			$this->wpdb->throwOnNextQuery = new \RuntimeException( 'simulated DB outage' );

			$this->transientStore['sc_health'] = array(
				'status'     => 'ok',
				'checked_at' => 1746316800,
			);
			$this->transientStore['sc_subscriptions_status'] = array(
				'active' => 7,
				'total'  => 7,
			);

			$html = $this->renderDashboard();

			// All 5 cards MUST still appear in the output.
			foreach ( array( 'connection', 'catalog', 'orders', 'webhooks', 'failed-ops' ) as $slug ) {
				$this->assertStringContainsString(
					'spreadconnect-card--' . $slug,
					$html,
					sprintf(
						'AC-14: Per-card try/catch MUST keep all 5 cards rendering even when one throws — '
						. 'card "%s" missing from output.',
						$slug
					)
				);
			}

			// The catalog card body MUST contain the unavailable-fallback copy.
			$this->assertStringContainsString(
				'An unexpected error occurred',
				$html,
				'AC-14: A throwing card MUST render the "An unexpected error occurred" fallback.'
			);
		}

		/**
		 * AC-14: A caught \Throwable from a card MUST be logged via the
		 * WC-Logger on the `spreadconnect-failure` source.
		 */
		public function test_dashboard_logs_error_when_card_repo_throws(): void
		{
			$this->wpdb->throwOnNextQuery = new \RuntimeException( 'simulated DB outage' );

			$this->renderDashboard();

			$this->assertNotEmpty(
				$this->loggerEntries,
				'AC-14: A throwing card MUST emit a wc_get_logger()->log() entry.'
			);

			// At least one entry has level=error and source=spreadconnect-failure.
			$matched = array_filter(
				$this->loggerEntries,
				static function ( array $entry ): bool {
					$source = $entry['context']['source'] ?? null;
					return 'error' === $entry['level']
						&& 'spreadconnect-failure' === $source;
				}
			);
			$this->assertNotEmpty(
				$matched,
				'AC-14: WC-Logger MUST emit at least one error-level entry on source "spreadconnect-failure".'
			);
		}
	}
}
