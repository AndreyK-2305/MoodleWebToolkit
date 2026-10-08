# Solo funciones: importar no lee config.yaml.
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

