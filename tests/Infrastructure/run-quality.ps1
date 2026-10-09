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
$reportHygieneApproved = $false
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
        $holdOutput = @(& docker compose exec -T --user www-data tool-runner php tests/Support/collector-resilience.php prepare)
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo iniciar el Recolector para la prueba de reinicios.' }
        $held = ($holdOutput -join [Environment]::NewLine) | ConvertFrom-Json
        if ($held.phase -ne 'HELD' -or $held.execution_uuid -notmatch '^[a-f0-9-]{36}$') { throw 'Identidad de la prueba de reinicios inválida.' }
        try {
            Invoke-QualityCompose restart redis
            Invoke-QualityCompose up -d --no-deps --wait --wait-timeout 60 redis
            Invoke-QualityCompose restart queue-worker
            Invoke-QualityCompose up -d --no-deps --wait --wait-timeout 60 queue-worker
            Invoke-QualityCompose restart reverb
            Invoke-QualityCompose up -d --no-deps --wait --wait-timeout 60 reverb
            $resilienceOutput = @(& docker compose exec -T --user www-data tool-runner php tests/Support/collector-resilience.php continue $held.execution_uuid)
            if ($LASTEXITCODE -ne 0) { throw 'El Recolector no acreditó recuperación después de los reinicios.' }
            $resilience = ($resilienceOutput -join [Environment]::NewLine) | ConvertFrom-Json
            if ($resilience.result -ne 'PASSED' -or $resilience.secret_hygiene -ne 'PASSED') { throw 'La prueba de recuperación no aprobó.' }
            $resilience | Add-Member -NotePropertyName restarted_services -NotePropertyValue @('redis', 'queue-worker', 'reverb')
            $resilience | ConvertTo-Json | Set-Content quality-results/collector-resilience.json
        } finally {
            Invoke-QualityCompose exec -T --user www-data tool-runner php tests/Support/collector-resilience.php release $held.execution_uuid
        }
    }
    Invoke-QualityCompose exec -T vite npm run test
    Invoke-QualityCompose exec -T vite npm run check
    Invoke-QualityCompose exec -T vite npm run lint
    Invoke-QualityCompose exec -T vite npm run types:check
    Invoke-QualityCompose exec -T vite npm run build
    & ./tests/Infrastructure/verify-baseline-integrity.ps1
    & ./tests/Infrastructure/verify-baseline-readonly.ps1 -IncludePlaywright:$CollectorLab
    # Additional local working-tree check; CI checks committed ranges separately.
    & git -c "safe.directory=$root" diff --check
    if ($LASTEXITCODE -ne 0) { throw 'git diff --check del working tree falló.' }
    if ($CollectorLab) {
        Invoke-QualityCompose --profile e2e run --rm --no-deps playwright npm run test:e2e -- tests/E2E/collector-lab.spec.ts
        $reportHygieneContractsOutput = @(& docker compose exec -T --user www-data tool-runner php tests/Support/collector-report-hygiene-contracts.php)
        if ($LASTEXITCODE -ne 0) { throw 'Los contratos negativos de inspección de reportes LAB fallaron.' }
        $reportHygieneContracts = ($reportHygieneContractsOutput -join [Environment]::NewLine) | ConvertFrom-Json
        $reportHygieneContractFields = @('schema_version', 'result', 'method', 'cases', 'private_staging_removed')
        if ($reportHygieneContracts -isnot [System.Management.Automation.PSCustomObject] -or
            @($reportHygieneContracts.PSObject.Properties.Name).Count -ne $reportHygieneContractFields.Count -or
            @($reportHygieneContracts.PSObject.Properties.Name | Where-Object { $_ -cnotin $reportHygieneContractFields }).Count -ne 0 -or
            $reportHygieneContracts.schema_version -cne 'collector-report-hygiene-contracts.v1' -or
            $reportHygieneContracts.result -cne 'PASSED' -or $reportHygieneContracts.method -cne 'REAL_LAB_SECRET_SCANNER_NEGATIVE_CONTRACTS_V1' -or
            ($reportHygieneContracts.cases -isnot [int] -and $reportHygieneContracts.cases -isnot [long]) -or
            $reportHygieneContracts.cases -ne 6 -or $reportHygieneContracts.private_staging_removed -isnot [bool] -or
            -not $reportHygieneContracts.private_staging_removed) {
            throw 'La evidencia de contratos negativos de reportes LAB está incompleta.'
        }
        $reportHygieneContracts | ConvertTo-Json | Set-Content quality-results/collector-report-hygiene-contracts.json
        $reportScanPrepared = $false
        $reportScanFailure = $null
        try {
            # The staging scope is fixed, private and newly created; no secret
            # value crosses the CLI boundary or reaches the host process.
            $prepareReportScan = 'umask(0077); $d="/tmp/collector-report-scan"; if (@lstat($d) !== false || !@mkdir($d, 0700)) { exit(1); }'
            & docker compose exec -T --user www-data tool-runner php -r $prepareReportScan
            if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la inspección privada de reportes LAB.' }
            $reportScanPrepared = $true
            foreach ($reportName in @('collector-lab-phpunit.xml', 'collector-lab-playwright.xml')) {
                Invoke-QualityCompose cp "quality-results/$reportName" "tool-runner:/tmp/collector-report-scan/$reportName"
            }
            $reportHygieneOutput = @(& docker compose exec -T --user www-data tool-runner php tests/Support/collector-resilience.php scan-reports)
            if ($LASTEXITCODE -ne 0) { throw 'La inspección del secreto real en reportes JUnit/Playwright falló.' }
            $reportHygiene = ($reportHygieneOutput -join [Environment]::NewLine) | ConvertFrom-Json
            $reportHygieneFields = @('schema_version', 'result', 'method', 'scope', 'reports_scanned', 'bytes_scanned', 'private_staging_removed')
            if ($reportHygiene -isnot [System.Management.Automation.PSCustomObject] -or
                @($reportHygiene.PSObject.Properties.Name).Count -ne $reportHygieneFields.Count -or
                @($reportHygiene.PSObject.Properties.Name | Where-Object { $_ -cnotin $reportHygieneFields }).Count -ne 0 -or
                $reportHygiene.schema_version -cne 'collector-report-hygiene.v1' -or $reportHygiene.result -cne 'PASSED' -or
                $reportHygiene.method -cne 'LAB_REAL_SECRET_RAW_XML_JSON_STREAMING_64K_WITH_OVERLAP' -or
                $reportHygiene.scope -cne 'LAB_PHPUNIT_AND_PLAYWRIGHT_JUNIT' -or
                ($reportHygiene.reports_scanned -isnot [int] -and $reportHygiene.reports_scanned -isnot [long]) -or
                ($reportHygiene.bytes_scanned -isnot [int] -and $reportHygiene.bytes_scanned -isnot [long]) -or
                $reportHygiene.reports_scanned -ne 2 -or $reportHygiene.bytes_scanned -lt 2 -or $reportHygiene.bytes_scanned -gt 33554432 -or
                $reportHygiene.private_staging_removed -isnot [bool] -or -not $reportHygiene.private_staging_removed) {
                throw 'La evidencia de higiene de reportes LAB está incompleta.'
            }
            $reportHygiene | ConvertTo-Json | Set-Content quality-results/collector-report-hygiene.json
            $reportHygieneApproved = $true
        } catch {
            $reportScanFailure = $_
            throw
        } finally {
            if ($reportScanPrepared) {
                # Idempotent cleanup is limited to our exact owned 0700 scope
                # and the two declared report basenames, including failed copies.
                $cleanupReportScan = 'clearstatcache(); $d="/tmp/collector-report-scan"; $s=@lstat($d); if ($s === false) { exit(0); } if (is_link($d) || realpath($d) !== $d || ($s["mode"] & 0170000) !== 0040000 || ($s["mode"] & 0777) !== 0700 || $s["uid"] !== posix_geteuid()) { exit(1); } foreach (["collector-lab-phpunit.xml", "collector-lab-playwright.xml"] as $n) { $p=$d."/".$n; if (@lstat($p) !== false && !@unlink($p)) { exit(1); } } exit(@rmdir($d) ? 0 : 1);'
                & docker compose exec -T --user www-data tool-runner php -r $cleanupReportScan
                if ($LASTEXITCODE -ne 0) {
                    if ($null -ne $reportScanFailure) {
                        Write-Warning 'También falló la retirada de la inspección privada de reportes LAB.'
                    } else {
                        throw 'No se pudo retirar la inspección privada de reportes LAB.'
                    }
                }
            }
        }
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
        playwright_errors = Measure-JUnitAttribute $browserReport 'errors'
        playwright_skipped = Measure-JUnitAttribute $browserReport 'skipped'
        playwright_retries = 0
        healthy_services = $expectedServices
        clean_images_built_in_driver = -not $SkipBuild
        baseline_files = 423
        baseline_readonly_services = if ($CollectorLab) { 8 } else { 7 }
        collector_restart_proof = if ($CollectorLab) { 'collector-resilience.json' } else { $null }
        collector_report_hygiene_proof = if ($CollectorLab -and $reportHygieneApproved) { 'collector-report-hygiene.json' } else { $null }
        collector_report_hygiene_contracts_proof = if ($CollectorLab -and $reportHygieneApproved) { 'collector-report-hygiene-contracts.json' } else { $null }
        baseline_sha256 = 'd2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221'
        completed_at_utc = [DateTime]::UtcNow.ToString('o')
    }
    $validation | ConvertTo-Json | Set-Content -LiteralPath $validationFile
    $validationWritten = $true
    Write-Host 'Todas las puertas de calidad aprobaron.'
} catch {
    $qualityGateFailure = $_
    if ($CollectorLab) {
        if (-not $reportHygieneApproved) {
            # PHPUnit, formatting or Playwright can fail before scanning. Only
            # an approved result from this invocation permits retaining the XML.
            try {
                [ordered] @{
                    schema_version = 'collector-report-hygiene.v1'
                    result = 'FAILED'
                    reason = 'REPORT_SCAN_NOT_APPROVED'
                    reports_expected = 2
                } | ConvertTo-Json | Set-Content quality-results/collector-report-hygiene.json
                foreach ($reportName in @('collector-lab-phpunit.xml', 'collector-lab-playwright.xml')) {
                    $hostReportPath = Join-Path $root "quality-results/$reportName"
                    if (Test-Path -LiteralPath $hostReportPath) {
                        if ((Get-Item -LiteralPath $hostReportPath -Force).PSIsContainer) { throw 'Un reporte LAB no aprobado no es un archivo eliminable.' }
                        Remove-Item -LiteralPath $hostReportPath -Force
                    }
                }
            } catch {
                # Preserve the original gate failure; CI also excludes these
                # two XML from every failure or cancellation artifact.
                Write-Warning 'No se pudo completar la retirada de reportes LAB no aprobados.'
            }
        }
        # The fixture emits only closed stage/error codes; raw Moodle output is discarded.
        & docker compose logs --no-log-prefix lab-init moodle-lab-fixture
        & docker compose cp moodle-lab-fixture:/tmp/toolkit-synthetic-fixture/diagnostic.json quality-results/collector-fixture-diagnostic.json
    }
    # Retain healthcheck diagnostics only, never inspect environment or credentials.
    $ids = @(& docker compose ps -aq)
    foreach ($id in $ids) {
        & docker inspect --format '{{.Name}} {{json .State.Health}}' $id
    }
    throw $qualityGateFailure
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
