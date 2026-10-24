$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$expectedFileCount = 426
$expectedFingerprint = '4613bd0a9a3338a0d6f3824955f9f30e712af3d1f6f15cbd43e2ae2b93c8cae6'
$baselineRoot = (Resolve-Path -LiteralPath 'BaseLine').Path

$paths = New-Object 'System.Collections.Generic.List[string]'
$rowsByPath = @{}

Get-ChildItem -LiteralPath $baselineRoot -Recurse -File -Force | ForEach-Object {
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

Write-Host "BaseLine verificada: $expectedFileCount archivos; SHA-256 canónico $fingerprint."
