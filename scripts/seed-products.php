<?php
/**
 * POD Shop – WooCommerce Mock Data Seeder
 * Run via: wp eval-file /scripts/seed-products.php --allow-root
 */

if (!function_exists('wc_create_attribute')) {
    WP_CLI::error('WooCommerce not active or not initialized. Run setup.sh first.');
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// Helper: find attribute ID by slug (searches wc_get_attribute_taxonomies)
// ─────────────────────────────────────────────────────────────
function pod_find_attribute_id(string $slug): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT attribute_id FROM {$table} WHERE attribute_name = %s LIMIT 1",
        $slug
    ));
    return $id ? (int) $id : 0;
}

// ─────────────────────────────────────────────────────────────
// Helper: get or create global product attribute (taxonomy)
// Returns the attribute taxonomy ID
// ─────────────────────────────────────────────────────────────
function pod_ensure_attribute(string $name, string $slug): int
{
    global $wpdb;
    $taxonomy = 'pa_' . $slug;
    $table    = $wpdb->prefix . 'woocommerce_attribute_taxonomies';

    // Direct DB lookup first (bypasses all caches)
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT attribute_id FROM {$table} WHERE attribute_name = %s LIMIT 1",
        $slug
    ));

    if ($id > 0) {
        // Ensure taxonomy is registered for term operations
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product', 'product_variation']);
        }
        WP_CLI::log("  Attribute '$name' exists (ID: $id)");
        return $id;
    }

    // Create via WooCommerce API (do NOT register_taxonomy before — WC checks taxonomy_exists!)
    $result = wc_create_attribute([
        'name'         => $name,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ]);

    if (is_wp_error($result)) {
        WP_CLI::error("Failed to create attribute '$name': " . $result->get_error_message());
    }

    // Clear WC cache and register taxonomy for term operations
    delete_transient('wc_attribute_taxonomies');
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product', 'product_variation']);
    }

    WP_CLI::log("  Created attribute '$name' (ID: $result)");
    return (int) $result;
}

// ─────────────────────────────────────────────────────────────
// Helper: get or insert a term in a taxonomy
// ─────────────────────────────────────────────────────────────
function pod_ensure_term(string $name, string $taxonomy): int
{
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product', 'product_variation']);
    }
    $term = get_term_by('name', $name, $taxonomy);
    if ($term) return (int) $term->term_id;

    $result = wp_insert_term($name, $taxonomy);
    if (is_wp_error($result)) {
        WP_CLI::error("Failed to insert term '$name' in $taxonomy: " . $result->get_error_message());
    }
    return (int) $result['term_id'];
}

