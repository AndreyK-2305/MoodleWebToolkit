param(
    [switch]$Automatic
)

$ErrorActionPreference = "Stop"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8NoBom
[Console]::OutputEncoding = $utf8NoBom
$OutputEncoding = $utf8NoBom

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot
. "$PSScriptRoot/ConfigAccess.ps1"
. "$PSScriptRoot/Notifications.ps1"
$DestinationWriteLockPath = Get-DestinationWriteLockPath

$script:AssistantExitCode = 0
$automaticDelaySeconds = 0
$configuredDelay = 0
if ([int]::TryParse(
        [string]$env:CONSOLIDATION_AUTO_DELAY_SECONDS,
        [ref]$configuredDelay
    ) -and $configuredDelay -ge 0 -and $configuredDelay -le 3600) {
    $automaticDelaySeconds = $configuredDelay
}

$statePath = Join-Path $ProjectRoot "reports\assistant-state.json"
$eventLogPath = Join-Path $ProjectRoot "reports\asistente-consolidacion.log"
New-Item -ItemType Directory -Force `
    -Path (Join-Path $ProjectRoot "reports") | Out-Null

function Write-AssistantEvent {
    param(
        [string]$Stage,
        [string]$Status,
        [string]$Message
    )
    $safeMessage = $Message.Replace("`r", " ").Replace("`n", " ")
    $line = (
        [DateTime]::UtcNow.ToString("o") + "`t" +
        $Stage + "`t" + $Status + "`t" + $safeMessage
    )
    Add-Content -LiteralPath $eventLogPath -Value $line -Encoding UTF8
}

function Write-AssistantState {
    param(
        [string]$Stage,
        [string]$Status,
        [string]$Message,
        [string[]]$ReviewPaths = @()
    )
    $state = [ordered]@{
        schema_version = "1.0"
        assistant_version = "8.0.0-linux-rc12"
        updated_at_utc = [DateTime]::UtcNow.ToString("o")
        stage = $Stage
        status = $Status
        message = $Message
        execution_mode = $(
            if ($Automatic) { "automatic" } else { "interactive" }
        )
        review_paths = @($ReviewPaths)
        destination_write_recorded = (
            (Test-Path -LiteralPath (
                Join-Path $ProjectRoot "exports\phase4\apply_summary.json"
            ) -PathType Leaf) -or
            (Test-Path -LiteralPath (
                Join-Path $ProjectRoot "exports\phase5\apply_summary.json"
            ) -PathType Leaf) -or
            (Test-Path -LiteralPath (
                Join-Path $ProjectRoot "exports\phase6\batch_apply_summary.json"
            ) -PathType Leaf)
        )
    }
    [System.IO.File]::WriteAllText(
        $statePath,
        ($state | ConvertTo-Json -Depth 8) + [Environment]::NewLine,
        $utf8NoBom
    )
    Write-AssistantEvent -Stage $Stage -Status $Status -Message $Message
}

function Read-JsonDocument {
    param([string]$RelativePath)
    $path = Join-Path $ProjectRoot $RelativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        return $null
    }
    try {
        return Get-Content -LiteralPath $path -Raw -Encoding UTF8 |
            ConvertFrom-Json
    } catch {
        return $null
    }
}

function Get-CurrentConfigHash {
    $path = Join-Path $ProjectRoot "config.yaml"
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        return ""
    }
    return (
        Get-FileHash -LiteralPath $path -Algorithm SHA256
    ).Hash.ToLowerInvariant()
}

function Test-ConfigBoundDocument {
    param([object]$Document)
    if ($null -eq $Document) {
        return $false
    }
    $configHash = Get-CurrentConfigHash
    return (
        $configHash -ne "" -and
        [string]$Document.config_sha256 -eq $configHash
    )
}

