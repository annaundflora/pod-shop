<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `ImageSideloader` references three globals that are normally provided by
// WordPress core at runtime. Since we do NOT load WP in tests (mock_external
// strategy per slice-21 spec), we provide minimal stubs:
//
//   1. `ABSPATH`              — string constant.
//   2. `\WP_Error`            — minimal stub class with `get_error_code()` /
//                               `get_error_message()` / `get_error_data()`.
//   3. `media_sideload_image` — global function whose return value is
//                               dispatched from
//                               `$GLOBALS['__test_msi_response']` so each
//                               test can configure success / WP_Error /
//                               throw scenarios.
//
// Once defined, these stubs persist for the whole PHPUnit run — `function_exists`
// returns `true` everywhere afterwards. AC-1 (require_once branch) is
// therefore covered by static source analysis (verifying the three require_once
// lines exist in the correct order); AC-2/3 cover the `function_exists`-true
// branch behaviourally.
// ---------------------------------------------------------------------------

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
	}

	if ( ! class_exists( 'WP_Error', false ) ) {
		/**
		 * Minimal WP_Error stub. Mirrors the public surface used by ImageSideloader
		 * (`new WP_Error( $code, $message, $data )` constructor).
		 */
		class WP_Error
		{
			public string $code;
			public string $message;
			public mixed $data;

			public function __construct( string $code = '', string $message = '', mixed $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'media_sideload_image' ) ) {
		/**
		 * Global stub for WP-Core's `media_sideload_image()`.
		 *
		 * Dispatch-Mechanik: das aktuelle Test-Setup steuert die Rueckgabe ueber
		 * `$GLOBALS['__test_msi_response']`. Zusaetzlich verfolgt
		 * `$GLOBALS['__test_msi_calls']` jeden Aufruf samt Argumenten — das
		 * erlaubt Spy-Asserts ueber Anzahl/Reihenfolge/Parameter ohne externe
		 * Mocking-Library.
		 *
		 * @param string $url
		 * @param int    $post_id
		 * @param mixed  $desc
		 * @param string $return_mode
		 * @return int|\WP_Error
		 */
		function media_sideload_image( string $url, int $post_id = 0, $desc = null, string $return_mode = 'html' ) {
			$GLOBALS['__test_msi_calls'][] = [
				'url'         => $url,
				'post_id'     => $post_id,
				'desc'        => $desc,
				'return_mode' => $return_mode,
			];

			$response = $GLOBALS['__test_msi_response'] ?? 0;

			if ( $response instanceof \Throwable ) {
				throw $response;
			}

			return $response;
		}
	}
}

namespace SpreadconnectPod\Tests {

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Catalog\ImageSideloader;
	use WP_Error;

	/**
	 * Slice 21 — Catalog\ImageSideloader (Cron-Context-Safe)
	 *
	 * Acceptance tests gegen `slice-21-image-sideloader.md`. Mocking-Strategy
	 * `mock_external`: WP-Core-Funktionen werden ueber File-Scope-Stubs gemockt
	 * (siehe Bootstrap oben), die statische Idempotenz-Property wird per
	 * Reflection zwischen Tests resettet. Jeder Test ist 1:1 aus einem
	 * GIVEN/WHEN/THEN abgeleitet.
	 *
	 * AC-7 (composer test exit code) wird vom Orchestrator gemessen.
	 */
	final class Slice21ImageSideloaderTest extends TestCase
	{
		/**
		 * Setzt die private-static `$includesLoaded`-Property zwischen Tests
		 * zurueck. Ohne Reset wuerde der erste erfolgreiche Lauf alle weiteren
		 * Tests in den Short-Circuit-Branch zwingen — wir wollen aber jeden
		 * Test isoliert mit definiertem Initial-Zustand starten.
		 */
		private function resetIncludesLoadedFlag(): void
		{
			$rc   = new ReflectionClass( ImageSideloader::class );
			$prop = $rc->getProperty( 'includesLoaded' );
			$prop->setValue( null, false );
		}

		protected function setUp(): void
		{
			parent::setUp();

			// Frisches Tracking pro Test.
			$GLOBALS['__test_msi_calls']    = [];
			$GLOBALS['__test_msi_response'] = 0;

			$this->resetIncludesLoadedFlag();
		}

