$ErrorActionPreference = 'Stop'

function Assert-Plan([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "V8_PLUGIN_SERIALIZATION_FAILED: $Message" }
}

$root = Split-Path -Parent $PSScriptRoot
$path = Join-Path $root 'scripts/audit-package-plugins.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $path, [ref]$tokens, [ref]$errors
)
Assert-Plan ($errors.Count -eq 0) 'audit-package-plugins.ps1 no parsea'

$required = @(
    'Get-PluginUpgradeTimeoutSeconds',
    'Wait-DestinationStartupReady',
    'Test-DestinationUpgradeRequired',
    'Invoke-SerializedDestinationUpgrade',
    'Get-PluginUpgradeFailureDiagnostic',
    'Invoke-DestinationUpgradeWait',
    'Test-RemovedCoreComponentResolution'
)
foreach ($name in $required) {
    $found = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
            $node.Name -eq $name
    }, $true))
    Assert-Plan ($found.Count -eq 1) "falta función $name"
    Invoke-Expression $found[0].Extent.Text
}

$source = Get-Content -LiteralPath $path -Raw -Encoding UTF8
Assert-Plan ($source -match "'flock'" -and
    $source -match "'/run/moodle-upgrade\.lock'") `
    'upgrade posterior no usa flock'
Assert-Plan ($source -match '--is-pending') `
    'auditor no consulta si Moodle requiere upgrade'
Assert-Plan ($source -match 'REMOVED_CORE_COMPONENT_RESOLVED') `
    'replacement resuelto no tiene estado explícito'
Assert-Plan ($source -notmatch 'mod_chat no admite equivalencias') `
    'permanece el bloqueo absoluto antiguo de mod_chat'

$service = 'moodle-target'
$script:upgradeScenario = 'not_pending'
$script:serializedCalls = 0
function Invoke-DockerCaptured([string[]]$Arguments) {
    $command = $Arguments -join ' '
    if ($command -match 'test -f /run/moodle-startup-ready') {
        return [pscustomobject]@{ ExitCode = 0; Stdout = ''; Stderr = '' }
    }
    if ($command -match 'php -r') {
        return [pscustomobject]@{
            ExitCode = 0; Stdout = 'DESTINATION_READY'; Stderr = ''
        }
    }
    if ($command -match '--is-pending') {
        $exit = if ($script:upgradeScenario -eq 'not_pending') { 0 } else { 2 }
        return [pscustomobject]@{ ExitCode = $exit; Stdout = ''; Stderr = '' }
    }
    if ($command -match 'flock --exclusive') {
        $script:serializedCalls++
        if ($script:upgradeScenario -eq 'pending_failure') {
            return [pscustomobject]@{
                ExitCode = 1; Stdout = ''; Stderr = 'Table already exists'
            }
        }
        return [pscustomobject]@{ ExitCode = 0; Stdout = 'Upgrade complete'; Stderr = '' }
    }
    if ($command -match '^compose ps -q') {
        return [pscustomobject]@{ ExitCode = 0; Stdout = 'container-id'; Stderr = '' }
    }
    if ($command -match '^inspect ') {
        return [pscustomobject]@{ ExitCode = 0; Stdout = '0|running|healthy'; Stderr = '' }
    }
    throw "comando Docker inesperado en fixture: $command"
}

$notPending = Invoke-DestinationUpgradeWait
Assert-Plan ($notPending.Ready -and $notPending.Attempts -eq 0 -and
    $script:serializedCalls -eq 0) 'ejecutó upgrade sin estar pendiente'

$script:upgradeScenario = 'pending_success'
$pending = Invoke-DestinationUpgradeWait
Assert-Plan ($pending.Ready -and $pending.Attempts -eq 1 -and
    $script:serializedCalls -eq 1) 'upgrade pendiente no se serializó exactamente una vez'

$script:upgradeScenario = 'pending_failure'
$failed = Invoke-DestinationUpgradeWait
Assert-Plan (-not $failed.Ready -and $failed.Attempts -eq 1 -and
    $script:serializedCalls -eq 2 -and
    $failed.Diagnostic.status -eq 'PLUGIN_UPGRADE_FAILED' -and
    $failed.Diagnostic.first_error -eq 'Table already exists' -and
    $failed.Diagnostic.restart_count -eq 0 -and
    $failed.Diagnostic.moodle_state -eq 'running/healthy') `
    'el fallo serializado no produjo diagnóstico inmediato y único'

Write-Output 'PLUGIN_UPGRADE_SERIALIZED_OK startup=ready pending=probed lock=flock calls=1 failure=single diagnostics=complete'
Write-Output 'MOD_CHAT_REPLACEMENT_OK state=REMOVED_CORE_COMPONENT_RESOLVED'
