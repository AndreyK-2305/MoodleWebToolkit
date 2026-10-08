<?php

namespace App\Domain\Processes;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Enums\ArtifactCategory;
use InvalidArgumentException;

/**
 * @phpstan-type RegisteredCommandDefinition array{
 *     argv: list<string>,
 *     environment: array<string, string>,
 *     timeout: ?int,
 *     startup_timeout_seconds: int,
 *     heartbeat_interval_seconds: int,
 *     stall_timeout_seconds: ?int,
 *     wall_timeout_seconds: ?int,
 *     cancellation_grace_seconds: int,
 *     resource_limits: array{cpu_seconds: ?int, memory_bytes: int, processes: int, file_bytes: int},
 *     enforce_os_limits: bool,
 *     allow_force_kill: bool,
 *     max_output_bytes: int,
 *     durable_log_max_bytes: int,
 *     platform_durable_log_max_bytes: int,
 *     stream_pending_max_bytes: int,
 *     artifact_descriptors: list<array<string, mixed>>,
 *     cancellable: bool,
 * }
 */
class RegisteredCommandRegistry
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return RegisteredCommandDefinition
     */
    public function resolve(string $key, array $parameters = []): array
    {
        if (preg_match('/^[a-z][a-z0-9._-]{2,119}$/D', $key) !== 1) {
            throw new InvalidArgumentException('La clave del comando registrado no es válida.');
        }

        $definitions = config('toolkit.runner.commands', []);
        $definition = is_array($definitions) ? ($definitions[$key] ?? null) : null;

        if (is_array($definition) === false) {
            throw new InvalidArgumentException('El comando solicitado no está registrado.');
        }

        $executable = $definition['executable'] ?? null;
        $fixed = $definition['fixed_arguments'] ?? [];
        $schema = $definition['parameters'] ?? [];
        if (($definition['requires_secrets'] ?? false) === true) {
            throw new InvalidArgumentException('El comando requiere secretos, pero no hay un almacén de secretos aprobado; su ejecución está bloqueada.');
        }

        if (is_string($executable) === false || $executable === '' || $this->isAbsolute($executable) === false || is_file($executable) === false || is_executable($executable) === false
            || is_array($fixed) === false || is_array($schema) === false
        ) {
            throw new InvalidArgumentException('La definición del comando registrado es inválida.');
        }

        $unexpected = array_diff(array_keys($parameters), array_keys($schema));
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Se enviaron parámetros no registrados para el comando.');
        }

        $argv = [$executable];
        foreach ($fixed as $argument) {
            if (is_string($argument) === false || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('La lista fija de argumentos no es válida.');
            }
            if (app(SensitiveValueRedactor::class)->redactString($argument) !== $argument) {
                throw new InvalidArgumentException('La lista fija de argumentos contiene material sensible sin almacén aprobado.');
            }
            $argv[] = $argument;
        }

        foreach ($schema as $name => $parameterDefinition) {
            if (is_array($parameterDefinition) === false || isset($parameterDefinition['pattern']) === false) {
                throw new InvalidArgumentException('El esquema de parámetros del comando está incompleto.');
            }
            if (app(SensitiveValueRedactor::class)->isSensitiveKeyName((string) $name) || ($parameterDefinition['secret'] ?? false) === true) {
                throw new InvalidArgumentException('El comando declara un parámetro sensible sin un almacén de secretos aprobado.');
            }
            if (array_key_exists($name, $parameters) === false) {
                if (($parameterDefinition['optional'] ?? false) === true) {
                    continue;
                }
                throw new InvalidArgumentException("Falta el parámetro obligatorio [{$name}].");
            }

            $value = $parameters[$name];
            $pattern = $parameterDefinition['pattern'];
            if (is_string($value) === false || strlen($value) > 4096 || str_contains($value, "\0")
                || app(SensitiveValueRedactor::class)->redactString($value) !== $value
                || is_string($pattern) === false || @preg_match($pattern, $value) !== 1
            ) {
                throw new InvalidArgumentException("El parámetro [{$name}] no cumple el formato registrado.");
            }
            $argv[] = $value;
        }

        $environment = ['LANG' => 'C.UTF-8'];
        foreach (($definition['environment'] ?? []) as $name => $value) {
            if (is_string($name) === false || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) !== 1 || is_string($value) === false || str_contains($value, "\0")) {
                throw new InvalidArgumentException('El entorno fijo del comando contiene una entrada inválida.');
            }
            if (app(SensitiveValueRedactor::class)->isSensitiveKeyName($name)) {
                throw new InvalidArgumentException('El entorno del comando contiene un secreto sin almacén aprobado.');
            }
            if (app(SensitiveValueRedactor::class)->redactString($value) !== $value) {
                throw new InvalidArgumentException('El entorno del comando contiene un valor secreto sin almacén aprobado.');
            }
            $environment[$name] = $value;
        }
        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            $environment['PATH'] = $path;
        }

        $descriptors = $this->validateArtifactDescriptors($definition['artifact_descriptors'] ?? []);
        $platformLimit = $this->positiveLimit(config('toolkit.runner.durable_log_max_bytes', 1_048_576), 8_388_608);
        $commandLimit = $this->positiveLimit($definition['durable_log_max_bytes'] ?? $platformLimit, 8_388_608);
        $pendingLimit = $this->positiveLimit(config('toolkit.runner.stream_pending_max_bytes', 65_536), 65_536);
        if ($pendingLimit < 64) {
            throw new InvalidArgumentException('El buffer pendiente debe tener al menos 64 bytes.');
        }

        $policy = app(RegisteredExecutionPolicy::class)->resolve($definition);

        return [
            ...$policy,
            'enforce_os_limits' => (bool) config('toolkit.runner.enforce_os_limits', true),
            'allow_force_kill' => (bool) config('toolkit.runner.allow_force_kill', false),
            'argv' => $argv,
            'environment' => $environment,
            'timeout' => $policy['wall_timeout_seconds'],
            'max_output_bytes' => min(8_388_608, max(1024, (int) config('toolkit.runner.max_output_bytes', 1_048_576))),
            'durable_log_max_bytes' => $commandLimit,
            'platform_durable_log_max_bytes' => $platformLimit,
            'stream_pending_max_bytes' => $pendingLimit,
            'artifact_descriptors' => $descriptors,
            'cancellable' => (bool) ($definition['cancellable'] ?? false),
        ];
    }

    private function positiveLimit(mixed $value, int $maximum): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException('El límite de salida debe ser un entero positivo dentro del máximo permitido.');
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function validateArtifactDescriptors(mixed $descriptors): array
    {
        if (is_array($descriptors) === false || array_is_list($descriptors) === false) {
            throw new InvalidArgumentException('Los descriptores de artefactos del comando deben ser una lista explícita.');
        }
        $paths = [];
        foreach ($descriptors as $descriptor) {
            if (is_array($descriptor) === false) {
                throw new InvalidArgumentException('Un descriptor de artefacto no es válido.');
            }
            $path = $descriptor['relative_path'] ?? null;
            $name = $descriptor['name'] ?? null;
            $category = $descriptor['category'] ?? null;
            $mimeTypes = $descriptor['mime_types'] ?? null;
            $maximum = $descriptor['max_size_bytes'] ?? null;
            $expectedSha256 = $descriptor['expected_sha256'] ?? null;
            $sensitivity = $descriptor['sensitivity'] ?? 'INTERNAL';
            $allowedKeys = ['relative_path', 'category', 'name', 'mime_types', 'max_size_bytes', 'expected_sha256', 'required', 'sensitivity'];
            $descriptorMimeStrings = is_array($mimeTypes) ? array_filter($mimeTypes, 'is_string') : [];
            $descriptorStrings = array_merge(is_string($path) ? [$path] : [], is_string($name) ? [$name] : [], $descriptorMimeStrings);
            if (array_diff(array_keys($descriptor), $allowedKeys) !== []
                || array_filter($descriptorStrings, fn (string $value): bool => app(SensitiveValueRedactor::class)->redactString($value) !== $value) !== []
                || is_string($path) === false || $path === '' || str_contains($path, "\0")
                || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
                || preg_match('#(^|[\\/])\.\.?([\\/]|$)#', $path) === 1
                || is_string($name) === false || basename(str_replace('\\', '/', $name)) !== $name
                || is_string($category) === false || ArtifactCategory::tryFrom($category) === null
                || is_array($mimeTypes) === false || $mimeTypes === []
                || array_filter($mimeTypes, fn (mixed $mime): bool => is_string($mime) === false || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD', $mime) !== 1) !== []
                || is_int($maximum) === false || $maximum < 1
                || ($expectedSha256 !== null && (is_string($expectedSha256) === false || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1))
                || in_array($sensitivity, ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'], true) === false
                || (isset($descriptor['required']) && is_bool($descriptor['required']) === false)
                || isset($paths[$path])
            ) {
                throw new InvalidArgumentException('Un descriptor de artefacto no cumple el contrato declarativo.');
            }
            $paths[$path] = true;
        }

        return $descriptors;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
