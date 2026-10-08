function Get-PluginPreflightStatus {
    param([string]$Component, $Source, $Target, $Pin, [bool]$Used, $Module,
        [bool]$AllowUnpinned = $false)
    $status = "enabled"
    $message = "Componente habilitado y compatible."
    $severity = "ok"
    $blocking = $false
    if ($null -eq $Target) {
        $status = "missing"
        $message = "El plugin requerido falta en el destino."
        $blocking = $Used -or [string]$Source.source -eq "additional"
        $severity = if ($blocking) { "critical" } else { "warning" }
        if ($Component -eq 'mod_chat' -and $Used -and
                [string]$Source.source -in @('standard', 'core')) {
            $status = 'removed_core_component'
            $message = 'mod_chat era core en el origen; Moodle destino no lo incluye. La actividad no tiene migración/preservación verificada.'
            $blocking = $true
            $severity = 'critical'
        }
    } else {
        $sourceVersion = [int64]$Source.version_disk
        $disk = [int64]$Target.version_disk
        $installed = [int64]$Target.version_db
        $disabled = $Target.enabled -eq $false -or
            ($null -ne $Module -and [int]$Module.visible -ne 1)
        if ($installed -lt 1) {
            $status = "missing_installed"
            $message = "El código todavía no está instalado en Moodle."
            $blocking = $true
        } elseif ($disabled) {
            $status = "installed_disabled"
            $message = "Plugin instalado, pero deshabilitado."
            $blocking = $Used
            $severity = if ($Used) { "critical" } else { "warning" }
        } elseif ($disk -lt $installed -or
                [string]$Target.installation_status -match
                    'incompatible|downgrade|missing|requires') {
            $status = "incompatible"
            $message = "Código y base instalada incompatibles."
            $blocking = $true
        } elseif ($sourceVersion -gt $disk) {
            $status = "older_version"
            $message = "La versión destino es anterior a la del origen."
            $blocking = $true
        }
        if ([string]$Source.source -eq "additional" -and -not $AllowUnpinned -and
                ($null -eq $Pin -or
                 [int64]$Pin.version -ne $disk -or
                 (($null -eq $Pin.release) -ne ($null -eq $Target.release)) -or
                 [string]$Pin.release -cne [string]$Target.release -or
                 [string]$Pin.tree_sha256 -cne [string]$Target.tree_sha256 -or
                 [string]$Pin.commit -cne [string]$Target.approved_commit)) {
            $status = "wrong_or_unpinned_version"
            $message = "Commit, árbol, versión o release difiere del pin aprobado."
            $blocking = $true
        }
        if ($blocking) { $severity = "critical" }
    }
    return [pscustomobject]@{
        status = $status
        message = $message
        severity = $severity
        blocking = $blocking
    }
}
