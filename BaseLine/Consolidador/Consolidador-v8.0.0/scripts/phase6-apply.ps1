param(
    [string]$AssistantApproval = ""
)

. "$PSScriptRoot/Common.ps1"

. "$PSScriptRoot/WorkerCount.ps1"
. "$PSScriptRoot/Phase6SourceRouting.ps1"

function Get-WorkerFailureDisposition([string]$Status, [string]$Category) {
    if ($Status -eq 'WAITING_MANUAL' -and $Category -in @(
            'SOURCE_DATA_DEFECT', 'MOODLE_RESTORE_INCOMPATIBILITY')) {
        return [pscustomobject]@{
            Status = 'WAITING_MANUAL'; Category = $Category
            StopAssigning = $false
        }
    }
    return [pscustomobject]@{
        Status = 'FATAL_SYSTEMIC'
        Category = if ($Category -in @('PRECONDITION_BUG', 'TOOL_INTERNAL_ERROR')) {
            $Category
        } else { 'TOOL_INTERNAL_ERROR' }
        StopAssigning = $true
    }
}

function Start-CourseProcess {
    param(
        [Parameter(Mandatory)]$Course,
        [Parameter(Mandatory)][int]$WorkerId,
        [Parameter(Mandatory)][int]$Position,
        [Parameter(Mandatory)][string]$TargetService,
        [Parameter(Mandatory)][string]$ConfigHash,
        [Parameter(Mandatory)][string]$TargetId,
        [Parameter(Mandatory)][string]$TargetUrl,
        [Parameter(Mandatory)][string]$SourceId,
        [Parameter(Mandatory)][string]$ExpectLab
    )
    $arguments = @(
        '-NoLogo', '-NoProfile', '-File',
        (Join-Path $PSScriptRoot 'phase6-course-worker.ps1'),
        '-TargetService', $TargetService,
        '-ConfigHash', $ConfigHash,
        '-TargetId', $TargetId,
        '-TargetUrl', $TargetUrl,
        '-SourceId', $SourceId,
        '-CourseKey', ([string]$Course.course_key),
        '-ExpectLab', $ExpectLab
    )
    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = 'pwsh'
    foreach ($argument in $arguments) {
        [void]$startInfo.ArgumentList.Add([string]$argument)
    }
    $startInfo.WorkingDirectory = $ProjectRoot
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    if (-not $process.Start()) {
        throw "No fue posible iniciar el worker $WorkerId."
    }
    return [pscustomobject]@{
        WorkerId = $WorkerId
        Position = $Position
        Course = $Course
        Process = $process
        StandardOutput = $process.StandardOutput.ReadToEndAsync()
        StandardError = $process.StandardError.ReadToEndAsync()
        StartedAt = [DateTimeOffset]::UtcNow
    }
}

function Write-WorkerSnapshot {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)]$Active,
        [Parameter(Mandatory)][int]$Completed,
        [Parameter(Mandatory)][int]$CompletedWithWarnings,
        [Parameter(Mandatory)][int]$Failed,
        [Parameter(Mandatory)][int]$Total,
        [Parameter(Mandatory)][int]$Pending,
        [Parameter(Mandatory)][int]$Workers,
        [Parameter(Mandatory)][string]$WorkersRequested
    )
    $snapshot = [ordered]@{
        schema_version = "1.0"
        phase = "6-parallel-course-restore"
        updated_at_utc = [DateTimeOffset]::UtcNow.ToString("o")
        workers = $Workers
        workers_requested = $WorkersRequested
        workers_effective = $Workers
        completed = $Completed
        completed_with_warnings = $CompletedWithWarnings
        failed = $Failed
        pending = $Pending
        total = $Total
        percent = if ($Total -gt 0) {
            [Math]::Round((100.0 * $Completed) / $Total, 2)
        } else { 100 }
        active = @($Active | ForEach-Object {
            [ordered]@{
                worker = $_.WorkerId
                course_key = [string]$_.Course.course_key
                source = [string]$_.Course.source
                shortname = [string]$_.Course.source_shortname
                estimated_weight = [int64]$_.Course.estimated_weight
                elapsed_seconds = [int]([DateTimeOffset]::UtcNow - $_.StartedAt).TotalSeconds
            }
        })
    }
    $temporary = "$Path.partial-$PID"
    $snapshot | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $temporary -Encoding UTF8
    Move-Item -LiteralPath $temporary -Destination $Path -Force
}

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}

