. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$phase5Host = Join-Path $ProjectRoot "exports\phase5"
$reportsHost = Join-Path $ProjectRoot "reports"
foreach ($fileName in @(
    "target_user_plan.csv",
    "plan_summary.json",
    "target_user_map.csv",
    "source_to_target_user_map.csv",
    "apply_summary.json",
    "verification.csv",
    "verification.json"
)) {
    if (-not (Test-Path (Join-Path $phase4Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase4\$fileName. La fase de usuarios no está verificada."
    }
}

$pilotConfigPath = Join-Path $ProjectRoot `
    "config\phase5-pilot-package.json"
if (-not (Test-Path -LiteralPath $pilotConfigPath -PathType Leaf)) {
    throw "Falta config\phase5-pilot-package.json."
}
try {
    $pilot = Get-Content -LiteralPath $pilotConfigPath -Raw -Encoding UTF8 |
        ConvertFrom-Json
} catch {
    throw "phase5-pilot-package.json no contiene JSON válido."
}
if ([string]$pilot.schema_version -ne "1.0" -or
        [string]$pilot.source_id -notmatch '^[a-z][a-z0-9_-]*$' -or
        [string]$pilot.course_key -notmatch
            '^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$' -or
        [int]$pilot.source_course_id -lt 1 -or
        [int]$pilot.target_category_id -lt 1) {
    throw "phase5-pilot-package.json contiene una selección inválida."
}
Assert-SourceNames @([string]$pilot.source_id)
$packageHost = Join-Path $ProjectRoot `
    "exports\packages\$($pilot.source_id)"
if (-not (Test-Path -LiteralPath $packageHost -PathType Container)) {
    throw "No existe el paquete importado '$($pilot.source_id)'."
}

New-Item -ItemType Directory -Force -Path `
    $phase5Host, (Join-Path $phase5Host "backups"), $reportsHost | Out-Null
Copy-Item -LiteralPath $pilotConfigPath `
    -Destination (Join-Path $phase5Host "pilot_config.json") -Force
foreach ($generatedName in @(
    "apply_preflight.json",
    "pilot_course_map.csv",
    "apply_summary.json",
    "target_course_inventory.json",
    "verification.csv",
    "verification.json"
)) {
    $generatedPath = Join-Path $phase5Host $generatedName
    if (Test-Path $generatedPath -PathType Leaf) {
        Remove-Item -LiteralPath $generatedPath -Force
    }
}

$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
Write-Host "Iniciando únicamente el Moodle destino..." -ForegroundColor Cyan
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite `
    -Service $targetService `
    -ContainerPath "/exports/phase5" `
    -ChildDirectories @("backups")

$configHash = Get-ConfigurationHash
$report = Join-Path $reportsHost "fase-5-simulacion-piloto-paquete.txt"
Write-Host "Inventariando el destino sin modificarlo..." -ForegroundColor Cyan
$targetOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase5-target-inventory.php `
    "--phase4=/exports/phase4" `
    "--output=/exports/phase5/target_preflight.json" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--categoryid=$([int]$pilot.target_category_id)" `
    "--expectlab=0" 2>&1
$targetExit = $LASTEXITCODE
$targetOutput | Tee-Object -FilePath $report
if ($targetExit -ne 0) {
    throw "El inventario del destino falló. Revise $report."
}

Write-Host "Auditando y normalizando el piloto recibido..." -ForegroundColor Cyan
$prepareOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase5-prepare-package.php `
    "--phase4=/exports/phase4" `
    "--output=/exports/phase5" `
    "--package=/exports/packages/$($pilot.source_id)" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--sourceid=$($pilot.source_id)" `
    "--coursekey=$($pilot.course_key)" `
    "--targeturl=$($targetSite.url)" `
    "--categoryid=$([int]$pilot.target_category_id)" `
    "--expectlab=0" 2>&1
$prepareExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase5"
$prepareOutput | Out-File -FilePath $report -Append -Encoding UTF8
$prepareOutput | ForEach-Object { Write-Host $_ }
if ($prepareExit -ne 0) {
    throw "La simulación del piloto falló. Revise $report."
}

$summary = Get-Content `
    -LiteralPath (Join-Path $phase5Host "plan_summary.json") `
    -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host (
    "PACKAGE_PILOT_PLAN_OK source=$($pilot.source_id) " +
    "course=$($pilot.course_key) blocked=$($summary.blocking_conflicts) write=0"
) -ForegroundColor Green
Write-Host (
    "Revise pilot_course_plan.csv, pilot_user_plan.csv, " +
    "pilot_role_plan.csv y backup_user_rewrite.csv."
) -ForegroundColor Yellow
