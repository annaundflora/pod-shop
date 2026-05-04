<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Slice 01 — Cleanup v1-Plugin (Greenfield-Reset)
 *
 * Filesystem-Asserts gegen die Acceptance Criteria der Slice-Spec
 * `slice-01-cleanup-v1.md`. Slice 01 ist rein subtraktiv: das v1-Plugin
 * sowie der v1-Test-Stub MUESSEN entfernt sein, das PSR-4-Mapping in der
 * Root-`composer.json` MUSS jedoch unveraendert bleiben (Slice 02 baut
 * das Zielverzeichnis neu auf).
 *
 * AC-4 (composer test exit code) und AC-5 (git status diff) werden vom
 * Orchestrator/Compliance-Gate gemessen und sind hier NICHT abgedeckt.
 */
final class CleanupV1Test extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------
    // AC-1: GIVEN Repository mit v1-Plugin unter wordpress/plugins/spreadconnect-pod/
    //       WHEN Slice 01 abgeschlossen
    //       THEN existiert das gesamte Verzeichnis nicht mehr.
    // -------------------------------------------------------------------
    public function test_v1_plugin_directory_does_not_exist(): void
    {
        $pluginDir = self::repoRoot() . '/wordpress/plugins/spreadconnect-pod';

        $this->assertDirectoryDoesNotExist(
            $pluginDir,
            sprintf(
                'AC-1: v1-Plugin-Verzeichnis "%s" muss vollstaendig entfernt sein. '
                . 'Slice 02 erstellt das Verzeichnis und seine composer.json neu.',
                $pluginDir
            )
        );

        // Zusaetzlich: file_exists() faengt auch dangling Symlinks ab,
        // die assertDirectoryDoesNotExist() unter Umstaenden nicht erkennt.
        $this->assertFalse(
            file_exists($pluginDir),
            sprintf(
                'AC-1: Kein Eintrag (auch kein Symlink/File) darf unter "%s" existieren.',
                $pluginDir
            )
        );

        // Sanity-Check: Andere Plugins duerfen NICHT geloescht werden
        // (Constraint: "Slice 01 entfernt keine anderen Plugins").
        $pluginsRoot = self::repoRoot() . '/wordpress/plugins';
        $this->assertDirectoryExists(
            $pluginsRoot,
            'AC-1 / Constraint: Das uebergeordnete Plugins-Verzeichnis muss erhalten bleiben.'
        );
    }

    // -------------------------------------------------------------------
    // AC-2: GIVEN v1-Test-Stub tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php
    //       WHEN Slice 01 abgeschlossen
    //       THEN ist diese Datei geloescht.
    // -------------------------------------------------------------------
    public function test_v1_slice_test_stub_does_not_exist(): void
    {
        $stub = self::repoRoot()
            . '/tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php';

        $this->assertFileDoesNotExist(
            $stub,
            sprintf(
                'AC-2: v1-Test-Stub "%s" muss geloescht sein.',
                $stub
            )
        );
    }

    // -------------------------------------------------------------------
    // AC-2 (Teil 2): tests/slices/pod-shop-mvp/ enthaelt KEINE v1-bezogenen
    // Test-Dateien mehr. Erlaubt sind:
    //   - die soeben erstellte Slice-01-Test-Datei (`slice-01-cleanup-v1.php`)
    //   - Vitest-Files (`*.test.ts`) der parallelen Frontend-Slices
    // Nicht erlaubt: irgendein PHP-File, dessen Name auf v1-Slice-Topics
    // (z. B. `slice-05-pod-anbindung-spreadconnect`) verweist.
    // -------------------------------------------------------------------
    public function test_no_v1_remnant_test_files_remain(): void
    {
        $sliceDir = self::repoRoot() . '/tests/slices/pod-shop-mvp';
        $this->assertDirectoryExists(
            $sliceDir,
            'AC-2: Slice-Test-Verzeichnis muss existieren (nur v1-Files entfernt, nicht das Verzeichnis).'
        );

        $entries = array_values(array_filter(
            scandir($sliceDir) ?: [],
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
        ));

        // Bekannte v1-Test-Files, die auf KEINEN Fall mehr existieren duerfen.
        $forbiddenV1Files = [
            'slice-05-pod-anbindung-spreadconnect.php',
        ];

        foreach ($forbiddenV1Files as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $entries,
                sprintf(
                    'AC-2: v1-Test-File "%s" darf nicht mehr in "%s" existieren.',
                    $forbidden,
                    $sliceDir
                )
            );
        }

        // Defensive Pruefung: KEIN PHP-File im Slice-Verzeichnis darf den
        // String "pod-anbindung-spreadconnect" tragen — das war der einzige
        // v1-Slice-Topic-Identifier.
        $phpFiles = array_filter(
            $entries,
            static fn(string $entry): bool => str_ends_with($entry, '.php')
        );

        foreach ($phpFiles as $phpFile) {
            $this->assertStringNotContainsString(
                'pod-anbindung-spreadconnect',
                $phpFile,
                sprintf(
                    'AC-2: PHP-Test-File "%s" referenziert v1-Slice-Topic. '
                    . 'Slice 01 ist anti-Reuse — alle v1-Test-Dateien werden verworfen.',
                    $phpFile
                )
            );
        }
    }

    // -------------------------------------------------------------------
    // AC-3: GIVEN Root-composer.json referenziert PSR-4-Mapping
    //         "SpreadconnectPod\\" -> "wordpress/plugins/spreadconnect-pod/includes/"
    //       WHEN Slice 01 abgeschlossen
    //       THEN bleibt das PSR-4-Mapping in der Root-composer.json unveraendert.
    // -------------------------------------------------------------------
    public function test_root_composer_psr4_mapping_preserved(): void
    {
        $composerPath = self::repoRoot() . '/composer.json';

        $this->assertFileExists(
            $composerPath,
            'AC-3: Root-composer.json muss existieren (Slice 01 ist subtraktiv, modifiziert composer.json nicht).'
        );

        $raw = file_get_contents($composerPath);
        $this->assertNotFalse($raw, 'AC-3: Root-composer.json muss lesbar sein.');

        /** @var array<string, mixed>|null $config */
        $config = json_decode($raw, true);
        $this->assertIsArray(
            $config,
            'AC-3: Root-composer.json muss valides JSON enthalten.'
        );

        // Strukturelle Pruefung: autoload.psr-4 existiert und enthaelt das
        // exakte v1-Namespace-Mapping. Slice 02 baut wordpress/plugins/spreadconnect-pod/
        // neu auf — daher MUSS das Mapping erhalten bleiben.
        $this->assertArrayHasKey(
            'autoload',
            $config,
            'AC-3: composer.json muss eine "autoload"-Section enthalten.'
        );
        $this->assertIsArray($config['autoload']);
        $this->assertArrayHasKey(
            'psr-4',
            $config['autoload'],
            'AC-3: composer.json autoload-Section muss "psr-4"-Mapping enthalten.'
        );
        $this->assertIsArray($config['autoload']['psr-4']);

        $psr4 = $config['autoload']['psr-4'];

        $this->assertArrayHasKey(
            'SpreadconnectPod\\',
            $psr4,
            'AC-3: PSR-4-Key "SpreadconnectPod\\\\" muss in autoload.psr-4 erhalten bleiben '
            . '(Slice 02 baut das Zielverzeichnis neu auf).'
        );

        $this->assertSame(
            'wordpress/plugins/spreadconnect-pod/includes/',
            $psr4['SpreadconnectPod\\'],
            'AC-3: PSR-4-Mapping fuer "SpreadconnectPod\\\\" muss exakt auf '
            . '"wordpress/plugins/spreadconnect-pod/includes/" zeigen — unveraendert gegenueber v1.'
        );
    }
}
