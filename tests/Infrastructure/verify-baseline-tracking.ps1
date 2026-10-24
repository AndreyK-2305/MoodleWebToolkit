$ErrorActionPreference = 'Stop'
$taskCheckout = (Resolve-Path -LiteralPath '.').Path
$taskTracked = @(git -c "safe.directory=$taskCheckout" ls-files -- BaseLine)
if ($LASTEXITCODE -ne 0 -or $taskTracked.Count -ne 426) {
    throw "El checkout publicado debe conservar los 426 archivos de BaseLine; Git registra $($taskTracked.Count)."
}
Write-Host 'BaseLine: los 426 archivos están registrados para el checkout limpio de CI.'
