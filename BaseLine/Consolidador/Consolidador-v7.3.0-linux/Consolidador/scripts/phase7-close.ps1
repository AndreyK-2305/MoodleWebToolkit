. "$PSScriptRoot/Common.ps1"

Assert-ConfigurationConfirmed

$phase3Host = Join-Path $ProjectRoot "exports\phase3"
$phase1Host = Join-Path $ProjectRoot "exports\phase1"
$phase2Host = Join-Path $ProjectRoot "exports\phase2"
$oauth2Host = Join-Path $ProjectRoot "exports\oauth2"
$phase4Host = Join-Path $ProjectRoot "exports\phase4"
$phase5Host = Join-Path $ProjectRoot "exports\phase5"
$phase6Host = Join-Path $ProjectRoot "exports\phase6"
$phase7Host = Join-Path $ProjectRoot "exports\phase7"
$reportsHost = Join-Path $ProjectRoot "reports"

function Read-RequiredJson {
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

function Get-LowerSha256 {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "No se puede calcular SHA-256; falta $Path."
    }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Assert-HashField {
    param(
        [object]$Document,
        [string]$Field,
        [string]$Path,
        [string]$Label
    )
    $expected = [string]$Document.$Field
    $actual = Get-LowerSha256 $Path
    if ($expected -notmatch '^[a-fA-F0-9]{64}$' -or
            $expected.ToLowerInvariant() -ne $actual) {
        throw "$Label perdió integridad en $Field."
    }
}

function Assert-ConfigAndTarget {
    param(
        [object]$Document,
        [string]$Label,
        [string]$ExpectedConfigSha,
        [string]$ExpectedTarget
    )
    if ([string]$Document.config_sha256 -ne $ExpectedConfigSha -or
            [string]$Document.target_id -ne $ExpectedTarget) {
        throw "$Label corresponde a otra configuración o destino."
    }
}

$paths = [ordered]@{
    p1_import = Join-Path $phase1Host "package_index.json"
    p2_plugins = Join-Path $phase2Host "plugin_compatibility.json"
    p2_plugins_csv = Join-Path $phase2Host "plugin_compatibility.csv"
    oauth2_validation = Join-Path $oauth2Host "validation.json"
    p4_plan = Join-Path $phase4Host "plan_summary.json"
    p4_target_plan = Join-Path $phase4Host "target_user_plan.csv"
    p4_apply = Join-Path $phase4Host "apply_summary.json"
    p4_verify = Join-Path $phase4Host "verification.json"
    p4_verify_csv = Join-Path $phase4Host "verification.csv"
    p4_target_map = Join-Path $phase4Host "target_user_map.csv"
    p4_source_map = Join-Path $phase4Host "source_to_target_user_map.csv"
    p5_plan = Join-Path $phase5Host "plan_summary.json"
    p5_apply = Join-Path $phase5Host "apply_summary.json"
    p5_verify = Join-Path $phase5Host "verification.json"
    p5_verify_csv = Join-Path $phase5Host "verification.csv"
    p5_course_map = Join-Path $phase5Host "pilot_course_map.csv"
    p5_target_inventory = Join-Path $phase5Host "target_course_inventory.json"
    p6_plan = Join-Path $phase6Host "plan_summary.json"
    p6_manifest = Join-Path $phase6Host "batch_manifest.json"
    p6_backup_progress = Join-Path $phase6Host "backup_progress.csv"
    p6_category_summary = Join-Path $phase6Host "category_apply_summary.json"
    p6_category_map = Join-Path $phase6Host "category_map.csv"
    p6_apply = Join-Path $phase6Host "batch_apply_summary.json"
    p6_course_map = Join-Path $phase6Host "course_map.csv"
    p6_verify = Join-Path $phase6Host "batch_verification.json"
    p6_verify_csv = Join-Path $phase6Host "batch_verification.csv"
}
foreach ($path in $paths.Values) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Falta la evidencia requerida $path."
    }
}

$configSha = Get-ConfigurationHash
$targetSite = Get-TargetSite
$targetId = [string]$targetSite.id
$mode = [string]$MigrationConfig.Mode

$p1Import = Read-RequiredJson $paths.p1_import "importación de paquetes"
$p2Plugins = Read-RequiredJson $paths.p2_plugins `
    "compatibilidad de plugins"
$oauth2Validation = Read-RequiredJson $paths.oauth2_validation `
    "validación de configuración OAuth2"
