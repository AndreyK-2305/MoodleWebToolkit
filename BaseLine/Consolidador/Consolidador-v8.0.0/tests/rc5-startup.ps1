$ErrorActionPreference = 'Stop'
function Assert-RC5([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "RC5_STARTUP_FAILED $Message" }
}

$root = Join-Path ([IO.Path]::GetTempPath()) `
    ('rc5-startup-' + [guid]::NewGuid().ToString('N'))
$oldLocation = (Get-Location).Path
$oldComposeProject = $env:COMPOSE_PROJECT_NAME
$oldEmail = $env:CONSOLIDATION_EMAIL_ENABLED
$env:CONSOLIDATION_EMAIL_ENABLED = '0'
try {
    New-Item -ItemType Directory -Force -Path "$root/scripts", `
        "$root/copias", "$root/reports" | Out-Null
    Copy-Item "$PSScriptRoot/../config" "$root/config" -Recurse
    foreach ($name in @('ConfigParser.ps1', 'ConfigAccess.ps1',
            'Common.ps1', 'Notifications.ps1',
            'consolidation-wizard.ps1')) {
        Copy-Item "$PSScriptRoot/../scripts/$name" "$root/scripts/$name"
    }
    @'
MOODLE_PUBLIC_URL=https://moodle.example.edu
MOODLE_INTERNAL_HEALTH_URL=http://127.0.0.1:8090
MOODLE_ADMIN_PASSWORD=ValidPassword123@
CONSOLIDATION_EMAIL_ENABLED=0
'@ | Set-Content "$root/.env" -Encoding utf8
    Assert-RC5 (-not (Test-Path "$root/config.yaml")) `
        'el fixture ya contiene config.yaml'

    # Importar ConfigAccess no importa configuración, escribe archivos ni
    # ejecuta Docker. Se verifica en un proyecto que no tiene config.yaml.
    $before = @((Get-ChildItem -LiteralPath $root -Recurse -File |
        ForEach-Object { $_.FullName }) | Sort-Object)
    $output = @(. "$root/scripts/ConfigAccess.ps1")
    $after = @((Get-ChildItem -LiteralPath $root -Recurse -File |
        ForEach-Object { $_.FullName }) | Sort-Object)
    Assert-RC5 ($output.Count -eq 0 -and
        ($before -join '|') -ceq ($after -join '|') -and
        -not (Get-Variable MigrationConfig -ErrorAction SilentlyContinue) -and
        -not (Get-Variable Sites -ErrorAction SilentlyContinue)) `
        'ConfigAccess tuvo efectos secundarios al cargarse'

    # Con un .env válido y config/*.json presentes, el wizard debe llegar
    # efectivamente a la acción de Fase 1 sin crear config.yaml manualmente.
    @'
Write-Host 'RC5_PHASE1_ENTERED'
throw 'RC5_PHASE1_STOP_TEST'
'@ | Set-Content "$root/scripts/import-source-packages.ps1" -Encoding utf8
    $stdout = "$root/startup.stdout"
    $stderr = "$root/startup.stderr"
    $pwsh = (Get-Command pwsh).Source
    $process = Start-Process -FilePath $pwsh -ArgumentList @('-NoLogo',
        '-NoProfile', '-File', "$root/scripts/consolidation-wizard.ps1",
        '-Automatic') -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr -PassThru
    if (-not $process.WaitForExit(15000)) {
        $process.Kill()
        throw 'RC5_STARTUP_FAILED wizard quedó bloqueado antes de Fase 1'
    }
    $result = (Get-Content -LiteralPath $stdout -Raw) +
        (Get-Content -LiteralPath $stderr -Raw)
    $state = Get-Content -LiteralPath "$root/reports/assistant-state.json" `
        -Raw | ConvertFrom-Json
    Assert-RC5 ($process.ExitCode -eq 20 -and
        $state.stage -eq '01-importar-paquetes' -and
        $state.status -eq 'blocked' -and
        $result -match 'RC5_PHASE1_ENTERED' -and
        $result -match 'RC5_PHASE1_STOP_TEST' -and
        $result -notmatch 'No se encontró config.yaml' -and
        -not (Test-Path "$root/config.yaml")) `
        "el wizard no llegó a Fase 1 sin config.yaml: $result"

    # Get-TargetSite interpreta config.yaml al invocarse explícitamente.
    @'
version: 1
project_name: rc5-test
mode: production
sources:
  - id: origen
    name: Origen
    service: origen
    url: https://origen.example.edu
target:
  id: target
  name: Destino
  service: moodle-target
  url: https://moodle.example.edu
'@ | Set-Content "$root/config.yaml" -Encoding utf8
    $target = Get-TargetSite
    Assert-RC5 ($target.id -eq 'target' -and
        $target.url -eq 'https://moodle.example.edu' -and
        $target.service -eq 'moodle-target') `
        'ConfigAccess no interpretó target correctamente bajo demanda'

    # Common continúa materializando inmediatamente el mapa esperado por
    # las otras fases. Su Get-TargetSite conserva el mismo resultado.
    & {
        . "$root/scripts/Common.ps1"
        Assert-RC5 ($null -ne $MigrationConfig -and
            $MigrationConfig.Target.id -eq 'target' -and
            $Sites['origen'].id -eq 'origen' -and
            $Sites['target'].service -eq 'moodle-target' -and
            (Get-TargetSite).url -eq 'https://moodle.example.edu' -and
            @(Get-SourceSiteNames).Count -eq 1 -and
            [string](Get-AllConfiguredSiteNames) -match 'origen' -and
            [string](Get-AllConfiguredSiteNames) -match 'target' -and
            @(Get-ConfiguredServices).Count -eq 2) `
            'Common perdió su contrato eager o el parser compartido'
    }
    Write-Output 'RC5_STARTUP_OK config_access_pure=1 phase1_without_config=1 target_on_demand=1 common=1'
} finally {
    Set-Location $oldLocation
    $env:CONSOLIDATION_EMAIL_ENABLED = $oldEmail
    $env:COMPOSE_PROJECT_NAME = $oldComposeProject
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
