. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed
Assert-Command "docker"
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker no está iniciado o no responde."
}

$closurePath = Join-Path $ProjectRoot `
    "exports\phase7\closure_summary.json"
if (-not (Test-Path -LiteralPath $closurePath -PathType Leaf)) {
    throw "Falta el cierre de evidencias de la fase 7."
}
$closure = Get-Content -LiteralPath $closurePath -Raw -Encoding UTF8 |
    ConvertFrom-Json
$configHash = Get-ConfigurationHash
if ([string]$closure.config_sha256 -ne $configHash -or
        [string]$closure.closure_status -notin @(
            "evidence_consolidated",
            "lab_validated"
        ) -or
        [int]$closure.failed_courses -ne 0) {
    throw "La fase 7 no conserva un cierre aprobado para esta configuración."
}

$phase8Host = Join-Path $ProjectRoot "exports\phase8"
$reportsHost = Join-Path $ProjectRoot "reports"
New-Item -ItemType Directory -Force -Path $phase8Host, $reportsHost |
    Out-Null

$artifactNames = @(
    "database.sql.gz",
    "moodle-code.tar.gz",
    "moodledata.tar.gz",
    "site-backup-metadata.json",
    "managed-config-manifest.json",
    "evidencia-cierre.zip",
    "LEEME-RESTAURACION.txt",
    "manifest.json",
    "checksums.sha256"
)
$packagePath = Join-Path $phase8Host "paquete-sitio-consolidado.zip"
$packageHashPath = Join-Path $phase8Host `
    "paquete-sitio-consolidado.sha256.txt"
$summaryPath = Join-Path $phase8Host "site_package_summary.json"
$reportPath = Join-Path $reportsHost "fase-8-paquete-sitio.txt"
foreach ($name in $artifactNames + @(
    "paquete-sitio-consolidado.zip.partial",
    "paquete-sitio-consolidado.zip",
    "paquete-sitio-consolidado.sha256.txt",
    "site_package_summary.json"
)) {
    $path = Join-Path $phase8Host $name
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Remove-Item -LiteralPath $path -Force
    }
}

