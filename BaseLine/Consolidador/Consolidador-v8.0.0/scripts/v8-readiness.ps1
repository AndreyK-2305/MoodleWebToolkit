param(
    [ValidateSet('Prepare', 'Check')]
    [string]$Mode = 'Prepare',
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"
$targetSite = Get-TargetSite

$engine = Join-Path $ProjectRoot 'scripts/v8-preparation.php'
$exports = Join-Path $ProjectRoot 'exports'
$candidates = Join-Path $exports 'identity_candidates.csv'
$candidateSummary = Join-Path $exports 'identity-candidates-summary.json'
$themePlan = Join-Path $exports 'theme-plan.json'
$themeInventoryStatus = Join-Path $exports 'theme-inventory-status.json'
$readiness = Join-Path $exports 'readiness.json'
$lock = Join-Path $exports 'plugin-lock.json'
$catalog = Join-Path $ProjectRoot 'config/plugin-compatibility-catalog.json'
$themePolicy = Join-Path $ProjectRoot 'config/theme-policy.json'
$phase3 = Join-Path $exports 'phase3/summary.json'
$sourceMap = Join-Path $exports 'phase3/source_user_map.csv'
$fuzzyAudit = Join-Path $exports 'phase3/fuzzy_identity_resolution_audit.csv'
$identityReview = Join-Path $exports 'identity_candidate_review.csv'
$phase4 = Join-Path $exports 'phase4/plan_summary.json'
$oauth = Join-Path $exports 'oauth2-live/validation.json'
$targetInventory = Join-Path $exports 'phase2/plugin_inventory_after.json'
$managedSettings = '/managed-config-host/active/settings.json'

function File-Hash([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Read-Document([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    try { return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json }
    catch { return $null }
}

function Save-ThemePolicy([object]$Document) {
    $json = ($Document | ConvertTo-Json -Depth 30) + [Environment]::NewLine
    [System.IO.File]::WriteAllText($themePolicy, $json,
        (New-Object System.Text.UTF8Encoding($false)))
}

function Test-V8ArtifactSeals {
    $theme = Read-Document $themePlan
    $identity = Read-Document $candidateSummary
    if ($null -eq $theme -or $null -eq $identity -or
            [string]$identity.identity_candidates_sha256 -cne (File-Hash $candidates) -or
            [string]$identity.source_user_map_sha256 -cne (File-Hash $sourceMap) -or
            [string]$identity.fuzzy_resolution_audit_sha256 -cne (File-Hash $fuzzyAudit)) {
        return $false
    }
    foreach ($property in $theme.theme_profile_hashes.PSObject.Properties) {
        $path = Join-Path $exports ("theme-profiles/" + $property.Name + '.json')
        if ([string]$property.Value -cne (File-Hash $path)) { return $false }
    }
    foreach ($property in $theme.theme_assignment_hashes.PSObject.Properties) {
        $path = Join-Path $exports ("theme-assignments/" + $property.Name)
        if ([string]$property.Value -cne (File-Hash $path)) { return $false }
    }
    return $true
}

function Test-ReadyDocument {
    $document = Read-Document $readiness
    if ($null -eq $document -or [string]$document.status -ne 'READY_TO_RUN' -or
            -not [bool]$document.ready_to_run -or [bool]$document.destination_write_performed) {
        return $false
    }
    $bindings = [ordered]@{
        lock_sha256 = $lock
        phase3_sha256 = $phase3
        phase4_sha256 = $phase4
        oauth_sha256 = $oauth
        theme_sha256 = $themePlan
        identity_sha256 = $candidateSummary
        catalog_sha256 = $catalog
        policy_sha256 = $themePolicy
        config_sha256 = $ConfigPath
        managed_sha256 = $managedSettings
        target_inventory_sha256 = $targetInventory
    }
    foreach ($field in $bindings.Keys) {
        if ([string]$document.sealed_inputs.$field -cne (File-Hash $bindings[$field])) {
            return $false
        }
    }
    foreach ($property in $document.checks.PSObject.Properties) {
        if (-not [bool]$property.Value) { return $false }
    }
    return (Test-V8ArtifactSeals)
}

if ($Mode -eq 'Check') {
    if (-not (Test-ReadyDocument)) { throw 'V8_READINESS_INVALID' }
    Write-Output (Read-Document $readiness)
    return
}

if (Test-Path -LiteralPath $DestinationWriteLockPath -PathType Leaf) {
    throw 'V8_READINESS_REGENERATION_BLOCKED: el destino ya recibió escrituras.'
}
foreach ($required in @($engine, $lock, $catalog, $themePolicy, $phase3, $phase4,
        $sourceMap, $fuzzyAudit, $oauth, $targetInventory, $ConfigPath,
        $managedSettings)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "V8_READINESS_INPUT_MISSING: $required"
    }
}
$managed = Read-Document $managedSettings
$themeConfiguration = Read-Document $themePolicy
$targetPlugins = Read-Document $targetInventory
if ($null -eq $managed -or $null -eq $themeConfiguration -or $null -eq $targetPlugins) {
    throw 'V8_THEME_TARGET_POLICY_INVALID: faltan configuración o inventario del destino.'
}

& php $engine '--mode=identities' "--input=$(Join-Path $exports 'phase3')" `
    "--source-map=$sourceMap" "--fuzzy-audit=$fuzzyAudit" '--review=pending' `
    "--output=$candidates" "--summary=$candidateSummary"
if ($LASTEXITCODE -ne 0) { throw 'V8_IDENTITY_CANDIDATES_FAILED' }
$identitySummary = Read-Document $candidateSummary
if ([int]$identitySummary.candidate_count -gt 0) {
    $choice = 'S'
    if (-not $NonInteractive) {
        Write-Host ''
        Write-Host 'CANDIDATOS FUZZY DE IDENTIDAD' -ForegroundColor Cyan
        Write-Host "Se generaron $($identitySummary.candidate_count) candidatos distintos de los canonicales ya resueltos."
        Write-Host '[P] Preparar archivo de revisión'
        if (Test-Path -LiteralPath $identityReview -PathType Leaf) {
            Write-Host '[I] Importar revisión completada'
        }
        Write-Host '[S] Salir sin aplicar cambios fuzzy'
        $choice = ([string](Read-Host 'Seleccione P, I o S')).Trim().ToUpperInvariant()
        if ($choice -notin @('P','I','S')) { throw 'V8_IDENTITY_REVIEW_CHOICE_INVALID' }
    }
    if ($choice -eq 'P') {
        if (Test-Path -LiteralPath $identityReview -PathType Leaf) {
            Remove-Item -LiteralPath $identityReview -Force
        }
        & "$PSScriptRoot/v8-identity-review.ps1" -Mode Prepare
        throw (
            'V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_PREPARED; ' +
            'WAITING_USER_ACTION. Edite exports/identity_candidate_review.csv ' +
            'y luego ejecute ./INICIAR-CONSOLIDACION.sh.'
        )
    }
    if ($choice -eq 'I') {
        if (-not (Test-Path -LiteralPath $identityReview -PathType Leaf)) {
            throw 'V8_IDENTITY_REVIEW_FILE_MISSING'
        }
        & "$PSScriptRoot/v8-identity-review.ps1" -Mode Apply
        throw (
            'V8_IDENTITY_REVIEW_IMPORTED: revisión fuzzy válida; ' +
            'reiniciando el flujo para recalcular conciliación y plan por hashes.'
        )
    }
    & php $engine '--mode=identities' "--input=$(Join-Path $exports 'phase3')" `
        "--source-map=$sourceMap" "--fuzzy-audit=$fuzzyAudit" '--review=skipped' `
        "--output=$candidates" "--summary=$candidateSummary"
    if ($LASTEXITCODE -ne 0) { throw 'V8_IDENTITY_CANDIDATES_FAILED' }
}

$availableThemes = @{}
foreach ($plugin in @($targetPlugins.plugins)) {
    $component = [string]$plugin.component
    if ($component.StartsWith('theme_') -and
            [string]$plugin.installation_status -ne 'missing') {
        $availableThemes[$component.Substring(6)] = $true
    }
}
foreach ($plugin in @((Read-Document $lock).plugins)) {
    $component = [string]$plugin.component
    if ($component.StartsWith('theme_') -and
            [string]$plugin.staging_validation -eq 'passed') {
        $availableThemes[$component.Substring(6)] = $true
    }
}
$selectedTheme = [string]$themeConfiguration.global_theme_selection
$selectionSource = [string]$themeConfiguration.selection_source
if (-not [string]::IsNullOrWhiteSpace($selectedTheme) -and
        [string]::IsNullOrWhiteSpace($selectionSource)) {
    $themeConfiguration.selection_source = 'config'
    $selectionSource = 'config'
    Save-ThemePolicy $themeConfiguration
} elseif (-not [string]::IsNullOrWhiteSpace($selectedTheme) -and
        $selectionSource -notin @('operator','config')) {
    throw 'V8_THEME_SELECTION_SOURCE_INVALID: use operator o config.'
}
if ([string]::IsNullOrWhiteSpace($selectedTheme)) {
    if ($NonInteractive) {
        throw 'V8_THEME_SELECTION_REQUIRED: defina global_theme_selection; selection_source se registrará como config.'
    }
    $names = @($availableThemes.Keys | Sort-Object)
    if ($names.Count -eq 0) { throw 'V8_THEME_TARGET_INVENTORY_EMPTY' }
    Write-Host ''
    Write-Host 'THEME GLOBAL DEL DESTINO' -ForegroundColor Cyan
    for ($i = 0; $i -lt $names.Count; $i++) {
        Write-Host "$($i + 1). $($names[$i])"
    }
    $answer = ([string](Read-Host 'Seleccione número o nombre del theme')).Trim()
    $number = 0
    if ([int]::TryParse($answer, [ref]$number) -and $number -ge 1 -and
            $number -le $names.Count) {
        $selectedTheme = $names[$number - 1]
    } else {
        $selectedTheme = $answer
    }
    if (-not $availableThemes.ContainsKey($selectedTheme)) {
        throw "V8_THEME_GLOBAL_NOT_AVAILABLE: $selectedTheme"
    }
    $themeConfiguration.global_theme_selection = $selectedTheme
    $themeConfiguration.selection_source = 'operator'
    $selectionSource = 'operator'
    Save-ThemePolicy $themeConfiguration
}
if (-not $availableThemes.ContainsKey($selectedTheme)) {
    throw "V8_THEME_GLOBAL_NOT_AVAILABLE: $selectedTheme"
}

$statusOutput = @(& php $engine '--mode=theme-status' `
    "--packages=$(Join-Path $exports 'packages')" `
    "--output=$themeInventoryStatus" 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw ('V8_THEME_INVENTORY_INVALID: ' + ($statusOutput -join ' '))
}
$statusOutput | ForEach-Object { Write-Host $_ }
$inventoryStatus = Read-Document $themeInventoryStatus
if ($null -eq $inventoryStatus) {
    throw 'V8_THEME_INVENTORY_INVALID: no se generó el diagnóstico de fuentes.'
}
$legacySources = @($inventoryStatus.legacy_sources)
$legacyAccepted = [bool]$themeConfiguration.theme_inventory_legacy_accepted
if ($legacySources.Count -gt 0 -and -not $legacyAccepted) {
    $sourceNames = @($legacySources | ForEach-Object {
        "$($_.source) [$($_.collector_version)]"
    }) -join ', '
    if ($NonInteractive) {
        throw (
            'V8_LEGACY_THEME_DECISION_REQUIRED: fuentes=' + $sourceNames +
            '; configure theme_inventory_legacy_accepted=true o recolecte con 7.4.2.'
        )
    }
    Write-Host ''
    Write-Host 'METADATA AVANZADA DE THEMES NO DISPONIBLE' -ForegroundColor Yellow
    Write-Host "Fuentes: $sourceNames"
    Write-Host '[R] Recolectar nuevamente con Recolector 7.4.2'
    Write-Host '[C] Continuar con compatibilidad legacy y warning explícito'
    Write-Host '[A] Abortar'
    $legacyChoice = ([string](Read-Host 'Seleccione R, C o A')).Trim().ToUpperInvariant()
    switch ($legacyChoice) {
        'R' {
            throw 'V8_LEGACY_THEME_RECOLLECT_REQUIRED: genere nuevamente esas fuentes con Recolector 7.4.2.'
        }
        'C' {
            $acceptedSources = @(
                $legacySources | ForEach-Object { [string]$_.source }
            )
            if ($themeConfiguration.PSObject.Properties.Name -contains
                    'theme_inventory_legacy_accepted') {
                $themeConfiguration.theme_inventory_legacy_accepted = $true
            } else {
                $themeConfiguration | Add-Member -NotePropertyName `
                    theme_inventory_legacy_accepted -NotePropertyValue $true
            }
            if ($themeConfiguration.PSObject.Properties.Name -contains
                    'legacy_accepted_sources') {
                $themeConfiguration.legacy_accepted_sources = $acceptedSources
            } else {
                $themeConfiguration | Add-Member -NotePropertyName `
                    legacy_accepted_sources -NotePropertyValue $acceptedSources
            }
            Save-ThemePolicy $themeConfiguration
            $legacyAccepted = $true
        }
        'A' { throw 'V8_LEGACY_THEME_ABORTED: decisión del operador.' }
        default { throw 'V8_LEGACY_THEME_CHOICE_INVALID: use R, C o A.' }
    }
}

$allowCourseThemes = if ([bool]$themeConfiguration.apply_course_themes) { 1 } else { 0 }
$manager = Join-Path $ProjectRoot 'GESTIONAR-CONFIG.sh'
$managerOutput = @(& bash $manager 'aplicar-politica-theme' `
    '--theme' $selectedTheme '--allow-course-themes' ([string]$allowCourseThemes) `
    '--motivo' 'V8: aplicar política global de themes antes de READY_TO_RUN' 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw ('THEME_POLICY_APPLY_FAILED: ' + ($managerOutput -join ' '))
}
$managerOutput | ForEach-Object { Write-Host $_ }
$managed = Read-Document $managedSettings
if ($null -eq $managed -or [string]$managed.settings.theme -cne $selectedTheme -or
        [int]$managed.settings.allowcoursethemes -ne $allowCourseThemes) {
    throw 'V8_THEME_TARGET_POLICY_INVALID: la configuración administrada no refleja la política verificada.'
}

$themeOutput = @(& php $engine '--mode=themes' "--packages=$(Join-Path $exports 'packages')" `
    "--policy=$themePolicy" "--lock=$lock" "--target-inventory=$targetInventory" `
    "--managed=$managedSettings" "--exports=$exports" 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw ('V8_THEME_PLAN_FAILED: ' + ($themeOutput -join ' '))
}
$themeOutput | ForEach-Object { Write-Host $_ }

Grant-ThemeTransportAccess -Service ([string]$targetSite.service)
Assert-ThemeTransportAccess -Service ([string]$targetSite.service)

& php $engine '--mode=readiness' "--lock=$lock" "--phase3=$phase3" `
    "--phase4=$phase4" "--oauth=$oauth" "--theme=$themePlan" `
    "--identity=$candidateSummary" "--catalog=$catalog" `
    "--policy=$themePolicy" "--config=$ConfigPath" `
    "--managed=$managedSettings" "--target-inventory=$targetInventory" `
    "--output=$readiness"
if ($LASTEXITCODE -ne 0 -or -not (Test-ReadyDocument)) {
    throw 'V8_NOT_READY'
}
Write-Output (Read-Document $readiness)
