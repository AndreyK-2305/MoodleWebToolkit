<?php

namespace App\Domain\Processes;

use InvalidArgumentException;

class RegisteredCommandRegistry
{
    /** @return array{argv: list<string>, environment: array<string, string>, timeout: int, max_output_bytes: int} */
    public function resolve(string $key, array $parameters = []): array
    {
        if (preg_match('/^[a-z][a-z0-9._-]{2,119}$/D', $key) !== 1) {
            throw new InvalidArgumentException('La clave del comando registrado no es válida.');
        }

        $definitions = config('toolkit.runner.commands', []);
        $definition = is_array($definitions) ? ($definitions[$key] ?? null) : null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException('El comando solicitado no está registrado.');
        }

        $executable = $definition['executable'] ?? null;
        $fixed = $definition['fixed_arguments'] ?? [];
        $schema = $definition['parameters'] ?? [];

        if (! is_string($executable) || $executable === '' || ! $this->isAbsolute($executable) || ! is_file($executable) || ! is_executable($executable)
            || ! is_array($fixed) || ! is_array($schema)
        ) {
            throw new InvalidArgumentException('La definición del comando registrado es inválida.');
        }

        $unexpected = array_diff(array_keys($parameters), array_keys($schema));
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Se enviaron parámetros no registrados para el comando.');
        }

        $argv = [$executable];
        foreach ($fixed as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('La lista fija de argumentos no es válida.');
            }
            $argv[] = $argument;
        }

        foreach ($schema as $name => $parameterDefinition) {
            if (! is_array($parameterDefinition) || ! isset($parameterDefinition['pattern'])) {
                throw new InvalidArgumentException('El esquema de parámetros del comando está incompleto.');
            }
            if (! array_key_exists($name, $parameters)) {
                if (($parameterDefinition['optional'] ?? false) === true) {
                    continue;
                }
                throw new InvalidArgumentException("Falta el parámetro obligatorio [{$name}].");
            }

            $value = $parameters[$name];
            $pattern = $parameterDefinition['pattern'];
            if (! is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")
                || ! is_string($pattern) || @preg_match($pattern, $value) !== 1
            ) {
                throw new InvalidArgumentException("El parámetro [{$name}] no cumple el formato registrado.");
            }
            $argv[] = $value;
        }

        $environment = ['LANG' => 'C.UTF-8'];
        foreach (($definition['environment'] ?? []) as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) !== 1 || ! is_string($value) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('El entorno fijo del comando contiene una entrada inválida.');
            }
            $environment[$name] = $value;
        }
        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            $environment['PATH'] = $path;
        }

        return [
            'argv' => $argv,
            'environment' => $environment,
            'timeout' => min(86400, max(1, (int) ($definition['timeout'] ?? config('toolkit.runner.timeout_seconds', 3600)))),
            'max_output_bytes' => min(8_388_608, max(1024, (int) config('toolkit.runner.max_output_bytes', 1_048_576))),
        ];
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
