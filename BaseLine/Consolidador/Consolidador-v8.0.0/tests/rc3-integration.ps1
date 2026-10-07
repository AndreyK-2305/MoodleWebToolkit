$ErrorActionPreference = 'Stop'
function Assert-Flow($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC3_FLOW_FAILED $Label" }
}
$project = Join-Path ([IO.Path]::GetTempPath()) ("rc3-plugin-test-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path "$project/scripts", "$project/config", `
    "$project/reports", "$project/exports/packages/origen_a", "$project/docker/custom-plugins", `
    "$project/bin" | Out-Null
try {
    foreach ($file in @('Common.ps1', 'ConfigParser.ps1', 'ConfigAccess.ps1',
            'PluginPreflight.ps1', 'PluginIntervention.ps1', 'MoodleVersion.ps1',
            'audit-package-plugins.ps1')) {
        Copy-Item "$PSScriptRoot/../scripts/$file" "$project/scripts/$file"
    }
    @'
version: 1
project_name: rc3-test
mode: production
sources:
  - id: origen_a
    name: Origen
    service: origen
    url: https://origen.example.edu
target:
  id: target
  name: Destino
  service: target
  url: https://moodle.example.edu
'@ | Set-Content -LiteralPath "$project/config.yaml" -Encoding utf8
    '{"schema_version":"1.0","plugins":[]}' | Set-Content `
        -LiteralPath "$project/docker/custom-plugins/approved-plugins.json" -Encoding utf8
    $cfgsha = (Get-FileHash "$project/config.yaml" -Algorithm SHA256).Hash.ToLowerInvariant()
    @{ config_sha256 = $cfgsha } | ConvertTo-Json | Set-Content `
        -LiteralPath "$project/reports/configuration-confirmation.json" -Encoding utf8
    $fixture = Get-Content -Raw -Encoding utf8 `
        "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" | ConvertFrom-Json
    $source = $fixture.sources[0].inventory
    $source | Add-Member -NotePropertyName source_id -NotePropertyValue 'origen_a'
    $source | Add-Member -NotePropertyName write_performed -NotePropertyValue $false
    $source | ConvertTo-Json -Depth 30 | Set-Content `
        -LiteralPath "$project/exports/packages/origen_a/plugins.json" -Encoding utf8
    @{ source_id = 'origen_a'; plugins_sha256 = `
        (Get-FileHash "$project/exports/packages/origen_a/plugins.json" `
            -Algorithm SHA256).Hash.ToLowerInvariant() } | ConvertTo-Json | Set-Content `
        -LiteralPath "$project/exports/packages/origen_a/manifest.json" -Encoding utf8
    $script:livePath = "$project/live-inventory.json"
    $pinsha = (Get-FileHash "$project/docker/custom-plugins/approved-plugins.json" `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before.approved_plugins_sha256 = $pinsha
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    @'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MOCK_DOCKER_LOG"
if [[ " $* " == *" --output=- "* ]]; then
    cat "$MOCK_LIVE_INVENTORY"
elif [[ " $* " == *" upgrade.php "* ]]; then
    printf '%s\n' 'Upgrade complete'
elif [[ " $* " == *" php -r "* ]]; then
    printf '%s\n' 'DESTINATION_READY'
fi
'@ | Set-Content "$project/bin/docker" -Encoding utf8
    & chmod +x "$project/bin/docker"
    $env:MOCK_DOCKER_LOG = "$project/docker-invocations.txt"
    $env:MOCK_LIVE_INVENTORY = $script:livePath
    $oldPath = $env:PATH
    $env:PATH = "$project/bin:$oldPath"
    $audit = "$project/scripts/audit-package-plugins.ps1"
    @('source_requirement,target_components', 'mod_vpl,mod_vpl2') |
        Set-Content "$project/config/plugin_equivalences.csv" -Encoding utf8
    $initial = & $audit -Mode Discover
    Assert-Flow ($initial.status -eq 'WAITING_USER_ACTION') 'espera inicial'
    $before = "$project/exports/phase2/plugin_inventory_before.json"
    $beforeHash = (Get-FileHash $before -Algorithm SHA256).Hash
    $calls = @(Get-Content $env:MOCK_DOCKER_LOG |
        Where-Object { $_ -match '^compose ' }).Count
    $pending = & $audit -Mode Discover
    Assert-Flow ($pending.status -eq 'WAITING_USER_ACTION') 'resume pendiente'
    Assert-Flow ($calls -eq @(Get-Content $env:MOCK_DOCKER_LOG |
        Where-Object { $_ -match '^compose ' }).Count) `
        'resume reutiliza snapshot sin Docker ni OAuth'
    Assert-Flow ((Get-FileHash $before -Algorithm SHA256).Hash -eq $beforeHash) `
        'BEFORE inmutable'

    $live = $fixture.before | ConvertTo-Json -Depth 30 | ConvertFrom-Json
    $live.plugins = @($live.plugins) + @($fixture.replacement_plugins)
    $live.activity_modules = @($live.activity_modules) + @(
        [pscustomobject]@{ name = 'zoom'; visible = 1 },
        [pscustomobject]@{ name = 'vpl2'; visible = 1 }
    )
    $live.plugins[-1].installation_status = 'incompatible'
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    $failed = & $audit -Mode Revalidate
    Assert-Flow ($failed.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED') `
        'rechaza plugin incompatible'
    try {
        & $audit -Mode Approve | Out-Null
        throw 'Aprobación imposible admitida'
    } catch {
        Assert-Flow ($_.Exception.Message -match 'PLUGIN_TECHNICAL_VALIDATION_FAILED') `
            'no ofrece Y ante fallo técnico'
    }
    $live.plugins[-1].installation_status = 'uptodate'
    $originalPlugins = @($live.plugins)
    $basePlugin = $live.plugins[0]
    $additional = @(
        @{ component='mod_customcert'; version=2026042013; deps=@() },
        @{ component='qbehaviour_adaptive_adapted_for_coderunner'; version=2026081000; deps=@() },
        @{ component='qtype_coderunner'; version=2026091501; deps=[pscustomobject]@{
            qbehaviour_adaptive_adapted_for_coderunner=2026070100 } },
        @{ component='theme_academi'; version=2026042800; deps=[pscustomobject]@{
            theme_boost=2026042000 } },
        @{ component='theme_boost'; version=2026042000; deps=[pscustomobject]@{} }
    )
    foreach ($item in $additional) {
        $p = $basePlugin | ConvertTo-Json -Depth 20 | ConvertFrom-Json
        $p.component = $item.component; $p.version_db = $item.version
        $p.version_disk = $item.version; $p.dependencies = $item.deps
        $live.plugins += $p
    }
    $live.plugins[-5].dependencies = @(123)
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    $invalid = & $audit -Mode Revalidate
    Assert-Flow ($invalid.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED') `
        'dependencia inválida produce fallo controlado'
    $phase2 = "$project/exports/phase2"
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    $checkpoint = Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" | ConvertFrom-Json
    Assert-Flow (-not $technical.passed -and $technical.issues[0].field -eq 'dependencies' -and
        $checkpoint.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        (Test-Path "$phase2/plugin_inventory_after.json") -and
        (Test-Path "$phase2/plugin_inventory_diff.json")) `
        'AFTER/diff/validación y checkpoint reanudable ante contrato inválido'
    $live.activity_modules[0].visible = @(1, 2)
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    $exceptionResult = & $audit -Mode Revalidate
    $exceptionIssue = (Get-Content -Raw "$phase2/plugin_technical_validation.json" |
        ConvertFrom-Json).issues[0]
    Assert-Flow ($exceptionResult.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        $exceptionIssue.field -eq 'inventory' -and
        (Test-Path "$phase2/plugin_inventory_after.json") -and
        (Get-Content -Raw "$phase2/plugin_inventory_diff.json" | ConvertFrom-Json).error) `
        'excepción de contrato de AFTER deja artefactos y no escapa al operador'
    $live.activity_modules[0].visible = 1
    $live.plugins[-5].dependencies = @()
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    $fourReady = & $audit -Mode Revalidate
    Assert-Flow ($fourReady.status -eq 'READY_FOR_APPROVAL' -and
        (Get-Content -Raw "$phase2/plugin_inventory_after.json" | ConvertFrom-Json).plugins.Count -eq 8) `
        'R de cuatro plugins reales alcanza READY_FOR_APPROVAL con AFTER'
    $live.plugins = $originalPlugins
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    $ready = & $audit -Mode Revalidate
    Assert-Flow ($ready.status -eq 'READY_FOR_APPROVAL') 'revalidación recupera'
    $approved = & $audit -Mode Approve
    Assert-Flow ($approved.status -eq 'PLUGIN_INTERVENTION_APPROVED') 'aprobación consciente'
    Assert-Flow ((& $audit -Mode Check).status -eq 'PLUGIN_INTERVENTION_APPROVED') `
        'resume aprobado sin prompts'
    $resolution = Get-Content -Raw `
        "$project/exports/phase2/plugin_intervention_resolution.json" | ConvertFrom-Json
    Assert-Flow ($resolution.operator_decision -eq 'approved' -and
        $resolution.original_compatibility_needs.Count -eq 3 -and
        $resolution.newly_detected_plugins.Count -eq 2 -and
        $resolution.explicit_equivalences.Count -eq 1 -and
        $resolution.explicit_equivalences[0].source_requirement -eq 'mod_vpl') `
        'solo equivalencia declarada explícitamente'
    $live.plugins[-1].tree_sha256 = 'f' * 64
    $live | ConvertTo-Json -Depth 30 | Set-Content $script:livePath -Encoding utf8
    Assert-Flow ((& $audit -Mode Check).status -eq 'PLUGIN_INTERVENTION_PENDING') `
        'cambio plugin invalida aprobación'
    $renew = & $audit -Mode Discover
    Assert-Flow ($renew.status -eq 'WAITING_USER_ACTION') `
        'nuevo inventario exige nueva aprobación aun sin needs'
    Assert-Flow ((Get-ChildItem "$project/exports/phase2/history" `
        -Directory).Count -eq 1) 'archiva contrato anterior'
    Add-Content -LiteralPath $before -Value 'tampered'
    try {
        & $audit -Mode Discover | Out-Null
        throw 'BEFORE manipulado admitido'
    } catch {
        Assert-Flow ($_.Exception.Message -match 'PLUGIN_INTERVENTION_SEAL_INVALID') `
            'BEFORE alterado bloquea'
    }
    & $audit -Mode Invalidate | Out-Null
    $source.plugins = @()
    $source.used_activity_modules = @()
    $source | ConvertTo-Json -Depth 30 | Set-Content `
        "$project/exports/packages/origen_a/plugins.json" -Encoding utf8
    @{ source_id = 'origen_a'; plugins_sha256 = `
        (Get-FileHash "$project/exports/packages/origen_a/plugins.json" `
            -Algorithm SHA256).Hash.ToLowerInvariant() } | ConvertTo-Json | Set-Content `
        "$project/exports/packages/origen_a/manifest.json" -Encoding utf8
    Remove-Item "$project/config/plugin_equivalences.csv"
    $fixture.before | ConvertTo-Json -Depth 30 |
        Set-Content $script:livePath -Encoding utf8
    $automatic = & $audit -Mode Discover
    Assert-Flow ($automatic.status -eq 'PLUGIN_INTERVENTION_APPROVED') `
        'sin necesidades válidas aprueba automáticamente'
    $resolution = Get-Content -Raw `
        "$project/exports/phase2/plugin_intervention_resolution.json" | ConvertFrom-Json
    Assert-Flow ($resolution.operator_decision -eq 'not_required') `
        'decisión automática identificada en resolución'
    Write-Output 'RC3_FLOW_OK resume=immutable approval=sealed technical=fail_closed live_change=invalidates'
} finally {
    Set-Location $PSScriptRoot
    if ($oldPath) { $env:PATH = $oldPath }
    Remove-Item Env:MOCK_DOCKER_LOG, Env:MOCK_LIVE_INVENTORY -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $project -Recurse -Force
}