$p4Apply = Read-RequiredJson $paths.p4_apply "aplicación de fase 4"
$p4Verification = Read-RequiredJson $paths.p4_verify "verificación de fase 4"
$p5Apply = Read-RequiredJson $paths.p5_apply "aplicación de fase 5"
$p5Verification = Read-RequiredJson $paths.p5_verify "verificación de fase 5"
$p6Plan = Read-RequiredJson $paths.p6_plan "plan de fase 6"
$p6Manifest = Read-RequiredJson $paths.p6_manifest "manifiesto de fase 6"
$p6Category = Read-RequiredJson $paths.p6_category_summary "categorías de fase 6"
$p6Apply = Read-RequiredJson $paths.p6_apply "aplicación de fase 6"
$p6Verification = Read-RequiredJson $paths.p6_verify "verificación de fase 6"

Assert-ConfigAndTarget `
    -Document $p1Import `
    -Label "importación de paquetes" `
    -ExpectedConfigSha $configSha `
    -ExpectedTarget $targetId
if ([string]$p1Import.import_status -ne "passed" -or
        -not [bool]$p1Import.packages_verified -or
        [int]$p1Import.sources -lt 1 -or
        [int]$p1Import.courses -lt 1 -or
        [bool]$p1Import.destination_write_performed) {
    throw "La importación de paquetes no conserva un estado aprobado."
}
foreach ($package in @($p1Import.package_index)) {
    $manifestPath = Join-Path $ProjectRoot `
        "exports\packages\$($package.source_id)\manifest.json"
    $expectedManifestHash = [string]$package.manifest_sha256
    if ($expectedManifestHash -notmatch '^[a-fA-F0-9]{64}$' -or
            (Get-LowerSha256 $manifestPath) -ne
                $expectedManifestHash.ToLowerInvariant()) {
        throw "El manifiesto importado de '$($package.source_id)' cambió."
    }
}

Assert-ConfigAndTarget `
    -Document $p2Plugins `
    -Label "compatibilidad de plugins" `
    -ExpectedConfigSha $configSha `
    -ExpectedTarget $targetId
if ([string]$p2Plugins.status -ne "compatible" -or
        [int]$p2Plugins.blocking_issues -ne 0 -or
        [bool]$p2Plugins.destination_write_performed) {
    throw "La compatibilidad de plugins no conserva un estado aprobado."
}
Assert-HashField $p2Plugins "compatibility_csv_sha256" `
    $paths.p2_plugins_csv "La compatibilidad de plugins"

Assert-ConfigAndTarget `
    -Document $oauth2Validation `
    -Label "validación de configuración OAuth2" `
    -ExpectedConfigSha $configSha `
    -ExpectedTarget $targetId
$oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
    throw "Falta config\oauth2.json para validar el cierre OAuth2."
}
$oauthConfigHash = Get-LowerSha256 $oauthConfigPath
if ([string]$oauth2Validation.status -ne "ready" -or
        [string]$oauth2Validation.validation -ne "passed" -or
        [string]$oauth2Validation.oauth_config_sha256 -ne $oauthConfigHash -or
        [int]$oauth2Validation.issuer_id -lt 1 -or
        -not [bool]$oauth2Validation.issuer_enabled -or
        -not [bool]$oauth2Validation.show_on_login_page -or
        -not [bool]$oauth2Validation.client_credentials_present -or
        -not [bool]$oauth2Validation.auth_plugin_enabled -or
        [int]$oauth2Validation.endpoints_configured -lt 1 -or
        [bool]$oauth2Validation.destination_write_performed) {
    throw "Google OAuth2 ya no conserva la configuración manual aprobada."
}
$oauth2IssuerId = [int]$oauth2Validation.issuer_id

$contractDocuments = @(
    [pscustomobject]@{ Document = $p4Apply; Label = "aplicación de fase 4" }
    [pscustomobject]@{ Document = $p4Verification; Label = "verificación de fase 4" }
    [pscustomobject]@{ Document = $p5Apply; Label = "aplicación de fase 5" }
    [pscustomobject]@{ Document = $p5Verification; Label = "verificación de fase 5" }
    [pscustomobject]@{ Document = $p6Plan; Label = "plan de fase 6" }
    [pscustomobject]@{ Document = $p6Manifest; Label = "manifiesto de fase 6" }
    [pscustomobject]@{ Document = $p6Category; Label = "categorías de fase 6" }
    [pscustomobject]@{ Document = $p6Apply; Label = "aplicación de fase 6" }
    [pscustomobject]@{ Document = $p6Verification; Label = "verificación de fase 6" }
)
foreach ($item in $contractDocuments) {
    Assert-ConfigAndTarget `
        -Document $item.Document `
        -Label $item.Label `
        -ExpectedConfigSha $configSha `
        -ExpectedTarget $targetId
}

