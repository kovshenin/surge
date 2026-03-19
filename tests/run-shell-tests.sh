#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

"$ROOT_DIR/tests/test-version-lib.sh"
"$ROOT_DIR/tests/test-package-lib.sh"
"$ROOT_DIR/tests/test-wp-lib.sh"
