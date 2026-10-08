$ErrorActionPreference = 'Stop'
function Assert-RC10($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC10_RETRY_FAILED: $Label" }
}
$project = Join-Path ([IO.Path]::GetTempPath()) ('rc10-retry-' + [guid]::NewGuid().ToString('N'))
$oldPath = $env:PATH
try {
    New-Item -ItemType Directory -Force -Path "$project/scripts", "$project/config", `
        "$project/reports", "$project/exports/packages/origen_a", `
        "$project/docker/custom-plugins", "$project/bin" | Out-Null
    foreach ($file in @('Common.ps1', 'ConfigParser.ps1', 'ConfigAccess.ps1', 'PluginPreflight.ps1',
            'PluginIntervention.ps1', 'MoodleVersion.ps1', 'audit-package-plugins.ps1')) {
        Copy-Item "$PSScriptRoot/../scripts/$file" "$project/scripts/$file"
    }
    @'
version: 1
project_name: rc10-retry
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
'@ | Set-Content "$project/config.yaml" -Encoding utf8
    $configHash = (Get-FileHash "$project/config.yaml" -Algorithm SHA256).Hash.ToLowerInvariant()
    @{ config_sha256 = $configHash } | ConvertTo-Json |
        Set-Content "$project/reports/configuration-confirmation.json" -Encoding utf8
    $fixture = Get-Content -Raw "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" |
        ConvertFrom-Json
    $source = $fixture.sources[0].inventory
    $source | Add-Member -NotePropertyName source_id -NotePropertyValue 'origen_a'
    $source | Add-Member -NotePropertyName write_performed -NotePropertyValue $false
    $pluginsPath = "$project/exports/packages/origen_a/plugins.json"
    $source | ConvertTo-Json -Depth 30 | Set-Content $pluginsPath -Encoding utf8
    @{ source_id = 'origen_a'; plugins_sha256 = (
        Get-FileHash $pluginsPath -Algorithm SHA256).Hash.ToLowerInvariant() } |
        ConvertTo-Json | Set-Content "$project/exports/packages/origen_a/manifest.json" -Encoding utf8
    $pinPath = "$project/docker/custom-plugins/approved-plugins.json"
    $csvPath = "$project/config/plugin_equivalences.csv"
    '{"schema_version":"1.0","plugins":[]}' | Set-Content $pinPath -Encoding utf8
    'source_requirement,target_components' | Set-Content $csvPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $livePath = "$project/live-inventory.json"
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
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
    $env:MOCK_LIVE_INVENTORY = $livePath
    $env:PATH = "$project/bin:$oldPath"
    $audit = "$project/scripts/audit-package-plugins.ps1"
    $phase2 = "$project/exports/phase2"
    $initial = & $audit -Mode Discover
    Assert-RC10 ($initial.status -eq 'WAITING_USER_ACTION') 'Discover inicial A espera aprobación'
    $beforeA = (Get-FileHash "$phase2/plugin_inventory_before.json" -Algorithm SHA256).Hash
    $cpA = Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" | ConvertFrom-Json
    $hashA = $cpA.approved_plugins_sha256
    # A: cambiar el manifest requiere reconstruir el contrato y revalidar en esta sesión.
    '{"schema_version":"1.0","plugins":[],"revision":"B"}' |
        Set-Content $pinPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $result = & $audit -Mode Revalidate
    $cpB = Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" | ConvertFrom-Json
    $history = @(Get-ChildItem "$phase2/history" -Directory)
    Assert-RC10 ($result.status -eq 'READY_FOR_APPROVAL' -and
        $cpB.approved_plugins_sha256 -eq $fixture.before.approved_plugins_sha256 -and
        $hashA -ne $cpB.approved_plugins_sha256 -and $cpB.review_required -and
        $history.Count -eq 1 -and
        (Get-FileHash "$($history[0].FullName)/plugin_inventory_before.json" -Algorithm SHA256).Hash -eq $beforeA -and
        (Test-Path "$($history[0].FullName)/plugin_intervention_checkpoint.json")) `
        'A: B listo, A archivado íntegro y sin aprobación automática'
    # B: sin cambios se usa exactamente el mismo BEFORE y no crece history.
    $beforeB = (Get-FileHash "$phase2/plugin_inventory_before.json" -Algorithm SHA256).Hash
    $result = & $audit -Mode Revalidate
    Assert-RC10 ($result.status -eq 'READY_FOR_APPROVAL' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 1 -and
        (Get-FileHash "$phase2/plugin_inventory_before.json" -Algorithm SHA256).Hash -eq $beforeB) `
        'B: mismo contrato, BEFORE sellado, history sin cambios'
    $afterPath = "$phase2/plugin_inventory_after.json"
    $afterBytes = [IO.File]::ReadAllBytes($afterPath)
    Add-Content $afterPath 'tampered'
    try {
        & $audit -Mode Revalidate | Out-Null
        throw 'RC10_RETRY_FAILED AFTER alterado se aceptó'
    } catch {
        Assert-RC10 ($_.Exception.Message -match 'PLUGIN_INTERVENTION_SEAL_INVALID' -and
            @(Get-ChildItem "$phase2/history" -Directory).Count -eq 1) `
            'AFTER alterado no se archiva silenciosamente'
    } finally {
        [IO.File]::WriteAllBytes($afterPath, $afterBytes)
    }
    # C: cambiar equivalencias también invalida un checkpoint aún no aprobado.
    @('source_requirement,target_components', 'mod_vpl,mod_quiz') |
        Set-Content $csvPath -Encoding utf8
    $result = & $audit -Mode Revalidate
    $cpC = Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" | ConvertFrom-Json
    Assert-RC10 ($result.status -eq 'READY_FOR_APPROVAL' -and
        $cpC.equivalences_sha256 -eq (Get-FileHash $csvPath -Algorithm SHA256).Hash.ToLowerInvariant() -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 2) `
        'C: equivalencias B archivadas y C enlazadas'
    # D: aprobación válida + R no modifica el contrato ni archiva.
    $approved = & $audit -Mode Approve
    Assert-RC10 ($approved.status -eq 'PLUGIN_INTERVENTION_APPROVED') 'aprobación manual válida'
    $approvedHash = (Get-FileHash "$phase2/plugin_intervention_checkpoint.json" -Algorithm SHA256).Hash
    $resolutionHash = (Get-FileHash "$phase2/plugin_intervention_resolution.json" -Algorithm SHA256).Hash
    $result = & $audit -Mode Revalidate
    Assert-RC10 ($result.status -eq 'PLUGIN_INTERVENTION_APPROVED' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 2 -and
        (Get-FileHash "$phase2/plugin_intervention_checkpoint.json" -Algorithm SHA256).Hash -eq $approvedHash -and
        (Get-FileHash "$phase2/plugin_intervention_resolution.json" -Algorithm SHA256).Hash -eq $resolutionHash) `
        'D: aprobado válido permanece intacto'
    # E: una aprobación invalidada se archiva sin reutilizarse; el nuevo READY
    # debe archivarse si aparece otro binding antes de la siguiente revisión.
    '{"schema_version":"1.0","plugins":[],"revision":"C"}' |
        Set-Content $pinPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $result = & $audit -Mode Revalidate
    Assert-RC10 ($result.status -eq 'READY_FOR_APPROVAL' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 3) `
        'aprobado inválido archivado; READY requiere nueva aprobación'
    '{"schema_version":"1.0","plugins":[],"revision":"D"}' |
        Set-Content $pinPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $result = & $audit -Mode Revalidate
    Assert-RC10 ($result.status -eq 'READY_FOR_APPROVAL' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 4 -and
        @(Get-ChildItem "$phase2/history" -Recurse -Filter plugin_inventory_before.json).Count -eq 4) `
        'E: READY previo preservado y contrato D regenerado'
    # mod_chat era core, se utilizó en el origen y no tiene transformación.
    $source.used_activity_modules += 'chat'
    $source.plugins += [pscustomobject]@{
        component = 'mod_chat'; type = 'mod'; name = 'chat'
        source = 'standard'; version_disk = 2024100702; release = '4.5'
    }
    $source | ConvertTo-Json -Depth 30 | Set-Content $pluginsPath -Encoding utf8
    @{ source_id = 'origen_a'; plugins_sha256 = (
        Get-FileHash $pluginsPath -Algorithm SHA256).Hash.ToLowerInvariant() } |
        ConvertTo-Json | Set-Content "$project/exports/packages/origen_a/manifest.json" -Encoding utf8
    $replacement = $fixture.before.plugins[0] | ConvertTo-Json -Depth 30 | ConvertFrom-Json
    $replacement.component = 'mod_openchat'; $replacement.name = 'openchat'
    $fixture.before.plugins += $replacement
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $blocked = & $audit -Mode Revalidate
    $needs = Get-Content -Raw "$phase2/plugin_compatibility_needs.json" | ConvertFrom-Json
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    Assert-RC10 ($blocked.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @($needs.original_compatibility_needs | Where-Object {
            $_.component -eq 'mod_chat' -and $_.observed_state -eq 'removed_core_component'
        }).Count -eq 1 -and
        @($technical.issues | Where-Object {
            $_.field -eq 'removed_core_component' -and $_.message -like 'REMOVED_CORE_COMPONENT:*'
        }).Count -eq 1 -and
        (Get-Content -Raw "$phase2/plugin_intervention_checkpoint.json" |
            ConvertFrom-Json).status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED') `
        'Chat core retirado bloquea aunque exista mod_openchat'
    @('source_requirement,target_components', 'mod_vpl,mod_quiz',
        'mod_chat,mod_openchat') | Set-Content $csvPath -Encoding utf8
    $blockedAgain = & $audit -Mode Revalidate
    Assert-RC10 ($blockedAgain.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 6) `
        'equivalencia ficticia mod_chat -> mod_openchat sigue bloqueada'
    $blockedAgain = & $audit -Mode Revalidate
    Assert-RC10 ($blockedAgain.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @(Get-ChildItem "$phase2/history" -Directory).Count -eq 6) `
        'reintento bloqueado sin cambios conserva el checkpoint'
    try {
        & $audit -Mode Approve | Out-Null
        throw 'RC10_RETRY_FAILED aprobó Chat sin restauración'
    } catch {
        Assert-RC10 ($_.Exception.Message -match 'PLUGIN_TECHNICAL_VALIDATION_FAILED') `
            'no se puede aprobar Chat retirado'
    }
    $source.used_activity_modules += 'evoting'
    $source.plugins += [pscustomobject]@{
        component = 'mod_evoting'; type = 'mod'; name = 'evoting'
        source = 'additional'; version_disk = 2024042302; release = 'v4.0'
    }
    $source | ConvertTo-Json -Depth 30 | Set-Content $pluginsPath -Encoding utf8
    @{ source_id = 'origen_a'; plugins_sha256 = (
        Get-FileHash $pluginsPath -Algorithm SHA256).Hash.ToLowerInvariant() } |
        ConvertTo-Json | Set-Content "$project/exports/packages/origen_a/manifest.json" -Encoding utf8
    $noCode = & $audit -Mode Revalidate
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    Assert-RC10 ($noCode.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @($technical.issues | Where-Object { $_.message -like 'EVOTING_SOURCE_UNVERIFIED:*' }).Count -eq 1) `
        'evoting sin código/pin institucional no se acepta por nombre'
    $evotingPin = [ordered]@{
        component = 'mod_evoting'; path = 'public/mod/evoting'
        version = 2023010100; release = 'v4.0'; commit = 'b' * 40
        tree_sha256 = 'a' * 64; submodules = @()
        provenance = [ordered]@{
            kind = 'institutional_git'; source = 'expediente institucional 123'
            origin = '/institucion/evoting.git'; responsible = 'Equipo Moodle'
            reviewed_at = '2026-09-20'
        }
    }
    $manifest = [ordered]@{ schema_version = '1.0'; plugins = @($evotingPin) }
    $manifest | ConvertTo-Json -Depth 30 | Set-Content $pinPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before.approved_plugins = @($evotingPin)
    $evoting = $replacement | ConvertTo-Json -Depth 30 | ConvertFrom-Json
    $evoting.component = 'mod_evoting'; $evoting.name = 'evoting'
    $evoting.source = 'additional'; $evoting.version_db = 2023010100
    $evoting.version_disk = 2023010100; $evoting.release = 'v4.0'
    $evoting.tree_sha256 = 'a' * 64; $evoting.approved_commit = 'b' * 40
    $evoting.approved_path = 'public/mod/evoting'
    $fixture.before.plugins += $evoting
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $oldVersion = & $audit -Mode Revalidate
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    Assert-RC10 ($oldVersion.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @($technical.issues | Where-Object { $_.message -like 'EVOTING_VERSION_DOWNGRADE:*' }).Count -eq 1) `
        'evoting institucional pinneado pero anterior al origen se bloquea'
    $evotingPin.version = 2024042302
    $manifest | ConvertTo-Json -Depth 30 | Set-Content $pinPath -Encoding utf8
    $fixture.before.approved_plugins_sha256 = (Get-FileHash $pinPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $fixture.before.approved_plugins = @($evotingPin)
    $evoting.version_db = 2024042302; $evoting.version_disk = 2024042302
    $fixture.before | ConvertTo-Json -Depth 30 | Set-Content $livePath -Encoding utf8
    $matchingVersion = & $audit -Mode Revalidate
    $technical = Get-Content -Raw "$phase2/plugin_technical_validation.json" | ConvertFrom-Json
    Assert-RC10 ($matchingVersion.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED' -and
        @($technical.issues | Where-Object { $_.component -eq 'mod_evoting' }).Count -eq 0 -and
        @($technical.issues | Where-Object { $_.field -eq 'removed_core_component' }).Count -eq 1) `
        'evoting verificado deja solo el bloqueo independiente de Chat'
    Write-Output 'RC10_RETRY_OK manifest=A-B equivalences=archived unchanged=sealed approved=unchanged ready=regenerated'
    Write-Output 'RC10_REMOVED_CORE_OK mod_chat=blocked mod_openchat=not_equivalent artifacts=preserved'
    Write-Output 'RC10_EVOTING_OK unpinned=blocked older=blocked institutional_git=accepted'
} finally {
    $env:PATH = $oldPath
    Remove-Item Env:MOCK_LIVE_INVENTORY -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $project -Recurse -Force -ErrorAction SilentlyContinue
}
