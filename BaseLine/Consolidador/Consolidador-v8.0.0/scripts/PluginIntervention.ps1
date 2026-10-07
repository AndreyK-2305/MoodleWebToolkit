. "$PSScriptRoot/PluginPreflight.ps1"

# Los hashes omiten únicamente generated_at_utc y contadores derivados; cada
# campo capaz de alterar una decisión de restore participa en la huella.
function Get-PluginInventoryFingerprint($Inventory) {
    $plugins = @($Inventory.plugins | Sort-Object component | ForEach-Object {
        [ordered]@{
            component = [string]$_.component
            type = [string]$_.type
            name = [string]$_.name
            version_db = [string]$_.version_db
            version_disk = [string]$_.version_disk
            release = if ($null -eq $_.release) { $null } else { [string]$_.release }
            source = [string]$_.source
            enabled = if ($null -eq $_.enabled) { 'unknown' } else { [bool]$_.enabled }
            installation_status = [string]$_.installation_status
            root_path = [string]$_.root_path
            tree_sha256 = [string]$_.tree_sha256
            approved_commit = [string]$_.approved_commit
            approved_path = [string]$_.approved_path
            dependencies = $_.dependencies
            version_file_valid = [bool]$_.version_file_valid
        }
    })
    $modules = @($Inventory.activity_modules | Sort-Object name | ForEach-Object {
        [ordered]@{ name = [string]$_.name; visible = [int]$_.visible }
    })
    $data = [ordered]@{
        target_id = [string]$Inventory.target_id
        target_wwwroot = [string]$Inventory.target_wwwroot
        moodle_version = [string]$Inventory.moodle_version
        moodle_release = [string]$Inventory.moodle_release
        approved_plugins_sha256 = [string]$Inventory.approved_plugins_sha256
        plugins = $plugins
        activity_modules = $modules
    }
    $bytes = [System.Text.Encoding]::UTF8.GetBytes(
        ($data | ConvertTo-Json -Depth 60 -Compress)
    )
    return [Convert]::ToHexString(
        [System.Security.Cryptography.SHA256]::HashData($bytes)
    ).ToLowerInvariant()
}

function Get-PluginInventoryDiff($Before, $After) {
    $left = @{}
    $right = @{}
    foreach ($plugin in @($Before.plugins)) { $left[[string]$plugin.component] = $plugin }
    foreach ($plugin in @($After.plugins)) { $right[[string]$plugin.component] = $plugin }
    $modulesBefore = @{}
    $modulesAfter = @{}
    foreach ($module in @($Before.activity_modules)) {
        $modulesBefore["mod_$([string]$module.name)"] = [int]$module.visible
    }
    foreach ($module in @($After.activity_modules)) {
        $modulesAfter["mod_$([string]$module.name)"] = [int]$module.visible
    }
    $new = @($right.Keys | Where-Object { -not $left.ContainsKey($_) } | Sort-Object | ForEach-Object {
        $p = $right[$_]
        [ordered]@{ component = $_; name = [string]$p.name;
            version = [string]$p.version_disk; release = [string]$p.release;
            enabled = $p.enabled }
    })
    $removed = @($left.Keys | Where-Object { -not $right.ContainsKey($_) } | Sort-Object | ForEach-Object {
        [ordered]@{ component = $_; name = [string]$left[$_].name }
    })
    $updated = @($left.Keys | Where-Object { $right.ContainsKey($_) } | Sort-Object | ForEach-Object {
        $a = $left[$_]
        $b = $right[$_]
        $stateA = if ($modulesBefore.ContainsKey($_)) { $modulesBefore[$_] } else { $null }
        $stateB = if ($modulesAfter.ContainsKey($_)) { $modulesAfter[$_] } else { $null }
        if ([string]$a.version_disk -cne [string]$b.version_disk -or
                [string]$a.version_db -cne [string]$b.version_db -or
                [string]$a.release -cne [string]$b.release -or
                [string]$a.tree_sha256 -cne [string]$b.tree_sha256 -or
                [string]$a.approved_commit -cne [string]$b.approved_commit -or
                [string]$a.installation_status -cne [string]$b.installation_status -or
                [string]$a.enabled -cne [string]$b.enabled -or $stateA -ne $stateB) {
            [ordered]@{ component = $_; name = [string]$b.name;
                before_version = [string]$a.version_disk;
                after_version = [string]$b.version_disk;
                before_release = [string]$a.release;
                after_release = [string]$b.release;
                before_enabled = $a.enabled; after_enabled = $b.enabled;
                before_module_visible = $stateA; after_module_visible = $stateB }
        }
    })
    return [ordered]@{ newly_detected_plugins = $new;
        updated_plugins = $updated; removed_plugins = $removed }
}

