$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../scripts/PluginIntervention.ps1"

function Assert-RC3($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC3_PS_CONTRACT_FAILED $Label" }
}
$fixture = Get-Content -Raw -Encoding UTF8 `
    "$PSScriptRoot/fixtures/rc3-plugin-scenarios.json" | ConvertFrom-Json
$before = $fixture.before
$after = $before | ConvertTo-Json -Depth 50 | ConvertFrom-Json
$after.plugins = @($after.plugins) + @($fixture.replacement_plugins)
$after.activity_modules = @($after.activity_modules) + @(
    [pscustomobject]@{ name = 'zoom'; visible = 1 },
    [pscustomobject]@{ name = 'vpl2'; visible = 1 }
)
$needs = @(Get-PluginCompatibilityNeeds -Sources $fixture.sources -Target $before)
Assert-RC3 ($needs.Count -eq 3) 'tres necesidades detectadas'
$diff = Get-PluginInventoryDiff $before $after
Assert-RC3 (@($diff.newly_detected_plugins).Count -eq 2) 'tres necesidades a dos reemplazos'
Assert-RC3 (@($diff.updated_plugins).Count -eq 0) 'sin cambios falsos'
$tech = Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true
Assert-RC3 $tech.passed 'moodle válido con dos reemplazos sin coincidencia de nombre'

$after.plugins = @($after.plugins) + @(
    1..3 | ForEach-Object {
        $copy = $fixture.replacement_plugins[0] | ConvertTo-Json -Depth 20 |
            ConvertFrom-Json
        $copy.component = "local_aux$_"; $copy.name = "aux$_";
        $copy.tree_sha256 = ([string]$_ * 64); $copy
    }
)
$diff = Get-PluginInventoryDiff $before $after
Assert-RC3 (@($diff.newly_detected_plugins).Count -eq 5) 'tres necesidades a cinco plugins'
Assert-RC3 ((Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true).passed) 'cinco plugins válidos'

$disabled = $fixture.replacement_plugins[0] | ConvertTo-Json -Depth 20 |
    ConvertFrom-Json
$disabled.component = 'mod_vpl'; $disabled.name = 'vpl'
$disabled.enabled = $false
$after.plugins = @($before.plugins) + @($disabled)
$after.activity_modules = @($before.activity_modules) + @(
    [pscustomobject]@{ name = 'vpl'; visible = 0 }
)
$needsDisabled = @(Get-PluginCompatibilityNeeds -Sources $fixture.sources -Target $after)
Assert-RC3 (($needsDisabled | Where-Object component -eq 'mod_vpl').observed_state -eq
    'installed_disabled') 'plugin instalado deshabilitado no es missing'
Assert-RC3 ((Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true).passed) 'disabled permite revisión manual'
$after.plugins[-1].installation_status = 'incompatible'
Assert-RC3 (-not (Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true).passed) 'incompatible bloquea aprobación'
$after.plugins[-1].installation_status = 'uptodate'
$after.plugins[-1].dependencies = [pscustomobject]@{ local_missing = 1 }
Assert-RC3 (-not (Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true).passed) 'dependencia sin instalar bloquea'
$after.plugins[-1].dependencies = [pscustomobject]@{}
$after.plugins[-1].version_file_valid = $false
Assert-RC3 (-not (Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $true -MoodleReady $true).passed) 'version.php inválido bloquea'
$after.plugins[-1].version_file_valid = $true
Assert-RC3 (-not (Test-PluginTechnicalInventory -Inventory $after -TargetId 'target' `
    -ExpectedUrl 'https://moodle.example.edu' `
    -PinHash $before.approved_plugins_sha256 `
    -UpgradeSucceeded $false -MoodleReady $true).passed) 'upgrade fallido bloquea'

$hash = Get-PluginInventoryFingerprint $after
$after | Add-Member -NotePropertyName generated_at_utc `
    -NotePropertyValue '2026-09-20T10:00:00Z'
Assert-RC3 ((Get-PluginInventoryFingerprint $after) -eq $hash) 'snapshot estable ante timestamps'
$after.plugins[-1].tree_sha256 = 'e' * 64
Assert-RC3 ((Get-PluginInventoryFingerprint $after) -ne $hash) 'plugin modificado invalida resume'
$resolution = [pscustomobject]@{
    schema_version = '1.0'; config_sha256 = 'config'; target_id = 'target'
    source_fingerprint = 'source'; inventory_before_sha256 = 'before'
    inventory_after_sha256 = 'after'; inventory_after_fingerprint = 'fingerprint'
    approved_plugins_sha256 = 'pin'; technical_validation = 'passed'
    operator_decision = 'approved'; resolution = 'manual_compatibility_accepted'
}
$arguments = @{
    Resolution = $resolution; ConfigHash = 'config'; TargetId = 'target'
    SourceFingerprint = 'source'; BeforeHash = 'before'; AfterHash = 'after'
    AfterFingerprint = 'fingerprint'; PinHash = 'pin'
}
Assert-RC3 (Test-PluginInterventionResolution @arguments) 'aprobación sellada'
$arguments.AfterFingerprint = 'altered'
Assert-RC3 (-not (Test-PluginInterventionResolution @arguments)) 'invalida cambios al inventario'
Write-Output 'RC3_PS_CONTRACTS_OK needs=3 replacements=2,5 disabled=warning technical=blocking resume=sealed'
