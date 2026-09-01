param(
    [string]$AssistantApproval = ""
)

. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"

$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$planPath = Join-Path $phase4Host "target_user_plan.csv"
$summaryPath = Join-Path $phase4Host "plan_summary.json"
if (-not (Test-Path $planPath -PathType Leaf) -or
        -not (Test-Path $summaryPath -PathType Leaf)) {
    throw "No existe un plan de fase 4. Reanude INICIAR-CONSOLIDACION.sh."
}
$summary = Get-Content -LiteralPath $summaryPath -Raw -Encoding UTF8 | ConvertFrom-Json
$currentConfigHash = Get-ConfigurationHash
if ([string]$summary.config_sha256 -ne $currentConfigHash) {
    throw "El plan no corresponde al config.yaml confirmado. Ejecute nuevamente los comandos 11 y 13."
}
if ([int]$summary.blocking_conflicts -gt 0) {
    throw "El plan contiene $($summary.blocking_conflicts) conflicto(s). Revise target_user_plan.csv y plan_summary.json."
}
$actualPlanHash = (Get-FileHash -LiteralPath $planPath -Algorithm SHA256).Hash.ToLowerInvariant()
if ([string]$summary.plan_sha256 -ne $actualPlanHash) {
    throw "target_user_plan.csv cambió después de la simulación. Ejecute nuevamente el comando 13."
}
$oauthSummaryPath = Join-Path $ProjectRoot "exports\oauth2\validation.json"
if (-not (Test-Path -LiteralPath $oauthSummaryPath -PathType Leaf)) {
    throw "Falta la validación de la configuración OAuth2."
}
$oauthSummary = Get-Content -LiteralPath $oauthSummaryPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
$oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
    throw "Falta config\oauth2.json."
}
$oauthConfigHash = (
    Get-FileHash -LiteralPath $oauthConfigPath -Algorithm SHA256
).Hash.ToLowerInvariant()
if ([string]$oauthSummary.config_sha256 -ne $currentConfigHash -or
        [string]$oauthSummary.oauth_config_sha256 -ne $oauthConfigHash -or
        [string]$oauthSummary.status -ne "ready" -or
        [string]$oauthSummary.validation -ne "passed" -or
        [int]$oauthSummary.issuer_id -lt 1) {
    throw "OAuth2 no está aprobado para este destino y esta configuración."
}

$targetSite = Get-TargetSite
Write-Host ""
Write-Host "FASE 4: creación o reutilización de usuarios canónicos" -ForegroundColor Yellow
Write-Host "Destino: $($targetSite.Name) ($($targetSite.id)) - $($targetSite.Url)" -ForegroundColor Yellow
Write-Host "Identidades aplicables: $($summary.applicable_identities)" -ForegroundColor Yellow
Write-Host "Identidades bloqueadas que se omitirán: $($summary.blocked_identities)" -ForegroundColor Yellow
Write-Host "Identidades excluidas por decisión: $($summary.excluded_identities)" -ForegroundColor Yellow
Write-Host "Plan SHA-256: $actualPlanHash" -ForegroundColor DarkGray
Write-Host "No se aplicarán roles, matrículas ni datos de cursos." -ForegroundColor Yellow
if ([string]::IsNullOrWhiteSpace($AssistantApproval)) {
    $confirmation = Read-Host "Escriba exactamente APLICAR FASE 4 para continuar"
    if ($confirmation -cne "APLICAR FASE 4") {
        Write-Host "Operación cancelada. No se modificó el destino." -ForegroundColor Yellow
        exit 0
    }
} else {
    $expectedApproval = "ASSISTANT-PHASE4-$actualPlanHash"
    if ($AssistantApproval -cne $expectedApproval) {
        throw "La autorización interna del asistente no corresponde al plan."
    }
    Write-Host "Autorización guiada verificada contra el SHA-256 del plan." -ForegroundColor Green
}

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}
$targetService = $targetSite.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $ProjectRoot "reports\fase-4-aplicacion-usuarios.txt"
Write-Host "Aplicando el plan confirmado de manera idempotente..." -ForegroundColor Cyan
Register-DestinationWriteIntent `
    -Phase "phase4-users" `
    -BoundHash $actualPlanHash
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase4"
$applyOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase4-apply.php `
    "--phase3=/exports/phase3" `
    "--phase4=/exports/phase4" `
    "--configsha=$currentConfigHash" `
    "--targetid=$($targetSite.id)" `
    "--oauthissuerid=$($oauthSummary.issuer_id)" `
    "--expectlab=$expectLab" 2>&1
$applyExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase4"
$applyOutput | Tee-Object -FilePath $report
if ($applyExit -ne 0) {
    throw "La aplicación de fase 4 falló. Revise $report. Puede reintentarse sin duplicar usuarios."
}

$applySummaryPath = Join-Path $phase4Host "apply_summary.json"
$targetMapPath = Join-Path $phase4Host "target_user_map.csv"
$sourceTargetMapPath = Join-Path $phase4Host "source_to_target_user_map.csv"
if (-not (Test-Path $applySummaryPath -PathType Leaf) -or
        -not (Test-Path $targetMapPath -PathType Leaf) -or
        -not (Test-Path $sourceTargetMapPath -PathType Leaf)) {
    throw "La aplicación terminó sin generar el resumen y el mapa de usuarios."
}
$applySummary = Get-Content -LiteralPath $applySummaryPath -Raw -Encoding UTF8 | ConvertFrom-Json
Write-Host ""
Write-Host "FASE 4 aplicada sobre usuarios." -ForegroundColor Green
Write-Host "Usuarios canónicos mapeados: $($applySummary.target_users_mapped)." -ForegroundColor Cyan
Write-Host "Vínculos Google OAuth2: $($applySummary.oauth2_links_materialized)." -ForegroundColor Cyan
Write-Host "Mapa: $targetMapPath" -ForegroundColor Cyan
Write-Host "Mapa directo de cuentas origen: $sourceTargetMapPath" -ForegroundColor Cyan
Write-Host "Roles aplicados: no. Matrículas aplicadas: no." -ForegroundColor Yellow
Write-Host "La siguiente etapa verificará los usuarios del destino." -ForegroundColor Yellow