function Get-PluginCompatibilityNeeds($Sources, $Target) {
    $installed = @{}
    $modules = @{}
    foreach ($p in @($Target.plugins)) { $installed[[string]$p.component] = $p }
    foreach ($m in @($Target.activity_modules)) {
        $modules["mod_$([string]$m.name)"] = $m
    }
    $needs = New-Object System.Collections.Generic.List[object]
    foreach ($source in @($Sources)) {
        $used = @{}
        foreach ($module in @($source.inventory.used_activity_modules)) {
            $used["mod_$([string]$module)"] = $true
        }
        $observed = @{}
        foreach ($plugin in @($source.inventory.plugins)) {
            $component = [string]$plugin.component
            $observed[$component] = $true
            if (-not $used.ContainsKey($component) -and
                    [string]$plugin.source -ne 'additional') { continue }
            $present = if ($installed.ContainsKey($component)) { $installed[$component] } else { $null }
            $module = if ($modules.ContainsKey($component)) { $modules[$component] } else { $null }
            $result = Get-PluginPreflightStatus -Component $component `
                -Source $plugin -Target $present -Pin $null `
                -Used $used.ContainsKey($component) -Module $module -AllowUnpinned $true
            if ($result.status -in @('enabled', 'installed_enabled')) { continue }
            [void]$needs.Add([ordered]@{
                source_id = [string]$source.source_id
                component = $component
                display_name = if ([string]$plugin.name) { [string]$plugin.name } else { $component }
                source_version = [string]$plugin.version_disk
                source_release = [string]$plugin.release
                used_activity = $used.ContainsKey($component)
                observed_state = [string]$result.status
                target_component_observed = if ($null -ne $present) { $component } else { '' }
            })
        }
        foreach ($component in @($used.Keys | Sort-Object)) {
            if ($observed.ContainsKey($component)) { continue }
            [void]$needs.Add([ordered]@{
                source_id = [string]$source.source_id
                component = $component
                display_name = $component
                source_version = ''
                source_release = ''
                used_activity = $true
                observed_state = 'missing_source_inventory'
                target_component_observed = ''
            })
        }
    }
    return @($needs.ToArray() | Sort-Object source_id,component)
}

function ConvertTo-PluginVersion($Value, [string]$Component, [string]$Field,
        [System.Collections.Generic.List[object]]$Issues) {
    $parsed = [long]0
    if ($null -eq $Value -or $Value -is [array] -or
            $Value -is [System.Collections.IDictionary] -or
            $Value -is [pscustomobject] -or $Value -is [bool] -or
            $Value -isnot [string] -and $Value -isnot [ValueType] -or
            -not [long]::TryParse([string]$Value,
                [System.Globalization.NumberStyles]::None,
                [System.Globalization.CultureInfo]::InvariantCulture,
                [ref]$parsed)) {
        [void]$Issues.Add([ordered]@{
            component = $Component; field = $Field; value = $Value
            message = "PLUGIN_TECHNICAL_VALIDATION_FAILED: $Component.$Field requiere versión Int64 no negativa."
        })
        return $null
    }
    return $parsed
}

