param(
    [ValidateSet('Discover', 'Revalidate', 'Approve', 'Check', 'Invalidate')]
    [string]$Mode = 'Discover'
)

. "$PSScriptRoot/Common.ps1"
. "$PSScriptRoot/PluginIntervention.ps1"
. "$PSScriptRoot/MoodleVersion.ps1"
Assert-ConfigurationConfirmed
Assert-Command 'docker'
& docker info *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Engine no responde.' }

$target = Get-TargetSite
$service = [string]$target.service
$root = Join-Path $ProjectRoot 'exports/phase2'
New-Item -ItemType Directory -Path $root -Force | Out-Null
$beforePath = Join-Path $root 'plugin_inventory_before.json'
$needsPath = Join-Path $root 'plugin_compatibility_needs.json'
$afterPath = Join-Path $root 'plugin_inventory_after.json'
$diffPath = Join-Path $root 'plugin_inventory_diff.json'
$technicalPath = Join-Path $root 'plugin_technical_validation.json'
$checkpointPath = Join-Path $root 'plugin_intervention_checkpoint.json'
$resolutionPath = Join-Path $root 'plugin_intervention_resolution.json'
$pinPath = Join-Path $ProjectRoot 'docker/custom-plugins/approved-plugins.json'
$equivalencePath = Join-Path $ProjectRoot 'config/plugin_equivalences.csv'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Read-Document([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
    return (Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json)
}
function File-Hash([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}
function Write-Document([string]$Path, $Data, [bool]$Immutable = $false) {
    $json = ($Data | ConvertTo-Json -Depth 80) + [Environment]::NewLine
    if ($Immutable) {
        $stream = [System.IO.File]::Open($Path, [System.IO.FileMode]::CreateNew)
        try {
            $bytes = $utf8.GetBytes($json)
            $stream.Write($bytes, 0, $bytes.Length)
        } finally { $stream.Dispose() }
    } else {
        $temporary = "$Path.tmp.$([guid]::NewGuid().ToString('N'))"
        try {
            [System.IO.File]::WriteAllText($temporary, $json, $utf8)
            [System.IO.File]::Move($temporary, $Path, $true)
        } finally {
            if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary }
        }
    }
}
function Get-Sources {
    $sources = @()
    $sha = @()
    foreach ($source in @($MigrationConfig.Sources | Sort-Object id)) {
        $id = [string]$source.id
        $base = Join-Path $ProjectRoot "exports/packages/$id"
        $manifestPath = Join-Path $base 'manifest.json'
        $pluginsPath = Join-Path $base 'plugins.json'
        $manifest = Read-Document $manifestPath
        $inventory = Read-Document $pluginsPath
        if ($null -eq $manifest -or $null -eq $inventory -or
                [string]$manifest.source_id -ne $id -or
                [string]$inventory.source_id -ne $id -or
                [bool]$inventory.write_performed -or
                ([string]$manifest.plugins_sha256).ToLowerInvariant() -cne
                    (File-Hash $pluginsPath)) {
            throw "El inventario de plugins de '$id' falta o perdió integridad."
        }
        $sources += [pscustomobject]@{ source_id = $id; inventory = $inventory }
        $sha += [ordered]@{
            source_id = $id; manifest_sha256 = File-Hash $manifestPath
            plugins_sha256 = File-Hash $pluginsPath
        }
    }
    $json = $sha | ConvertTo-Json -Depth 8 -Compress
    $digest = [System.Security.Cryptography.SHA256]::HashData($utf8.GetBytes($json))
    return [pscustomobject]@{
        sources = @($sources)
        fingerprint = [Convert]::ToHexString($digest).ToLowerInvariant()
    }
}
function Get-Bindings {
    if (-not (Test-Path -LiteralPath $pinPath -PathType Leaf)) {
        throw 'Falta el manifiesto de plugins empaquetados.'
    }
    $sourceData = Get-Sources
    return [pscustomobject]@{
        source_data = $sourceData
        config_sha256 = Get-ConfigurationHash
        source_fingerprint = $sourceData.fingerprint
        target_id = [string]$target.id
        target_service = $service
        target_wwwroot = [string]$target.url
        approved_plugins_sha256 = File-Hash $pinPath
        equivalences_sha256 = File-Hash $equivalencePath
    }
}
function Bindings-Match($Checkpoint, $Bindings, [bool]$Approved = $false) {
    if ($null -eq $Checkpoint) { return $false }
    foreach ($key in @('config_sha256', 'source_fingerprint', 'target_id',
            'target_service', 'target_wwwroot', 'approved_plugins_sha256')) {
        if ([string]$Checkpoint.$key -cne [string]$Bindings.$key) { return $false }
    }
    if ($Approved -and [string]$Checkpoint.equivalences_sha256 -cne
            [string]$Bindings.equivalences_sha256) { return $false }
    return $true
}
function Assert-SealedBefore($Checkpoint) {
    if ([string]$Checkpoint.inventory_before_sha256 -cne (File-Hash $beforePath) -or
            [string]$Checkpoint.compatibility_needs_sha256 -cne (File-Hash $needsPath)) {
        throw 'PLUGIN_INTERVENTION_SEAL_INVALID: BEFORE o necesidades cambiaron.'
    }
}
function Get-LiveInventory {
    $output = & docker compose exec -T -u www-data $service `
        php /opt/consolidator/target-plugins.php '--output=-' `
        "--targetid=$($target.id)" 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PLUGIN_TECHNICAL_VALIDATION_FAILED: inventario CLI: $($output -join ' ')"
    }
    try { $inventory = $output -join "`n" | ConvertFrom-Json }
    catch { throw 'PLUGIN_TECHNICAL_VALIDATION_FAILED: inventario CLI ilegible.' }
    if ([string]$inventory.schema_version -ne '1.1' -or
            [bool]$inventory.write_performed) {
        throw 'PLUGIN_TECHNICAL_VALIDATION_FAILED: contrato del inventario inválido.'
    }
    return $inventory
}
function Test-ApprovedAdditionalPlugin($Inventory, [string]$Component) {
    $plugin = @($Inventory.plugins | Where-Object component -eq $Component |
        Select-Object -First 1)
    $pin = @($Inventory.approved_plugins | Where-Object component -eq $Component |
        Select-Object -First 1)
    if ($plugin.Count -ne 1) { return $false }
    if ([string]$plugin[0].source -ne 'additional') { return $true }
    return (
        $pin.Count -eq 1 -and
        [string]$plugin[0].version_disk -ceq [string]$pin[0].version -and
        [string]$plugin[0].approved_commit -ceq [string]$pin[0].commit -and
        [string]$plugin[0].tree_sha256 -ceq [string]$pin[0].tree_sha256 -and
        (($null -eq $plugin[0].release) -eq ($null -eq $pin[0].release)) -and
        [string]$plugin[0].release -ceq [string]$pin[0].release
    )
}

