function Resolve-WorkerCount {
    $requested = [string]$env:CONSOLIDATION_WORKERS
    if ([string]::IsNullOrWhiteSpace($requested)) {
        $requested = "auto"
    }
    $available = [Math]::Max(1, [Environment]::ProcessorCount)
    if ($requested -eq "auto") {
        $memory = [int64]0
        if (Test-Path -LiteralPath "/sys/fs/cgroup/memory.max") {
            $limit = (Get-Content "/sys/fs/cgroup/memory.max" -Raw).Trim()
            [void][int64]::TryParse($limit, [ref]$memory)
        }
        if ($memory -le 0 -and (Test-Path "/proc/meminfo")) {
            $total = Select-String -Path "/proc/meminfo" -Pattern '^MemTotal:\s+(\d+) kB'
            if ($total) { $memory = [int64]$total.Matches[0].Groups[1].Value * 1024 }
        }
        $byMemory = if ($memory -gt 0) {
            [Math]::Max(1, [int][Math]::Floor($memory / 2GB))
        } else { 1 }
        return [Math]::Min([Math]::Min($available, $byMemory), 4)
    }
    $explicit = 0
    if (-not [int]::TryParse($requested, [ref]$explicit) -or
            $explicit -lt 1 -or $explicit -gt 4) {
        throw "CONSOLIDATION_WORKERS debe ser auto o un entero entre 1 y 4."
    }
    return $explicit
}
