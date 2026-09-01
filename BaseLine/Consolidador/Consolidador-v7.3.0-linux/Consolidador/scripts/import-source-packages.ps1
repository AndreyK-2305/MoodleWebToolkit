$ErrorActionPreference = "Stop"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8NoBom
[Console]::OutputEncoding = $utf8NoBom
$OutputEncoding = $utf8NoBom

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

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
        [int]$MaximumEntries
    )

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
                $sourceId -notmatch '^[a-z][a-z0-9_-]*$' -or
                [string]::IsNullOrWhiteSpace(
                    [string]$manifest.source_name
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

        $packageRoot = Join-Path $StagingRoot $sourceId
        New-Item -ItemType Directory -Force -Path $packageRoot | Out-Null
        $packageRootFull = [System.IO.Path]::GetFullPath($packageRoot)
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
            $inputStream = $entry.Open()
            try {
                $outputStream = [System.IO.File]::Open(
                    $destination,
                    [System.IO.FileMode]::CreateNew,
                    [System.IO.FileAccess]::Write,
                    [System.IO.FileShare]::None
                )
                try {
                    $inputStream.CopyTo($outputStream)
                } finally {
                    $outputStream.Dispose()
                }
            } finally {
                $inputStream.Dispose()
            }
        }

        return [pscustomobject]@{
            SourceId = $sourceId
            SourceName = [string]$manifest.source_name
            SourceUrl = [string]$manifest.source_wwwroot
            MoodleVersion = [string]$manifest.source_moodle_version
            MoodleRelease = [string]$manifest.source_moodle_release
            IdentityScope = [string]$manifest.identity_scope
            Courses = [int]$manifest.courses_expected
            ZipPath = $ZipPath
            ZipSha256 = (Get-FileHash `
                -LiteralPath $ZipPath `
                -Algorithm SHA256).Hash.ToLowerInvariant()
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
        $package = Expand-ValidatedSourcePackage `
            -ZipPath $zip.FullName `
            -StagingRoot $stagingRoot `
            -MaximumEntries $maximumEntries
        if ($sourceIds.ContainsKey($package.SourceId) -or
                $package.SourceId -eq [string]$target.id) {
            throw "El source_id '$($package.SourceId)' está repetido o coincide con el destino."
        }
        $sourceIds[$package.SourceId] = $true
        [void]$packages.Add($package)
    }
    # New-Object List[object] + @($list) puede lanzar
    # "Argument types do not match" tanto en Windows PowerShell 5.1 como en
    # PowerShell 7. Convierta de forma explícita antes de usar operadores de
    # colección; los paquetes de entrada y sus hashes no cambian.
    [object[]]$packageArray = $packages.ToArray()
    $packages = @($packageArray | Sort-Object SourceId)

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
