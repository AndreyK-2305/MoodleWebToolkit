$ErrorActionPreference = 'Stop'

function Assert-RC13([bool]$Condition, [string]$Label) {
    if (-not $Condition) { throw "RC13_WIZARD_PATHS_FAILED: $Label" }
}

# Ejecuta las funciones reales del wizard sin iniciar el asistente ni Docker.
$wizard = Join-Path $PSScriptRoot '../scripts/consolidation-wizard.ps1'
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $wizard, [ref]$tokens, [ref]$parseErrors
)
Assert-RC13 ($parseErrors.Count -eq 0) 'sintaxis del wizard'
$names = @('Read-JsonDocument', 'Get-CurrentConfigHash',
    'Test-ConfigBoundDocument', 'Test-SealedFileHash',
    'Get-LowerFileHash', 'Test-IdentityReconciliation')
$definitions = $ast.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
    $node.Name -in $names
}, $true)
Assert-RC13 ($definitions.Count -eq $names.Count) 'funciones reales presentes'
foreach ($definition in $definitions) {
    Invoke-Expression $definition.Extent.Text
}

# Prueba con una ruta absoluta de estilo /srv/... sin exigir permiso para
# crear directamente en /srv del equipo que ejecuta verify-package.sh.
$scratchRoot = Join-Path ([IO.Path]::GetTempPath()) `
    ('rc13-paths-' + [guid]::NewGuid().ToString('N'))
$linuxProjectRoot = '/srv/consolidador-v7.4.0-rc13/Consolidador'
$ProjectRoot = Join-Path $scratchRoot $linuxProjectRoot.TrimStart('/')
$relative = 'exports/phase3/shared_email_audit.csv'
$auditAbsolute = Join-Path $ProjectRoot $relative
$summaryAbsolute = Join-Path $ProjectRoot 'exports/phase3/summary.json'
$policyAbsolute = Join-Path $ProjectRoot 'config/identity-policy.json'
$fuzzyAbsolute = Join-Path $ProjectRoot 'config/fuzzy_identity_resolutions.csv'
$configAbsolute = Join-Path $ProjectRoot 'config.yaml'
$script:ObservedHashPaths = [System.Collections.Generic.List[string]]::new()

# Permite observar todas las rutas reales pasadas al cmdlet por el wizard.
function Get-FileHash {
    param([string]$LiteralPath, [string]$Algorithm = 'SHA256')
    $script:ObservedHashPaths.Add($LiteralPath)
    Microsoft.PowerShell.Utility\Get-FileHash -LiteralPath $LiteralPath `
        -Algorithm $Algorithm
}

try {
    New-Item -ItemType Directory -Force -Path `
        (Split-Path $auditAbsolute), (Split-Path $policyAbsolute) | Out-Null
    [IO.File]::WriteAllText($configAbsolute, "mode: production`n")
    [IO.File]::WriteAllText($policyAbsolute, '{"schema_version":"1.0"}')
    [IO.File]::WriteAllText($fuzzyAbsolute,
        "resolution_id,candidate_group,decision,source_account,canonical_account,canonical_email,canonical_username,oauth_policy,approved_by,approved_at_utc,evidence_reference,justification,algorithm_version,previous_canonical_ids,active`n")
    $originalAudit = "email,canonical_id`nshared@example.com,CAN-A`nshared@example.com,CAN-B`n"
    [IO.File]::WriteAllText($auditAbsolute, $originalAudit)
    $auditHash = (Microsoft.PowerShell.Utility\Get-FileHash -LiteralPath `
        $auditAbsolute -Algorithm SHA256).Hash.ToLowerInvariant()
    $policyHash = (Microsoft.PowerShell.Utility\Get-FileHash -LiteralPath `
        $policyAbsolute -Algorithm SHA256).Hash.ToLowerInvariant()
    $configHash = (Microsoft.PowerShell.Utility\Get-FileHash -LiteralPath `
        $configAbsolute -Algorithm SHA256).Hash.ToLowerInvariant()
    $fuzzyHash = (Microsoft.PowerShell.Utility\Get-FileHash -LiteralPath `
        $fuzzyAbsolute -Algorithm SHA256).Hash.ToLowerInvariant()
    @{
        schema_version = '1.6'
        config_sha256 = $configHash
        identity_policy_sha256 = $policyHash
        fuzzy_identity_resolutions_sha256 = $fuzzyHash
        shared_email_audit_sha256 = $auditHash
        identity_conflicts_unresolved = 0
        phase4_expected = @{
            blocked_identities = 0
            identity_review_pending = 0
        }
        apply_performed = $false
    } | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $summaryAbsolute `
        -Encoding utf8
    $summaryBefore = [IO.File]::ReadAllBytes($summaryAbsolute)

    Assert-RC13 ([bool](Test-IdentityReconciliation)) `
        'Fase 3 válida bloqueada con ProjectRoot absoluto Linux'
    Assert-RC13 ($script:ObservedHashPaths.Contains($auditAbsolute)) `
        'no se consultó el audit en su ruta física exacta'
    foreach ($observed in $script:ObservedHashPaths) {
        Assert-RC13 (-not $observed.Contains(
            $ProjectRoot.TrimEnd('/') + $ProjectRoot)) `
            "ProjectRoot duplicado: $observed"
    }
    Assert-RC13 ([bool](Test-SealedFileHash $auditHash $relative)) `
        'el contrato relativo del helper histórico dejó de funcionar'

    [IO.File]::AppendAllText($auditAbsolute, "manipulado`n")
    Assert-RC13 (-not (Test-IdentityReconciliation)) `
        'el wizard aceptó un audit modificado con summary sellado'
    Assert-RC13 (-not (Test-SealedFileHash $auditHash $relative)) `
        'el helper histórico aceptó un audit modificado'
    Assert-RC13 ([Convert]::ToBase64String([IO.File]::ReadAllBytes($summaryAbsolute)) -eq
        [Convert]::ToBase64String($summaryBefore)) 'el checkpoint modificó summary.json'

    [IO.File]::WriteAllText($auditAbsolute, $originalAudit)
    Assert-RC13 ([bool](Test-IdentityReconciliation)) `
        'reintento no reconoció artefactos restaurados intactos'
    Assert-RC13 (-not (Test-Path -LiteralPath `
        (Join-Path $ProjectRoot 'exports/phase4/apply_summary.json'))) `
        'la comprobación host-side escribió en el destino'
    Write-Output 'RC13_WIZARD_PATHS_OK linux_absolute=1 root_once=1 sha_tamper=blocked retry=ok'
} finally {
    Remove-Item -LiteralPath $scratchRoot -Recurse -Force `
        -ErrorAction SilentlyContinue
}
