$ErrorActionPreference = "Stop"
$utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot
$ComposeProjectName = "moodle-consolidation-production"
$env:COMPOSE_PROJECT_NAME = $ComposeProjectName

$ConfigPath = Join-Path $ProjectRoot "config.yaml"
$Phase5PilotConfigPath = Join-Path $ProjectRoot "config\phase5-pilot.json"
$Phase6BatchConfigPath = Join-Path $ProjectRoot "config\phase6-batch.json"
$Phase6RoleResolutionsPath = Join-Path $ProjectRoot "config\phase6-role-resolutions.csv"
$ConfigurationConfirmationPath = Join-Path $ProjectRoot "reports\configuration-confirmation.json"
$DestinationWriteLockPath = Join-Path $ProjectRoot "reports\destination-write.lock.json"

function ConvertFrom-ConfigScalar([string]$Value) {
    $value = $Value.Trim()
    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        return $value.Substring(1, $value.Length - 2)
    }
    if ($value -eq "true") { return $true }
    if ($value -eq "false") { return $false }
    if ($value -match '^-?\d+$') { return [int]$value }
    return $value
}

function Set-ConfigField {
    param(
        [System.Collections.IDictionary]$Target,
        [string]$Line,
        [int]$LineNumber
    )
    if ($Line -notmatch '^([a-zA-Z][a-zA-Z0-9_]*):\s*(.*)$') {
        throw "config.yaml, línea ${LineNumber}: se esperaba 'campo: valor'."
    }
    $key = $Matches[1]
    $value = ConvertFrom-ConfigScalar $Matches[2]
    if ($Target.Contains($key)) {
        throw "config.yaml, línea ${LineNumber}: el campo '$key' está repetido."
    }
    # No permita que la asignación forme parte de la salida de la función.
    # Import-MigrationConfig debe emitir exclusivamente el objeto final.
    [void]($Target[$key] = $value)
}

function ConvertTo-ConfigObject {
    param(
        [System.Collections.IDictionary]$Map
    )

    # La conversión explícita evita diferencias de adaptación de
    # OrderedDictionary entre Windows PowerShell 5.1 y PowerShell 7.
    $result = New-Object PSObject
    foreach ($key in $Map.Keys) {
        Add-Member `
            -InputObject $result `
            -MemberType NoteProperty `
            -Name ([string]$key) `
            -Value $Map[$key]
    }
    return $result
}