if (-not [bool]$p4Apply.apply_performed -or
        [int]$p4Apply.oauth2_issuer_id -ne $oauth2IssuerId -or
        [bool]$p4Apply.roles_applied -or
        [bool]$p4Apply.enrolments_applied -or
        [bool]$p4Apply.course_data_applied) {
    throw "La aplicación de fase 4 no conserva su alcance exclusivo de identidades."
}
Assert-HashField $p4Apply "plan_sha256" `
    $paths.p4_target_plan "La aplicación de fase 4"
Assert-HashField $p4Apply "target_user_map_sha256" `
    $paths.p4_target_map "La aplicación de fase 4"
Assert-HashField $p4Apply "source_to_target_user_map_sha256" `
    $paths.p4_source_map "La aplicación de fase 4"

if ([string]$p4Verification.validation -ne "passed" -or
        [int]$p4Verification.failed_checks -ne 0 -or
        [int]$p4Verification.oauth2_issuer_id -ne $oauth2IssuerId -or
        [int]$p4Verification.oauth2_links_failed -ne 0 -or
        [int]$p4Verification.oauth2_links_verified -ne
            [int]$p4Verification.oauth2_links_expected -or
        [int]$p4Apply.oauth2_links_materialized -ne
            [int]$p4Verification.oauth2_links_expected -or
        [bool]$p4Verification.roles_applied -or
        [bool]$p4Verification.enrolments_applied) {
    throw "La fase 4 no conserva una verificación aprobada y limitada a identidades."
}
Assert-HashField $p4Verification "plan_sha256" `
    $paths.p4_target_plan "La verificación de fase 4"
Assert-HashField $p4Verification "target_user_map_sha256" `
    $paths.p4_target_map "La verificación de fase 4"
Assert-HashField $p4Verification "source_to_target_user_map_sha256" `
    $paths.p4_source_map "La verificación de fase 4"
Assert-HashField $p4Verification "verification_csv_sha256" `
    $paths.p4_verify_csv "La verificación de fase 4"

if ([string]$p5Verification.validation -ne "passed" -or
        [int]$p5Verification.failed_checks -ne 0 -or
        -not [bool]$p5Verification.course_data_applied -or
        -not [bool]$p5Verification.roles_applied -or
        -not [bool]$p5Verification.enrolments_applied) {
    throw "La fase 5 no conserva una verificación piloto aprobada."
}
Assert-HashField $p5Verification "plan_summary_sha256" `
    $paths.p5_plan "La verificación de fase 5"
Assert-HashField $p5Verification "apply_summary_sha256" `
    $paths.p5_apply "La verificación de fase 5"
Assert-HashField $p5Verification "pilot_course_map_sha256" `
    $paths.p5_course_map "La verificación de fase 5"
Assert-HashField $p5Verification "target_course_inventory_sha256" `
    $paths.p5_target_inventory "La verificación de fase 5"
Assert-HashField $p5Verification "verification_csv_sha256" `
    $paths.p5_verify_csv "La verificación de fase 5"

if ([string]$p6Plan.plan_status -ne "applicable" -or
        [int]$p6Plan.blocking_conflicts -ne 0 -or
        [int]$p6Plan.blocked_categories -ne 0 -or
        [int]$p6Plan.blocked_courses -ne 0 -or
        [int]$p6Plan.blocked_identity_convergences -ne 0) {
    throw "El plan de fase 6 no conserva el estado applicable sin bloqueos."
}
if ([string]$p6Manifest.manifest_status -ne "prepared" -or
        [int]$p6Manifest.courses_pending -ne 0) {
    throw "El manifiesto de fase 6 no conserva el estado prepared."
}
Assert-HashField $p6Manifest "plan_summary_sha256" `
    $paths.p6_plan "El manifiesto de fase 6"
Assert-HashField $p6Manifest "backup_progress_sha256" `
    $paths.p6_backup_progress "El manifiesto de fase 6"

