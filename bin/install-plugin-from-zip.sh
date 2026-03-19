#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_PATH="${1:-}"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/wp.sh"

if [[ -z "$ZIP_PATH" ]]; then
  ZIP_PATH="$(ls -t "$ROOT_DIR"/dist/surge-*.zip 2>/dev/null | head -n 1 || true)"
fi

if [[ -z "$ZIP_PATH" || ! -f "$ZIP_PATH" ]]; then
  printf 'Provide a plugin ZIP path or build one in dist/ first.\n' >&2
  exit 1
fi

surge_bootstrap_wordpress "$ROOT_DIR"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc 'rm -rf /var/www/html/wp-content/plugins/surge'
surge_wp "$ROOT_DIR" plugin install "/workspace/surge/${ZIP_PATH#"$ROOT_DIR"/}" --force --activate >/dev/null

printf 'Activated Surge from %s.\n' "$ZIP_PATH"
