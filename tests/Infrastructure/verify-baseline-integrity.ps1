$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$expectedFileCount = 131
$expectedFingerprint = '5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3'
$baselineRoot = (Resolve-Path -LiteralPath 'BaseLine').Path

$paths = New-Object 'System.Collections.Generic.List[string]'
$rowsByPath = @{}

Get-ChildItem -LiteralPath $baselineRoot -Recurse -File | ForEach-Object {
    $relativePath = $_.FullName.Substring($baselineRoot.Length + 1).Replace('\', '/')
    $contentHash = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()

    $paths.Add($relativePath)
    $rowsByPath[$relativePath] = $relativePath + [char]0 + $contentHash
}

$paths.Sort([StringComparer]::Ordinal)

if ($paths.Count -ne $expectedFileCount) {
    throw "BaseLine contiene $($paths.Count) archivos; se esperaban $expectedFileCount."
}

$rows = $paths | ForEach-Object { $rowsByPath[$_] }
$manifest = [string]::Join("`n", $rows)
$algorithm = [Security.Cryptography.SHA256]::Create()

try {
    $bytes = [Text.Encoding]::UTF8.GetBytes($manifest)
    $fingerprint = ([BitConverter]::ToString($algorithm.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
}
finally {
    $algorithm.Dispose()
}

if ($fingerprint -ne $expectedFingerprint) {
    throw "La huella de BaseLine no coincide. Actual: $fingerprint."
}

Write-Host "BaseLine intacta: $expectedFileCount archivos; SHA-256 canónico $fingerprint."
