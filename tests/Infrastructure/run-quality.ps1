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
$volumes = @(& docker volume ls -q --filter "label=com.docker.compose.project=$ProjectName")
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
    Invoke-QualityCompose up -d --wait --wait-timeout 300
    $services = @(& docker compose ps --format json | ForEach-Object { $_ | ConvertFrom-Json })
    if ($services.Count -ne 10 -or @($services | Where-Object { $_.Health -ne 'healthy' }).Count -gt 0) {
        throw 'Los diez servicios deben estar saludables simultáneamente.'
    }
    $services | Select-Object Service, State, Health | ConvertTo-Json | Set-Content quality-results/health.json
    Write-Host 'Instalación limpia: diez servicios saludables; dependencias incorporadas en imágenes, sin montajes del código local.'
    Invoke-QualityCompose exec -T app php artisan migrate:fresh --force --no-interaction
    Invoke-QualityCompose exec -T app composer lint:check
    Invoke-QualityCompose exec -T app composer types:check
    Invoke-QualityCompose exec -T app php artisan test --log-junit /tmp/phpunit.xml
    Invoke-QualityCompose cp app:/tmp/phpunit.xml quality-results/phpunit.xml
    Invoke-QualityCompose exec -T vite npm run test
    Invoke-QualityCompose exec -T vite npm run check
    Invoke-QualityCompose exec -T vite npm run lint
    Invoke-QualityCompose exec -T vite npm run types:check
    Invoke-QualityCompose exec -T vite npm run build
    & ./tests/Infrastructure/verify-baseline-integrity.ps1
    & ./tests/Infrastructure/verify-baseline-readonly.ps1
    & git diff --check
    if ($LASTEXITCODE -ne 0) { throw 'git diff --check falló.' }
    Invoke-QualityCompose --profile e2e run --rm --no-deps playwright
    Write-Host 'Todas las puertas de calidad aprobaron.'
} finally {
    # This project was proven absent before creation; never remove development volumes.
    & docker compose down --volumes --remove-orphans
    if ($LASTEXITCODE -ne 0) { throw "No se pudo desmontar el entorno exclusivo $ProjectName." }
    Remove-Item Env:QUALITY_APP_KEY, Env:QUALITY_DB_PASSWORD, Env:QUALITY_REVERB_SECRET -ErrorAction SilentlyContinue
}
