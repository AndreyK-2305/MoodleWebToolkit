. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}

$phase5Host = Join-Path $ProjectRoot "exports\phase5"
foreach ($fileName in @(
    "plan_summary.json",
    "pilot_course_map.csv",
    "apply_summary.json"
)) {
    if (-not (Test-Path (Join-Path $phase5Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase5\$fileName. Ejecute primero los comandos 16 y 17."
    }
}

$targetSite = Get-TargetSite
$targetService = $targetSite.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase5"

$configHash = Get-ConfigurationHash
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $ProjectRoot "reports\fase-5-verificacion-curso-piloto.txt"
Write-Host "Comparando estructura, usuarios, roles y relaciones académicas..." -ForegroundColor Cyan
$verifyOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase5-verify.php `
    "--phase4=/exports/phase4" `
    "--phase5=/exports/phase5" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=$expectLab" 2>&1
$verifyExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase5"
$verifyOutput | Tee-Object -FilePath $report
if ($verifyExit -ne 0) {
    throw "La verificación de fase 5 falló. Revise $report y exports\phase5\verification.csv."
}

$verification = Get-Content -LiteralPath (Join-Path $phase5Host "verification.json") -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host "FASE 5 verificada: $($verification.validation)." -ForegroundColor Green
Write-Host "Curso destino ID: $($verification.target_course_id)." -ForegroundColor Cyan
Write-Host "Matrículas verificadas: $($verification.enrolments_verified)." -ForegroundColor Cyan
Write-Host "Roles verificados: $($verification.roles_verified)." -ForegroundColor Cyan
Write-Host "Comprobaciones fallidas: $($verification.failed_checks)." -ForegroundColor Cyan
