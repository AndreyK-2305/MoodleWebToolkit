<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ToolDistribution;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

final class CollectorPackageInspector
{
    public function __construct(
        private readonly CollectorPackageMetadata $metadata,
        private readonly DeployToolDistribution $distributions,
        private readonly ExecutionWorkspaceManager $workspaces,
        private readonly CollectorConfiguration $configurations,
        private readonly LabMoodleProfiles $profiles,
        private readonly SecretProvider $secrets,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(Execution $execution, ToolDistribution $validator, string $path, string $expectedHash, int $expectedBytes): array
    {
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1 || $expectedBytes < 1 || $expectedBytes > 21474836480
            || $stat['size'] !== $expectedBytes || ! hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
            throw new ToolOperationBlocked('El paquete no es un archivo regular íntegro y exclusivo.');
        }
        if ($validator->key !== 'moodle-recolector-7.4.2-linux-tree') {
            throw new ToolOperationBlocked('La auditoría requiere la distribución verificada del Recolector 7.4.2.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ToolOperationBlocked('El paquete fuente no es un ZIP íntegro.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
                throw new ToolOperationBlocked('El inventario ZIP excede los límites de auditoría del laboratorio.');
            }
            $names = [];
            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $entry = $zip->statIndex($index);
                if (! is_string($name) || ! is_array($entry) || $name === '' || str_starts_with($name, '/')
                    || str_contains($name, '\\') || str_contains($name, ':') || preg_match('#(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]#', $name) !== 0
                    || isset($names[strtolower($name)]) || $entry['encryption_method'] !== 0) {
                    throw new ToolOperationBlocked('El ZIP contiene rutas inseguras, duplicadas o cifradas.');
                }
                $names[strtolower($name)] = true;
                $system = $attributes = 0;
                if (! $zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    throw new ToolOperationBlocked('No se pudieron acreditar los atributos de una entrada ZIP.');
                }
                $kind = ($attributes >> 16) & 0170000;
                if (! in_array($kind, [0, 0100000, 0040000], true)) {
                    throw new ToolOperationBlocked('El ZIP contiene enlaces o entradas especiales.');
                }
                $expandedBytes += $entry['size'];
                if ($expandedBytes > 21474836480 || $entry['size'] > 21474836480) {
                    throw new ToolOperationBlocked('El ZIP excede la capacidad máxima de auditoría.');
                }
            }
            $manifestBytes = $zip->getFromName('manifest.json', 1048577);
            $manifest = is_string($manifestBytes) && strlen($manifestBytes) <= 1048576 ? json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR) : null;
            if (! is_array($manifest)) {
                throw new ToolOperationBlocked('El manifiesto falta o excede los límites de lectura.');
            }
            $metadata = $this->metadata->recognize($manifest);
            $configuration = $execution->project->configuration;
            if ($this->configurations->selected($configuration)) {
                $settings = $this->configurations->settings($configuration);
                $profile = $this->profiles->get($settings['profile_id']);
                if ($metadata['source_id'] !== $profile['source_id']) {
                    throw new ToolOperationBlocked('El paquete no corresponde al origen aprobado del proyecto.');
                }
                $this->secrets->consume($profile['credential_reference'], $profile['credential_version'], function (string $value) use ($zip): void {
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $name = $zip->getNameIndex($index);
                        if (! is_string($name) || str_ends_with($name, '/')) {
                            continue;
                        }
                        $stream = $zip->getStream($name);
                        if (! is_resource($stream)) {
                            throw new ToolOperationBlocked('No se pudo inspeccionar una entrada ZIP.');
                        }
                        try {
                            $tail = '';
                            while (! feof($stream)) {
                                $chunk = fread($stream, 65536);
                                if ($chunk === false || str_contains($tail.$chunk, $value)) {
                                    throw new ToolOperationBlocked('Se rechazó el paquete por contenido privado o ilegible.');
                                }
                                $tail = substr($tail.$chunk, -max(1, strlen($value) - 1));
                            }
                        } finally {
                            fclose($stream);
                        }
                    }
                });
            }
        } catch (Throwable $error) {
            throw new ToolOperationBlocked('La metadata o integridad del paquete no pasó validación.', previous: $error);
        } finally {
            $zip->close();
        }
        $deployment = $this->distributions->deploy($execution, $validator);
        $directory = $this->workspaces->resolve($execution, 'temporary', 'package-audit-'.Str::uuid());
        if (! mkdir($directory, 0700)) {
            throw new ToolOperationBlocked('No se pudo preparar una auditoría privada.');
        }
        $reportPath = $directory.'/report.json';
        $process = null;
        try {
            $sidecar = $directory.'/package.sha256';
            file_put_contents($sidecar, $expectedHash.'  '.basename($path)."\n");
            chmod($sidecar, 0600);
            $process = proc_open([(string) config('collector.php_binary'), '-c', (string) config('collector.php_ini'),
                $deployment['path'].'/scripts/validate-package.php', '--zip='.$path, '--sidecar='.$sidecar, '--report='.$reportPath],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes,
                $deployment['path'], ['LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => (string) config('collector.php_scan_dir'),
                    'PHPRC' => (string) config('collector.php_ini')], ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new ToolOperationBlocked('No se pudo iniciar el auditor verificado.');
            }
            $deadline = hrtime(true) + 60_000_000_000;
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process, SIGKILL);
                    throw new ToolOperationBlocked('La auditoría excedió su límite de verificación.');
                }
                usleep(100_000);
            } while (true);
            $closed = proc_close($process);
            $process = null;
            $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
            $reportStat = @lstat($reportPath);
            if ($exit !== 0 || $reportStat === false || is_link($reportPath) || $reportStat['size'] > 1048576
                || $reportStat['nlink'] !== 1 || ($reportStat['mode'] & 0170000) !== 0100000) {
                throw new ToolOperationBlocked('El auditor original rechazó el paquete.');
            }
            $report = json_decode((string) file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR);
            clearstatcache(true, $path);
            if (! is_array($report) || ($report['result'] ?? null) !== 'ok' || ($report['failures'] ?? null) !== []
                || ($report['outer_zip']['sha256'] ?? null) !== $expectedHash || ($report['outer_zip']['bytes'] ?? null) !== $expectedBytes
                || ! hash_equals($expectedHash, $this->currentHash($path)) || filesize($path) !== $expectedBytes) {
                throw new ToolOperationBlocked('La auditoría no acredita el contenido observado.');
            }

            return [...$metadata, 'validation_schema' => 'collector-web-audit.v1', 'result' => 'VALID',
                'execution_uuid' => $execution->uuid, 'project_uuid' => $execution->project->uuid,
                'package_sha256' => $expectedHash, 'package_bytes' => $expectedBytes, 'manifest_sha256' => hash('sha256', $manifestBytes),
                'validator_version' => '7.4.2-linux', 'validator_distribution_sha256' => $validator->distribution_sha256,
                'counts' => array_filter(is_array($report['counts'] ?? null) ? $report['counts'] : [], 'is_int'),
                'warnings_count' => count(is_array($report['warnings'] ?? null) ? $report['warnings'] : [])];
        } catch (Throwable $error) {
            throw new ToolOperationBlocked('No se pudo acreditar un paquete fuente válido.', previous: $error);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_link($file) || ! is_file($file) || ! unlink($file)) {
                    throw new ToolOperationBlocked('No se pudo retirar la auditoría privada; el workspace requiere reconciliación.');
                }
            }
            if (! rmdir($directory)) {
                throw new ToolOperationBlocked('No se pudo retirar el área privada de auditoría.');
            }
        }
    }

    /** @phpstan-impure */
    private function currentHash(string $path): string
    {
        return (string) hash_file('sha256', $path);
    }
}
