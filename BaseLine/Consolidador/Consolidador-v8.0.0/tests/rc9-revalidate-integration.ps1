$ErrorActionPreference = 'Stop'
function Assert-RC9-Flow($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC9_REVALIDATE_FAILED: $Label" }
}
$project = Join-Path ([IO.Path]::GetTempPath()) (
    'rc9-revalidate-' + [guid]::NewGuid().ToString('N'))
$ids = @('posgrados-2025-05-02-directo', 'pregrado-2026-03-04-directo')
$oldPath = $env:PATH
try {
    New-Item -ItemType Directory -Force -Path "$project/scripts", "$project/config", `
        "$project/reports", "$project/docker/custom-plugins", "$project/bin" | Out-Null
    foreach ($id in $ids) {
        New-Item -ItemType Directory -Force -Path "$project/exports/packages/$id" |
            Out-Null
    }
    foreach ($file in @('Common.ps1', 'ConfigParser.ps1', 'ConfigAccess.ps1',
            'PluginPreflight.ps1', 'PluginIntervention.ps1', 'MoodleVersion.ps1',
            'audit-package-plugins.ps1')) {
        Copy-Item "$PSScriptRoot/../scripts/$file" "$project/scripts/$file"
    }
    @"
version: 1
project_name: rc9-test
mode: production
sources:
  - id: $($ids[0])
    name: Posgrados
    service: posgrados
    url: https://posgrados.example.edu
  - id: $($ids[1])
    name: Pregrado
    service: pregrado
    url: https://pregrado.example.edu
target:
  id: target
  name: Destino
  service: target
  url: https://moodle.example.edu
"@ | Set-Content -LiteralPath "$project/config.yaml" -Encoding utf8
    '{"schema_version":"1.0","plugins":[]}' | Set-Content `
        "$project/docker/custom-plugins/approved-plugins.json" -Encoding utf8
    @{ config_sha256 = (Get-FileHash "$project/config.yaml" -Algorithm SHA256).Hash.ToLowerInvariant() } |
        ConvertTo-Json | Set-Content "$project/reports/configuration-confirmation.json" -Encoding utf8
    $fixture = Get-Content -Raw "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" |
        ConvertFrom-Json
    for ($index = 0; $index -lt $ids.Count; $index++) {
        $id = $ids[$index]
        $source = $fixture.sources[0].inventory | ConvertTo-Json -Depth 30 |
            ConvertFrom-Json
        $source | Add-Member -NotePropertyName source_id -NotePropertyValue $id
        $source | Add-Member -NotePropertyName write_performed -NotePropertyValue $false
        $source.moodle_version = if ($index -eq 0) { 2024100702.01 } else {
            '2024100702.01'
        }
        $pluginsPath = "$project/exports/packages/$id/plugins.json"
        $source | ConvertTo-Json -Depth 30 | Set-Content $pluginsPath -Encoding utf8
        @{ source_id = $id; plugins_sha256 = (
            Get-FileHash $pluginsPath -Algorithm SHA256).Hash.ToLowerInvariant() } |
            ConvertTo-Json | Set-Content "$project/exports/packages/$id/manifest.json" -Encoding utf8
    }
    $pinPath = "$project/docker/custom-plugins/approved-plugins.json"
    $fixture.before.approved_plugins_sha256 = (
        Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before.moodle_version = 2026042001
    $live = "$project/live-inventory.json"
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $live -Encoding utf8
    @'
#!/usr/bin/env bash
set -euo pipefail
if [[ " $* " == *" --output=- "* ]]; then
    cat "$MOCK_LIVE_INVENTORY"
elif [[ " $* " == *" upgrade.php "* ]]; then
    printf '%s\n' 'Upgrade complete'
elif [[ " $* " == *" php -r "* ]]; then
    printf '%s\n' 'DESTINATION_READY'
fi
'@ | Set-Content "$project/bin/docker" -Encoding utf8
    & chmod +x "$project/bin/docker"
    $env:MOCK_LIVE_INVENTORY = $live
    $env:PATH = "$project/bin:$oldPath"
    $audit = "$project/scripts/audit-package-plugins.ps1"
    $discovered = & $audit -Mode Discover
    Assert-RC9-Flow ($discovered.status -eq 'WAITING_USER_ACTION') 'BEFORE espera intervención'
    $phase2 = "$project/exports/phase2"
    $beforeHash = (Get-FileHash "$phase2/plugin_inventory_before.json" -Algorithm SHA256).Hash
    $ready = & $audit -Mode Revalidate
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    $checkpoint = Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" | ConvertFrom-Json
    Assert-RC9-Flow ($ready.status -eq 'READY_FOR_APPROVAL' -and
        $technical.passed -and $technical.issues.Count -eq 0 -and
        $checkpoint.status -eq 'READY_FOR_APPROVAL' -and
        (Test-Path "$phase2/plugin_inventory_after.json") -and
        (Test-Path "$phase2/plugin_inventory_diff.json")) `
        'R con ambos orígenes decimales genera AFTER, diff, validación y READY'
    $fixture.before.moodle_version = '2024100702.foo'
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $live -Encoding utf8
    $blocked = & $audit -Mode Revalidate
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    Assert-RC9-Flow ($blocked.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @($technical.issues | Where-Object {
            $_.field -eq 'moodle_version' -and $_.component -eq 'target' -and
            $_.message -like 'MOODLE_VERSION_INVALID:*'
        }).Count -eq 1) 'Moodle destino inválido se registra con error propio'
    $fixture.before.moodle_version = 2026042001
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $live -Encoding utf8
    $recovered = & $audit -Mode Revalidate
    Assert-RC9-Flow ($recovered.status -eq 'READY_FOR_APPROVAL' -and
        (Get-FileHash "$phase2/plugin_inventory_before.json" -Algorithm SHA256).Hash -eq $beforeHash) `
        'R reanuda y conserva BEFORE inmutable'
    Write-Output 'RC9_REVALIDATE_OK sources=2 decimal=valid after=sealed diff=written invalid=controlled resume=ready'
} finally {
    $env:PATH = $oldPath
    Remove-Item Env:MOCK_LIVE_INVENTORY -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $project -Recurse -Force -ErrorAction SilentlyContinue
}
