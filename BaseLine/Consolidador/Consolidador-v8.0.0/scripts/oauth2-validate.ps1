param(
    [switch]$Quiet,
    [switch]$LiveCheck,
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')]
    [string]$ExportName = ''
)

. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker Engine no está iniciado o no responde."
}

$oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
    throw "Falta config\oauth2.json."
}
try {
    $oauthConfig = Get-Content -LiteralPath $oauthConfigPath -Raw -Encoding UTF8 |
        ConvertFrom-Json
} catch {
    throw "config\oauth2.json no contiene JSON válido."
}
if ([string]$oauthConfig.schema_version -ne "1.0" -or
        -not [bool]$oauthConfig.required -or
        [string]$oauthConfig.service_type -notmatch '^[a-z0-9_]+$' -or
        [string]::IsNullOrWhiteSpace(
            [string]$oauthConfig.expected_subject_issuer
        )) {
    throw "config\oauth2.json no conserva la política OAuth2 requerida."
}
$issuerSelector = [string]$oauthConfig.issuer_id
if ($issuerSelector -ne "auto") {
    $issuerNumber = 0
    if (-not [int]::TryParse($issuerSelector, [ref]$issuerNumber) -or
            $issuerNumber -lt 1) {
        throw "config\oauth2.json: issuer_id debe ser auto o un entero positivo."
    }
}

$target = Get-TargetSite
$targetService = [string]$target.service
$configHash = Get-ConfigurationHash
$oauthConfigHash = (
    Get-FileHash -LiteralPath $oauthConfigPath -Algorithm SHA256
).Hash.ToLowerInvariant()
$oauthExportName = if (-not [string]::IsNullOrWhiteSpace($ExportName)) {
    $ExportName
} elseif ($LiveCheck) {
    "oauth2-live"
} else {
    "oauth2"
}
if (-not $LiveCheck -and $oauthExportName -ne 'oauth2') {
    throw 'ExportName personalizado solo es válido con -LiveCheck.'
}
$oauthHost = Join-Path $ProjectRoot "exports\$oauthExportName"
$reportsHost = Join-Path $ProjectRoot "reports"
New-Item -ItemType Directory -Force -Path $oauthHost, $reportsHost | Out-Null

$callbackUrl = ([string]$target.url).TrimEnd("/") +
    "/admin/oauth2callback.php"
$guidePath = Join-Path $reportsHost "oauth2-configuracion-manual.txt"
if (-not $LiveCheck) {
    $guideLines = @(
        "CONFIGURACIÓN MANUAL DE GOOGLE OAUTH2",
        "",
        "Esta fase no solicita ni guarda Client ID o Client secret.",
        "",
        "1. En Google Cloud registre exactamente esta URI de redirección:",
        "   $callbackUrl",
        "",
        "2. En Moodle abra:",
        "   Administración del sitio > Servidor > Servicios OAuth 2",
        "",
        "3. Cree o edite el servicio Google institucional, introduzca las",
        "   credenciales y déjelo habilitado para la página de acceso.",
        "",
        "4. Habilite OAuth 2 en:",
        "   Administración del sitio > Plugins > Autenticación >",
        "   Gestionar autenticación",
        "",
        "5. Regrese al asistente y use reintentar/continuar. Si la ejecución",
        "   estaba en segundo plano, ejecute de nuevo",
        "   ./INICIAR-SEGUNDO-PLANO.sh.",
        "",
        "Si existen varios servicios Google, escriba el ID correcto en",
        "config/oauth2.json, campo issuer_id. No coloque secretos allí."
    )
    [System.IO.File]::WriteAllLines(
        $guidePath,
        $guideLines,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

if (-not $LiveCheck) {
    # OAuth es anterior a la intervención: construir el destino por primera vez.
    Invoke-Compose -Arguments @('build', $targetService)
}
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Grant-ContainerExportWrite `
    -Service $targetService `
    -ContainerPath "/exports/$oauthExportName"

$reportName = if ($LiveCheck -and -not [string]::IsNullOrWhiteSpace($ExportName)) {
    "oauth2-validacion-$oauthExportName.txt"
} elseif ($LiveCheck) {
    "oauth2-validacion-publicacion.txt"
} else {
    "oauth2-validacion.txt"
}
$reportPath = Join-Path $reportsHost $reportName
$previousErrorAction = $ErrorActionPreference
$ErrorActionPreference = "Continue"
try {
    $output = & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/oauth2-validate.php `
        "--output=/exports/$oauthExportName" `
        "--configsha=$configHash" `
        "--oauthconfigsha=$oauthConfigHash" `
        "--targetid=$($target.id)" `
        "--issuerid=$issuerSelector" `
        "--servicetype=$($oauthConfig.service_type)" `
        "--expectedbaseurl=$($oauthConfig.expected_subject_issuer)" `
        "--expectedwwwroot=$($target.url)" 2>&1
    $exitCode = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorAction
}
Restore-AssistantExportOwnership `
    -Service $targetService `
    -ContainerPath "/exports/$oauthExportName"
$output | Tee-Object -FilePath $reportPath
if ($exitCode -ne 0) {
    $reviewMessage = if ($LiveCheck) {
        "Revise $reportPath."
    } else {
        "Revise $guidePath y $reportPath."
    }
    throw "La configuración OAuth2 aún requiere intervención manual. $reviewMessage"
}

$summaryPath = Join-Path $oauthHost "validation.json"
if (-not (Test-Path -LiteralPath $summaryPath -PathType Leaf)) {
    throw "La validación OAuth2 terminó sin generar validation.json."
}
$summary = Get-Content -LiteralPath $summaryPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
if ([string]$summary.config_sha256 -ne $configHash -or
        [string]$summary.oauth_config_sha256 -ne $oauthConfigHash -or
        [string]$summary.status -ne "ready" -or
        [string]$summary.validation -ne "passed" -or
        [int]$summary.issuer_id -lt 1 -or
        [string]$summary.expected_public_url -cne [string]$target.url -or
        [string]$summary.public_url_validation -ne "passed" -or
        -not [bool]$summary.reverseproxy -or
        -not [bool]$summary.sslproxy -or
        [string]$summary.callback_url -cne $callbackUrl -or
        -not [bool]$summary.auth_plugin_enabled) {
    throw "La validación OAuth2 no quedó aprobada para esta ejecución."
}
if (-not $Quiet) {
    Write-Host "OAuth2 listo para la consolidación." -ForegroundColor Green
    Write-Host "Issuer ID: $($summary.issuer_id)." -ForegroundColor Cyan
    Write-Host "Callback: $($summary.callback_url)." -ForegroundColor Cyan
}
