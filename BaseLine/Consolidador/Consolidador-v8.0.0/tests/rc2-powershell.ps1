$ErrorActionPreference = "Stop"
. "$PSScriptRoot/../scripts/PluginPreflight.ps1"
. "$PSScriptRoot/../scripts/WorkerCount.ps1"
. "$PSScriptRoot/../scripts/Notifications.ps1"

function Assert-RC2($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC2_PS_CONTRACT_FAILED $Label" }
}
$fixture = Get-Content -LiteralPath "$PSScriptRoot/fixtures/rc2-scenarios.json" `
    -Raw -Encoding UTF8 | ConvertFrom-Json
$env:CONSOLIDATION_WORKERS = [string]$fixture.restore_cases.workers
Assert-RC2 ((Resolve-WorkerCount) -eq 2) "workers=2"
$env:CONSOLIDATION_WORKERS = "5"
try { [void](Resolve-WorkerCount); throw "workers inválidos admitidos" }
catch { Assert-RC2 ($_.Exception.Message -match "entre 1 y 4") "límite workers" }
$env:CONSOLIDATION_EMAIL_ENABLED = "0"
$env:CONSOLIDATION_SMTP_HOST = ""
Assert-RC2 (-not (Test-ConsolidationNotificationsEnabled)) "sendmail deshabilitado"
Send-ConsolidationNotification -Stage "fixture" -Status "OK" -Message "sin envío"

$source = [pscustomobject]@{ source = "additional"; version_disk = 2026091900 }
$tree = 'a' * 64
$commit = 'b' * 40
$pin = [pscustomobject]@{
    version = 2026091900; release = '1.0'; tree_sha256 = $tree; commit = $commit
}
$target = [pscustomobject]@{
    enabled = $true; version_disk = 2026091900; version_db = 2026091900
    installation_status = 'uptodate'; release = '1.0'
    tree_sha256 = $tree; approved_commit = $commit
}
$module = [pscustomobject]@{ visible = 1 }
$cases = @(
    @{ Name = "enabled"; Target = $target; Pin = $pin; Module = $module; Block = $false },
    @{ Name = "missing"; Target = $null; Pin = $pin; Module = $module; Block = $true },
    @{ Name = "installed_disabled"; Target = $target; Pin = $pin
        Module = [pscustomobject]@{ visible = 0 }; Block = $true },
    @{ Name = "incompatible"; Target = [pscustomobject]@{
        enabled = $true; version_disk = 2026091900; version_db = 2026091900
        installation_status = 'incompatible'; release = '1.0'
        tree_sha256 = $tree; approved_commit = $commit
    }; Pin = $pin; Module = $module; Block = $true },
    @{ Name = "older_version"; Target = [pscustomobject]@{
        enabled = $true; version_disk = 2026091800; version_db = 2026091800
        installation_status = 'uptodate'; release = '1.0'
        tree_sha256 = $tree; approved_commit = $commit
    }; Pin = [pscustomobject]@{
        version = 2026091800; release = '1.0'; tree_sha256 = $tree; commit = $commit
    }; Module = $module; Block = $true },
    @{ Name = "wrong_or_unpinned_version"; Target = $target; Pin = $null
        Module = $module; Block = $true }
)
foreach ($case in $cases) {
    $actual = Get-PluginPreflightStatus -Component 'mod_hvp' `
        -Source $source -Target $case.Target -Pin $case.Pin `
        -Used $true -Module $case.Module
    Assert-RC2 ($actual.status -eq $case.Name -and
        $actual.blocking -eq $case.Block) "plugin $($case.Name)"
}
Write-Output "RC2_PS_CONTRACTS_OK workers=2 mail=disabled plugin_cases=$($cases.Count)"
