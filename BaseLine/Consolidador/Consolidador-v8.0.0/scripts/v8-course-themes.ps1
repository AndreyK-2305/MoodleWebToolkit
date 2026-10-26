param(
    [ValidateSet('Pilot', 'All')]
    [string]$Scope = 'Pilot'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/Common.ps1"
$target = Get-TargetSite
Grant-ThemeTransportAccess -Service ([string]$target.service)
Assert-ThemeTransportAccess -Service ([string]$target.service)
$maps = @('/exports/phase5/pilot_course_map.csv')
$output = '/exports/theme-assignment-verification-pilot.json'
if ($Scope -eq 'All') {
    $maps += '/exports/phase6/course_map.csv'
    $output = '/exports/theme-assignment-verification.json'
}
if ($Scope -eq 'Pilot') {
    # 8.2.0: la personalización del theme global, una sola vez antes del lote.
    & docker compose exec -T -u www-data ([string]$target.service) php `
        /opt/consolidator/v8-theme-profile.php `
        '--plan=/exports/theme-plan.json' `
        '--output=/exports/theme-profile-application.json'
    if ($LASTEXITCODE -ne 0) { throw 'V8_THEME_PROFILE_FAILED' }
}
& docker compose exec -T -u www-data ([string]$target.service) php `
    /opt/consolidator/v8-course-themes.php `
    '--plan=/exports/theme-plan.json' `
    '--assignments=/exports/theme-assignments' `
    "--maps=$($maps -join ',')" "--output=$output" `
    "--scope=$($Scope.ToLowerInvariant())"
if ($LASTEXITCODE -ne 0) { throw 'V8_THEME_ASSIGNMENTS_FAILED' }
