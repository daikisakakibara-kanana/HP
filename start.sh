#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

PORT="${PORT:-8080}"

if [[ ! -f img/logo.png ]]; then
  echo "→ 画像を生成・取得しています..."
  python3 scripts/generate-images.py
fi

echo "→ http://localhost:${PORT}/index.html で表示できます（Ctrl+C で終了）"
exec python3 -m http.server "$PORT" --bind 127.0.0.1
