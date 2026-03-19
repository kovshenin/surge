#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-stable}"
SHORT_SHA="${2:-}"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/version.sh"
# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/package.sh"

VERSION="$(surge_plugin_version "$ROOT_DIR/surge.php")"

if [[ "$MODE" == "stable" ]]; then
  surge_assert_stable_versions_match "$ROOT_DIR"
else
  SHORT_SHA="${SHORT_SHA:-$(surge_short_sha "$ROOT_DIR")}"
fi

ARCHIVE_NAME="$(surge_archive_name "$MODE" "$VERSION" "$SHORT_SHA")"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$(mktemp -d)"
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE_NAME"

trap 'rm -rf "$STAGE_DIR"' EXIT

mkdir -p "$DIST_DIR"
rm -f "$ARCHIVE_PATH"

surge_stage_package "$ROOT_DIR" "$STAGE_DIR"

(
  cd "$STAGE_DIR"
  zip -qr "$ARCHIVE_PATH" surge
)

printf '%s\n' "$ARCHIVE_PATH"
