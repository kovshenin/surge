#!/usr/bin/env bash

surge_repo_root() {
  cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd
}

surge_require_env_file() {
  local root_dir="$1"

  if [[ ! -f "$root_dir/.env" ]]; then
    printf 'Missing %s/.env. Copy .env.example first.\n' "$root_dir" >&2
    return 1
  fi
}

surge_load_env_file() {
  local env_file="$1"
  local line
  local key
  local value

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"

    if [[ -z "$line" || "${line:0:1}" == "#" ]]; then
      continue
    fi

    key="${line%%=*}"
    value="${line#*=}"
    key="${key%"${key##*[![:space:]]}"}"
    value="${value#"${value%%[![:space:]]*}"}"

    if [[ "$value" == \"*\" && "$value" == *\" ]]; then
      value="${value:1:${#value}-2}"
    elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
      value="${value:1:${#value}-2}"
    fi

    export "$key=$value"
  done < "$env_file"
}

surge_load_env() {
  local root_dir="$1"

  surge_require_env_file "$root_dir"
  surge_load_env_file "$root_dir/.env"
}

surge_compose() {
  local root_dir="$1"
  shift

  docker compose --project-directory "$root_dir" "$@"
}

surge_wp() {
  local root_dir="$1"
  shift

  surge_compose "$root_dir" run --rm wpcli "$@"
}

surge_site_url() {
  local root_dir="$1"

  surge_load_env "$root_dir"
  printf 'http://localhost:%s\n' "$WORDPRESS_PORT"
}

surge_wait_for_http() {
  local url="$1"
  local attempts=30
  local attempt

  for (( attempt=1; attempt<=attempts; attempt++ )); do
    if curl -fsS -o /dev/null "$url"; then
      return 0
    fi

    sleep 2
  done

  printf 'Timed out waiting for %s\n' "$url" >&2
  return 1
}

surge_bootstrap_wordpress() {
  local root_dir="$1"
  local site_url

  surge_load_env "$root_dir"
  surge_compose "$root_dir" up -d --wait db wordpress

  site_url="$(surge_site_url "$root_dir")"
  surge_wait_for_http "$site_url"

  if surge_wp "$root_dir" core is-installed >/dev/null 2>&1; then
    return 0
  fi

  surge_wp "$root_dir" core install \
    --url="$site_url" \
    --title="$WORDPRESS_SITE_TITLE" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL"
}
