# La versión de Moodle core admite revisión decimal (p. ej. 2024100702.01).
# Las versiones de plugins se validan por separado como Int64 estricto.
function ConvertTo-MoodleVersion($Value, [string]$Component,
        [System.Collections.Generic.List[object]]$Issues) {
    $culture = [System.Globalization.CultureInfo]::InvariantCulture
    $parsed = [decimal]0
    $text = if ($Value -is [string]) {
        $Value
    } elseif ($null -ne $Value -and $Value -is [ValueType] -and
            $Value -isnot [bool] -and $Value -is [System.IFormattable]) {
        $Value.ToString($null, $culture)
    } else { '' }
    if ($text -notmatch '^[0-9]+(?:\.[0-9]+)?$' -or
            -not [decimal]::TryParse($text,
                [System.Globalization.NumberStyles]::AllowDecimalPoint,
                $culture, [ref]$parsed)) {
        [void]$Issues.Add([ordered]@{
            component = $Component; field = 'moodle_version'; value = $Value
            message = "MOODLE_VERSION_INVALID: $Component.moodle_version debe ser numérica no negativa (con revisión decimal opcional)."
        })
        return $null
    }
    return $parsed
}
