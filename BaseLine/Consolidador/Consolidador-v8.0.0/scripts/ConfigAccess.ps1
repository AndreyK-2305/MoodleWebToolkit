# Importar esta librería define funciones y no exige config.yaml.
. "$PSScriptRoot/ConfigParser.ps1"

function Get-DestinationWriteLockPath {
    $projectRoot = Split-Path -Parent $PSScriptRoot
    return Join-Path $projectRoot 'reports\destination-write.lock.json'
}

function Get-TargetSite {
    $configPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'config.yaml'
    $config = Import-MigrationConfig -Path $configPath
    return $config.Target
}