$target = Get-TargetSite
$targetService = [string]$target.service
$compressionThreads = [Math]::Max(1, [Environment]::ProcessorCount)
$assistantUid = 0
$assistantGid = 0
if (-not [int]::TryParse([string]$env:ASSISTANT_UID, [ref]$assistantUid) -or
        -not [int]::TryParse([string]$env:ASSISTANT_GID, [ref]$assistantGid) -or
        $assistantUid -lt 0 -or $assistantGid -lt 0) {
    throw "No fue posible determinar el UID/GID del operador para fase 8."
}
$publicUrl = [string]$env:MOODLE_PUBLIC_URL
if ([string]::IsNullOrWhiteSpace($publicUrl)) {
    $publicUrl = [string]$target.url
}
$publicUrl = $publicUrl.TrimEnd("/")
Invoke-Compose -Arguments @("up", "-d", "--no-build", $targetService)
Invoke-Compose -Arguments @(
    "exec", "-T", $targetService, "sh", "-lc",
    "mkdir -p /exports/phase8 && chown -R www-data:www-data /exports/phase8"
)
$maintenanceEnabled = $false
$operationError = $null
$disableError = $null
$ownershipError = $null
try {
    Write-Host (
        "Activando mantenimiento temporal para obtener una copia consistente..."
    ) -ForegroundColor Yellow
    & docker compose exec -T -u www-data $targetService `
        php admin/cli/maintenance.php --enable
    if ($LASTEXITCODE -ne 0) {
        throw "No fue posible activar el modo de mantenimiento."
    }
    $maintenanceEnabled = $true

    Write-Host "Exportando la base de datos consolidada..." `
        -ForegroundColor Cyan
    $databaseCommand = (
        'set -eu; umask 077; ' +
        'rm -f /exports/phase8/database.sql.gz.partial; ' +
        'export MYSQL_PWD="$MOODLE_DB_PASSWORD"; ' +
        'mariadb-dump --host="$MOODLE_DB_HOST" ' +
        '--port="$MOODLE_DB_PORT" --user="$MOODLE_DB_USER" ' +
        '--single-transaction --quick --skip-lock-tables --hex-blob ' +
        '--default-character-set=utf8mb4 "$MOODLE_DB_NAME" | ' +
        "pigz -6 -p $compressionThreads > /exports/phase8/database.sql.gz.partial; " +
        'unset MYSQL_PWD; ' +
        'mv /exports/phase8/database.sql.gz.partial ' +
        '/exports/phase8/database.sql.gz'
    )
    & docker compose exec -T -u www-data $targetService `
        bash -o pipefail -c $databaseCommand
    if ($LASTEXITCODE -ne 0) {
        throw "Falló la exportación consistente de la base de datos."
    }

    Write-Host "Empaquetando código Moodle y plugins sin config.php..." `
        -ForegroundColor Cyan
    $codeCommand = (
        'set -eu; umask 077; ' +
        'rm -f /exports/phase8/moodle-code.tar.gz.partial; ' +
        'tar -C /var/www/html ' +
        '--exclude=./config.php --exclude=./.git --exclude=./node_modules ' +
        "-cf - . | pigz -6 -p $compressionThreads " +
        '> /exports/phase8/moodle-code.tar.gz.partial; ' +
        'mv /exports/phase8/moodle-code.tar.gz.partial ' +
        '/exports/phase8/moodle-code.tar.gz'
    )
    & docker compose exec -T -u www-data $targetService `
        bash -o pipefail -c $codeCommand
    if ($LASTEXITCODE -ne 0) {
        throw "Falló el empaquetado del código Moodle."
    }

    Write-Host "Empaquetando moodledata sin cachés regenerables..." `
        -ForegroundColor Cyan
    $dataCommand = (
        'set -eu; umask 077; ' +
        'rm -f /exports/phase8/moodledata.tar.gz.partial; ' +
        'tar -C /var/www/moodledata ' +
        '--exclude=./cache --exclude=./localcache --exclude=./temp ' +
        '--exclude=./trashdir --exclude=./sessions --exclude=./lock ' +
        "-cf - . | pigz -6 -p $compressionThreads " +
        '> /exports/phase8/moodledata.tar.gz.partial; ' +
        'mv /exports/phase8/moodledata.tar.gz.partial ' +
        '/exports/phase8/moodledata.tar.gz'
    )
    & docker compose exec -T -u www-data $targetService `
        bash -o pipefail -c $dataCommand
    if ($LASTEXITCODE -ne 0) {
        throw "Falló el empaquetado de moodledata."
    }

    & docker compose exec -T -u www-data $targetService `
        php /opt/consolidator/site-backup-metadata.php `
        "--output=/exports/phase8/site-backup-metadata.json" `
        "--targetid=$($target.id)"
    if ($LASTEXITCODE -ne 0) {
        throw "No fue posible registrar los metadatos del sitio."
    }
} catch {
    $operationError = $_
} finally {
    if ($maintenanceEnabled) {
        Write-Host "Desactivando el modo de mantenimiento..." `
            -ForegroundColor Yellow
        & docker compose exec -T -u www-data $targetService `
            php admin/cli/maintenance.php --disable
        if ($LASTEXITCODE -ne 0) {
            $disableError = (
                "No fue posible desactivar el modo de mantenimiento. " +
                "Desactívelo inmediatamente desde la CLI de Moodle."
            )
        }
    }
    $ownershipCommand = (
        "chown -R ${assistantUid}:${assistantGid} /exports/phase8 && " +
        "chmod -R u=rwX,go= /exports/phase8"
    )
    & docker compose exec -T $targetService sh -lc $ownershipCommand
    if ($LASTEXITCODE -ne 0) {
        $ownershipError = (
            "No fue posible devolver al operador los permisos de exports/phase8."
        )
    }
}
if ($null -ne $disableError) {
    throw $disableError
}
if ($null -ne $operationError) {
    throw $operationError
}
if ($null -ne $ownershipError) {
    throw $ownershipError
}

$managedConfigSource = "/managed-config-host/manifest.json"
$managedConfigCopy = Join-Path $phase8Host "managed-config-manifest.json"
if (-not (Test-Path -LiteralPath $managedConfigSource -PathType Leaf)) {
    throw "Falta el manifiesto de la configuración declarativa administrada."
}
Copy-Item -LiteralPath $managedConfigSource `
    -Destination $managedConfigCopy -Force

$requiredGenerated = @(
    "database.sql.gz",
    "moodle-code.tar.gz",
    "moodledata.tar.gz",
    "site-backup-metadata.json",
    "managed-config-manifest.json"
)
foreach ($name in $requiredGenerated) {
    $path = Join-Path $phase8Host $name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or
            (Get-Item -LiteralPath $path).Length -lt 1) {
        throw "El artefacto $name quedó ausente o vacío."
    }
}

$closureArchivePath = Join-Path $ProjectRoot `
    "exports\phase7\fase-7-cierre-migracion.zip"
