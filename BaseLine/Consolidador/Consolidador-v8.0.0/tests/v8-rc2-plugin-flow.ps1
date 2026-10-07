$ErrorActionPreference = 'Stop'

function Assert-RC2Plugin([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "V8_RC2_PLUGIN_FLOW_FAILED: $Message" }
}

$root = Split-Path -Parent $PSScriptRoot
$source = Join-Path $root 'scripts/audit-package-plugins.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $source, [ref]$tokens, [ref]$errors
)
Assert-RC2Plugin ($errors.Count -eq 0) 'audit-package-plugins.ps1 no parsea'
$requiredFunctions = @(
    'Test-ApprovedAdditionalPlugin',
    'Enable-RequiredDisabledActivityModules',
    'Test-RemovedCoreComponentResolution'
)
foreach ($name in $requiredFunctions) {
    $definition = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
            $node.Name -eq $name
    }, $true))
    Assert-RC2Plugin ($definition.Count -eq 1) "no encontró función $name"
    Invoke-Expression $definition[0].Extent.Text
}

$auditText = Get-Content -LiteralPath $source -Raw -Encoding UTF8
foreach ($needle in @('Wait-DestinationStartupReady', 'Test-DestinationUpgradeRequired',
        'Invoke-SerializedDestinationUpgrade', '/run/moodle-upgrade.lock',
        'PLUGIN_UPGRADE_FAILED')) {
    Assert-RC2Plugin ($auditText.Contains($needle)) "falta contrato $needle"
}

$temp = Join-Path ([IO.Path]::GetTempPath()) (
    'v8-rc2-plugin-flow-' + [guid]::NewGuid().ToString('N')
)
$oldPath = $env:PATH
$oldLog = $env:V8RC2_DOCKER_LOG
try {
    New-Item -ItemType Directory -Force -Path $temp | Out-Null
    $docker = Join-Path $temp 'docker'
    @'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$V8RC2_DOCKER_LOG"
if [[ "$*" == *"target-enable-plugin.php"* ]]; then
  printf '%s\n' 'TARGET_PLUGIN_ENABLED'
fi
'@ | Set-Content -LiteralPath $docker -Encoding utf8NoBOM
    & /bin/chmod 0755 $docker
    $env:PATH = "$temp$([IO.Path]::PathSeparator)$oldPath"
    $env:V8RC2_DOCKER_LOG = Join-Path $temp 'docker.log'
    $service = 'moodle-target'

    $plugin = [pscustomobject]@{
        component = 'mod_chat'; source = 'additional'; version_disk = '2026092700'
        version_db = '2026092700'; version_file_valid = $true
        installation_status = 'uptodate'; enabled = $true
        approved_commit = 'abc123'; tree_sha256 = ('a' * 64); release = '8.0'
    }
    $pin = [pscustomobject]@{
        component = 'mod_chat'; version = '2026092700'; commit = 'abc123'
        tree_sha256 = ('a' * 64); release = '8.0'
    }
    $inventory = [pscustomobject]@{
        plugins = @($plugin); approved_plugins = @($pin)
    }
    $need = [pscustomobject]@{
        component = 'mod_chat'; source_id = 'source-a'; used_activity = $true
        observed_state = 'removed_core_component'
    }
    $pinEvents = (& {
        Enable-RequiredDisabledActivityModules @($need) $inventory
    } 6>&1 | Out-String)
    $dockerLog = Get-Content -LiteralPath $env:V8RC2_DOCKER_LOG -Raw
    Assert-RC2Plugin ($pinEvents -match 'PLUGIN_REPLACEMENT_FOUND' -and
        $pinEvents -match 'TARGET_PLUGIN_PIN_VERIFIED' -and
        $pinEvents -match 'TARGET_PLUGIN_ENABLE_OK' -and
        $dockerLog -match '--component=mod_chat' -and
        $dockerLog -match '--pin-verified=1') `
        'replacement mod_chat pinned no fue habilitado con evidencia estricta'
    Assert-RC2Plugin (Test-RemovedCoreComponentResolution $need $inventory) `
        'mod_chat válido no quedó REMOVED_CORE_COMPONENT_RESOLVED'

    $inventory.approved_plugins = @()
    Assert-RC2Plugin (-not (Test-RemovedCoreComponentResolution $need $inventory)) `
        'replacement sin pin fue resuelto'
    $blocked = $false
    try { Enable-RequiredDisabledActivityModules @($need) $inventory | Out-Null }
    catch { $blocked = $_.Exception.Message -match 'PLUGIN_PIN_REQUIRED' }
    Assert-RC2Plugin $blocked 'plugin adicional sin pin no bloqueó'

    Write-Output 'V8_RC2_PLUGIN_FLOW_OK upgrade=serialized mod_chat=resolved pinned=enabled unpinned=blocked'
} finally {
    $env:PATH = $oldPath
    $env:V8RC2_DOCKER_LOG = $oldLog
    Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
}