$manifestSha = Get-LowerSha256 $paths.p6_manifest
if ([string]$p6Category.manifest_sha256 -ne $manifestSha -or
        [string]$p6Category.apply_status -ne "applied") {
    throw "La jerarquía aplicada no corresponde al manifiesto sellado."
}
Assert-HashField $p6Category "category_map_sha256" `
    $paths.p6_category_map "La jerarquía de fase 6"

if ([string]$p6Apply.manifest_sha256 -ne $manifestSha -or
        [string]$p6Apply.apply_status -ne
            "applied_pending_batch_verification" -or
        [int]$p6Apply.courses_pending -ne 0 -or
        -not [bool]$p6Apply.destination_write_performed -or
        -not [bool]$p6Apply.courses_restored) {
    throw "La aplicación de fase 6 no corresponde al manifiesto completo."
}
Assert-HashField $p6Apply "category_apply_summary_sha256" `
    $paths.p6_category_summary "La aplicación de fase 6"
Assert-HashField $p6Apply "category_map_sha256" `
    $paths.p6_category_map "La aplicación de fase 6"
Assert-HashField $p6Apply "course_map_sha256" `
    $paths.p6_course_map "La aplicación de fase 6"

if ([string]$p6Verification.manifest_sha256 -ne $manifestSha -or
        [string]$p6Verification.validation -ne "passed" -or
        [int]$p6Verification.failed_courses -ne 0 -or
        -not [bool]$p6Verification.pilot_preserved -or
        [string]$p6Verification.personalizado_profile -ne
            "student_readonly") {
    throw "La fase 6 no conserva una verificación consolidada aprobada."
}
Assert-HashField $p6Verification "batch_apply_summary_sha256" `
    $paths.p6_apply "La verificación de fase 6"
Assert-HashField $p6Verification "course_map_sha256" `
    $paths.p6_course_map "La verificación de fase 6"
Assert-HashField $p6Verification "verification_csv_sha256" `
    $paths.p6_verify_csv "La verificación de fase 6"

$courses = [int]$p6Plan.courses_to_restore
if ($courses -lt 1 -or
        [int]$p6Manifest.courses_expected -ne $courses -or
        [int]$p6Manifest.courses_prepared -ne $courses -or
        [int]$p6Apply.courses_expected -ne $courses -or
        [int]$p6Apply.courses_applied -ne $courses -or
        [int]$p6Verification.courses_expected -ne $courses -or
        [int]$p6Verification.courses_verified -ne $courses) {
    throw "Los conteos de cursos no convergen entre plan, manifiesto, aplicación y verificación."
}
$pilotId = [int]$p6Verification.pilot_course_id
if ($pilotId -lt 1 -or $pilotId -ne [int]$p5Verification.target_course_id) {
    throw "El curso piloto no coincide entre las fases 5 y 6."
}
$totalCoursesVerified = $courses + 1

if ($mode -eq "lab") {
    foreach ($document in @(
        $p4Verification,
        $p5Verification,
        $p6Plan,
        $p6Manifest,
        $p6Apply,
        $p6Verification
    )) {
        if ([string]$document.lab_validation -ne "passed") {
            throw "Una fase no conserva lab_validation=passed."
        }
    }
    if ($courses -lt 1 -or
            $totalCoursesVerified -ne [int]$p1Import.courses) {
        throw (
            "El cierre LAB no conserva el total de cursos importado y " +
            "verificado por el plan firmado."
        )
    }
}

New-Item -ItemType Directory -Force -Path $phase7Host | Out-Null
$manifestPath = Join-Path $phase7Host "evidence_manifest.csv"
$reportPath = Join-Path $phase7Host "informe-final-migracion.md"
$summaryPath = Join-Path $phase7Host "closure_summary.json"
$archivePath = Join-Path $phase7Host "fase-7-cierre-migracion.zip"
$archiveHashPath = Join-Path $phase7Host "fase-7-cierre-migracion.sha256.txt"
$consoleReport = Join-Path $reportsHost "fase-7-cierre-migracion.txt"

$evidenceFiles = New-Object 'System.Collections.Generic.List[System.IO.FileInfo]'
[void]$evidenceFiles.Add((Get-Item -LiteralPath $ConfigPath))
foreach ($directory in @(
    (Join-Path $ProjectRoot "config"),
    $phase1Host,
    $phase2Host,
    $oauth2Host,
    $phase3Host,
    $phase4Host,
    $phase5Host,
    $phase6Host,
    $reportsHost
)) {
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        continue
    }
    foreach ($file in Get-ChildItem -LiteralPath $directory -File) {
        if ($file.FullName -ne $consoleReport -and
                $file.Extension.ToLowerInvariant() -in @(
            ".json", ".csv", ".txt", ".md", ".yaml", ".yml"
        )) {
            [void]$evidenceFiles.Add($file)
        }
    }
}
foreach ($sourceId in @(Get-SourceSiteNames)) {
    $packageDirectory = Join-Path $ProjectRoot "exports\packages\$sourceId"
    foreach ($fileName in @(
        "manifest.json",
        "checksums.sha256",
        "plugins.json"
    )) {
        $packageEvidence = Join-Path $packageDirectory $fileName
        if (Test-Path -LiteralPath $packageEvidence -PathType Leaf) {
            [void]$evidenceFiles.Add((Get-Item -LiteralPath $packageEvidence))
        }
    }
}
$evidenceRows = @(
    $evidenceFiles |
    Sort-Object FullName -Unique |
    ForEach-Object {
        $relative = $_.FullName.Substring($ProjectRoot.Length) -replace
            '^[\\/]+', ''
        $sensitivity = if ($relative -match
                '^(exports[\\/](phase[1-6]|oauth2|packages)|config[\\/]).*\.(csv|json|sha256)$') {
            "institutional_sensitive"
        } else {
            "operational"
        }
        [pscustomobject][ordered]@{
            relative_path = $relative
            bytes = [int64]$_.Length
            sha256 = Get-LowerSha256 $_.FullName
            sensitivity = $sensitivity
        }
    }
)
$evidenceRows | Export-Csv `
    -LiteralPath $manifestPath `
    -NoTypeInformation `
    -Encoding UTF8

