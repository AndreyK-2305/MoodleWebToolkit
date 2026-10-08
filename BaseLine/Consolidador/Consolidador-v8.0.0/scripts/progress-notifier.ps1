[CmdletBinding()]
param(
    [switch]$Loop,
    [switch]$Once,
    [switch]$Force,
    [ValidateRange(1, 300)]
    [int]$PollSeconds = 15
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = Split-Path -Parent $PSScriptRoot

. "$PSScriptRoot/Notifications.ps1"

$reportsRoot = Join-Path $ProjectRoot 'reports'
$assistantStatePath = Join-Path $reportsRoot 'assistant-state.json'
$phase1ProgressPath = Join-Path $reportsRoot 'fase-1-progress.json'
$phase13ProgressPath = Join-Path $reportsRoot 'fase-6-workers-status.json'
$statePath = Join-Path $reportsRoot 'progress-email-state.json'
$activePath = Join-Path $reportsRoot 'progress-notifier.active'

function Read-JsonDocumentOrNull {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return $null
    }

    try {
        return Get-Content `
            -LiteralPath $Path `
            -Raw `
            -Encoding UTF8 |
            ConvertFrom-Json
    } catch {
        Write-Warning (
            "PROGRESS_EMAIL_STATE_READ_FAILED path=$Path " +
            $_.Exception.Message
        )
        return $null
    }
}

function Get-ProgressIntervalMinutes {
    $value = 0

    if (-not [int]::TryParse(
            [string]$env:CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES,
            [ref]$value
        ) -or
        $value -lt 1 -or
        $value -gt 1440) {

        return 15
    }

    return $value
}

function Read-ProgressEmailState {
    $result = @{}

    $document = Read-JsonDocumentOrNull -Path $statePath

    if ($null -eq $document -or $null -eq $document.stages) {
        return $result
    }

    foreach ($property in $document.stages.PSObject.Properties) {
        $result[[string]$property.Name] =
            [string]$property.Value
    }

    return $result
}

function Write-ProgressEmailState {
    param(
        [Parameter(Mandatory = $true)]
        [hashtable]$Stages
    )

    if (-not (Test-Path -LiteralPath $reportsRoot -PathType Container)) {
        New-Item `
            -ItemType Directory `
            -Force `
            -Path $reportsRoot |
            Out-Null
    }

    $document = [ordered]@{
        schema_version = '1.0'
        updated_at_utc = [DateTimeOffset]::UtcNow.ToString('o')
        stages = [ordered]@{}
    }

    foreach ($key in @($Stages.Keys | Sort-Object)) {
        $document.stages[$key] = [string]$Stages[$key]
    }

    $temporary = "$statePath.partial-$PID"

    $document |
        ConvertTo-Json -Depth 6 |
        Set-Content `
            -LiteralPath $temporary `
            -Encoding UTF8

    Move-Item `
        -LiteralPath $temporary `
        -Destination $statePath `
        -Force
}

function Get-SnapshotAgeText {
    param(
        [string]$Timestamp
    )

    $parsed = [DateTimeOffset]::MinValue

    if (-not [DateTimeOffset]::TryParse(
            $Timestamp,
            [ref]$parsed
        )) {
        return 'desconocida'
    }

    $seconds = [Math]::Max(
        0,
        [int](
            [DateTimeOffset]::UtcNow - $parsed
        ).TotalSeconds
    )

    if ($seconds -lt 60) {
        return "$seconds s"
    }

    $minutes = [Math]::Floor($seconds / 60)

    return "$minutes min"
}

function Get-ProgressMessage {
    param(
        [Parameter(Mandatory = $true)]
        [object]$AssistantState
    )

    $stage = [string]$AssistantState.stage

    if ($stage -eq '13-aplicar-lote') {

        $progress = Read-JsonDocumentOrNull `
            -Path $phase13ProgressPath

        if ($null -eq $progress) {
            return $null
        }

        $activeCount = @($progress.active).Count

        $age = Get-SnapshotAgeText `
            -Timestamp ([string]$progress.updated_at_utc)

        return (
            "Restauración masiva en progreso.`n" +
            "Cursos completados: $($progress.completed)/$($progress.total).`n" +
            "Progreso: $($progress.percent)%.`n" +
            "Con advertencias: $($progress.completed_with_warnings).`n" +
            "Fallidos: $($progress.failed).`n" +
            "Activos: $activeCount.`n" +
            "Pendientes: $($progress.pending).`n" +
            "Workers: $($progress.workers).`n" +
            "Última actualización del motor: hace $age."
        )
    }

    if ($stage -eq '01-importar-paquetes') {

        $progress = Read-JsonDocumentOrNull `
            -Path $phase1ProgressPath

        if ($null -eq $progress) {
            return $null
        }

        $age = Get-SnapshotAgeText `
            -Timestamp ([string]$progress.updated_at_utc)

        return (
            "Lectura/importación de paquetes en progreso.`n" +
            "Fuente: $($progress.source_id).`n" +
            "Cursos extraídos: $($progress.processed)/$($progress.total).`n" +
            "Progreso: $($progress.percent)%.`n" +
            "Curso actual: $($progress.current_course_file).`n" +
            "Índice actual: $($progress.current_index)/$($progress.total).`n" +
            "Última actualización del snapshot: hace $age."
        )
    }

    return $null
}

