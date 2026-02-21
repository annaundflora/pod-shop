#!/bin/sh
# =============================================================
# POD Shop – Mock Data Seeder
# Creates: categories, product attributes, variable products,
#          legal pages
# Idempotent: safe to run multiple times
# =============================================================
set -e

WP_PATH=/var/www/html

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  🌱 POD Shop Mock Data Seeder"
echo "═══════════════════════════════════════════════════════════"
echo ""

# Idempotency check
if wp option get pod_shop_mock_data_seeded --allow-root --path="$WP_PATH" 2>/dev/null | grep -q "1"; then
  echo "✅ Mock data already seeded – skipping"
  exit 0
fi

# Run PHP seed script via WP-CLI eval-file
wp eval-file /scripts/seed-products.php --allow-root --path="$WP_PATH"

# Mark as seeded
wp option update pod_shop_mock_data_seeded "1" --allow-root --path="$WP_PATH"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  ✅ Mock data seeded successfully!"
echo "═══════════════════════════════════════════════════════════"
echo ""
