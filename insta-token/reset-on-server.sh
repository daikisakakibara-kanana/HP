#!/bin/bash
# さくら VPS 上で実行: bash reset-on-server.sh
set -euo pipefail

BASE="${1:-/var/www/html/insta-token}"
BRANCH="cursor/aburamaru-design-lp-9e6c"
RAW="https://raw.githubusercontent.com/daikisakakibara-kanana/HP/${BRANCH}/insta-token"

echo "==> BASE: $BASE"

rm -f "$BASE/storage/instagram-token-latest.json" \
      "$BASE/storage/instagram-token-latest.json.tmp" 2>/dev/null || true

mkdir -p "$BASE/storage"
chmod 750 "$BASE/storage" 2>/dev/null || true

echo "==> Download callback.php + health.php"
curl -fsSL -o "$BASE/callback.php" "$RAW/callback.php"
curl -fsSL -o "$BASE/health.php" "$RAW/health.php"
chmod 644 "$BASE/callback.php" "$BASE/health.php"

if ! php -m 2>/dev/null | grep -q curl; then
  echo "==> Installing php-curl..."
  apt-get update -qq
  apt-get install -y php-curl
  systemctl restart apache2
fi

echo "==> PHP modules:"
php -m | grep -E '^(curl|json|openssl)$' || true

echo "==> Done. Open:"
echo "   https://insta-api.kanana-tech.jp/insta-token/callback.php"
echo "   (footer must show: callback v2.2.0)"
