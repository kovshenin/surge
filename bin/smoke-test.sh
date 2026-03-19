#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/wp.sh"

SITE_URL="$(surge_site_url "$ROOT_DIR")"
CACHE_DIR="/var/www/html/wp-content/cache/surge"
ADVANCED_CACHE_FILE="/var/www/html/wp-content/advanced-cache.php"
OBSERVABILITY_DIR="$CACHE_DIR/observability"
DEBUG_SESSION_FILE="$OBSERVABILITY_DIR/debug-session.json.php"
REQUEST_LOG_FILE="$OBSERVABILITY_DIR/requests.log.php"
ADMIN_LOG_FILE="$OBSERVABILITY_DIR/admin.log.php"
INVALIDATION_LOG_FILE="$OBSERVABILITY_DIR/invalidations.log.php"

surge_bootstrap_wordpress "$ROOT_DIR"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
  "if test -d '$OBSERVABILITY_DIR'; then find '$OBSERVABILITY_DIR' -maxdepth 1 -type f \\( -name '*.log.php' -o -name 'debug-session.json.php' \\) -delete; fi"

surge_wp "$ROOT_DIR" option delete surge_observability_debug_session >/dev/null 2>&1 || true

surge_wp "$ROOT_DIR" plugin is-active surge >/dev/null
surge_wp "$ROOT_DIR" surge flush --delete >/dev/null

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test -f '$ADVANCED_CACHE_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q 'namespace Surge;' '$ADVANCED_CACHE_FILE'"

FIRST_HEADERS="$(mktemp)"
SECOND_HEADERS="$(mktemp)"
trap 'rm -f "$FIRST_HEADERS" "$SECOND_HEADERS"' EXIT

curl -fsS -D "$FIRST_HEADERS" -o /dev/null "$SITE_URL/"
curl -fsS -D "$SECOND_HEADERS" -o /dev/null "$SITE_URL/"

grep -iq '^X-Cache: miss' "$FIRST_HEADERS"
grep -iq '^X-Cache: hit' "$SECOND_HEADERS"

CACHE_FILE_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "find '$CACHE_DIR' -type f ! -name 'flags.json.php' ! -path '$OBSERVABILITY_DIR/*' | wc -l | tr -d ' '"
)"

if [[ "${CACHE_FILE_COUNT:-0}" -lt 1 ]]; then
  printf 'Expected cache files to exist after priming request.\n' >&2
  exit 1
fi

DEBUG_ENABLED_AT="$(date -u +%s)"
DEBUG_EXPIRES_AT="$((DEBUG_ENABLED_AT + 3600))"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "
  mkdir -p '$OBSERVABILITY_DIR' &&
  chmod 0777 '$OBSERVABILITY_DIR' &&
  printf '<?php exit; ?>\n{\"duration\":\"1h\",\"enabledAt\":${DEBUG_ENABLED_AT},\"expiresAt\":${DEBUG_EXPIRES_AT}}\n' > '$DEBUG_SESSION_FILE' &&
  chmod 0666 '$DEBUG_SESSION_FILE'
"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test -f '$DEBUG_SESSION_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q '\"duration\":\"1h\"' '$DEBUG_SESSION_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q '\"expiresAt\":$DEBUG_EXPIRES_AT' '$DEBUG_SESSION_FILE'"

curl -fsS -o /dev/null "$SITE_URL/"

ACTIVE_REQUEST_LOG_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "if test -f '$REQUEST_LOG_FILE'; then sed '1d' '$REQUEST_LOG_FILE' | grep -c '^{' ; else printf 0; fi"
)"

if [[ "${ACTIVE_REQUEST_LOG_COUNT:-0}" -lt 1 ]]; then
  printf 'Expected request samples to be written while debug capture is active.\n' >&2
  exit 1
fi

surge_wp "$ROOT_DIR" surge flush >/dev/null

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test -f '$ADMIN_LOG_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test -f '$INVALIDATION_LOG_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q '\"action\":\"flush\"' '$ADMIN_LOG_FILE'"
surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "grep -q '\"scope\":\"path\"' '$INVALIDATION_LOG_FILE'"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "rm -f '$DEBUG_SESSION_FILE'"
surge_wp "$ROOT_DIR" option delete surge_observability_debug_session >/dev/null 2>&1 || true

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc "test ! -f '$DEBUG_SESSION_FILE'"

curl -fsS -o /dev/null "$SITE_URL/"

POST_STOP_REQUEST_LOG_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "if test -f '$REQUEST_LOG_FILE'; then sed '1d' '$REQUEST_LOG_FILE' | grep -c '^{' ; else printf 0; fi"
)"

if [[ "$POST_STOP_REQUEST_LOG_COUNT" -ne "$ACTIVE_REQUEST_LOG_COUNT" ]]; then
  printf 'Expected request samples to stop after the debug session ends.\n' >&2
  exit 1
fi

surge_wp "$ROOT_DIR" surge flush --delete >/dev/null

POST_FLUSH_COUNT="$(
  surge_compose "$ROOT_DIR" exec -T wordpress sh -lc \
    "find '$CACHE_DIR' -type f ! -name 'flags.json.php' ! -path '$OBSERVABILITY_DIR/*' | wc -l | tr -d ' '"
)"

if [[ "${POST_FLUSH_COUNT:-0}" -ne 0 ]]; then
  printf 'Expected cache files to be removed after wp surge flush --delete.\n' >&2
  exit 1
fi

printf 'Smoke test passed.\n'
