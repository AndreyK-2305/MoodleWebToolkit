. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$phase6Host = Join-Path $ProjectRoot "exports\phase6"
$reportsHost = Join-Path $ProjectRoot "reports"
foreach ($fileName in @(
    "plan_summary.json",
    "batch_config.json",
    "role_resolutions.csv",
    "target_inventory.json",
    "category_plan.csv",
    "course_plan.csv",
    "course_user_plan.csv",
    "course_role_plan.csv",
    "role_normalization.csv",
    "identity_convergence.csv"
)) {
    if (-not (Test-Path (Join-Path $phase6Host $fileName) -PathType Leaf)) {
        throw "Falta exports\phase6\$fileName."
    }
}
$summary = Get-Content `
    -LiteralPath (Join-Path $phase6Host "plan_summary.json") `
    -Raw -Encoding UTF8 |
    ConvertFrom-Json
if ([string]$summary.plan_status -ne "applicable" -or
        [int]$summary.blocking_conflicts -ne 0 -or
        [bool]$summary.destination_write_performed) {
    throw "El plan del lote no está aplicable."
}

foreach ($directory in @(
    (Join-Path $phase6Host "course-inventories"),
    (Join-Path $phase6Host "course-jobs"),
    (Join-Path $phase6Host "backup-checkpoints"),
    $reportsHost
)) {
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
}

$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)

$courses = @(
    Import-Csv `
        -LiteralPath (Join-Path $phase6Host "course_plan.csv") `
        -Encoding UTF8 |
    Where-Object { [string]$_.action -eq "restore_new" } |
    Sort-Object -Property @(
        "source"
        @{ Expression = { [int]$_.source_course_id } }
    )
)
if ($courses.Count -ne [int]$summary.courses_to_restore) {
    throw "course_plan.csv no conserva la cantidad aprobada."
}
$configHash = Get-ConfigurationHash
$report = Join-Path $reportsHost "fase-6-preparacion-paquetes.txt"
if (Test-Path $report -PathType Leaf) {
    Remove-Item -LiteralPath $report -Force
}
Grant-ContainerExportWrite `
    -Service $targetService `
    -ContainerPath "/exports/phase6" `
    -ChildDirectories @(
        "course-inventories",
        "course-jobs",
        "backup-checkpoints"
    )

$position = 0
foreach ($course in $courses) {
    $position++
    Write-Progress `
        -Activity "Auditando paquete $position de $($courses.Count)" `
        -Status "$($course.source): $($course.source_shortname)" `
        -PercentComplete (($position / $courses.Count) * 100)
    Write-Host (
        "[$position/$($courses.Count)] $($course.source): " +
        "$($course.source_shortname)"
    ) -ForegroundColor Cyan
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $output = & docker compose exec -T -u www-data $targetService `
            php /opt/consolidator/phase6-prepare-package-course.php `
            "--phase4=/exports/phase4" `
            "--phase6=/exports/phase6" `
            "--package=/exports/packages/$($course.source)" `
            "--configsha=$configHash" `
            "--targetid=$($targetSite.id)" `
            "--sourceid=$($course.source)" `
            "--coursekey=$($course.course_key)" `
            "--expectlab=0" 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    $output | Out-File -FilePath $report -Append -Encoding UTF8
    $output | ForEach-Object { Write-Host $_ }
    if ($exitCode -ne 0) {
        Write-Progress -Activity "Auditando paquetes" -Completed
        Restore-AssistantExportOwnership `
            -Service $targetService `
            -ContainerPath "/exports/phase6"
        throw (
            "La preparación se detuvo en $($course.course_key). " +
            "Los checkpoints anteriores se conservan."
        )
    }
}
Write-Progress -Activity "Auditando paquetes" -Completed

Write-Host "Sellando el manifiesto central..." -ForegroundColor Cyan
$sealOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase6-seal-backups.php `
    "--phase4=/exports/phase4" `
    "--phase6=/exports/phase6" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=0" 2>&1
$sealExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
$sealOutput | Out-File -FilePath $report -Append -Encoding UTF8
$sealOutput | ForEach-Object { Write-Host $_ }
if ($sealExit -ne 0) {
    throw "No fue posible sellar batch_manifest.json."
}

$manifest = Get-Content `
    -LiteralPath (Join-Path $phase6Host "batch_manifest.json") `
    -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host (
    "PACKAGE_BATCH_REFERENCED_OK courses=$($manifest.courses_prepared) " +
    "pending=$($manifest.courses_pending) copied=0 extracted=0 hashed_again=0 write=0"
) -ForegroundColor Green
Write-Host "Los MBZ originales permanecen intactos y no se duplicaron." -ForegroundColor Yellow