		protected function tearDown(): void
		{
			unset( $GLOBALS['__test_msi_calls'], $GLOBALS['__test_msi_response'] );

			parent::tearDown();
		}

		/**
		 * Liefert den vollen Source-Code der ImageSideloader-Klasse als String.
		 * Wird von AC-1 (Source-Reihenfolge der require_once-Pfade) verwendet,
		 * weil ein behaviouraler Test der "function_exists === false"-Branch
		 * unmoeglich ist, sobald der File-Scope-Stub einmal geladen wurde
		 * (PHP erlaubt kein un-define von Funktionen).
		 */
		private function imageSideloaderSource(): string
		{
			$rc   = new ReflectionClass( ImageSideloader::class );
			$file = $rc->getFileName();

			$this->assertNotFalse( $file, 'ImageSideloader-Datei muss via Reflection lokalisierbar sein.' );

			$source = file_get_contents( (string) $file );
			$this->assertNotFalse( $source, 'ImageSideloader-Datei muss lesbar sein.' );

			return (string) $source;
		}

		// -------------------------------------------------------------------
		// AC-1: GIVEN media_sideload_image() ist NICHT definiert
		//       WHEN ensureAdminIncludesLoaded() aufgerufen wird
		//       THEN werden file.php -> media.php -> image.php in dieser
		//            Reihenfolge via require_once geladen.
		// -------------------------------------------------------------------
		public function test_ac1_ensure_admin_includes_loaded_requires_three_files_in_order(): void
		{
			$source = $this->imageSideloaderSource();

			// Die drei require_once-Pfade muessen in genau dieser Reihenfolge
			// vorkommen. Wir suchen pro Pfad die Position im Source und
			// verifizieren `pos(file) < pos(media) < pos(image)`.
			$patterns = [
				'file'  => '/require_once\s+ABSPATH\s*\.\s*([\'"])wp-admin\/includes\/file\.php\1\s*;/',
				'media' => '/require_once\s+ABSPATH\s*\.\s*([\'"])wp-admin\/includes\/media\.php\1\s*;/',
				'image' => '/require_once\s+ABSPATH\s*\.\s*([\'"])wp-admin\/includes\/image\.php\1\s*;/',
			];

			$positions = [];
			foreach ( $patterns as $key => $pattern ) {
				$ok = preg_match( $pattern, $source, $matches, PREG_OFFSET_CAPTURE );
				$this->assertSame(
					1,
					$ok,
					"AC-1: ImageSideloader muss `require_once ABSPATH . 'wp-admin/includes/{$key}.php';` enthalten."
				);
				$positions[ $key ] = (int) $matches[0][1];
			}

			$this->assertLessThan(
				$positions['media'],
				$positions['file'],
				'AC-1: file.php muss VOR media.php geladen werden (download_url-Abhaengigkeit).'
			);
			$this->assertLessThan(
				$positions['image'],
				$positions['media'],
				'AC-1: media.php muss VOR image.php geladen werden (architecture.md "Stack & Conventions").'
			);
		}

		/**
		 * Behaviouraler AC-1-Smoke-Test: aus File-Scope wurde der
		 * `media_sideload_image`-Stub bereits geladen, also kann der echte
		 * `function_exists`-Branch nicht mehr getriggert werden. Wir
		 * verifizieren stattdessen, dass `ensureAdminIncludesLoaded()` mit
		 * geladener Funktion fehlerfrei durchlaeuft (kein Side-Effect-Crash).
		 */
		public function test_ac1_ensure_admin_includes_loaded_executes_without_error_when_function_already_loaded(): void
		{
			$this->assertTrue(
				function_exists( 'media_sideload_image' ),
				'Pre-condition: Bootstrap-Stub muss media_sideload_image definiert haben.'
			);

			ImageSideloader::ensureAdminIncludesLoaded();

			// Wenn wir hier ankommen, hat die Methode keinen Fatal Error
			// produziert — Smoke-OK. Detaillierte Branch-Verifikation:
			// statisches Source-Pattern oben (`test_ac1_..._files_in_order`).
			$this->addToAssertionCount( 1 );
		}