function Enable-RequiredDisabledActivityModules($Needs, $Inventory) {
    $components = @(
        $Needs |
            Where-Object {
                [bool]$_.used_activity -and
                [string]$_.observed_state -in @(
                    'installed_disabled', 'removed_core_component'
                ) -and
                [string]$_.component -match '^mod_[a-z][a-z0-9_]*$'
            } |
            ForEach-Object { [string]$_.component } |
            Sort-Object -Unique
    )
    $changed = $false
    foreach ($component in $components) {
        $need = @($Needs | Where-Object component -eq $component |
            Select-Object -First 1)
        $plugin = @($Inventory.plugins | Where-Object component -eq $component |
            Select-Object -First 1)
        if ($plugin.Count -ne 1) {
            if ([string]$need[0].observed_state -eq 'removed_core_component') {
                continue
            }
            throw "PLUGIN_REQUIRED_ENABLE_FAILED: ${component}: no está instalado."
        }
        if (-not (Test-ApprovedAdditionalPlugin $Inventory $component)) {
            throw "PLUGIN_PIN_REQUIRED: $component no coincide con un pin aprobado."
        }
        $additional = $plugin.Count -eq 1 -and
            [string]$plugin[0].source -eq 'additional'
        if ([string]$need[0].observed_state -eq 'removed_core_component') {
            Write-Host "PLUGIN_REPLACEMENT_FOUND component=$component" `
                -ForegroundColor DarkGray
        }
        if ($additional) {
            Write-Host "TARGET_PLUGIN_PIN_VERIFIED component=$component" `
                -ForegroundColor DarkGray
        }
        $output = & docker compose exec -T -u www-data $service `
            php /opt/consolidator/target-enable-plugin.php `
            "--component=$component" `
            "--pin-verified=$(if ($additional) { 1 } else { 0 })" 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw (
                "PLUGIN_REQUIRED_ENABLE_FAILED: ${component}: " +
                ($output -join ' ')
            )
        }
        $output | ForEach-Object { Write-Host $_ -ForegroundColor DarkGray }
        Write-Host "TARGET_PLUGIN_ENABLE_OK component=$component" `
            -ForegroundColor DarkGray
        $changed = $true
    }
    return $changed
}

function Invoke-DockerCaptured([string[]]$Arguments) {
    $start = [System.Diagnostics.ProcessStartInfo]::new()
    $start.FileName = 'docker'
    foreach ($argument in $Arguments) {
        [void]$start.ArgumentList.Add([string]$argument)
    }
    $start.WorkingDirectory = $ProjectRoot
    $start.UseShellExecute = $false
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'PLUGIN_UPGRADE_FAILED: docker no inició.' }
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    $result = [pscustomobject]@{
        ExitCode = $process.ExitCode
        Stdout = $stdout.Trim()
        Stderr = $stderr.Trim()
    }
    $process.Dispose()
    return $result
}

function Get-PluginUpgradeTimeoutSeconds {
    $timeoutSeconds = 300
    $configuredTimeout = 0
    if ([int]::TryParse([string]$env:PLUGIN_UPGRADE_WAIT_TIMEOUT_SECONDS,
            [ref]$configuredTimeout) -and $configuredTimeout -ge 1 -and
            $configuredTimeout -le 3600) {
        $timeoutSeconds = $configuredTimeout
    }
    return $timeoutSeconds
}

