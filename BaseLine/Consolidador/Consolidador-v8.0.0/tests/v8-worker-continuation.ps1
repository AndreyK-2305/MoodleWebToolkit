$ErrorActionPreference = 'Stop'
function Assert-Worker([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "V8_WORKER_CONTINUATION_FAILED: $Message" }
}
$source = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts/phase6-apply.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $source, [ref]$tokens, [ref]$errors
)
Assert-Worker ($errors.Count -eq 0) 'phase6-apply.ps1 no parsea'
$definition = @($ast.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -eq 'Get-WorkerFailureDisposition'
}, $true))
Assert-Worker ($definition.Count -eq 1) 'falta política explícita de worker'
Invoke-Expression $definition[0].Extent.Text

foreach ($category in @('SOURCE_DATA_DEFECT', 'MOODLE_RESTORE_INCOMPATIBILITY')) {
    $result = Get-WorkerFailureDisposition 'WAITING_MANUAL' $category
    Assert-Worker (-not $result.StopAssigning -and
        $result.Status -eq 'WAITING_MANUAL') "$category detuvo el lote"
}
foreach ($category in @('PRECONDITION_BUG', 'TOOL_INTERNAL_ERROR')) {
    $result = Get-WorkerFailureDisposition 'FATAL_SYSTEMIC' $category
    Assert-Worker ($result.StopAssigning -and
        $result.Status -eq 'FATAL_SYSTEMIC') "$category no detuvo el lote"
}
$scenario = @(
    [pscustomobject]@{ Course = 'A'; Status = 'SUCCESS'; Category = '' },
    [pscustomobject]@{ Course = 'B'; Status = 'SUCCESS'; Category = '' },
    [pscustomobject]@{ Course = 'C'; Status = 'WAITING_MANUAL'; Category = 'MOODLE_RESTORE_INCOMPATIBILITY' },
    [pscustomobject]@{ Course = 'D'; Status = 'SUCCESS'; Category = '' },
    [pscustomobject]@{ Course = 'E'; Status = 'SUCCESS'; Category = '' }
)
$observed = @()
$stopped = $false
foreach ($course in $scenario) {
    if ($stopped) { break }
    $observed += $course.Course
    if ($course.Status -eq 'WAITING_MANUAL') {
        $decision = Get-WorkerFailureDisposition $course.Status $course.Category
        $stopped = $decision.StopAssigning
    }
}
Assert-Worker (($observed -join '') -eq 'ABCDE' -and -not $stopped) `
    'el escenario A/B/C/D/E no continuó después del curso aislado C'
Write-Output 'WORKER_CONTINUATION_OK isolated=continue scenario=ABCDE systemic=STOP_ASSIGNING'