function Test-PackageImport {
    $summary = Read-JsonDocument "exports\phase1\package_index.json"
    if (-not (Test-ConfigBoundDocument $summary) -or
            [string]$summary.import_status -ne "passed" -or
            -not [bool]$summary.packages_verified -or
            [bool]$summary.destination_write_performed -or
            [int]$summary.sources -lt 1 -or
            [int]$summary.courses -lt 1) {
        return $false
    }
    foreach ($item in @($summary.package_index)) {
        $manifestPath = Join-Path $ProjectRoot `
            "exports\packages\$($item.source_id)\manifest.json"
        if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
            return $false
        }
        $actual = (
            Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256
        ).Hash.ToLowerInvariant()
        if ($actual -ne ([string]$item.manifest_sha256).ToLowerInvariant()) {
            return $false
        }
    }
    return $true
}

function Test-ConfigurationConfirmation {
    $confirmation = Read-JsonDocument `
        "reports\configuration-confirmation.json"
    if ($null -eq $confirmation) {
        return $false
    }
    return (
        [string]$confirmation.workflow -eq "source-packages" -and
        [string]$confirmation.config_sha256 -eq (Get-CurrentConfigHash)
    )
}

function Test-SealedFileHash($ExpectedHash, [string]$RelativePath) {
    $path = Join-Path $ProjectRoot $RelativePath
    return (
        [string]$ExpectedHash -match '^[a-f0-9]{64}$' -and
        (Test-Path -LiteralPath $path -PathType Leaf) -and
        (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -eq
            [string]$ExpectedHash
    )
}

function Test-PluginAudit {
    $checkpoint = Read-JsonDocument `
        "exports\phase2\plugin_intervention_checkpoint.json"
    if ([string]$checkpoint.status -ne 'PLUGIN_INTERVENTION_APPROVED') {
        return $false
    }
    $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode Check
    return [string]$result.status -eq 'PLUGIN_INTERVENTION_APPROVED'
}

function Test-V8PluginLock {
    try {
        $result = & "$PSScriptRoot/v8-plugin-resolver.ps1" -Mode Check
        return [string]$result.lock_status -eq 'SEALED' -and
            [bool]$result.plugin_lock_valid
    } catch {
        return $false
    }
}

function Test-V8Readiness {
    try {
        $result = & "$PSScriptRoot/v8-readiness.ps1" -Mode Check
        return [string]$result.status -eq 'READY_TO_RUN' -and
            [bool]$result.ready_to_run
    } catch {
        return $false
    }
}

function Assert-PostWriteAnchor {
    $lock = Read-JsonDocument 'reports\destination-write.lock.json'
    $readinessPath = Join-Path $ProjectRoot 'exports/readiness.json'
    $oauthConfigPath = Join-Path $ProjectRoot 'config/oauth2.json'
    if ($null -eq $lock -or
            [string]$lock.schema_version -ne '1.1' -or
            -not (Test-Path -LiteralPath $readinessPath -PathType Leaf) -or
            -not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
        throw 'POST_WRITE_ANCHOR_INVALID: faltan lock/readiness OAuth de la ejecución.'
    }
    $readinessHash = (Get-FileHash -LiteralPath $readinessPath `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    $oauthHash = (Get-FileHash -LiteralPath $oauthConfigPath `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    $readiness = Read-JsonDocument 'exports/readiness.json'
    if ([string]$lock.config_sha256 -ne (Get-CurrentConfigHash) -or
            [string]$lock.oauth_config_sha256 -ne $oauthHash -or
            [string]$lock.readiness_sha256 -ne $readinessHash -or
            [string]$readiness.status -ne 'READY_TO_RUN' -or
            -not [bool]$readiness.ready_to_run) {
        throw (
            'POST_WRITE_ANCHOR_INVALID: una entrada sellada pre-write cambió. ' +
            'No se regenerarán automáticamente plugins, OAuth ni readiness.'
        )
    }
}

function Test-OAuth2Ready {
    if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'config.yaml') `
            -PathType Leaf)) {
        return $false
    }
    $summary = Read-JsonDocument "exports\oauth2\validation.json"
    $oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
    if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
        return $false
    }
    $oauthConfigHash = (
        Get-FileHash -LiteralPath $oauthConfigPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.oauth_config_sha256 -eq $oauthConfigHash -and
        [string]$summary.status -eq "ready" -and
        [string]$summary.validation -eq "passed" -and
        [string]$summary.expected_public_url -ceq [string](Get-TargetSite).url -and
        [string]$summary.public_url_validation -eq "passed" -and
        [bool]$summary.reverseproxy -and
        [bool]$summary.sslproxy -and
        [string]$summary.callback_url -ceq (
            ([string](Get-TargetSite).url).TrimEnd("/") + "/admin/oauth2callback.php"
        ) -and
        [int]$summary.issuer_id -gt 0 -and
        [bool]$summary.auth_plugin_enabled -and
        [bool]$summary.client_credentials_present -and
        [bool]$summary.show_on_login_page -and
        [bool]$summary.issuer_enabled -and
        [int]$summary.endpoints_configured -gt 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Test-OAuth2LiveReady {
    if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'config.yaml') `
            -PathType Leaf)) {
        return $false
    }
    $original = Read-JsonDocument 'exports\oauth2\validation.json'
    $live = Read-JsonDocument 'exports\oauth2-live\validation.json'
    $resolution = Read-JsonDocument `
        'exports\phase2\plugin_intervention_resolution.json'
    $oauthConfigPath = Join-Path $ProjectRoot 'config\oauth2.json'
    if ($null -eq $original -or $null -eq $live -or
            $null -eq $resolution -or
            -not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
        return $false
    }
    $hash = (Get-FileHash -LiteralPath $oauthConfigPath `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    $targetUrl = [string](Get-TargetSite).url
    return ((Test-ConfigBoundDocument $live) -and
        [string]$live.oauth_config_sha256 -eq $hash -and
        [string]$live.status -eq 'ready' -and
        [string]$live.validation -eq 'passed' -and
        [string]$live.expected_public_url -ceq $targetUrl -and
        [string]$live.public_url_validation -eq 'passed' -and
        [bool]$live.reverseproxy -and [bool]$live.sslproxy -and
        [string]$live.callback_url -ceq (
            $targetUrl.TrimEnd('/') + '/admin/oauth2callback.php') -and
        [int]$live.issuer_id -gt 0 -and
        [int]$live.issuer_id -eq [int]$original.issuer_id -and
        [bool]$live.auth_plugin_enabled -and
        [bool]$live.client_credentials_present -and
        [bool]$live.show_on_login_page -and
        [bool]$live.issuer_enabled -and
        [int]$live.endpoints_configured -gt 0 -and
        (Get-Item -LiteralPath (Join-Path $ProjectRoot `
            'exports/oauth2-live/validation.json')).LastWriteTimeUtc -ge
        (Get-Item -LiteralPath (Join-Path $ProjectRoot `
            'exports/phase2/plugin_intervention_resolution.json')).LastWriteTimeUtc)
}

function Test-IdentityReconciliation {
    $summary = Read-JsonDocument "exports\phase3\summary.json"
    $sharedAuditRelative = 'exports/phase3/shared_email_audit.csv'
    $sharedAudit = Join-Path $ProjectRoot $sharedAuditRelative
    $identityPolicyPath = Join-Path $ProjectRoot "config\identity-policy.json"
    if (-not (Test-Path -LiteralPath $identityPolicyPath -PathType Leaf)) {
        return $false
    }
    $identityPolicyHash = (
        Get-FileHash -LiteralPath $identityPolicyPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    $fuzzyResolutions = Join-Path $ProjectRoot 'config/fuzzy_identity_resolutions.csv'
    if (-not (Test-Path -LiteralPath $fuzzyResolutions -PathType Leaf)) {
        return $false
    }
    $fuzzyHash = (Get-FileHash -LiteralPath $fuzzyResolutions `
        -Algorithm SHA256).Hash.ToLowerInvariant()
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.schema_version -eq "1.6" -and
        [string]$summary.identity_policy_sha256 -eq $identityPolicyHash -and
        [string]$summary.fuzzy_identity_resolutions_sha256 -eq $fuzzyHash -and
        (Test-Path -LiteralPath $sharedAudit -PathType Leaf) -and
        [string]$summary.shared_email_audit_sha256 -eq
            (Get-LowerFileHash $sharedAuditRelative) -and
        [int]$summary.identity_conflicts_unresolved -eq 0 -and
        [int]$summary.phase4_expected.blocked_identities -eq 0 -and
        [int]$summary.phase4_expected.identity_review_pending -eq 0 -and
        -not [bool]$summary.apply_performed
    )
}

function Test-Phase4Plan {
    $summary = Read-JsonDocument "exports\phase4\plan_summary.json"
    if ($null -eq $summary) { return $false }
    $phase3Bindings = [ordered]@{
        'canonical_users.csv' = 'exports/phase3/canonical_users.csv'
        'source_user_map.csv' = 'exports/phase3/source_user_map.csv'
        'identity_conflicts.csv' = 'exports/phase3/identity_conflicts.csv'
        'identity_resolution_audit.csv' = 'exports/phase3/identity_resolution_audit.csv'
        'fuzzy_identity_resolution_audit.csv' = 'exports/phase3/fuzzy_identity_resolution_audit.csv'
        'email_normalization_audit.csv' = 'exports/phase3/email_normalization_audit.csv'
        'shared_email_audit.csv' = 'exports/phase3/shared_email_audit.csv'
        'summary.json' = 'exports/phase3/summary.json'
    }
    foreach ($binding in $phase3Bindings.GetEnumerator()) {
        if ([string]$summary.phase3_input_sha256.($binding.Key) -ne
                (Get-LowerFileHash $binding.Value)) {
            return $false
        }
    }
    return (
        (Test-ConfigBoundDocument $summary) -and
        [int]$summary.blocking_conflicts -eq 0 -and
        [bool]$summary.target_allows_shared_email -and
        $null -ne $summary.duplicate_username_values -and
        [int]$summary.duplicate_username_values -eq 0 -and
        [int]$summary.identity_review_pending -eq 0 -and
        -not [bool]$summary.apply_performed
    )
}

function Test-Phase4Apply {
    $summary = Read-JsonDocument "exports\phase4\apply_summary.json"
    $oauth = Read-JsonDocument "exports\oauth2\validation.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        (Test-ConfigBoundDocument $oauth) -and
        [int]$summary.oauth2_issuer_id -gt 0 -and
        [int]$summary.oauth2_issuer_id -eq [int]$oauth.issuer_id -and
        [bool]$summary.apply_performed -and
        -not [bool]$summary.roles_applied -and
        -not [bool]$summary.enrolments_applied
    )
}

function Test-Phase4Verify {
    $summary = Read-JsonDocument "exports\phase4\verification.json"
    $oauth = Read-JsonDocument "exports\oauth2\validation.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        (Test-ConfigBoundDocument $oauth) -and
        [int]$summary.oauth2_issuer_id -gt 0 -and
        [int]$summary.oauth2_issuer_id -eq [int]$oauth.issuer_id -and
        [string]$summary.validation -eq "passed" -and
        [int]$summary.failed_checks -eq 0 -and
        [int]$summary.oauth2_links_failed -eq 0 -and
        [int]$summary.oauth2_links_verified -eq
            [int]$summary.oauth2_links_expected
    )
}

