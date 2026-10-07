param(
    [ValidateSet('Approve', 'Reject', 'AutoClean')]
    [string]$Decision = 'Approve'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"

$phase6 = Join-Path $ProjectRoot 'exports\phase6'
$batchPath = Join-Path $phase6 'degradation_batch.json'
$manifestPath = Join-Path $phase6 'batch_manifest.json'
$approvalPath = Join-Path $phase6 'degradation_approval.json'
$batch = Get-Content -LiteralPath $batchPath -Raw -Encoding UTF8 | ConvertFrom-Json
if ([string]$batch.schema_version -ne '1.0' -or
        [string]$batch.phase -ne '6-batch-degradation-preflight' -or
        [int]$batch.totals.blocking_errors -ne 0 -or
        [string]$batch.batch_manifest_sha256 -cne
            (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()) {
    throw 'DEGRADATION_APPROVAL_INPUT_INVALID'
}
foreach ($plan in @($batch.plans)) {
    $path = Join-Path $phase6 ([string]$plan.plan_file)
    if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or
            [string]$plan.plan_sha256 -cne
                (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()) {
        throw "DEGRADATION_PLAN_SEAL_INVALID: $($plan.course_key)"
    }
}
if ($Decision -eq 'AutoClean' -and [string]$batch.status -ne 'clean') {
    throw 'DEGRADATION_AUTOCLEAN_REQUIRES_CLEAN_BATCH'
}
if ($Decision -eq 'Approve' -and
        [string]$batch.status -notin @('ready_for_approval', 'clean')) {
    throw 'DEGRADATION_APPROVAL_NOT_AVAILABLE'
}

if (Test-Path -LiteralPath $approvalPath -PathType Leaf) {
    $existing = Get-Content -LiteralPath $approvalPath -Raw -Encoding UTF8 |
        ConvertFrom-Json
    $batchHash = (Get-FileHash -LiteralPath $batchPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ([string]$existing.degradation_batch_sha256 -ceq $batchHash -and
            [string]$existing.status -eq 'approved' -and $Decision -ne 'Reject') {
        Write-Output $existing
        return
    }
    $history = Join-Path $phase6 'degradation-approval-history'
    New-Item -ItemType Directory -Path $history -Force | Out-Null
    $archive = Join-Path $history (
        'approval-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ') + '.json'
    )
    Move-Item -LiteralPath $approvalPath -Destination $archive
}

$document = [ordered]@{
    schema_version = '1.0'
    phase = '6-degradation-approval'
    generated_at_utc = [DateTime]::UtcNow.ToString('o')
    config_sha256 = [string]$batch.config_sha256
    batch_id = [string]$batch.batch_id
    batch_manifest_sha256 = [string]$batch.batch_manifest_sha256
    degradation_batch_sha256 = (
        Get-FileHash -LiteralPath $batchPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    plans_sha256 = [string]$batch.plans_sha256
    operator_decision = $(if ($Decision -eq 'Reject') {
        'rejected'
    } elseif ($Decision -eq 'AutoClean') {
        'not_required_clean'
    } else {
        'approved_known_degradations'
    })
    status = $(if ($Decision -eq 'Reject') { 'rejected' } else { 'approved' })
    courses_affected = [int]$batch.totals.courses_affected
    missing_payloads = [int]$batch.totals.missing_payloads
    structural_warnings = [int]$batch.totals.structural_warnings
    items_to_omit = [int]$batch.totals.items_to_omit
    destination_write_performed = $false
}
$temporary = "$approvalPath.tmp.$PID"
$document | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $temporary -Encoding UTF8
Move-Item -LiteralPath $temporary -Destination $approvalPath -Force
Write-Output $document