		// -------------------------------------------------------------------
		// AC-2: GIVEN media_sideload_image() ist BEREITS definiert
		//       WHEN ensureAdminIncludesLoaded() aufgerufen wird
		//       THEN keine require_once-Aufrufe (No-Op).
		// -------------------------------------------------------------------
		public function test_ac2_ensure_admin_includes_loaded_is_noop_when_function_exists(): void
		{
			// File-Scope-Stub garantiert, dass media_sideload_image existiert.
			$this->assertTrue( function_exists( 'media_sideload_image' ) );

			// Static-Property auf false setzen, damit der Static-Guard nicht
			// short-circuitet und der `function_exists`-Branch wirklich
			// ausgewertet wird.
			$this->resetIncludesLoadedFlag();

			$rc       = new ReflectionClass( ImageSideloader::class );
			$beforeOk = $rc->getProperty( 'includesLoaded' )->getValue();
			$this->assertFalse( $beforeOk, 'Pre-condition: includesLoaded muss false sein.' );

			// Da media_sideload_image existiert UND ABSPATH auf einen Pfad
			// ohne WP-Core-Files zeigt: wuerde die Methode dort `require_once`
			// versuchen, gaebe es einen Fatal Error. Dass der Test sauber
			// durchlaeuft, ist der Beweis fuer "kein require_once" — kein
			// Mock-Inspector noetig.
			ImageSideloader::ensureAdminIncludesLoaded();

			$afterOk = $rc->getProperty( 'includesLoaded' )->getValue();
			$this->assertTrue(
				$afterOk,
				'AC-2: Methode markiert includesLoaded=true (Cache fuer Folge-Aufrufe) auch ohne require_once.'
			);
		}

		// -------------------------------------------------------------------
		// AC-3: GIVEN ensureAdminIncludesLoaded() lief bereits einmal durch
		//       WHEN ein zweites Mal aufgerufen
		//       THEN idempotent (kein zweiter require_once, kein zweiter
		//            function_exists-Check noetig).
		// -------------------------------------------------------------------
		public function test_ac3_ensure_admin_includes_loaded_is_idempotent(): void
		{
			// Erster Aufruf — setzt $includesLoaded = true.
			ImageSideloader::ensureAdminIncludesLoaded();

			$rc = new ReflectionClass( ImageSideloader::class );
			$this->assertTrue(
				$rc->getProperty( 'includesLoaded' )->getValue(),
				'AC-3: Nach erstem Aufruf muss includesLoaded=true sein.'
			);

			// Zweiter Aufruf — darf nicht werfen, darf keine Filesystem-IO
			// triggern. Wenn die Methode dennoch werfen sollte, scheitert
			// der Test.
			try {
				ImageSideloader::ensureAdminIncludesLoaded();
			} catch ( \Throwable $e ) {
				$this->fail(
					'AC-3: Zweiter Aufruf darf keinen Fehler werfen, warf jedoch: '
					. $e::class . ' — ' . $e->getMessage()
				);
			}

			// Dritter Aufruf — gleiche Garantie.
			ImageSideloader::ensureAdminIncludesLoaded();

			$this->assertTrue(
				$rc->getProperty( 'includesLoaded' )->getValue(),
				'AC-3: includesLoaded bleibt nach mehrfachem Aufruf stabil true.'
			);
		}

		// -------------------------------------------------------------------
		// AC-4 (Teil 1): GIVEN gueltiger URL + product_id, media_sideload_image
		//                liefert int
		//                WHEN sideload() aufgerufen
		//                THEN exakt diese int wird durchgereicht.
		// -------------------------------------------------------------------
		public function test_ac4_sideload_returns_attachment_id_on_success(): void
		{
			$GLOBALS['__test_msi_response'] = 4711;

			$sideloader = new ImageSideloader();
			$result     = $sideloader->sideload( 'https://cdn.example.test/preview.png', 99 );

			$this->assertSame(
				4711,
				$result,
				'AC-4: sideload() muss die int-Attachment-ID unveraendert durchreichen.'
			);
			$this->assertIsInt( $result, 'AC-4: Return-Type muss int sein, kein Casting.' );
		}

