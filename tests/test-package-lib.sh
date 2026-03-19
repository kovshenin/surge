#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/package.sh"

assert_exists() {
  local path="$1"
  local message="$2"

  if [[ ! -e "$path" ]]; then
    printf 'assertion failed: %s\nmissing path: %s\n' "$message" "$path" >&2
    exit 1
  fi
}

assert_not_exists() {
  local path="$1"
  local message="$2"

  if [[ -e "$path" ]]; then
    printf 'assertion failed: %s\nunexpected path: %s\n' "$message" "$path" >&2
    exit 1
  fi
}

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

stage_dir="$tmp_dir/stage"
surge_stage_package "$ROOT_DIR" "$stage_dir"

assert_exists "$stage_dir/surge/surge.php" "stage should include plugin bootstrap"
assert_exists "$stage_dir/surge/uninstall.php" "stage should include uninstall hook"
assert_exists "$stage_dir/surge/include/common.php" "stage should include runtime PHP files"
assert_exists "$stage_dir/surge/build/admin.js" "stage should include built frontend assets when present"
assert_exists "$stage_dir/surge/readme.txt" "stage should include readme"
assert_exists "$stage_dir/surge/LICENSE" "stage should include license"
assert_not_exists "$stage_dir/surge/docs" "stage should exclude repo docs"
assert_not_exists "$stage_dir/surge/.agents" "stage should exclude agent files"
assert_not_exists "$stage_dir/surge/.gitignore" "stage should exclude repo-only files"
