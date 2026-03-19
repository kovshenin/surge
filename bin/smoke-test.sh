#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/wp.sh"

SITE_URL="$(surge_site_url "$ROOT_DIR")"
CACHE_DIR="/var/www/html/wp-content/cache/surge"
ADVANCED_CACHE_FILE="/var/www/html/wp-content/advanced-cache.php"

surge_bootstrap_wordpress "$ROOT_DIR"

surge_wp "$ROOT_DIR" plugin is-active surge >/dev/null

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test -f '$ADVANCED_CACHE_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q 'namespace Surge;' '$ADVANCED_CACHE_FILE'"

FIRST_HEADERS="$(mktemp)"
SECOND_HEADERS="$(mktemp)"
trap 'rm -f "$FIRST_HEADERS" "$SECOND_HEADERS"' EXIT

curl -fsSI "$SITE_URL/" -o "$FIRST_HEADERS"
curl -fsSI "$SITE_URL/" -o "$SECOND_HEADERS"

grep -iq '^X-Cache: miss' "$FIRST_HEADERS"
grep -iq '^X-Cache: hit' "$SECOND_HEADERS"

CACHE_FILE_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "find '$CACHE_DIR' -type f ! -name 'flags.json.php' | wc -l | tr -d ' '"
)"

if [[ "${CACHE_FILE_COUNT:-0}" -lt 1 ]]; then
  printf 'Expected cache files to exist after priming request.\n' >&2
  exit 1
fi

surge_wp "$ROOT_DIR" surge flush --delete >/dev/null

POST_FLUSH_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "find '$CACHE_DIR' -type f ! -name 'flags.json.php' | wc -l | tr -d ' '"
)"

if [[ "${POST_FLUSH_COUNT:-0}" -ne 0 ]]; then
  printf 'Expected cache files to be removed after wp surge flush --delete.\n' >&2
  exit 1
fi

printf 'Smoke test passed.\n'
