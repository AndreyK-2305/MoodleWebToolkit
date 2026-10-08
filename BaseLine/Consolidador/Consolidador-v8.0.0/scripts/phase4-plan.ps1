. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}

$phase3Host = Join-Path $ProjectRoot "exports\phase3"
$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$reportsHost = Join-Path $ProjectRoot "reports"
$requiredPhase3 = @(
    "canonical_users.csv",
    "source_user_map.csv",
    "summary.json"
)
foreach ($fileName in $requiredPhase3) {
    if (-not (Test-Path (Join-Path $phase3Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase3\$fileName. Reanude INICIAR-CONSOLIDACION.sh."
    }
}
foreach ($sourceName in @(Get-SourceSiteNames)) {
    $identityFile = "identity-$sourceName.json"
    if (-not (Test-Path (Join-Path $phase3Host $identityFile) -PathType Leaf)) {
        throw "Falta exports\phase3\$identityFile. Ejecute nuevamente el comando 11."
    }
}

New-Item -ItemType Directory -Force -Path $phase4Host, $reportsHost | Out-Null
$targetSite = Get-TargetSite
$targetService = $targetSite.service
Write-Host "Iniciando únicamente el Moodle destino confirmado..." -ForegroundColor Cyan
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)

Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase4"

$configHash = Get-ConfigurationHash
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $reportsHost "fase-4-simulacion-usuarios.txt"
Write-Host "Consultando el destino y construyendo el plan sin modificarlo..." -ForegroundColor Cyan
$planOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase4-plan.php `
    "--input=/exports/phase3" `
    "--output=/exports/phase4" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=$expectLab" 2>&1
$planExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase4"
$planOutput | Tee-Object -FilePath $report
if ($planExit -ne 0) {
    throw "La simulación de fase 4 falló. Revise $report."
}

$planPath = Join-Path $phase4Host "target_user_plan.csv"
$summaryPath = Join-Path $phase4Host "plan_summary.json"
if (-not (Test-Path $planPath -PathType Leaf) -or
        -not (Test-Path $summaryPath -PathType Leaf)) {
    throw "La simulación no produjo target_user_plan.csv y plan_summary.json."
}
$summary = Get-Content -LiteralPath $summaryPath -Raw -Encoding UTF8 | ConvertFrom-Json

Write-Host ""
Write-Host "FASE 4 simulada sin modificar el Moodle destino." -ForegroundColor Green
Write-Host "Destino consultado: $($targetSite.Name) ($($targetSite.id))." -ForegroundColor Cyan
Write-Host "Identidades canónicas: $($summary.canonical_identities)." -ForegroundColor Cyan
Write-Host "Aplicables: $($summary.applicable_identities). Bloqueadas: $($summary.blocked_identities)." -ForegroundColor Cyan
Write-Host "Excluidas por decisión: $($summary.excluded_identities). Pendientes de identidad: $($summary.identity_review_pending)." -ForegroundColor Cyan
Write-Host "Colisiones contra el destino: $($summary.blocking_conflicts)." -ForegroundColor Cyan
Write-Host "Plan: $planPath" -ForegroundColor Cyan
if ([int]$summary.blocking_conflicts -gt 0) {
    Write-Host "No ejecute el comando 14. Revise las filas conflict_* y orphan_canonical_markers de plan_summary.json." -ForegroundColor Yellow
} else {
    Write-Host "Revise el plan antes de autorizar el comando 14." -ForegroundColor Yellow
}
Write-Host "Esta fase no concede roles, no matricula usuarios y no restaura cursos." -ForegroundColor Yellow
