#!/bin/sh
# =============================================================
# POD Shop – WordPress Automated Setup
# Idempotent: safe to run multiple times
# =============================================================
set -e

WP_PATH=/var/www/html
WP_URL="${WP_HOME:-http://localhost:8080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin_password}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@localhost.local}"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  POD Shop – WordPress Automated Setup"
echo "═══════════════════════════════════════════════════════════"
echo ""

# ─────────────────────────────────────────────────────────────
# Step 1: Wait for wp-config.php
# ─────────────────────────────────────────────────────────────
echo "⏳ Waiting for wp-config.php..."
WAIT=0
until [ -f "$WP_PATH/wp-config.php" ]; do
  sleep 3
  WAIT=$((WAIT + 3))
  if [ $WAIT -gt 120 ]; then
    echo "❌ Timeout: wp-config.php not found after 120s"
    exit 1
  fi
done
echo "✅ wp-config.php found"

# ─────────────────────────────────────────────────────────────
# Step 2: Wait for database connection
# ─────────────────────────────────────────────────────────────
echo "⏳ Waiting for database..."
WAIT=0
until php /scripts/check-db.php > /dev/null 2>&1; do
  sleep 3
  WAIT=$((WAIT + 3))
  if [ $WAIT -gt 120 ]; then
    echo "❌ Timeout: database not ready after 120s"
    exit 1
  fi
done
echo "✅ Database ready"

# ─────────────────────────────────────────────────────────────
# Step 3: Idempotency check
# ─────────────────────────────────────────────────────────────
if wp option get pod_shop_setup_complete --allow-root --path="$WP_PATH" 2>/dev/null | grep -q "1"; then
  echo "✅ Setup already completed – skipping WordPress install"
  echo "🌱 Checking mock data..."
  sh /scripts/mock-data.sh
  exit 0
fi

# ─────────────────────────────────────────────────────────────
# Step 4: WordPress core install
# ─────────────────────────────────────────────────────────────
echo ""
echo "📦 Installing WordPress core..."
if ! wp core is-installed --allow-root --path="$WP_PATH" 2>/dev/null; then
  wp core install \
    --allow-root \
    --path="$WP_PATH" \
    --url="$WP_URL" \
    --title="POD Shop" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
  echo "✅ WordPress core installed"
else
  echo "✅ WordPress core already installed"
fi

# ─────────────────────────────────────────────────────────────
# Step 5: Permalink structure (required for WPGraphQL)
# ─────────────────────────────────────────────────────────────
echo ""
echo "🔗 Setting permalink structure..."
wp option update permalink_structure "/%postname%/" --allow-root --path="$WP_PATH"
wp rewrite flush --allow-root --path="$WP_PATH"
echo "✅ Permalinks: /%postname%/"

# ─────────────────────────────────────────────────────────────
# Step 6: Install WooCommerce
# ─────────────────────────────────────────────────────────────
echo ""
echo "📦 Installing WooCommerce..."
wp plugin install woocommerce --activate --allow-root --path="$WP_PATH"
echo "✅ WooCommerce installed & activated"

# ─────────────────────────────────────────────────────────────
# Step 7: Install WPGraphQL + WooGraphQL
# ─────────────────────────────────────────────────────────────
echo ""
echo "📦 Installing WPGraphQL..."
wp plugin install wp-graphql --activate --allow-root --path="$WP_PATH"
echo "✅ WPGraphQL installed & activated"

echo "📦 Installing WooGraphQL (from GitHub)..."
wp plugin install https://github.com/wp-graphql/wp-graphql-woocommerce/releases/download/v0.21.2/wp-graphql-woocommerce.zip --activate --allow-root --path="$WP_PATH"
echo "✅ WooGraphQL installed & activated"

# ─────────────────────────────────────────────────────────────
# Step 8: Install Mollie Payments
# ─────────────────────────────────────────────────────────────
echo ""
echo "📦 Installing Mollie Payments for WooCommerce..."
wp plugin install mollie-payments-for-woocommerce --activate --allow-root --path="$WP_PATH"
echo "✅ Mollie installed & activated"

# ─────────────────────────────────────────────────────────────
# Step 9: Activate custom plugins
# ─────────────────────────────────────────────────────────────
echo ""
echo "🔌 Activating custom plugins..."
wp plugin activate spreadconnect-pod --allow-root --path="$WP_PATH" 2>/dev/null \
  && echo "✅ spreadconnect-pod activated" \
  || echo "⚠️  spreadconnect-pod not found (skipped)"
