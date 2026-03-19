#!/usr/bin/env bash

surge_stage_package() {
  local root_dir="$1"
  local stage_root="$2"
  local plugin_dir="$stage_root/surge"

  rm -rf "$stage_root"
  mkdir -p "$plugin_dir"

  cp "$root_dir/surge.php" "$plugin_dir/surge.php"
  cp "$root_dir/uninstall.php" "$plugin_dir/uninstall.php"
  cp "$root_dir/readme.txt" "$plugin_dir/readme.txt"
  cp "$root_dir/LICENSE" "$plugin_dir/LICENSE"
  cp -R "$root_dir/include" "$plugin_dir/include"

  if [[ -d "$root_dir/build" ]]; then
    cp -R "$root_dir/build" "$plugin_dir/build"
  fi

  if [[ -d "$root_dir/languages" ]]; then
    cp -R "$root_dir/languages" "$plugin_dir/languages"
  fi

  if [[ -d "$root_dir/vendor" ]]; then
    cp -R "$root_dir/vendor" "$plugin_dir/vendor"
  fi
}