// ─────────────────────────────────────────────────────────────
// Helper: create a variable product with all size×color variants
// ─────────────────────────────────────────────────────────────
function pod_create_variable_product(array $args): int
{
    $sizes  = ['S', 'M', 'L', 'XL', 'XXL'];
    $colors = ['Schwarz', 'Weiß', 'Grau', 'Navy'];

    $product = new WC_Product_Variable();
    $product->set_name($args['name']);
    $product->set_slug($args['slug']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($args['description']);
    $product->set_short_description($args['short_description']);
    $product->set_category_ids([$args['category_id']]);

    // Size attribute
    $size_attr_id  = pod_find_attribute_id('groesse');
    $size_term_ids = array_map(fn($s) => pod_ensure_term($s, 'pa_groesse'), $sizes);

    $size_attr = new WC_Product_Attribute();
    $size_attr->set_id($size_attr_id);
    $size_attr->set_name('pa_groesse');
    $size_attr->set_options($size_term_ids);
    $size_attr->set_position(0);
    $size_attr->set_visible(true);
    $size_attr->set_variation(true);

    // Color attribute
    $color_attr_id  = pod_find_attribute_id('farbe');
    $color_term_ids = array_map(fn($c) => pod_ensure_term($c, 'pa_farbe'), $colors);

    $color_attr = new WC_Product_Attribute();
    $color_attr->set_id($color_attr_id);
    $color_attr->set_name('pa_farbe');
    $color_attr->set_options($color_term_ids);
    $color_attr->set_position(1);
    $color_attr->set_visible(true);
    $color_attr->set_variation(true);

    $product->set_attributes([$size_attr, $color_attr]);
    $product->update_meta_data('_spreadconnect_product_id', $args['spreadconnect_id'] ?? '');
    $product_id = $product->save();

    // Assign terms to product post
    wp_set_object_terms($product_id, $size_term_ids, 'pa_groesse');
    wp_set_object_terms($product_id, $color_term_ids, 'pa_farbe');

    // Create all size × color variations
    $count = 0;
    foreach ($sizes as $size) {
        foreach ($colors as $color) {
            $size_term  = get_term_by('name', $size,  'pa_groesse');
            $color_term = get_term_by('name', $color, 'pa_farbe');
            if (!$size_term || !$color_term) continue;

            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_regular_price($args['price']);
            $variation->set_status('publish');
            $variation->set_attributes([
                'pa_groesse' => $size_term->slug,
                'pa_farbe'   => $color_term->slug,
            ]);
            $variation->save();
            $count++;
        }
    }

    WC_Product_Variable::sync($product_id);
    WP_CLI::success(sprintf("  '%s' (ID: %d, %d variations)", $args['name'], $product_id, $count));
    return $product_id;
}

// ═════════════════════════════════════════════════════════════
// 1. Product attributes
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('📐 Creating product attributes...');
pod_ensure_attribute('Größe', 'groesse');
pod_ensure_attribute('Farbe',  'farbe');
WP_CLI::success('Attributes ready');

// ═════════════════════════════════════════════════════════════
// 2. Product categories
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('📂 Creating product categories...');

$tshirt = get_term_by('slug', 't-shirts', 'product_cat');
$tshirt_cat_id = $tshirt
    ? (int) $tshirt->term_id
    : (int) wp_insert_term('T-Shirts', 'product_cat', ['slug' => 't-shirts'])['term_id'];
WP_CLI::log("  T-Shirts: ID $tshirt_cat_id");

$hoodie = get_term_by('slug', 'hoodies', 'product_cat');
$hoodie_cat_id = $hoodie
    ? (int) $hoodie->term_id
    : (int) wp_insert_term('Hoodies', 'product_cat', ['slug' => 'hoodies'])['term_id'];
WP_CLI::log("  Hoodies: ID $hoodie_cat_id");

WP_CLI::success('Categories ready');

// ═════════════════════════════════════════════════════════════
// 3. Variable products
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('👕 Creating variable products...');

if (!get_page_by_path('classic-t-shirt', OBJECT, 'product')) {
    pod_create_variable_product([
        'name'              => 'Classic T-Shirt',
        'slug'              => 'classic-t-shirt',
        'description'       => '<p>Ein klassisches T-Shirt in hochwertiger Baumwollqualität. Print-on-Demand gefertigt über Spreadconnect.</p><p>Material: 100% Baumwolle, 180g/m²</p>',
        'short_description' => 'Hochwertiges T-Shirt, Print-on-Demand. 5 Größen, 4 Farben.',
        'category_id'       => $tshirt_cat_id,
        'price'             => '24.99',
        'spreadconnect_id'  => 'demo-tshirt-001',
    ]);
} else { WP_CLI::log('  Classic T-Shirt already exists'); }

if (!get_page_by_path('premium-t-shirt', OBJECT, 'product')) {
    pod_create_variable_product([
        'name'              => 'Premium T-Shirt',
        'slug'              => 'premium-t-shirt',
        'description'       => '<p>Unser Premium T-Shirt aus Bio-Baumwolle (GOTS-zertifiziert), 200g/m².</p>',
        'short_description' => 'Premium T-Shirt aus Bio-Baumwolle. 5 Größen, 4 Farben.',
        'category_id'       => $tshirt_cat_id,
        'price'             => '34.99',
        'spreadconnect_id'  => 'demo-tshirt-002',
    ]);
} else { WP_CLI::log('  Premium T-Shirt already exists'); }

if (!get_page_by_path('classic-hoodie', OBJECT, 'product')) {
    pod_create_variable_product([
        'name'              => 'Classic Hoodie',
        'slug'              => 'classic-hoodie',
        'description'       => '<p>Ein hochwertiger Hoodie mit Känguru-Tasche. Print-on-Demand gefertigt über Spreadconnect.</p><p>Material: 80% Baumwolle, 20% Polyester, 320g/m²</p>',
        'short_description' => 'Hochwertiger Hoodie, Print-on-Demand. 5 Größen, 4 Farben.',
        'category_id'       => $hoodie_cat_id,
        'price'             => '44.99',
        'spreadconnect_id'  => 'demo-hoodie-001',
    ]);
} else { WP_CLI::log('  Classic Hoodie already exists'); }

WP_CLI::success('Variable products created');

// ═════════════════════════════════════════════════════════════
// 4. Legal pages
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('📄 Creating legal pages...');

$legal = [
    ['title' => 'Impressum',            'slug' => 'impressum',   'content' => '<h1>Impressum</h1><p>Angaben gemäß § 5 TMG</p><p><strong>[Ihr Name]</strong><br>[Straße Hausnummer]<br>[PLZ Ort]</p><p>E-Mail: [ihre@email.de]</p><p><em>Hinweis gemäß § 19 UStG: Als Kleinunternehmer wird keine Umsatzsteuer berechnet.</em></p>'],
    ['title' => 'Datenschutzerklärung', 'slug' => 'datenschutz', 'content' => '<h1>Datenschutzerklärung</h1><p>[Vollständige Datenschutzerklärung eintragen]</p>'],
    ['title' => 'AGB',                  'slug' => 'agb',          'content' => '<h1>Allgemeine Geschäftsbedingungen</h1><p>Gemäß § 19 UStG wird keine Umsatzsteuer ausgewiesen.</p>'],
    ['title' => 'Widerrufsbelehrung',   'slug' => 'widerruf',     'content' => '<h1>Widerrufsbelehrung</h1><p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>'],
];

foreach ($legal as $page) {
    if (!get_page_by_path($page['slug'])) {
        wp_insert_post(['post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['content'], 'post_status' => 'publish', 'post_type' => 'page']);
        WP_CLI::log("  Created: {$page['title']}");
    } else {
        WP_CLI::log("  Exists: {$page['title']}");
    }
}

WP_CLI::success('Legal pages ready');
WP_CLI::log('');
WP_CLI::success('🎉 All mock data seeded!');
