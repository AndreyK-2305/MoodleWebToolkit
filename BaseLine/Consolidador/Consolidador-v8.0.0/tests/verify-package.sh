#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

sha256sum --check --strict FILES.sha256
python3 - <<'PY'
from pathlib import Path
import re

entries = Path('FILES.sha256').read_text().splitlines()
listed = set()
for line in entries:
    match = re.fullmatch(r'[a-f0-9]{64}  (\./[^\n]+)', line)
    if match is None or match.group(1) in listed:
        raise SystemExit('FILES.sha256 contiene filas inválidas o repetidas')
    listed.add(match.group(1))
# RC8_MUTABLE_DISTRIBUTION_FILES
# Estos archivos forman parte obligatoria de la distribución, pero el propio
# Consolidador los materializa/actualiza durante importación. Por tanto no
# pertenecen al conjunto criptográficamente inmutable.
mutable = {
    './config/phase5-pilot-package.json',
    './config/phase6-batch.json',
}

allfiles = {
    './' + path.as_posix()
    for path in Path('.').rglob('*')
    if path.is_file() and path.as_posix() != 'FILES.sha256'
}

missingmutable = sorted(mutable - allfiles)
if missingmutable:
    raise SystemExit(
        f'DISTRIBUTION_MUTABLE_REQUIRED_MISSING: {missingmutable}'
    )

unexpectedmutablehashes = sorted(mutable & listed)
if unexpectedmutablehashes:
    raise SystemExit(
        'FILES.sha256 no debe sellar archivos operativos mutables: '
        f'{unexpectedmutablehashes}'
    )

immutableactual = allfiles - mutable

if listed != immutableactual:
    raise SystemExit(
        f'FILES.sha256 incompleto: '
        f'faltan={sorted(immutableactual-listed)} '
        f'extras={sorted(listed-immutableactual)}'
    )
for path in Path('.').rglob('*'):
    if not path.is_file():
        continue
    name = path.as_posix()
    if ('.git' in path.parts or path.name in ('.env',) or
            path.suffix.lower() in ('.bak', '.pem', '.zip', '.sql') or
            (path.parts[0] == 'exports' and name != 'exports/LEEME.txt') or
            (path.parts[0] == 'reports' and name != 'reports/LEEME.txt') or
            (path.parts[0] == 'copias' and name != 'copias/COLOQUE-AQUI-LOS-ZIP.txt')):
        raise SystemExit(f'DISTRIBUTION_CONTENT_INVALID: {name}')
PY
printf '%s\n' FILES_SHA256_OK
find . -type f -name '*.sh' -print0 | xargs -0 -r -n 1 bash -n
printf '%s\n' BASH_LINT_OK
find . -type f -name '*.php' -print0 | xargs -0 -r -n 1 php -l
printf '%s\n' PHP_LINT_OK
php tests/contracts.php
php tests/rc2-contracts.php
php tests/rc11-email-normalization.php
php tests/rc11-resolution-regression.php
php tests/rc12-managed-config.php
php tests/rc12-shared-email.php
php tests/rc10-plugin-pins.php
php tests/rc14-module-identities.php
php tests/rc15-relation-reconciliation.php
php tests/rc16-file-context-population.php
php tests/rc17-activity-contexts.php
php tests/rc17-editpdf-contract.php
php tests/rc18-course-completions.php
php tests/rc19-phase6-tar-users.php
php tests/rc20-phase6-user-classification.php
php tests/rc21-phase6-mbz-user-references.php
php tests/rc22-legacy-random-normalization.php
php tests/rc23-random-normalization.php
php tests/rc23-phase6-effective-inventory.php
php tests/rc23-quiz-attempt-contract.php
php tests/rc24-phase13-contracts.php
php tests/v8-contracts.php
php tests/v8-fuzzy-reconciliation.php
php tests/v8-rc1-regressions.php
printf '%s\n' RC1_REGRESSION_OK
php tests/v8-rc2-degradation.php
php tests/v8-rc2-incremental-degradation.php
php tests/v8-rc2-plugin-pin-executor.php
php tests/v8-native-restore-delegation.php
php tests/v8-rc4-plugin-paths.php
php tests/v8-rc4-random-cases.php
php tests/v8-rc5-quiz-attempts.php
php tests/v8-rc5-precheck.php
php tests/v8-rc5-historical-users.php
php tests/v8-rc5-random-order.php