function Import-MigrationConfig([string]$Path = $ConfigPath) {
    if (-not (Test-Path $Path -PathType Leaf)) {
        throw "No se encontró config.yaml en $Path."
    }

    $root = [ordered]@{}
    $sources = New-Object System.Collections.ArrayList
    $target = [ordered]@{}
    $section = ""
    $currentSource = $null
    $lineNumber = 0

    foreach ($rawLine in @(Get-Content -LiteralPath $Path -Encoding UTF8)) {
        $lineNumber++
        if ($rawLine -match '^\s*(#.*)?$') { continue }
        if ($rawLine -match "`t") {
            throw "config.yaml, línea ${lineNumber}: use espacios, no tabulaciones."
        }

        if ($rawLine -match '^([a-zA-Z][a-zA-Z0-9_]*):\s*$') {
            $section = $Matches[1]
            $currentSource = $null
            if ($section -notin @("sources", "target")) {
                throw "config.yaml, línea ${lineNumber}: sección desconocida '$section'."
            }
            continue
        }

        if ($rawLine -match '^([a-zA-Z][a-zA-Z0-9_]*):\s*(.+)$') {
            if ($section -ne "") {
                throw "config.yaml, línea ${lineNumber}: el campo raíz está fuera de lugar."
            }
            Set-ConfigField -Target $root -Line $rawLine -LineNumber $lineNumber | Out-Null
            continue
        }

        if ($section -eq "sources" -and $rawLine -match '^  -\s+(.+)$') {
            $currentSource = [ordered]@{}
            [void]$sources.Add($currentSource)
            Set-ConfigField -Target $currentSource -Line $Matches[1] -LineNumber $lineNumber | Out-Null
            continue
        }
        if ($section -eq "sources" -and $rawLine -match '^    (.+)$') {
            if ($null -eq $currentSource) {
                throw "config.yaml, línea ${lineNumber}: defina primero '- id: ...'."
            }
            Set-ConfigField -Target $currentSource -Line $Matches[1] -LineNumber $lineNumber | Out-Null
            continue
        }
        if ($section -eq "target" -and $rawLine -match '^  (.+)$') {
            Set-ConfigField -Target $target -Line $Matches[1] -LineNumber $lineNumber | Out-Null
            continue
        }

        throw "config.yaml, línea ${lineNumber}: indentación o sintaxis no soportada."
    }

    foreach ($required in @("version", "project_name", "mode")) {
        if (-not $root.Contains($required) -or [string]::IsNullOrWhiteSpace([string]$root[$required])) {
            throw "config.yaml: falta el campo raíz '$required'."
        }
    }
    if ([int]$root.version -ne 1) {
        throw "config.yaml: versión no soportada '$($root.version)'; se esperaba 1."
    }
    if ($root.mode -notin @("lab", "production")) {
        throw "config.yaml: mode debe ser 'lab' o 'production'."
    }
    if ($sources.Count -lt 1) {
        throw "config.yaml: debe existir al menos una instancia en sources."
    }

    $baseRequired = @("id", "name", "service", "url")
    $labRequired = @(
        "lab_code", "dataset", "teacher_username", "manager_username",
        "representative_course", "custom_tutor_role", "semesters", "subjects"
    )
    $seenIds = @{}
    $seenServices = @{}
    $seenLabCodes = @{}
    # Use a typed list instead of += over a PowerShell array. This keeps the
    # Sources property as a collection of source objects on Windows PowerShell
    # 5.1 and avoids an accidental null item during object conversion.
    $sourceObjects = New-Object 'System.Collections.Generic.List[object]'
    foreach ($source in $sources) {
        foreach ($required in $baseRequired) {
            if (-not $source.Contains($required) -or
                    [string]::IsNullOrWhiteSpace([string]$source[$required])) {
                throw "config.yaml: una fuente no contiene '$required'."
            }
        }
        if ($root.mode -eq "lab") {
            foreach ($required in $labRequired) {
                if (-not $source.Contains($required) -or
                        [string]::IsNullOrWhiteSpace([string]$source[$required])) {
                    throw "config.yaml: la fuente '$($source.id)' no contiene '$required' requerido en modo lab."
                }
            }
            if ([string]$source.lab_code -notmatch '^[A-Z0-9]{2,12}$') {
                throw "config.yaml: lab_code inválido para '$($source.id)'."
            }
            if ($seenLabCodes.ContainsKey([string]$source.lab_code)) {
                throw "config.yaml: lab_code repetido '$($source.lab_code)'."
            }
            if ([string]$source.dataset -notmatch '^[a-zA-Z0-9_.-]+\.csv$') {
                throw "config.yaml: dataset inválido para '$($source.id)'."
            }
            if (-not ($source.custom_tutor_role -is [bool])) {
                throw "config.yaml: custom_tutor_role debe ser true o false en '$($source.id)'."
            }
            if ([int]$source.semesters -lt 1 -or [int]$source.semesters -gt 12) {
                throw "config.yaml: semesters debe estar entre 1 y 12 en '$($source.id)'."
            }
            if ([int]$source.subjects -lt 2 -or [int]$source.subjects -gt 12) {
                throw "config.yaml: subjects debe estar entre 2 y 12 en '$($source.id)'."
            }
            $seenLabCodes[[string]$source.lab_code] = $true
        }
        if ([string]$source.id -notmatch '^[a-z][a-z0-9_-]*$') {
            throw "config.yaml: id de fuente inválido '$($source.id)'."
        }
        if ([string]$source.url -notmatch '^https?://') {
            throw "config.yaml: URL inválida para '$($source.id)': $($source.url)."
        }
        if ($seenIds.ContainsKey([string]$source.id)) {
            throw "config.yaml: id repetido '$($source.id)'."
        }
        if ($seenServices.ContainsKey([string]$source.service)) {
            throw "config.yaml: servicio repetido '$($source.service)'."
        }
        $seenIds[[string]$source.id] = $true
        $seenServices[[string]$source.service] = $true
        $sourceObject = ConvertTo-ConfigObject $source
        $sourceId = [string]$sourceObject.id
        if ([string]::IsNullOrWhiteSpace($sourceId)) {
            throw "config.yaml: no se pudo materializar el id de una fuente."
        }
        [void]$sourceObjects.Add($sourceObject)
    }

    foreach ($required in $baseRequired) {
        if (-not $target.Contains($required) -or
                [string]::IsNullOrWhiteSpace([string]$target[$required])) {
            throw "config.yaml: target no contiene '$required'."
        }
    }
    if ([string]$target.id -notmatch '^[a-z][a-z0-9_-]*$') {
        throw "config.yaml: id de destino inválido '$($target.id)'."
    }
    if ([string]$target.url -notmatch '^https?://') {
        throw "config.yaml: URL inválida para el destino: $($target.url)."
    }
    if ($seenIds.ContainsKey([string]$target.id)) {
        throw "config.yaml: el id de destino '$($target.id)' también aparece como fuente."
    }
    if ($seenServices.ContainsKey([string]$target.service)) {
        throw "config.yaml: el servicio de destino '$($target.service)' también aparece como fuente."
    }
    $materializedSources = [object[]]$sourceObjects.ToArray()
    $materializedTarget = ConvertTo-ConfigObject $target

    $configObject = New-Object PSObject
    Add-Member -InputObject $configObject -MemberType NoteProperty -Name "Version" -Value ([int]$root.version)
    Add-Member -InputObject $configObject -MemberType NoteProperty -Name "ProjectName" -Value ([string]$root.project_name)
    Add-Member -InputObject $configObject -MemberType NoteProperty -Name "Mode" -Value ([string]$root.mode)
    Add-Member -InputObject $configObject -MemberType NoteProperty -Name "Sources" -Value $materializedSources
    Add-Member -InputObject $configObject -MemberType NoteProperty -Name "Target" -Value $materializedTarget

    Write-Output -NoEnumerate $configObject
}

