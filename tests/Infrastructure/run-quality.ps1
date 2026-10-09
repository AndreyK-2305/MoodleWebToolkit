param([string] $ProjectName = ('mt1g-' + [Guid]::NewGuid().ToString('N').Substring(0, 12)), [switch] $CollectorLab, [switch] $SkipBuild)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($ProjectName -notmatch '^mt1g-[a-z0-9-]+$') {
    throw 'El proyecto de calidad debe usar un prefijo exclusivo mt1g-.'
}
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location -LiteralPath $root
$env:COMPOSE_FILE = Join-Path $root 'compose.quality.yaml'
if ($CollectorLab) {
    $env:COMPOSE_FILE += [IO.Path]::PathSeparator + (Join-Path $root 'compose.collector-lab.yaml')
}
$env:COMPOSE_PROJECT_NAME = $ProjectName
$expectedServices = if ($CollectorLab) { 12 } else { 11 }
$reportPrefix = if ($CollectorLab) { 'collector-lab-' } else { '' }
$validationFile = if ($CollectorLab) { 'quality-results/iteration3-lab-validation.json' } else { 'quality-results/iteration2-validation.json' }

function New-QualitySecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return [Convert]::ToBase64String($bytes)
}
$env:QUALITY_APP_KEY = 'base64:' + (New-QualitySecret)
$env:QUALITY_DB_PASSWORD = New-QualitySecret
$env:QUALITY_REVERB_SECRET = New-QualitySecret

function Invoke-QualityCompose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)
    & docker compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Puerta fallida: docker compose $($Arguments -join ' ')" }
}

