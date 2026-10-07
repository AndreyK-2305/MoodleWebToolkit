$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../scripts/PluginIntervention.ps1"
function Assert-RC7($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC7_DEPENDENCIES_FAILED: $Label" }
}
$fixture = Get-Content -Raw "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" | ConvertFrom-Json
$inventory = $fixture.before
$base = $inventory.plugins[0]
function Plugin($Component, $Version, $Dependencies) {
    $p = $base | ConvertTo-Json -Depth 20 | ConvertFrom-Json
    $p.component = $Component; $p.version_db = $Version
    $p.version_disk = $Version; $p.dependencies = $Dependencies
    return $p
}
function Validate($Plugins) {
    $inventory.plugins = @($Plugins)
    return Test-PluginTechnicalInventory -Inventory $inventory -TargetId 'target' `
        -ExpectedUrl 'https://moodle.example.edu' `
        -PinHash $inventory.approved_plugins_sha256 `
        -UpgradeSucceeded $true -MoodleReady $true
}
$empty = @() # El algoritmo de RC6 intentaba convertir las propiedades de este array a Int64.
$custom = Plugin 'mod_customcert' 2026042013 $empty
$behaviour = Plugin 'qbehaviour_adaptive_adapted_for_coderunner' 2026081000 @()
$coderunner = Plugin 'qtype_coderunner' 2026091501 `
    ([pscustomobject]@{ qbehaviour_adaptive_adapted_for_coderunner = 2026070100 })
$theme = Plugin 'theme_academi' 2026042800 `
    ([pscustomobject]@{ theme_boost = 2026042000 })
$boost = Plugin 'theme_boost' 2026042000 ([pscustomobject]@{})
Assert-RC7 ((Validate @($custom)).passed) 'dependencies []'
$custom.dependencies = [pscustomobject]@{}
Assert-RC7 ((Validate @($custom)).passed) 'dependencies {}'
$custom.dependencies = @()
$all = @($custom, $behaviour, $coderunner, $theme, $boost)
Assert-RC7 ((Validate $all).passed) 'cuatro plugins reales y boost'
$behaviour.version_db = 2026070000; $behaviour.version_disk = 2026070000
$result = Validate $all
Assert-RC7 (-not $result.passed -and @($result.issues | Where-Object {
    $_.field -eq 'dependencies.qbehaviour_adaptive_adapted_for_coderunner'
}).Count -eq 1) 'dependencia insuficiente identificada'
$behaviour.version_db = 2026081000; $behaviour.version_disk = 2026081000
foreach ($bad in @(@(123), 'abc', $true, ([pscustomobject]@{ mod_x = @('2026041000') }),
        ([pscustomobject]@{ mod_x = 'abc' }), ([pscustomobject]@{ mod_x = @{ nested = 1 } }))) {
    $custom.dependencies = $bad
    $result = Validate $all
    Assert-RC7 (-not $result.passed -and @($result.issues | Where-Object {
        $_ -is [System.Collections.IDictionary] -and $_.component -eq 'mod_customcert' -and
        $_.field -like 'dependencies*'
    }).Count -gt 0) "forma de dependencias inválida: $bad"
}
$custom.dependencies = @()
$custom.version_db = @(2026042013)
Assert-RC7 (-not (Validate $all).passed) 'version_db array'
$custom.version_db = 2026042013; $custom.version_disk = @(2026042013)
Assert-RC7 (-not (Validate $all).passed) 'version_disk array'
Write-Output 'RC7_DEPENDENCIES_OK empty=[] empty={} map=ok missing=identified invalid=controlled four_plugins=passed'
