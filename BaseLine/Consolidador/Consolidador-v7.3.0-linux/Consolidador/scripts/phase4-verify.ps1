. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}

$phase4Host = Join-Path $ProjectRoot "exports\phase4"
foreach ($fileName in @(
    "target_user_plan.csv",
    "plan_summary.json",
    "target_user_map.csv",
    "source_to_target_user_map.csv",
    "apply_summary.json"
)) {
    if (-not (Test-Path (Join-Path $phase4Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase4\$fileName. Ejecute primero los comandos 13 y 14."
    }
}

$targetSite = Get-TargetSite
$targetService = $targetSite.service
$oauthSummaryPath = Join-Path $ProjectRoot "exports\oauth2\validation.json"
if (-not (Test-Path -LiteralPath $oauthSummaryPath -PathType Leaf)) {
    throw "Falta la validación OAuth2."
}
$oauthSummary = Get-Content -LiteralPath $oauthSummaryPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
$configHash = Get-ConfigurationHash
$oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
    throw "Falta config\oauth2.json."
}
$oauthConfigHash = (
    Get-FileHash -LiteralPath $oauthConfigPath -Algorithm SHA256
).Hash.ToLowerInvariant()
if ([string]$oauthSummary.config_sha256 -ne $configHash -or
        [string]$oauthSummary.oauth_config_sha256 -ne $oauthConfigHash -or
        [string]$oauthSummary.status -ne "ready" -or
        [int]$oauthSummary.issuer_id -lt 1) {
    throw "OAuth2 no está listo para verificar los vínculos de acceso."
}
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase4"

$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$report = Join-Path $ProjectRoot "reports\fase-4-verificacion-usuarios.txt"
Write-Host "Verificando marcadores, IDs y atributos canónicos en el destino..." -ForegroundColor Cyan
$verifyOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase4-verify.php `
    "--phase3=/exports/phase3" `
    "--phase4=/exports/phase4" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--oauthissuerid=$($oauthSummary.issuer_id)" `
    "--expectlab=$expectLab" 2>&1
$verifyExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase4"
$verifyOutput | Tee-Object -FilePath $report
if ($verifyExit -ne 0) {
    throw "La verificación de fase 4 falló. Revise $report y exports\phase4\verification.csv."
}

$verificationPath = Join-Path $phase4Host "verification.json"
$verification = Get-Content -LiteralPath $verificationPath -Raw -Encoding UTF8 | ConvertFrom-Json
Write-Host ""
Write-Host "FASE 4 verificada: $($verification.validation)." -ForegroundColor Green
Write-Host "Identidades comprobadas: $($verification.canonical_rows_checked)." -ForegroundColor Cyan
Write-Host "Usuarios destino mapeados: $($verification.target_users_mapped)." -ForegroundColor Cyan
Write-Host "Vínculos Google verificados: $($verification.oauth2_links_verified)." -ForegroundColor Cyan
Write-Host "Comprobaciones fallidas: $($verification.failed_checks)." -ForegroundColor Cyan
Write-Host "Roles y matrículas continúan fuera del alcance de esta fase." -ForegroundColor Yellow
