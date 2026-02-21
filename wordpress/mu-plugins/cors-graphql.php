<?php
/**
 * Plugin Name: POD Shop – GraphQL CORS Headers
 * Description: Setzt CORS-Header für WPGraphQL-Requests vom Next.js Frontend.
 *              Erlaubt localhost:3000 (dev) sowie die konfigurierte SHOP_URL (prod).
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * CORS-Header für WPGraphQL-Responses.
 * Nutzt WPGraphQL's eigenen Filter, damit Headers korrekt gesetzt werden.
 *
 * @param array $headers
 * @return array
 */
add_filter('graphql_response_headers_to_send', static function (array $headers): array {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    $allowed_origins = array_filter([
        'http://localhost:3000',
        'http://localhost:3001',
        getenv('NEXT_PUBLIC_SHOP_URL') ?: null,
        getenv('WP_HOME')              ?: null,
    ]);

    if (in_array($origin, $allowed_origins, true)) {
        $headers['Access-Control-Allow-Origin']      = $origin;
        $headers['Access-Control-Allow-Credentials'] = 'true';
        $headers['Access-Control-Allow-Headers']     = 'Authorization, Content-Type, woocommerce-session';
        $headers['Access-Control-Expose-Headers']    = 'woocommerce-session';
        $headers['Access-Control-Allow-Methods']     = 'POST, GET, OPTIONS';
        $headers['Vary']                             = 'Origin';
    }

    return $headers;
});

/**
 * Beantwortet OPTIONS-Preflight-Requests direkt (vor WordPress-Routing).
 */
add_action('init', static function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!str_contains($request_uri, '/graphql')) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    $allowed_origins = array_filter([
        'http://localhost:3000',
        'http://localhost:3001',
        getenv('NEXT_PUBLIC_SHOP_URL') ?: null,
        getenv('WP_HOME')              ?: null,
    ]);

    if (!in_array($origin, $allowed_origins, true)) {
        return;
    }

    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, woocommerce-session');
    header('Access-Control-Expose-Headers: woocommerce-session');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');

    http_response_code(204);
    exit;
}, 1);
