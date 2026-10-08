param(
    [Parameter(Mandatory = $true)][string]$Stage,
    [Parameter(Mandatory = $true)][string]$Status,
    [Parameter(Mandatory = $true)][string]$Message,
    [string[]]$ReviewPaths = @()
)

. "$PSScriptRoot/Notifications.ps1"

Send-ConsolidationNotification `
    -Stage $Stage `
    -Status $Status `
    -Message $Message `
    -ReviewPaths $ReviewPaths
