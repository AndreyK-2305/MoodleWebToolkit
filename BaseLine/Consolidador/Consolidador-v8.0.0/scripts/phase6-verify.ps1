. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"

$phase6Host = Join-Path $ProjectRoot "exports\phase6"
if (-not (Test-Path (Join-Path $phase6Host "batch_apply_summary.json") -PathType Leaf)) {
    throw "Falta la aplicación completa de fase 6. Ejecute primero el comando 21."
}
$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
$configHash = Get-ConfigurationHash
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $ProjectRoot "reports\fase-6-verificacion-lote.txt"

Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase6"

Write-Host "Verificando jerarquía y evidencia incremental sellada por curso..." -ForegroundColor Cyan
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Continue"
try {
    $verifyOutput = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/phase6-verify.php `
        "--phase4=/exports/phase4" `
        "--phase6=/exports/phase6" `
        "--configsha=$configHash" `
        "--targetid=$($targetSite.id)" `
        "--expectlab=$expectLab" 2>&1
    $verifyExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
$verifyOutput | Tee-Object -FilePath $report
if ($verifyExit -ne 0) {
    throw "La verificación del lote falló. Revise $report y exports\phase6\batch_verification.csv."
}

$summary = Get-Content `
    -LiteralPath (Join-Path $phase6Host "batch_verification.json") `
    -Raw `
    -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host "FASE 6 verificada correctamente." -ForegroundColor Green
Write-Host "Cursos verificados: $($summary.courses_verified)." -ForegroundColor Cyan
Write-Host "Cursos con diferencias: $($summary.failed_courses)." -ForegroundColor Cyan
Write-Host "Piloto conservado: $($summary.pilot_course_id)." -ForegroundColor Cyan
Write-Host "Estado: $($summary.validation)." -ForegroundColor Cyan
Write-Host "Modo: $($summary.verification_mode)." -ForegroundColor Cyan