$configOutput = @(Import-MigrationConfig)
if ($configOutput.Count -ne 1) {
    throw "Error interno al interpretar config.yaml: se obtuvieron $($configOutput.Count) resultados en lugar de un único objeto de configuración."
}
$MigrationConfig = $configOutput[0]
$Sites = [ordered]@{}
$sourcePosition = 0
$configuredSources = @($MigrationConfig.Sources)
if ($configuredSources.Count -lt 1) {
    throw "config.yaml: no se materializó ninguna fuente."
}
# Common.ps1 se importa con dot-sourcing y comparte el ámbito del script llamador.
# No use "$source" aquí: PowerShell no distingue mayúsculas y puede colisionar
# con parámetros tipados "$Source" de otros scripts, convirtiendo los objetos
# de configuración en texto.
foreach ($configuredSource in $configuredSources) {
    $sourcePosition++
    if ($null -eq $configuredSource) {
        throw "config.yaml: la fuente en la posición $sourcePosition se materializó como NULL."
    }
    $sourceId = [string]$configuredSource.id
    if ([string]::IsNullOrWhiteSpace($sourceId)) {
        throw "config.yaml: la fuente en la posición $sourcePosition no tiene un id utilizable."
    }
    $Sites[$sourceId] = $configuredSource
}
$targetId = [string]$MigrationConfig.Target.id
if ([string]::IsNullOrWhiteSpace($targetId)) {
    throw "config.yaml: el destino no tiene un id utilizable."
}
$Sites[$targetId] = $MigrationConfig.Target

function Get-SourceSiteNames {
    return @($MigrationConfig.Sources | ForEach-Object { $_.id })
}

function Get-AllConfiguredSiteNames {
    return @((Get-SourceSiteNames) + @($MigrationConfig.Target.id))
}

