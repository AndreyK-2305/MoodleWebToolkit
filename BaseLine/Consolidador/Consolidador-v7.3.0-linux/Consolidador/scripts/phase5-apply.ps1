param(
    [string]$AssistantApproval = ""
)

. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"

$phase5Host = Join-Path $ProjectRoot "exports\phase5"
$summaryPath = Join-Path $phase5Host "plan_summary.json"
if (-not (Test-Path $summaryPath -PathType Leaf)) {
    throw "No existe un plan de fase 5. Reanude INICIAR-CONSOLIDACION.sh."
}
$summary = Get-Content -LiteralPath $summaryPath -Raw -Encoding UTF8 | ConvertFrom-Json
$currentConfigHash = Get-ConfigurationHash
if ([string]$summary.config_sha256 -ne $currentConfigHash) {
    throw "El plan no corresponde al config.yaml confirmado. Ejecute nuevamente los comandos 12 y 16."
}
if ([int]$summary.blocking_conflicts -gt 0) {
    throw "El plan contiene conflictos bloqueantes."
}

$targetSite = Get-TargetSite
Write-Host ""
Write-Host "FASE 5: restauración controlada del curso piloto" -ForegroundColor Yellow
Write-Host "Origen: $($summary.source_id)" -ForegroundColor Yellow
Write-Host "Curso: $($summary.source_course_idnumber)" -ForegroundColor Yellow
Write-Host "Destino: $($targetSite.Name) ($($targetSite.id))" -ForegroundColor Yellow
Write-Host "Categoría destino: $($summary.target_category_id)" -ForegroundColor Yellow
Write-Host "Matrículas previstas: $($summary.enrolments_planned)" -ForegroundColor Yellow
Write-Host "Roles estándar previstos: $($summary.roles_planned)" -ForegroundColor Yellow
Write-Host "Backup SHA-256: $($summary.artifacts_sha256.'normalized_backup.mbz')" -ForegroundColor DarkGray
$planHash = (
    Get-FileHash -LiteralPath $summaryPath -Algorithm SHA256
).Hash.ToLowerInvariant()
if ([string]::IsNullOrWhiteSpace($AssistantApproval)) {
    $confirmation = Read-Host "Escriba exactamente APLICAR FASE 5 para continuar"
    if ($confirmation -cne "APLICAR FASE 5") {
        Write-Host "Operación cancelada. No se modificó el destino." -ForegroundColor Yellow
        exit 0
    }
} else {
    $expectedApproval = "ASSISTANT-PHASE5-$planHash"
    if ($AssistantApproval -cne $expectedApproval) {
        throw "La autorización interna del asistente no corresponde al piloto."
    }
    Write-Host "Autorización guiada verificada contra el SHA-256 del piloto." -ForegroundColor Green
}

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}
$targetService = $targetSite.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $ProjectRoot "reports\fase-5-aplicacion-curso-piloto.txt"
Write-Host "Revalidando el plan contra el estado actual del destino..." -ForegroundColor Cyan
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase5"
$preflightOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase5-apply-preflight.php `
    "--phase4=/exports/phase4" `
    "--phase5=/exports/phase5" `
    "--configsha=$currentConfigHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=$expectLab" 2>&1
$preflightExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase5"
$preflightOutput | Tee-Object -FilePath $report
if ($preflightExit -ne 0) {
    throw "La prevalidación de fase 5 falló. Revise $report."
}
$preflight = Get-Content -LiteralPath (Join-Path $phase5Host "apply_preflight.json") -Raw -Encoding UTF8 |
    ConvertFrom-Json

Register-DestinationWriteIntent `
    -Phase "phase5-pilot" `
    -BoundHash $planHash

if ([string]$preflight.mode -in @("restore_new", "recover_failed_restore")) {
    if ([string]$preflight.mode -eq "recover_failed_restore") {
        Write-Host "Recuperando de forma controlada el curso contenedor del intento fallido..." -ForegroundColor Yellow
    }
    Write-Host "Restaurando el backup normalizado mediante las API oficiales de Moodle..." -ForegroundColor Cyan
    Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase5"
    # Windows PowerShell 5.1 convierte stderr de un ejecutable nativo en
    # NativeCommandError cuando ErrorActionPreference=Stop. Captúrelo como
    # salida para conservar el diagnóstico emitido por Moodle y evaluar el
    # código de salida de forma explícita.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $restoreOutput = & docker compose exec -T -u www-data $targetService `
            php /opt/consolidator/phase5-restore.php `
            "--phase4=/exports/phase4" `
            "--phase5=/exports/phase5" `
            "--configsha=$currentConfigHash" `
            "--targetid=$($targetSite.id)" `
            "--expectlab=$expectLab" 2>&1
        $restoreExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase5"
    $restoreOutput | Out-File -FilePath $report -Append -Encoding UTF8
    $restoreOutput | ForEach-Object { Write-Host $_ }
    if ($restoreExit -ne 0) {
        throw "La restauración falló y fue revertida. Revise $report y exports\phase5\restore_diagnostic.json."
    }
} else {
    Write-Host "No se repetirá la restauración: modo $($preflight.mode)." -ForegroundColor Yellow
}

Write-Host "Identificando y marcando el curso restaurado..." -ForegroundColor Cyan
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase5"
$finalOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase5-finalize.php `
    "--phase4=/exports/phase4" `
    "--phase5=/exports/phase5" `
    "--configsha=$currentConfigHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=$expectLab" 2>&1
$finalExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase5"
$finalOutput | Out-File -FilePath $report -Append -Encoding UTF8
$finalOutput | ForEach-Object { Write-Host $_ }
if ($finalExit -ne 0) {
    throw "La finalización de fase 5 falló. Revise $report."
}

$applySummary = Get-Content -LiteralPath (Join-Path $phase5Host "apply_summary.json") -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host "FASE 5 aplicada sobre el curso piloto." -ForegroundColor Green
Write-Host "Curso destino ID: $($applySummary.target_course_id)." -ForegroundColor Cyan
Write-Host "Estado: $($applySummary.apply_status)." -ForegroundColor Cyan
Write-Host "La siguiente etapa verificará el curso piloto." -ForegroundColor Yellow
