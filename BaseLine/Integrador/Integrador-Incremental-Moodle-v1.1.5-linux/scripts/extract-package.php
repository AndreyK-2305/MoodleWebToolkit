<?php
// Extrae una única vez un paquete ya validado por el verificador 7.4.1.

declare(strict_types=1);

function arg(string $name): string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach (array_slice($argv ?? [], 1) as $item) {
        if (str_starts_with((string)$item, $prefix)) {
            return substr((string)$item, strlen($prefix));
        }
    }
    throw new RuntimeException('Falta --' . $name . '.');
}

function safe_path(string $path): void {
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') ||
            preg_match('/[\x00-\x1F]/', $path) === 1) {
        throw new RuntimeException('Ruta insegura en el ZIP: ' . $path . '.');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' ||
                rtrim($segment, " .") !== $segment) {
            throw new RuntimeException('Segmento inseguro en ' . $path . '.');
        }
    }
}

function remove_tree(string $path): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    $zippath = realpath(arg('zip'));
    $validationpath = arg('validation');
    $destination = rtrim(arg('destination'), '/\\');
    if ($zippath === false || !is_readable($zippath) ||
            !is_readable($validationpath) || $destination === '' ||
            !str_starts_with($destination, '/exports/integrator/')) {
        throw new RuntimeException('Las rutas de extracción son inválidas.');
    }
    $validation = json_decode(
        (string)file_get_contents($validationpath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($validation['result'] ?? '') !== 'ok' ||
            !preg_match('/^[a-f0-9]{64}$/', (string)($validation['outer_zip']['sha256'] ?? '')) ||
            !hash_equals(
                (string)$validation['outer_zip']['sha256'],
                (string)hash_file('sha256', $zippath)
            )) {
        throw new RuntimeException('La validación no corresponde al ZIP actual.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zippath, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('ZipArchive no pudo abrir el paquete.');
    }
    $temporary = $destination . '.tmp.' . getmypid();
    remove_tree($temporary);
    if (!mkdir($temporary, 0770, true) && !is_dir($temporary)) {
        throw new RuntimeException('No se pudo crear el staging de extracción.');
    }
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || str_ends_with($name, '/')) {
                continue;
            }
            safe_path($name);
            $target = $temporary . '/' . $name;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new RuntimeException('No se pudo crear ' . $parent . '.');
            }
            $input = $zip->getStream($name);
            $output = fopen($target . '.tmp', 'xb');
            if ($input === false || $output === false) {
                throw new RuntimeException('No se pudo extraer ' . $name . '.');
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Falló la copia de ' . $name . '.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (!rename($target . '.tmp', $target) || !chmod($target, 0660)) {
                throw new RuntimeException('No se pudo publicar ' . $name . '.');
            }
        }
        if (is_dir($destination)) {
            $existing = $destination . '.previous.' . getmypid();
            if (!rename($destination, $existing)) {
                throw new RuntimeException('No se pudo reemplazar la extracción anterior.');
            }
            if (!rename($temporary, $destination)) {
                rename($existing, $destination);
                throw new RuntimeException('No se pudo publicar la extracción nueva.');
            }
            remove_tree($existing);
        } else if (!rename($temporary, $destination)) {
            throw new RuntimeException('No se pudo publicar la extracción.');
        }
    } catch (Throwable $error) {
        remove_tree($temporary);
        throw $error;
    } finally {
        $zip->close();
    }
    fwrite(STDOUT, 'INCREMENTAL_PACKAGE_EXTRACT_OK destination=' . $destination . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'INCREMENTAL_PACKAGE_EXTRACT_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