function Test-PluginTechnicalInventory {
    param($Inventory, [string]$TargetId, [string]$ExpectedUrl,
        [string]$PinHash, [bool]$UpgradeSucceeded, [bool]$MoodleReady)
    $issues = New-Object System.Collections.Generic.List[object]
    if (-not $MoodleReady -or -not $UpgradeSucceeded) {
        [void]$issues.Add('Moodle no inició o admin/cli/upgrade.php falló.')
    }
    if ([string]$Inventory.schema_version -ne '1.1' -or
            [string]$Inventory.target_id -ne $TargetId -or
            [string]$Inventory.target_wwwroot -cne $ExpectedUrl -or
            [string]$Inventory.approved_plugins_sha256 -ne $PinHash) {
        [void]$issues.Add('Inventario, destino, URL o pins no coinciden.')
    }
    $bycomponent = @{}
    foreach ($plugin in @($Inventory.plugins)) {
        $component = [string]$plugin.component
        if ($component -notmatch '^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$' -or
                $bycomponent.ContainsKey($component)) {
            [void]$issues.Add("Componente inválido o duplicado: $component")
        }
        $bycomponent[$component] = $plugin
    }
    foreach ($plugin in @($Inventory.plugins)) {
        $component = [string]$plugin.component
        $disk = ConvertTo-PluginVersion $plugin.version_disk $component 'version_disk' $issues
        $db = ConvertTo-PluginVersion $plugin.version_db $component 'version_db' $issues
        $status = [string]$plugin.installation_status
        $locationStatus = [string]$plugin.location_status
        $expectedLocationStatus = if ([string]$plugin.source -eq 'additional') {
            'installed_localizable'
        } else { 'core_component' }
        # Inventarios anteriores no incluían este campo; la compatibilidad se
        # conserva al leerlos. Todo inventario live RC4 sí lo publica y falla
        # cerrado si Moodle declaró un componente cuyo root real no existe.
        if (-not [string]::IsNullOrWhiteSpace($locationStatus) -and
                $locationStatus -cne $expectedLocationStatus) {
            [void]$issues.Add([ordered]@{
                component = $component
                field = 'location_status'
                value = $locationStatus
                message = "PLUGIN_LOCATION_INVALID: $component no es localizable en el dirroot efectivo de Moodle."
            })
        }
        if ($status -notin @('uptodate', 'installed', 'disabled') -or
                ($null -ne $db -and $null -ne $disk -and $db -gt 0 -and
                    ($disk -lt 1 -or $disk -ne $db)) -or
                ([string]$plugin.source -eq 'additional' -and
                    ($null -ne $disk -and $null -ne $db -and
                     ($disk -lt 1 -or $db -lt 1) -or
                     -not [bool]$plugin.version_file_valid -or
                     [string]$plugin.tree_sha256 -notmatch '^[a-f0-9]{64}$'))) {
            [void]$issues.Add("Plugin inválido o upgrade pendiente: $component")
        }
        $declared = $plugin.dependencies
        $depnames = if ($null -eq $declared) { @() }
        elseif ($declared -is [array]) {
            if ($declared.Count -gt 0) {
                [void]$issues.Add([ordered]@{ component = $component
                    field = 'dependencies'; value = $declared
                    message = 'PLUGIN_TECHNICAL_VALIDATION_FAILED: dependencies debe ser un mapa o [] vacío.' })
            }
            @()
        } elseif ($declared -is [System.Collections.IDictionary]) {
            @($declared.Keys)
        } elseif ($declared -is [pscustomobject]) {
            @($declared.PSObject.Properties.Name)
        } else {
            [void]$issues.Add([ordered]@{ component = $component
                field = 'dependencies'; value = $declared
                message = 'PLUGIN_TECHNICAL_VALIDATION_FAILED: dependencies debe ser un mapa.' })
            @()
        }
        foreach ($depcomponent in $depnames) {
            $value = if ($declared -is [System.Collections.IDictionary]) {
                $declared[$depcomponent]
            } else { $declared.PSObject.Properties[$depcomponent].Value }
            if ([string]$depcomponent -notmatch '^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$') {
                [void]$issues.Add([ordered]@{ component = $component
                    field = "dependencies.$depcomponent"; value = $value
                    message = "PLUGIN_TECHNICAL_VALIDATION_FAILED: nombre de dependencia inválido: $depcomponent" })
                continue
            }
            $minimum = ConvertTo-PluginVersion $value $component "dependencies.$depcomponent" $issues
            if ($null -eq $minimum) { continue }
            $installedVersion = if ($bycomponent.ContainsKey($depcomponent)) {
                ConvertTo-PluginVersion $bycomponent[$depcomponent].version_db `
                    $depcomponent 'version_db' $issues
            } else { $null }
            if ($minimum -gt 0 -and
                    ($null -eq $installedVersion -or $installedVersion -lt $minimum)) {
                [void]$issues.Add([ordered]@{ component = $component
                    field = "dependencies.$depcomponent"; value = $minimum
                    message = "Dependencia incumplida: $component -> $depcomponent (mínimo $minimum)" })
            }
        }
    }
    foreach ($pin in @($Inventory.approved_plugins)) {
        $component = [string]$pin.component
        if (-not $bycomponent.ContainsKey($component)) {
            [void]$issues.Add("Plugin empaquetado ausente: $component")
            continue
        }
        $p = $bycomponent[$component]
        if ([string]$p.approved_commit -cne [string]$pin.commit -or
                [string]$p.tree_sha256 -cne [string]$pin.tree_sha256 -or
                $null -eq (ConvertTo-PluginVersion $pin.version $component 'pin.version' $issues) -or
                $null -eq (ConvertTo-PluginVersion $p.version_disk $component 'version_disk' $issues) -or
                [string]$p.version_disk -cne [string]$pin.version -or
                (($null -eq $p.release) -ne ($null -eq $pin.release)) -or
                [string]$p.release -cne [string]$pin.release) {
            [void]$issues.Add("Integridad del plugin empaquetado alterada: $component")
        }
    }
    return [pscustomobject]@{ passed = $issues.Count -eq 0; issues = @($issues.ToArray()) }
}

function Test-PluginInterventionResolution {
    param($Resolution, [string]$ConfigHash, [string]$TargetId,
        [string]$SourceFingerprint, [string]$BeforeHash,
        [string]$AfterHash, [string]$AfterFingerprint,
        [string]$PinHash)
    return ($null -ne $Resolution -and
        [string]$Resolution.schema_version -eq '1.0' -and
        [string]$Resolution.config_sha256 -eq $ConfigHash -and
        [string]$Resolution.target_id -eq $TargetId -and
        [string]$Resolution.source_fingerprint -eq $SourceFingerprint -and
        [string]$Resolution.inventory_before_sha256 -eq $BeforeHash -and
        [string]$Resolution.inventory_after_sha256 -eq $AfterHash -and
        [string]$Resolution.inventory_after_fingerprint -eq $AfterFingerprint -and
        [string]$Resolution.approved_plugins_sha256 -eq $PinHash -and
        [string]$Resolution.technical_validation -eq 'passed' -and
        [string]$Resolution.operator_decision -in @('approved', 'not_required') -and
        [string]$Resolution.resolution -in @(
            'manual_compatibility_accepted', 'compatible_without_intervention')
    )
}
