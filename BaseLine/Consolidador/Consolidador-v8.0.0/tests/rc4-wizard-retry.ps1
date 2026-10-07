$ErrorActionPreference = 'Stop'
function Assert-RC4Wizard([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "RC4_WIZARD_FAILED $Message" }
}
$root = Join-Path ([IO.Path]::GetTempPath()) `
    ('rc4-wizard-' + [guid]::NewGuid().ToString('N'))
$oldEmail = $env:CONSOLIDATION_EMAIL_ENABLED
$oldUrl = $env:MOODLE_PUBLIC_URL
$env:CONSOLIDATION_EMAIL_ENABLED = '0'
$env:MOODLE_PUBLIC_URL = 'https://moodle.example.edu'
New-Item -ItemType Directory -Force -Path "$root/scripts", "$root/reports", `
    "$root/config", "$root/exports/phase1", "$root/exports/packages/origen", `
    "$root/copias" | Out-Null
try {
    foreach ($script in @('Common.ps1', 'ConfigParser.ps1',
            'ConfigAccess.ps1', 'Notifications.ps1',
            'consolidation-wizard.ps1')) {
        Copy-Item "$PSScriptRoot/../scripts/$script" "$root/scripts/$script"
    }
    @'
version: 1
project_name: rc4-test
mode: production
sources:
  - id: origen
    name: Origen
    service: origen
    url: https://origen.example.edu
target:
  id: target
  name: Destino
  service: moodle-target
  url: https://moodle.example.edu
'@ | Set-Content "$root/config.yaml" -Encoding utf8
    '{}' | Set-Content "$root/exports/packages/origen/manifest.json" -Encoding utf8
    '{}' | Set-Content "$root/config/oauth2.json" -Encoding utf8
    $configHash = (Get-FileHash "$root/config.yaml" -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifestHash = (Get-FileHash "$root/exports/packages/origen/manifest.json" `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    @{
        schema_version = '1.0'; config_sha256 = $configHash
        import_status = 'passed'; packages_verified = $true
        destination_write_performed = $false; sources = 1; courses = 1
        package_index = @(@{ source_id = 'origen'; manifest_sha256 = $manifestHash })
    } | ConvertTo-Json -Depth 12 | Set-Content `
        "$root/exports/phase1/package_index.json" -Encoding utf8
    @{ workflow = 'source-packages'; config_sha256 = $configHash } |
        ConvertTo-Json | Set-Content "$root/reports/configuration-confirmation.json" -Encoding utf8
    'manual' | Set-Content "$root/mock-oauth-status.txt"
    'pending' | Set-Content "$root/mock-plugin-status.txt"
    @'
param([switch]$LiveCheck)
$root = Split-Path -Parent $PSScriptRoot
$setting = (Get-Content "$root/mock-oauth-status.txt" -Raw).Trim()
$ready = $setting -eq 'ready'
$switchAfterValidation = ($setting -eq 'manual_then_ready' -and -not $LiveCheck) -or
    ($setting -eq 'manual_then_ready_live' -and $LiveCheck)
$target = 'https://moodle.example.edu'
$configHash = (Get-FileHash "$root/config.yaml" -Algorithm SHA256).Hash.ToLowerInvariant()
$oauthHash = (Get-FileHash "$root/config/oauth2.json" -Algorithm SHA256).Hash.ToLowerInvariant()
$dir = if ($LiveCheck) { "$root/exports/oauth2-live" } else { "$root/exports/oauth2" }
New-Item -ItemType Directory -Force $dir | Out-Null
@{
    config_sha256 = $configHash; oauth_config_sha256 = $oauthHash
    status = if ($ready) { 'ready' } else { 'manual_required' }
    validation = if ($ready) { 'passed' } else { 'failed' }
    expected_public_url = $target; public_url_validation = 'passed'
    reverseproxy = $true; sslproxy = $true
    callback_url = "$target/admin/oauth2callback.php"
    issuer_id = 7; auth_plugin_enabled = $true
    client_credentials_present = $ready; show_on_login_page = $ready
    issuer_enabled = $ready; endpoints_configured = if ($ready) { 2 } else { 0 }
    destination_write_performed = $false
} | ConvertTo-Json | Set-Content "$dir/validation.json" -Encoding utf8
if ($switchAfterValidation) {
    'ready' | Set-Content "$root/mock-oauth-status.txt" -Encoding utf8
}
if ($ready) { 'OAUTH2_READY' } else { 'OAUTH2_MANUAL_REQUIRED' }
'@ | Set-Content "$root/scripts/oauth2-validate.ps1" -Encoding utf8
    @'
param([string]$Mode)
$root = Split-Path -Parent $PSScriptRoot
Add-Content "$root/mock-plugin-modes.txt" $Mode
$phase2 = "$root/exports/phase2"
New-Item -ItemType Directory -Force $phase2 | Out-Null
if ((Get-Content "$root/mock-plugin-status.txt" -Raw).Trim() -eq 'approved') {
    @{ status = 'PLUGIN_INTERVENTION_APPROVED' } | ConvertTo-Json |
        Set-Content "$phase2/plugin_intervention_checkpoint.json" -Encoding utf8
    if (-not (Test-Path "$phase2/plugin_intervention_resolution.json")) {
        @{ status = 'PLUGIN_INTERVENTION_APPROVED' } | ConvertTo-Json |
            Set-Content "$phase2/plugin_intervention_resolution.json" -Encoding utf8
    }
    @{ status = 'PLUGIN_INTERVENTION_APPROVED' }
} else {
    @{ status = 'WAITING_USER_ACTION' } | ConvertTo-Json |
        Set-Content "$phase2/plugin_intervention_checkpoint.json" -Encoding utf8
    @{ original_compatibility_needs = @() } | ConvertTo-Json |
        Set-Content "$phase2/plugin_compatibility_needs.json" -Encoding utf8
    @{ status = 'PLUGIN_INTERVENTION_PENDING' }
}
'@ | Set-Content "$root/scripts/audit-package-plugins.ps1" -Encoding utf8
    @'
param([string]$Mode)
$root = Split-Path -Parent $PSScriptRoot
$approved = (Get-Content "$root/mock-plugin-status.txt" -Raw).Trim() -eq 'approved'
if ($Mode -eq 'Check' -and -not $approved) { throw 'V8_PLUGIN_LOCK_INVALID' }
if ($Mode -eq 'Prepare') {
    @{ lock_status = 'ARTIFACTS_VERIFIED_PENDING_STAGING'; unknown_plugins = @() }
} else {
    @{ lock_status = 'SEALED'; plugin_lock_valid = $true }
}
'@ | Set-Content "$root/scripts/v8-plugin-resolver.ps1" -Encoding utf8
    @'
throw 'RC4_AFTER_LIVE_REACHED'
'@ | Set-Content "$root/scripts/reconcile-packages.ps1" -Encoding utf8
    $pwsh = (Get-Command pwsh).Source
    $invoke = {
        $stdout = "$root/wizard.stdout"
        $stderr = "$root/wizard.stderr"
        $process = Start-Process -FilePath $pwsh -ArgumentList @('-NoLogo',
            '-NoProfile', '-File', "$root/scripts/consolidation-wizard.ps1",
            '-Automatic') -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr -PassThru
        if (-not $process.WaitForExit(15000)) {
            $process.Kill()
            throw 'RC4_WIZARD_FAILED: el wizard se quedó en un bucle.'
        }
        $output = (Get-Content -Raw $stdout) + (Get-Content -Raw $stderr)
        return @{ Code = $process.ExitCode; Output = $output }
    }
    $first = & $invoke
    $firstState = Get-Content -Raw "$root/reports/assistant-state.json" | ConvertFrom-Json
    Assert-RC4Wizard ($first.Code -eq 22 -and
        $firstState.stage -eq '02-oauth2' -and
        $firstState.status -eq 'waiting_manual' -and
        $first.Output -match 'OAUTH2_MANUAL_REQUIRED') `
        "primera validación manual: $($first.Output)"
    'ready' | Set-Content "$root/mock-oauth-status.txt"
    $second = & $invoke
    $secondState = Get-Content -Raw "$root/reports/assistant-state.json" | ConvertFrom-Json
    Assert-RC4Wizard ($second.Code -eq 22 -and
        $secondState.stage -eq '03-plugins' -and
        $second.Output -match 'ETAPA_OK 02-oauth2' -and
        $second.Output -notmatch 'Get-TargetSite') `
        "reintento de OAuth en wizard: $($second.Output)"
    'approved' | Set-Content "$root/mock-plugin-status.txt"
    'manual' | Set-Content "$root/mock-oauth-status.txt"
    $third = & $invoke
    $thirdState = Get-Content -Raw "$root/reports/assistant-state.json" | ConvertFrom-Json
    Assert-RC4Wizard ($third.Code -eq 22 -and
        $thirdState.stage -eq '03b-oauth2-final' -and
        $thirdState.status -eq 'waiting_manual') `
        "intervención OAuth live: $($third.Output)"
    $pluginModes = @(Get-Content "$root/mock-plugin-modes.txt") -join ','
    Assert-RC4Wizard ($pluginModes -match '^Discover,Revalidate,Revalidate,Check$') `
        "reintento general de Fase 2 usa la misma revalidación R: $pluginModes"
    'ready' | Set-Content "$root/mock-oauth-status.txt"
    $fourth = & $invoke
    $fourthState = Get-Content -Raw "$root/reports/assistant-state.json" | ConvertFrom-Json
    Assert-RC4Wizard ($fourth.Code -eq 20 -and
        $fourthState.stage -eq '04-identidades' -and
        $fourth.Output -match 'ETAPA_OK 03b-oauth2-final' -and
        $fourth.Output -match 'RC4_AFTER_LIVE_REACHED' -and
        $fourth.Output -notmatch 'Get-TargetSite') `
        "reintento OAuth live en wizard: $($fourth.Output)"
    $invokeInteractive = {
        param([string]$Commands)
        $start = [System.Diagnostics.ProcessStartInfo]::new()
        $start.FileName = $pwsh
        foreach ($argument in @('-NoLogo', '-NoProfile', '-File',
                "$root/scripts/consolidation-wizard.ps1")) {
            [void]$start.ArgumentList.Add($argument)
        }
        $start.UseShellExecute = $false
        $start.RedirectStandardInput = $true
        $start.RedirectStandardOutput = $true
        $start.RedirectStandardError = $true
        $process = [System.Diagnostics.Process]::new()
        $process.StartInfo = $start
        [void]$process.Start()
        $outputTask = $process.StandardOutput.ReadToEndAsync()
        $errorTask = $process.StandardError.ReadToEndAsync()
        $process.StandardInput.Write($Commands)
        $process.StandardInput.Close()
        if (-not $process.WaitForExit(15000)) {
            $process.Kill()
            throw 'RC4_WIZARD_FAILED: reintentar interactivo quedó bloqueado.'
        }
        return @{ Code = $process.ExitCode;
            Output = $outputTask.Result + $errorTask.Result }
    }
    'pending' | Set-Content "$root/mock-plugin-status.txt"
    'manual_then_ready' | Set-Content "$root/mock-oauth-status.txt"
    Remove-Item "$root/exports/oauth2/validation.json"
    $interactiveOAuth = & $invokeInteractive "continuar`nreintentar`nS`n"
    Assert-RC4Wizard ($interactiveOAuth.Code -eq 0 -and
        $interactiveOAuth.Output -match 'INTERVENCION_MANUAL 02-oauth2' -and
        $interactiveOAuth.Output -match 'ETAPA_OK 02-oauth2' -and
        $interactiveOAuth.Output -notmatch 'Get-TargetSite') `
        "comando literal reintentar OAuth: $($interactiveOAuth.Output)"
    'approved' | Set-Content "$root/mock-plugin-status.txt"
    'manual_then_ready_live' | Set-Content "$root/mock-oauth-status.txt"
    Remove-Item "$root/exports/oauth2-live/validation.json"
    $interactiveLive = & $invokeInteractive "continuar`nreintentar`nsalir`n"
    Assert-RC4Wizard ($interactiveLive.Code -eq 0 -and
        $interactiveLive.Output -match 'INTERVENCION_MANUAL 03b-oauth2-final' -and
        $interactiveLive.Output -match 'ETAPA_OK 03b-oauth2-final' -and
        $interactiveLive.Output -match '4\. Conciliar identidades' -and
        $interactiveLive.Output -notmatch 'Get-TargetSite') `
        "comando literal reintentar OAuth live: $($interactiveLive.Output)"
    Write-Output 'RC4_WIZARD_OK manual_wait=1 retry_ready=1 live_wait=1 live_retry_ready=1 interactive_retries=2'
} finally {
    Set-Location $PSScriptRoot
    $env:MOODLE_PUBLIC_URL = $oldUrl
    $env:CONSOLIDATION_EMAIL_ENABLED = $oldEmail
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