function Get-ConfiguredServices {
    return @($Sites.Values | ForEach-Object { $_.service } | Select-Object -Unique)
}

function Get-TargetSite {
    return $MigrationConfig.Target
}

function Import-Phase5PilotConfig([string]$Path = $Phase5PilotConfigPath) {
    if (-not (Test-Path $Path -PathType Leaf)) {
        throw "No se encontró config\phase5-pilot.json."
    }
    try {
        $pilot = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 |
            ConvertFrom-Json
    }
    catch {
        throw "config\phase5-pilot.json no contiene JSON válido."
    }
    foreach ($required in @("schema_version", "source_id", "target_category_id")) {
        if ($null -eq $pilot.$required -or
                [string]::IsNullOrWhiteSpace([string]$pilot.$required)) {
            throw "config\phase5-pilot.json no contiene '$required'."
        }
    }
    if ([string]$pilot.schema_version -ne "1.0") {
        throw "config\phase5-pilot.json usa una versión no soportada."
    }
    Assert-SourceNames @([string]$pilot.source_id)
    $categoryId = 0
    if (-not [int]::TryParse(
            [string]$pilot.target_category_id,
            [ref]$categoryId
        ) -or $categoryId -lt 1) {
        throw "config\phase5-pilot.json: target_category_id debe ser un entero positivo."
    }
    return $pilot
}

function Get-PilotSourceSite {
    $pilot = Import-Phase5PilotConfig
    return Get-Site ([string]$pilot.source_id)
}

function Get-PilotCategoryId {
    $pilot = Import-Phase5PilotConfig
    return [int]$pilot.target_category_id
}

function Import-Phase6BatchConfig([string]$Path = $Phase6BatchConfigPath) {
    if (-not (Test-Path $Path -PathType Leaf)) {
        throw "No se encontró config\phase6-batch.json."
    }
    try {
        $batch = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 |
            ConvertFrom-Json
    }
    catch {
        throw "config\phase6-batch.json no contiene JSON válido."
    }
    foreach ($required in @(
        "schema_version",
        "batch_id",
        "target_parent_category_id",
        "exclude_verified_phase5_pilot",
        "sources",
        "selection",
        "role_policy"
    )) {
        if ($null -eq $batch.$required) {
            throw "config\phase6-batch.json no contiene '$required'."
        }
    }
    if ([string]$batch.schema_version -ne "1.0") {
        throw "config\phase6-batch.json usa una versión no soportada."
    }
    if ([string]$batch.batch_id -notmatch '^[a-z][a-z0-9_-]{2,63}$') {
        throw "config\phase6-batch.json: batch_id debe ser un identificador de 3 a 64 caracteres."
    }
    $categoryId = 0
    if (-not [int]::TryParse(
            [string]$batch.target_parent_category_id,
            [ref]$categoryId
        ) -or $categoryId -lt 1) {
        throw "config\phase6-batch.json: target_parent_category_id debe ser un entero positivo."
    }
    if (-not ($batch.exclude_verified_phase5_pilot -is [bool])) {
        throw "config\phase6-batch.json: exclude_verified_phase5_pilot debe ser true o false."
    }
    $selectedSources = @($batch.sources | ForEach-Object { [string]$_ })
    if ($selectedSources.Count -lt 1) {
        throw "config\phase6-batch.json debe seleccionar al menos una fuente."
    }
    if (($selectedSources | Select-Object -Unique).Count -ne $selectedSources.Count) {
        throw "config\phase6-batch.json contiene fuentes repetidas."
    }
    Assert-SourceNames $selectedSources
    if ([string]$batch.selection.mode -ne "all_non_site_courses") {
        throw "config\phase6-batch.json: selection.mode no está soportado."
    }
    if (-not ($batch.selection.include_hidden -is [bool])) {
        throw "config\phase6-batch.json: selection.include_hidden debe ser true o false."
    }
    $expectedRolePolicy = @{
        student = "student"
        teacher = "editingteacher"
        editingteacher = "editingteacher"
        manager = "manager"
        fallback = "personalizado"
    }
    foreach ($sourceRole in $expectedRolePolicy.Keys) {
        if ([string]$batch.role_policy.$sourceRole -ne
                [string]$expectedRolePolicy[$sourceRole]) {
            throw "config\phase6-batch.json: la política de '$sourceRole' no coincide con la normalización aprobada."
        }
    }
    if (-not ($batch.role_policy.preserve_site_admins_separately -is [bool]) -or
            -not [bool]$batch.role_policy.preserve_site_admins_separately) {
        throw "config\phase6-batch.json debe conservar los administradores del sitio por separado."
    }
    $personalizado = $batch.role_policy.personalizado_safety
    if ($null -eq $personalizado -or
            [string]$personalizado.assignable_context -ne "course_only" -or
            [string]$personalizado.profile -ne "student_readonly") {
        throw "config\phase6-batch.json: personalizado debe ser student_readonly y exclusivo del contexto de curso."
    }
    foreach ($denyField in @(
        "allow_content_view",
        "deny_content_mutation",
        "deny_grading",
        "deny_enrolment_and_roles",
        "deny_backup_restore",
        "deny_configuration"
    )) {
        if (-not ($personalizado.$denyField -is [bool]) -or
                -not [bool]$personalizado.$denyField) {
            throw "config\phase6-batch.json: la protección '$denyField' de personalizado debe permanecer activa."
        }
    }
    return $batch
}

