# Resolución autoritativa course_key -> source para Fase 13.
# Este archivo no ejecuta trabajo al importarse y puede usarse en tests.

function Get-Phase6CourseSourceMap {
    param(
        [Parameter(Mandatory)][string]$CoursePlanPath
    )
    if (-not (Test-Path -LiteralPath $CoursePlanPath -PathType Leaf)) {
        throw "PHASE13_SOURCE_PLAN_MISSING: $CoursePlanPath"
    }
    $rows = @(Import-Csv -LiteralPath $CoursePlanPath -Encoding UTF8)
    $map = @{}
    foreach ($row in $rows) {
        if ([string]$row.action -cne 'restore_new') {
            continue
        }
        $courseKey = ([string]$row.course_key).Trim().ToUpperInvariant()
        $sourceId = ([string]$row.source).Trim().ToLowerInvariant()
        if ([string]::IsNullOrWhiteSpace($courseKey) -or
                $courseKey -notmatch '^COURSE-[A-Z0-9_-]+$' -or
                [string]::IsNullOrWhiteSpace($sourceId) -or
                $sourceId -notmatch '^[a-z][a-z0-9_-]*$' -or
                -not [string]::IsNullOrWhiteSpace([string]$row.blocking_reason)) {
            throw "PHASE13_SOURCE_PLAN_INVALID: fila no restaurable"
        }
        if ($map.ContainsKey($courseKey)) {
            throw "PHASE13_SOURCE_PLAN_DUPLICATE: $courseKey"
        }
        $map[$courseKey] = $sourceId
    }
    if ($map.Count -lt 1) {
        throw 'PHASE13_SOURCE_PLAN_INVALID: no contiene cursos restore_new'
    }
    return $map
}

function Resolve-Phase6CourseSource {
    param(
        [Parameter(Mandatory)][hashtable]$SourceMap,
        [Parameter(Mandatory)][string]$CourseKey,
        [Parameter(Mandatory)][string]$ReceivedSourceId
    )
    $normalizedKey = $CourseKey.Trim().ToUpperInvariant()
    if (-not $SourceMap.ContainsKey($normalizedKey)) {
        throw "PHASE13_SOURCE_NOT_PLANNED: $normalizedKey"
    }
    $planned = [string]$SourceMap[$normalizedKey]
    $received = $ReceivedSourceId.Trim().ToLowerInvariant()
    return [pscustomobject]@{
        CourseKey = $normalizedKey
        SourceId = $planned
        ReceivedSourceId = $received
        Corrected = $received -cne $planned
    }
}
