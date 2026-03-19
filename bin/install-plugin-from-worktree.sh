#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "$ROOT_DIR/bin/lib/wp.sh"

surge_bootstrap_wordpress "$ROOT_DIR"

surge_compose "$ROOT_DIR" exec -T wordpress sh -lc '
  rm -rf /var/www/html/wp-content/plugins/surge
  ln -s /workspace/surge /var/www/html/wp-content/plugins/surge
'

surge_wp "$ROOT_DIR" plugin activate surge >/dev/null

printf 'Activated Surge from the working tree.\n'
