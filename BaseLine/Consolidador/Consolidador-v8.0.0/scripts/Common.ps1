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
. "$PSScriptRoot/ConfigAccess.ps1"
$DestinationWriteLockPath = Get-DestinationWriteLockPath

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
    $configuredSourceId = [string]$configuredSource.id
    if ([string]::IsNullOrWhiteSpace($configuredSourceId)) {
        throw "config.yaml: la fuente en la posición $sourcePosition no tiene un id utilizable."
    }
    $Sites[$configuredSourceId] = $configuredSource
}
$configuredTargetId = [string]$MigrationConfig.Target.id
if ([string]::IsNullOrWhiteSpace($configuredTargetId)) {
    throw "config.yaml: el destino no tiene un id utilizable."
}
$Sites[$configuredTargetId] = $MigrationConfig.Target

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
        "source_aliases",
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

    $aliasProperties = @(
        $batch.source_aliases.PSObject.Properties
    )

    if ($aliasProperties.Count -ne $selectedSources.Count) {
        throw "config\phase6-batch.json: source_aliases no coincide con sources."
    }

    $seenAliases = @{}

    foreach ($source in $selectedSources) {
        $property = @(
            $aliasProperties |
                Where-Object {
                    [string]$_.Name -ceq [string]$source
                }
        )

        if ($property.Count -ne 1) {
            throw "config\phase6-batch.json: falta alias para '$source'."
        }

        $alias = (
            [string]$property[0].Value
        ).Trim().ToUpperInvariant()

        if ($alias -notmatch '^[A-Z0-9]{3}$') {
            throw "config\phase6-batch.json: alias inválido '$alias' para '$source'."
        }

        if ($seenAliases.ContainsKey($alias)) {
            throw "config\phase6-batch.json: alias repetido '$alias'."
        }

        $seenAliases[$alias] = $source
    }

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
    $readinessPath = Join-Path $ProjectRoot 'exports/readiness.json'
    if (-not (Test-Path -LiteralPath $readinessPath -PathType Leaf)) {
        throw 'V8_READINESS_REQUIRED_BEFORE_DESTINATION_WRITE'
    }
    $readinessHash = (
        Get-FileHash -LiteralPath $readinessPath -Algorithm SHA256
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
                    $currentOAuthConfigHash -or
                [string]$existing.readiness_sha256 -ne $readinessHash) {
            throw (
                "El destino ya quedó vinculado a otra configuración, " +
                "readiness u otro issuer OAuth2. No se retrocederá el flujo."
            )
        }
        return
    }
    $parent = Split-Path -Parent $DestinationWriteLockPath
    New-Item -ItemType Directory -Force -Path $parent | Out-Null
    $document = [ordered]@{
        schema_version = "1.1"
        created_at_utc = [DateTime]::UtcNow.ToString("o")
        config_sha256 = $currentConfigHash
        oauth_config_sha256 = $currentOAuthConfigHash
        readiness_sha256 = $readinessHash
        first_write_phase = $Phase
        authorization_sha256 = $BoundHash
        package_replacement_blocked = $true
        workflow_floor = 'phase4-users'
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
    $segments = @($ContainerPath.Split('/') | Where-Object { $_ -ne '' })
    if ($ContainerPath -notmatch '^/exports/(phase[1-8]|oauth2(?:-[a-z0-9][a-z0-9_-]*)?)(/[a-zA-Z0-9._-]+)*$' -or
            $segments -contains '.' -or $segments -contains '..') {
        throw "Ruta de exportación no permitida: '$ContainerPath'."
    }
}

function Grant-ThemeTransportAccess {
    param([Parameter(Mandatory = $true)][string]$Service)

    $ownership = Get-AssistantOwnership
    $command = @'
set -eu
chown ASSISTANT_UID:www-data /exports
chmod u=rwx,g=rwx,o= /exports
chmod g+s /exports
for directory in /exports/theme-assignments /exports/theme-profiles; do
  if [ -d "$directory" ]; then
    chown -R ASSISTANT_UID:www-data "$directory"
    find "$directory" -type d -exec chmod u=rwx,g=rx,o= {} +
    find "$directory" -type f -exec chmod u=rw,g=r,o= {} +
    find "$directory" -type d -exec chmod g+s {} +
  fi
done
'@
    $command = $command.Replace('ASSISTANT_UID', [string]$ownership.Uid)
    Invoke-Compose -Arguments @(
        'exec', '-T', '-u', 'root', $Service, 'sh', '-ec', $command
    )
}

function Assert-ThemeTransportAccess {
    param([Parameter(Mandatory = $true)][string]$Service)

    $command = @'
set -eu
test -r /exports/theme-plan.json
find /exports/theme-assignments -type f -name course-themes.csv -print -quit |
  grep -q .
find /exports/theme-assignments -type f -name course-themes.csv -exec test -r {} \;
probe=/exports/.theme-transport-probe-$$
(umask 007; printf '%s\n' '{"status":"probe"}' > "$probe")
test -s "$probe"
rm -f "$probe"
'@
    Invoke-Compose -Arguments @(
        'exec', '-T', '-u', 'www-data', $Service, 'sh', '-ec', $command
    )
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
    $ownership = Get-AssistantOwnership
    $command = (
        "mkdir -p $quotedPaths; " +
        "chown -R $($ownership.Uid):www-data '$ContainerPath'; " +
        "chmod -R u=rwX,g=rwX,o= '$ContainerPath'; " +
        "find '$ContainerPath' -type d -exec chmod g+s {} +"
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
        "chmod -R u=rwX,g=rwX,o= '$ContainerPath'; " +
        "find '$ContainerPath' -type d -exec chmod g+s {} +"
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