function Wait-DestinationStartupReady {
    $timeoutSeconds = Get-PluginUpgradeTimeoutSeconds
    $retrySeconds = 5
    $configuredRetry = 0
    if ([int]::TryParse([string]$env:PLUGIN_UPGRADE_RETRY_SECONDS,
            [ref]$configuredRetry) -and $configuredRetry -ge 1 -and
            $configuredRetry -le 60) {
        $retrySeconds = $configuredRetry
    }
    $deadline = [DateTimeOffset]::UtcNow.AddSeconds($timeoutSeconds)
    $attempt = 0
    while ($true) {
        $attempt++
        $marker = Invoke-DockerCaptured @(
            'compose', 'exec', '-T', $service,
            'test', '-f', '/run/moodle-startup-ready'
        )
        if ($marker.ExitCode -eq 0) {
            $probe = Invoke-DockerCaptured @(
                'compose', 'exec', '-T', '-u', 'www-data', $service,
                'php', '-r',
                'define("CLI_SCRIPT", true); require "/var/www/html/config.php"; global $DB; $DB->get_field_sql("SELECT 1"); echo "DESTINATION_READY\n";'
            )
            if ($probe.ExitCode -eq 0 -and $probe.Stdout -match 'DESTINATION_READY') {
                Write-Host "DESTINATION_STARTUP_READY attempts=$attempt" `
                    -ForegroundColor Green
                return [pscustomobject]@{ Ready = $true; Attempts = $attempt }
            }
        }
        if ([DateTimeOffset]::UtcNow.AddSeconds($retrySeconds) -gt $deadline) {
            throw "PLUGIN_UPGRADE_FAILED: startup readiness timeout=${timeoutSeconds}s attempts=$attempt"
        }
        Write-Host "DESTINATION_STARTUP_WAIT attempt=$attempt timeout=${timeoutSeconds}s" `
            -ForegroundColor Yellow
        Start-Sleep -Seconds $retrySeconds
    }
}

function Test-DestinationUpgradeRequired {
    $result = Invoke-DockerCaptured @(
        'compose', 'exec', '-T', '-u', 'www-data', $service,
        'php', '/var/www/html/admin/cli/upgrade.php', '--is-pending'
    )
    if ($result.ExitCode -eq 0) {
        Write-Host 'DESTINATION_UPGRADE_NOT_REQUIRED' -ForegroundColor DarkGray
        return $false
    }
    if ($result.ExitCode -eq 2) {
        Write-Host 'DESTINATION_UPGRADE_REQUIRED' -ForegroundColor Yellow
        return $true
    }
    throw (
        'PLUGIN_UPGRADE_FAILED: no se pudo consultar --is-pending; exit=' +
        $result.ExitCode + ' stderr=' + $result.Stderr
    )
}

function Invoke-SerializedDestinationUpgrade {
    $timeoutSeconds = Get-PluginUpgradeTimeoutSeconds
    return Invoke-DockerCaptured @(
        'compose', 'exec', '-T', '-u', 'www-data', $service,
        'flock', '--exclusive', "--timeout=$timeoutSeconds",
        '/run/moodle-upgrade.lock', 'php',
        '/var/www/html/admin/cli/upgrade.php', '--non-interactive'
    )
}

function Get-PluginUpgradeFailureDiagnostic(
        [string]$Component, $Result, [string]$MoodleState = 'upgrade_failed') {
    $container = Invoke-DockerCaptured @('compose', 'ps', '-q', $service)
    $restartCount = $null
    $containerState = $MoodleState
    if ($container.ExitCode -eq 0 -and $container.Stdout) {
        $inspection = Invoke-DockerCaptured @(
            'inspect', '--format',
            '{{.RestartCount}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}',
            $container.Stdout
        )
        if ($inspection.ExitCode -eq 0 -and $inspection.Stdout -match '^([0-9]+)\|([^|]+)\|(.+)$') {
            $restartCount = [int]$Matches[1]
            $containerState = "$($Matches[2])/$($Matches[3])"
        }
    }
    $lines = @(
        @($Result.Stderr, $Result.Stdout) |
            Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } |
            ForEach-Object { ([string]$_ -split "`r?`n")[0].Trim() }
    )
    return [ordered]@{
        status = 'PLUGIN_UPGRADE_FAILED'
        first_error = if ($lines.Count -gt 0) { $lines[0] } else { 'upgrade sin detalle' }
        component = $Component
        exit_code = [int]$Result.ExitCode
        stdout = [string]$Result.Stdout
        stderr = [string]$Result.Stderr
        restart_count = $restartCount
        moodle_state = $containerState
    }
}

# Compatibilidad interna para tests/consumidores anteriores: ya no ejecuta
# upgrade como mecanismo de espera; espera readiness, consulta y serializa.
function Invoke-DestinationUpgradeWait {
    Wait-DestinationStartupReady | Out-Null
    if (-not (Test-DestinationUpgradeRequired)) {
        return [pscustomobject]@{ Ready = $true; Attempts = 0; Output = @() }
    }
    $result = Invoke-SerializedDestinationUpgrade
    if ($result.ExitCode -ne 0) {
        return [pscustomobject]@{
            Ready = $false; Attempts = 1
            Output = @($result.Stdout, $result.Stderr)
            Diagnostic = Get-PluginUpgradeFailureDiagnostic '' $result
        }
    }
    Wait-DestinationStartupReady | Out-Null
    Write-Host 'DESTINATION_UPGRADE_READY attempts=1' -ForegroundColor Green
    return [pscustomobject]@{ Ready = $true; Attempts = 1; Output = @($result.Stdout) }
}

function Test-RemovedCoreComponentResolution($Need, $Inventory) {
    $component = [string]$Need.component
    $plugin = @($Inventory.plugins | Where-Object component -eq $component |
        Select-Object -First 1)
    if ($plugin.Count -ne 1 -or
            [string]$plugin[0].source -ne 'additional' -or
            -not (Test-ApprovedAdditionalPlugin $Inventory $component)) {
        return $false
    }
    $disk = [long]0
    $db = [long]0
    if (-not [long]::TryParse([string]$plugin[0].version_disk, [ref]$disk) -or
            -not [long]::TryParse([string]$plugin[0].version_db, [ref]$db) -or
            $disk -lt 1 -or $db -ne $disk -or
            -not [bool]$plugin[0].version_file_valid -or
            [string]$plugin[0].installation_status -notin @('uptodate', 'installed') -or
            $plugin[0].enabled -eq $false) {
        return $false
    }
    return $true
}

function Assert-After-Seal($Checkpoint) {
    if ([string]$Checkpoint.inventory_after_sha256 -cne (File-Hash $afterPath) -or
            [string]$Checkpoint.inventory_diff_sha256 -cne (File-Hash $diffPath) -or
            [string]$Checkpoint.technical_validation_sha256 -cne (File-Hash $technicalPath)) {
        throw 'PLUGIN_INTERVENTION_SEAL_INVALID: la revalidación cambió.'
    }
}
function Assert-ExistingSeals($Checkpoint) {
    Assert-SealedBefore $Checkpoint
    foreach ($entry in @(
            @('inventory_after_sha256', $afterPath),
            @('inventory_diff_sha256', $diffPath),
            @('technical_validation_sha256', $technicalPath),
            @('resolution_sha256', $resolutionPath))) {
        $expected = [string]$Checkpoint.($entry[0])
        if ($expected -and $expected -cne (File-Hash $entry[1])) {
            throw 'PLUGIN_INTERVENTION_SEAL_INVALID: el contrato anterior cambió.'
        }
    }
}
function Test-Approved($Checkpoint, $Bindings, [bool]$Live = $true) {
    if (-not (Bindings-Match $Checkpoint $Bindings $true) -or
            [string]$Checkpoint.status -ne 'PLUGIN_INTERVENTION_APPROVED') { return $false }
    Assert-SealedBefore $Checkpoint
    Assert-After-Seal $Checkpoint
    if ((File-Hash $resolutionPath) -cne [string]$Checkpoint.resolution_sha256) {
        throw 'PLUGIN_INTERVENTION_SEAL_INVALID: resolución modificada.'
    }
    $after = Read-Document $afterPath
    $resolution = Read-Document $resolutionPath
    if (-not (Test-PluginInterventionResolution -Resolution $resolution `
            -ConfigHash $Bindings.config_sha256 -TargetId $Bindings.target_id `
            -SourceFingerprint $Bindings.source_fingerprint `
            -BeforeHash (File-Hash $beforePath) -AfterHash (File-Hash $afterPath) `
            -AfterFingerprint (Get-PluginInventoryFingerprint $after) `
            -PinHash $Bindings.approved_plugins_sha256) -or
            [string]$resolution.equivalences_sha256 -cne [string]$Bindings.equivalences_sha256) {
        throw 'PLUGIN_INTERVENTION_SEAL_INVALID: aprobación incompleta.'
    }
    if ($Live) {
        $inventory = Get-LiveInventory
        if ((Get-PluginInventoryFingerprint $inventory) -cne
                [string]$resolution.inventory_after_fingerprint) { return $false }
    }
    return $true
}
function Archive-Intervention {
    $archive = Join-Path $root ("history/" + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ') +
        '-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force -Path $archive | Out-Null
    foreach ($path in @($beforePath, $needsPath, $afterPath, $diffPath,
            $technicalPath, $resolutionPath, $checkpointPath)) {
        if (Test-Path -LiteralPath $path) {
            Move-Item -LiteralPath $path -Destination $archive
        }
    }
    Write-Host "Contrato previo archivado en $archive" -ForegroundColor DarkGray
}
function Technical-Revalidate($Bindings, $Before, $Needs, $Checkpoint) {
    $currentComponent = [string](@($Needs | Select-Object -First 1).component)
    try {
        $upgrade = Invoke-DestinationUpgradeWait
    } catch {
        $failedResult = [pscustomobject]@{
            ExitCode = 1; Stdout = ''; Stderr = $_.Exception.Message
        }
        $upgrade = [pscustomobject]@{
            Ready = $false; Attempts = 0; Output = @($_.Exception.Message)
            Diagnostic = Get-PluginUpgradeFailureDiagnostic `
                $currentComponent $failedResult 'readiness_or_pending_probe_failed'
        }
    }
    $upgradeSucceeded = [bool]$upgrade.Ready
    $moodleReady = [bool]$upgrade.Ready
    if (-not $upgradeSucceeded) {
        $diagnostic = $upgrade.Diagnostic
        if ($null -eq $diagnostic) {
            $fallback = [pscustomobject]@{
                ExitCode = 1; Stdout = (@($upgrade.Output) -join "`n"); Stderr = ''
            }
            $diagnostic = Get-PluginUpgradeFailureDiagnostic `
                $currentComponent $fallback
        }
        $failure = [ordered]@{
            schema_version = '1.0'
            generated_at_utc = [DateTime]::UtcNow.ToString('o')
            status = 'PLUGIN_UPGRADE_FAILED'
            passed = $false
            failure_kind = 'upgrade'
            upgrade_failure = $diagnostic
            issues = @([ordered]@{
                component = $diagnostic.component
                field = 'upgrade'
                value = $diagnostic.exit_code
                message = 'PLUGIN_UPGRADE_FAILED: ' + $diagnostic.first_error
            })
        }
        Write-Document $technicalPath $failure
        $Checkpoint.status = 'PLUGIN_UPGRADE_FAILED'
        $Checkpoint.technical_validation_sha256 = File-Hash $technicalPath
        Write-Document $checkpointPath $Checkpoint
        Write-Host ('PLUGIN_UPGRADE_FAILED ' +
            ($diagnostic | ConvertTo-Json -Depth 8 -Compress)) -ForegroundColor Red
        return [pscustomobject]@{
            status = 'PLUGIN_UPGRADE_FAILED'; issues = $failure.issues
        }
    }
    try {
        $after = Get-LiveInventory
        $enabled = Enable-RequiredDisabledActivityModules $Needs $after
        if ($enabled) { $after = Get-LiveInventory }
    }
    catch {
        $failure = [ordered]@{
            schema_version = '1.0'; generated_at_utc = [DateTime]::UtcNow.ToString('o')
            status = 'PLUGIN_TECHNICAL_VALIDATION_FAILED'; passed = $false
            failure_kind = 'inventory_collection'
            issues = @([ordered]@{ component = ''; field = 'inventory_cli'
                value = $null; message = $_.Exception.Message })
        }
        Write-Document $technicalPath $failure
        $Checkpoint.status = 'PLUGIN_TECHNICAL_VALIDATION_FAILED'
        $Checkpoint.technical_validation_sha256 = File-Hash $technicalPath
        Write-Document $checkpointPath $Checkpoint
        return [pscustomobject]@{ status = 'PLUGIN_TECHNICAL_VALIDATION_FAILED'; issues = $failure.issues }
    }
    # Sellar AFTER antes de validar: los fallos de contrato siguen siendo
    # diagnosticables y se pueden reintentar con R sin perder BEFORE.
    Write-Document $afterPath $after
    $diffWritten = $false
    try {
        $diff = Get-PluginInventoryDiff -Before $Before -After $after
        Write-Document $diffPath $diff
        $diffWritten = $true
        $technical = Test-PluginTechnicalInventory -Inventory $after `
            -TargetId $Bindings.target_id -ExpectedUrl $Bindings.target_wwwroot `
            -PinHash $Bindings.approved_plugins_sha256 `
            -UpgradeSucceeded $upgradeSucceeded -MoodleReady $moodleReady
    } catch {
        $technical = [pscustomobject]@{ passed = $false; issues = @(
            [ordered]@{ component = ''; field = 'inventory'
                value = $null; message = "PLUGIN_TECHNICAL_VALIDATION_FAILED: $($_.Exception.Message)" }
        ) }
        if (-not $diffWritten) {
            Write-Document $diffPath ([ordered]@{
                newly_detected_plugins = @(); updated_plugins = @(); removed_plugins = @()
                error = $_.Exception.Message
            })
        }
        $diff = Read-Document $diffPath
    }
    $issues = @($technical.issues)
    $requiredUsed = @{}
    foreach ($need in @($Needs)) {
        if ([bool]$need.used_activity) {
            $requiredUsed[[string]$need.component] = $true
        }
    }
    foreach ($plugin in @($after.plugins)) {
        $component = [string]$plugin.component
        if ($requiredUsed.ContainsKey($component) -and
                ([string]$plugin.installation_status -eq 'disabled' -or
                 $plugin.enabled -eq $false)) {
            $issues += [ordered]@{
                component = $component
                field = 'enabled'
                value = $plugin.enabled
                message = "PLUGIN_REQUIRED_DISABLED: $component es usado por cursos y debe quedar habilitado antes de READY_TO_RUN."
            }
        }
    }
    $issuesList = New-Object System.Collections.Generic.List[object]
    $targetVersion = ConvertTo-MoodleVersion $after.moodle_version `
        $Bindings.target_id $issuesList
    foreach ($source in @($Bindings.source_data.sources)) {
        $sourceVersion = ConvertTo-MoodleVersion $source.inventory.moodle_version `
            $source.source_id $issuesList
        if ($null -ne $sourceVersion -and $null -ne $targetVersion -and
                $sourceVersion -gt $targetVersion) {
            $issues += "Moodle origen más reciente: $($source.source_id)"
        }
    }
    $removedResolved = @()
    foreach ($need in @($Needs | Where-Object observed_state -eq 'removed_core_component')) {
        if (Test-RemovedCoreComponentResolution $need $after) {
            $component = [string]$need.component
            $removedResolved += [ordered]@{
                component = $component
                source_id = [string]$need.source_id
                status = 'REMOVED_CORE_COMPONENT_RESOLVED'
            }
            Write-Host "REMOVED_CORE_COMPONENT_RESOLVED component=$component" `
                -ForegroundColor Green
        } else {
            $issues += [ordered]@{
                component = [string]$need.component
                field = 'removed_core_component'
                value = [string]$need.source_id
                message = "REMOVED_CORE_COMPONENT: $($need.component) en $($need.source_id): replacement ausente, sin pin, deshabilitado o con upgrade incompleto."
            }
        }
    }
    foreach ($source in @($Bindings.source_data.sources)) {
        $evotingSources = @($source.inventory.plugins |
            Where-Object component -eq 'mod_evoting')
        if (@($source.inventory.used_activity_modules) -contains 'evoting' -and
                $evotingSources.Count -eq 0) {
            $issues += [ordered]@{
                component = 'mod_evoting'; field = 'source_inventory'
                value = [string]$source.source_id
                message = 'EVOTING_SOURCE_UNVERIFIED: actividad usada sin versión de origen comprobable.'
            }
        }
        foreach ($sourcePlugin in $evotingSources) {
            $sourceVersion = ConvertTo-PluginVersion $sourcePlugin.version_disk `
                'mod_evoting' 'source.version_disk' $issuesList
            $installed = @($after.plugins | Where-Object component -eq 'mod_evoting' |
                Select-Object -First 1)
            $pin = @($after.approved_plugins | Where-Object component -eq 'mod_evoting' |
                Select-Object -First 1)
            if ($installed.Count -ne 1 -or $pin.Count -ne 1 -or
                    [string]$pin[0].provenance.kind -ne 'institutional_git') {
                $issues += [ordered]@{
                    component = 'mod_evoting'; field = 'provenance'
                    value = [string]$source.source_id
                    message = 'EVOTING_SOURCE_UNVERIFIED: se requiere mod_evoting compatible con pin Git y procedencia institucional verificada; no se acepta un plugin viejo o solo el nombre.'
                }
                continue
            }
            $targetPluginVersion = ConvertTo-PluginVersion $installed[0].version_disk `
                'mod_evoting' 'target.version_disk' $issuesList
            if ($null -ne $sourceVersion -and $null -ne $targetPluginVersion -and
                    $targetPluginVersion -lt $sourceVersion) {
                $issues += [ordered]@{
                    component = 'mod_evoting'; field = 'version_disk'
                    value = $targetPluginVersion
                    message = "EVOTING_VERSION_DOWNGRADE: origen $($source.source_id) versión $sourceVersion; destino $targetPluginVersion."
                }
            }
        }
    }
    $issues += @($issuesList.ToArray())
    $passed = $technical.passed -and $issues.Count -eq 0
    Write-Document $technicalPath ([ordered]@{
        schema_version = '1.0'; generated_at_utc = [DateTime]::UtcNow.ToString('o')
        status = if ($passed) { 'passed' } else { 'PLUGIN_TECHNICAL_VALIDATION_FAILED' }
        passed = $passed; issues = @($issues)
        failure_kind = if ($passed) { '' } else { 'technical_validation' }
        upgrade_succeeded = $upgradeSucceeded; moodle_ready = $moodleReady
        resolved_removed_core_components = @($removedResolved)
    })
    $Checkpoint.inventory_after_sha256 = File-Hash $afterPath
    $Checkpoint.inventory_diff_sha256 = File-Hash $diffPath
    $Checkpoint.technical_validation_sha256 = File-Hash $technicalPath
    $Checkpoint.status = if ($passed) { 'READY_FOR_APPROVAL' } else {
        'PLUGIN_TECHNICAL_VALIDATION_FAILED'
    }
    Write-Document $checkpointPath $Checkpoint
    Write-Host "Necesidades originales: $(@($Needs).Count)" -ForegroundColor Cyan
    foreach ($need in @($Needs)) {
        Write-Host "  $($need.display_name) [$($need.component)]: $($need.observed_state)"
    }
    Write-Host "Plugins nuevos: $(@($diff.newly_detected_plugins).Count); actualizados: $(@($diff.updated_plugins).Count); eliminados: $(@($diff.removed_plugins).Count)."
    foreach ($plugin in @($diff.newly_detected_plugins)) {
        Write-Host "  + $($plugin.name) [$($plugin.component)] $($plugin.version)"
    }
    foreach ($plugin in @($diff.updated_plugins)) {
        Write-Host "  ~ $($plugin.name) [$($plugin.component)] $($plugin.before_version) -> $($plugin.after_version)"
    }
    foreach ($plugin in @($diff.removed_plugins)) {
        Write-Host "  - $($plugin.name) [$($plugin.component)]"
    }
    if (-not $passed) {
        foreach ($issue in $issues) {
            $message = if ($issue -is [System.Collections.IDictionary]) {
                [string]$issue.message
            } else { [string]$issue }
            Write-Host "  ERROR: $message" -ForegroundColor Red
        }
    }
    return [pscustomobject]@{ status = $Checkpoint.status; issues = @($issues) }
}
function Read-Equivalences($Needs, $Inventory) {
    $rows = @()
    if (-not (Test-Path -LiteralPath $equivalencePath)) { return $rows }
    $header = @(Get-Content -LiteralPath $equivalencePath -TotalCount 1)
    if ($header.Count -ne 1 -or
            $header[0].TrimStart([char]0xfeff) -cne
                'source_requirement,target_components') {
        throw 'config/plugin_equivalences.csv debe definir source_requirement,target_components.'
    }
    $validSources = @{}
    $validTargets = @{}
    foreach ($need in @($Needs)) { $validSources[[string]$need.component] = $true }
    foreach ($plugin in @($Inventory.plugins)) { $validTargets[[string]$plugin.component] = $true }
    foreach ($row in @(Import-Csv -LiteralPath $equivalencePath -Encoding UTF8)) {
        if (-not $row.source_requirement -or -not $row.target_components -or
                -not $validSources.ContainsKey([string]$row.source_requirement)) {
            throw 'Equivalencia no declarada en las necesidades originales.'
        }
        $targets = @(([string]$row.target_components).Split(',') |
            ForEach-Object { $_.Trim() } | Where-Object { $_ })
        foreach ($component in $targets) {
            if (-not $validTargets.ContainsKey($component)) {
                throw "Componente de equivalencia no instalado: $component"
            }
        }
        $rows += [ordered]@{ source_requirement = [string]$row.source_requirement
            target_components = @($targets) }
    }
    return $rows
}

$bindings = Get-Bindings
$checkpoint = Read-Document $checkpointPath
$revalidateAfterDiscover = $false
if ($Mode -eq 'Check') {
    $approved = Test-Approved $checkpoint $bindings
    if ($approved) { Write-Host 'PLUGIN_INTERVENTION_APPROVED' -ForegroundColor Green }
    else { Write-Host 'PLUGIN_INTERVENTION_PENDING' -ForegroundColor Yellow }
    return [pscustomobject]@{ status = if ($approved) {
        'PLUGIN_INTERVENTION_APPROVED'
    } else { 'PLUGIN_INTERVENTION_PENDING' } }
}
if ($Mode -eq 'Invalidate') {
    Archive-Intervention
    Write-Host 'PLUGIN_INTERVENTION_PENDING'
    return
}
if ($Mode -eq 'Revalidate') {
    if ($null -ne $checkpoint) { Assert-ExistingSeals $checkpoint }
    if ([string]$checkpoint.status -eq 'PLUGIN_INTERVENTION_APPROVED' -and
            (Test-Approved $checkpoint $bindings)) {
        Write-Host 'PLUGIN_INTERVENTION_APPROVED' -ForegroundColor Green
        return [pscustomobject]@{ status = 'PLUGIN_INTERVENTION_APPROVED' }
    }
    if ($null -eq $checkpoint -or -not (Bindings-Match $checkpoint $bindings $true) -or
            [string]$checkpoint.status -eq 'PLUGIN_INTERVENTION_APPROVED') {
        $Mode = 'Discover'
        $revalidateAfterDiscover = $true
    }
}
if ($Mode -eq 'Discover') {
    $reapproval = $false
    if ($null -ne $checkpoint) {
        Assert-ExistingSeals $checkpoint
        if (Bindings-Match $checkpoint $bindings $true) {
            Assert-SealedBefore $checkpoint
            if ([string]$checkpoint.status -eq 'PLUGIN_INTERVENTION_APPROVED') {
                if (Test-Approved $checkpoint $bindings) {
                    Write-Host 'PLUGIN_INTERVENTION_APPROVED' -ForegroundColor Green
                    return [pscustomobject]@{ status = 'PLUGIN_INTERVENTION_APPROVED' }
                }
                $reapproval = $true
                Archive-Intervention
            } else {
                Write-Host 'PLUGIN_INTERVENTION_PENDING: reutilizando BEFORE y checkpoint.' -ForegroundColor Yellow
                return [pscustomobject]@{ status = 'WAITING_USER_ACTION' }
            }
        } else {
            $reapproval = [string]$checkpoint.status -eq 'PLUGIN_INTERVENTION_APPROVED'
            Archive-Intervention
        }
    } elseif (Test-Path -LiteralPath $beforePath) {
        throw 'PLUGIN_INTERVENTION_SEAL_INVALID: BEFORE existe sin checkpoint.'
    }
    $before = Get-LiveInventory
    if ([string]$before.target_id -ne $bindings.target_id -or
            [string]$before.target_wwwroot -cne $bindings.target_wwwroot -or
            [string]$before.approved_plugins_sha256 -ne $bindings.approved_plugins_sha256) {
        throw 'PLUGIN_TECHNICAL_VALIDATION_FAILED: destino, URL o pins inconsistentes.'
    }
    $needs = @(Get-PluginCompatibilityNeeds -Sources $bindings.source_data.sources -Target $before)
    Write-Document $beforePath $before $true
    Write-Document $needsPath ([ordered]@{
        schema_version = '1.0'; generated_at_utc = [DateTime]::UtcNow.ToString('o')
        original_compatibility_needs = @($needs)
    }) $true
    $checkpoint = [ordered]@{
        schema_version = '1.0'; status = 'WAITING_USER_ACTION'
        config_sha256 = $bindings.config_sha256
        source_fingerprint = $bindings.source_fingerprint
        target_id = $bindings.target_id; target_service = $bindings.target_service
        target_wwwroot = $bindings.target_wwwroot
        approved_plugins_sha256 = $bindings.approved_plugins_sha256
        equivalences_sha256 = $bindings.equivalences_sha256
        inventory_before_sha256 = File-Hash $beforePath
        compatibility_needs_sha256 = File-Hash $needsPath
        review_required = $reapproval -or $revalidateAfterDiscover -or $needs.Count -gt 0
        inventory_after_sha256 = ''; inventory_diff_sha256 = ''
        technical_validation_sha256 = ''; resolution_sha256 = ''
    }
    Write-Document $checkpointPath $checkpoint
    if ($revalidateAfterDiscover) {
        return Technical-Revalidate $bindings $before $needs $checkpoint
    }
    if (-not $checkpoint.review_required) {
        $result = Technical-Revalidate $bindings $before $needs $checkpoint
        if ($result.status -eq 'READY_FOR_APPROVAL') {
            $Mode = 'Approve'
        } else { return $result }
    } else {
        Write-Host "INTERVENCIÓN REQUERIDA: $($needs.Count) necesidades. AFTER requiere revisión manual."
        return [pscustomobject]@{ status = 'WAITING_USER_ACTION' }
    }
}
$checkpoint = Read-Document $checkpointPath
if (-not (Bindings-Match $checkpoint $bindings $true) -or
        [string]$checkpoint.status -eq 'PLUGIN_INTERVENTION_APPROVED') {
    throw 'PLUGIN_INTERVENTION_PENDING: contrato inválido o ya aprobado.'
}
Assert-SealedBefore $checkpoint
$before = Read-Document $beforePath
$needs = @((Read-Document $needsPath).original_compatibility_needs)
if ($Mode -eq 'Revalidate') {
    return Technical-Revalidate $bindings $before $needs $checkpoint
}
if ($Mode -eq 'Approve') {
    if ([string]$checkpoint.status -ne 'READY_FOR_APPROVAL') {
        throw 'PLUGIN_TECHNICAL_VALIDATION_FAILED: revalide antes de aprobar.'
    }
    Assert-After-Seal $checkpoint
    $technical = Read-Document $technicalPath
    if (-not [bool]$technical.passed -or
            [string]$technical.status -ne 'passed') {
        throw 'PLUGIN_TECHNICAL_VALIDATION_FAILED: no se ofrece aprobación.'
    }
    $after = Read-Document $afterPath
    $fingerprint = Get-PluginInventoryFingerprint $after
    $live = Get-LiveInventory
    if ((Get-PluginInventoryFingerprint $live) -cne $fingerprint) {
        $checkpoint.status = 'WAITING_USER_ACTION'
        Write-Document $checkpointPath $checkpoint
        throw 'PLUGIN_INTERVENTION_PENDING: inventario cambió, pulse R para revalidar.'
    }
    $equivalences = @(Read-Equivalences $needs $after)
    $checkpoint.equivalences_sha256 = $bindings.equivalences_sha256
    $diff = Read-Document $diffPath
    $resolution = [ordered]@{
        schema_version = '1.0'; generated_at_utc = [DateTime]::UtcNow.ToString('o')
        config_sha256 = $bindings.config_sha256; target_id = $bindings.target_id
        source_fingerprint = $bindings.source_fingerprint
        approved_plugins_sha256 = $bindings.approved_plugins_sha256
        equivalences_sha256 = $bindings.equivalences_sha256
        original_compatibility_needs = @($needs)
        inventory_before_sha256 = File-Hash $beforePath
        inventory_after_sha256 = File-Hash $afterPath
        inventory_after_fingerprint = $fingerprint
        newly_detected_plugins = @($diff.newly_detected_plugins)
        updated_plugins = @($diff.updated_plugins)
        removed_plugins = @($diff.removed_plugins)
        explicit_equivalences = @($equivalences)
        technical_validation = 'passed'
        operator_decision = if ($checkpoint.review_required) { 'approved' } else { 'not_required' }
        resolution = if ($checkpoint.review_required) {
            'manual_compatibility_accepted'
        } else { 'compatible_without_intervention' }
    }
    Write-Document $resolutionPath $resolution $true
    $checkpoint.resolution_sha256 = File-Hash $resolutionPath
    $checkpoint.status = 'PLUGIN_INTERVENTION_APPROVED'
    Write-Document $checkpointPath $checkpoint
    Write-Host 'PLUGIN_INTERVENTION_APPROVED' -ForegroundColor Green
    return [pscustomobject]@{ status = 'PLUGIN_INTERVENTION_APPROVED' }
}
