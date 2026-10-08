. "$PSScriptRoot/Common.ps1"
. "$PSScriptRoot/WorkerCount.ps1"

Assert-ConfigurationConfirmed
Assert-Command 'docker'
& docker info *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker no está iniciado o no responde.' }

$phase6Host = Join-Path $ProjectRoot 'exports/phase6'
$reportsHost = Join-Path $ProjectRoot 'reports'
$required = @(
    'plan_summary.json', 'batch_config.json', 'role_resolutions.csv',
    'target_inventory.json', 'category_plan.csv', 'course_plan.csv',
    'course_user_plan.csv', 'course_role_plan.csv', 'role_normalization.csv',
    'identity_convergence.csv'
)
foreach ($name in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $phase6Host $name) -PathType Leaf)) {
        throw "Falta exports/phase6/$name."
    }
}
$summary = Get-Content -LiteralPath (Join-Path $phase6Host 'plan_summary.json') `
    -Raw -Encoding UTF8 | ConvertFrom-Json
if ([string]$summary.plan_status -ne 'applicable' -or
        [int]$summary.blocking_conflicts -ne 0 -or
        [bool]$summary.destination_write_performed) {
    throw 'El plan del lote no está aplicable.'
}

New-Item -ItemType Directory -Force -Path $phase6Host, $reportsHost | Out-Null
$resolutionSource = Join-Path $ProjectRoot 'config/phase6-degradation-resolutions.csv'
$resolutionTarget = Join-Path $phase6Host 'degradation-resolutions.csv'
$integritySource = Join-Path $ProjectRoot 'config/source-integrity-policies.csv'
$integrityTarget = Join-Path $phase6Host 'source-integrity-policies.csv'
foreach ($path in @($resolutionSource, $integritySource)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Falta $path."
    }
}
Copy-Item -LiteralPath $resolutionSource -Destination $resolutionTarget -Force
Copy-Item -LiteralPath $integritySource -Destination $integrityTarget -Force

$workers = Resolve-WorkerCount
$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
$configHash = Get-ConfigurationHash
$expectLab = if ($MigrationConfig.Mode -eq 'lab') { '1' } else { '0' }

Invoke-Compose -Arguments @('up', '-d', '--no-build', $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath '/exports/phase6' `
    -ChildDirectories @(
        'course-inventories', 'course-jobs', 'backup-checkpoints',
        'course-degradation-plans', 'degradation-cache', 'course-worker-states'
    )

$projectDrive = [System.IO.DriveInfo]::new(
    [System.IO.Path]::GetPathRoot([System.IO.Path]::GetFullPath($ProjectRoot))
)
if ($projectDrive.AvailableFreeSpace -lt 1024MB) {
    throw 'PHASE12_SPACE_INSUFFICIENT: se requiere al menos 1 GiB libre.'
}

$report = Join-Path $reportsHost 'fase-6-preparacion-paquetes.txt'
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $output = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/phase6-build-batch-manifest.php `
        '--phase4=/exports/phase4' '--phase6=/exports/phase6' `
        "--configsha=$configHash" "--targetid=$($targetSite.id)" `
        '--source-integrity=/exports/phase6/source-integrity-policies.csv' `
        '--resolutions=/exports/phase6/degradation-resolutions.csv' `
        "--expectlab=$expectLab" 2>&1
    $exitCode = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
Restore-AssistantExportOwnership -Service $targetService `
    -ContainerPath '/exports/phase6'
$output | Set-Content -LiteralPath $report -Encoding UTF8
$output | ForEach-Object { Write-Host $_ }
if ($exitCode -ne 0) {
    throw 'La creación del manifiesto ligero de Fase 12 falló.'
}

$manifestPath = Join-Path $phase6Host 'batch_manifest.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
if ([string]$manifest.manifest_status -ne 'BATCH_READY' -or
        -not [bool]$manifest.worker_course_precheck -or
        [int]$manifest.mbz_deep_open_count -ne 0 -or
        [bool]$manifest.destination_write_performed) {
    throw 'PHASE12_LIGHTWEIGHT_CONTRACT_INVALID'
}

Write-Host (
    "BATCH_READY courses=$($manifest.courses_expected) workers=$workers " +
    'copied=0 extracted=0 hashed_again=0 write=0'
) -ForegroundColor Green
Write-Host 'MBZ_DEEP_OPEN_COUNT=0' -ForegroundColor Green
Write-Host 'PHASE12_READY' -ForegroundColor Green
