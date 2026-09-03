$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$services = @('app', 'queue-worker', 'scheduler', 'reverb', 'vite', 'nginx')
$baselineDirectory = '/var/www/html/BaseLine'
$probe = "$baselineDirectory/.readonly-probe"

function Invoke-DockerCompose {
    param(
        [Parameter(Mandatory)]
        [string[]] $Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = @(& docker compose @Arguments 2>&1)
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousErrorActionPreference

    return [PSCustomObject]@{
        ExitCode = $exitCode
        Output = @($output | ForEach-Object { $_.ToString() })
    }
}

if (Test-Path -LiteralPath 'BaseLine/.readonly-probe') {
    throw 'Existe una sonda residual dentro de BaseLine antes de iniciar la prueba.'
}

foreach ($service in $services) {
    $accessCheck = Invoke-DockerCompose -Arguments @(
        'exec',
        '-T',
        $service,
        'sh',
        '-c',
        "command -v touch >/dev/null && test -d '$baselineDirectory' && test -r '$baselineDirectory' && printf baseline-access-ok"
    )

    if ($accessCheck.ExitCode -ne 0 -or ($accessCheck.Output -join '') -notmatch 'baseline-access-ok') {
        throw "No se puede ejecutar comandos o acceder a BaseLine desde el servicio [$service]."
    }

    $writeCheck = Invoke-DockerCompose -Arguments @(
        'exec',
        '-T',
        $service,
        'sh',
        '-c',
        "touch '$probe'"
    )

    if ($writeCheck.ExitCode -eq 0) {
        Invoke-DockerCompose -Arguments @(
            'exec',
            '-T',
            $service,
            'sh',
            '-c',
            "rm -f '$probe'"
        ) | Out-Null

        throw "BaseLine admite escritura desde el servicio [$service]."
    }

    if (($writeCheck.Output -join [Environment]::NewLine) -notmatch '(?i)read-only file system') {
        throw "La escritura falló por un motivo distinto a un sistema de archivos read-only en [$service]: $($writeCheck.Output -join ' ')"
    }

    $postCheck = Invoke-DockerCompose -Arguments @(
        'exec',
        '-T',
        $service,
        'sh',
        '-c',
        "test -d '$baselineDirectory' && printf baseline-access-ok"
    )

    if ($postCheck.ExitCode -ne 0 -or ($postCheck.Output -join '') -notmatch 'baseline-access-ok') {
        throw "El servicio [$service] dejó de responder durante la prueba."
    }

    Write-Host "BaseLine rechaza escritura por filesystem read-only desde [$service]."
}

if (Test-Path -LiteralPath 'BaseLine/.readonly-probe') {
    throw 'La prueba dejó un archivo dentro de BaseLine.'
}

Write-Host 'Todos los montajes de BaseLine están accesibles y son de solo lectura.'
