#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

[[ "$(< VERSION.txt)" == "7.4.2-linux" ]]
rg -q 'readonly collector_version="7\.4\.2-linux"' EXPORTAR-ORIGEN.sh
rg -q 'readonly collector_version="7\.4\.2-linux"' VALIDAR-PAQUETE.sh
rg -q "COLLECTOR_VALIDATOR_VERSION = '7\.4\.2-linux'" scripts/validate-package.php
rg -q "'collector_version' => '7\.4\.2-linux'" scripts/source-seal.php

sha256sum --check --strict FILES.sha256

while IFS= read -r file; do
  php -l "$file" >/dev/null
done < <(find . -type f -name '*.php' -print | LC_ALL=C sort)

while IFS= read -r file; do
  bash -n "$file"
done < <(find . -type f -name '*.sh' -print | LC_ALL=C sort)

while IFS= read -r file; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$file"
done < <(find . -type f -name '*.json' -print | LC_ALL=C sort)

php tests/theme-contracts.php
php tests/resume-theme-metadata.php
bash tests/static-regressions.sh

listed="$(mktemp)"
actual="$(mktemp)"
trap 'rm -f "$listed" "$actual"' EXIT
awk '{sub(/^\*/, "", $2); print $2}' FILES.sha256 | LC_ALL=C sort > "$listed"
find . -type f ! -path './FILES.sha256' -printf '%P\n' | LC_ALL=C sort > "$actual"
diff -u "$listed" "$actual"

echo "DISTRIBUTION_OK"