		/**
		 * AC-4 (Teil 2): media_sideload_image MUSS mit Return-Mode 'id' und
		 * exakt den uebergebenen URL/product_id-Argumenten aufgerufen werden.
		 */
		public function test_ac4_sideload_calls_media_sideload_image_with_id_return_mode(): void
		{
			$GLOBALS['__test_msi_response'] = 17;

			$sideloader = new ImageSideloader();
			$sideloader->sideload( 'https://cdn.example.test/img.png', 42 );

			$this->assertCount( 1, $GLOBALS['__test_msi_calls'], 'AC-4: media_sideload_image muss genau einmal aufgerufen werden.' );

			$call = $GLOBALS['__test_msi_calls'][0];
			$this->assertSame( 'https://cdn.example.test/img.png', $call['url'], 'AC-4: URL muss unveraendert weitergereicht werden.' );
			$this->assertSame( 42, $call['post_id'], 'AC-4: product_id muss als post_id-Parameter weitergereicht werden.' );
			$this->assertNull( $call['desc'], 'AC-4: description muss NULL sein (WP-Default-Verhalten).' );
			$this->assertSame(
				'id',
				$call['return_mode'],
				"AC-4: Return-Mode MUSS 'id' sein (Integer-Attachment-ID statt HTML)."
			);
		}

		/**
		 * AC-4 (Teil 3): sideload() ruft `ensureAdminIncludesLoaded()` BEVOR
		 * `media_sideload_image()`. Wir verifizieren, dass nach einem
		 * erfolgreichen sideload()-Lauf die Static-Property gesetzt ist —
		 * d. h. der Guard wurde durchlaufen.
		 */
		public function test_ac4_sideload_calls_ensure_admin_includes_loaded_first(): void
		{
			$GLOBALS['__test_msi_response'] = 1;

			$rc = new ReflectionClass( ImageSideloader::class );
			$this->assertFalse(
				$rc->getProperty( 'includesLoaded' )->getValue(),
				'Pre-condition: includesLoaded muss vor sideload() false sein.'
			);

			$sideloader = new ImageSideloader();
			$sideloader->sideload( 'https://cdn.example.test/img.png', 1 );

			$this->assertTrue(
				$rc->getProperty( 'includesLoaded' )->getValue(),
				'AC-4: sideload() muss ensureAdminIncludesLoaded() aufgerufen haben (Static-Property muss true sein).'
			);
			$this->assertCount(
				1,
				$GLOBALS['__test_msi_calls'],
				'AC-4: media_sideload_image MUSS aufgerufen worden sein (nach Includes-Load).'
			);
		}

		// -------------------------------------------------------------------
		// AC-5: GIVEN media_sideload_image liefert WP_Error
		//       WHEN sideload() aufgerufen
		//       THEN WP_Error wird unveraendert durchgereicht (Wert-Identitaet).
		// -------------------------------------------------------------------
		public function test_ac5_sideload_passes_wp_error_through(): void
		{
			$wpError                        = new WP_Error( 'http_request_failed', 'Connection refused', [ 'http_code' => 0 ] );
			$GLOBALS['__test_msi_response'] = $wpError;

			$sideloader = new ImageSideloader();
			$result     = $sideloader->sideload( 'https://cdn.example.test/broken.png', 7 );

			$this->assertSame(
				$wpError,
				$result,
				'AC-5: WP_Error muss als identische Instanz durchgereicht werden (kein Re-Wrap, kein Clone).'
			);
			$this->assertSame(
				'http_request_failed',
				$result->get_error_code(),
				'AC-5: WP_Error-Code muss erhalten bleiben.'
			);
			$this->assertSame(
				'Connection refused',
				$result->get_error_message(),
				'AC-5: WP_Error-Message muss erhalten bleiben.'
			);
			$this->assertSame(
				[ 'http_code' => 0 ],
				$result->get_error_data(),
				'AC-5: WP_Error-Data muss erhalten bleiben.'
			);
		}

