#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/wp.sh"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

cat > "$tmp_dir/.env" <<'EOF'
WORDPRESS_PORT=8899
WORDPRESS_SITE_TITLE=Surge Local
WORDPRESS_ADMIN_USER=admin
EOF

surge_load_env_file "$tmp_dir/.env"

if [[ "${WORDPRESS_PORT:-}" != "8899" ]]; then
  printf 'Expected WORDPRESS_PORT to load from .env file.\n' >&2
  exit 1
fi

if [[ "${WORDPRESS_SITE_TITLE:-}" != "Surge Local" ]]; then
  printf 'Expected WORDPRESS_SITE_TITLE to preserve spaces.\n' >&2
  exit 1
fi
