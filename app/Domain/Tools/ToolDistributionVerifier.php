<?php

namespace App\Domain\Tools;

use App\Domain\Tools\DTOs\VerifiedDistribution;
use App\Models\ToolDistribution;
use RuntimeException;
use SplFileInfo;

class ToolDistributionVerifier
{
    public function verify(ToolDistribution $distribution): VerifiedDistribution
    {
        if ($distribution->kind !== 'TREE') {
            throw new RuntimeException('Esta versión del verificador solo acepta distribuciones de tipo TREE.');
        }

        $repositoryRoot = realpath(base_path());
        $baselinePath = base_path('BaseLine');
        $baselineRoot = realpath($baselinePath);
        $relativeSource = $this->safeRelativePath($distribution->source_path);

        if ($repositoryRoot === false || $baselineRoot === false || is_link($baselinePath)) {
            throw new RuntimeException('No se encontró la BaseLine de herramientas.');
        }

        $this->rejectSymlinkSegments($repositoryRoot, $relativeSource);
        $sourceRoot = realpath(base_path($relativeSource));

        if ($sourceRoot === false || ! $this->isWithin($sourceRoot, $baselineRoot) || ! is_dir($sourceRoot)) {
            throw new RuntimeException('La distribución debe estar dentro de BaseLine y apuntar a un directorio existente.');
        }

        $manifestName = $distribution->manifest_name;

        if (! is_string($manifestName) || $manifestName === '') {
            throw new RuntimeException('La distribución no tiene manifiesto de archivos.');
        }

        $manifestRelative = $this->safeRelativePath($manifestName);
        $this->rejectSymlinkSegments($sourceRoot, $manifestRelative);
        $manifestPath = $sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifestRelative);
        $manifestContent = @file_get_contents($manifestPath);

        if (! is_string($manifestContent)) {
            throw new RuntimeException('No se pudo leer el manifiesto de la distribución.');
        }

        $manifestSha = hash('sha256', $manifestContent);

        if (! hash_equals((string) $distribution->manifest_sha256, $manifestSha)) {
            throw new RuntimeException('El hash del manifiesto de la distribución cambió.');
        }

        $manifestFiles = $this->parseManifest($manifestContent);
        $mutablePaths = array_map(fn (string $path): string => $this->safeRelativePath($path), $distribution->mutable_paths ?? []);
        $presentFiles = $this->listFiles($sourceRoot);
        $allowedFiles = array_fill_keys([...array_keys($manifestFiles), $manifestRelative, ...$mutablePaths], true);

        if (count($presentFiles) !== (int) $distribution->file_count) {
            throw new RuntimeException('La cantidad de archivos de la distribución no coincide con el catálogo.');
        }

        $unexpected = array_values(array_diff(array_keys($presentFiles), array_keys($allowedFiles)));
        $missing = array_values(array_diff(array_keys($allowedFiles), array_keys($presentFiles)));

        if ($unexpected !== [] || $missing !== []) {
            throw new RuntimeException('La distribución contiene archivos extra o faltantes respecto del manifiesto y sus overlays declarados.');
        }

        foreach ($manifestFiles as $relative => $expectedHash) {
            if (! hash_equals($expectedHash, $presentFiles[$relative])) {
                throw new RuntimeException("El archivo [{$relative}] no coincide con FILES.sha256.");
            }
        }

        $mutableFiles = [];

        foreach ($mutablePaths as $relative) {
            $mutableFiles[$relative] = $presentFiles[$relative];
        }

        $treeSha = $this->treeHash($presentFiles);

        if (! hash_equals((string) $distribution->distribution_sha256, $treeSha)) {
            throw new RuntimeException('El hash canónico del árbol no coincide con la distribución registrada.');
        }

        return new VerifiedDistribution(
            $sourceRoot,
            $treeSha,
            $manifestSha,
            count($presentFiles),
            $manifestFiles,
            $mutableFiles,
            now()->utc()->toIso8601String(),
        );
    }

    /** @return array<string, string> */
    private function parseManifest(string $content): array
    {
        $files = [];

        foreach (preg_split('/\r?\n/', trim($content)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $matches) !== 1) {
                throw new RuntimeException('El formato de FILES.sha256 no es válido.');
            }

            $path = $this->safeRelativePath($matches[2]);

            if (isset($files[$path])) {
                throw new RuntimeException('FILES.sha256 contiene una ruta duplicada.');
            }

            $files[$path] = $matches[1];
        }

        if ($files === []) {
            throw new RuntimeException('FILES.sha256 está vacío.');
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, string> */
    private function listFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isLink()) {
                throw new RuntimeException('No se permiten enlaces simbólicos en una distribución de herramientas.');
            }

            if (! $item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $hash = hash_file('sha256', $item->getPathname());

            if (! is_string($hash)) {
                throw new RuntimeException("No se pudo calcular el hash de [{$relative}].");
            }

            $files[$relative] = $hash;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string, string> $files */
    public function treeHash(array $files): string
    {
        ksort($files, SORT_STRING);
        $rows = [];

        foreach ($files as $path => $hash) {
            $rows[] = $path."\0".$hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
        ) {
            throw new RuntimeException('La ruta relativa de la distribución no es segura.');
        }

        return $path;
    }

    private function rejectSymlinkSegments(string $root, string $relative): void
    {
        $cursor = $root;

        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new RuntimeException('La distribución contiene una ruta enlazada simbólicamente.');
            }
        }
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path.'/', $root.'/');
    }
}
