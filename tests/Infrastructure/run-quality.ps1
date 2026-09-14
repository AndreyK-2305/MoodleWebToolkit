param([string] $ProjectName = ('mt1g-' + [Guid]::NewGuid().ToString('N').Substring(0, 12)))

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($ProjectName -notmatch '^mt1g-[a-z0-9-]+$') {
    throw 'El proyecto de calidad debe usar un prefijo exclusivo mt1g-.'
}
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location -LiteralPath $root
$env:COMPOSE_FILE = Join-Path $root 'compose.quality.yaml'
$env:COMPOSE_PROJECT_NAME = $ProjectName

function New-QualitySecret {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
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
try {
    Invoke-QualityCompose config --quiet
    # Original development Compose is also a required gate.
    & docker compose -f compose.yaml config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'compose.yaml inválido.' }
    Invoke-QualityCompose build
    Invoke-QualityCompose --profile e2e build playwright
    # Populate shared storage once before Compose creates its other consumers.
    Invoke-QualityCompose create app
    Invoke-QualityCompose up -d --wait --wait-timeout 300
    $services = @(& docker compose ps --format json | ForEach-Object { $_ | ConvertFrom-Json })
    if ($services.Count -ne 10 -or @($services | Where-Object { $_.Health -ne 'healthy' }).Count -gt 0) {
        throw 'Los diez servicios deben estar saludables simultáneamente.'
    }
    $services | Select-Object Service, State, Health | ConvertTo-Json | Set-Content quality-results/health.json
    Write-Host 'Instalación limpia: diez servicios saludables; dependencias incorporadas en imágenes, sin montajes del código local.'
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
    $testArguments += @('app', 'php', 'artisan', 'test', '--display-warnings', '--cache-directory', '/tmp/phpunit-cache', '--log-junit', '/tmp/phpunit.xml')
    try {
        Invoke-QualityCompose -Arguments $testArguments
    } finally {
        Invoke-QualityCompose cp app:/tmp/phpunit.xml quality-results/phpunit.xml
    }
    Invoke-QualityCompose exec -T vite npm run test
    Invoke-QualityCompose exec -T vite npm run check
    Invoke-QualityCompose exec -T vite npm run lint
    Invoke-QualityCompose exec -T vite npm run types:check
    Invoke-QualityCompose exec -T vite npm run build
    & ./tests/Infrastructure/verify-baseline-integrity.ps1
    & ./tests/Infrastructure/verify-baseline-readonly.ps1
    # Additional local working-tree check; CI checks committed ranges separately.
    & git diff --check
    if ($LASTEXITCODE -ne 0) { throw 'git diff --check del working tree falló.' }
    Invoke-QualityCompose --profile e2e run --rm --no-deps playwright
    Write-Host 'Todas las puertas de calidad aprobaron.'
} catch {
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
    Remove-Item Env:QUALITY_APP_KEY, Env:QUALITY_DB_PASSWORD, Env:QUALITY_REVERB_SECRET -ErrorAction SilentlyContinue
}
