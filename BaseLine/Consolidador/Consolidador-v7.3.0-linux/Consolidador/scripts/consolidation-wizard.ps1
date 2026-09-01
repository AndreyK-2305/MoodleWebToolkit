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
. "$PSScriptRoot/Notifications.ps1"

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
        assistant_version = "7.3.0-linux"
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

function Test-PluginAudit {
    $summary = Read-JsonDocument `
        "exports\phase2\plugin_compatibility.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.status -eq "compatible" -and
        [int]$summary.blocking_issues -eq 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Test-OAuth2Ready {
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
        [int]$summary.issuer_id -gt 0 -and
        [bool]$summary.auth_plugin_enabled -and
        [bool]$summary.client_credentials_present -and
        [bool]$summary.show_on_login_page -and
        [bool]$summary.issuer_enabled -and
        [int]$summary.endpoints_configured -gt 0 -and
        -not [bool]$summary.destination_write_performed
    )
}

function Test-IdentityReconciliation {
    $summary = Read-JsonDocument "exports\phase3\summary.json"
    $identityPolicyPath = Join-Path $ProjectRoot "config\identity-policy.json"
    if (-not (Test-Path -LiteralPath $identityPolicyPath -PathType Leaf)) {
        return $false
    }
    $identityPolicyHash = (
        Get-FileHash -LiteralPath $identityPolicyPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    return (
        (Test-ConfigBoundDocument $summary) -and
        [string]$summary.schema_version -eq "1.6" -and
        [string]$summary.identity_policy_sha256 -eq $identityPolicyHash -and
        [int]$summary.identity_conflicts_unresolved -eq 0 -and
        [int]$summary.phase4_expected.blocked_identities -eq 0 -and
        [int]$summary.phase4_expected.identity_review_pending -eq 0 -and
        -not [bool]$summary.apply_performed
    )
}

function Test-Phase4Plan {
    $summary = Read-JsonDocument "exports\phase4\plan_summary.json"
    return (
        (Test-ConfigBoundDocument $summary) -and
        [int]$summary.blocking_conflicts -eq 0 -and
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
        [string]$summary.phase -eq "6-multi-course-reference-manifest" -and
        [string]$summary.manifest_status -eq "prepared" -and
        [bool]$summary.single_extraction_pipeline -and
        [int]$summary.raw_backups_created -eq 0 -and
        [int]$summary.courses_pending -eq 0 -and
        [int]$summary.courses_prepared -eq [int]$summary.courses_expected -and
        -not [bool]$summary.destination_write_performed
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
            $blockedStatus = if ($ManualIntervention) {
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
                -Status $(if ($ManualIntervention) {
                    "INTERVENCIÓN MANUAL"
                } else {
                    "FALLIDA"
                }) `
                -Message $message `
                -ReviewPaths $ReviewPaths
            Write-Host ""
            if ($ManualIntervention) {
                Write-Host "INTERVENCION_MANUAL $Id" -ForegroundColor Yellow
            } else {
                Write-Host "ETAPA_BLOQUEADA $Id" -ForegroundColor Red
            }
            Write-Host $message -ForegroundColor Yellow
            Write-Host (
                "Corrija la causa y reanude. Los pasos aprobados y los " +
                "checkpoints permanecen."
            ) -ForegroundColor Yellow
            if ($Automatic) {
                $script:AssistantExitCode = if ($ManualIntervention) {
                    22
                } else {
                    20
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

function Get-LowerFileHash {
    param([string]$RelativePath)
    return (
        Get-FileHash `
            -LiteralPath (Join-Path $ProjectRoot $RelativePath) `
            -Algorithm SHA256
    ).Hash.ToLowerInvariant()
}

Write-Host ""
Write-Host "ASISTENTE DE CONSOLIDACIÓN MOODLE" -ForegroundColor Cyan
Write-Host "Versión: 7.3.0-linux" -ForegroundColor DarkGray
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
    if (-not (Test-PackageImport) -or
            -not (Test-ConfigurationConfirmation)) {
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

    if (-not (Test-PluginAudit)) {
        Invoke-StageOrExit -Arguments @{
            Id = "02-plugins"
            Title = "2. Comprobar versión y plugins del destino"
            Description = @(
                "Iniciará únicamente el Moodle destino.",
                "Comparará módulos utilizados y plugins adicionales.",
                "Una incompatibilidad bloqueante debe corregirse antes del cargue."
            )
            WritesDestination = $false
            ReviewPaths = @(
                "exports\phase2\plugin_compatibility.csv",
                "exports\phase2\plugin_compatibility.json"
            )
            Action = {
                & "$PSScriptRoot/audit-package-plugins.ps1"
            }
            SuccessTest = { Test-PluginAudit }
            FailureMessage = (
                "La compatibilidad de plugins no está aprobada. Revise " +
                "exports\phase2\plugin_compatibility.csv."
            )
        }
        continue
    }

    if (-not (Test-OAuth2Ready)) {
        Invoke-StageOrExit -Arguments @{
            Id = "03-oauth2"
            Title = "3. Configurar y validar Google OAuth2"
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

    if (-not (Test-IdentityReconciliation)) {
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

    if (-not (Test-Phase4Plan)) {
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
                "Creará trabajos livianos sin copiar ni extraer cada .mbz.",
                "Confiará en el SHA sellado y comprobará referencia y tamaño.",
                "Reintentar reutiliza las referencias ya preparadas.",
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
                "El manifiesto del lote no quedó prepared. Corrija el curso " +
                "indicado y use reintentar."
            )
        }
        continue
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
            ReviewPaths = @(
                "exports\phase6\batch_manifest.json",
                "exports\phase6\course_plan.csv"
            )
            Action = {
                $manifestHash = Get-LowerFileHash `
                    "exports\phase6\batch_manifest.json"
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
