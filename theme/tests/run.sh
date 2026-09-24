#!/usr/bin/env bash
set -euo pipefail

theme_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

jq empty "$theme_dir/theme.json"
node "$theme_dir/tests/validate-theme.mjs"
node "$theme_dir/tests/validate-patterns.mjs"

while IFS= read -r php_file; do
	php -l "$php_file" >/dev/null
done < <(find "$theme_dir" -type f -name '*.php' | sort)

echo "Validaciones del theme completadas."
