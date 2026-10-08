param(
    [ValidateSet('Prepare', 'Apply')]
    [string]$Mode = 'Prepare'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"
$candidates = Join-Path $ProjectRoot 'exports/identity_candidates.csv'
$review = Join-Path $ProjectRoot 'exports/identity_candidate_review.csv'
$output = Join-Path $ProjectRoot 'config/fuzzy_identity_resolutions.csv'
$engine = Join-Path $ProjectRoot 'scripts/v8-identity-review.php'

if (-not (Test-Path -LiteralPath $candidates -PathType Leaf)) {
    throw 'V8_IDENTITY_CANDIDATES_MISSING'
}
if (Test-Path -LiteralPath $DestinationWriteLockPath -PathType Leaf) {
    throw 'V8_IDENTITY_REVIEW_POST_WRITE_BLOCKED: la conciliación no puede cambiar después de escribir en destino.'
}
if ($Mode -eq 'Prepare') {
    if (-not (Test-Path -LiteralPath $review -PathType Leaf)) {
        Copy-Item -LiteralPath $candidates -Destination $review
    }
    Write-Host 'Revise exports\identity_candidate_review.csv.' -ForegroundColor Yellow
    Write-Host 'Complete resolution=MERGE|KEEP_SEPARATE|IGNORE y los campos de auditoría.'
    Write-Host 'Para MERGE, canonical_target debe ser una cuenta origen source:user_id incluida en canonical_members_a/b.'
    Write-Host 'IDENTITY_REVIEW_PREPARED'
    Write-Host 'WAITING_USER_ACTION'
    Write-Host 'Edite exports/identity_candidate_review.csv.'
    Write-Host 'Luego ejecute ./INICIAR-CONSOLIDACION.sh o ./APLICAR-REVISION-IDENTIDADES.sh.'
    return
}
if (-not (Test-Path -LiteralPath $review -PathType Leaf)) {
    throw 'V8_IDENTITY_REVIEW_FILE_MISSING'
}
& php $engine "--candidates=$candidates" "--review=$review" "--output=$output"
if ($LASTEXITCODE -ne 0) { throw 'V8_IDENTITY_REVIEW_IMPORT_FAILED' }
$readiness = Join-Path $ProjectRoot 'exports/readiness.json'
if (Test-Path -LiteralPath $readiness -PathType Leaf) {
    Remove-Item -LiteralPath $readiness -Force
}
Write-Host 'Decisiones fuzzy importadas y validadas.' -ForegroundColor Green
Write-Host 'IDENTITY_REVIEW_IMPORTED'
Write-Host 'El asistente continuará automáticamente y recalculará conciliación y plan antes de READY_TO_RUN.'
