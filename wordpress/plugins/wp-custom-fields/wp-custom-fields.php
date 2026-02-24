<?php
/**
 * Plugin Name: WP Custom Fields for Headless
 * Plugin URI:  https://github.com/pod-shop
 * Description: Registriert Hero-Felder und SEO Meta Description fuer WPGraphQL
 * Version:     1.0.0
 * Author:      POD Shop
 * Text Domain: wp-custom-fields
 *
 * @package WpCustomFields
 */

declare(strict_types=1);

namespace WpCustomFields;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-custom-fields.php';

add_action('init', [CustomFields::class, 'register_post_meta_fields']);
add_action('graphql_register_types', [CustomFields::class, 'register_graphql_fields']);