$phase6Host = Join-Path $ProjectRoot "exports\phase6"
$manifestPath = Join-Path $phase6Host "batch_manifest.json"
$coursePlanPath = Join-Path $phase6Host "course_plan.csv"
if (-not (Test-Path $manifestPath -PathType Leaf)) {
    throw "Falta exports\phase6\batch_manifest.json. Ejecute primero el comando 20."
}
$manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
$courseSourceMap = Get-Phase6CourseSourceMap -CoursePlanPath $coursePlanPath
if ([string]$manifest.manifest_status -ne 'BATCH_READY' -or
        -not [bool]$manifest.single_extraction_pipeline -or
        -not [bool]$manifest.worker_course_precheck -or
        [int]$manifest.mbz_deep_open_count -ne 0 -or
        [bool]$manifest.destination_write_performed) {
    throw "El manifiesto no corresponde al pipeline optimizado preparado."
}
$manifestHash = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
$authorizationBytes = [System.Text.Encoding]::UTF8.GetBytes(
    $manifestHash + ':worker-course-precheck'
)
$authorizationHash = [Convert]::ToHexString(
    [System.Security.Cryptography.SHA256]::HashData($authorizationBytes)
).ToLowerInvariant()

$workers = Resolve-WorkerCount
$workersRequested = if ([string]::IsNullOrWhiteSpace($env:CONSOLIDATION_WORKERS)) {
    "auto"
} else { [string]$env:CONSOLIDATION_WORKERS }
$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
$configHash = Get-ConfigurationHash
$expectLab = if ($MigrationConfig.Mode -eq "lab") { "1" } else { "0" }
$reportsHost = Join-Path $ProjectRoot "reports"
New-Item -ItemType Directory -Force -Path $reportsHost | Out-Null
$report = Join-Path $reportsHost "fase-6-aplicacion-lote.txt"
$workersReport = Join-Path $reportsHost "fase-6-workers-status.json"
$workerLogs = Join-Path $reportsHost "fase-6-workers"
New-Item -ItemType Directory -Force -Path $workerLogs | Out-Null

Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite `
    -Service $targetService `
    -ContainerPath "/exports/phase6" `
    -ChildDirectories @(
        "normalization-audits",
        "target-inventories",
        "apply-states",
        "apply-checkpoints",
        "restore-diagnostics",
        "course-inventories",
        "course-jobs",
        "backup-checkpoints",
        "course-degradation-plans",
        "degradation-cache",
        "course-worker-states",
        "historical-users",
        "historical-audits"
    )

Write-Host "Revalidando manifiesto y reanudación..." -ForegroundColor Cyan
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Continue"
try {
    $preflightOutput = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/phase6-apply-preflight.php `
        "--phase4=/exports/phase4" `
        "--phase6=/exports/phase6" `
        "--configsha=$configHash" `
        "--targetid=$($targetSite.id)" `
        "--expectlab=$expectLab" 2>&1
    $preflightExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
$preflightOutput | Tee-Object -FilePath $report
if ($preflightExit -ne 0) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw "La prevalidación de aplicación falló. Revise $report."
}
Restore-AssistantExportOwnership `
    -Service $targetService `
    -ContainerPath "/exports/phase6"
$preflight = Get-Content `
    -LiteralPath (Join-Path $phase6Host "apply_preflight.json") `
    -Raw -Encoding UTF8 | ConvertFrom-Json

