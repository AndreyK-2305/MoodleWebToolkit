<?php

namespace App\Console\Commands;

use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Processes\RegisteredCommandRegistry;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\RemoteOperation;
use Illuminate\Console\Command;
use RuntimeException;

class RunDetachedRemoteOperation extends Command
{
    protected $signature = 'toolkit:run-detached-remote-operation {requestPath}';

    protected $description = 'Ejecuta una operación registrada fuera del worker Laravel y conserva evidencia durable.';

    public function handle(
        RemoteOperationCoordinator $operations,
        ExecutionWorkspaceManager $workspaces,
        RegisteredCommandRegistry $registry,
    ): int {
        $requestPath = (string) $this->argument('requestPath');
        if (is_link($requestPath) || is_file($requestPath) === false) {
            $this->error('No se encontró el descriptor privado de la operación.');

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($requestPath), true);
        if (is_array($payload) === false || is_int($payload['operation_id'] ?? null) === false) {
            $this->error('El descriptor privado de la operación no es válido.');

            return self::FAILURE;
        }
        $operation = RemoteOperation::query()->with('execution')->find($payload['operation_id']);
        if ($operation === null || $operation->operation_uuid !== ($payload['operation_uuid'] ?? null)
            || hash_equals($operation->command_sha256, (string) ($payload['command_sha256'] ?? '')) === false
        ) {
            $this->error('La identidad del descriptor no coincide con la operación durable.');

            return self::FAILURE;
        }
        $expected = $workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'request.json');
        if (realpath($requestPath) === false || realpath($expected) !== realpath($requestPath)) {
            $this->error('El descriptor está fuera del área privada de estado de la ejecución.');

            return self::FAILURE;
        }
        if (unlink($requestPath) === false) {
            throw new RuntimeException('No se pudo retirar el descriptor de lanzamiento de un solo uso.');
        }

        $commandKey = $payload['command_key'] ?? null;
        $rawParameters = $payload['parameters'] ?? null;
        if (is_string($commandKey) === false || is_array($rawParameters) === false) {
            $this->error('El comando o sus parámetros no tienen un formato válido.');

            return self::FAILURE;
        }
        /** @var array<string, string> $parameters */
        $parameters = [];
        foreach ($rawParameters as $name => $value) {
            if (is_string($name) === false || is_string($value) === false) {
                $this->error('Los parámetros del comando no tienen un formato válido.');

                return self::FAILURE;
            }
            $parameters[$name] = $value;
        }

        $operations->runDetached(
            (int) $operation->getKey(),
            $commandKey,
            $parameters,
            is_string($payload['working_directory'] ?? null) ? $payload['working_directory'] : '',
            $registry->resolve($commandKey, $parameters),
        );

        return self::SUCCESS;
    }
}
