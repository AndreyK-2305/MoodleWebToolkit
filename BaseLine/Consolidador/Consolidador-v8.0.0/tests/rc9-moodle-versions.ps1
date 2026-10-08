$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../scripts/MoodleVersion.ps1"
. "$PSScriptRoot/../scripts/PluginIntervention.ps1"
function Assert-RC9($Condition, [string]$Label) {
    if (-not $Condition) { throw "RC9_MOODLE_VERSION_FAILED: $Label" }
}
function Parse-Core($Value) {
    $issues = New-Object System.Collections.Generic.List[object]
    $result = ConvertTo-MoodleVersion -Value $Value -Component 'source' -Issues $issues
    Assert-RC9 ($issues.Count -eq 0 -and $null -ne $result -and
        $result -is [decimal]) "válido: $Value"
    return $result
}
$sourceNumeric = Parse-Core 2024100702.01
$sourceString = Parse-Core '2024100702.01'
$sourceInteger = Parse-Core 2024100702
$target = Parse-Core 2026042001
Assert-RC9 ($sourceNumeric -eq $sourceString -and $sourceNumeric -lt $target) `
    'revisión numérica y texto preceden a Moodle 5.2.1'
Assert-RC9 ((Parse-Core '2024100702.02') -gt $sourceNumeric) `
    'revisión 02 posterior a 01'
Assert-RC9 ($sourceInteger -eq (Parse-Core '2024100702.00')) `
    'revisión ausente igual a .00'
foreach ($bad in @($null, '', @(), [pscustomobject]@{}, 'abc',
        '2024100702.foo', '-2024100702.01', @('2024100702.01'),
        @{ build = 2024100702 }, $true)) {
    $issues = New-Object System.Collections.Generic.List[object]
    $parsed = ConvertTo-MoodleVersion -Value $bad -Component 'source' -Issues $issues
    Assert-RC9 ($null -eq $parsed -and $issues.Count -eq 1 -and
        $issues[0].component -eq 'source' -and
        $issues[0].field -eq 'moodle_version' -and
        $issues[0].message -like 'MOODLE_VERSION_INVALID:*') `
        "rechaza valor inválido: $bad"
}
$pluginIssues = New-Object System.Collections.Generic.List[object]
Assert-RC9 ($null -eq (ConvertTo-PluginVersion '2024100702.01' `
    'mod_x' 'version_disk' $pluginIssues) -and $pluginIssues.Count -eq 1 -and
    $pluginIssues[0].field -eq 'version_disk' -and
    $pluginIssues[0].message -like '*Int64*') 'plugins mantienen Int64 estricto'
Assert-RC9 ((ConvertTo-PluginVersion 2026042001 'mod_x' 'version_disk' `
    $pluginIssues) -eq 2026042001) 'versión entera de plugin sigue válida'
Write-Output 'RC9_MOODLE_VERSION_OK decimal=int/string ordering=exact invalid=controlled plugin=Int64'
