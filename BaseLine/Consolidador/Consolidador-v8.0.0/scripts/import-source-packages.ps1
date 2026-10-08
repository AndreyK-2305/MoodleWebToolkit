$ErrorActionPreference = "Stop"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8NoBom
[Console]::OutputEncoding = $utf8NoBom
$OutputEncoding = $utf8NoBom

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot
. "$PSScriptRoot/PackageIntegrity.ps1"

function Write-Utf8NoBom {
    param(
        [string]$Path,
        [string]$Content
    )
    $parent = Split-Path -Parent $Path
    if (-not [string]::IsNullOrWhiteSpace($parent)) {
        New-Item -ItemType Directory -Force -Path $parent | Out-Null
    }
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

function Write-JsonNoBom {
    param(
        [string]$Path,
        [object]$Value,
        [int]$Depth = 100
    )
    $json = $Value | ConvertTo-Json -Depth $Depth
    Write-Utf8NoBom -Path $Path -Content ($json + [Environment]::NewLine)
}

function Read-JsonFile {
    param(
        [string]$Path,
        [string]$Label
    )
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Falta $Label en $Path."
    }
    try {
        return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 |
            ConvertFrom-Json
    } catch {
        throw "$Label no contiene JSON válido: $($_.Exception.Message)"
    }
}

function Get-StringSha256 {
    param([string]$Value)
    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = $utf8NoBom.GetBytes($Value)
        return ([BitConverter]::ToString(
            $algorithm.ComputeHash($bytes)
        )).Replace("-", "").ToLowerInvariant()
    } finally {
        $algorithm.Dispose()
    }
}

