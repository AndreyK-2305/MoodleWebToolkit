$ErrorActionPreference = 'Stop'

. "$PSScriptRoot/../scripts/Phase6SourceRouting.ps1"

function Assert-RC4Source([bool]$Condition, [string]$Message) {
    if (-not $Condition) {
        throw "V8_RC4_SOURCE_ROUTING_FAILED $Message"
    }
}

$temporary = Join-Path ([System.IO.Path]::GetTempPath()) (
    'consolidador-v8-rc4-source-' + [guid]::NewGuid().ToString('N')
)
New-Item -ItemType Directory -Path $temporary | Out-Null
$plan = Join-Path $temporary 'course_plan.csv'
try {
    $rows = [System.Collections.Generic.List[object]]::new()
    for ($index = 1; $index -le 40; $index++) {
        $source = if (($index % 2) -eq 0) {
            'pregrado-2026-03-04-directo'
        } else {
            'posgrados-2025-05-02-directo'
        }
        $rows.Add([pscustomobject]@{
            course_key = ('COURSE-' + $index.ToString('D3'))
            source = $source
            action = 'restore_new'
            blocking_reason = ''
        })
    }
    $rows | Export-Csv -LiteralPath $plan -NoTypeInformation -Encoding UTF8

    $map = Get-Phase6CourseSourceMap -CoursePlanPath $plan
    Assert-RC4Source ($map.Count -eq 40) 'el mapa perdió cursos'

    # Simula cuatro workers que cambian de source en cada asignación. Cada
    # resolución debe depender solo de course_key, nunca del job anterior.
    $corrections = 0
    for ($worker = 1; $worker -le 4; $worker++) {
        for ($position = $worker; $position -le 40; $position += 4) {
            $key = 'COURSE-' + $position.ToString('D3')
            $expected = if (($position % 2) -eq 0) {
                'pregrado-2026-03-04-directo'
            } else {
                'posgrados-2025-05-02-directo'
            }
            $resolved = Resolve-Phase6CourseSource `
                -SourceMap $map -CourseKey $key -ReceivedSourceId $expected
            Assert-RC4Source ($resolved.SourceId -ceq $expected) `
                "worker=$worker contaminó $key"
            if ($resolved.Corrected) { $corrections++ }
        }
    }
    Assert-RC4Source ($corrections -eq 0) `
        'WORKER_SOURCE_CORRECTED se activaría en operación normal'

    $defensive = Resolve-Phase6CourseSource `
        -SourceMap $map -CourseKey 'COURSE-001' `
        -ReceivedSourceId 'pregrado-2026-03-04-directo'
    Assert-RC4Source (
        $defensive.Corrected -and
        $defensive.SourceId -ceq 'posgrados-2025-05-02-directo'
    ) 'la defensa HF3 no corrigió un caller inválido'

    $scheduler = Get-Content -LiteralPath "$PSScriptRoot/../scripts/phase6-apply.ps1" `
        -Raw -Encoding UTF8
    Assert-RC4Source (
        $scheduler.Contains('Get-Phase6CourseSourceMap') -and
        $scheduler.Contains('-SourceId $plannedSourceId') -and
        -not $scheduler.Contains("'-SourceId', ([string]`$Course.source)")
    ) 'el scheduler todavía despacha Course.source directamente'

    Write-Output 'V8_RC4_SOURCE_ROUTING_OK sources=2 jobs=40 workers=4 corrections_normal=0 defense=1'
} finally {
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