Write-Host ""
Write-Host "FASE 6: restauración paralela con extracción única" -ForegroundColor Yellow
Write-Host "Lote: $($manifest.batch_id)" -ForegroundColor Yellow
Write-Host "Cursos pendientes: $($preflight.courses_pending)" -ForegroundColor Yellow
Write-Host "Workers efectivos: $workers" -ForegroundColor Yellow
Write-Host "Workers solicitados: $workersRequested" -ForegroundColor Yellow
if ([string]::IsNullOrWhiteSpace($AssistantApproval)) {
    $confirmation = Read-Host "Escriba exactamente APLICAR FASE 6 para continuar"
    if ($confirmation -cne "APLICAR FASE 6") {
        Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
        Write-Host "Operación cancelada." -ForegroundColor Yellow
        exit 0
    }
} else {
    $expectedApproval = "ASSISTANT-PHASE6-$authorizationHash"
    if ($AssistantApproval -cne $expectedApproval) {
        Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
        throw "La autorización interna no corresponde al manifiesto."
    }
}
Register-DestinationWriteIntent -Phase "phase6-batch" -BoundHash $authorizationHash

Write-Host "Aplicando jerarquía y rol seguro..." -ForegroundColor Cyan
Grant-ContainerExportWrite `
    -Service $targetService `
    -ContainerPath "/exports/phase6" `
    -ChildDirectories @(
        "normalization-audits",
        "target-inventories",
        "apply-states",
        "apply-checkpoints",
        "restore-diagnostics",
        "course-inventories",
        "course-jobs",
        "backup-checkpoints",
        "course-degradation-plans",
        "degradation-cache",
        "course-worker-states",
        "historical-users",
        "historical-audits"
    )
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Continue"
try {
    $categoryOutput = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/phase6-apply-categories.php `
        "--phase4=/exports/phase4" `
        "--phase6=/exports/phase6" `
        "--configsha=$configHash" `
        "--targetid=$($targetSite.id)" `
        "--expectlab=$expectLab" 2>&1
    $categoryExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
$categoryOutput | Out-File -FilePath $report -Append -Encoding UTF8
$categoryOutput | ForEach-Object { Write-Host $_ }
if ($categoryExit -ne 0) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw "No fue posible aplicar la jerarquía. Revise $report."
}

# Mayor peso primero reduce la cola residual; no cambia el trabajo total, pero
# evita que el último worker quede solo con un curso grande.
$courses = @(
    @($manifest.entries) |
    Sort-Object -Property @(
        @{ Expression = { [int64]$_.estimated_weight }; Descending = $true }
        @{ Expression = { [string]$_.source } }
        @{ Expression = { [int]$_.source_course_id } }
    )
)
if ($courses.Count -ne [int]$manifest.courses_expected) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw "El manifiesto no conserva todos los cursos."
}
if ($courseSourceMap.Count -ne $courses.Count) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw 'PHASE13_SOURCE_CONTRACT_MISMATCH: course_plan y manifiesto difieren.'
}
foreach ($plannedCourse in $courses) {
    $routing = Resolve-Phase6CourseSource `
        -SourceMap $courseSourceMap `
        -CourseKey ([string]$plannedCourse.course_key) `
        -ReceivedSourceId ([string]$plannedCourse.source)
    if ($routing.Corrected) {
        Restore-AssistantExportOwnership `
            -Service $targetService -ContainerPath "/exports/phase6"
        throw (
            'PHASE13_SOURCE_CONTRACT_MISMATCH: course_key=' +
            [string]$routing.CourseKey + ' manifest=' +
            [string]$routing.ReceivedSourceId + ' planned=' +
            [string]$routing.SourceId
        )
    }
}

