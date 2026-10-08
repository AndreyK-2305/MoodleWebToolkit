param(
    [Parameter(Mandatory)][string]$TargetService,
    [Parameter(Mandatory)][string]$ConfigHash,
    [Parameter(Mandatory)][string]$TargetId,
    [Parameter(Mandatory)][string]$TargetUrl,
    [Parameter(Mandatory)][string]$SourceId,
    [Parameter(Mandatory)][string]$CourseKey,
    [Parameter(Mandatory)][string]$ExpectLab
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"
. "$PSScriptRoot/Phase6SourceRouting.ps1"

function Invoke-CourseDocker([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& docker @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    $output | ForEach-Object { Write-Host $_ }
    return [pscustomobject]@{
        ExitCode = $exitCode
        Text = ($output -join [Environment]::NewLine)
    }
}

function Write-CourseWorkerState([string]$Status, [string]$Category, [string]$Message) {
    $safe = $CourseKey.ToLowerInvariant()
    $directory = Join-Path $ProjectRoot 'exports/phase6/course-worker-states'
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
    $path = Join-Path $directory "state-$safe.json"
    $temporary = "$path.partial-$PID"
    [ordered]@{
        schema_version = '1.0'
        course_key = $CourseKey
        status = $Status
        category = $Category
        message = $Message
        updated_at_utc = [DateTimeOffset]::UtcNow.ToString('o')
    } | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $temporary -Encoding UTF8
    Move-Item -LiteralPath $temporary -Destination $path -Force
}

try {
    $courseSourceMap = Get-Phase6CourseSourceMap -CoursePlanPath (
        Join-Path $ProjectRoot 'exports/phase6/course_plan.csv'
    )
    $sourceRouting = Resolve-Phase6CourseSource `
        -SourceMap $courseSourceMap `
        -CourseKey $CourseKey `
        -ReceivedSourceId $SourceId
    if ($sourceRouting.Corrected) {
        Write-Output (
            'WORKER_SOURCE_CORRECTED course_key=' + $sourceRouting.CourseKey +
            ' received=' + $sourceRouting.ReceivedSourceId +
            ' planned=' + $sourceRouting.SourceId
        )
    }
    $SourceId = [string]$sourceRouting.SourceId
} catch {
    Write-CourseWorkerState 'FATAL_SYSTEMIC' 'TOOL_INTERNAL_ERROR' $_.Exception.Message
    Write-Output "WORKER_RESULT status=FATAL_SYSTEMIC category=TOOL_INTERNAL_ERROR course_key=$CourseKey"
    exit 70
}

$common = @('compose', 'exec', '-T', '-u', 'www-data', $TargetService)
$prepare = Invoke-CourseDocker ($common + @(
    'php', '/opt/consolidator/phase6-prepare-package-course.php',
    '--phase4=/exports/phase4', '--phase6=/exports/phase6',
    "--package=/exports/packages/$SourceId", "--configsha=$ConfigHash",
    "--targetid=$TargetId", "--sourceid=$SourceId", "--coursekey=$CourseKey",
    "--expectlab=$ExpectLab"
))
if ($prepare.ExitCode -ne 0) {
    Write-CourseWorkerState 'WAITING_MANUAL' 'SOURCE_DATA_DEFECT' $prepare.Text
    Write-Output "WORKER_RESULT status=WAITING_MANUAL category=SOURCE_DATA_DEFECT course_key=$CourseKey"
    exit 30
}

$precheck = Invoke-CourseDocker ($common + @(
    'php', '/opt/consolidator/phase6-analyze-course.php',
    '--phase4=/exports/phase4', '--phase6=/exports/phase6',
    "--configsha=$ConfigHash", "--targetid=$TargetId", "--coursekey=$CourseKey",
    '--source-integrity=/exports/phase6/source-integrity-policies.csv',
    '--resolutions=/exports/phase6/degradation-resolutions.csv',
    "--expectlab=$ExpectLab"
))
if ($precheck.ExitCode -ne 0) {
    Write-CourseWorkerState 'WAITING_MANUAL' 'SOURCE_DATA_DEFECT' $precheck.Text
    if ($precheck.Text -notmatch 'WORKER_RESULT status=WAITING_MANUAL') {
        Write-Output "WORKER_RESULT status=WAITING_MANUAL category=SOURCE_DATA_DEFECT course_key=$CourseKey"
    }
    exit 30
}

$planHashMatch = [regex]::Match(
    $precheck.Text,
    '(?m)^COURSE_PRECHECK_OK\b.*\bplan_sha256=([a-fA-F0-9]{64})\b'
)
if (-not $planHashMatch.Success) {
    Write-CourseWorkerState `
        'FATAL_SYSTEMIC' `
        'TOOL_INTERNAL_ERROR' `
        'precheck no devolvió plan_sha256'
    Write-Output "WORKER_RESULT status=FATAL_SYSTEMIC category=TOOL_INTERNAL_ERROR course_key=$CourseKey"
    exit 70
}
$planHash = $planHashMatch.Groups[1].Value.ToLowerInvariant()
$apply = Invoke-CourseDocker ($common + @(
    'php', '/opt/consolidator/phase6-apply-course.php',
    '--phase4=/exports/phase4', '--phase6=/exports/phase6',
    "--configsha=$ConfigHash", "--targetid=$TargetId", "--targeturl=$TargetUrl",
    "--coursekey=$CourseKey", "--degradationplansha=$planHash",
    "--expectlab=$ExpectLab"
))
if ($apply.ExitCode -ne 0) {
    $category = if ($apply.Text -match 'stage=(initializing|load_job)') {
        'PRECONDITION_BUG'
    } elseif ($apply.Text -match 'stage=(single_extract|normalize_in_place)') {
        'SOURCE_DATA_DEFECT'
    } else {
        'MOODLE_RESTORE_INCOMPATIBILITY'
    }
    $status = if ($category -eq 'PRECONDITION_BUG') {
        'FATAL_SYSTEMIC'
    } else { 'WAITING_MANUAL' }
    Write-CourseWorkerState $status $category $apply.Text
    Write-Output "WORKER_RESULT status=$status category=$category course_key=$CourseKey"
    exit $(if ($status -eq 'FATAL_SYSTEMIC') { 71 } else { 31 })
}

$status = if ($apply.Text -match '(?m)^COURSE_RESTORE_WARNING ') {
    'WARNING'
} else { 'SUCCESS' }
Write-CourseWorkerState $status '' 'completed'
Write-Output "WORKER_RESULT status=$status category= course_key=$CourseKey"
exit 0