$generatedAt = [DateTime]::UtcNow.ToString("o")
$sources = @($p6Plan.selected_sources | ForEach-Object { [string]$_ })
$closureStatus = if ($mode -eq "lab") {
    "lab_validated"
} else {
    "evidence_consolidated"
}
$reportLines = @(
    "# Informe final de consolidación Moodle",
    "",
    "- Fecha UTC: $generatedAt",
    "- Proyecto: $($MigrationConfig.ProjectName)",
    "- Modo: $mode",
    "- Destino: $targetId",
    "- Fuentes: $($sources -join ', ')",
    "- Paquetes de origen verificados: $([int]$p1Import.sources)",
    "- Compatibilidad de plugins: $([string]$p2Plugins.status)",
    "- Advertencias de plugins: $([int]$p2Plugins.warnings)",
    "- Google OAuth2 listo; issuer ID del destino: $oauth2IssuerId",
    "- Estado de cierre: $closureStatus",
    "",
    "## Resultado verificado",
    "",
    "- Usuarios canónicos verificados en fase 4: $([int]$p4Verification.target_users_mapped)",
    "- Vínculos nativos Google OAuth2 verificados: $([int]$p4Verification.oauth2_links_verified)",
    "- Curso piloto conservado: $pilotId",
    "- Cursos del lote preparados, aplicados y verificados: $courses",
    "- Total de cursos verificados, incluido el piloto: $totalCoursesVerified",
    "- Cursos con diferencias: $([int]$p6Verification.failed_courses)",
    "- Matrículas efectivas: $([int]$p6Apply.effective_enrolments)",
    "- Roles efectivos: $([int]$p6Apply.effective_roles)",
    "- Convergencias de identidad aprobadas: $([int]$p6Manifest.approved_identity_convergences)",
    "- Perfil de rol personalizado: $([string]$p6Verification.personalizado_profile)",
    "- MBZ de origen referenciados sin copia: $([int]$p6Manifest.source_backups_referenced)",
    "- Bytes de MBZ referenciados: $([int64]$p6Manifest.source_backup_bytes)",
    "- Bytes duplicados por fase 6: $([int64]$p6Manifest.duplicate_backup_bytes)",
    "",
    "## Integridad y alcance",
    "",
    "El cierre revalidó la cadena de hashes entre las fases 4, 5 y 6, sus mapas,",
    "resúmenes y verificaciones. No restauró cursos, no modificó usuarios, roles,",
    "matrículas ni contenido de Moodle. Los SHA declarados por el Recolector",
    "permanecen sellados en batch_manifest.json y backup_progress.csv;",
    "este cierre no vuelve a leer cada archivo .mbz.",
    "",
    "evidence_manifest.csv registra los archivos de evidencia de nivel superior.",
    "Los archivos marcados institutional_sensitive deben conservarse con los",
    "controles de acceso y retención definidos por la institución.",
    "",
    "## Interpretación",
    "",
    $(if ($mode -eq "lab") {
        "Este resultado acredita el laboratorio configurado; no acredita por sí solo una migración productiva."
    } else {
        "Este resultado consolida la evidencia técnica; la aceptación productiva continúa sujeta a la aprobación institucional."
    })
)
$reportLines | Set-Content -LiteralPath $reportPath -Encoding UTF8