function Assert-SourceNames([string[]]$Names) {
    $allowed = @(Get-SourceSiteNames)
    foreach ($name in $Names) {
        if ($name -notin $allowed) {
            throw "Instancia de origen inválida '$name'. Valores permitidos: $($allowed -join ', ')."
        }
    }
}

function Get-ConfigurationHash {
    return (Get-FileHash -LiteralPath $ConfigPath -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Register-DestinationWriteIntent {
    param(
        [Parameter(Mandatory = $true)][string]$Phase,
        [Parameter(Mandatory = $true)][string]$BoundHash
    )
    if ($Phase -notmatch '^[a-z0-9_-]+$' -or
            $BoundHash -notmatch '^[a-f0-9]{64}$') {
        throw "No se pudo registrar una intención de escritura válida."
    }
    $currentConfigHash = Get-ConfigurationHash
    $oauthConfigPath = Join-Path $ProjectRoot "config\oauth2.json"
    if (-not (Test-Path -LiteralPath $oauthConfigPath -PathType Leaf)) {
        throw "Falta config\oauth2.json antes de registrar escrituras."
    }
    $currentOAuthConfigHash = (
        Get-FileHash -LiteralPath $oauthConfigPath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    if (Test-Path -LiteralPath $DestinationWriteLockPath -PathType Leaf) {
        try {
            $existing = Get-Content -LiteralPath $DestinationWriteLockPath `
                -Raw -Encoding UTF8 | ConvertFrom-Json
        } catch {
            throw "El bloqueo de escritura del destino no puede interpretarse."
        }
        if ([string]$existing.config_sha256 -ne $currentConfigHash -or
                [string]$existing.oauth_config_sha256 -ne
                    $currentOAuthConfigHash) {
            throw (
                "El destino ya quedó vinculado a otra configuración u " +
                "otro issuer OAuth2. No se cambiarán los paquetes ni el proveedor."
            )
        }
        return
    }
    $parent = Split-Path -Parent $DestinationWriteLockPath
    New-Item -ItemType Directory -Force -Path $parent | Out-Null
    $document = [ordered]@{
        schema_version = "1.0"
        created_at_utc = [DateTime]::UtcNow.ToString("o")
        config_sha256 = $currentConfigHash
        oauth_config_sha256 = $currentOAuthConfigHash
        first_write_phase = $Phase
        authorization_sha256 = $BoundHash
        package_replacement_blocked = $true
    }
    [System.IO.File]::WriteAllText(
        $DestinationWriteLockPath,
        ($document | ConvertTo-Json -Depth 8) + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Assert-ConfigurationConfirmed {
    if (-not (Test-Path $ConfigurationConfirmationPath -PathType Leaf)) {
        throw "La configuración aún no está confirmada. Reanude INICIAR-CONSOLIDACION.sh."
    }
    try {
        $confirmation = Get-Content -LiteralPath $ConfigurationConfirmationPath -Raw -Encoding UTF8 |
            ConvertFrom-Json
    }
    catch {
        throw "No se pudo leer la confirmación. Reanude INICIAR-CONSOLIDACION.sh."
    }
    $currentHash = Get-ConfigurationHash
    if ([string]$confirmation.config_sha256 -ne $currentHash) {
        throw "config.yaml cambió después de su confirmación. Reanude INICIAR-CONSOLIDACION.sh."
    }
}

function Assert-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "No se encontró '$Name' dentro del runtime Linux."
    }
}

function Invoke-Compose {
    param(
        [string[]]$Arguments
    )
    & docker compose --project-name $ComposeProjectName @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose terminó con código $LASTEXITCODE."
    }
}

function Assert-ExportContainerPath([string]$ContainerPath) {
    if ($ContainerPath -notmatch '^/exports/(phase[1-8]|oauth2(-live)?)(/[a-zA-Z0-9._-]+)*$') {
        throw "Ruta de exportación no permitida: '$ContainerPath'."
    }
}

function Get-AssistantOwnership {
    $assistantUid = 0
    $assistantGid = 0
    if (-not [int]::TryParse([string]$env:ASSISTANT_UID, [ref]$assistantUid) -or
            -not [int]::TryParse([string]$env:ASSISTANT_GID, [ref]$assistantGid) -or
            $assistantUid -lt 1 -or $assistantGid -lt 1) {
        throw "ASSISTANT_UID y ASSISTANT_GID deben ser enteros positivos."
    }
    return [pscustomobject]@{
        Uid = $assistantUid
        Gid = $assistantGid
    }
}

function Grant-ContainerExportWrite {
    param(
        [Parameter(Mandatory = $true)][string]$Service,
        [Parameter(Mandatory = $true)][string]$ContainerPath,
        [string[]]$ChildDirectories = @()
    )
    Assert-ExportContainerPath $ContainerPath
    $paths = New-Object 'System.Collections.Generic.List[string]'
    [void]$paths.Add($ContainerPath)
    foreach ($child in $ChildDirectories) {
        if ([string]$child -notmatch '^[a-zA-Z0-9._-]+(/[a-zA-Z0-9._-]+)*$') {
            throw "Subdirectorio de exportación no permitido: '$child'."
        }
        $childPath = "$ContainerPath/$child"
        Assert-ExportContainerPath $childPath
        [void]$paths.Add($childPath)
    }
    $quotedPaths = @($paths | ForEach-Object { "'$_'" }) -join " "
    $command = (
        "mkdir -p $quotedPaths; " +
        "chown -R www-data:www-data '$ContainerPath'; " +
        "chmod -R u=rwX,g=rwX,o= '$ContainerPath'"
    )
    Invoke-Compose -Arguments @(
        "exec", "-T", "-u", "root", $Service, "sh", "-ec", $command
    )
}

function Restore-AssistantExportOwnership {
    param(
        [Parameter(Mandatory = $true)][string]$Service,
        [Parameter(Mandatory = $true)][string]$ContainerPath
    )
    Assert-ExportContainerPath $ContainerPath
    $ownership = Get-AssistantOwnership
    $command = (
        "chown -R $($ownership.Uid):www-data '$ContainerPath'; " +
        "chmod -R u=rwX,g=rX,o= '$ContainerPath'"
    )
    Invoke-Compose -Arguments @(
        "exec", "-T", "-u", "root", $Service, "sh", "-ec", $command
    )
}

function Get-Site([string]$Name) {
    if (-not $Sites.Contains($Name)) {
        $allowed = ($Sites.Keys -join ", ")
        throw "Instancia inválida '$Name'. Valores permitidos: $allowed."
    }
    return $Sites[$Name]
}
