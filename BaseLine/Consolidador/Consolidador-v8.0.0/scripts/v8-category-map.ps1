param(
    [ValidateSet('Prepare', 'Check', 'PilotChain', 'PilotCheck')]
    [string]$Mode = 'Prepare'
)

# 8.1.0: mapa de categorías destino. Prepare genera la propuesta (solo
# lectura) y valida config/category-map.json; PilotChain crea la cadena de
# categorías del piloto y ubica el piloto en ella. Sin mapa ni
# CONSOLIDATION_CATEGORY_MAP=required el asistente no invoca este script.

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"

$engine = Join-Path $ProjectRoot 'scripts/v8-category-map.php'
$mapDirectory = Join-Path $ProjectRoot 'exports/category-map'
$proposal = Join-Path $mapDirectory 'proposal.json'
$targetCategories = Join-Path $mapDirectory 'target_categories.json'
$validation = Join-Path $mapDirectory 'validation.json'
$pilotChain = Join-Path $mapDirectory 'pilot_chain.json'
$mapping = Join-Path $ProjectRoot 'config/category-map.json'
$pilotConfig = Join-Path $ProjectRoot 'config/phase5-pilot-package.json'
$batchConfig = Join-Path $ProjectRoot 'config/phase6-batch.json'

function File-Hash([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Read-Document([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    try { return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json }
    catch { return $null }
}

function Test-ValidMap {
    $document = Read-Document $validation
    return (
        $null -ne $document -and
        [string]$document.status -eq 'VALID' -and
        [string]$document.config_sha256 -eq (Get-ConfigurationHash) -and
        [string]$document.proposal_sha256 -eq (File-Hash $proposal) -and
        [string]$document.category_map_sha256 -eq (File-Hash $mapping) -and
        -not [bool]$document.destination_write_performed
    )
}

function Test-PilotPlaced {
    $chain = Read-Document $pilotChain
    $pilot = Read-Document $pilotConfig
    return (
        (Test-ValidMap) -and $null -ne $chain -and $null -ne $pilot -and
        [string]$chain.validation_sha256 -eq (File-Hash $validation) -and
        [string]$chain.config_sha256 -eq (Get-ConfigurationHash) -and
        [int]$chain.target_category_id -gt 0 -and
        [int]$pilot.target_category_id -eq [int]$chain.target_category_id -and
        [string]$pilot.category_map_sha256 -eq (File-Hash $mapping)
    )
}

if ($Mode -eq 'Check') {
    if (-not (Test-ValidMap)) { throw 'V8_CATEGORY_MAP_INVALID: el mapa de categorías no está validado.' }
    Write-Output (Read-Document $validation)
    return
}
if ($Mode -eq 'PilotCheck') {
    if (-not (Test-PilotPlaced)) { throw 'V8_CATEGORY_MAP_PILOT_NOT_PLACED' }
    Write-Output (Read-Document $pilotChain)
    return
}

$targetSite = Get-TargetSite
$targetService = [string]$targetSite.service
$configHash = Get-ConfigurationHash

if ($Mode -eq 'PilotChain') {
    if (-not (Test-ValidMap)) {
        throw 'V8_CATEGORY_MAP_INVALID: el mapa cambió después de validarse; no se crearán categorías.'
    }
    if (Test-PilotPlaced) {
        Write-Output (Read-Document $pilotChain)
        return
    }
    Register-DestinationWriteIntent -Phase 'category-map-pilot' -BoundHash (File-Hash $validation)
    Invoke-Compose -Arguments @('up', '-d', '--no-build', $targetService)
    Grant-ContainerExportWrite -Service $targetService -ContainerPath '/exports/category-map'
    $output = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/v8-category-chain.php `
        '--validation=/exports/category-map/validation.json' `
        "--configsha=$configHash" `
        '--output=/exports/category-map/pilot_chain.json' 2>&1
    $exit = $LASTEXITCODE
    Restore-AssistantExportOwnership -Service $targetService -ContainerPath '/exports/category-map'
    $output | ForEach-Object { Write-Host $_ }
    if ($exit -ne 0) {
        throw "V8_CATEGORY_MAP_PILOT_CHAIN_FAILED: $(($output | Select-Object -Last 1))"
    }
    $chain = Read-Document $pilotChain
    $pilot = Get-Content -LiteralPath $pilotConfig -Raw -Encoding UTF8 | ConvertFrom-Json
    $pilot.target_category_id = [int]$chain.target_category_id
    $pilot | Add-Member -NotePropertyName category_map_sha256 -NotePropertyValue (File-Hash $mapping) -Force
    $pilot | Add-Member -NotePropertyName category_map_node -NotePropertyValue ([string]$chain.pilot_node) -Force
    [System.IO.File]::WriteAllText($pilotConfig, ($pilot | ConvertTo-Json -Depth 10) + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false)))
    if (-not (Test-PilotPlaced)) { throw 'V8_CATEGORY_MAP_PILOT_NOT_PLACED' }
    Write-Output $chain
    return
}

# Prepare: propuesta y validación, sin escribir en el destino.
if (Test-Path -LiteralPath $DestinationWriteLockPath -PathType Leaf) {
    if (Test-ValidMap) {
        Write-Output (Read-Document $validation)
        return
    }
    throw 'V8_CATEGORY_MAP_REGENERATION_BLOCKED: el destino ya recibió escrituras.'
}
foreach ($required in @($engine, $pilotConfig, $batchConfig)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "V8_CATEGORY_MAP_INPUT_MISSING: $required"
    }
}
New-Item -ItemType Directory -Force -Path $mapDirectory | Out-Null
Invoke-Compose -Arguments @('up', '-d', '--no-build', $targetService)
Grant-ContainerExportWrite -Service $targetService -ContainerPath '/exports/category-map'
$output = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/v8-category-target.php `
    '--output=/exports/category-map/target_categories.json' 2>&1
$exit = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $targetService -ContainerPath '/exports/category-map'
if ($exit -ne 0) {
    throw "V8_CATEGORY_MAP_TARGET_FAILED: $(($output | Select-Object -Last 1))"
}

& php $engine '--mode=proposal' "--batch=$batchConfig" `
    "--inventories=$(Join-Path $ProjectRoot 'exports/phase6')" `
    "--target=$targetCategories" "--pilot=$pilotConfig" `
    "--configsha=$configHash" "--output=$proposal"
if ($LASTEXITCODE -ne 0) { throw 'V8_CATEGORY_MAP_PROPOSAL_FAILED' }

if (-not (Test-Path -LiteralPath $mapping -PathType Leaf)) {
    if (Test-Path -LiteralPath $validation -PathType Leaf) {
        Remove-Item -LiteralPath $validation -Force
    }
    throw (
        'V8_CATEGORY_MAP_REQUIRED: CATEGORY_MAP_PROPOSAL_READY; WAITING_USER_ACTION. ' +
        'Revise exports/category-map/proposal.json, escriba config/category-map.json ' +
        'y luego ejecute ./INICIAR-CONSOLIDACION.sh.'
    )
}
& php $engine '--mode=validate' "--proposal=$proposal" "--mapping=$mapping" "--output=$validation"
$exit = $LASTEXITCODE
if ($exit -eq 3) {
    $document = Read-Document $validation
    $messages = @($document.issues | Select-Object -First 5 | ForEach-Object { [string]$_.message })
    throw ('V8_CATEGORY_MAP_INVALID: ' + ($messages -join ' | '))
}
if ($exit -ne 0 -or -not (Test-ValidMap)) { throw 'V8_CATEGORY_MAP_VALIDATION_FAILED' }
Write-Output (Read-Document $validation)
