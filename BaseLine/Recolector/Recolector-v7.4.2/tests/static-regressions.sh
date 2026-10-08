#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

expected_plugins_sha="0acbcfef685db11c85d41ea1b2aea68d447bef2731bd8a0dc9f3ea4bfe77eada"
actual_plugins_sha="$(sha256sum scripts/source-plugins.php | awk '{print $1}')"
[[ "$actual_plugins_sha" == "$expected_plugins_sha" ]] || {
  echo "source-plugins.php cambió respecto de 7.4.1" >&2
  exit 1
}

rg -q "'theme' => \(string\).*course->theme" scripts/phase5-lib.php
rg -q "'theme' => \(string\).*seed\['theme'\]" scripts/phase5-lib.php
rg -q "theme IS NOT NULL AND theme <> ''" scripts/phase6-inventory.php
rg -q "COLLECTOR_THEME_SCHEMA_VERSION" scripts/source-seal.php
rg -q "'theme_inventory' => COLLECTOR_THEME_SCHEMA_VERSION" scripts/source-seal.php
rg -q "'7.4.1-linux'" scripts/source-export.php
rg -q "collector_theme_academic_course_inventory" scripts/source-backup-course.php
rg -q "collector_theme_academic_course_inventory" scripts/validate-package.php
rg -q '\$criticalcomplete = \$globalstate.*' scripts/phase6-inventory.php
rg -q '\$themecontractrequired = \$themecapability ===' scripts/validate-package.php

if rg -n "\b(?:UPDATE|INSERT|DELETE|ALTER|DROP|TRUNCATE)\b" \
    scripts/phase6-inventory.php scripts/theme-contract.php; then
  echo "La recolección de themes dejó de ser read-only" >&2
  exit 1
fi
if rg -n "https?://|curl|wget" scripts/phase6-inventory.php scripts/theme-contract.php; then
  echo "La recolección de themes intenta usar red externa" >&2
  exit 1
fi

echo "STATIC_REGRESSIONS_OK"
