$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
function Assert-RC4([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "RC4_IMPORT_FAILED $Message" }
}
function Write-RC4Json([string]$Path, $Value) {
    [IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json -Depth 40))
}
function New-RC4Fixture([string]$Root, [bool]$Strict,
        [string]$Mutation) {
    New-Item -ItemType Directory -Force -Path "$Root/scripts", "$Root/config", `
        "$Root/copias", "$Root/exports", "$Root/reports", "$Root/data/cursos", `
        "$Root/data/inventarios", "$Root/data/checkpoints" | Out-Null
    Copy-Item "$PSScriptRoot/../scripts/import-source-packages.ps1" "$Root/scripts/"
    Copy-Item "$PSScriptRoot/../scripts/PackageIntegrity.ps1" "$Root/scripts/"
    $policy = [ordered]@{
        minimum_sources = 1; maximum_sources = 1; maximum_entries_per_package = 100
        required_source_ids = @('origen'); require_identity_scope_all_for_production = $true
        strict_integrity = $Strict
    }
    Write-RC4Json "$Root/config/assistant.json" ([ordered]@{
        schema_version = '1.0'; target = @{
            id = 'target'; name = 'Destino'; service = 'moodle-target'
            url_from_environment = 'MOODLE_PUBLIC_URL'; parent_category_id = 1
        }
        package_policy = $policy; pilot = @{
            source_id = 'auto'; course_key = 'auto'; target_category_id = 1
        }; selection = @{ include_hidden = $true }
    })
    $key = 'COURSE-ORIGEN-112233AABBCC'
    $entries = [ordered]@{}
    Write-RC4Json "$Root/data/identidades.json" @{
        metadata = @{ source = 'origen'; schema_version = '1.2'; scope = 'all' }
    }
    Write-RC4Json "$Root/data/inventario-origen.json" @{
        source_id = $(if ($Mutation -eq 'wrong_source') { 'otro' } else { 'origen' })
        source_name = 'Origen'; source_wwwroot = 'https://origen.test'
        source_moodle_version = '2025041400'; source_moodle_release = '4.5.2'
        write_performed = $false; config_sha256 = ''; counts = @{ courses = 1 }
    }
    Write-RC4Json "$Root/data/plugins.json" @{
        source_id = 'origen'; write_performed = $false; plugins = @()
    }
    Write-RC4Json "$Root/data/inventarios/curso.json" @{
        inventory = @{ counts = @{
            activities = 1; enrolments = 2; assignment_submissions = 0
            forum_posts = 0; quiz_attempts = 0; module_files = 0
        } }
    }
    Write-RC4Json "$Root/data/checkpoints/curso.json" @{
        course_key = $key; source_id = 'origen'
    }
    [IO.File]::WriteAllText("$Root/data/cursos/curso.mbz", 'MOODLE_BACKUP_FIXTURE')
    foreach ($relative in @('identidades.json', 'inventario-origen.json',
            'plugins.json', 'cursos/curso.mbz', 'inventarios/curso.json',
            'checkpoints/curso.json')) {
        $entries[$relative] = (Microsoft.PowerShell.Utility\Get-FileHash `
            -LiteralPath "$Root/data/$relative" -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $manifest = [ordered]@{
        schema_version = '1.0'; package_type = 'moodle-consolidation-source'
        collector_version = '7.4.1-linux'; hash_strategy = 'single-pass-v1'
        package_status = 'sealed'; source_id = 'origen'; source_name = 'Origen'
        source_wwwroot = 'https://origen.test'
        source_moodle_version = '2025041400'; source_moodle_release = '4.5.2'
        identity_scope = 'all'; identity_schema_version = '1.2'
        identity_file = 'identidades.json'; identity_sha256 = $entries['identidades.json']
        source_inventory_file = 'inventario-origen.json'
        source_inventory_sha256 = $entries['inventario-origen.json']
        plugins_file = 'plugins.json'; plugins_sha256 = $entries['plugins.json']
        courses_expected = 1; source_write_performed = $false
        destination_write_performed = $false
        entries = @([ordered]@{
            course_key = $key; source_course_id = 8
            source_course_idnumber = 'CURSO8'; source_shortname = 'Curso 8'
            backup_file = 'cursos/curso.mbz'; backup_sha256 = $entries['cursos/curso.mbz']
            inventory_file = 'inventarios/curso.json'
            inventory_sha256 = $entries['inventarios/curso.json']
            checkpoint_file = 'checkpoints/curso.json'
            checkpoint_sha256 = $entries['checkpoints/curso.json']
        })
    }
    Write-RC4Json "$Root/data/manifest.json" $manifest
    $entries['manifest.json'] = (Microsoft.PowerShell.Utility\Get-FileHash `
        "$Root/data/manifest.json" -Algorithm SHA256).Hash.ToLowerInvariant()
    $checksums = @($entries.Keys | Sort-Object | ForEach-Object {
        "$($entries[$_])  $_"
    }) -join "`n"
    [IO.File]::WriteAllText("$Root/data/checksums.sha256", $checksums + "`n")
    $zip = "$Root/copias/origen.zip"
    [IO.Compression.ZipFile]::CreateFromDirectory("$Root/data", $zip)
    $sha = (Microsoft.PowerShell.Utility\Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText("$zip.sha256", "$sha  origen.zip`n")
    return $zip
}
$fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) `
    ('rc4-import-' + [guid]::NewGuid().ToString('N'))
$oldUrl = $env:MOODLE_PUBLIC_URL
$env:MOODLE_PUBLIC_URL = 'https://moodle.example.edu'
$global:rc4ZipFullHashCalls = 0
$global:rc4Mismatch = $false
function Get-FileHash {
    param([string]$LiteralPath, [string]$Algorithm = 'SHA256')
    if ($LiteralPath -match 'origen\.zip$') {
        $global:rc4ZipFullHashCalls++
        if ($global:rc4Mismatch) { return [pscustomobject]@{ Hash = 'f' * 64 } }
    }
    return Microsoft.PowerShell.Utility\Get-FileHash `
        -LiteralPath $LiteralPath -Algorithm $Algorithm
}
function Invoke-RC4Fixture([string]$Case, [bool]$Strict, [string]$Mutation, [bool]$Success) {
    $root = Join-Path $fixtureRoot $Case
    $zip = New-RC4Fixture $root $Strict $Mutation
    $seal = "$zip.sha256"
    switch ($Mutation) {
        'missing' { Remove-Item $seal }
        'invalid' { [IO.File]::WriteAllText($seal, 'incorrecto') }
        'wrong_name' {
            $sha = (Microsoft.PowerShell.Utility\Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant()
            [IO.File]::WriteAllText($seal, "$sha  otro.zip`n")
        }
        'modified_zip' { [IO.File]::AppendAllText($zip, 'RC4_TAMPER') }
        { $_ -in @('zip_hardlink_trusted', 'zip_hardlink_strict',
                'zip_symlink') } {
            $storage = "$root/origen-storage.zip"
            Move-Item -LiteralPath $zip -Destination $storage
            $kind = if ($Mutation -eq 'zip_symlink') { 'SymbolicLink' } else {
                'HardLink'
            }
            New-Item -ItemType $kind -Path $zip -Target $storage | Out-Null
        }
        { $_ -in @('sha_hardlink_trusted', 'sha_hardlink_strict',
                'sha_symlink') } {
            $storage = "$root/sidecar-storage.sha256"
            Move-Item -LiteralPath $seal -Destination $storage
            $kind = if ($Mutation -eq 'sha_symlink') { 'SymbolicLink' } else {
                'HardLink'
            }
            New-Item -ItemType $kind -Path $seal -Target $storage | Out-Null
        }
    }
    $global:rc4ZipFullHashCalls = 0
    $global:rc4Mismatch = ($Mutation -eq 'mismatch')
    $captured = ''
    $failed = $false
    try {
        $captured = (& "$root/scripts/import-source-packages.ps1" 6>&1 | Out-String)
    } catch {
        $failed = $true
        $captured += $_.Exception.Message
    }
    Set-Location $PSScriptRoot
    Assert-RC4 ($failed -ne $Success) "resultado $Case $captured"
    if ($Success) {
        Assert-RC4 ((Test-Path "$root/exports/packages/origen/manifest.json") -and
            (Test-Path "$root/exports/phase1/package_index.json") -and
            (Test-Path "$root/exports/packages/origen/cursos/curso.mbz")) `
            "importación limpia $Case"
        $summary = Get-Content -Raw "$root/exports/phase1/package_index.json" |
            ConvertFrom-Json
        Assert-RC4 ($summary.zip_hashed_again -eq $Strict -and
            $summary.package_index[0].zip_sha256 -eq
                ((Get-Content $seal).Split(' ')[0])) "índice $Case"
        $sealFeedback = if ($Strict) { 'Calculando SHA-256 completo' } else {
            'Sello SHA-256'
        }
        foreach ($stage in @($sealFeedback, 'Validando contrato',
            'Preparando extracción', 'Extrayendo paquete',
            'Validando estructura extraída', 'Registrando paquete',
            'Importación completada')) {
            Assert-RC4 ($captured -match [regex]::Escape($stage)) `
                "feedback $stage $Case"
        }
    } else {
        Assert-RC4 (-not (Test-Path "$root/exports/phase1/package_index.json")) `
            "no debe aprobar el paquete $Case"
    }
    $expectedZipReads = if ($Strict -and $Mutation -notin @('missing', 'invalid',
            'wrong_name')) { 1 } else { 0 }
    Assert-RC4 ($global:rc4ZipFullHashCalls -eq $expectedZipReads) `
        "Get-FileHash ZIP $Case calls=$global:rc4ZipFullHashCalls"
    if ($Case -eq 'trusted') {
        Assert-RC4 ($captured -notmatch 'Calculando SHA-256 completo') `
            'el modo normal anunció un rehash'
    }
    if ($Mutation -in @('mismatch', 'modified_zip')) {
        Assert-RC4 ($captured -match 'no coincide con el sello') `
            'strict mismatch sin causa descriptiva'
    }
    if ($Mutation -eq 'zip_symlink') {
        Assert-RC4 ($captured -match 'no es un archivo .zip regular') `
            'el symlink ZIP no fue rechazado explícitamente'
    }
    if ($Mutation -eq 'sha_symlink') {
        Assert-RC4 ($captured -match 'Falta el sello externo') `
            'el symlink sidecar no fue rechazado explícitamente'
    }
    $global:rc4Mismatch = $false
}
try {
    Invoke-RC4Fixture 'trusted' $false '' $true
    Invoke-RC4Fixture 'strict' $true '' $true
    Invoke-RC4Fixture 'mismatch' $true 'mismatch' $false
    Invoke-RC4Fixture 'modified_zip' $true 'modified_zip' $false
    Invoke-RC4Fixture 'wrong_source' $false 'wrong_source' $false
    Invoke-RC4Fixture 'missing' $false 'missing' $false
    Invoke-RC4Fixture 'invalid' $false 'invalid' $false
    Invoke-RC4Fixture 'wrong_name' $false 'wrong_name' $false
    Invoke-RC4Fixture 'zip_hardlink_trusted' $false 'zip_hardlink_trusted' $true
    Invoke-RC4Fixture 'zip_hardlink_strict' $true 'zip_hardlink_strict' $true
    Invoke-RC4Fixture 'sha_hardlink_trusted' $false 'sha_hardlink_trusted' $true
    Invoke-RC4Fixture 'sha_hardlink_strict' $true 'sha_hardlink_strict' $true
    Invoke-RC4Fixture 'zip_symlink' $false 'zip_symlink' $false
    Invoke-RC4Fixture 'sha_symlink' $false 'sha_symlink' $false
    Write-Output 'RC4_IMPORT_OK trusted=1 strict=1 mismatch=1 modified_zip=1 wrong_source=1 missing=1 invalid=1 wrong_name=1 feedback=7'
    Write-Output 'RC6_LINKS_OK zip_hardlink=2 sha_hardlink=2 symlink_rejected=2 trusted_zip_rehash=0 strict_zip_rehash=1'
} finally {
    Set-Location $PSScriptRoot
    $env:MOODLE_PUBLIC_URL = $oldUrl
    Remove-Item -LiteralPath $fixtureRoot -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item Function:Get-FileHash -ErrorAction SilentlyContinue
    Remove-Variable rc4ZipFullHashCalls, rc4Mismatch -Scope Global -ErrorAction SilentlyContinue
}
