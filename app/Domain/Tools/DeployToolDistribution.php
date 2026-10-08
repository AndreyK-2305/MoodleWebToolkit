<?php

namespace App\Domain\Tools;

use App\Domain\Tools\DTOs\VerifiedDistribution;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use App\Models\ToolDistribution;
use Illuminate\Support\Str;
use RuntimeException;

class DeployToolDistribution
{
    private readonly ToolDistributionVerifier $verifier;

    private readonly ExecutionWorkspaceManager $workspaces;

    public function __construct(
        ToolDistributionVerifier $verifier,
        ExecutionWorkspaceManager $workspaces,
    ) {
        $this->verifier = $verifier;
        $this->workspaces = $workspaces;
    }

    /** @return array{path: string, evidence: array<string, mixed>} */
    public function deploy(Execution $execution, ToolDistribution $distribution): array
    {
        $verified = $this->verifier->verify($distribution);
        $this->workspaces->prepare($execution);
        $slug = Str::slug($distribution->key);
        $excluded = $this->deploymentExclusions($distribution, $verified);
        $runtimeConfig = $this->workspaces->resolve($execution, 'state', 'runtime-config/'.$slug);
        if (is_dir($runtimeConfig) === false && mkdir($runtimeConfig, 0700, true) === false && is_dir($runtimeConfig) === false) {
            throw new RuntimeException('No se pudo crear el área separada de configuración activa.');
        }

        if ($slug === '' || strlen($slug) > 160) {
            throw new RuntimeException('La clave de distribución no puede convertirse en una ruta segura.');
        }

        $toolsRoot = $this->workspaces->resolve($execution, 'tools');
        $target = $this->workspaces->resolve($execution, 'tools', $slug);
        $staging = $toolsRoot.DIRECTORY_SEPARATOR.'.staging-'.$slug.'-'.Str::uuid();

        if (file_exists($target) || is_link($target)) {
            $evidence = $this->verifyDeployedTree($target, $verified, (string) $distribution->manifest_name, $excluded);
            $overlayEvidence = $this->deployMutableOverlays($execution, $distribution, $verified, $slug, $excluded);
            $fullEvidence = $this->deploymentEvidence($distribution, $verified, $target, [...$evidence, 'workspace_overlays' => $overlayEvidence]);
            $this->workspaces->writeState($execution, 'distribution-'.$slug.'.json', $fullEvidence);

            return ['path' => $target, 'evidence' => $fullEvidence];
        }

        if (mkdir($staging, 0700) === false) {
            throw new RuntimeException('No se pudo crear un área de staging dentro del workspace.');
        }

        try {
            $files = array_diff_key($verified->manifestFiles, array_fill_keys($excluded, true));
            $files[$distribution->manifest_name] = $verified->manifestSha256;
            if (in_array($distribution->manifest_name, $excluded, true)) {
                unset($files[$distribution->manifest_name]);
            }
            ksort($files, SORT_STRING);

            foreach ($files as $relative => $expectedHash) {
                $source = $verified->sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $destination = $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->ensureParentDirectory($staging, dirname($destination));
                $this->copyVerifiedFile($source, $destination, $expectedHash);
            }

            $overlayEvidence = $this->deployMutableOverlays($execution, $distribution, $verified, $slug, $excluded);

            $deployedEvidence = $this->verifyDeployedTree($staging, $verified, (string) $distribution->manifest_name, $excluded);
            $this->sealTree($staging);

            if (rename($staging, $target) === false) {
                throw new RuntimeException('No se pudo desplegar atómicamente la distribución verificada.');
            }

            $this->workspaces->measure($execution);
            $evidence = $this->deploymentEvidence($distribution, $verified, $target, [...$deployedEvidence, 'workspace_overlays' => $overlayEvidence]);
            $this->workspaces->writeState($execution, 'distribution-'.$slug.'.json', $evidence);

            return ['path' => $target, 'evidence' => $evidence];
        } catch (\Throwable $exception) {
            $this->removeStaging($staging);
            throw $exception;
        }
    }

