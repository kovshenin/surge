#!/usr/bin/env bash

surge_plugin_version() {
  local plugin_file="$1"
  local version

  version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' "$plugin_file" | head -n 1)"

  if [[ -z "$version" ]]; then
    printf 'Could not read plugin version from %s\n' "$plugin_file" >&2
    return 1
  fi

  printf '%s\n' "$version"
}

surge_readme_stable_tag() {
  local readme_file="$1"
  local stable_tag

  stable_tag="$(sed -nE 's/^Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' "$readme_file" | head -n 1)"

  if [[ -z "$stable_tag" ]]; then
    printf 'Could not read stable tag from %s\n' "$readme_file" >&2
    return 1
  fi

  printf '%s\n' "$stable_tag"
}

surge_archive_name() {
  local mode="$1"
  local version="$2"
  local short_sha="${3:-}"

  case "$mode" in
    stable)
      printf 'surge-%s.zip\n' "$version"
      ;;
    preview)
      if [[ -z "$short_sha" ]]; then
        printf 'Preview builds require a short SHA.\n' >&2
        return 1
      fi
      printf 'surge-%s-dev+%s.zip\n' "$version" "$short_sha"
      ;;
    *)
      printf 'Unknown archive mode: %s\n' "$mode" >&2
      return 1
      ;;
  esac
}

surge_assert_stable_versions_match() {
  local root_dir="$1"
  local plugin_version
  local stable_tag

  plugin_version="$(surge_plugin_version "$root_dir/surge.php")"
  stable_tag="$(surge_readme_stable_tag "$root_dir/readme.txt")"

  if [[ "$plugin_version" != "$stable_tag" ]]; then
    printf 'Stable tag mismatch: plugin=%s readme=%s\n' "$plugin_version" "$stable_tag" >&2
    return 1
  fi
}

surge_short_sha() {
  local root_dir="$1"

  git -C "$root_dir" rev-parse --short=7 HEAD
}