$closureSummary = [ordered]@{
    schema_version = "1.0"
    phase = "7-migration-evidence-closure"
    generated_at_utc = $generatedAt
    config_sha256 = $configSha
    project_name = [string]$MigrationConfig.ProjectName
    mode = $mode
    target_id = $targetId
    sources = $sources
    source_packages_verified = [int]$p1Import.sources
    source_package_courses = [int]$p1Import.courses
    plugin_compatibility_status = [string]$p2Plugins.status
    plugin_compatibility_warnings = [int]$p2Plugins.warnings
    oauth2_validation = [string]$oauth2Validation.validation
    oauth_config_sha256 = $oauthConfigHash
    oauth2_issuer_id = $oauth2IssuerId
    oauth2_links_verified = [int]$p4Verification.oauth2_links_verified
    oauth2_links_failed = [int]$p4Verification.oauth2_links_failed
    phase4_validation = [string]$p4Verification.validation
    canonical_users_verified = [int]$p4Verification.target_users_mapped
    phase5_validation = [string]$p5Verification.validation
    pilot_course_id = $pilotId
    pilot_preserved = $true
    phase6_plan_status = [string]$p6Plan.plan_status
    phase6_manifest_status = [string]$p6Manifest.manifest_status
    phase6_apply_status = [string]$p6Apply.apply_status
    phase6_validation = [string]$p6Verification.validation
    courses_verified = $courses
    pilot_courses_verified = 1
    total_courses_verified = $totalCoursesVerified
    failed_courses = [int]$p6Verification.failed_courses
    effective_enrolments = [int]$p6Apply.effective_enrolments
    effective_roles = [int]$p6Apply.effective_roles
    approved_identity_convergences =
        [int]$p6Manifest.approved_identity_convergences
    personalizado_profile = [string]$p6Verification.personalizado_profile
    source_backups_referenced = [int]$p6Manifest.source_backups_referenced
    source_backup_bytes = [int64]$p6Manifest.source_backup_bytes
    duplicate_backup_bytes = [int64]$p6Manifest.duplicate_backup_bytes
    evidence_files_indexed = $evidenceRows.Count
    evidence_manifest_sha256 = Get-LowerSha256 $manifestPath
    report_sha256 = Get-LowerSha256 $reportPath
    backup_rehash_performed = $false
    moodle_write_performed = $false
    closure_status = $closureStatus
}
$closureSummary |
    ConvertTo-Json -Depth 10 |
    Set-Content -LiteralPath $summaryPath -Encoding UTF8

if (Test-Path -LiteralPath $archivePath -PathType Leaf) {
    Remove-Item -LiteralPath $archivePath -Force
}
Compress-Archive `
    -LiteralPath @($summaryPath, $manifestPath, $reportPath) `
    -DestinationPath $archivePath `
    -CompressionLevel Optimal
$archiveSha = Get-LowerSha256 $archivePath
"$archiveSha  $([System.IO.Path]::GetFileName($archivePath))" |
    Set-Content -LiteralPath $archiveHashPath -Encoding ASCII

$consoleLines = @(
    (
        "FASE7_CLOSURE_OK mode=$mode courses=$courses " +
        "total=$totalCoursesVerified failed=0 pilot=$pilotId write=0"
    ),
    "",
    "FASE 7 cerrada sin modificar Moodle.",
    "Estado: $closureStatus.",
    "Informe: $reportPath",
    "Resumen: $summaryPath",
    "Manifiesto: $manifestPath",
    "Paquete: $archivePath",
    "SHA-256: $archiveSha",
    "Los CSV y JSON de identidades pueden contener datos institucionales sensibles."
)
$consoleLines | Tee-Object -FilePath $consoleReport
