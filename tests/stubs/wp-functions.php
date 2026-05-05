<?php
/**
 * Minimal no-op stubs for WordPress functions used by Plugin::init()
 * (and follow-up slice production code) that are NOT shipped by
 * Brain\Monkey.
 *
 * Loaded from `tests/bootstrap.php` AFTER Patchwork's stream wrapper is
 * installed, so Brain\Monkey's `Functions\when()->alias()` (which
 * delegates to `\Patchwork\redefine()`) can override individual stubs
 * at test runtime.
 *
 * IMPORTANT: This file must remain stack-agnostic — only WP-specific
 * stubs that ANY slice may need. Slice-specific behaviour belongs in
 * the test that needs it (via Brain\Monkey expectations), not here.
 *
 * NOT stubbed here (Brain\Monkey already provides them):
 *   - add_action / add_filter / did_action / has_action / has_filter
 *     (see vendor/brain/monkey/inc/wp-hook-functions.php).
 */

declare(strict_types=1);

if ( ! function_exists('register_activation_hook')) {
    /**
     * No-op stub for WP `register_activation_hook()`.
     *
     * @param string         $file     Absolute path to the plugin file.
     * @param callable|array $callback Activation callback.
     */
    function register_activation_hook($file, $callback): void
    {
        // Intentionally empty.
    }
}

if ( ! function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback): void
    {
        // Intentionally empty.
    }
}

if ( ! function_exists('register_uninstall_hook')) {
    function register_uninstall_hook($file, $callback): void
    {
        // Intentionally empty.
    }
}

if ( ! function_exists('plugin_basename')) {
    /**
     * Minimal stub for WP `plugin_basename()`. Returns
     * `<plugin-dir>/<plugin-file>` for absolute plugin file paths,
     * matching WP's most common output.
     */
    function plugin_basename(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if ( ! function_exists('load_plugin_textdomain')) {
    /**
     * No-op stub for WP `load_plugin_textdomain()`. Always returns true.
     */
    function load_plugin_textdomain(string $domain, $deprecated = false, $plugin_rel_path = false): bool
    {
        return true;
    }
}

if ( ! function_exists('wp_die')) {
    /**
     * Stub for WP `wp_die()`. Throws a RuntimeException so tests can
     * assert on early-termination paths without halting PHPUnit itself.
     *
     * @param string|\WP_Error $message
     * @param string           $title
     * @param array<string,mixed> $args
     */
    function wp_die($message = '', $title = '', $args = []): void
    {
        $msg = is_scalar($message) ? (string) $message : 'wp_die';
        throw new \RuntimeException('wp_die: ' . $msg);
    }
}