wp plugin activate pinterest-capi --allow-root --path="$WP_PATH" 2>/dev/null \
  && echo "✅ pinterest-capi activated" \
  || echo "⚠️  pinterest-capi not found (skipped)"

# ─────────────────────────────────────────────────────────────
# Step 10: WooCommerce configuration
# ─────────────────────────────────────────────────────────────
echo ""
echo "⚙️  Configuring WooCommerce..."

# Currency & formatting
wp option update woocommerce_currency "EUR" --allow-root --path="$WP_PATH"
wp option update woocommerce_currency_pos "right_space" --allow-root --path="$WP_PATH"
wp option update woocommerce_price_decimal_sep "," --allow-root --path="$WP_PATH"
wp option update woocommerce_price_thousand_sep "." --allow-root --path="$WP_PATH"
wp option update woocommerce_price_num_decimals "2" --allow-root --path="$WP_PATH"

# Store location
wp option update woocommerce_default_country "DE" --allow-root --path="$WP_PATH"
wp option update woocommerce_store_city "Berlin" --allow-root --path="$WP_PATH"
wp option update woocommerce_store_postcode "10115" --allow-root --path="$WP_PATH"

# §19 UStG Kleinunternehmerregelung: disable taxes
wp option update woocommerce_calc_taxes "no" --allow-root --path="$WP_PATH"
wp option update woocommerce_prices_include_tax "no" --allow-root --path="$WP_PATH"
wp option update woocommerce_tax_display_shop "excl" --allow-root --path="$WP_PATH"
wp option update woocommerce_tax_display_cart "excl" --allow-root --path="$WP_PATH"

# Checkout & accounts
wp option update woocommerce_enable_guest_checkout "yes" --allow-root --path="$WP_PATH"
wp option update woocommerce_enable_signup_and_login_from_checkout "yes" --allow-root --path="$WP_PATH"
wp option update woocommerce_enable_myaccount_registration "yes" --allow-root --path="$WP_PATH"

# Stock management: disabled for Print-on-Demand
wp option update woocommerce_manage_stock "no" --allow-root --path="$WP_PATH"

# Shipping: Germany only
wp option update woocommerce_ship_to_countries "specific" --allow-root --path="$WP_PATH"

echo "✅ WooCommerce configured (EUR, §19 UStG, DE shipping)"

# ─────────────────────────────────────────────────────────────
# Step 11: WordPress general settings
# ─────────────────────────────────────────────────────────────
echo ""
echo "⚙️  Configuring WordPress settings..."
wp option update blogname "POD Shop" --allow-root --path="$WP_PATH"
wp option update blogdescription "Dein Print-on-Demand Shop" --allow-root --path="$WP_PATH"
wp option update timezone_string "Europe/Berlin" --allow-root --path="$WP_PATH"
wp option update date_format "d.m.Y" --allow-root --path="$WP_PATH"
wp option update time_format "H:i" --allow-root --path="$WP_PATH"
wp option update default_comment_status "closed" --allow-root --path="$WP_PATH"
echo "✅ WordPress settings configured"

# ─────────────────────────────────────────────────────────────
# Step 12: WPGraphQL settings
# ─────────────────────────────────────────────────────────────
echo ""
echo "⚙️  Configuring WPGraphQL..."
wp option update graphql_general_settings \
  '{"public_introspection_enabled":"on","batch_queries_enabled":"on","batch_limit":"10","query_depth_enabled":"off","graphql_endpoint":"graphql"}' \
  --format=json --allow-root --path="$WP_PATH" 2>/dev/null || true
echo "✅ WPGraphQL configured (endpoint: /graphql)"

# ─────────────────────────────────────────────────────────────
# Step 13: Mark setup complete
# ─────────────────────────────────────────────────────────────
wp option update pod_shop_setup_complete "1" --allow-root --path="$WP_PATH"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  ✅ WordPress Setup Complete!"
echo ""
echo "  URL:      $WP_URL"
echo "  Admin:    $WP_URL/wp-admin"
echo "  User:     $WP_ADMIN_USER"
echo "  GraphQL:  $WP_URL/graphql"
echo "═══════════════════════════════════════════════════════════"
echo ""

# ─────────────────────────────────────────────────────────────
# Step 14: Seed mock data
# ─────────────────────────────────────────────────────────────
echo "🌱 Seeding mock products..."
sh /scripts/mock-data.sh