pwsh -NoLogo -NoProfile -Command '
  $issues = @()
  Get-ChildItem scripts -Filter *.ps1 | ForEach-Object {
    $tokens = $null
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile(
      $_.FullName, [ref]$tokens, [ref]$errors
    )
    if ($errors) { $issues += $errors }
  }
  if ($issues.Count -gt 0) { $issues | Format-List; exit 1 }
'
printf '%s\n' POWERSHELL_PARSE_OK
pwsh -NoLogo -NoProfile -File tests/rc2-powershell.ps1
pwsh -NoLogo -NoProfile -File tests/rc3-powershell.ps1
pwsh -NoLogo -NoProfile -File tests/rc7-plugin-dependencies.ps1
pwsh -NoLogo -NoProfile -File tests/rc10-release.ps1
pwsh -NoLogo -NoProfile -File tests/rc9-moodle-versions.ps1
pwsh -NoLogo -NoProfile -File tests/rc9-revalidate-integration.ps1
pwsh -NoLogo -NoProfile -File tests/rc10-plugin-retry.ps1
pwsh -NoLogo -NoProfile -File tests/rc3-integration.ps1
pwsh -NoLogo -NoProfile -File tests/rc4-import.ps1
pwsh -NoLogo -NoProfile -File tests/rc4-wizard-retry.ps1
pwsh -NoLogo -NoProfile -File tests/rc13-wizard-paths.ps1
pwsh -NoLogo -NoProfile -File tests/rc5-startup.ps1
pwsh -NoLogo -NoProfile -File tests/v8-rc2-powershell.ps1
pwsh -NoLogo -NoProfile -File tests/v8-rc2-plugin-flow.ps1
pwsh -NoLogo -NoProfile -File tests/v8-plugin-serialization.ps1
pwsh -NoLogo -NoProfile -File tests/v8-worker-continuation.ps1
pwsh -NoLogo -NoProfile -File tests/v8-rc4-source-routing.ps1
pwsh -NoLogo -NoProfile -File tests/v8-rc4-plugin-location.ps1
bash tests/rc5-launcher.sh
bash tests/rc6-stop.sh
bash tests/v8-theme-policy.sh
bash tests/v8-rc2-theme-transport.sh
bash tests/v8-identity-wrapper.sh
bash tests/v8-rc4-phase13-restart.sh
python3 tests/v8-runtime-tags.py
python3 tests/v8-rc1-static.py
python3 tests/v8-rc2-static.py
python3 tests/v8-rc2-plan-contracts.py
python3 tests/v8-rc4-hotfix-contracts.py
python3 tests/v8-rc8-news-forum-contract.py
python3 tests/v8-rc8-export-root-contract.py
python3 tests/v8-rc8-mutable-integrity.py
python3 tests/v8-rc9-background-linger.py
python3 tests/v8-rc10-progress-email.py
python3 tests/v8-rc11-progress-lifecycle.py
python3 tests/v8-rc12-background-wrapper-permissions.py
printf '%s\n' RC2_REGRESSION_OK
python3 tests/rc12-env-migration.py
bash tests/v8-live-integration.sh

python3 - <<'PY'
import json
from pathlib import Path
import yaml

for path in Path('.').rglob('*.json'):
    if 'exports' not in path.parts and 'reports' not in path.parts:
        json.loads(path.read_text(encoding='utf-8-sig'))
yaml.safe_load(Path('compose.yaml').read_text())
print('JSON_YAML_OK')
PY

if command -v shellcheck >/dev/null 2>&1; then
    find . -type f -name '*.sh' -print0 | xargs -0 -r shellcheck
fi
printf '%s\n' PHASE12_LIGHTWEIGHT_OK
printf '%s\n' PERMISSIONS_OK
printf '%s\n' BACKGROUND_OK
printf '%s\n' CHECKPOINT_RESUME_OK
printf '%s\n' POST_WRITE_MONOTONICITY_OK
printf '%s\n' RC1_FUNCTIONAL_REGRESSION_OK
printf '%s\n' RC2_PIPELINE_OK
printf '%s\n' DISTRIBUTION_OK