function Test-Phase5Plan {
    $summary = Read-JsonDocument "exports\phase5\plan_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [int]$summary.blocking_conflicts -eq 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Test-Phase5Apply {
    $summary = Read-JsonDocument "exports\phase5\apply_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [bool]$summary.apply_performed -and
        [bool]$summary.roles_applied -and
        [bool]$summary.enrolments_applied -and
        [bool]$summary.course_data_applied
    )
}

function Test-V8PilotThemes {
    $summary = Read-JsonDocument `
        "exports\theme-assignment-verification-pilot.json"
    return (
        $null -ne $summary -and
        [string]$summary.scope -eq 'pilot' -and
        [string]$summary.theme_plan_sha256 -eq
            (Get-LowerFileHash 'exports\theme-plan.json') -and
        [int]$summary.counts.failed_functional -eq 0
    )
}

function Test-V8AllThemes {
    $summary = Read-JsonDocument "exports\theme-assignment-verification.json"
    return (
        $null -ne $summary -and
        [string]$summary.scope -eq 'all' -and
        [string]$summary.theme_plan_sha256 -eq
            (Get-LowerFileHash 'exports\theme-plan.json') -and
        [int]$summary.counts.failed_functional -eq 0
    )
}

function Test-Phase5Verify {
    $summary = Read-JsonDocument "exports\phase5\verification.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.validation -eq "passed" -and
        [int]$summary.failed_checks -eq 0 -and
        [bool]$summary.course_data_applied
    )
}

function Test-Phase6Plan {
    $summary = Read-JsonDocument "exports\phase6\plan_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.plan_status -eq "applicable" -and
        [int]$summary.blocking_conflicts -eq 0 -and
        [int]$summary.blocked_categories -eq 0 -and
        [int]$summary.blocked_courses -eq 0 -and
        [int]$summary.blocked_identity_convergences -eq 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Test-Phase6Prepared {
    $summary = Read-JsonDocument "exports\phase6\batch_manifest.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.phase -eq '6-lightweight-batch-manifest' -and
        [string]$summary.manifest_status -eq 'BATCH_READY' -and
        [bool]$summary.single_extraction_pipeline -and
        [bool]$summary.worker_course_precheck -and
        [int]$summary.mbz_deep_open_count -eq 0 -and
        [int]$summary.raw_backups_created -eq 0 -and
        [int]$summary.courses_pending -eq [int]$summary.courses_expected -and
        [int]$summary.courses_prepared -eq 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Get-Phase6AuthorizationHash {
    if (-not (Test-Phase6Prepared)) { throw 'PHASE12_READY_REQUIRED' }
    $manifestHash = Get-LowerFileHash 'exports\phase6\batch_manifest.json'
    $bytes = $utf8NoBom.GetBytes($manifestHash + ':worker-course-precheck')
    return [Convert]::ToHexString(
        [System.Security.Cryptography.SHA256]::HashData($bytes)
    ).ToLowerInvariant()
}

function Get-MassExecutionMode {
    $document = Read-JsonDocument 'reports\mass-execution-mode.json'
    if ($null -eq $document -or
            [string]$document.batch_manifest_sha256 -ne
                (Get-LowerFileHash 'exports\phase6\batch_manifest.json') -or
            [string]$document.phase12_status -ne 'BATCH_READY' -or
            [string]$document.mode -notin @('foreground', 'background')) {
        return ''
    }
    return [string]$document.mode
}

function Set-MassExecutionMode([string]$Mode) {
    if ($Mode -notin @('foreground', 'background')) {
        throw 'MASS_EXECUTION_MODE_INVALID'
    }
    $path = Join-Path $ProjectRoot 'reports\mass-execution-mode.json'
    $document = [ordered]@{
        schema_version = '1.0'
        generated_at_utc = [DateTime]::UtcNow.ToString('o')
        mode = $Mode
        batch_manifest_sha256 = Get-LowerFileHash 'exports\phase6\batch_manifest.json'
        phase12_status = 'BATCH_READY'
        workers = [string]$env:CONSOLIDATION_WORKERS
    }
    [System.IO.File]::WriteAllText(
        $path,
        ($document | ConvertTo-Json -Depth 8) + [Environment]::NewLine,
        $utf8NoBom
    )
}

function Test-Phase6Apply {
    $summary = Read-JsonDocument "exports\phase6\batch_apply_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.apply_status -eq
            "applied_pending_batch_verification" -and
        [int]$summary.courses_pending -eq 0 -and
        [int]$summary.courses_applied -eq [int]$summary.courses_expected -and
        [bool]$summary.destination_write_performed
    )
}

function Test-Phase6Verify {
    $summary = Read-JsonDocument "exports\phase6\batch_verification.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.validation -eq "passed" -and
        [int]$summary.failed_courses -eq 0 -and
        [bool]$summary.pilot_preserved
    )
}

function Test-Phase7Closure {
    $summary = Read-JsonDocument "exports\phase7\closure_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.closure_status -in @(
            "evidence_consolidated",
            "lab_validated"
        ) -and
        [int]$summary.failed_courses -eq 0 -and
        -not [bool]$summary.moodle_write_performed
    )
}

function Test-SitePackage {
    $summary = Read-JsonDocument "exports\phase8\site_package_summary.json"
    if (-not (Test-ConfigBoundDocument $summary) -or
            [string]$summary.status -ne "sealed" -or
            -not [bool]$summary.maintenance_mode_restored -or
            [string]$summary.package_sha256 -notmatch '^[a-fA-F0-9]{64}$') {
        return $false
    }
    $packagePath = Join-Path $ProjectRoot `
        "exports\phase8\paquete-sitio-consolidado.zip"
    $manifestPath = Join-Path $ProjectRoot "exports\phase8\manifest.json"
    if (-not (Test-Path -LiteralPath $packagePath -PathType Leaf) -or
            -not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        return $false
    }
    $actualPackageHash = (
        Get-FileHash -LiteralPath $packagePath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    $actualManifestHash = (
        Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    return (
        $actualPackageHash -eq
            ([string]$summary.package_sha256).ToLowerInvariant() -and
        $actualManifestHash -eq
            ([string]$summary.manifest_sha256).ToLowerInvariant()
    )
}

function Open-ReviewPath {
    param([string[]]$RelativePaths)
    $existing = @(
        $RelativePaths |
            ForEach-Object { Join-Path $ProjectRoot $_ } |
            Where-Object { Test-Path -LiteralPath $_ } |
            Select-Object -Unique
    )
    if ($existing.Count -lt 1) {
        Write-Host "Todavía no existe un archivo para abrir en esta fase." `
            -ForegroundColor Yellow
        return
    }
    Write-Host "Archivos de revisión:" -ForegroundColor Cyan
    $existing | ForEach-Object { Write-Host "  $_" }
    if ($IsWindows) {
        try {
            Start-Process -FilePath $existing[0] | Out-Null
        } catch {
            Write-Host (
                "No fue posible abrirlo automáticamente; use la ruta mostrada."
            ) -ForegroundColor Yellow
        }
    } else {
        Write-Host (
            "En Linux, revise el archivo desde otra terminal con la ruta mostrada."
        ) -ForegroundColor DarkGray
    }
}

function Invoke-GuidedStage {
    param(
        [string]$Id,
        [string]$Title,
        [string[]]$Description,
        [bool]$WritesDestination,
        [bool]$ManualIntervention = $false,
        [string[]]$ReviewPaths,
        [scriptblock]$Action,
        [scriptblock]$SuccessTest,
        [string]$FailureMessage
    )

    while ($true) {
        Write-Host ""
        Write-Host ("=" * 72) -ForegroundColor DarkGray
        Write-Host $Title -ForegroundColor Cyan
        Write-Host ("=" * 72) -ForegroundColor DarkGray
        foreach ($line in $Description) {
            Write-Host $line
        }
        if ($WritesDestination) {
            Write-Host ""
            Write-Host (
                "ATENCIÓN: esta etapa sí escribirá en el Moodle destino. " +
                "La autorización quedará vinculada al SHA-256 del plan."
            ) -ForegroundColor Yellow
        } else {
            Write-Host "Alcance: solo lectura respecto al Moodle destino." `
                -ForegroundColor DarkGray
        }
        if ($Automatic) {
            Write-Host "Modo automático: ejecutando la etapa." `
                -ForegroundColor DarkGray
        } else {
            Write-Host ""
            Write-Host (
                "Comandos: continuar | reintentar | abrir | salir"
            ) -ForegroundColor DarkGray
            $command = (Read-Host "Acción").Trim().ToLowerInvariant()
            switch ($command) {
                "abrir" {
                    Open-ReviewPath -RelativePaths $ReviewPaths
                    continue
                }
                "salir" {
                    $pauseMessage = "Pausa solicitada por el operador."
                    Write-AssistantState `
                        -Stage $Id `
                        -Status "paused" `
                        -Message $pauseMessage `
                        -ReviewPaths $ReviewPaths
                    Send-ConsolidationNotification `
                        -Stage $Id `
                        -Status "PAUSADA" `
                        -Message $pauseMessage `
                        -ReviewPaths $ReviewPaths
                    Write-Host (
                        "Ejecución pausada. Repita INICIAR-CONSOLIDACION.sh " +
                        "para reanudar desde este punto."
                    ) -ForegroundColor Yellow
                    return $false
                }
                "continuar" {
                    # Continúa en el bloque de ejecución común.
                }
                "reintentar" {
                    # Ejecuta de nuevo la fase conservando sus checkpoints.
                }
                default {
                    Write-Host "Comando no reconocido." -ForegroundColor Yellow
                    continue
                }
            }
        }

        Write-AssistantState `
            -Stage $Id `
            -Status "running" `
            -Message $Title `
            -ReviewPaths $ReviewPaths
        try {
            & $Action | Out-Host
            if (-not [bool](& $SuccessTest)) {
                throw $FailureMessage
            }
            Write-AssistantState `
                -Stage $Id `
                -Status "completed" `
                -Message $Title `
                -ReviewPaths $ReviewPaths
            Send-ConsolidationNotification `
                -Stage $Id `
                -Status "COMPLETADA" `
                -Message $Title `
                -ReviewPaths $ReviewPaths
            Write-Host ""
            Write-Host "ETAPA_OK $Id" -ForegroundColor Green
            if ($Automatic -and $automaticDelaySeconds -gt 0) {
                Write-Host (
                    "Siguiente etapa en $automaticDelaySeconds segundo(s)..."
                ) -ForegroundColor DarkGray
                Start-Sleep -Seconds $automaticDelaySeconds
            }
            return $true
        } catch {
            $message = $_.Exception.Message
            $identityReviewImported = $message.StartsWith(
                'V8_IDENTITY_REVIEW_IMPORTED',
                [System.StringComparison]::Ordinal
            )
            if ($identityReviewImported) {
                Write-AssistantState `
                    -Stage $Id `
                    -Status "restarting" `
                    -Message $message `
                    -ReviewPaths $ReviewPaths
                Write-Host ""
                Write-Host (
                    "IDENTITY_REVIEW_IMPORTED_OK: revisión válida; " +
                    "recalculando automáticamente conciliación y plan."
                ) -ForegroundColor Green
                return $true
            }

            $workflowRestartRequired = $message.StartsWith(
                'V8_IDENTITY_REVIEW_REQUIRED',
                [System.StringComparison]::Ordinal
            )
            $blockedStatus = if ($ManualIntervention -or $workflowRestartRequired) {
                "waiting_manual"
            } else {
                "blocked"
            }
            Write-AssistantState `
                -Stage $Id `
                -Status $blockedStatus `
                -Message $message `
                -ReviewPaths $ReviewPaths
            Send-ConsolidationNotification `
                -Stage $Id `
                -Status $(if ($ManualIntervention -or $workflowRestartRequired) {
                    "INTERVENCIÓN MANUAL"
                } else {
                    "FALLIDA"
                }) `
                -Message $message `
                -ReviewPaths $ReviewPaths
            Write-Host ""
            if ($ManualIntervention -or $workflowRestartRequired) {
                Write-Host "INTERVENCION_MANUAL $Id" -ForegroundColor Yellow
            } else {
                Write-Host "ETAPA_BLOQUEADA $Id" -ForegroundColor Red
            }
            Write-Host $message -ForegroundColor Yellow
            Write-Host (
                "Corrija la causa y reanude. Los pasos aprobados y los " +
                "checkpoints permanecen."
            ) -ForegroundColor Yellow
            if ($Automatic -or $workflowRestartRequired) {
                $script:AssistantExitCode = if ($ManualIntervention -or
                        $workflowRestartRequired) {
                    22
                } else {
                    20
                }
                if ($workflowRestartRequired) {
                    Write-Host (
                        'Aplique la revisión fuzzy y vuelva a ejecutar el asistente; ' +
                        'la conciliación y el plan de usuarios se recalcularán desde arriba.'
                    ) -ForegroundColor Yellow
                }
                return $false
            }
        }
    }
}

function Invoke-StageOrExit {
    param([hashtable]$Arguments)
    $continue = Invoke-GuidedStage @Arguments
    if (-not $continue) {
        exit $script:AssistantExitCode
    }
}

function Invoke-PluginInterventionStage {
    $stage = '03-plugins'

    Write-Host ""
    Write-Host ("=" * 72)
    Write-Host "3. Validar compatibilidad e instalar plugins requeridos"
    Write-Host ("=" * 72)

    $paths = @(
        'exports\phase2\plugin_compatibility_needs.json',
        'exports\phase2\plugin_inventory_before.json',
        'exports\phase2\plugin_inventory_after.json',
        'exports\phase2\plugin_inventory_diff.json',
        'exports\phase2\plugin_technical_validation.json',
        'exports\phase2\plugin_intervention_resolution.json'
    )
    try {
        $existing = Read-JsonDocument `
            'exports\phase2\plugin_intervention_checkpoint.json'
        $mode = if ($null -eq $existing) { 'Discover' } else { 'Revalidate' }
        $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode $mode
        if ([string]$result.status -eq 'PLUGIN_INTERVENTION_APPROVED' -and
                (Test-V8PluginLock)) {
            Write-AssistantState -Stage $stage -Status 'completed' `
                -Message 'PLUGIN_INTERVENTION_APPROVED' -ReviewPaths $paths
            return $true
        }
        # El catálogo selecciona únicamente commits pinneados. La instalación
        # altera el manifiesto aprobado y por eso siempre se revalida en staging.
        $lock = & "$PSScriptRoot/v8-plugin-resolver.ps1" -Mode Prepare
        $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode Revalidate
        if ([string]$result.status -eq 'READY_FOR_APPROVAL') {
            # Seal adopta componentes standard/core y subplugins cubiertos por
            # padres pinneados usando el inventario AFTER ya revalidado.
            & "$PSScriptRoot/v8-plugin-resolver.ps1" -Mode Seal | Out-Null
            if (Test-V8PluginLock) {
                $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode Approve
                if ([string]$result.status -eq 'PLUGIN_INTERVENTION_APPROVED') {
                    Write-AssistantState -Stage $stage -Status 'completed' `
                        -Message 'PLUGIN_INTERVENTION_APPROVED_CATALOG' -ReviewPaths $paths
                    return $true
                }
            }
        }
    } catch {
        Write-AssistantState -Stage $stage -Status 'WAITING_USER_ACTION' `
            -Message $_.Exception.Message -ReviewPaths $paths
        Write-Host $_.Exception.Message -ForegroundColor Red
    }
    while ($true) {
        $checkpoint = Read-JsonDocument `
            'exports\phase2\plugin_intervention_checkpoint.json'
        $ready = [string]$checkpoint.status -eq 'READY_FOR_APPROVAL'
        $failed = [string]$checkpoint.status -eq 'PLUGIN_TECHNICAL_VALIDATION_FAILED'
        $status = if ($failed) { 'PLUGIN_TECHNICAL_VALIDATION_FAILED' } else {
            'WAITING_USER_ACTION'
        }
        Write-AssistantState -Stage $stage -Status $status `
            -Message 'PLUGIN_INTERVENTION_PENDING: instalación y revisión de plugins.' `
            -ReviewPaths $paths
        Write-Host ''
        Write-Host 'INTERVENCIÓN REQUERIDA - PLUGINS' -ForegroundColor Yellow
        $needs = Read-JsonDocument 'exports\phase2\plugin_compatibility_needs.json'
        Write-Host "Necesidades originales: $(@($needs.original_compatibility_needs).Count)"
        foreach ($need in @($needs.original_compatibility_needs)) {
            Write-Host "  $($need.display_name) [$($need.component)]: $($need.observed_state)"
        }
        if ($ready) {
            $diff = Read-JsonDocument 'exports\phase2\plugin_inventory_diff.json'
            Write-Host "Nuevos: $(@($diff.newly_detected_plugins).Count); actualizados: $(@($diff.updated_plugins).Count); eliminados: $(@($diff.removed_plugins).Count)."
            foreach ($item in @($diff.newly_detected_plugins)) {
                Write-Host "  + $($item.name) [$($item.component)]"
            }
            foreach ($item in @($diff.updated_plugins)) {
                Write-Host "  ~ $($item.name) [$($item.component)]"
            }
            foreach ($item in @($diff.removed_plugins)) {
                Write-Host "  - $($item.name) [$($item.component)]"
            }
            Write-Host 'Revise que los componentes instalados cubran los datos del origen.'
            Write-Host '[Y] Aceptar intervención | [N] No continuar | [R] Refrescar inventario'
        } else {
            if ($failed) {
                $technical = Read-JsonDocument `
                    'exports\phase2\plugin_technical_validation.json'
                foreach ($issue in @($technical.issues)) {
                    $message = if ($null -ne $issue.message) {
                        [string]$issue.message
                    } else { [string]$issue }
                    Write-Host "  ERROR: $message" -ForegroundColor Red
                }
            }
            if (@($needs.original_compatibility_needs | Where-Object {
                $_.observed_state -eq 'removed_core_component'
            }).Count -gt 0) {
                Write-Host 'Hay componentes core retirados. El catálogo debe instalar y validar su replacement pinneado antes de aprobar.' -ForegroundColor Yellow
            } else {
                Write-Host 'Instale las versiones o sustitutos compatibles con Moodle 5.2.'
            }
            Write-Host '[R] Revalidar plugins | [S] Salir y continuar posteriormente'
        }
        if ($Automatic) {
            $script:AssistantExitCode = if ($failed) { 20 } else { 22 }
            return $false
        }
        $choice = (Read-Host 'Acción').Trim().ToUpperInvariant()
        if ($choice -in @('S', 'N')) {
            Write-AssistantState -Stage $stage -Status 'WAITING_USER_ACTION' `
                -Message 'PLUGIN_INTERVENTION_PENDING: reanudar después.' `
                -ReviewPaths $paths
            return $false
        }
        if ($choice -eq 'R') {
            try {
                $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode Revalidate
            } catch {
                Write-AssistantState -Stage $stage `
                    -Status 'PLUGIN_TECHNICAL_VALIDATION_FAILED' `
                    -Message $_.Exception.Message -ReviewPaths $paths
                Write-Host $_.Exception.Message -ForegroundColor Red
            }
            continue
        }
        if ($choice -eq 'Y' -and $ready) {
            try {
                $result = & "$PSScriptRoot/audit-package-plugins.ps1" -Mode Approve
                if ([string]$result.status -ne 'PLUGIN_INTERVENTION_APPROVED') {
                    throw 'No se selló la aprobación de plugins.'
                }
                & "$PSScriptRoot/v8-plugin-resolver.ps1" -Mode Seal | Out-Null
                if (-not (Test-V8PluginLock)) {
                    throw 'El plugin-lock de V8 no quedó sellado.'
                }
                Write-AssistantState -Stage $stage -Status 'completed' `
                    -Message 'PLUGIN_INTERVENTION_APPROVED' -ReviewPaths $paths
                Send-ConsolidationNotification -Stage $stage -Status 'COMPLETADA' `
                    -Message 'Intervención de plugins aprobada.' -ReviewPaths $paths
                return $true
            } catch {
                Write-Host $_.Exception.Message -ForegroundColor Red
            }
        } else {
            Write-Host 'Opción no disponible hasta superar la validación técnica.' `
                -ForegroundColor Yellow
        }
    }
}

function Get-LowerFileHash {
    param([string]$RelativePath)
    return (
        Get-FileHash `
            -LiteralPath (Join-Path $ProjectRoot $RelativePath) `
            -Algorithm SHA256
    ).Hash.ToLowerInvariant()
}

function Assert-SealedPilot {
    $apply = Read-JsonDocument "exports\phase5\apply_summary.json"
    if ((Test-Path "exports\phase5\apply_summary.json" -PathType Leaf) -and -not $apply) {
        throw "El estado aplicado del piloto es ilegible. Se bloquea la reanudación."
    }
    if ($apply) {
        if ([string]$apply.plan_summary_sha256 -ne
                (Get-LowerFileHash "exports\phase5\plan_summary.json")) {
            throw "El plan del piloto cambió después de escribir el destino. Se bloquea su regeneración."
        }
    }
    $verification = Read-JsonDocument "exports\phase5\verification.json"
    if ((Test-Path "exports\phase5\verification.json" -PathType Leaf) -and
            -not $verification) {
        throw "La verificación del piloto es ilegible. Se bloquea la reanudación."
    }
    if ($verification -and [string]$verification.validation -eq "passed") {
        $artifacts = [ordered]@{
            "plan_summary_sha256" = "exports\phase5\plan_summary.json"
            "apply_summary_sha256" = "exports\phase5\apply_summary.json"
            "pilot_course_map_sha256" = "exports\phase5\pilot_course_map.csv"
            "target_course_inventory_sha256" = "exports\phase5\target_course_inventory.json"
            "verification_csv_sha256" = "exports\phase5\verification.csv"
        }
        foreach ($field in $artifacts.Keys) {
            if ([string]$verification.$field -ne (Get-LowerFileHash $artifacts[$field])) {
                throw "El piloto verificado perdió integridad: $($artifacts[$field]). Se bloquea la reanudación."
            }
        }
    }
}

function Assert-SealedBatch {
    $manifest = Read-JsonDocument "exports\phase6\batch_manifest.json"
    if ((Test-Path "exports\phase6\batch_manifest.json" -PathType Leaf) -and
            -not $manifest) {
        throw "El manifiesto masivo es ilegible. Se bloquea la reanudación."
    }
    if ($manifest -and [string]$manifest.manifest_status -eq "prepared") {
        if ([string]$manifest.plan_summary_sha256 -ne
                (Get-LowerFileHash "exports\phase6\plan_summary.json")) {
            throw "El plan masivo cambió tras sellar el lote. Se bloquea su regeneración."
        }
    }
}

Write-Host ""
Write-Host "ASISTENTE DE CONSOLIDACIÓN MOODLE" -ForegroundColor Cyan
Write-Host "Versión: 8.0.0-linux-rc12" -ForegroundColor DarkGray
Write-Host (
    "Reanudación, OAuth2 validado y restauración paralela con pool dinámico."
) -ForegroundColor DarkGray
Write-Host (
    "Modo: " + $(if ($Automatic) { "automático" } else { "interactivo" })
) -ForegroundColor DarkGray
Send-ConsolidationNotification `
    -Stage "assistant" `
    -Status "INICIADA" `
    -Message $(if ($Automatic) {
        "Ejecución automática iniciada o reanudada."
    } else {
        "Ejecución interactiva iniciada o reanudada."
    })

while ($true) {
    Assert-SealedPilot
    Assert-SealedBatch
    $postWriteStarted = Test-Path -LiteralPath $DestinationWriteLockPath -PathType Leaf
    if ($postWriteStarted) {
        Assert-PostWriteAnchor
    }
    if (-not $postWriteStarted -and
            (-not (Test-PackageImport) -or
             -not (Test-ConfigurationConfirmation))) {
        $zipFiles = @(
            Get-ChildItem -LiteralPath (Join-Path $ProjectRoot "copias") `
                -Filter "*.zip" -File -ErrorAction SilentlyContinue |
                Sort-Object Name
        )
        Write-Host ""
        Write-Host "Paquetes detectados en copias:" -ForegroundColor Cyan
        if ($zipFiles.Count -lt 1) {
            Write-Host "  Ninguno." -ForegroundColor Yellow
        } else {
            foreach ($zip in $zipFiles) {
                $sizeMb = [Math]::Round(($zip.Length / 1048576), 2)
                Write-Host "  $($zip.Name) - $sizeMb MB"
            }
        }
        Invoke-StageOrExit -Arguments @{
            Id = "01-importar-paquetes"
            Title = "1. Importar y vincular paquetes de origen"
            Description = @(
                "Validará el contrato mínimo y el sello final de cada ZIP.",
                "No repetirá la auditoría exhaustiva de la fase previa.",
                "Extraerá copias de trabajo; los ZIP originales no se modificarán.",
                "Seleccionará automáticamente un curso piloto con evidencia."
            )
            WritesDestination = $false
            ReviewPaths = @("copias", "config\assistant.json")
            Action = {
                if (-not (Test-PackageImport)) {
                    & "$PSScriptRoot/import-source-packages.ps1"
                }
                & "$PSScriptRoot/confirm-package-config.ps1" -Force
            }
            SuccessTest = {
                (Test-PackageImport) -and
                (Test-ConfigurationConfirmation)
            }
            FailureMessage = (
                "La importación o la vinculación de configuración no quedó aprobada."
            )
        }
        continue
    }

    if (-not $postWriteStarted -and -not (Test-OAuth2Ready)) {
        Invoke-StageOrExit -Arguments @{
            Id = "02-oauth2"
            Title = "2. Configurar y validar Google OAuth2"
            Description = @(
                "La conexión institucional se configura manualmente en Moodle.",
                "La herramienta solo validará el servicio y obtendrá su issuerid.",
                "No leerá ni guardará el Client ID o el Client secret.",
                "Esta etapa siempre pausa si la configuración todavía no está lista."
            )
            WritesDestination = $false
            ManualIntervention = $true
            ReviewPaths = @(
                "reports\oauth2-configuracion-manual.txt",
                "reports\oauth2-validacion.txt",
                "exports\oauth2\validation.json",
                "config\oauth2.json"
            )
            Action = {
                & "$PSScriptRoot/oauth2-validate.ps1"
            }
            SuccessTest = { Test-OAuth2Ready }
            FailureMessage = (
                "Google OAuth2 aún no está listo. Complete la configuración " +
                "manual indicada y reanude el asistente."
            )
        }
        continue
    }

    if (-not $postWriteStarted -and
            (-not (Test-PluginAudit) -or -not (Test-V8PluginLock))) {
        if (-not (Invoke-PluginInterventionStage)) {
            exit $script:AssistantExitCode
        }
        continue
    }

    if (-not $postWriteStarted -and -not (Test-OAuth2LiveReady)) {
        Invoke-StageOrExit -Arguments @{
            Id = '03b-oauth2-final'
            Title = '3.1. Confirmar OAuth y proxy antes de conciliar identidades'
            Description = @(
                'Comprueba nuevamente la configuración pública después de los plugins.',
                'Comprueba el mismo issuer Google antes de Fase 3.'
            )
            WritesDestination = $false
            ManualIntervention = $true
            ReviewPaths = @(
                'reports\oauth2-validacion-publicacion.txt',
                'exports\oauth2-live\validation.json'
            )
            Action = { & "$PSScriptRoot/oauth2-validate.ps1" -LiveCheck }
            SuccessTest = { Test-OAuth2LiveReady }
            FailureMessage = 'OAuth2 o reverse proxy cambió tras aprobar los plugins.'
        }
        continue
    }

    if (-not $postWriteStarted -and -not (Test-IdentityReconciliation)) {
        Invoke-StageOrExit -Arguments @{
            Id = "04-identidades"
            Title = "4. Conciliar identidades, roles y matrículas"
            Description = @(
                "Usará los inventarios incluidos en los paquetes.",
                "No volverá a consultar los Moodle de origen.",
                "Los conflictos deben resolverse en config\identity_resolutions.csv."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase3\identity_conflicts.csv",
                "config\identity_resolutions.csv",
                "exports\phase3\role_classification_exceptions.csv"
            )
            Action = {
                & "$PSScriptRoot/reconcile-packages.ps1"
            }
            SuccessTest = { Test-IdentityReconciliation }
            FailureMessage = (
                "Quedan identidades bloqueadas o pendientes. Revise " +
                "identity_conflicts.csv, registre decisiones auditadas en " +
                "config\identity_resolutions.csv y use reintentar."
            )
        }
        continue
    }

    if (-not $postWriteStarted -and -not (Test-Phase4Plan)) {
        Invoke-StageOrExit -Arguments @{
            Id = "05-plan-usuarios"
            Title = "5. Simular usuarios canónicos en el destino"
            Description = @(
                "Consultará usuarios existentes y construirá el plan.",
                "No creará ni modificará cuentas durante esta etapa."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase4\target_user_plan.csv",
                "exports\phase4\plan_summary.json"
            )
            Action = {
                & "$PSScriptRoot/phase4-plan.ps1"
            }
            SuccessTest = { Test-Phase4Plan }
            FailureMessage = (
                "El plan de usuarios contiene bloqueos. Revise " +
                "exports\phase4\target_user_plan.csv."
            )
        }
        continue
    }

    if (-not $postWriteStarted -and -not (Test-V8Readiness)) {
        Invoke-StageOrExit -Arguments @{
            Id = "05b-readiness"
            Title = "5.1. Preparar y sellar la ejecución V8"
            Description = @(
                "Generará candidatos fuzzy después de la conciliación determinística.",
                "Podrá revisarlos o ignorarlos sin bloquear la ejecución.",
                "Sellará perfiles y asignaciones de themes por curso.",
                "Aplicará y verificará únicamente la política administrada de themes; todavía no escribirá datos académicos."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\plugin-lock.json",
                "exports\identity_candidates.csv",
                "exports\theme-plan.json",
                "exports\readiness.json"
            )
            Action = {
                & "$PSScriptRoot/v8-readiness.ps1" -Mode Prepare `
                    -NonInteractive:$Automatic
            }
            SuccessTest = { Test-V8Readiness }
            FailureMessage = (
                "La preparación V8 no alcanzó READY_TO_RUN. Revise " +
                "exports\readiness.json."
            )
        }
        continue
    }

    if (-not (Test-Phase4Apply)) {
        Invoke-StageOrExit -Arguments @{
            Id = "06-aplicar-usuarios"
            Title = "6. Aplicar usuarios canónicos y vincular Google"
            Description = @(
                "Creará, adoptará o actualizará únicamente los usuarios del plan.",
                "Vinculará cada identificador OAuth comprobado al issuerid validado de Moodle.",
                "Todavía no aplicará cursos, roles ni matrículas."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\phase4\target_user_plan.csv",
                "exports\phase4\plan_summary.json"
            )
            Action = {
                $planHash = Get-LowerFileHash `
                    "exports\phase4\target_user_plan.csv"
                & "$PSScriptRoot/phase4-apply.ps1" `
                    -AssistantApproval "ASSISTANT-PHASE4-$planHash"
            }
            SuccessTest = { Test-Phase4Apply }
            FailureMessage = "La aplicación de usuarios no quedó completa."
        }
        continue
    }

    if (-not (Test-Phase4Verify)) {
        Invoke-StageOrExit -Arguments @{
            Id = "07-verificar-usuarios"
            Title = "7. Verificar usuarios canónicos y accesos Google"
            Description = @(
                "Comprobará IDs, marcadores, atributos y linked logins OAuth2."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase4\verification.csv",
                "exports\phase4\verification.json"
            )
            Action = {
                & "$PSScriptRoot/phase4-verify.ps1"
            }
            SuccessTest = { Test-Phase4Verify }
            FailureMessage = "La verificación de usuarios no quedó aprobada."
        }
        continue
    }

    if (-not (Test-Phase5Plan)) {
        Invoke-StageOrExit -Arguments @{
            Id = "08-plan-piloto"
            Title = "8. Preparar y simular el curso piloto"
            Description = @(
                "Auditará el .mbz piloto, normalizará una copia y firmará el plan.",
                "El paquete original permanecerá intacto."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase5\pilot_course_plan.csv",
                "exports\phase5\pilot_user_plan.csv",
                "exports\phase5\pilot_role_plan.csv",
                "exports\phase5\plan_summary.json"
            )
            Action = {
                & "$PSScriptRoot/phase5-package-plan.ps1"
            }
            SuccessTest = { Test-Phase5Plan }
            FailureMessage = (
                "El piloto contiene conflictos bloqueantes. Revise los planes " +
                "de exports\phase5."
            )
        }
        continue
    }

    if (-not (Test-Phase5Apply)) {
        Invoke-StageOrExit -Arguments @{
            Id = "09-aplicar-piloto"
            Title = "9. Restaurar el curso piloto"
            Description = @(
                "Restaurará un solo curso y aplicará sus roles y matrículas.",
                "Un fallo revierte únicamente el contenedor incompleto."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\phase5\plan_summary.json",
                "exports\phase5\pilot_course_plan.csv"
            )
            Action = {
                $planHash = Get-LowerFileHash `
                    "exports\phase5\plan_summary.json"
                & "$PSScriptRoot/phase5-apply.ps1" `
                    -AssistantApproval "ASSISTANT-PHASE5-$planHash"
            }
            SuccessTest = { Test-Phase5Apply }
            FailureMessage = (
                "La restauración piloto no quedó completa. Use reintentar " +
                "después de revisar el reporte."
            )
        }
        continue
    }

    if (-not (Test-V8PilotThemes)) {
        Invoke-StageOrExit -Arguments @{
            Id = "09b-themes-piloto"
            Title = "9.1. Aplicar y verificar themes del curso piloto"
            Description = @(
                "Conservará la asignación explícita cuando el theme esté disponible.",
                "Usará el theme global con warning si solo era incompatible visualmente."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\theme-assignment-verification-pilot.json"
            )
            Action = { & "$PSScriptRoot/v8-course-themes.ps1" -Scope Pilot }
            SuccessTest = { Test-V8PilotThemes }
            FailureMessage = "No se verificó el theme del piloto."
        }
        continue
    }

    if (-not (Test-Phase5Verify)) {
        Invoke-StageOrExit -Arguments @{
            Id = "10-verificar-piloto"
            Title = "10. Verificar el curso piloto"
            Description = @(
                "Comparará estructura, contenido, archivos, usuarios, roles y actividad."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase5\verification.csv",
                "exports\phase5\verification.json"
            )
            Action = {
                & "$PSScriptRoot/phase5-verify.ps1"
            }
            SuccessTest = { Test-Phase5Verify }
            FailureMessage = "La verificación del piloto no quedó aprobada."
        }
        continue
    }

    if (-not (Test-Phase6Plan)) {
        Invoke-StageOrExit -Arguments @{
            Id = "11-plan-lote"
            Title = "11. Simular el lote consolidado"
            Description = @(
                "Propondrá categorías, cursos, roles y convergencias.",
                "Excluirá el piloto ya verificado y no modificará el destino."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase6\course_plan.csv",
                "exports\phase6\category_plan.csv",
                "exports\phase6\role_normalization.csv",
                "exports\phase6\identity_convergence.csv",
                "exports\phase6\plan_summary.json"
            )
            Action = {
                & "$PSScriptRoot/phase6-package-plan.ps1"
            }
            SuccessTest = { Test-Phase6Plan }
            FailureMessage = (
                "El plan del lote no está applicable. Revise los CSV de " +
                "exports\phase6 y config\phase6-role-resolutions.csv."
            )
        }
        continue
    }

    if (-not (Test-Phase6Prepared)) {
        Invoke-StageOrExit -Arguments @{
            Id = "12-preparar-lote"
            Title = "12. Referenciar los backups del lote"
            Description = @(
                "Creará un manifiesto ligero sin abrir profundamente ningún .mbz.",
                "Validará referencias, sellos disponibles, políticas, espacio y permisos.",
                "Cada worker hará el precheck físico al tomar su curso.",
                "Todavía no creará categorías ni restaurará cursos."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase6\backup_progress.csv",
                "exports\phase6\batch_manifest.json"
            )
            Action = {
                & "$PSScriptRoot/phase6-package-prepare.ps1"
            }
            SuccessTest = { Test-Phase6Prepared }
            FailureMessage = (
                "El manifiesto ligero del lote no quedó BATCH_READY."
            )
        }
        continue
    }

    $massMode = Get-MassExecutionMode
    if ($massMode -eq '') {
        $requestedMode = ([string]$env:CONSOLIDATION_MASS_EXECUTION_MODE).Trim().ToLowerInvariant()
        if ($requestedMode -in @('foreground', 'background')) {
            Set-MassExecutionMode $requestedMode
            $massMode = $requestedMode
        } elseif ($Automatic) {
            Write-AssistantState -Stage '12c-modo-ejecucion' `
                -Status 'WAITING_USER_ACTION' `
                -Message 'MASS_EXECUTION_MODE_REQUIRED' `
                -ReviewPaths @('exports\phase6\batch_manifest.json')
            exit 22
        } else {
            Write-Host ''
            Write-Host '¿Cómo desea ejecutar la consolidación masiva?' `
                -ForegroundColor Cyan
            Write-Host '1. Primer plano: salida visible; cerrar SSH puede terminar el proceso.'
            Write-Host '2. Segundo plano: desacoplado de SSH, con logs, heartbeat y ./ESTADO.sh.'
            $choice = ([string](Read-Host 'Seleccione 1 o 2')).Trim()
            if ($choice -notin @('1', '2')) {
                Write-Host 'Selección inválida.' -ForegroundColor Yellow
                continue
            }
            $massMode = if ($choice -eq '1') { 'foreground' } else { 'background' }
            Set-MassExecutionMode $massMode
        }
    }
    if ($massMode -eq 'background' -and -not $Automatic) {
        Write-AssistantState -Stage '12c-modo-ejecucion' `
            -Status 'BACKGROUND_HANDOFF_REQUESTED' `
            -Message 'La ejecución masiva continuará en segundo plano.' `
            -ReviewPaths @('reports\mass-execution-mode.json')
        exit 24
    }

    if (-not (Test-Phase6Apply)) {
        Invoke-StageOrExit -Arguments @{
            Id = "13-aplicar-lote"
            Title = "13. Aplicar el lote consolidado"
            Description = @(
                "Creará o reutilizará la jerarquía aprobada.",
                "Usará un pool dinámico y una sola extracción por curso.",
                "Procesará primero los cursos de mayor peso estimado.",
                "Los checkpoints permiten reanudar sin repetir cursos aprobados."
            )
            WritesDestination = $true
            ManualIntervention = $true
            ReviewPaths = @(
                "exports\phase6\batch_manifest.json",
                "exports\phase6\course_plan.csv",
                "exports\phase6\course-worker-states",
                "reports\fase-6-workers-status.json"
            )
            Action = {
                $manifestHash = Get-Phase6AuthorizationHash
                & "$PSScriptRoot/phase6-apply.ps1" `
                    -AssistantApproval "ASSISTANT-PHASE6-$manifestHash"
            }
            SuccessTest = { Test-Phase6Apply }
            FailureMessage = (
                "La aplicación del lote se interrumpió. El curso incompleto " +
                "se revierte y los checkpoints anteriores se conservan."
            )
        }
        continue
    }

    if (-not (Test-V8AllThemes)) {
        Invoke-StageOrExit -Arguments @{
            Id = "13b-themes-lote"
            Title = "13.1. Aplicar y verificar themes de los cursos"
            Description = @(
                "Revalidará todas las asignaciones explícitas del lote.",
                "Los fallbacks puramente visuales quedarán como warnings."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\theme-assignment-verification.json"
            )
            Action = { & "$PSScriptRoot/v8-course-themes.ps1" -Scope All }
            SuccessTest = { Test-V8AllThemes }
            FailureMessage = "No se verificaron las asignaciones de themes."
        }
        continue
    }

    if (-not (Test-Phase6Verify)) {
        Invoke-StageOrExit -Arguments @{
            Id = "14-verificar-lote"
            Title = "14. Verificar toda la consolidación"
            Description = @(
                "Revalidará jerarquía, cursos, matrículas, roles y contenido."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase6\batch_verification.csv",
                "exports\phase6\batch_verification.json"
            )
            Action = {
                & "$PSScriptRoot/phase6-verify.ps1"
            }
            SuccessTest = { Test-Phase6Verify }
            FailureMessage = "La verificación consolidada no quedó aprobada."
        }
        continue
    }

    if (-not (Test-Phase7Closure)) {
        Invoke-StageOrExit -Arguments @{
            Id = "15-cierre"
            Title = "15. Consolidar evidencias y cerrar"
            Description = @(
                "Revalidará la cadena de hashes y generará el informe final.",
                "No volverá a escribir en Moodle ni releerá todos los .mbz."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase7\informe-final-migracion.md",
                "exports\phase7\closure_summary.json"
            )
            Action = {
                & "$PSScriptRoot/phase7-close.ps1"
            }
            SuccessTest = { Test-Phase7Closure }
            FailureMessage = "El cierre de evidencias no quedó aprobado."
        }
        continue
    }

    $assistantConfig = Read-JsonDocument "config\assistant.json"
    $siteBackupEnabled = (
        $null -ne $assistantConfig -and
        [bool]$assistantConfig.site_backup.enabled
    )
    if ($siteBackupEnabled -and -not (Test-SitePackage)) {
        Invoke-StageOrExit -Arguments @{
            Id = "16-paquete-sitio"
            Title = "16. Generar la copia integral del sitio consolidado"
            Description = @(
                "Activará temporalmente el modo de mantenimiento.",
                "Exportará base de datos, moodledata y código/plugins.",
                (
                    "No incluirá config.php, valores declarativos ni " +
                    "credenciales; sí incluirá su manifiesto sin valores."
                ),
                "Al terminar restaurará el modo normal del sitio."
            )
            WritesDestination = $true
            ReviewPaths = @(
                "exports\phase7\informe-final-migracion.md",
                "exports\phase7\closure_summary.json"
            )
            Action = {
                & "$PSScriptRoot/export-consolidated-site.ps1"
            }
            SuccessTest = { Test-SitePackage }
            FailureMessage = (
                "La copia integral no quedó sellada. Verifique especialmente " +
                "que el modo de mantenimiento haya sido desactivado."
            )
        }
        continue
    }

    $closure = Read-JsonDocument "exports\phase7\closure_summary.json"
    $archiveHashPath = Join-Path $ProjectRoot `
        "exports\phase7\fase-7-cierre-migracion.sha256.txt"
    Write-AssistantState `
        -Stage "completed" `
        -Status "completed" `
        -Message "Consolidación verificada y cerrada."
    Write-Host ""
    Write-Host "CONSOLIDATION_ASSISTANT_OK" -ForegroundColor Green
    Write-Host "Fuentes: $(@($closure.sources).Count)." -ForegroundColor Cyan
    $verifiedTotal = if (
        $null -ne $closure.total_courses_verified -and
        [int]$closure.total_courses_verified -gt 0
    ) {
        [int]$closure.total_courses_verified
    } else {
        [int]$closure.courses_verified + 1
    }
    Write-Host (
        "Cursos verificados: $verifiedTotal " +
        "(piloto + $($closure.courses_verified) del lote). " +
        "Diferencias: $($closure.failed_courses)."
    ) -ForegroundColor Cyan
    Write-Host "Estado: $($closure.closure_status)." -ForegroundColor Cyan
    Write-Host (
        "Informe: " +
        (Join-Path $ProjectRoot "exports\phase7\informe-final-migracion.md")
    ) -ForegroundColor Cyan
    if (Test-Path -LiteralPath $archiveHashPath -PathType Leaf) {
        Write-Host (
            "Paquete y SHA-256: " +
            (Join-Path $ProjectRoot "exports\phase7")
        ) -ForegroundColor Cyan
    }
    if ($siteBackupEnabled) {
        $siteSummary = Read-JsonDocument `
            "exports\phase8\site_package_summary.json"
        Write-Host (
            "Copia integral: " +
            (Join-Path $ProjectRoot `
                "exports\phase8\paquete-sitio-consolidado.zip")
        ) -ForegroundColor Green
        Write-Host "SHA-256: $($siteSummary.package_sha256)" `
            -ForegroundColor Cyan
    }
    Write-Host (
        "Los paquetes y evidencias de identidades requieren acceso restringido."
    ) -ForegroundColor Yellow
    Send-ConsolidationNotification `
        -Stage "completed" `
        -Status "FINALIZADA" `
        -Message (
            "Consolidación cerrada con $verifiedTotal curso(s) verificado(s) " +
            "y cero diferencias."
        ) `
        -ReviewPaths @(
            "exports\phase7\informe-final-migracion.md",
            "exports\phase8\paquete-sitio-consolidado.zip"
        )
    break
}
