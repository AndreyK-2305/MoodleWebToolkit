$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../scripts/PluginIntervention.ps1"

function Assert-RC4PluginLocation([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "V8_RC4_PLUGIN_LOCATION_FAILED $Message" }
}

function New-Inventory([string]$Source, [string]$LocationStatus) {
    $tree = if ($Source -eq 'additional') { 'a' * 64 } else { '' }
    return [pscustomobject]@{
        schema_version = '1.1'
        target_id = 'target'
        target_wwwroot = 'https://moodle.example.test'
        approved_plugins_sha256 = 'b' * 64
        approved_plugins = @()
        plugins = @([pscustomobject]@{
            component = 'mod_fixture'
            version_disk = 2026092800
            version_db = 2026092800
            source = $Source
            installation_status = 'installed'
            location_status = $LocationStatus
            tree_sha256 = $tree
            version_file_valid = $true
            dependencies = [pscustomobject]@{}
        })
    }
}

$arguments = @{
    TargetId = 'target'
    ExpectedUrl = 'https://moodle.example.test'
    PinHash = 'b' * 64
    UpgradeSucceeded = $true
    MoodleReady = $true
}

$core = Test-PluginTechnicalInventory `
    -Inventory (New-Inventory 'standard' 'core_component') @arguments
Assert-RC4PluginLocation $core.passed 'componente core localizable fue rechazado'

$additional = Test-PluginTechnicalInventory `
    -Inventory (New-Inventory 'additional' 'installed_localizable') @arguments
Assert-RC4PluginLocation $additional.passed 'plugin adicional localizable fue rechazado'

$missing = Test-PluginTechnicalInventory `
    -Inventory (New-Inventory 'additional' 'declared_missing') @arguments
Assert-RC4PluginLocation (-not $missing.passed) 'plugin declarado ausente no bloqueó'
Assert-RC4PluginLocation (
    @($missing.issues | Where-Object field -eq 'location_status').Count -eq 1
) 'plugin ausente no produjo diagnóstico de ubicación'

$unknown = Test-PluginTechnicalInventory `
    -Inventory (New-Inventory 'standard' 'unknown_component') @arguments
Assert-RC4PluginLocation (-not $unknown.passed) 'componente no resoluble no bloqueó'

Write-Output 'V8_RC4_PLUGIN_LOCATION_OK core=1 additional=1 missing=blocked unknown=blocked'
