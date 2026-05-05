<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Slice 04 — Schema + dbDelta-Activator
 *
 * Acceptance Tests gegen die Slice-Spec `slice-04-schema-dbdelta.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer `dbDelta()` (Spy auf SQL-Strings).
 *   - $wpdb-Stub als globales Object mit prefix='wp_',
 *     get_charset_collate(), query()-Spy.
 *   - ABSPATH-Konstante als realistischer existierender Pfad
 *     (Repo-Wurzel mit `wp-admin/includes/upgrade.php`-Stub).
 *
 * Strategie:
 *   - AC-1, AC-6: Dynamic — Schema::install() aufrufen, dbDelta-Spy + require_once-Effekt pruefen.
 *   - AC-2: Statische Source-Analyse — preg_match auf Spalten/Index-Namen.
 *   - AC-3: Idempotenz — Schema::install() zweimal aufrufen (3+3=6 dbDelta-Calls, kein Throw).
 *           PLUS Source-Analyse: KEIN `DROP TABLE` in install().
 *   - AC-4: Dynamic — Schema::uninstall() aufrufen, $wpdb->query-Spy auf 3 DROP TABLE pruefen.
 *           PLUS uninstall.php ruft Schema::uninstall() auf.
 *   - AC-5: Dynamic — Plugin::init() registriert register_activation_hook (Spy via Brain\Monkey).
 *   - AC-7: Statische Source-Analyse — Schema::uninstall() enthaelt KEIN
 *           `defined('WP_UNINSTALL_PLUGIN')`.
 */
final class Slice04SchemaDbdeltaTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur Schema.php.
     */
    private static function schemaClassFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Schema.php';
    }

    /**
     * Absoluter Pfad zur Plugin.php.
     */
    private static function pluginClassFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php';
    }

    /**
     * Absoluter Pfad zur Plugin-Hauptdatei.
     */
    private static function pluginMainFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
    }

    /**
     * Absoluter Pfad zur uninstall.php.
     */
    private static function uninstallFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/uninstall.php';
    }

    /**
     * Source-Inhalt der Schema.php (cached pro Test).
     */
    private static function schemaSource(): string
    {
        $file = self::schemaClassFile();
        self::assertFileExists($file, 'Schema.php muss existieren.');
        $contents = (string) file_get_contents($file);
        self::assertNotSame('', $contents, 'Schema.php darf nicht leer sein.');
        return $contents;
    }

    /**
     * Reset Plugin-internen State (Idempotenz-Guard) zwischen Tests.
     */
    private static function resetPluginState(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        if (! class_exists($fqcn)) {
            return;
        }

        $reflection = new ReflectionClass($fqcn);
        if ($reflection->hasProperty('initialized')) {
            $prop = $reflection->getProperty('initialized');
            $prop->setValue(null, false);
        }
        if ($reflection->hasProperty('pluginFile')) {
            $prop = $reflection->getProperty('pluginFile');
            $prop->setValue(null, '');
        }
    }

    /**
     * Erzeugt ein $wpdb-Stub-Object mit prefix, get_charset_collate(),
     * und einem query()-Spy-Array.
     */
    private static function makeWpdbStub(string $prefix = 'wp_'): object
    {
        return new class($prefix) {
            public string $prefix;
            /** @var array<int, string> */
            public array $queryCalls = [];

            public function __construct(string $prefix)
            {
                $this->prefix = $prefix;
            }

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }

            public function query(string $sql): int
            {
                $this->queryCalls[] = $sql;
                return 1;
            }
        };
    }

    /**
     * Stellt sicher, dass ABSPATH und die `wp-admin/includes/upgrade.php`-
     * Datei verfuegbar sind, damit `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
     * im Schema::install()-Aufruf nicht in einen "file not found"-Warning laeuft.
     *
     * **Wichtig**: ABSPATH kann von einem vorherigen Test in dieser PHPUnit-Run-Session
     * (z. B. Slice21ImageSideloaderTest) bereits auf `sys_get_temp_dir() . '/'`
     * gesetzt worden sein — define() ist einmalig und kann nicht ueberschrieben
     * werden. Daher schreiben wir den Stub auf den AKTUELLEN ABSPATH-Wert
     * (sofern bereits definiert) oder auf einen frischen temp-Ordner.
     *
     * Rueckgabe: Pfad des temporaeren ABSPATH-Roots (mit Trailing-Slash),
     * der per `define('ABSPATH', ...)` gesetzt werden kann.
     */
    private static function ensureAbspathStub(): string
    {
        // Wenn ABSPATH bereits definiert ist (z. B. von Slice21ImageSideloaderTest),
        // verwenden wir diesen Pfad — sonst legen wir einen neuen an.
        if (defined('ABSPATH')) {
            $root = (string) ABSPATH;
            // Trailing-Slash sicherstellen.
            if (! str_ends_with($root, '/')) {
                $root .= '/';
            }
        } else {
            $root = sys_get_temp_dir() . '/';
        }

        $upgradeDir  = $root . 'wp-admin/includes/';
        $upgradeFile = $upgradeDir . 'upgrade.php';

        if (! is_dir($upgradeDir)) {
            @mkdir($upgradeDir, 0777, true);
        }

        if (! file_exists($upgradeFile)) {
            // Datei kann leer sein — Brain\Monkey definiert dbDelta() als
            // Stub im PHPUnit-Process, bevor Schema::install() laeuft.
            file_put_contents($upgradeFile, "<?php\n// Test stub for ABSPATH/wp-admin/includes/upgrade.php\n");
        }

        return $root;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::resetPluginState();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        self::resetPluginState();
        // $wpdb global zwischen Tests cleanen.
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ===================================================================
    // AC-1: GIVEN ein neu installiertes WordPress mit aktivem $wpdb-Prefix
    //       WHEN Schema::install() aufgerufen wird
    //       THEN ruft sie require_once ABSPATH . 'wp-admin/includes/upgrade.php'
    //            und delegiert an dbDelta() mit GENAU 3 CREATE-TABLE-Statements:
    //              {prefix}spreadconnect_failed_ops
    //              {prefix}spreadconnect_webhook_log
    //              {prefix}spreadconnect_sync_history
    //            Charset-Collate ist an jedem CREATE angefuegt.
    // ===================================================================

    /**
     * AC-1: Schema::install() ruft dbDelta() mit 3 CREATE-Statements auf —
     * fuer failed_ops, webhook_log, sync_history (in beliebiger Reihenfolge).
     */
    public function test_install_calls_dbdelta_with_three_create_statements(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';
        $this->assertTrue(class_exists($fqcn), 'AC-1: Bootstrap\\Schema muss autoloadbar sein.');

        // ABSPATH konstantenseitig verfuegbar machen + Stub-Datei legen.
        $abspathRoot = self::ensureAbspathStub();
        if (! defined('ABSPATH')) {
            define('ABSPATH', $abspathRoot);
        }

        // $wpdb-Stub.
        $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');

        // dbDelta-Spy: jede SQL aufzeichnen.
        $sqlCalls = [];
        Monkey\Functions\when('dbDelta')->alias(function ($sql) use (&$sqlCalls) {
            $sqlCalls[] = (string) $sql;
            return [];
        });

        // Aufruf.
        $fqcn::install();

        // AC-1: GENAU 3 dbDelta-Calls.
        $this->assertCount(
            3,
            $sqlCalls,
            'AC-1: Schema::install() MUSS dbDelta() genau 3 Mal aufrufen — '
            . 'einmal pro Custom-Table (failed_ops, webhook_log, sync_history).'
        );

        // Jede Tabelle muss EINMAL via CREATE TABLE referenziert sein.
        $expectedTables = [
            'wp_spreadconnect_failed_ops',
            'wp_spreadconnect_webhook_log',
            'wp_spreadconnect_sync_history',
        ];

        foreach ($expectedTables as $table) {
            $hits = 0;
            foreach ($sqlCalls as $sql) {
                // Toleranter Match: "CREATE TABLE" gefolgt vom voll-qualifizierten Tabellennamen.
                if (preg_match('/CREATE\s+TABLE\s+' . preg_quote($table, '/') . '\b/i', $sql) === 1) {
                    $hits++;
                }
            }
            $this->assertSame(
                1,
                $hits,
                sprintf(
                    'AC-1: dbDelta() muss GENAU EINEN CREATE TABLE-Aufruf fuer "%s" erhalten '
                    . '(gefunden: %d).',
                    $table,
                    $hits
                )
            );
        }
    }

    /**
     * AC-1: Charset-Collate-Suffix wird an jedes CREATE-Statement angehaengt.
     */
    public function test_install_appends_charset_collate_to_each_create(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';

        $abspathRoot = self::ensureAbspathStub();
        if (! defined('ABSPATH')) {
            define('ABSPATH', $abspathRoot);
        }

        $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');

        $sqlCalls = [];
        Monkey\Functions\when('dbDelta')->alias(function ($sql) use (&$sqlCalls) {
            $sqlCalls[] = (string) $sql;
            return [];
        });

        $fqcn::install();

        $this->assertCount(3, $sqlCalls, 'AC-1: 3 dbDelta-Aufrufe erwartet.');

        // get_charset_collate() liefert den charsetCollate-String — dieser
        // muss an jedes CREATE-Statement angehaengt sein.
        $charsetCollate = $GLOBALS['wpdb']->get_charset_collate();

        foreach ($sqlCalls as $i => $sql) {
            $this->assertStringContainsString(
                $charsetCollate,
                $sql,
                sprintf(
                    'AC-1: SQL #%d muss den $wpdb->get_charset_collate()-Suffix '
                    . '("%s") enthalten — sonst landet die Tabelle in einem '
                    . 'falschen Default-Charset.',
                    $i + 1,
                    $charsetCollate
                )
            );
        }
    }

    /**
     * AC-1 (Constraint): Schema verwendet $wpdb->prefix (kein hardcoded "wp_").
     *
     * Statische Source-Analyse: Pruefe, dass die SQL-Strings via
     * `{$wpdb->prefix}` (oder via einer Variable die aus $wpdb->prefix
     * gespeist wird) interpoliert werden — niemals als Literal "wp_".
     */
    public function test_install_uses_wpdb_prefix_not_hardcoded_wp_underscore(): void
    {
        $source = self::schemaSource();

        // Verbotenes Literal: ein hardcoded "wp_spreadconnect_*" innerhalb eines String-Literals
        // (statt dynamisch ueber {$wpdb->prefix} oder einer Variable).
        // Wir suchen nach `'wp_spreadconnect_` oder `"wp_spreadconnect_` — beides waere ein Bug.
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]wp_spreadconnect_/',
            $source,
            'AC-1 / Constraint: Schema.php darf KEIN hardcoded "wp_"-Praefix in einem '
            . 'Tabellennamen-String enthalten — Praefix MUSS dynamisch ueber $wpdb->prefix '
            . 'kommen (Multi-Site-Kompatibilitaet, custom prefixes).'
        );

        // Positiv: Source MUSS auf $wpdb->prefix bzw. $prefix verweisen.
        $this->assertMatchesRegularExpression(
            '/\$wpdb\s*->\s*prefix/',
            $source,
            'AC-1: Schema.php MUSS auf $wpdb->prefix zugreifen, um die '
            . 'Tabellennamen dynamisch zu bauen.'
        );
    }

    /**
     * AC-1 (zusaetzlich): install() ruft tatsaechlich `global $wpdb;`.
     */
    public function test_install_declares_global_wpdb(): void
    {
        $source = self::schemaSource();

        // Wir suchen nach `global $wpdb;` innerhalb der install()-Methode.
        // Da der Source-Inhalt zwei Methoden enthaelt (install + uninstall),
        // pruefen wir mit etwas Toleranz: `global` darf auch in uninstall()
        // erscheinen, aber install() muss eine eigene Deklaration haben.
        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all('/\bglobal\s+\$wpdb\s*;/', $source),
            'AC-1: Schema.php muss "global $wpdb;" deklarieren, damit das '
            . 'globale $wpdb-Object zugaenglich ist (install() und/oder uninstall()).'
        );
    }

    // ===================================================================
    // AC-2: GIVEN die drei generierten CREATE-Statements
    //       WHEN sie an dbDelta() uebergeben werden
    //       THEN entsprechen Spalten-Definitionen, Constraints und Indexes
    //            exakt der architecture.md (Schema Details). Pflicht-Indexes:
    //              failed_ops:    idx_state_op_type, idx_related_entity, idx_created_at
    //              webhook_log:   uniq_event_id (UNIQUE), idx_received_at,
    //                             idx_related_entity, idx_processing_status
    //              sync_history:  idx_state_started_at, idx_started_at
    // ===================================================================

    /**
     * AC-2: failed_ops-CREATE enthaelt alle Pflicht-Indexes.
     */
    public function test_failed_ops_create_contains_required_indexes(): void
    {
        $source = self::schemaSource();

        // CREATE TABLE-Statement: das Tabellennamen-Token wird via PHP-Variable
        // interpoliert (z. B. `{$failedOpsTable}`). Wir akzeptieren jedes
        // Variable-Token gefolgt vom literal Tabellennamen-Suffix in einer
        // zweiten Pruefung.
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+\{?\$\w+\}?/i',
            $source,
            'AC-2: Schema.php MUSS mindestens ein CREATE TABLE-Statement mit '
            . 'dynamisch interpoliertem Tabellennamen enthalten.'
        );

        // Spezifisch: das Tabellennamen-Suffix `spreadconnect_failed_ops`
        // erscheint im Source (entweder im Variable-Definition-Statement
        // oder direkt im SQL-Heredoc).
        $this->assertStringContainsString(
            'spreadconnect_failed_ops',
            $source,
            'AC-2: Schema.php MUSS die Tabelle spreadconnect_failed_ops anlegen.'
        );

        // Pflicht-Indexes:
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_state_op_type\s*\(\s*state\s*,\s*op_type\s*\)/i',
            $source,
            'AC-2: failed_ops MUSS Index "idx_state_op_type (state, op_type)" haben.'
        );
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_related_entity\s*\(\s*related_entity_type\s*,\s*related_entity_id\s*\)/i',
            $source,
            'AC-2: failed_ops MUSS Index "idx_related_entity (related_entity_type, related_entity_id)" haben.'
        );
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_created_at\s*\(\s*created_at\s*\)/i',
            $source,
            'AC-2: failed_ops MUSS Index "idx_created_at (created_at)" haben.'
        );
    }

    /**
     * AC-2: webhook_log-CREATE enthaelt UNIQUE uniq_event_id + 3 normale Indexes.
     */
    public function test_webhook_log_create_contains_unique_and_indexes(): void
    {
        $source = self::schemaSource();

        $this->assertStringContainsString(
            'spreadconnect_webhook_log',
            $source,
            'AC-2: Schema.php MUSS die Tabelle spreadconnect_webhook_log anlegen.'
        );

        // UNIQUE auf event_id.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+uniq_event_id\s*\(\s*event_id\s*\)/i',
            $source,
            'AC-2: webhook_log MUSS UNIQUE KEY "uniq_event_id (event_id)" haben — '
            . 'dies stuetzt die Idempotenz-Pruefung in slice-16 (EventIdHasher).'
        );

        // idx_received_at
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_received_at\s*\(\s*received_at\s*\)/i',
            $source,
            'AC-2: webhook_log MUSS Index "idx_received_at (received_at)" haben.'
        );

        // idx_related_entity (3 Spalten!)
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_related_entity\s*\(\s*related_entity_type\s*,\s*related_entity_id\s*,\s*received_at\s*\)/i',
            $source,
            'AC-2: webhook_log MUSS Index "idx_related_entity (related_entity_type, related_entity_id, received_at)" haben.'
        );

        // idx_processing_status
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_processing_status\s*\(\s*processing_status\s*\)/i',
            $source,
            'AC-2: webhook_log MUSS Index "idx_processing_status (processing_status)" haben.'
        );
    }

    /**
     * AC-2: sync_history-CREATE enthaelt idx_state_started_at + idx_started_at.
     */
    public function test_sync_history_create_contains_required_indexes(): void
    {
        $source = self::schemaSource();

        $this->assertStringContainsString(
            'spreadconnect_sync_history',
            $source,
            'AC-2: Schema.php MUSS die Tabelle spreadconnect_sync_history anlegen.'
        );

        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_state_started_at\s*\(\s*state\s*,\s*started_at\s*\)/i',
            $source,
            'AC-2: sync_history MUSS Index "idx_state_started_at (state, started_at)" haben.'
        );
        $this->assertMatchesRegularExpression(
            '/KEY\s+idx_started_at\s*\(\s*started_at\s*\)/i',
            $source,
            'AC-2: sync_history MUSS Index "idx_started_at (started_at)" haben.'
        );
    }

    /**
     * AC-2: Spalten-Typen entsprechen architecture.md
     * (BIGINT UNSIGNED PK, CHAR(64) event_id, DATETIME, LONGTEXT etc.).
     */
    public function test_column_types_match_architecture_schema(): void
    {
        $source = self::schemaSource();

        // PRIMARY KEY auf id (alle 3 Tabellen). dbDelta-Konvention: ZWEI Spaces zw. "PRIMARY KEY" und "(id)".
        $primaryKeyCount = preg_match_all('/PRIMARY\s+KEY\s+\s*\(\s*id\s*\)/i', $source);
        $this->assertGreaterThanOrEqual(
            3,
            $primaryKeyCount,
            'AC-2 / dbDelta-Konvention: Alle 3 Tabellen MUSSEN "PRIMARY KEY  (id)" '
            . 'haben (id ist die SK; Doppel-Space-Konvention wird in eigenem Test geprueft).'
        );

        // event_id ist CHAR(64) (sha256 hex).
        $this->assertMatchesRegularExpression(
            '/event_id\s+CHAR\s*\(\s*64\s*\)/i',
            $source,
            'AC-2: webhook_log.event_id MUSS CHAR(64) sein — sha256 hex digest = 64 chars.'
        );

        // BIGINT UNSIGNED AUTO_INCREMENT auf id-Spalte.
        $bigintCount = preg_match_all(
            '/\bid\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i',
            $source
        );
        $this->assertGreaterThanOrEqual(
            3,
            $bigintCount,
            'AC-2: Alle 3 Tabellen MUSSEN "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT" als Primary Key haben.'
        );

        // payload LONGTEXT (failed_ops + webhook_log).
        $payloadCount = preg_match_all('/\bpayload\s+LONGTEXT\b/i', $source);
        $this->assertGreaterThanOrEqual(
            2,
            $payloadCount,
            'AC-2: failed_ops und webhook_log MUSSEN "payload LONGTEXT" haben '
            . '(SC-Payloads sind unbeschraenkt; LONGTEXT = 4GB).'
        );

        // sync_history.details LONGTEXT (JSON-Container).
        $this->assertMatchesRegularExpression(
            '/\bdetails\s+LONGTEXT\b/i',
            $source,
            'AC-2: sync_history.details MUSS LONGTEXT sein (JSON-Container).'
        );

        // started_at DATETIME (sync_history).
        $this->assertMatchesRegularExpression(
            '/\bstarted_at\s+DATETIME\b/i',
            $source,
            'AC-2: sync_history.started_at MUSS DATETIME sein.'
        );

        // created_at DATETIME (failed_ops).
        $this->assertMatchesRegularExpression(
            '/\bcreated_at\s+DATETIME\b/i',
            $source,
            'AC-2: failed_ops.created_at MUSS DATETIME sein.'
        );

        // received_at DATETIME (webhook_log).
        $this->assertMatchesRegularExpression(
            '/\breceived_at\s+DATETIME\b/i',
            $source,
            'AC-2: webhook_log.received_at MUSS DATETIME sein.'
        );

        // state-Default-Werte: failed_ops.state DEFAULT 'unresolved', sync_history.state DEFAULT 'pending'.
        $this->assertMatchesRegularExpression(
            "/\\bstate\\s+VARCHAR\\(\\s*16\\s*\\)\\s+NOT\\s+NULL\\s+DEFAULT\\s+'unresolved'/i",
            $source,
            "AC-2: failed_ops.state MUSS VARCHAR(16) NOT NULL DEFAULT 'unresolved' sein."
        );
        $this->assertMatchesRegularExpression(
            "/\\bstate\\s+VARCHAR\\(\\s*16\\s*\\)\\s+NOT\\s+NULL\\s+DEFAULT\\s+'pending'/i",
            $source,
            "AC-2: sync_history.state MUSS VARCHAR(16) NOT NULL DEFAULT 'pending' sein."
        );

        // webhook_log.processing_status DEFAULT 'pending'.
        $this->assertMatchesRegularExpression(
            "/\\bprocessing_status\\s+VARCHAR\\(\\s*16\\s*\\)\\s+NOT\\s+NULL\\s+DEFAULT\\s+'pending'/i",
            $source,
            "AC-2: webhook_log.processing_status MUSS VARCHAR(16) NOT NULL DEFAULT 'pending' sein."
        );
    }

    /**
     * AC-2 (Constraint): `trigger` ist ein MySQL-Reserved-Word und MUSS
     * mit Backticks escaped sein.
     */
    public function test_sync_history_trigger_column_uses_backtick_escape(): void
    {
        $source = self::schemaSource();

        $this->assertMatchesRegularExpression(
            '/`trigger`\s+VARCHAR/i',
            $source,
            'AC-2 / Constraint: sync_history.trigger MUSS mit Backticks escaped werden '
            . '("`trigger`"), weil "TRIGGER" ein MySQL-Reserved-Word ist.'
        );
    }

    /**
     * AC-2 (Constraint): dbDelta-Konvention — DOPPEL-Space zwischen
     * "PRIMARY KEY" und der Spalten-Klammer.
     *
     * https://developer.wordpress.org/reference/functions/dbdelta/ —
     * dbDelta vergleicht Strings naiv und stolpert ohne den 2-Space-Hack
     * bei Re-Diff-Versuchen.
     */
    public function test_dbdelta_formatting_double_space_after_primary_key(): void
    {
        $source = self::schemaSource();

        // Wir muessen den ZWEI-Space-Marker explizit pruefen: "PRIMARY KEY  (".
        // Der Test waere zufrieden mit `PRIMARY KEY (` — das wuerde dbDelta-Diffs aber brechen.
        $doubleSpaceHits = preg_match_all('/PRIMARY KEY  \(/', $source);
        $this->assertGreaterThanOrEqual(
            3,
            $doubleSpaceHits,
            'AC-2 / dbDelta-Konvention: Jede der 3 Tabellen MUSS exakt '
            . '"PRIMARY KEY  (" mit ZWEI Spaces nach "KEY" verwenden, '
            . 'sonst schlaegt das dbDelta-Diffing bei Folge-Aktivierungen fehl.'
        );
    }

    // ===================================================================
    // AC-3: GIVEN Schema::install() bereits einmal aufgerufen
    //       WHEN ein zweites Mal aufgerufen
    //       THEN idempotent — dbDelta erneut mit identischer SQL,
    //            kein Throw, kein DROP.
    // ===================================================================

    /**
     * AC-3: Wiederholter Aufruf von Schema::install() ist idempotent
     * — 2x install() = 2x 3 dbDelta-Aufrufe mit IDENTISCHER SQL.
     */
    public function test_install_is_idempotent_on_repeated_calls(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';

        $abspathRoot = self::ensureAbspathStub();
        if (! defined('ABSPATH')) {
            define('ABSPATH', $abspathRoot);
        }

        $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');

        $firstRun = [];
        $secondRun = [];
        $callCount = 0;

        Monkey\Functions\when('dbDelta')->alias(function ($sql) use (&$firstRun, &$secondRun, &$callCount) {
            $callCount++;
            if ($callCount <= 3) {
                $firstRun[] = (string) $sql;
            } else {
                $secondRun[] = (string) $sql;
            }
            return [];
        });

        // 1. Aufruf — kein Throw erlaubt.
        try {
            $fqcn::install();
        } catch (\Throwable $e) {
            $this->fail('AC-3: Erster install()-Aufruf darf nicht werfen: ' . $e::class . ' — ' . $e->getMessage());
        }

        // 2. Aufruf — kein Throw erlaubt.
        try {
            $fqcn::install();
        } catch (\Throwable $e) {
            $this->fail('AC-3: Zweiter install()-Aufruf (Idempotenz) darf nicht werfen: ' . $e::class . ' — ' . $e->getMessage());
        }

        $this->assertCount(3, $firstRun, 'AC-3: 1. install() ergibt 3 dbDelta-Aufrufe.');
        $this->assertCount(3, $secondRun, 'AC-3: 2. install() ergibt erneut 3 dbDelta-Aufrufe.');

        // SQL-Strings beider Runs MUSSEN identisch sein — Schema::install() darf
        // pro Aufruf KEINE neue SQL generieren (z. B. mit Timestamps).
        $this->assertSame(
            $firstRun,
            $secondRun,
            'AC-3: Idempotenz — 2. install()-Aufruf MUSS identische SQL-Strings an dbDelta '
            . 'liefern wie der 1. Aufruf (kein Drift, keine zeitabhaengigen Werte).'
        );
    }

    /**
     * AC-3 (Constraint): install() enthaelt KEIN `DROP TABLE`.
     *
     * dbDelta ist additive-only — wenn install() selbst DROP machen wuerde,
     * waere das ein Daten-Verlust-Bug bei Re-Activation.
     */
    public function test_install_source_contains_no_drop_table(): void
    {
        $source = self::schemaSource();

        // Wir suchen nach dem Body von `function install()`. Wenn der ganze File
        // ein DROP TABLE enthaelt, faellt es bereits hier auf — wir koennen
        // nicht zuverlaessig zwischen install/uninstall trennen ohne Tokenizer.
        // Stattdessen: pruefe, dass DROP TABLE NUR im uninstall()-Body vorkommt
        // (siehe AC-4-Test). Hier: der Source darf zwischen `function install()`
        // und der schliessenden Methoden-Klammer KEIN `DROP TABLE` haben.
        $installBody = self::extractMethodBody($source, 'install');
        $this->assertNotNull(
            $installBody,
            'AC-3: install()-Methoden-Body muss extrahierbar sein.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bDROP\s+TABLE\b/i',
            $installBody,
            'AC-3 / Constraint: Schema::install() darf KEIN "DROP TABLE" enthalten — '
            . 'dbDelta ist additive-only; ein DROP wuerde Daten loeschen bei jeder Re-Aktivierung.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bTRUNCATE\s+TABLE\b/i',
            $installBody,
            'AC-3 / Constraint: Schema::install() darf KEIN "TRUNCATE TABLE" enthalten — '
            . 'auch das wuerde Daten loeschen bei Re-Aktivierung.'
        );
    }

    /**
     * Extrahiert den Methoden-Body als Source-String.
     *
     * Greift mit Tokenizer, um Klammer-Balance korrekt aufzuloesen
     * (Strings mit `}` als Inhalt zu beachten).
     */
    private static function extractMethodBody(string $source, string $methodName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (! is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            // Naechstes T_STRING ist der Methodenname.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_STRING) {
                    if ($next[1] === $methodName) {
                        // Body beginnt beim naechsten "{".
                        for ($k = $j + 1; $k < $count; $k++) {
                            if ($tokens[$k] === '{') {
                                // Body endet bei matchender "}".
                                $depth = 1;
                                $body = '';
                                for ($l = $k + 1; $l < $count; $l++) {
                                    $t = $tokens[$l];
                                    if ($t === '{') {
                                        $depth++;
                                        $body .= '{';
                                    } elseif ($t === '}') {
                                        $depth--;
                                        if ($depth === 0) {
                                            return $body;
                                        }
                                        $body .= '}';
                                    } else {
                                        $body .= is_array($t) ? $t[1] : $t;
                                    }
                                }
                                return null;
                            }
                        }
                    }
                    break;
                }
            }
        }

        return null;
    }

    // ===================================================================
    // AC-4: GIVEN uninstall.php wurde um Schema::uninstall() erweitert
    //       WHEN WP die Datei mit gesetztem WP_UNINSTALL_PLUGIN laedt
    //       THEN ruft sie Schema::uninstall(), das per $wpdb->query()
    //            DREI "DROP TABLE IF EXISTS"-Statements absetzt.
    // ===================================================================

    /**
     * AC-4: Schema::uninstall() ruft $wpdb->query() 3-mal mit
     * "DROP TABLE IF EXISTS {prefix}spreadconnect_*"-Statements.
     */
    public function test_uninstall_drops_all_three_tables(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';
        $this->assertTrue(class_exists($fqcn), 'AC-4: Bootstrap\\Schema muss autoloadbar sein.');

        $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');

        $fqcn::uninstall();

        $queries = $GLOBALS['wpdb']->queryCalls;

        $this->assertCount(
            3,
            $queries,
            'AC-4: Schema::uninstall() MUSS $wpdb->query() exakt 3-mal aufrufen — '
            . 'einmal pro Custom-Table.'
        );

        // Jede Query muss DROP TABLE IF EXISTS sein.
        foreach ($queries as $i => $sql) {
            $this->assertMatchesRegularExpression(
                '/^DROP\s+TABLE\s+IF\s+EXISTS\s+wp_spreadconnect_/i',
                $sql,
                sprintf(
                    'AC-4: Query #%d MUSS mit "DROP TABLE IF EXISTS {$wpdb->prefix}spreadconnect_..." beginnen, war: %s',
                    $i + 1,
                    $sql
                )
            );
        }

        // Alle drei erwarteten Tabellen sind dropped (Reihenfolge irrelevant per Spec).
        $combined = implode("\n", $queries);
        foreach (['wp_spreadconnect_failed_ops', 'wp_spreadconnect_webhook_log', 'wp_spreadconnect_sync_history'] as $table) {
            $this->assertMatchesRegularExpression(
                '/DROP\s+TABLE\s+IF\s+EXISTS\s+' . preg_quote($table, '/') . '\b/i',
                $combined,
                sprintf('AC-4: DROP TABLE IF EXISTS fuer "%s" fehlt.', $table)
            );
        }
    }

    /**
     * AC-4: DROP-Statements nutzen $wpdb->prefix (kein hardcoded "wp_").
     *
     * Wenn wir den $wpdb-Stub mit Custom-Prefix laufen lassen, MUESSEN
     * die DROP-Statements diesen Prefix verwenden.
     */
    public function test_uninstall_uses_wpdb_prefix(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';

        // Custom prefix — wenn der Code "wp_" hardcoded haette, wuerden die
        // Tests hier scheitern.
        $GLOBALS['wpdb'] = self::makeWpdbStub('xx_test_');

        $fqcn::uninstall();

        $queries = $GLOBALS['wpdb']->queryCalls;

        $this->assertCount(3, $queries, 'AC-4: Genau 3 DROP-Queries.');

        foreach ($queries as $i => $sql) {
            $this->assertStringContainsString(
                'xx_test_spreadconnect_',
                $sql,
                sprintf(
                    'AC-4 / Constraint: Query #%d MUSS den Custom-Prefix "xx_test_" enthalten — '
                    . 'kein hardcoded "wp_". SQL war: %s',
                    $i + 1,
                    $sql
                )
            );
            $this->assertStringNotContainsString(
                'wp_spreadconnect_',
                $sql,
                sprintf(
                    'AC-4 / Constraint: Query #%d enthaelt hardcoded "wp_spreadconnect_" — '
                    . 'das ist ein Bug. Verwende {$wpdb->prefix}.',
                    $i + 1
                )
            );
        }
    }

    /**
     * AC-4: uninstall.php ruft \SpreadconnectPod\Bootstrap\Schema::uninstall().
     */
    public function test_uninstall_php_calls_schema_uninstall(): void
    {
        $file = self::uninstallFile();
        $this->assertFileExists($file, 'AC-4: uninstall.php muss existieren.');

        $contents = (string) file_get_contents($file);

        // Akzeptiere FQN-Schreibweise und use-importierte Form.
        $patternFqn = '/\\\\?SpreadconnectPod\\\\Bootstrap\\\\Schema::uninstall\s*\(\s*\)\s*;/';
        $patternShort = '/(^|[^\\w\\\\])Schema::uninstall\s*\(\s*\)\s*;/m';

        $matchedFqn = preg_match($patternFqn, $contents) === 1;
        $matchedShort = preg_match($patternShort, $contents) === 1;

        $this->assertTrue(
            $matchedFqn || $matchedShort,
            'AC-4: uninstall.php MUSS `\\SpreadconnectPod\\Bootstrap\\Schema::uninstall()` '
            . '(oder via use importiert `Schema::uninstall()`) aufrufen.'
        );

        // Genau einmal.
        $countFqn = preg_match_all($patternFqn, $contents) ?: 0;
        $countShort = preg_match_all($patternShort, $contents) ?: 0;

        $this->assertSame(
            1,
            $countFqn + $countShort,
            'AC-4: Schema::uninstall() darf nur EINMAL in uninstall.php aufgerufen werden.'
        );
    }

    // ===================================================================
    // AC-5: GIVEN Plugin::init() aus Slice 02
    //       WHEN Slice 04 abgeschlossen
    //       THEN registriert init() einen
    //            register_activation_hook( $plugin_file, [Schema::class, 'install'] )
    //            — genau einmal pro init()-Lauf.
    // ===================================================================

    /**
     * AC-5: Plugin::init() registriert register_activation_hook
     * mit [Schema::class, 'install'].
     */
    public function test_plugin_init_registers_activation_hook_for_schema_install(): void
    {
        $pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $schemaFqcn = 'SpreadconnectPod\\Bootstrap\\Schema';
        $this->assertTrue(class_exists($pluginFqcn), 'AC-5: Plugin muss autoloadbar sein.');
        $this->assertTrue(class_exists($schemaFqcn), 'AC-5: Schema muss autoloadbar sein.');

        $registrations = [];
        Monkey\Functions\when('register_activation_hook')->alias(function ($file, $callable) use (&$registrations) {
            $registrations[] = [$file, $callable];
            return null;
        });

        $pluginFile = self::pluginMainFile();
        $pluginFqcn::init($pluginFile);

        $this->assertGreaterThanOrEqual(
            1,
            count($registrations),
            'AC-5: register_activation_hook MUSS mindestens einmal aufgerufen werden.'
        );

        // Suche die Registration mit dem [Schema::class, 'install']-Callback.
        $found = null;
        foreach ($registrations as $reg) {
            [$file, $callable] = $reg;
            if (is_array($callable)
                && count($callable) === 2
                && ltrim((string) $callable[0], '\\') === $schemaFqcn
                && $callable[1] === 'install'
            ) {
                $found = $reg;
                break;
            }
        }

        $this->assertNotNull(
            $found,
            'AC-5: Plugin::init() MUSS register_activation_hook() mit '
            . 'Callable [Schema::class, "install"] aufrufen. '
            . 'Registrierungen waren: ' . print_r($registrations, true)
        );

        // Erstes Argument MUSS der uebergebene $plugin_file sein.
        $this->assertSame(
            $pluginFile,
            $found[0],
            'AC-5: register_activation_hook() MUSS als 1. Argument den $plugin_file '
            . 'erhalten, der an init() uebergeben wurde — KEIN hardcoded Pfad.'
        );
    }

    /**
     * AC-5: register_activation_hook wird pro init()-Lauf nur einmal
     * fuer den Schema::install-Callback aufgerufen.
     */
    public function test_activation_hook_registered_only_once_per_init(): void
    {
        $pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $schemaFqcn = 'SpreadconnectPod\\Bootstrap\\Schema';

        $registrations = [];
        Monkey\Functions\when('register_activation_hook')->alias(function ($file, $callable) use (&$registrations) {
            $registrations[] = [$file, $callable];
            return null;
        });

        $pluginFile = self::pluginMainFile();

        // 2x init() — Idempotenz aus Slice 02.
        $pluginFqcn::init($pluginFile);
        $pluginFqcn::init($pluginFile);

        // Zaehle nur die fuer Schema::install registrierten Hooks.
        $schemaInstallCount = 0;
        foreach ($registrations as $reg) {
            [, $callable] = $reg;
            if (is_array($callable)
                && count($callable) === 2
                && ltrim((string) $callable[0], '\\') === $schemaFqcn
                && $callable[1] === 'install'
            ) {
                $schemaInstallCount++;
            }
        }

        $this->assertSame(
            1,
            $schemaInstallCount,
            'AC-5: Auch bei doppeltem Plugin::init() darf register_activation_hook '
            . 'fuer [Schema::class, "install"] nur EINMAL aufgerufen werden — '
            . 'der Idempotenz-Guard aus Slice 02 AC-5 muss greifen.'
        );
    }

    /**
     * AC-5 (Constraint): Hook-Registrierung kommt NACH dem hpos-Hook
     * aus slice-03 in der init()-Methode (Source-Reihenfolge).
     *
     * Statische Source-Analyse: das `register_activation_hook(...)`-Statement
     * MUSS positionell hinter dem `add_action('before_woocommerce_init', ...)`
     * (HPOS-Hook aus slice-03) erscheinen.
     */
    public function test_activation_hook_registered_after_hpos_hook(): void
    {
        $pluginFile = self::pluginClassFile();
        $this->assertFileExists($pluginFile, 'Plugin.php muss existieren.');

        $contents = (string) file_get_contents($pluginFile);

        // Wir extrahieren den init()-Methoden-Body und vergleichen die
        // Positionen der add_action()- und register_activation_hook()-
        // Aufrufe innerhalb dieses Bodys. Reine String-Suche mit
        // "before_woocommerce_init" wuerde die Docstring-Erwaehnung weiter
        // oben treffen — deshalb muessen wir auf die echten Funktions-
        // Aufrufe matchen.
        $initBody = self::extractMethodBody($contents, 'init');
        $this->assertNotNull(
            $initBody,
            'AC-5: init()-Methoden-Body muss extrahierbar sein.'
        );

        // Strip // line-comments und /** ... */ doc-comments aus dem Body —
        // sonst trifft unsere Suche auch die Slice-Hinweise wie
        // `// - slice-04: register_activation_hook() -> Schema::dbDelta().`
        // Wir benutzen den PHP-Tokenizer fuer Robustheit (keine String-/Heredoc-
        // Verwechslungen).
        $bodyTokens = token_get_all('<?php ' . $initBody);
        $initBody = '';
        foreach ($bodyTokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_OPEN_TAG) {
                    continue;
                }
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $initBody .= $token[1];
            } else {
                $initBody .= $token;
            }
        }

        // Position des add_action('before_woocommerce_init', ...)-Aufrufs.
        $addActionPos = false;
        if (preg_match(
            "/add_action\\s*\\(\\s*['\"]before_woocommerce_init['\"]/",
            $initBody,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            $addActionPos = $matches[0][1];
        }
        $this->assertNotFalse(
            $addActionPos,
            'AC-5 (precondition): init()-Body muss '
            . "add_action('before_woocommerce_init', ...) aufrufen (slice-03)."
        );

        // Position des register_activation_hook(...)-Aufrufs.
        $activationPos = false;
        if (preg_match(
            '/register_activation_hook\s*\(/',
            $initBody,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            $activationPos = $matches[0][1];
        }
        $this->assertNotFalse(
            $activationPos,
            'AC-5: init()-Body MUSS register_activation_hook(...) aufrufen.'
        );

        $this->assertGreaterThan(
            $addActionPos,
            $activationPos,
            'AC-5 / Constraint: register_activation_hook MUSS NACH dem '
            . 'add_action(\'before_woocommerce_init\', ...) (slice-03) registriert werden — '
            . 'slice-04 erweitert init() additiv, aendert die slice-03-Reihenfolge nicht.'
        );
    }

    // ===================================================================
    // AC-6: GIVEN Schema::install()
    //       WHEN aufgerufen, aber wp-admin/includes/upgrade.php ist noch nicht geladen
    //       THEN laedt sie via require_once ABSPATH . 'wp-admin/includes/upgrade.php'.
    // ===================================================================

    /**
     * AC-6: install() enthaelt require_once auf
     * ABSPATH . 'wp-admin/includes/upgrade.php'.
     */
    public function test_install_requires_upgrade_php(): void
    {
        $source = self::schemaSource();

        $installBody = self::extractMethodBody($source, 'install');
        $this->assertNotNull($installBody, 'AC-6: install()-Body muss extrahierbar sein.');

        $this->assertMatchesRegularExpression(
            '/require_once\s+ABSPATH\s*\.\s*[\'"]wp-admin\/includes\/upgrade\.php[\'"]/',
            $installBody,
            'AC-6: Schema::install() MUSS exakt '
            . '`require_once ABSPATH . \'wp-admin/includes/upgrade.php\';` enthalten — '
            . 'sonst ist `dbDelta()` im Activation-Kontext nicht definiert.'
        );
    }

    /**
     * AC-6 (zusaetzlich, dynamic): Ein Aufruf von install() OHNE vorab
     * geladene `dbDelta`-Funktion verursacht keinen "function not found"-Fehler,
     * weil install() upgrade.php selbst nachlaedt.
     *
     * Wir simulieren die Situation: ABSPATH zeigt auf einen Ordner, in dem
     * `wp-admin/includes/upgrade.php` existiert und beim require_once eine
     * `dbDelta`-Funktion definiert. Wir koennen `dbDelta` NICHT vorab via
     * Brain\Monkey definieren, damit der require_once-Pfad signifikant ist.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_install_loads_upgrade_php_when_dbdelta_undefined(): void
    {
        // Brain\Monkey (Test-spezifisch, da Separate Process).
        Monkey\setUp();
        try {
            $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';
            $this->assertTrue(class_exists($fqcn), 'AC-6: Schema muss autoloadbar sein.');

            // Prepare ABSPATH stub: upgrade.php definiert dbDelta() als no-op.
            $root = sys_get_temp_dir() . '/sc-pod-test-abspath-ac6-' . getmypid() . '/';
            $upgradeDir = $root . 'wp-admin/includes/';
            $upgradeFile = $upgradeDir . 'upgrade.php';

            if (! is_dir($upgradeDir)) {
                @mkdir($upgradeDir, 0777, true);
            }
            // Datei schreibt eine globale dbDelta()-Funktion, sofern noch nicht definiert.
            file_put_contents(
                $upgradeFile,
                "<?php\nif ( ! function_exists( 'dbDelta' ) ) { function dbDelta( \$sql ) { \$GLOBALS['ac6_dbdelta_calls'][] = (string) \$sql; return []; } }\n"
            );

            if (! defined('ABSPATH')) {
                define('ABSPATH', $root);
            }

            $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');
            $GLOBALS['ac6_dbdelta_calls'] = [];

            // Vorab darf dbDelta NICHT definiert sein.
            $this->assertFalse(
                function_exists('dbDelta'),
                'AC-6 (precondition): dbDelta darf vor install() noch nicht definiert sein.'
            );

            // install() — sollte upgrade.php nachladen und dbDelta verfuegbar machen.
            try {
                $fqcn::install();
            } catch (\Throwable $e) {
                $this->fail(
                    'AC-6: install() darf bei nicht-definierter dbDelta-Funktion nicht werfen — '
                    . 'das require_once muss die Funktion via upgrade.php laden. '
                    . 'Geworfen: ' . $e::class . ' — ' . $e->getMessage()
                );
            }

            // dbDelta ist nun definiert.
            $this->assertTrue(
                function_exists('dbDelta'),
                'AC-6: Nach install() MUSS dbDelta() definiert sein (via require_once upgrade.php).'
            );

            // Und sie wurde 3x aufgerufen.
            $this->assertCount(
                3,
                $GLOBALS['ac6_dbdelta_calls'] ?? [],
                'AC-6: install() ruft dbDelta() 3x auf, nachdem upgrade.php geladen wurde.'
            );
        } finally {
            Monkey\tearDown();
        }
    }

    // ===================================================================
    // AC-7: GIVEN Schema::uninstall() wird ohne WP_UNINSTALL_PLUGIN
    //             aufgerufen
    //       WHEN ausserhalb des WP-Uninstall-Lifecycles
    //       THEN prueft die Methode NICHT selbst auf den Konstanten-Guard
    //            — die Pruefung verbleibt im aufrufenden uninstall.php.
    //            uninstall() ist eine reine DROP-Sequenz.
    // ===================================================================

    /**
     * AC-7: Schema::uninstall() enthaelt KEINEN internen
     * defined('WP_UNINSTALL_PLUGIN')-Guard.
     */
    public function test_schema_uninstall_has_no_internal_constant_guard(): void
    {
        $source = self::schemaSource();

        $uninstallBody = self::extractMethodBody($source, 'uninstall');
        $this->assertNotNull(
            $uninstallBody,
            'AC-7: uninstall()-Body muss extrahierbar sein.'
        );

        // Forbidden patterns:
        $forbidden = [
            // defined('WP_UNINSTALL_PLUGIN') in jedweder Form.
            "defined-Check (single quotes)" => "/defined\\s*\\(\\s*'WP_UNINSTALL_PLUGIN'\\s*\\)/",
            "defined-Check (double quotes)" => '/defined\s*\(\s*"WP_UNINSTALL_PLUGIN"\s*\)/',
            // Direkter Konstanten-Zugriff in einem Vergleich.
            "WP_UNINSTALL_PLUGIN const usage" => '/\bWP_UNINSTALL_PLUGIN\b/',
            // Frueh-Exit Patterns.
            "exit/return guard" => '/if\s*\([^\)]*WP_UNINSTALL_PLUGIN[^\)]*\)\s*\{?\s*(return|exit|die)/i',
        ];

        foreach ($forbidden as $label => $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $uninstallBody,
                sprintf(
                    'AC-7: Schema::uninstall() darf KEINEN internen WP_UNINSTALL_PLUGIN-Guard '
                    . 'enthalten — gefunden: "%s". Single Responsibility: der Guard lebt in '
                    . 'uninstall.php (slice-02 AC-6). Schema::uninstall() ist reine DROP-Sequenz.',
                    $label
                )
            );
        }
    }

    /**
     * AC-7: Schema::uninstall() laesst sich ohne gesetztes WP_UNINSTALL_PLUGIN
     * aufrufen (Test-Setup-Use-Case).
     */
    public function test_schema_uninstall_runnable_without_constant(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';

        // WP_UNINSTALL_PLUGIN darf in der Test-Umgebung NICHT definiert sein
        // (PHPUnit-Process ist kein WP-Uninstall-Lifecycle).
        $this->assertFalse(
            defined('WP_UNINSTALL_PLUGIN'),
            'AC-7 (precondition): WP_UNINSTALL_PLUGIN darf in PHPUnit nicht definiert sein.'
        );

        $GLOBALS['wpdb'] = self::makeWpdbStub('wp_');

        // Aufruf ohne Konstante — MUSS regulaer durchlaufen.
        try {
            $fqcn::uninstall();
        } catch (\Throwable $e) {
            $this->fail(
                'AC-7: Schema::uninstall() MUSS auch ohne WP_UNINSTALL_PLUGIN aufrufbar sein '
                . '(Test-Setup-Use-Case). Geworfen: ' . $e::class . ' — ' . $e->getMessage()
            );
        }

        // Drei DROP-Queries sind tatsaechlich gelaufen — kein Early-Return durch internen Guard.
        $this->assertCount(
            3,
            $GLOBALS['wpdb']->queryCalls,
            'AC-7: uninstall() darf nicht durch einen internen Guard blockiert sein — '
            . 'es muessen 3 DROP-Queries laufen, auch wenn WP_UNINSTALL_PLUGIN nicht gesetzt ist.'
        );
    }

    /**
     * AC-7 (Signatur): Schema::uninstall() ist public static, keine Args, return void.
     */
    public function test_schema_uninstall_signature(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Schema';
        $this->assertTrue(class_exists($fqcn), 'AC-7: Schema muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);
        $this->assertTrue(
            $reflection->isFinal(),
            'AC-7 / Constraint: Schema MUSS final class sein (keine Subclasses).'
        );

        $this->assertTrue(
            $reflection->hasMethod('uninstall'),
            'AC-7: Schema MUSS Methode "uninstall" haben.'
        );

        $method = $reflection->getMethod('uninstall');
        $this->assertTrue($method->isPublic(), 'AC-7: uninstall() muss public sein.');
        $this->assertTrue($method->isStatic(), 'AC-7: uninstall() muss static sein.');
        $this->assertCount(
            0,
            $method->getParameters(),
            'AC-7: uninstall() darf keine Parameter haben.'
        );

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName(), 'AC-7: uninstall() return type ist void.');

        // Ebenso install() (zur Sicherheit gegen Drift):
        $this->assertTrue($reflection->hasMethod('install'), 'AC-7: Schema MUSS Methode "install" haben.');
        $installMethod = $reflection->getMethod('install');
        $this->assertTrue($installMethod->isPublic(), 'AC-7: install() muss public sein.');
        $this->assertTrue($installMethod->isStatic(), 'AC-7: install() muss static sein.');
        $this->assertCount(0, $installMethod->getParameters(), 'AC-7: install() darf keine Parameter haben.');
        $installReturn = $installMethod->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $installReturn);
        $this->assertSame('void', $installReturn->getName(), 'AC-7: install() return type ist void.');
    }
}
