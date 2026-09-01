$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$services = @('app', 'queue-worker', 'reverb', 'vite', 'nginx')
$probe = '/var/www/html/BaseLine/.readonly-probe'

foreach ($service in $services) {
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & docker compose exec -T $service sh -c "touch '$probe'" 2>$null
    $touchExitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousErrorActionPreference

    if ($touchExitCode -eq 0) {
        & docker compose exec -T $service sh -c "rm -f '$probe'" 2>$null
        throw "BaseLine admite escritura desde el servicio [$service]."
    }

    Write-Host "BaseLine rechaza escritura desde [$service]."
}

if (Test-Path -LiteralPath 'BaseLine/.readonly-probe') {
    throw 'La prueba dejó un archivo dentro de BaseLine.'
}

Write-Host 'Todos los montajes de BaseLine son de solo lectura.'