$queue = [System.Collections.Generic.Queue[object]]::new()
foreach ($course in $courses) { $queue.Enqueue($course) }
$active = [System.Collections.Generic.List[object]]::new()
$availableWorkerIds = [System.Collections.Generic.Queue[int]]::new()
for ($workerId = 1; $workerId -le $workers; $workerId++) {
    $availableWorkerIds.Enqueue($workerId)
}
$completed = 0
$completedWithWarnings = 0
$failed = 0
$waitingManual = 0
$position = 0
$fatalSystemic = $false
$failureMessages = [System.Collections.Generic.List[string]]::new()
$lastHeartbeat = [DateTimeOffset]::MinValue

while ($active.Count -gt 0 -or ($queue.Count -gt 0 -and -not $fatalSystemic)) {
    while (-not $fatalSystemic -and $queue.Count -gt 0 -and
            $active.Count -lt $workers) {
        $course = $queue.Dequeue()
        $plannedSourceId = [string]$courseSourceMap[
            ([string]$course.course_key).Trim().ToUpperInvariant()
        ]
        $workerId = $availableWorkerIds.Dequeue()
        $position++
        Write-Host (
            "WORKER_START worker=$workerId position=$position/$($courses.Count) " +
            "course_key=$($course.course_key) bytes=$($course.source_backup_bytes)"
        ) -ForegroundColor Cyan
        $running = Start-CourseProcess `
            -Course $course -WorkerId $workerId -Position $position `
            -TargetService $targetService -ConfigHash $configHash `
            -TargetId ([string]$targetSite.id) -TargetUrl ([string]$targetSite.url) `
            -SourceId $plannedSourceId `
            -ExpectLab $expectLab
        [void]$active.Add($running)
    }

    for ($index = $active.Count - 1; $index -ge 0; $index--) {
        $running = $active[$index]
        if (-not $running.Process.HasExited) { continue }
        $running.Process.WaitForExit()
        $stdout = $running.StandardOutput.GetAwaiter().GetResult()
        $stderr = $running.StandardError.GetAwaiter().GetResult()
        $combined = ($stdout + [Environment]::NewLine + $stderr).Trim()
        $safeKey = ([string]$running.Course.course_key) -replace '[^A-Za-z0-9_-]', '_'
        $logPath = Join-Path $workerLogs ("worker-$($running.WorkerId)-$safeKey.log")
        $combined | Set-Content -LiteralPath $logPath -Encoding UTF8
        if (-not [string]::IsNullOrWhiteSpace($combined)) {
            $combined | Out-File -FilePath $report -Append -Encoding UTF8
            $combined -split "`r?`n" | ForEach-Object { Write-Host $_ }
        }
        $resultMatch = [regex]::Match(
            $combined,
            'WORKER_RESULT status=(SUCCESS|WARNING|WAITING_MANUAL|FATAL_SYSTEMIC) category=([^\s]*)'
        )
        $workerStatus = if ($resultMatch.Success) {
            [string]$resultMatch.Groups[1].Value
        } elseif ($running.Process.ExitCode -eq 0) { 'SUCCESS' } else { 'FATAL_SYSTEMIC' }
        $workerCategory = if ($resultMatch.Success) {
            [string]$resultMatch.Groups[2].Value
        } else { 'TOOL_INTERNAL_ERROR' }
        if ($running.Process.ExitCode -eq 0 -and
                $workerStatus -in @('SUCCESS', 'WARNING')) {
            $completed++
            if ($workerStatus -eq 'WARNING' -or
                    $combined -match '(?m)^COURSE_RESTORE_WARNING ') {
                $completedWithWarnings++
                Write-Host (
                    "WORKER_WARNING worker=$($running.WorkerId) " +
                    "course_key=$($running.Course.course_key) " +
                    "completed_with_warnings=$completedWithWarnings"
                ) -ForegroundColor Yellow
            } else {
                Write-Host (
                    "WORKER_COMPLETED worker=$($running.WorkerId) " +
                    "course_key=$($running.Course.course_key) completed=$completed/$($courses.Count)"
                ) -ForegroundColor Green
            }
        } else {
            $failed++
            [void]$failureMessages.Add(
                "course_key=$($running.Course.course_key) status=$workerStatus " +
                "category=$workerCategory exit_code=$($running.Process.ExitCode)"
            )
            $disposition = Get-WorkerFailureDisposition $workerStatus $workerCategory
            if (-not $disposition.StopAssigning) {
                $waitingManual++
                Write-Host (
                    "WORKER_WAITING_MANUAL worker=$($running.WorkerId) " +
                    "course_key=$($running.Course.course_key) " +
                    "category=$workerCategory; continuando otros cursos"
                ) -ForegroundColor Yellow
            } else {
                # PRECONDITION_BUG global y TOOL_INTERNAL_ERROR son sistémicos.
                $fatalSystemic = $true
                $workerCategory = [string]$disposition.Category
                Write-Host (
                    "STOP_ASSIGNING status=FATAL_SYSTEMIC category=$workerCategory " +
                    "course_key=$($running.Course.course_key)"
                ) -ForegroundColor Red
            }
        }
        $availableWorkerIds.Enqueue([int]$running.WorkerId)
        $running.Process.Dispose()
        $active.RemoveAt($index)
    }

    if (([DateTimeOffset]::UtcNow - $lastHeartbeat).TotalSeconds -ge 15 -or
            ($active.Count -eq 0 -and $queue.Count -eq 0)) {
        Write-WorkerSnapshot -Path $workersReport -Active $active `
            -Completed $completed -CompletedWithWarnings $completedWithWarnings `
            -Failed $failed -Total $courses.Count `
            -Pending $queue.Count -Workers $workers `
            -WorkersRequested $workersRequested
        Write-Host (
            "WORKERS_HEARTBEAT completed=$completed/$($courses.Count) " +
            "failed=$failed active=$($active.Count) pending=$($queue.Count) workers=$workers"
        ) -ForegroundColor DarkGray
        $lastHeartbeat = [DateTimeOffset]::UtcNow
    }
    if ($active.Count -gt 0) { Start-Sleep -Milliseconds 500 }
}

if ($fatalSystemic) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw (
        "FATAL_SYSTEMIC: los workers activos terminaron y los checkpoints " +
        "válidos se conservaron. " + ($failureMessages -join " | ")
    )
}
if ($waitingManual -gt 0) {
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
    throw (
        "WAITING_MANUAL: $waitingManual curso(s) requieren intervención; " +
        "los demás cursos continuaron. " + ($failureMessages -join " | ")
    )
}

Write-Host "Sellando la aplicación completa del lote..." -ForegroundColor Cyan
$sealOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/phase6-seal-apply.php `
    "--phase4=/exports/phase4" `
    "--phase6=/exports/phase6" `
    "--configsha=$configHash" `
    "--targetid=$($targetSite.id)" `
    "--expectlab=$expectLab" 2>&1
$sealExit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath "/exports/phase6"
$sealOutput | Out-File -FilePath $report -Append -Encoding UTF8
$sealOutput | ForEach-Object { Write-Host $_ }
if ($sealExit -ne 0) {
    throw "No fue posible sellar la aplicación. Revise $report."
}

$summary = Get-Content `
    -LiteralPath (Join-Path $phase6Host "batch_apply_summary.json") `
    -Raw -Encoding UTF8 | ConvertFrom-Json
Write-Host ""
Write-Host "FASE 6 aplicada con extracción única y pool dinámico." -ForegroundColor Green
Write-Host "Cursos aplicados: $($summary.courses_applied)." -ForegroundColor Cyan
Write-Host "Cursos con warnings: $($summary.courses_with_warnings)." -ForegroundColor Yellow
Write-Host "Workers efectivos: $workers." -ForegroundColor Cyan
Write-Host "Los checkpoints permiten reanudar solo los pendientes." -ForegroundColor Green
