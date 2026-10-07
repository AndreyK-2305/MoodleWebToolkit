$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
. "$root/scripts/ConfigAccess.ps1"

function Assert-RC2([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "V8_RC2_POWERSHELL_FAILED: $Message" }
}

$lockPath = Get-DestinationWriteLockPath
Assert-RC2 (-not [string]::IsNullOrWhiteSpace($lockPath)) `
    'DestinationWriteLockPath quedó null'
Assert-RC2 ($lockPath -eq (Join-Path $root 'reports/destination-write.lock.json')) `
    'DestinationWriteLockPath no es determinista desde ProjectRoot'
$cleanProbe = Test-Path -LiteralPath $lockPath -PathType Leaf
Assert-RC2 ($cleanProbe -is [bool]) 'Test-Path limpio no devolvió booleano'

$issues = @()
Get-ChildItem (Join-Path $root 'scripts') -Filter '*.ps1' | ForEach-Object {
    $tokens = $null
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile(
        $_.FullName, [ref]$tokens, [ref]$errors
    )
    if ($errors) { $issues += $errors }
}
Assert-RC2 ($issues.Count -eq 0) 'algún .ps1 conserva ParserError'

$audit = Get-Content -LiteralPath (Join-Path $root 'scripts/audit-package-plugins.ps1') `
    -Raw -Encoding UTF8
Assert-RC2 ($audit.Contains('Wait-DestinationStartupReady') -and
    $audit.Contains('Invoke-SerializedDestinationUpgrade') -and
    $audit.Contains('PLUGIN_UPGRADE_FAILED') -and
    $audit.Contains('REMOVED_CORE_COMPONENT_RESOLVED') -and
    $audit.Contains('TARGET_PLUGIN_PIN_VERIFIED') -and
    -not $audit.Contains('"PLUGIN_REQUIRED_ENABLE_FAILED: $component: "')) `
    'readiness/serialización/pin/replacement no conserva el contrato RC2'

Write-Output 'V8_RC2_POWERSHELL_OK clean_start=1 parser=all plugin_upgrade=serialized pin=1 replacement=resolved'
