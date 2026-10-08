$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../scripts/PluginIntervention.ps1"
function Assert-RC10-Release($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC10_RELEASE_FAILED: $Label" }
}
$fixture = Get-Content -Raw "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" |
    ConvertFrom-Json
$inventory = $fixture.before
$plugin = $fixture.replacement_plugins[0] | ConvertTo-Json -Depth 30 |
    ConvertFrom-Json
$plugin.release = $null
$plugin.approved_commit = 'c' * 40
$inventory.plugins = @($inventory.plugins) + @($plugin)
$pin = [pscustomobject]@{
    component = $plugin.component; commit = $plugin.approved_commit
    tree_sha256 = $plugin.tree_sha256; version = $plugin.version_disk
    release = $null
}
$inventory.approved_plugins = @($pin)
function Validate-RC10 {
    Test-PluginTechnicalInventory -Inventory $inventory -TargetId 'target' `
        -ExpectedUrl 'https://moodle.example.edu' `
        -PinHash $inventory.approved_plugins_sha256 `
        -UpgradeSucceeded $true -MoodleReady $true
}
Assert-RC10-Release ((Validate-RC10).passed) 'release ausente en pin e inventario'
$preflight = Get-PluginPreflightStatus -Component $plugin.component `
    -Source $plugin -Target $plugin -Pin $pin -Used $true -Module $null
Assert-RC10-Release ($preflight.status -eq 'enabled') 'preflight reconoce release null'
$plugin.release = ''
Assert-RC10-Release (-not (Validate-RC10).passed) 'release vacío no equivale a null'
$preflight = Get-PluginPreflightStatus -Component $plugin.component `
    -Source $plugin -Target $plugin -Pin $pin -Used $true -Module $null
Assert-RC10-Release ($preflight.status -eq 'wrong_or_unpinned_version') `
    'preflight no relaja release'
Write-Output 'RC10_RELEASE_OK null=accepted mismatch=blocked pin=checked'
