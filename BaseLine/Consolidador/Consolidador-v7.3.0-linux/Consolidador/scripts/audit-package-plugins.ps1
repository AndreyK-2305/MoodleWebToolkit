. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$phase2Host = Join-Path $ProjectRoot "exports\phase2"
$reportsHost = Join-Path $ProjectRoot "reports"
New-Item -ItemType Directory -Force -Path $phase2Host, $reportsHost | Out-Null

$target = Get-TargetSite
$targetService = [string]$target.service
Write-Host (
    "Verificando la imagen y la configuración web del Moodle destino..."
) -ForegroundColor DarkGray
# Construir aquí permite que Docker reutilice sus capas, pero evita iniciar por
# accidente una imagen antigua cuyo entrypoint exponga una raíz web incorrecta.
Invoke-Compose -Arguments @("build", $targetService)
Invoke-Compose -Arguments @(
    "up", "-d", "--no-build", "--force-recreate", $targetService
)

# Moodle 5.1+ debe exponer /var/www/html/public como DocumentRoot. No se añade
# "/public" a la URL visible. Esta reparación es idempotente y también cubre
# contenedores creados con una imagen anterior que permanezca en Docker.
$publicRootReady = $false
$publicRootDeadline = (Get-Date).AddMinutes(2)
do {
    & docker compose exec -T $targetService sh -lc `
        "test -f /var/www/html/public/login/index.php" 2>$null
    $publicRootReady = $LASTEXITCODE -eq 0
    if (-not $publicRootReady -and (Get-Date) -lt $publicRootDeadline) {
        Start-Sleep -Seconds 4
    }
} while (-not $publicRootReady -and (Get-Date) -lt $publicRootDeadline)
if (-not $publicRootReady) {
    throw (
        "El contenedor destino no contiene " +
        "/var/www/html/public/login/index.php. La imagen o el volumen de " +
        "código no corresponden a Moodle 5.2. Revise " +
        "docker compose logs --tail=100 $targetService."
    )
}

$webRootCommand = (
    "set -eu; " +
    "sed -ri " +
    "'s#^[[:space:]]*DocumentRoot[[:space:]].*" +
    "#DocumentRoot /var/www/html/public#' " +
    "/etc/apache2/sites-available/000-default.conf; " +
    "grep -Eq " +
    "'^[[:space:]]*DocumentRoot[[:space:]]+" +
    "/var/www/html/public[[:space:]]*$' " +
    "/etc/apache2/sites-available/000-default.conf; " +
    "apache2ctl -k graceful >/dev/null 2>&1 || true; " +
    "printf '%s\n' DESTINATION_WEBROOT_OK"
)
$webRootOutput = & docker compose exec -T $targetService `
    sh -lc $webRootCommand 2>&1
$webRootExit = $LASTEXITCODE
$webRootOutput | ForEach-Object { Write-Host $_ }
if ($webRootExit -ne 0 -or
        @($webRootOutput) -notcontains "DESTINATION_WEBROOT_OK") {
    throw (
        "No fue posible configurar /var/www/html/public como raíz web " +
        "del Moodle destino."
    )
}

$ready = $false
$lastReadinessResult = "sin respuesta del CLI"
$deadline = (Get-Date).AddMinutes(12)
do {
    $readinessOutput = & docker compose exec -T -u www-data `
        $targetService php -r `
        'define("CLI_SCRIPT", true); require "/var/www/html/config.php"; echo "DESTINATION_READY\n";' `
        2>&1
    $readinessExit = $LASTEXITCODE
    $ready = $readinessExit -eq 0 -and
        @($readinessOutput) -contains "DESTINATION_READY"
    $lastReadinessResult = (
        @($readinessOutput | Select-Object -Last 1) -join ""
    )
    if (-not $ready -and (Get-Date) -lt $deadline) {
        Write-Host (
            "Esperando que el Moodle destino termine de iniciar..."
        ) -ForegroundColor DarkGray
        Start-Sleep -Seconds 8
    }
} while (-not $ready -and (Get-Date) -lt $deadline)
if (-not $ready) {
    throw (
        "El Moodle destino no quedó disponible para su CLI. " +
        "Último resultado: $lastReadinessResult. " +
        "Revise docker compose logs --tail=100 $targetService."
    )
}

& docker compose exec -T -u www-data $targetService `
    test -r /opt/consolidator/target-plugins.php
if ($LASTEXITCODE -ne 0) {
    throw (
        "El montaje /opt/consolidator existe, pero www-data no puede leer " +
        "target-plugins.php. Ejecute chmod 0755 scripts y chmod 0644 scripts/*.php."
    )
}

Invoke-Compose -Arguments @(
    "exec", "-T", $targetService, "sh", "-lc",
    "mkdir -p /exports/phase2 && chown -R www-data:www-data /exports/phase2"
)
$report = Join-Path $reportsHost "fase-2-compatibilidad-plugins.txt"
$targetOutput = & docker compose exec -T -u www-data $targetService `
    php /opt/consolidator/target-plugins.php `
    "--output=/exports/phase2/target-plugins.json" `
    "--targetid=$($target.id)" 2>&1
$targetExit = $LASTEXITCODE
$targetOutput | Tee-Object -FilePath $report
if ($targetExit -ne 0) {
    throw "No fue posible inventariar los plugins del destino. Revise $report."
}

# target-plugins.php debe escribir como www-data, pero los informes de
# compatibilidad que siguen los genera el runtime con el UID/GID del operador.
# Devuelva la propiedad antes de que PowerShell cree esos archivos en el host.
$assistantUid = [string]$env:ASSISTANT_UID
$assistantGid = [string]$env:ASSISTANT_GID
if ($assistantUid -notmatch '^\d+$' -or $assistantGid -notmatch '^\d+$') {
    throw "No fue posible determinar el UID/GID del operador para fase 2."
}
$phase2OwnershipCommand = (
    "chown -R ${assistantUid}:${assistantGid} /exports/phase2 && " +
    "chmod -R u=rwX,go= /exports/phase2"
)
Invoke-Compose -Arguments @(
    "exec", "-T", $targetService, "sh", "-lc",
    $phase2OwnershipCommand
)

$targetPath = Join-Path $phase2Host "target-plugins.json"
$targetInventory = Get-Content -LiteralPath $targetPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
if ([string]$targetInventory.schema_version -ne "1.0" -or
        [string]$targetInventory.target_id -ne [string]$target.id -or
        [bool]$targetInventory.write_performed) {
    throw "El inventario de plugins del destino no conserva su contrato."
}

$targetPlugins = @{}
foreach ($plugin in @($targetInventory.plugins)) {
    $component = [string]$plugin.component
    if ([string]::IsNullOrWhiteSpace($component) -or
            $targetPlugins.ContainsKey($component)) {
        throw "El destino contiene un componente de plugin inválido o repetido."
    }
    $targetPlugins[$component] = $plugin
}

$rows = New-Object System.Collections.Generic.List[object]
foreach ($source in $MigrationConfig.Sources) {
    $packageRoot = Join-Path $ProjectRoot "exports\packages\$($source.id)"
    $manifestPath = Join-Path $packageRoot "manifest.json"
    $pluginsPath = Join-Path $packageRoot "plugins.json"
    foreach ($requiredPath in @($manifestPath, $pluginsPath)) {
        if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
            throw "Falta el artefacto importado $requiredPath."
        }
    }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 |
        ConvertFrom-Json
    $sourcePlugins = Get-Content -LiteralPath $pluginsPath -Raw -Encoding UTF8 |
        ConvertFrom-Json
    $actualPluginsHash = (
        Get-FileHash -LiteralPath $pluginsPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    if ([string]$manifest.source_id -ne [string]$source.id -or
            [string]$sourcePlugins.source_id -ne [string]$source.id -or
            ([string]$manifest.plugins_sha256).ToLowerInvariant() -ne
                $actualPluginsHash -or
            [bool]$sourcePlugins.write_performed) {
        throw "El inventario de plugins de '$($source.id)' perdió integridad."
    }

    $sourceCoreVersion = [int64]$sourcePlugins.moodle_version
    $targetCoreVersion = [int64]$targetInventory.moodle_version
    if ($sourceCoreVersion -gt $targetCoreVersion) {
        [void]$rows.Add([pscustomobject][ordered]@{
            source_id = [string]$source.id
            component = "core_moodle"
            plugin_type = "core"
            plugin_origin = "standard"
            used_activity = 1
            source_version = [string]$sourceCoreVersion
            target_version = [string]$targetCoreVersion
            severity = "critical"
            status = "source_moodle_newer"
            blocking = 1
            message = (
                "La versión Moodle del origen es posterior a la del destino."
            )
        })
    }

    $usedComponents = @{}
    foreach ($moduleName in @($sourcePlugins.used_activity_modules)) {
        $usedComponents["mod_$([string]$moduleName)"] = $true
    }

    foreach ($sourcePlugin in @($sourcePlugins.plugins)) {
        $component = [string]$sourcePlugin.component
        $sourceKind = [string]$sourcePlugin.source
        $usedByCourse = $usedComponents.ContainsKey($component)
        $targetPlugin = if ($targetPlugins.ContainsKey($component)) {
            $targetPlugins[$component]
        } else {
            $null
        }

        $severity = "ok"
        $status = "compatible"
        $message = "El componente está disponible en el destino."
        $blocking = $false
        if ($null -eq $targetPlugin) {
            if ($usedByCourse) {
                $severity = "critical"
                $status = "missing_used_activity"
                $message = (
                    "El módulo se usa en cursos del paquete y falta en el destino."
                )
                $blocking = $true
            } elseif ($sourceKind -eq "additional") {
                $severity = "critical"
                $status = "missing_additional_plugin"
                $message = (
                    "El plugin adicional falta; instálelo o documente una " +
                    "decisión institucional antes de restaurar."
                )
                $blocking = $true
            } else {
                $severity = "warning"
                $status = "standard_component_not_present"
                $message = (
                    "El componente estándar no aparece en esta versión del " +
                    "destino y no figura como actividad utilizada."
                )
            }
        } else {
            $sourceVersion = [int64]$sourcePlugin.version_disk
            $targetVersion = [int64]$targetPlugin.version_disk
            if ($sourceKind -eq "additional" -and
                    $sourceVersion -gt 0 -and
                    $targetVersion -gt 0 -and
                    $targetVersion -lt $sourceVersion) {
                $severity = "critical"
                $status = "target_plugin_older"
                $message = (
                    "La versión del plugin en el destino es anterior a la " +
                    "registrada en el origen."
                )
                $blocking = $true
            }
        }

        [void]$rows.Add([pscustomobject][ordered]@{
            source_id = [string]$source.id
            component = $component
            plugin_type = [string]$sourcePlugin.type
            plugin_origin = $sourceKind
            used_activity = if ($usedByCourse) { 1 } else { 0 }
            source_version = [string]$sourcePlugin.version_disk
            target_version = if ($null -eq $targetPlugin) {
                ""
            } else {
                [string]$targetPlugin.version_disk
            }
            severity = $severity
            status = $status
            blocking = if ($blocking) { 1 } else { 0 }
            message = $message
        })
    }
}

[object[]]$rowArray = $rows.ToArray()
$blockingIssues = @($rowArray | Where-Object { [int]$_.blocking -eq 1 })
$warnings = @($rowArray | Where-Object { [string]$_.severity -eq "warning" })
$csvPath = Join-Path $phase2Host "plugin_compatibility.csv"
$rowArray |
    Export-Csv -LiteralPath $csvPath -NoTypeInformation -Encoding UTF8
$summary = [ordered]@{
    schema_version = "1.0"
    phase = "2-package-plugin-compatibility"
    generated_at_utc = [DateTime]::UtcNow.ToString("o")
    config_sha256 = Get-ConfigurationHash
    target_id = [string]$target.id
    target_moodle_version = [string]$targetInventory.moodle_version
    target_moodle_release = [string]$targetInventory.moodle_release
    sources_checked = $MigrationConfig.Sources.Count
    blocking_issues = $blockingIssues.Count
    warnings = $warnings.Count
    compatibility_csv_sha256 = (
        Get-FileHash -LiteralPath $csvPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    destination_write_performed = $false
    status = if ($blockingIssues.Count -eq 0) {
        "compatible"
    } else {
        "blocked"
    }
}
$summaryPath = Join-Path $phase2Host "plugin_compatibility.json"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText(
    $summaryPath,
    ($summary | ConvertTo-Json -Depth 10) + [Environment]::NewLine,
    $utf8NoBom
)

$color = if ($blockingIssues.Count -eq 0) { "Green" } else { "Yellow" }
Write-Host ""
Write-Host (
    "PACKAGE_PLUGIN_AUDIT status=$($summary.status) " +
    "blocking=$($blockingIssues.Count) warnings=$($warnings.Count) write=0"
) -ForegroundColor $color
Write-Host "Detalle: $csvPath" -ForegroundColor Cyan
if ($blockingIssues.Count -gt 0) {
    throw (
        "Hay $($blockingIssues.Count) incompatibilidad(es) bloqueante(s). " +
        "Corrija los plugins del destino y repita esta fase."
    )
}
