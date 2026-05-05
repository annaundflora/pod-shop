<?php
/**
 * PHPUnit bootstrap for the POD Shop MVP test suite.
 *
 * Loads Composer's autoloader and defines minimal no-op stubs for
 * WordPress functions that the Plugin bootstrap (and follow-up slices)
 * call directly. Each slice that adds a WP function call to
 * `Plugin::init()` would otherwise break every earlier slice's tests
 * that exercise `init()` directly. This bootstrap removes that
 * per-slice fragility.
 *
 * Design notes:
 *
 *   1. Patchwork is loaded BEFORE the WP function stubs so that
 *      tests using `Brain\Monkey\Functions\when('register_activation_hook')
 *      ->alias(...)` (which delegates to `\Patchwork\redefine()`) can
 *      redefine our no-op stubs at runtime. Patchwork installs a
 *      stream wrapper that instruments any PHP file `require`d AFTER
 *      it loads — files included earlier are NOT redefinable
 *      (`Patchwork\Exceptions\DefinedTooEarly`).
 *
 *   2. We deliberately do NOT stub `add_action()`, `add_filter()` or
 *      `did_action()`. Those are shipped by Brain\Monkey and gated by
 *      its own `function_exists()` checks in `inc/wp-hook-functions.php`
 *      (loaded on each `Brain\Monkey\setUp()` call). Pre-defining no-op
 *      variants here would shadow Brain\Monkey's hook-storage spies
 *      and silently break any test that asserts on action/filter
 *      registrations (e.g. Slice03 HPOS hook tests).
 *
 *   3. We also do NOT define `ABSPATH` here. The Slice04 dbDelta
 *      sequence (`Schema::install()`) requires
 *      `ABSPATH . 'wp-admin/includes/upgrade.php'`, and several slice
 *      tests need to point ABSPATH at a fresh temp directory they
 *      provision themselves (e.g. `#[RunInSeparateProcess]` tests for
 *      "what if dbDelta is not yet defined"). Defining ABSPATH globally
 *      here would short-circuit those tests' own `if ( ! defined() )`
 *      guards. Tests that need the constant define it locally.
 *
 *   4. Every stub uses `function_exists()` so that running the
 *      bootstrap in a subprocess (e.g. `#[RunInSeparateProcess]`) does
 *      not redeclare functions if the parent process has already
 *      provided them via PHPUnit's globals snapshot.
 */

declare(strict_types=1);

// --------------------------------------------------------------------
// 1. Composer autoloader (project + dev dependencies, including
//    Brain\Monkey and Patchwork).
// --------------------------------------------------------------------
require_once __DIR__ . '/../vendor/autoload.php';

// --------------------------------------------------------------------
// 2. Pre-load Patchwork so its stream wrapper is active before any
//    WP function stubs are declared. Brain\Monkey's setUp() will
//    short-circuit on the second load (idempotent via
//    `function_exists('Patchwork\redefine')`).
// --------------------------------------------------------------------
$patchworkLoader = __DIR__ . '/../vendor/brain/monkey/inc/patchwork-loader.php';
if (is_file($patchworkLoader)) {
    require_once $patchworkLoader;
}

// --------------------------------------------------------------------
// 3. WP function stubs — declared inside an instrumented file so
//    Patchwork can redefine them via Brain\Monkey expectations.
//    The stubs themselves live in a separate file that is
//    require_once'd here (Patchwork's stream wrapper instruments the
//    require, not the inline declaration in this bootstrap file).
// --------------------------------------------------------------------
require_once __DIR__ . '/stubs/wp-functions.php';

// --------------------------------------------------------------------
// 4. WC class stubs — minimal canonical declarations of `WC_Order`,
//    `WC_Logger`, `WC_Product`, `WC_Order_Item_Product`, `wpdb`. These
//    must be loaded BEFORE any individual slice test declares its own
//    `class_exists()`-guarded stub, otherwise the FIRST slice test
//    declares a partial stub and follow-up slices that need additional
//    methods (e.g. slice-28's billing/shipping accessors) cannot
//    `method_exists()` them.
// --------------------------------------------------------------------
require_once __DIR__ . '/stubs/wc-classes.php';
