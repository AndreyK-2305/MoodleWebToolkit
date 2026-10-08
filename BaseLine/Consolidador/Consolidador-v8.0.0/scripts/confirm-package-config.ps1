param(
    [switch]$Force
)

. "$PSScriptRoot/Common.ps1"

$composePath = Join-Path $ProjectRoot "compose.yaml"
if (-not (Test-Path $composePath -PathType Leaf)) {
    throw "No se encontró compose.yaml."
}
$composeServices = @{}
$insideServices = $false
foreach ($line in @(Get-Content -LiteralPath $composePath -Encoding UTF8)) {
    if ($line -match '^services:\s*$') {
        $insideServices = $true
        continue
    }
    if ($insideServices -and
            $line -match '^[a-zA-Z][a-zA-Z0-9_-]*:\s*$') {
        break
    }
    if ($insideServices -and
            $line -match '^  ([a-zA-Z0-9_.-]+):\s*$') {
        $composeServices[$Matches[1]] = $true
    }
}
$target = Get-TargetSite
if (-not $composeServices.ContainsKey([string]$target.service)) {
    throw (
        "El servicio destino '$($target.service)' no existe en compose.yaml."
    )
}

$rows = @()
foreach ($source in $MigrationConfig.Sources) {
    $packagePath = Join-Path $ProjectRoot `
        "exports\packages\$($source.id)\manifest.json"
    if (-not (Test-Path -LiteralPath $packagePath -PathType Leaf)) {
        throw "Falta el paquete importado de '$($source.id)'."
    }
    $rows += [pscustomobject]@{
        Tipo = "PAQUETE"
        Id = $source.id
        Nombre = $source.name
        Ejecucion = "sin instancia origen"
        URL = $source.url
    }
}
$rows += [pscustomobject]@{
    Tipo = "DESTINO"
    Id = $target.id
    Nombre = $target.name
    Ejecucion = $target.service
    URL = $target.url
}

Write-Host ""
Write-Host "Proyecto: $($MigrationConfig.ProjectName)" -ForegroundColor Cyan
Write-Host "Modo del motor: $($MigrationConfig.Mode)" -ForegroundColor Cyan
Write-Host "Entradas y destino que quedan vinculados al SHA-256:" -ForegroundColor Cyan
$rows | Format-Table -AutoSize
Write-Host (
    "Paquetes: $($MigrationConfig.Sources.Count). " +
    "Solo se iniciará el servicio destino."
) -ForegroundColor Yellow
Write-Host "Esta confirmación no inicia Docker ni modifica Moodle." -ForegroundColor DarkGray

if (-not $Force) {
    $answer = Read-Host "Escriba CONFIRMAR para aprobar esta configuración"
    if ($answer -cne "CONFIRMAR") {
        Write-Host "Configuración no confirmada." -ForegroundColor Yellow
        exit 0
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
New-Item -ItemType Directory -Force `
    -Path (Split-Path -Parent $ConfigurationConfirmationPath) | Out-Null
$record = [ordered]@{
    schema_version = "1.0"
    workflow = "source-packages"
    project_name = $MigrationConfig.ProjectName
    mode = $MigrationConfig.Mode
    config_sha256 = (Get-ConfigurationHash)
    confirmed_at = (Get-Date).ToString("o")
    source_count = $MigrationConfig.Sources.Count
    sources = @($MigrationConfig.Sources | ForEach-Object {
        [ordered]@{
            id = $_.id
            name = $_.name
            package_manifest = "exports/packages/$($_.id)/manifest.json"
            url = $_.url
        }
    })
    target = [ordered]@{
        id = $target.id
        name = $target.name
        service = $target.service
        url = $target.url
    }
}
$json = $record | ConvertTo-Json -Depth 8
[System.IO.File]::WriteAllText(
    $ConfigurationConfirmationPath,
    $json + [Environment]::NewLine,
    $utf8NoBom
)

Write-Host ""
Write-Host "PACKAGE_CONFIGURATION_CONFIRMED" -ForegroundColor Green