function Read-ZipEntryText {
    param(
        [System.IO.Compression.ZipArchiveEntry]$Entry
    )
    $stream = $Entry.Open()
    try {
        $reader = New-Object System.IO.StreamReader(
            $stream,
            $utf8NoBom,
            $true
        )
        try {
            return $reader.ReadToEnd()
        } finally {
            $reader.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}

function Assert-SafeZipPath {
    param([string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path) -or
            $Path.Contains("\") -or
            $Path.StartsWith("/") -or
            $Path -match '^[a-zA-Z]:' -or
            $Path.IndexOfAny([char[]]'<>:"|?*') -ge 0 -or
            $Path.IndexOf([char]0) -ge 0) {
        throw "El ZIP contiene una ruta insegura: '$Path'."
    }
    $normalized = $Path.Normalize(
        [System.Text.NormalizationForm]::FormC
    )
    if ($normalized -cne $Path) {
        throw "El ZIP contiene una ruta Unicode no normalizada."
    }
    $segments = @($Path.Split("/"))
    if ($segments.Count -lt 1 -or
            $segments -contains ".." -or
            $segments -contains ".") {
        throw "El ZIP contiene una ruta relativa insegura: '$Path'."
    }
    foreach ($segment in $segments) {
        if ([string]::IsNullOrWhiteSpace($segment) -or
                $segment.TrimEnd([char[]]@(" ", ".")) -cne $segment -or
                $segment -match
                    '^(?i:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$') {
            throw "El ZIP contiene una ruta vacía o duplicada: '$Path'."
        }
    }
}

function Expand-ValidatedSourcePackage {
    param(
        [string]$ZipPath,
        [string]$StagingRoot,
        [int]$MaximumEntries,
        [Parameter(Mandatory)]$Integrity
    )

    Write-Host "Validando contrato del paquete $([IO.Path]::GetFileName($ZipPath))..." `
        -ForegroundColor Cyan
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $fileEntries = @(
            $archive.Entries |
                Where-Object { -not [string]::IsNullOrWhiteSpace($_.Name) }
        )
        if ($fileEntries.Count -lt 5 -or
                $fileEntries.Count -gt $MaximumEntries) {
            throw (
                "El ZIP contiene $($fileEntries.Count) archivos; el límite " +
                "configurado es $MaximumEntries."
            )
        }
        foreach ($entry in $fileEntries) {
            if ([int64]$entry.Length -lt 0) {
                throw "El ZIP declara una longitud de entrada inválida."
            }
        }
        $seenPaths = @{}
        foreach ($entry in $fileEntries) {
            Assert-SafeZipPath ([string]$entry.FullName)
            $key = ([string]$entry.FullName).ToLowerInvariant()
            if ($seenPaths.ContainsKey($key)) {
                throw "El ZIP repite la ruta '$($entry.FullName)'."
            }
            $seenPaths[$key] = $entry

            # Rechaza enlaces simbólicos conservados desde plataformas Unix.
            $unixType = (([int64]$entry.ExternalAttributes -shr 16) -band 0xF000)
            if ($unixType -eq 0xA000) {
                throw "El ZIP contiene un enlace simbólico no permitido."
            }
        }

        $manifestEntry = $fileEntries |
            Where-Object { $_.FullName -ceq "manifest.json" } |
            Select-Object -First 1
        $checksumsEntry = $fileEntries |
            Where-Object { $_.FullName -ceq "checksums.sha256" } |
            Select-Object -First 1
        if ($null -eq $manifestEntry -or $null -eq $checksumsEntry) {
            throw "El ZIP no contiene manifest.json y checksums.sha256."
        }
        if ([int64]$manifestEntry.Length -gt 33554432 -or
                [int64]$checksumsEntry.Length -gt 33554432) {
            throw "El manifiesto o la lista de hashes excede 32 MB."
        }
        try {
            $manifest = (Read-ZipEntryText $manifestEntry) | ConvertFrom-Json
        } catch {
            throw "manifest.json no contiene JSON válido."
        }
        $sourceId = [string]$manifest.source_id
        if ([string]$manifest.schema_version -ne "1.0" -or
                [string]$manifest.package_type -ne
                    "moodle-consolidation-source" -or
                [string]$manifest.package_status -ne "sealed" -or
                [string]$manifest.collector_version -notmatch '^7\.[0-9]+\.[0-9]+(?:-linux(?:-rc[0-9]+)?)?$' -or
                [string]$manifest.hash_strategy -cne 'single-pass-v1' -or
                $sourceId -notmatch '^[a-z][a-z0-9_-]*$' -or
                [string]::IsNullOrWhiteSpace(
                    [string]$manifest.source_name
                ) -or
                [string]::IsNullOrWhiteSpace(
                    [string]$manifest.source_moodle_version
                ) -or
                [string]::IsNullOrWhiteSpace(
                    [string]$manifest.source_moodle_release
                ) -or
                [string]$manifest.source_name -match '[\x00-\x1F]' -or
                [string]$manifest.identity_scope -notin @("lab", "all") -or
                [int]$manifest.courses_expected -lt 1 -or
                [bool]$manifest.source_write_performed -or
                [bool]$manifest.destination_write_performed) {
            throw "El manifiesto del paquete no conserva el contrato sellado."
        }
        if (-not [string]::IsNullOrWhiteSpace(
                [string]$manifest.source_wwwroot
            ) -and [string]$manifest.source_wwwroot -notmatch '^https?://') {
            throw "El manifiesto contiene una URL de origen inválida."
        }
        if ([string]$manifest.identity_file -cne "identidades.json" -or
                [string]$manifest.source_inventory_file -cne
                    "inventario-origen.json" -or
                [string]$manifest.plugins_file -cne "plugins.json") {
            throw "El manifiesto cambió las rutas base del contrato."
        }
        foreach ($hashField in @(
            "identity_sha256",
            "source_inventory_sha256",
            "plugins_sha256"
        )) {
            if ([string]$manifest.$hashField -notmatch
                    '^[a-fA-F0-9]{64}$') {
                throw "El manifiesto contiene un hash base inválido."
            }
        }

        $expected = [ordered]@{
            "identidades.json" = $true
            "inventario-origen.json" = $true
            "plugins.json" = $true
            "manifest.json" = $true
            "checksums.sha256" = $true
        }
        $courseKeys = @{}
        $courseIds = @{}
        foreach ($item in @($manifest.entries)) {
            $courseKey = [string]$item.course_key
            $courseId = 0
            if ($courseKey -notmatch '^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$' -or
                    -not [int]::TryParse(
                        [string]$item.source_course_id,
                        [ref]$courseId
                    ) -or
                    $courseId -lt 1 -or
                    $courseKeys.ContainsKey($courseKey) -or
                    $courseIds.ContainsKey($courseId)) {
                throw "El manifiesto contiene un curso inválido o repetido."
            }
            $courseKeys[$courseKey] = $true
            $courseIds[$courseId] = $true
            foreach ($field in @(
                "backup_file",
                "inventory_file",
                "checkpoint_file"
            )) {
                $relative = [string]$item.$field
                Assert-SafeZipPath $relative
                if ($expected.Contains($relative)) {
                    throw "El manifiesto repite el artefacto '$relative'."
                }
                $expected[$relative] = $true
            }
            foreach ($hashField in @(
                "backup_sha256",
                "inventory_sha256",
                "checkpoint_sha256"
            )) {
                if ([string]$item.$hashField -notmatch '^[a-fA-F0-9]{64}$') {
                    throw "El manifiesto contiene un hash inválido en $hashField."
                }
            }
        }
        if (@($manifest.entries).Count -ne
                [int]$manifest.courses_expected) {
            throw "El manifiesto no conserva el número esperado de cursos."
        }

        $actualPaths = @($fileEntries | ForEach-Object { [string]$_.FullName })
        $expectedPaths = @($expected.Keys)
        $unexpected = @($actualPaths | Where-Object { $_ -notin $expectedPaths })
        $missing = @($expectedPaths | Where-Object { $_ -notin $actualPaths })
        if ($unexpected.Count -gt 0 -or $missing.Count -gt 0) {
            throw (
                "El ZIP no coincide con su manifiesto. Faltantes: " +
                ($missing -join "|") + ". Ajenos: " +
                ($unexpected -join "|") + "."
            )
        }

        $declared = @{}
        foreach ($line in ((Read-ZipEntryText $checksumsEntry) -split "`n")) {
            if ($line -eq '') { continue }
            $line = $line.TrimEnd("`r")
            if ($line -cnotmatch '^([a-f0-9]{64})  (.+)$') {
                throw 'checksums.sha256 contiene una fila inválida.'
            }
            $sha = [string]$Matches[1]
            $relative = [string]$Matches[2]
            Assert-SafeZipPath $relative
            if ($relative -ceq 'checksums.sha256' -or
                    -not $expected.Contains($relative) -or
                    $declared.ContainsKey($relative)) {
                throw "checksums.sha256 contiene una ruta ajena o duplicada: $relative."
            }
            $declared[$relative] = $sha
        }
        if ($declared.Count -ne $expected.Count - 1) {
            throw 'checksums.sha256 no cubre todos los archivos del manifiesto.'
        }
        foreach ($field in @(
            @{ Path = 'identidades.json'; Sha = 'identity_sha256' },
            @{ Path = 'inventario-origen.json'; Sha = 'source_inventory_sha256' },
            @{ Path = 'plugins.json'; Sha = 'plugins_sha256' }
        )) {
            if ($declared[$field.Path] -cne
                    ([string]$manifest.($field.Sha)).ToLowerInvariant()) {
                throw "El manifiesto contradice checksums.sha256: $($field.Path)."
            }
        }
        foreach ($item in @($manifest.entries)) {
            foreach ($field in @(
                @{ Path = 'backup_file'; Sha = 'backup_sha256' },
                @{ Path = 'inventory_file'; Sha = 'inventory_sha256' },
                @{ Path = 'checkpoint_file'; Sha = 'checkpoint_sha256' }
            )) {
                $relative = [string]$item.($field.Path)
                if ($declared[$relative] -cne
                        ([string]$item.($field.Sha)).ToLowerInvariant()) {
                    throw "El manifiesto contradice checksums.sha256: $relative."
                }
            }
        }
        Write-Host 'Contrato, rutas y sellos internos: validados.' -ForegroundColor Green

        $packageRoot = Join-Path $StagingRoot $sourceId
        New-Item -ItemType Directory -Force -Path $packageRoot | Out-Null
        $packageRootFull = [System.IO.Path]::GetFullPath($packageRoot)
        Write-Host 'Preparando extracción...' -ForegroundColor Cyan

        # RC10: snapshot observacional; no participa en validación ni extracción.
        $phase1ProgressPath = Join-Path `
            (Split-Path -Parent $PSScriptRoot) `
            "reports\fase-1-progress.json"

        $phase1CourseTotal = @(
            $fileEntries |
                Where-Object {
                    [string]$_.FullName -match '^cursos/[^/]+\.mbz$'
                }
        ).Count

        $phase1CourseStarted = 0
        foreach ($entry in $fileEntries) {
            $relativeOs = ([string]$entry.FullName).Replace(
                "/",
                [System.IO.Path]::DirectorySeparatorChar
            )
            $destination = [System.IO.Path]::GetFullPath(
                (Join-Path $packageRootFull $relativeOs)
            )
            $prefix = $packageRootFull.TrimEnd(
                [System.IO.Path]::DirectorySeparatorChar
            ) + [System.IO.Path]::DirectorySeparatorChar
            if (-not $destination.StartsWith(
                $prefix,
                [StringComparison]::OrdinalIgnoreCase
            )) {
                throw "La extracción intentó salir del directorio asignado."
            }
            New-Item -ItemType Directory -Force `
                -Path (Split-Path -Parent $destination) | Out-Null
            if ([string]$entry.FullName -match '^cursos/[^/]+\.mbz$') {
                $phase1CourseStarted++

                $phase1CompletedBefore = [Math]::Max(
                    0,
                    $phase1CourseStarted - 1
                )

                $phase1Percent = if ($phase1CourseTotal -gt 0) {
                    [Math]::Round(
                        100.0 * $phase1CompletedBefore / $phase1CourseTotal,
                        2
                    )
                } else {
                    0
                }

                $phase1Snapshot = [ordered]@{
                    schema_version = "1.0"
                    phase = "1-source-package-import"
                    updated_at_utc = [DateTimeOffset]::UtcNow.ToString("o")
                    source_id = $sourceId
                    operation = "extracting_course_backup"
                    processed = $phase1CompletedBefore
                    total = $phase1CourseTotal
                    percent = $phase1Percent
                    current_index = $phase1CourseStarted
                    current_course_file = [string]$entry.FullName
                }

                $phase1Temporary = "$phase1ProgressPath.partial-$PID"

                $phase1Snapshot |
                    ConvertTo-Json -Depth 5 |
                    Set-Content `
                        -LiteralPath $phase1Temporary `
                        -Encoding UTF8

                Move-Item `
                    -LiteralPath $phase1Temporary `
                    -Destination $phase1ProgressPath `
                    -Force
            }

            Write-Host "Extrayendo paquete: $($entry.FullName)" -ForegroundColor Cyan
            $inputStream = $entry.Open()
            try {
                $outputStream = [System.IO.File]::Open(
                    $destination,
                    [System.IO.FileMode]::CreateNew,
                    [System.IO.FileAccess]::Write,
                    [System.IO.FileShare]::None
                )
                try {
                    $buffer = New-Object byte[] (4 * 1024 * 1024)
                    $copied = [int64]0
                    $lastProgress = [DateTime]::UtcNow
                    while (($read = $inputStream.Read($buffer, 0, $buffer.Length)) -gt 0) {
                        $outputStream.Write($buffer, 0, $read)
                        $copied += $read
                        if (([DateTime]::UtcNow - $lastProgress).TotalSeconds -ge 20) {
                            Write-Host (
                                "Extrayendo paquete: $($entry.FullName) " +
                                "$([Math]::Round($copied / 1GB, 2)) / " +
                                "$([Math]::Round($entry.Length / 1GB, 2)) GiB"
                            ) -ForegroundColor DarkGray
                            $lastProgress = [DateTime]::UtcNow
                        }
                    }
                    if ($copied -ne [int64]$entry.Length) {
                        throw "La extracción de $($entry.FullName) quedó incompleta."
                    }
                } finally {
                    $outputStream.Dispose()
                }
            } finally {
                $inputStream.Dispose()
            }
        }

        Write-Host 'Validando estructura extraída y hashes de metadatos...' `
            -ForegroundColor Cyan
        foreach ($relative in @($declared.Keys | Where-Object {
            $_ -cnotmatch '\.mbz$'
        })) {
            $path = Join-Path $packageRootFull `
                ($relative.Replace('/', [IO.Path]::DirectorySeparatorChar))
            if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() `
                    -cne $declared[$relative]) {
                throw "El archivo extraído no coincide con el sello: $relative."
            }
        }
        $identity = Read-JsonFile (Join-Path $packageRoot 'identidades.json') `
            'identidades del Recolector'
        $inventory = Read-JsonFile (Join-Path $packageRoot 'inventario-origen.json') `
            'inventario del Recolector'
        $plugins = Read-JsonFile (Join-Path $packageRoot 'plugins.json') `
            'plugins del Recolector'
        if ([string]$identity.metadata.source -cne $sourceId -or
                [string]$inventory.source_id -cne $sourceId -or
                [string]$plugins.source_id -cne $sourceId -or
                [string]$inventory.source_name -cne [string]$manifest.source_name -or
                [string]$inventory.source_wwwroot -cne [string]$manifest.source_wwwroot -or
                [string]$inventory.source_moodle_version -cne
                    [string]$manifest.source_moodle_version -or
                [string]$inventory.source_moodle_release -cne
                    [string]$manifest.source_moodle_release -or
                [string]$identity.metadata.scope -cne [string]$manifest.identity_scope -or
                [string]$identity.metadata.schema_version -cne
                    [string]$manifest.identity_schema_version -or
                [string]$manifest.identity_schema_version -cne '1.2' -or
                [bool]$inventory.write_performed -or
                [bool]$plugins.write_performed) {
            throw 'Los artefactos del Recolector no corresponden al source_id y al contrato sellado.'
        }
        Write-Host 'Registrando paquete y metadatos del Recolector...' `
            -ForegroundColor Cyan

        return [pscustomobject]@{
            SourceId = $sourceId
            SourceName = [string]$manifest.source_name
            SourceUrl = [string]$manifest.source_wwwroot
            MoodleVersion = [string]$manifest.source_moodle_version
            MoodleRelease = [string]$manifest.source_moodle_release
            IdentityScope = [string]$manifest.identity_scope
            Courses = [int]$manifest.courses_expected
            ZipPath = $ZipPath
            ZipSha256 = $Integrity.Sha256
            ZipIntegrityMode = $Integrity.Mode
            ZipHashedAgain = $Integrity.ZipHashedAgain
            ZipSidecar = $Integrity.SidecarPath
            PackageRoot = $packageRoot
            Manifest = $manifest
            ManifestSha256 = (Get-FileHash `
                -LiteralPath (Join-Path $packageRoot "manifest.json") `
                -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    } finally {
        $archive.Dispose()
    }
}

function Quote-Yaml {
    param([string]$Value)
    return '"' + $Value.Replace("\", "\\").Replace('"', '\"') + '"'
}

$assistantPath = Join-Path $ProjectRoot "config\assistant.json"
$assistant = Read-JsonFile $assistantPath "configuración del asistente"
if ([string]$assistant.schema_version -ne "1.0") {
    throw "config\assistant.json usa una versión no soportada."
}
$strictIntegrity = $false
if ($null -ne $assistant.package_policy.strict_integrity) {
    if ($assistant.package_policy.strict_integrity -isnot [bool]) {
        throw 'package_policy.strict_integrity debe ser true o false.'
    }
    $strictIntegrity = [bool]$assistant.package_policy.strict_integrity
}
$target = $assistant.target
foreach ($field in @(
    "id",
    "name",
    "service",
    "url_from_environment",
    "parent_category_id"
)) {
    if ($null -eq $target.$field -or
            [string]::IsNullOrWhiteSpace([string]$target.$field)) {
        throw "config\assistant.json: falta target.$field."
    }
}
if ([string]$target.id -notmatch '^[a-z][a-z0-9_-]*$' -or
        [string]$target.service -notmatch '^[a-zA-Z0-9_.-]+$' -or
        [string]$target.url_from_environment -ne "MOODLE_PUBLIC_URL" -or
        [int]$target.parent_category_id -lt 1) {
    throw "config\assistant.json contiene un destino inválido."
}
$publicUrl = [string]$env:MOODLE_PUBLIC_URL
if ([string]::IsNullOrWhiteSpace($publicUrl)) {
    throw "Falta MOODLE_PUBLIC_URL para identificar el Moodle destino."
}
$publicUrl = $publicUrl.TrimEnd("/")
if ($publicUrl -notmatch '^https?://') {
    throw "MOODLE_PUBLIC_URL debe ser una URL http(s)."
}
Add-Member `
    -InputObject $target `
    -MemberType NoteProperty `
    -Name "url" `
    -Value $publicUrl `
    -Force

$laterArtifacts = @(
    "reports\destination-write.lock.json",
    "exports\phase4\apply_summary.json",
    "exports\phase5\apply_summary.json",
    "exports\phase6\category_apply_summary.json",
    "exports\phase6\batch_apply_summary.json"
)
foreach ($relative in $laterArtifacts) {
    if (Test-Path -LiteralPath (Join-Path $ProjectRoot $relative) `
            -PathType Leaf) {
        throw (
            "Ya existen escrituras registradas en $relative. " +
            "No se reemplazarán los paquetes de esta ejecución."
        )
    }
}

$copyDirectory = Join-Path $ProjectRoot "copias"
$zipFiles = @(
    Get-ChildItem -LiteralPath $copyDirectory -Filter "*.zip" -File |
        Sort-Object Name
)
$minimumSources = [int]$assistant.package_policy.minimum_sources
if ($minimumSources -lt 1) { $minimumSources = 1 }
$maximumSources = [int]$assistant.package_policy.maximum_sources
if ($maximumSources -lt $minimumSources) {
    throw "config\assistant.json contiene un maximum_sources inválido."
}
$maximumEntries = [int]$assistant.package_policy.maximum_entries_per_package
if ($maximumEntries -lt 5) {
    throw "config\assistant.json contiene límites de paquete inválidos."
}
if ($zipFiles.Count -lt $minimumSources) {
    throw (
        "Se esperaban al menos $minimumSources paquete(s) ZIP en copias; " +
        "se encontraron $($zipFiles.Count)."
    )
}
if ($zipFiles.Count -gt $maximumSources) {
    throw (
        "Se permiten como máximo $maximumSources paquete(s) ZIP en copias; " +
        "se encontraron $($zipFiles.Count)."
    )
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$stagingRoot = Join-Path $ProjectRoot `
    ("exports\.package-import-" + [Guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $stagingRoot | Out-Null

try {
    $packages = New-Object System.Collections.Generic.List[object]
    $sourceIds = @{}
    foreach ($zip in $zipFiles) {
        Write-Host "Validando $($zip.Name)..." -ForegroundColor Cyan
        $integrity = Resolve-SourcePackageIntegrity -Zip $zip `
            -StrictIntegrity $strictIntegrity
        $package = Expand-ValidatedSourcePackage `
            -ZipPath $zip.FullName `
            -StagingRoot $stagingRoot `
            -MaximumEntries $maximumEntries `
            -Integrity $integrity
        if ($sourceIds.ContainsKey($package.SourceId) -or
                $package.SourceId -eq [string]$target.id) {
            throw "El source_id '$($package.SourceId)' está repetido o coincide con el destino."
        }
        $sourceIds[$package.SourceId] = $true
        [void]$packages.Add($package)
        Write-Host "Importación completada: $($zip.Name)." -ForegroundColor Green
    }
    # New-Object List[object] + @($list) puede lanzar
    # "Argument types do not match" tanto en Windows PowerShell 5.1 como en
    # PowerShell 7. Convierta de forma explícita antes de usar operadores de
    # colección; los paquetes de entrada y sus hashes no cambian.
    [object[]]$packageArray = $packages.ToArray()
    $packages = @($packageArray | Sort-Object SourceId)

    # Alias operativo corto por origen. Por defecto usa los tres primeros
    # caracteres alfanuméricos del source_id. Un archivo opcional permite
    # resolver explícitamente colisiones antes de crear cualquier plan.
    $sourceAliasPath = Join-Path $ProjectRoot "config\source-aliases.json"
    $aliasOverrides = @{}
    if (Test-Path -LiteralPath $sourceAliasPath -PathType Leaf) {
        $aliasDocument = Read-JsonFile $sourceAliasPath "alias de fuentes"
        if ([string]$aliasDocument.schema_version -ne "1.0" -or
                $null -eq $aliasDocument.aliases) {
            throw "config\source-aliases.json no conserva el contrato 1.0."
        }
        foreach ($property in @($aliasDocument.aliases.PSObject.Properties)) {
            $aliasOverrides[[string]$property.Name] =
                ([string]$property.Value).Trim().ToUpperInvariant()
        }
    }

    $sourceAliases = [ordered]@{}
    $aliasesSeen = @{}
    $aliasCollisions = New-Object System.Collections.Generic.List[string]

    foreach ($package in $packages) {
        $sourceId = [string]$package.SourceId
        $compact = [regex]::Replace($sourceId, '[^A-Za-z0-9]', '')

        if ($compact.Length -lt 3) {
            throw "SOURCE_ALIAS_INVALID: '$sourceId' no permite derivar un alias de tres caracteres."
        }

        $alias = $compact.Substring(0, 3).ToUpperInvariant()

        if ($aliasOverrides.ContainsKey($sourceId)) {
            $alias = [string]$aliasOverrides[$sourceId]
        }

        if ($alias -notmatch '^[A-Z0-9]{3}$') {
            throw "SOURCE_ALIAS_INVALID: '$sourceId' usa '$alias'; se requieren exactamente tres caracteres A-Z/0-9."
        }

        $sourceAliases[$sourceId] = $alias

        if ($aliasesSeen.ContainsKey($alias)) {
            [void]$aliasCollisions.Add(
                "$alias=$($aliasesSeen[$alias]),$sourceId"
            )
        } else {
            $aliasesSeen[$alias] = $sourceId
        }
    }

    $unknownAliases = @(
        $aliasOverrides.Keys |
            Where-Object {
                $_ -notin @(
                    $packages |
                        ForEach-Object { $_.SourceId }
                )
            }
    )

    if ($unknownAliases.Count -gt 0) {
        throw (
            "SOURCE_ALIAS_UNKNOWN: config\source-aliases.json contiene fuentes " +
            "no importadas: " + ($unknownAliases -join ', ')
        )
    }

    Write-JsonNoBom -Path $sourceAliasPath -Value ([ordered]@{
        schema_version = "1.0"
        aliases = $sourceAliases
    })

    if ($aliasCollisions.Count -gt 0) {
        throw (
            "SOURCE_ALIAS_COLLISION: " +
            ($aliasCollisions -join '; ') +
            ". Ajuste config\source-aliases.json y vuelva a importar."
        )
    }

    $packageSourceIds = @(
        $packages |
            ForEach-Object { [string]$_.SourceId } |
            Sort-Object
    )
    $requiredSourceIds = @(
        $assistant.package_policy.required_source_ids |
            ForEach-Object { ([string]$_).Trim() } |
            Where-Object { -not [string]::IsNullOrWhiteSpace($_) } |
            Sort-Object
    )
    if ($requiredSourceIds.Count -gt 0 -and (
            $requiredSourceIds.Count -ne $packageSourceIds.Count -or
            ($requiredSourceIds -join "|") -cne
                ($packageSourceIds -join "|"))) {
        throw (
            "Los source_id deben ser exactamente: " +
            ($requiredSourceIds -join ", ") + ". Detectados: " +
            ($packageSourceIds -join ", ") + "."
        )
    }
    if ([bool]$assistant.package_policy.require_identity_scope_all_for_production) {
        $invalidScopes = @(
            $packages |
                Where-Object { $_.IdentityScope -ne "all" }
        )
        if ($invalidScopes.Count -gt 0) {
            throw (
                "La política exige identity_scope=all. Incumplen: " +
                (($invalidScopes | ForEach-Object { $_.SourceId }) -join ", ")
            )
        }
    }

    # Todos los ZIP ya fueron verificados antes de invalidar planes derivados.
    # Esta ruta solo es alcanzable antes de cualquier escritura registrada.
    foreach ($relative in @(
        "exports\phase1",
        "exports\phase2",
        "exports\phase3",
        "exports\phase4",
        "exports\phase5",
        "exports\phase6",
        "exports\phase7",
        "exports\phase8"
    )) {
        $path = Join-Path $ProjectRoot $relative
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Recurse -Force
        }
    }
    foreach ($relative in @(
        "reports\configuration-confirmation.json",
        "reports\assistant-state.json"
    )) {
        $path = Join-Path $ProjectRoot $relative
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Remove-Item -LiteralPath $path -Force
        }
    }

    $yamlLines = New-Object System.Collections.Generic.List[string]
    $yamlLines.Add("version: 1")
    $yamlLines.Add("project_name: " + (Quote-Yaml "consolidacion-moodle-paquetes"))
    $yamlLines.Add("mode: production")
    $yamlLines.Add("")
    $yamlLines.Add("sources:")
    foreach ($package in $packages) {
        $url = if ([string]::IsNullOrWhiteSpace($package.SourceUrl)) {
            "https://source.invalid/$($package.SourceId)"
        } else {
            $package.SourceUrl
        }
        $yamlLines.Add("  - id: " + $package.SourceId)
        $yamlLines.Add("    name: " + (Quote-Yaml $package.SourceName))
        $yamlLines.Add("    service: package-" + $package.SourceId)
        $yamlLines.Add("    url: " + (Quote-Yaml $url))
    }
    $yamlLines.Add("")
    $yamlLines.Add("target:")
    $yamlLines.Add("  id: " + [string]$target.id)
    $yamlLines.Add("  name: " + (Quote-Yaml ([string]$target.name)))
    $yamlLines.Add("  service: " + [string]$target.service)
    $yamlLines.Add("  url: " + (Quote-Yaml ([string]$target.url)))
    $configPath = Join-Path $ProjectRoot "config.yaml"
    Write-Utf8NoBom `
        -Path $configPath `
        -Content (($yamlLines -join "`r`n") + "`r`n")
    $configSha = (
        Get-FileHash -LiteralPath $configPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()

    $finalPackages = Join-Path $ProjectRoot "exports\packages"
    if (Test-Path -LiteralPath $finalPackages) {
        Remove-Item -LiteralPath $finalPackages -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $finalPackages | Out-Null
    foreach ($package in $packages) {
        Move-Item `
            -LiteralPath $package.PackageRoot `
            -Destination (Join-Path $finalPackages $package.SourceId)
        $package.PackageRoot = Join-Path $finalPackages $package.SourceId
    }

    $phase1 = Join-Path $ProjectRoot "exports\phase1"
    $phase3 = Join-Path $ProjectRoot "exports\phase3"
    $phase6 = Join-Path $ProjectRoot "exports\phase6"
    foreach ($directory in @($phase1, $phase3, $phase6)) {
        New-Item -ItemType Directory -Force -Path $directory | Out-Null
    }
    Get-ChildItem -LiteralPath $phase3 -Filter "identity-*.json" `
        -File -ErrorAction SilentlyContinue |
        Remove-Item -Force
    Get-ChildItem -LiteralPath $phase6 -Filter "source-inventory-*.json" `
        -File -ErrorAction SilentlyContinue |
        Remove-Item -Force

    $indexRows = New-Object System.Collections.Generic.List[object]
    $pilotCandidates = New-Object System.Collections.Generic.List[object]
    foreach ($package in $packages) {
        $root = $package.PackageRoot
        Copy-Item `
            -LiteralPath (Join-Path $root "identidades.json") `
            -Destination (Join-Path $phase3 "identity-$($package.SourceId).json") `
            -Force
        $inventory = Read-JsonFile `
            (Join-Path $root "inventario-origen.json") `
            "inventario del origen $($package.SourceId)"
        $inventory.config_sha256 = $configSha
        Write-JsonNoBom `
            -Path (Join-Path $phase6 `
                "source-inventory-$($package.SourceId).json") `
            -Value $inventory

        foreach ($entry in @($package.Manifest.entries)) {
            $detail = Read-JsonFile `
                (Join-Path $root ([string]$entry.inventory_file)) `
                "inventario detallado de $($entry.course_key)"
            $counts = $detail.inventory.counts
            $score = (
                1000 * [int]$counts.activities +
                100 * [int]$counts.enrolments +
                25 * [int]$counts.assignment_submissions +
                25 * [int]$counts.forum_posts +
                25 * [int]$counts.quiz_attempts +
                10 * [int]$counts.module_files
            )
            [void]$pilotCandidates.Add([pscustomobject]@{
                source_id = $package.SourceId
                course_key = [string]$entry.course_key
                source_course_id = [int]$entry.source_course_id
                source_course_idnumber = [string]$entry.source_course_idnumber
                source_shortname = [string]$entry.source_shortname
                score = $score
            })
        }
        [void]$indexRows.Add([pscustomobject]@{
            source_id = $package.SourceId
            source_name = $package.SourceName
            source_url = $package.SourceUrl
            moodle_version = $package.MoodleVersion
            moodle_release = $package.MoodleRelease
            identity_scope = $package.IdentityScope
            courses = $package.Courses
            zip_file = [System.IO.Path]::GetFileName($package.ZipPath)
            zip_sha256 = $package.ZipSha256
            zip_integrity_mode = $package.ZipIntegrityMode
            zip_hashed_again = $package.ZipHashedAgain
            zip_sidecar_file = [System.IO.Path]::GetFileName($package.ZipSidecar)
            manifest_sha256 = $package.ManifestSha256
            imported_path = "exports/packages/$($package.SourceId)"
        })
    }

    $resolutionsPath = Join-Path $ProjectRoot `
        "config\identity_resolutions.csv"

    $preferredSource = [string]$assistant.pilot.source_id
    $preferredCourseKey = [string]$assistant.pilot.course_key
    [object[]]$candidates = $pilotCandidates.ToArray()
    if ($preferredSource -ne "" -and $preferredSource -ne "auto") {
        $candidates = @(
            $candidates |
                Where-Object { $_.source_id -eq $preferredSource }
        )
    }
    if ($preferredCourseKey -ne "" -and $preferredCourseKey -ne "auto") {
        $candidates = @(
            $candidates |
                Where-Object { $_.course_key -eq $preferredCourseKey }
        )
    }
    if ($candidates.Count -lt 1) {
        throw "La selección del piloto en assistant.json no coincide con ningún curso."
    }
    $pilot = $candidates |
        Sort-Object -Property @(
            @{ Expression = { [int64]$_.score }; Descending = $true }
            "source_id"
            "source_course_id"
        ) |
        Select-Object -First 1
    $pilotCategory = [int]$assistant.pilot.target_category_id
    if ($pilotCategory -lt 1) {
        $pilotCategory = [int]$target.parent_category_id
    }
    Write-JsonNoBom `
        -Path (Join-Path $ProjectRoot "config\phase5-pilot-package.json") `
        -Value ([ordered]@{
            schema_version = "1.0"
            source_id = [string]$pilot.source_id
            source_alias = [string]$sourceAliases[[string]$pilot.source_id]
            course_key = [string]$pilot.course_key
            source_course_id = [int]$pilot.source_course_id
            source_course_idnumber = [string]$pilot.source_course_idnumber
            source_shortname = [string]$pilot.source_shortname
            target_category_id = $pilotCategory
            selection = "highest_evidence_score"
        })

    $combined = ($indexRows | ForEach-Object {
        "$($_.source_id)|$($_.zip_sha256)"
    }) -join "`n"
    $batchToken = (Get-StringSha256 $combined).Substring(0, 12)
    Write-JsonNoBom `
        -Path (Join-Path $ProjectRoot "config\phase6-batch.json") `
        -Value ([ordered]@{
            schema_version = "1.0"
            batch_id = "package-batch-$batchToken"
            target_parent_category_id = [int]$target.parent_category_id
            exclude_verified_phase5_pilot = $true
            sources = @($packages | ForEach-Object { $_.SourceId })
            source_aliases = $sourceAliases
            selection = [ordered]@{
                mode = "all_non_site_courses"
                include_hidden = [bool]$assistant.selection.include_hidden
            }
            role_policy = [ordered]@{
                student = "student"
                teacher = "editingteacher"
                editingteacher = "editingteacher"
                manager = "manager"
                fallback = "personalizado"
                preserve_site_admins_separately = $true
                personalizado_safety = [ordered]@{
                    assignable_context = "course_only"
                    profile = "student_readonly"
                    allow_content_view = $true
                    deny_content_mutation = $true
                    deny_grading = $true
                    deny_enrolment_and_roles = $true
                    deny_backup_restore = $true
                    deny_configuration = $true
                }
            }
        })

    $summary = [ordered]@{
        schema_version = "1.0"
        phase = "1-source-package-import"
        generated_at_utc = [DateTime]::UtcNow.ToString("o")
        config_sha256 = $configSha
        target_id = [string]$target.id
        sources = $indexRows.Count
        courses = [int](($indexRows | Measure-Object courses -Sum).Sum)
        pilot_source_id = [string]$pilot.source_id
        pilot_course_key = [string]$pilot.course_key
        package_index = [object[]]$indexRows.ToArray()
        packages_verified = $true
        validation_scope = "sealed_contract_only"
        internal_payload_rehash_performed = $false
        zip_integrity_mode = $(if ($strictIntegrity) { 'strict_integrity' } else {
            'trusted_package_integrity'
        })
        zip_hashed_again = $strictIntegrity
        artificial_size_limit_applied = $false
        destination_write_performed = $false
        import_status = "passed"
    }
    Write-JsonNoBom `
        -Path (Join-Path $phase1 "package_index.json") `
        -Value $summary

    $indexCsv = Join-Path $phase1 "package_index.csv"
    $indexRows |
        Select-Object -Property @(
            "source_id"
            "source_name"
            "moodle_release"
            "identity_scope"
            "courses"
            "zip_file"
            "zip_sha256"
            "manifest_sha256"
            "imported_path"
        ) |
        Export-Csv -LiteralPath $indexCsv -NoTypeInformation -Encoding UTF8

    Write-Host ""
    Write-Host (
        "PACKAGE_IMPORT_OK sources=$($indexRows.Count) " +
        "courses=$($summary.courses) pilot=$($pilot.course_key) write=0"
    ) -ForegroundColor Green
    Write-Host "Configuración generada: $configPath" -ForegroundColor Cyan
    Write-Host "Índice: $phase1" -ForegroundColor Cyan
} finally {
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}