$evidenceCopyPath = Join-Path $phase8Host "evidencia-cierre.zip"
Copy-Item -LiteralPath $closureArchivePath `
    -Destination $evidenceCopyPath -Force

$restoreGuidePath = Join-Path $phase8Host "LEEME-RESTAURACION.txt"
$restoreGuide = @(
    "PAQUETE INTEGRAL DEL MOODLE CONSOLIDADO",
    "",
    "Este ZIP no se restaura desde la pantalla Restaurar curso.",
    "Debe ser aplicado por un administrador de servidor en una ventana aprobada.",
    "",
    "Contenido:",
    "  database.sql.gz       Base de datos consolidada.",
    "  moodledata.tar.gz     Archivos persistentes; excluye caches regenerables.",
    "  moodle-code.tar.gz    Código y plugins; excluye config.php.",
    "  managed-config-manifest.json  Hash y versión; no contiene valores.",
    "  evidencia-cierre.zip  Informe y cadena de evidencia de la consolidación.",
    "",
    "URL pública registrada al crear la copia: $publicUrl",
    "",
    "Secuencia de restauración:",
    "  1. Verificar checksums.sha256 y manifest.json.",
    "  2. Preparar una base vacía y un moodledata vacío con permisos correctos.",
    "  3. Extraer moodle-code.tar.gz y moodledata.tar.gz.",
    "  4. Importar database.sql.gz en la base preparada.",
    "  5. Instalar esta distribución y restaurar por separado el directorio",
    "     seguro indicado por MOODLE_MANAGED_CONFIG_DIR.",
    "  6. Regenerar config.php desde .env con PREPARAR-DESTINO.sh.",
    "  7. Si cambia la URL, ejecutar fuera de línea la sustitución oficial",
    "     de URL de Moodle y revisar el resultado antes de abrir el sitio.",
    "  8. Mantener el acceso web cerrado y ejecutar admin/cli/upgrade.php",
    "     --non-interactive.",
    "  9. Purgar cachés, ejecutar cron y realizar la validación institucional.",
    " 10. Desactivar mantenimiento solo al final con",
    "     admin/cli/maintenance.php --disable.",
    "",
    "config.php, los valores declarativos y las credenciales no se incluyen.",
    "managed-config-manifest.json permite comprobar qué versión corresponde.",
    "La base sí contiene hashes de cuenta y posiblemente datos de autenticación",
    "de Moodle. Mantenga todo el paquete cifrado y con acceso limitado."
)
[System.IO.File]::WriteAllLines(
    $restoreGuidePath,
    $restoreGuide,
    (New-Object System.Text.UTF8Encoding($false))
)

$metadataPath = Join-Path $phase8Host "site-backup-metadata.json"
$metadata = Get-Content -LiteralPath $metadataPath -Raw -Encoding UTF8 |
    ConvertFrom-Json
$batchCoursesVerified = [int]$closure.courses_verified
$totalCoursesVerified = if (
    $null -ne $closure.total_courses_verified -and
    [int]$closure.total_courses_verified -gt 0
) {
    [int]$closure.total_courses_verified
} else {
    $batchCoursesVerified + 1
}
$files = @(
    "database.sql.gz",
    "moodle-code.tar.gz",
    "moodledata.tar.gz",
    "site-backup-metadata.json",
    "managed-config-manifest.json",
    "evidencia-cierre.zip",
    "LEEME-RESTAURACION.txt"
)
$hashByFile = @{}
$entries = @(
    $files | ForEach-Object {
        $path = Join-Path $phase8Host $_
        $hashByFile[$_] = (
            Get-FileHash -LiteralPath $path -Algorithm SHA256
        ).Hash.ToLowerInvariant()
        [ordered]@{
            file = $_
            bytes = [int64](Get-Item -LiteralPath $path).Length
            sha256 = $hashByFile[$_]
        }
    }
)
$manifest = [ordered]@{
    schema_version = "1.0"
    package_type = "moodle-consolidated-site-backup"
    package_version = "7.3.0-linux"
    generated_at_utc = [DateTime]::UtcNow.ToString("o")
    config_sha256 = $configHash
    target_id = [string]$target.id
    target_url_at_backup = $publicUrl
    moodle_version = [string]$metadata.moodle_version
    moodle_release = [string]$metadata.moodle_release
    phase7_closure_sha256 = (
        Get-FileHash -LiteralPath $closurePath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    batch_courses_verified = $batchCoursesVerified
    pilot_courses_verified = 1
    courses_verified_total = $totalCoursesVerified
    failed_courses = [int]$closure.failed_courses
    files = $entries
    database_export_mode = "single_transaction"
    maintenance_mode_used = $true
    maintenance_mode_restored = $true
    restored_copy_starts_in_maintenance_mode = $true
    academic_content_write_performed = $false
    config_php_included = $false
    managed_config_manifest_included = $true
    managed_config_values_included = $false
    database_connection_credentials_included = $false
    contains_sensitive_authentication_data = $true
    package_status = "sealed"
}
$manifestPath = Join-Path $phase8Host "manifest.json"
[System.IO.File]::WriteAllText(
    $manifestPath,
    ($manifest | ConvertTo-Json -Depth 12) + [Environment]::NewLine,
    (New-Object System.Text.UTF8Encoding($false))
)
$manifestHash = (
    Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256
).Hash.ToLowerInvariant()
$hashByFile["manifest.json"] = $manifestHash

$checksumFiles = $files + @("manifest.json")
$checksumLines = @(
    $checksumFiles | ForEach-Object {
        "$($hashByFile[$_])  $_"
    }
)
$checksumsPath = Join-Path $phase8Host "checksums.sha256"
[System.IO.File]::WriteAllLines(
    $checksumsPath,
    $checksumLines,
    (New-Object System.Text.UTF8Encoding($false))
)

Add-Type -AssemblyName System.IO.Compression.FileSystem
$temporaryPackage = "$packagePath.partial"
if (Test-Path -LiteralPath $temporaryPackage -PathType Leaf) {
    Remove-Item -LiteralPath $temporaryPackage -Force
}
$archive = [System.IO.Compression.ZipFile]::Open(
    $temporaryPackage,
    [System.IO.Compression.ZipArchiveMode]::Create
)
try {
    foreach ($name in $artifactNames) {
        $path = Join-Path $phase8Host $name
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $path,
            $name,
            [System.IO.Compression.CompressionLevel]::NoCompression
        )
    }
} finally {
    $archive.Dispose()
}
if (Test-Path -LiteralPath $packagePath -PathType Leaf) {
    Remove-Item -LiteralPath $packagePath -Force
}
Move-Item -LiteralPath $temporaryPackage -Destination $packagePath

$verificationArchive = [System.IO.Compression.ZipFile]::OpenRead($packagePath)
try {
    $actualNames = @(
        $verificationArchive.Entries |
            ForEach-Object { [string]$_.FullName } |
            Sort-Object
    )
    $expectedNames = @($artifactNames | Sort-Object)
    if (($actualNames -join "|") -cne ($expectedNames -join "|")) {
        throw "El ZIP integral no contiene exactamente los artefactos sellados."
    }
} finally {
    $verificationArchive.Dispose()
}

$packageHash = (
    Get-FileHash -LiteralPath $packagePath -Algorithm SHA256
).Hash.ToLowerInvariant()
[System.IO.File]::WriteAllText(
    $packageHashPath,
    "$packageHash  $([System.IO.Path]::GetFileName($packagePath))" +
        [Environment]::NewLine,
    [System.Text.Encoding]::ASCII
)
$packageSummary = [ordered]@{
    schema_version = "1.0"
    phase = "8-consolidated-site-package"
    generated_at_utc = [DateTime]::UtcNow.ToString("o")
    config_sha256 = $configHash
    target_id = [string]$target.id
    package_file = [System.IO.Path]::GetFileName($packagePath)
    package_bytes = [int64](Get-Item -LiteralPath $packagePath).Length
    package_sha256 = $packageHash
    manifest_sha256 = $manifestHash
    maintenance_mode_restored = $true
    status = "sealed"
}
[System.IO.File]::WriteAllText(
    $summaryPath,
    ($packageSummary | ConvertTo-Json -Depth 8) + [Environment]::NewLine,
    (New-Object System.Text.UTF8Encoding($false))
)

$consoleLines = @(
    (
        "CONSOLIDATED_SITE_PACKAGE_OK courses=" +
        $totalCoursesVerified +
        " batch=" + $batchCoursesVerified +
        " failed=0 maintenance_restored=1"
    ),
    "",
    "Paquete integral generado: $packagePath",
    "SHA-256: $packageHash",
    "Contiene base de datos, moodledata, código/plugins y evidencia.",
    "No contiene config.php, valores declarativos ni credenciales."
)
$consoleLines | Tee-Object -FilePath $reportPath