    /**
     * @param  list<string>  $excluded
     * @return array<string, mixed>
     */
    private function verifyDeployedTree(string $root, VerifiedDistribution $verified, string $manifestName, array $excluded = []): array
    {
        if (is_link($root) || is_dir($root) === false) {
            throw new RuntimeException('El destino del despliegue no es un directorio normal.');
        }

        $expected = $verified->manifestFiles;
        $expected[$manifestName] = $verified->manifestSha256;
        foreach ($excluded as $path) {
            unset($expected[$path]);
        }
        ksort($expected, SORT_STRING);
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('El staging desplegado contiene un enlace simbólico.');
            }
            if ($item->isFile() === false) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $hash = hash_file('sha256', $item->getPathname());
            if (is_string($hash) === false) {
                throw new RuntimeException("No se pudo verificar el hash del archivo desplegado [{$relative}].");
            }
            $actual[$relative] = $hash;
        }
        ksort($actual, SORT_STRING);

        if (array_keys($actual) !== array_keys($expected)) {
            throw new RuntimeException('El árbol copiado tiene archivos diferentes al paquete inmutable declarado.');
        }
        foreach ($expected as $path => $hash) {
            if (hash_equals($hash, $actual[$path]) === false) {
                throw new RuntimeException("La copia desplegada falló su revalidación en [{$path}].");
            }
        }

        return [
            'deployed_tree_sha256' => $this->verifier->treeHash($actual),
            'deployed_file_count' => count($actual),
        ];
    }

    private function copyVerifiedFile(string $source, string $destination, string $expectedHash): void
    {
        if (is_link($source) || is_file($source) === false) {
            throw new RuntimeException('El origen de una copia del paquete no es un archivo regular.');
        }

        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('No se pudo copiar un archivo al staging privado.');
        }

        try {
            $hash = hash_init('sha256');
            while (feof($input) === false) {
                $chunk = fread($input, 65_536);
                if ($chunk === false) {
                    throw new RuntimeException('No se pudo leer una parte de la distribución.');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('No se pudo escribir una parte del staging.');
                    }
                }
            }
            if (fflush($output) === false || hash_equals($expectedHash, hash_final($hash)) === false) {
                throw new RuntimeException('La copia del archivo no pasó la comprobación SHA-256.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        @chmod($destination, str_ends_with(strtolower($destination), '.sh') ? 0500 : 0400);
    }

    /**
     * @param  list<string>  $excluded
     * @return array<string, array{path: string, source_sha256: string, workspace_sha256: string}>
     */
    private function deployMutableOverlays(Execution $execution, ToolDistribution $distribution, VerifiedDistribution $verified, string $slug, array $excluded = []): array
    {
        $evidence = [];
        foreach ($verified->mutableFiles as $relative => $hash) {
            if (in_array($relative, $excluded, true)) {
                continue;
            }
            $source = $verified->sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $workspaceRelative = 'overlays/'.$slug.'/'.$relative;
            $target = $this->workspaces->resolve($execution, 'state', $workspaceRelative);
            $this->ensureParentDirectory($this->workspaces->resolve($execution, 'state'), dirname($target));
            if (is_link($target)) {
                throw new RuntimeException('El overlay de workspace no puede ser un enlace simbólico.');
            }
            if (file_exists($target) === false) {
                $this->copyVerifiedFile($source, $target, $hash);
                @chmod($target, 0600);
            }
            if (is_file($target) === false || is_string($workspaceHash = hash_file('sha256', $target)) === false) {
                throw new RuntimeException('No se pudo verificar un overlay operativo del workspace.');
            }
            $evidence[$relative] = ['path' => $target, 'source_sha256' => $hash, 'workspace_sha256' => $workspaceHash];
        }

        return $evidence;
    }

    private function sealTree(string $root): void
    {
        $directories = [$root];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('No se sella una distribución que contenga enlaces simbólicos.');
            }
            if ($entry->isDir()) {
                $directories[] = $entry->getPathname();
            } elseif ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.sh') === false) {
                @chmod($entry->getPathname(), 0400);
            }
        }

        usort($directories, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($directories as $directory) {
            @chmod($directory, 0500);
        }
    }

    private function ensureParentDirectory(string $root, string $directory): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedDirectory = str_replace('\\', '/', $directory);
        if (str_starts_with($normalizedDirectory.'/', $normalizedRoot.'/') === false) {
            throw new RuntimeException('El destino de copia intentó escapar del staging.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Se rechazó un enlace simbólico en staging.');
        }
        if (is_dir($directory) === false && mkdir($directory, 0700, true) === false && is_dir($directory) === false) {
            throw new RuntimeException('No se pudo crear un directorio del staging.');
        }
    }

    /**
     * @param  array<string, mixed>  $deployed
     * @return array<string, mixed>
     */
    private function deploymentEvidence(ToolDistribution $distribution, VerifiedDistribution $verified, string $target, array $deployed): array
    {
        return [
            'distribution_key' => $distribution->key,
            'tool_version_id' => $distribution->tool_version_id,
            'source_tree_sha256' => $verified->treeSha256,
            'manifest_sha256' => $verified->manifestSha256,
            'source_file_count' => $verified->fileCount,
            'deployed_path' => $target,
            ...$deployed,
            'excluded_mutable_overlays' => $verified->mutableFiles,
            'excluded_from_runtime' => $distribution->deployment_exclusions ?? [],
            'runtime_configuration_required' => str_starts_with((string) $distribution->key, 'moodle-consolidador-'),
            'runtime_configuration_approved' => false,
            'verified_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function deploymentExclusions(ToolDistribution $distribution, VerifiedDistribution $verified): array
    {
        $exclusions = array_values(array_unique(array_map('strval', $distribution->deployment_exclusions ?? [])));
        foreach ($exclusions as $path) {
            if (array_key_exists($path, $verified->manifestFiles) === false && array_key_exists($path, $verified->mutableFiles) === false
                && $path !== $distribution->manifest_name
            ) {
                throw new RuntimeException("La exclusión de despliegue [{$path}] no pertenece a la distribución verificada.");
            }
        }
        sort($exclusions, SORT_STRING);

        return $exclusions;
    }

    private function removeStaging(string $path): void
    {
        if (is_dir($path) === false || is_link($path)) {
            return;
        }
        @chmod($path, 0700);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('No se limpia staging que contenga enlaces simbólicos.');
            }
            if ($entry->isDir()) {
                @chmod($entry->getPathname(), 0700);
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