function Invoke-ProgressEmailCheck {

    if ([string]$env:CONSOLIDATION_PROGRESS_EMAIL_ENABLED -ne '1') {
        return
    }

    if (-not (Test-ConsolidationNotificationsEnabled)) {
        return
    }

    $assistant = Read-JsonDocumentOrNull `
        -Path $assistantStatePath

    if ($null -eq $assistant) {
        return
    }

    if ([string]$assistant.status -ne 'running') {
        return
    }

    $stage = [string]$assistant.stage

    if ($stage -notin @(
            '01-importar-paquetes',
            '13-aplicar-lote'
        )) {
        return
    }

    $message = Get-ProgressMessage `
        -AssistantState $assistant

    if ([string]::IsNullOrWhiteSpace($message)) {
        return
    }

    $now = [DateTimeOffset]::UtcNow
    $intervalMinutes = Get-ProgressIntervalMinutes
    $state = Read-ProgressEmailState

    $basis = [DateTimeOffset]::MinValue

    if ($state.ContainsKey($stage)) {

        [void][DateTimeOffset]::TryParse(
            [string]$state[$stage],
            [ref]$basis
        )

    } else {

        [void][DateTimeOffset]::TryParse(
            [string]$assistant.updated_at_utc,
            [ref]$basis
        )
    }

    if ($basis -eq [DateTimeOffset]::MinValue) {
        $basis = $now
    }

    $due = $Force -or (
        ($now - $basis).TotalMinutes -ge
        $intervalMinutes
    )

    if (-not $due) {
        return
    }

    Send-ConsolidationNotification `
        -Stage $stage `
        -Status 'EN PROGRESO' `
        -Message $message `
        -ReviewPaths @(
            if ($stage -eq '13-aplicar-lote') {
                'reports\fase-6-workers-status.json'
            } else {
                'reports\fase-1-progress.json'
            }
        )

    # Incluso si SMTP falla, Notifications.ps1 es fail-open.
    # Registramos el intento para evitar reintentos cada 15 s.
    $state[$stage] = $now.ToString('o')

    Write-ProgressEmailState `
        -Stages $state

    Write-Host (
        "PROGRESS_EMAIL_HEARTBEAT stage=$stage " +
        "interval_minutes=$intervalMinutes"
    ) -ForegroundColor DarkGray
}

if ($Once) {
    Invoke-ProgressEmailCheck
    return
}

if (-not $Loop) {
    throw 'Use -Loop o -Once.'
}

while (Test-Path -LiteralPath $activePath -PathType Leaf) {

    try {
        Invoke-ProgressEmailCheck
    } catch {
        Write-Warning (
            'PROGRESS_EMAIL_LOOP_WARNING: ' +
            $_.Exception.Message
        )
    }

    Start-Sleep -Seconds $PollSeconds
}
