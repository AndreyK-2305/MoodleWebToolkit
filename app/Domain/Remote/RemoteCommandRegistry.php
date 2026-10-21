<?php

namespace App\Domain\Remote;

use InvalidArgumentException;

/**
 * Platform-side mirror of the operations registered in mwt-agent. Both sides
 * validate: the platform never sends what the agent would reject.
 */
class RemoteCommandRegistry
{
    /** @var array<string, array{parameters: array<string, string>, cancellable: bool}> */
    private const COMMANDS = [
        'diagnostic.sleep' => ['parameters' => ['seconds' => '/^[1-9][0-9]{0,4}$/D'], 'cancellable' => true],
        'diagnostic.fail' => ['parameters' => ['code' => '/^[1-9][0-9]?$/D'], 'cancellable' => false],
        'diagnostic.lines' => ['parameters' => ['count' => '/^[1-9][0-9]{0,3}$/D'], 'cancellable' => true],
        'collector.742' => ['parameters' => [
            'distribution' => '/^[0-9a-f]{64}$/D',
            'source' => '/^[a-z0-9][a-z0-9-]{1,62}$/D',
            'package' => '/^[a-z][a-z0-9_-]{0,63}$/D',
            'workers' => '/^(auto|[1-4])$/D',
        ], 'cancellable' => true],
        'package.upload' => ['parameters' => [
            'collector' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
            'project' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
            'bucket' => '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',
            'region' => '/^[a-z]{2}(-gov)?-[a-z]+-[1-9]$/D',
        ], 'cancellable' => true],
    ];

    /**
     * @param  array<string, string>  $parameters
     * @return array{key: string, arguments: list<string>, parameters: array<string, string>, cancellable: bool}
     */
    public function resolve(string $key, array $parameters): array
    {
        $definition = self::COMMANDS[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('La operación remota no está registrada.');
        }
        if (array_diff_key($parameters, $definition['parameters']) !== [] || array_diff_key($definition['parameters'], $parameters) !== []) {
            throw new InvalidArgumentException('Los parámetros no coinciden con la operación registrada.');
        }
        ksort($parameters, SORT_STRING);
        $arguments = [];
        foreach ($parameters as $name => $value) {
            if (preg_match($definition['parameters'][$name], $value) !== 1) {
                throw new InvalidArgumentException('Un parámetro de la operación remota no es válido.');
            }
            $arguments[] = $name.'='.$value;
        }
        if ($key === 'diagnostic.sleep' && (int) $parameters['seconds'] > 86400) {
            throw new InvalidArgumentException('La espera de diagnóstico no puede superar un día.');
        }

        return ['key' => $key, 'arguments' => $arguments, 'parameters' => $parameters, 'cancellable' => $definition['cancellable']];
    }
}
