$ErrorActionPreference = 'Stop'
$taskCheckout = (Resolve-Path -LiteralPath '.').Path
$taskTracked = @(git -c "safe.directory=$taskCheckout" ls-files -- BaseLine)
if ($LASTEXITCODE -ne 0 -or $taskTracked.Count -ne 423) {
    throw "El checkout publicado debe conservar los 423 archivos de BaseLine; Git registra $($taskTracked.Count)."
}
Write-Host 'BaseLine: los 423 archivos están registrados para el checkout limpio de CI.'