		// -------------------------------------------------------------------
		// AC-6 (Teil 1): GIVEN leerer URL-String
		//                WHEN sideload() aufgerufen
		//                THEN WP_Error mit code spreadconnect_invalid_sideload_args.
		// -------------------------------------------------------------------
		public function test_ac6_sideload_rejects_empty_url(): void
		{
			$sideloader = new ImageSideloader();
			$result     = $sideloader->sideload( '', 42 );

			$this->assertInstanceOf( WP_Error::class, $result, 'AC-6: leerer URL muss WP_Error liefern.' );
			$this->assertSame(
				'spreadconnect_invalid_sideload_args',
				$result->get_error_code(),
				'AC-6: Error-Code muss exakt "spreadconnect_invalid_sideload_args" sein.'
			);
		}

		/**
		 * AC-6 (Teil 2): nicht-positive product_id wird abgewiesen.
		 */
		#[DataProvider( 'provideNonPositiveProductIds' )]
		public function test_ac6_sideload_rejects_non_positive_product_id( int $productId ): void
		{
			$sideloader = new ImageSideloader();
			$result     = $sideloader->sideload( 'https://cdn.example.test/img.png', $productId );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"AC-6: product_id={$productId} muss WP_Error liefern."
			);
			$this->assertSame(
				'spreadconnect_invalid_sideload_args',
				$result->get_error_code(),
				'AC-6: Error-Code muss exakt "spreadconnect_invalid_sideload_args" sein.'
			);
		}

		/**
		 * @return array<string, array{0: int}>
		 */
		public static function provideNonPositiveProductIds(): array
		{
			return [
				'zero'           => [ 0 ],
				'negative one'   => [ -1 ],
				'large negative' => [ -999 ],
			];
		}

		/**
		 * AC-6 (Teil 3): Pre-Check umgeht ensureAdminIncludesLoaded() UND
		 * media_sideload_image() — verhindert unnoetige Filesystem-IO.
		 */
		public function test_ac6_sideload_pre_check_skips_includes_and_api(): void
		{
			$this->resetIncludesLoadedFlag();

			$rc = new ReflectionClass( ImageSideloader::class );
			$this->assertFalse( $rc->getProperty( 'includesLoaded' )->getValue() );

			$sideloader = new ImageSideloader();

			// Beide Pre-Check-Branches: leerer URL + invalider product_id.
			$result1 = $sideloader->sideload( '', 42 );
			$result2 = $sideloader->sideload( 'https://cdn.example.test/img.png', 0 );
			$result3 = $sideloader->sideload( '', 0 );

			$this->assertInstanceOf( WP_Error::class, $result1 );
			$this->assertInstanceOf( WP_Error::class, $result2 );
			$this->assertInstanceOf( WP_Error::class, $result3 );

			$this->assertFalse(
				$rc->getProperty( 'includesLoaded' )->getValue(),
				'AC-6: Pre-Check MUSS ensureAdminIncludesLoaded() umgehen — '
				. 'Static-Property darf nicht mutiert werden.'
			);

			$this->assertSame(
				[],
				$GLOBALS['__test_msi_calls'],
				'AC-6: Pre-Check MUSS media_sideload_image() umgehen — kein einziger Aufruf erlaubt.'
			);
		}

		/**
		 * AC-6 (Teil 4): WP_Error-Data enthaelt die ungueltigen Argumente
		 * fuer Caller-Diagnose (siehe ImageSideloader::sideload Inline-Doc).
		 */
		public function test_ac6_sideload_pre_check_includes_invalid_args_in_error_data(): void
		{
			$sideloader = new ImageSideloader();
			$result     = $sideloader->sideload( '', -5 );

			$this->assertInstanceOf( WP_Error::class, $result );

			$data = $result->get_error_data();
			$this->assertIsArray( $data, 'AC-6: WP_Error-Data muss array fuer Diagnose enthalten.' );
			$this->assertArrayHasKey( 'url', $data, 'AC-6: Error-Data muss die uebergebene URL enthalten.' );
			$this->assertArrayHasKey( 'product_id', $data, 'AC-6: Error-Data muss die uebergebene product_id enthalten.' );
			$this->assertSame( '', $data['url'] );
			$this->assertSame( -5, $data['product_id'] );
		}
	}
}
