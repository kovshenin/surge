#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/version.sh"

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"

  if [[ "$expected" != "$actual" ]]; then
    printf 'assertion failed: %s\nexpected: %s\nactual: %s\n' "$message" "$expected" "$actual" >&2
    exit 1
  fi
}

plugin_version="$(surge_plugin_version "$ROOT_DIR/surge.php")"
stable_tag="$(surge_readme_stable_tag "$ROOT_DIR/readme.txt")"
preview_name="$(surge_archive_name preview "$plugin_version" "abcdef1")"
stable_name="$(surge_archive_name stable "$plugin_version" "ignored")"

assert_eq "1.1.0" "$plugin_version" "plugin version should be read from surge.php"
assert_eq "1.1.0" "$stable_tag" "stable tag should be read from readme.txt"
assert_eq "surge-1.1.0-dev+abcdef1.zip" "$preview_name" "preview archive naming should include short sha"
assert_eq "surge-1.1.0.zip" "$stable_name" "stable archive naming should omit dev suffix"

surge_assert_stable_versions_match "$ROOT_DIR"
