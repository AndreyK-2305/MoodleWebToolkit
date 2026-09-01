. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$phase3Host = Join-Path $ProjectRoot "exports\phase3"
$reportsHost = Join-Path $ProjectRoot "reports"
$resolutionsHost = Join-Path $ProjectRoot "config\identity_resolutions.csv"
if (-not (Test-Path -LiteralPath $resolutionsHost -PathType Leaf)) {
    throw "Falta config\identity_resolutions.csv."
}
$identityPolicyHost = Join-Path $ProjectRoot "config\identity-policy.json"
if (-not (Test-Path -LiteralPath $identityPolicyHost -PathType Leaf)) {
    throw "Falta config\identity-policy.json."
}
$sourceNames = @(Get-SourceSiteNames)
foreach ($sourceId in $sourceNames) {
    $identityPath = Join-Path $phase3Host "identity-$sourceId.json"
    if (-not (Test-Path -LiteralPath $identityPath -PathType Leaf)) {
        throw "Falta el inventario de identidades del paquete '$sourceId'."
    }
}
New-Item -ItemType Directory -Force -Path $phase3Host, $reportsHost | Out-Null

$target = Get-TargetSite
$runner = [string]$target.service
Invoke-Compose -Arguments @("up", "-d", "--no-build", $runner)

& docker compose cp $resolutionsHost `
    "${runner}:/tmp/identity_resolutions.csv"
if ($LASTEXITCODE -ne 0) {
    throw "No fue posible copiar identity_resolutions.csv."
}
& docker compose cp $identityPolicyHost `
    "${runner}:/tmp/identity-policy.json"
if ($LASTEXITCODE -ne 0) {
    throw "No fue posible copiar identity-policy.json."
}
Grant-ContainerExportWrite -Service $runner -ContainerPath "/exports/phase3"

$configHash = Get-ConfigurationHash
$report = Join-Path $reportsHost "fase-3-conciliacion-paquetes.txt"
Write-Host "Conciliando identidades contenidas en los paquetes..." -ForegroundColor Cyan
$output = & docker compose exec -T -u www-data $runner `
    php /opt/consolidator/reconcile-identities.php `
    "--input=/exports/phase3" `
    "--output=/exports/phase3" `
    "--sources=$($sourceNames -join ',')" `
    "--targetid=$($target.id)" `
    "--targetname=$($target.Name)" `
    "--confighash=$configHash" `
    "--resolutions=/tmp/identity_resolutions.csv" `
    "--identitypolicy=/tmp/identity-policy.json" `
    "--expectlab=0" 2>&1
$exitCode = $LASTEXITCODE
Restore-AssistantExportOwnership -Service $runner -ContainerPath "/exports/phase3"
$output | Tee-Object -FilePath $report
if ($exitCode -ne 0) {
    throw "La conciliación falló. Revise $report."
}

$summaryPath = Join-Path $phase3Host "summary.json"
if (-not (Test-Path -LiteralPath $summaryPath -PathType Leaf)) {
    throw "La conciliación no produjo summary.json."
}
$summary = Get-Content -LiteralPath $summaryPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
Write-Host ""
Write-Host (
    "PACKAGE_IDENTITIES_OK sources=$($sourceNames.Count) " +
    "canonical=$($summary.canonical_identities) " +
    "conflicts=$($summary.identity_conflicts_unresolved) write=0"
) -ForegroundColor Green
Write-Host (
    "Revise exports\phase3\identity_conflicts.csv y " +
    "role_classification_exceptions.csv."
) -ForegroundColor Yellow
