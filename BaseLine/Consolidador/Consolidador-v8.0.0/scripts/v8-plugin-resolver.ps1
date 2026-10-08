param(
    [ValidateSet('Prepare', 'Seal', 'Check')]
    [string]$Mode = 'Prepare'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"

$catalog = Join-Path $ProjectRoot 'config/plugin-compatibility-catalog.json'
$needs = Join-Path $ProjectRoot 'exports/phase2/plugin_compatibility_needs.json'
$lock = Join-Path $ProjectRoot 'exports/plugin-lock.json'
$summary = Join-Path $ProjectRoot 'exports/plugin-installation.json'
$technical = Join-Path $ProjectRoot 'exports/phase2/plugin_technical_validation.json'
$inventory = Join-Path $ProjectRoot 'exports/phase2/plugin_inventory_after.json'
$engine = Join-Path $ProjectRoot 'scripts/v8-plugin-catalog.php'

function File-Hash([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Read-Document([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
}

function Invoke-V8Catalog([string[]]$Arguments) {
    $output = @(& php $engine @Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw 'V8_PLUGIN_RESOLVER_FAILED'
    }
    if ($output.Count -gt 0) { Write-Host ($output -join [Environment]::NewLine) }
}

function Wait-MoodleTarget {
    for ($attempt = 1; $attempt -le 90; $attempt++) {
        $container = (& docker compose ps -q moodle-target 2>$null | Out-String).Trim()
        if ($LASTEXITCODE -eq 0 -and $container) {
            $health = (& docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' `
                $container 2>$null | Out-String).Trim()
            if ($health -eq 'healthy') { return }
            if ($health -in @('unhealthy', 'exited', 'dead')) {
                throw "V8_PLUGIN_STAGING_FAILED: moodle-target=$health"
            }
        }
        Start-Sleep -Seconds 2
    }
    throw 'V8_PLUGIN_STAGING_FAILED: timeout esperando moodle-target.'
}

foreach ($required in @($catalog, $engine)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "V8_PLUGIN_RESOLVER_MISSING: $required"
    }
}

if ($Mode -eq 'Check') {
    $document = Read-Document $lock
    if ($null -eq $document -or [string]$document.lock_status -ne 'SEALED' -or
            -not [bool]$document.plugin_catalog_checked -or
            -not [bool]$document.plugin_lock_valid -or
            [string]$document.catalog_sha256 -cne (File-Hash $catalog) -or
            [string]$document.compatibility_needs_sha256 -notmatch '^[a-f0-9]{64}$' -or
            [string]$document.technical_validation_sha256 -cne (File-Hash $technical) -or
            [string]$document.inventory_after_sha256 -cne (File-Hash $inventory)) {
        throw 'V8_PLUGIN_LOCK_INVALID'
    }
    Write-Output $document
    return
}

if ($Mode -eq 'Prepare') {
    if (-not (Test-Path -LiteralPath $needs -PathType Leaf)) {
        throw 'V8_PLUGIN_NEEDS_MISSING'
    }
    $existing = Read-Document $lock
    if ($null -ne $existing -and
            [string]$existing.catalog_sha256 -ceq (File-Hash $catalog) -and
            [string]$existing.compatibility_needs_sha256 -ceq (File-Hash $needs) -and
            [string]$existing.lock_status -in @(
                'ARTIFACTS_VERIFIED_PENDING_STAGING', 'DISCOVERY_REQUIRED', 'SEALED')) {
        Write-Output $existing
        return
    }
    Invoke-V8Catalog @(
        '--mode=resolve', "--catalog=$catalog", "--needs=$needs", "--lock=$lock")
    Invoke-V8Catalog @(
        '--mode=install', "--catalog=$catalog", "--lock=$lock",
        "--projectroot=$ProjectRoot", "--summary=$summary")
    $installation = Read-Document $summary
    if ([int]$installation.installed_count -gt 0) {
        & docker compose build moodle-target | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'V8_PLUGIN_IMAGE_BUILD_FAILED' }
        & docker compose up -d --force-recreate moodle-target | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'V8_PLUGIN_TARGET_RECREATE_FAILED' }
        Wait-MoodleTarget
    }
    Write-Output (Read-Document $lock)
    return
}

if (-not (Test-Path -LiteralPath $technical -PathType Leaf) -or
        -not (Test-Path -LiteralPath $inventory -PathType Leaf)) {
    throw 'V8_PLUGIN_STAGING_ARTIFACTS_MISSING'
}
$document = Read-Document $lock
if (@($document.unknown_plugins).Count -gt 0) {
    try {
        Invoke-V8Catalog @(
            '--mode=adopt', "--catalog=$catalog", "--lock=$lock", "--inventory=$inventory")
    } catch {
        Write-Host (
            'V8_PLUGIN_ADOPT_PENDING: quedan componentes que requieren ' +
            'resolución/pin explícito.'
        ) -ForegroundColor Yellow
        Write-Output (Read-Document $lock)
        return
    }
}
Invoke-V8Catalog @(
    '--mode=seal', "--catalog=$catalog", "--lock=$lock",
    "--technical=$technical", "--inventory=$inventory")
Write-Output (Read-Document $lock)
