$ErrorActionPreference = 'Stop'

function Assert-V800([bool]$Condition, [string]$Label) {
    if (-not $Condition) { throw "V800_ZERO_PLUGIN_REBUILD_FAILED: $Label" }
}

# Ejecuta las funciones reales del resolver con un docker simulado: la imagen se
# reconstruye cuando su manifiesto de pins difiere del archivo, aunque no se
# haya instalado ningún plugin (lock vacío).
$resolver = Join-Path $PSScriptRoot '../scripts/v8-plugin-resolver.ps1'
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $resolver, [ref]$tokens, [ref]$parseErrors
)
Assert-V800 ($parseErrors.Count -eq 0) 'sintaxis del resolver'
$names = @('File-Hash', 'Get-TargetPinHash', 'Sync-MoodleTarget')
$definitions = $ast.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
    $node.Name -in $names
}, $true)
Assert-V800 ($definitions.Count -eq $names.Count) 'funciones reales presentes'
foreach ($definition in $definitions) {
    Invoke-Expression $definition.Extent.Text
}

$scratch = Join-Path ([IO.Path]::GetTempPath()) ('v800-pins-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $scratch | Out-Null
$pins = Join-Path $scratch 'approved-plugins.json'
Set-Content -LiteralPath $pins -Value '{"schema_version":"1.0","plugins":[]}' -NoNewline -Encoding utf8
$script:ImageHash = ''
$script:Calls = [System.Collections.Generic.List[string]]::new()
function Wait-MoodleTarget { $script:Calls.Add('wait') }
function docker {
    $line = $args -join ' '
    $script:Calls.Add($line)
    $global:LASTEXITCODE = 0
    if ($line -like 'compose exec -T moodle-target sha256sum*') {
        if ($script:ImageHash) { return "$script:ImageHash  /opt/approved-plugins.json" }
        $global:LASTEXITCODE = 1
    }
}

try {
    # La imagen conserva un manifiesto anterior: se reconstruye sin plugins.
    $script:ImageHash = 'a' * 64
    Sync-MoodleTarget $false
    Assert-V800 ($script:Calls -contains 'compose build moodle-target') 'pins distintos reconstruyen'
    Assert-V800 ($script:Calls -contains 'compose up -d --force-recreate moodle-target') 'pins distintos recrean'
    Assert-V800 ($script:Calls -contains 'wait') 'pins distintos esperan salud'

    # La imagen ya tiene el mismo manifiesto: no se reconstruye.
    $script:Calls.Clear()
    $script:ImageHash = File-Hash $pins
    Sync-MoodleTarget $false
    Assert-V800 ($script:Calls -notcontains 'compose build moodle-target') 'pins iguales no reconstruyen'

    # Plugins instalados: se reconstruye siempre, como antes.
    $script:Calls.Clear()
    Sync-MoodleTarget $true
    Assert-V800 ($script:Calls -contains 'compose build moodle-target') 'plugins instalados reconstruyen'

    # Sin contenedor que responda: se reconstruye para no validar contra una imagen dudosa.
    $script:Calls.Clear()
    $script:ImageHash = ''
    Sync-MoodleTarget $false
    Assert-V800 ($script:Calls -contains 'compose build moodle-target') 'imagen ilegible reconstruye'
} finally {
    Remove-Item -LiteralPath $scratch -Recurse -Force
}

$text = Get-Content -LiteralPath $resolver -Raw
Assert-V800 ($text.Contains('Sync-MoodleTarget ([int]$installation.installed_count -gt 0)')) 'Prepare sincroniza la imagen'
Assert-V800 ($text.Contains("Sync-MoodleTarget `$false`n        Write-Output `$existing") -or
    $text.Contains("Sync-MoodleTarget `$false`r`n        Write-Output `$existing")) 'un lock reutilizado también sincroniza'

Write-Host 'V800_ZERO_PLUGIN_REBUILD_OK'
