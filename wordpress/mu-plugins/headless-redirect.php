<?php
/**
 * Plugin Name: POD Shop – Headless Redirect
 * Description: Leitet alle WordPress-Frontend-Requests zum Next.js Frontend um.
 *              Nur WP-Admin, REST API und GraphQL bleiben auf WordPress erreichbar.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

add_action('template_redirect', static function (): void {
    // WP-Admin: nicht umleiten
    if (is_admin()) {
        return;
    }

    // REST API: nicht umleiten
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    // GraphQL: nicht umleiten
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($request_uri, '/graphql')) {
        return;
    }

    // WP-Login/Register: nicht umleiten
    if (str_contains($request_uri, '/wp-login.php') || str_contains($request_uri, '/wp-register.php')) {
        return;
    }

    // WooCommerce-Checkout und Zahlungsseiten: nicht umleiten (Mollie-Callbacks etc.)
    $wc_paths = ['/checkout', '/order-pay', '/order-received', '/wc-api/'];
    foreach ($wc_paths as $wc_path) {
        if (str_starts_with($request_uri, $wc_path)) {
            return;
        }
    }

    // Alles andere → Next.js Frontend
    $next_url = rtrim(getenv('NEXT_PUBLIC_SHOP_URL') ?: 'http://localhost:3000', '/');

    // Pfad + Query-String weiterreichen
    $path = $request_uri;
    wp_redirect($next_url . $path, 301);
    exit;
});