$existing = @(& docker ps -aq --filter "label=com.docker.compose.project=$ProjectName")
if ($LASTEXITCODE -ne 0) { throw 'No se pudo inspeccionar Docker; no se creará ni eliminará ningún recurso.' }
$volumes = @(& docker volume ls -q --filter "label=com.docker.compose.project=$ProjectName")
if ($LASTEXITCODE -ne 0) { throw 'No se pudieron inspeccionar los volúmenes Docker.' }
if ($existing.Count -gt 0 -or $volumes.Count -gt 0) {
    throw 'El prefijo ya tiene recursos. Use un proyecto nuevo; no se borrará un entorno preexistente.'
}
New-Item -ItemType Directory -Path quality-results -Force | Out-Null
& ./tests/Infrastructure/verify-baseline-tracking.ps1
& ./tests/Infrastructure/verify-baseline-integrity.ps1
$validationWritten = $false
try {
    Invoke-QualityCompose config --quiet
    # Original development Compose is also a required gate.
    & docker compose -f compose.yaml config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'compose.yaml inválido.' }
    if (-not $SkipBuild) {
        Invoke-QualityCompose build
        Invoke-QualityCompose --profile e2e build playwright
    }
    # Populate shared storage once before Compose creates its other consumers.
    Invoke-QualityCompose create app
    $startupWait = if ($CollectorLab) { 900 } else { 300 }
    Invoke-QualityCompose up -d --wait --wait-timeout $startupWait
    $services = @(& docker compose ps --format json | ForEach-Object { $_ | ConvertFrom-Json })
    if ($services.Count -ne $expectedServices -or @($services | Where-Object { $_.Health -ne 'healthy' }).Count -gt 0) {
        throw "Los $expectedServices servicios deben estar saludables simultáneamente."
    }
    $services | Select-Object Service, State, Health | ConvertTo-Json | Set-Content "quality-results/${reportPrefix}health.json"
    Write-Host "Instalación limpia: $expectedServices servicios saludables; dependencias incorporadas en imágenes."
    Invoke-QualityCompose exec -T --user www-data app php artisan migrate:fresh --force --no-interaction
    Invoke-QualityCompose exec -T app composer lint:check
    Invoke-QualityCompose exec -T app composer types:check
    # Apply PHPUnit's environment before Artisan boots: container server variables
    # otherwise retain E2E database values in the parent but not its child workers.
    [xml] $phpunit = Get-Content -LiteralPath phpunit.xml -Raw
    $testArguments = @('exec', '-T', '--user', 'www-data')
    foreach ($variable in $phpunit.phpunit.php.env) {
        $testArguments += @('-e', "$($variable.name)=$($variable.value)")
    }
    $testService = if ($CollectorLab) { 'tool-runner' } else { 'app' }
    $testArguments += @($testService, 'php', 'artisan', 'test')
    if ($CollectorLab) { $testArguments += @('tests/Laboratory') }
    $testArguments += @('--display-warnings', '--cache-directory', '/tmp/phpunit-cache', '--log-junit', '/tmp/phpunit.xml')
    try {
        Invoke-QualityCompose -Arguments $testArguments
    } finally {
        Invoke-QualityCompose cp "${testService}:/tmp/phpunit.xml" "quality-results/${reportPrefix}phpunit.xml"
    }
    if ($CollectorLab) {
        Invoke-QualityCompose exec -T --user www-data app php artisan tools:catalog:sync
        Invoke-QualityCompose exec -T --user www-data tool-runner php artisan collector:enable-laboratory
        $contracts = @(& docker compose exec -T --user www-data tool-runner php tests/Support/collector-contracts.php)
        if ($LASTEXITCODE -ne 0) { throw 'Las pruebas de la copia verificada del Recolector fallaron.' }
        $contracts | Set-Content quality-results/collector-contracts.json
    }
    Invoke-QualityCompose exec -T vite npm run test
    Invoke-QualityCompose exec -T vite npm run check
    Invoke-QualityCompose exec -T vite npm run lint
    Invoke-QualityCompose exec -T vite npm run types:check
    Invoke-QualityCompose exec -T vite npm run build
    & ./tests/Infrastructure/verify-baseline-integrity.ps1
    & ./tests/Infrastructure/verify-baseline-readonly.ps1
    # Additional local working-tree check; CI checks committed ranges separately.
    & git -c "safe.directory=$root" diff --check
    if ($LASTEXITCODE -ne 0) { throw 'git diff --check del working tree falló.' }
    if ($CollectorLab) {
        Invoke-QualityCompose --profile e2e run --rm --no-deps playwright npm run test:e2e -- tests/E2E/collector-lab.spec.ts
    } else {
        Invoke-QualityCompose --profile e2e run --rm --no-deps playwright
    }
    $services = @(& docker compose ps --format json | ForEach-Object { $_ | ConvertFrom-Json })
    if ($LASTEXITCODE -ne 0 -or $services.Count -ne $expectedServices -or @($services | Where-Object { $_.Health -ne 'healthy' }).Count -gt 0) {
        throw 'La comprobación de salud al finalizar falló.'
    }
    $services | Select-Object Service, State, Health | ConvertTo-Json | Set-Content "quality-results/${reportPrefix}final-health.json"
    # Persist exact checkout identity and aggregate results outside the checkout,
    # so documentation does not need a circular self-referencing commit hash.
    [xml] $phpReport = Get-Content -LiteralPath "quality-results/${reportPrefix}phpunit.xml" -Raw
    [xml] $browserReport = Get-Content -LiteralPath "quality-results/${reportPrefix}playwright.xml" -Raw
    function Measure-JUnitAttribute {
        param([xml] $Report, [string] $Attribute)
        return [int] (($Report.SelectNodes('/testsuites/testsuite') | ForEach-Object { [int] $_.GetAttribute($Attribute) } | Measure-Object -Sum).Sum)
    }
    $headSha = (& git -c "safe.directory=$root" rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo registrar el SHA exacto del checkout validado.' }
    $dirty = @(& git -c "safe.directory=$root" status --porcelain)
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo registrar el estado del checkout validado.' }
    $validation = [ordered] @{
        schema_version = if ($CollectorLab) { 'iteration3-lab-validation.v1' } else { 'iteration2-validation.v1' }
        head_sha = $headSha
        working_tree_dirty = $dirty.Count -gt 0
        ci_url = if ($env:GITHUB_RUN_ID) { "https://github.com/$env:GITHUB_REPOSITORY/actions/runs/$env:GITHUB_RUN_ID" } else { $null }
        quality_gates = 'PASSED'
        php_tests = Measure-JUnitAttribute $phpReport 'tests'
        php_assertions = Measure-JUnitAttribute $phpReport 'assertions'
        php_failures = Measure-JUnitAttribute $phpReport 'failures'
        php_errors = Measure-JUnitAttribute $phpReport 'errors'
        php_skipped = Measure-JUnitAttribute $phpReport 'skipped'
        playwright_tests = Measure-JUnitAttribute $browserReport 'tests'
        playwright_failures = Measure-JUnitAttribute $browserReport 'failures'
        healthy_services = $expectedServices
        clean_images_built_in_driver = -not $SkipBuild
        baseline_files = 423
        baseline_sha256 = 'd2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221'
        completed_at_utc = [DateTime]::UtcNow.ToString('o')
    }
    $validation | ConvertTo-Json | Set-Content -LiteralPath $validationFile
    $validationWritten = $true
    Write-Host 'Todas las puertas de calidad aprobaron.'
} catch {
    if ($CollectorLab) {
        # The fixture emits only closed stage/error codes; raw Moodle output is discarded.
        & docker compose logs --no-log-prefix lab-init moodle-lab-fixture
        & docker compose cp moodle-lab-fixture:/tmp/toolkit-synthetic-fixture/diagnostic.json quality-results/collector-fixture-diagnostic.json
    }
    # Retain healthcheck diagnostics only, never inspect environment or credentials.
    $ids = @(& docker compose ps -aq)
    foreach ($id in $ids) {
        & docker inspect --format '{{.Name}} {{json .State.Health}}' $id
    }
    throw
} finally {
    # This project was proven absent before creation; never remove development volumes.
    & docker compose --profile e2e down --volumes --remove-orphans
    if ($LASTEXITCODE -ne 0) { throw "No se pudo desmontar el entorno exclusivo $ProjectName." }
    $remaining = @(& docker ps -aq --filter "label=com.docker.compose.project=$ProjectName")
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo comprobar el teardown de contenedores.' }
    $remainingVolumes = @(& docker volume ls -q --filter "label=com.docker.compose.project=$ProjectName")
    if ($LASTEXITCODE -ne 0 -or $remaining.Count -gt 0 -or $remainingVolumes.Count -gt 0) { throw 'El teardown dejó recursos del proyecto de calidad.' }
    if ($validationWritten) {
        $validation = Get-Content -LiteralPath $validationFile -Raw | ConvertFrom-Json
        $validation | Add-Member -NotePropertyName teardown -NotePropertyValue 'PASSED' -Force
        $validation | ConvertTo-Json | Set-Content -LiteralPath $validationFile
    }
    Remove-Item Env:QUALITY_APP_KEY, Env:QUALITY_DB_PASSWORD, Env:QUALITY_REVERB_SECRET -ErrorAction SilentlyContinue
}
