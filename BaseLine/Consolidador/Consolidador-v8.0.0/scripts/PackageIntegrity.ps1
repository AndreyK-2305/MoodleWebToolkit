# El Recolector oficial publica el SHA exterior en nombre.zip.sha256.
# El sello es un dato de procedencia confiado por el operador, no una firma digital.
function Test-RegularCollectorFile {
    param([System.IO.FileInfo]$File)
    return ($null -ne $File -and $File.Exists -and
        -not ($File.Attributes -band [IO.FileAttributes]::ReparsePoint) -and
        [string]$File.LinkType -in @('', 'HardLink'))
}

function Get-CollectorPackageSeal {
    param([Parameter(Mandatory)][System.IO.FileInfo]$Zip)
    if (-not (Test-RegularCollectorFile -File $Zip) -or
            $Zip.Extension -cne '.zip') {
        throw "El ZIP original no es un archivo .zip regular: $($Zip.Name)."
    }
    $sidecarPath = $Zip.FullName + '.sha256'
    $sidecar = Get-Item -LiteralPath $sidecarPath -ErrorAction SilentlyContinue
    if ($sidecar -isnot [System.IO.FileInfo] -or
            -not (Test-RegularCollectorFile -File $sidecar)) {
        throw "Falta el sello externo del Recolector: $($Zip.Name).sha256."
    }
    if ([int64]$sidecar.Length -gt 512) {
        throw "El sello SHA-256 del Recolector es demasiado grande: $($sidecar.Name)."
    }
    $line = [System.IO.File]::ReadAllText($sidecar.FullName, [Text.Encoding]::UTF8)
    if ($line -cnotmatch '^([a-f0-9]{64})  ([^\r\n]+\.zip)\r?\n$') {
        throw "El sello SHA-256 del Recolector tiene formato inválido: $($sidecar.Name)."
    }
    if ($Matches[2] -cne $Zip.Name) {
        throw "El sello SHA-256 referencia otro ZIP: $($Matches[2]); esperado=$($Zip.Name)."
    }
    return [pscustomobject]@{
        Sha256 = [string]$Matches[1]
        SidecarPath = $sidecar.FullName
        ZipName = $Zip.Name
    }
}

function Resolve-SourcePackageIntegrity {
    param(
        [Parameter(Mandatory)][System.IO.FileInfo]$Zip,
        [Parameter(Mandatory)][bool]$StrictIntegrity
    )
    $seal = Get-CollectorPackageSeal -Zip $Zip
    if ($StrictIntegrity) {
        Write-Host "Calculando SHA-256 completo del ZIP $($Zip.Name)..." -ForegroundColor Cyan
        $actual = (Get-FileHash -LiteralPath $Zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -cne $seal.Sha256) {
            throw "El SHA-256 completo del ZIP no coincide con el sello del Recolector: $($Zip.Name)."
        }
        Write-Host 'SHA-256 completo del ZIP: coincide con el sello.' -ForegroundColor Green
    } else {
        Write-Host "Sello SHA-256 del Recolector: aceptado ($($Zip.Name))." -ForegroundColor Green
    }
    return [pscustomobject]@{
        Sha256 = $seal.Sha256
        SidecarPath = $seal.SidecarPath
        Mode = $(if ($StrictIntegrity) { 'strict_integrity' } else {
            'trusted_package_integrity'
        })
        ZipHashedAgain = $StrictIntegrity
    }
}
