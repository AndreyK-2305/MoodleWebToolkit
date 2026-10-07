. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$phase5Host = Join-Path $ProjectRoot "exports\phase5"
$phase6Host = Join-Path $ProjectRoot "exports\phase6"
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
        throw "Falta exports\phase4\$fileName."
    }
}
foreach ($fileName in @(
    "plan_summary.json",
    "pilot_course_map.csv",
    "apply_summary.json",
    "target_course_inventory.json",
    "verification.csv",
    "verification.json"
)) {
    if (-not (Test-Path (Join-Path $phase5Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase5\$fileName."
    }
}

$batchConfig = Import-Phase6BatchConfig
$selectedSources = @($batchConfig.sources | ForEach-Object { [string]$_ })
foreach ($sourceId in $selectedSources) {
    if (-not (Test-Path `
        (Join-Path $phase6Host "source-inventory-$sourceId.json") `
        -PathType Leaf)) {
        throw "Falta el inventario importado del origen '$sourceId'."
    }
    if (-not (Test-Path `
        (Join-Path $ProjectRoot "exports\packages\$sourceId\manifest.json") `
        -PathType Leaf)) {
        throw "Falta el paquete importado del origen '$sourceId'."
    }
}

$targetSite = Get-TargetSite
New-Item -ItemType Directory -Force -Path $phase6Host, $reportsHost | Out-Null
foreach ($laterArtifact in @(
    "category_apply_summary.json",
    "category_map.csv",
    "batch_manifest.json",
    "batch_apply_summary.json",
    "batch_verification.json"
)) {
    if (Test-Path (Join-Path $phase6Host $laterArtifact) -PathType Leaf) {
        throw "La fase del lote ya registra un manifiesto o escrituras posteriores."
    }
}

Copy-Item -LiteralPath $Phase6BatchConfigPath `
    -Destination (Join-Path $phase6Host "batch_config.json") -Force
Copy-Item -LiteralPath $Phase6RoleResolutionsPath `
    -Destination (Join-Path $phase6Host "role_resolutions.csv") -Force
foreach ($pattern in @(
    "target_inventory.json",
    "category_plan.csv",
    "course_plan.csv",
    "course_user_plan.csv",
    "course_role_plan.csv",
    "role_normalization.csv",
    "identity_convergence.csv",
    "plan_summary.json"
)) {
    Get-ChildItem -LiteralPath $phase6Host -Filter $pattern -File `
        -ErrorAction SilentlyContinue |
        Remove-Item -Force
}

$targetService = [string]$targetSite.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath "/exports/phase6"

$configHash = Get-ConfigurationHash
$report = Join-Path $reportsHost "fase-6-plan-paquetes.txt"
Write-Host "Revalidando el destino y el piloto aprobado..." -ForegroundColor Cyan
$targetOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase6-target-inventory.php `
    "--phase4=/exports/phase4" `
    "--phase5=/exports/phase5" `
    "--output=/exports/phase6/target_inventory.json" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--parentcategoryid=$($batchConfig.target_parent_category_id)" `
    "--expectlab=0" 2>&1
$targetExit = $LASTEXITCODE
$targetOutput | Tee-Object -FilePath $report
if ($targetExit -ne 0) {
    throw "La revalidación del destino falló. Revise $report."
}

Write-Host "Construyendo el plan desde los paquetes..." -ForegroundColor Cyan
$planOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase6-plan.php `
    "--phase4=/exports/phase4" `
    "--input=/exports/phase6" `
    "--output=/exports/phase6" `
    "--batchconfig=/exports/phase6/batch_config.json" `
    "--roleresolutions=/exports/phase6/role_resolutions.csv" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=0" 2>&1
$planExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
$planOutput | Out-File -FilePath $report -Append -Encoding UTF8
$planOutput | ForEach-Object { Write-Host $_ }
if ($planExit -ne 0) {
    throw "La planificación del lote falló. Revise $report."
}

$summary = Get-Content `
    -LiteralPath (Join-Path $phase6Host "plan_summary.json") `
    -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host (
    "PACKAGE_BATCH_PLAN_OK discovered=$($summary.courses_discovered) " +
    "restore=$($summary.courses_to_restore) " +
    "blocked=$($summary.blocking_conflicts) write=0"
) -ForegroundColor Green
Write-Host (
    "Revise category_plan.csv, course_plan.csv, course_user_plan.csv, " +
    "course_role_plan.csv, role_normalization.csv e identity_convergence.csv."
) -ForegroundColor Yellow
