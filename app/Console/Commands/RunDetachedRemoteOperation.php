<?php

namespace App\Console\Commands;

use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\RemoteOperation;
use Illuminate\Console\Command;
use RuntimeException;

class RunDetachedRemoteOperation extends Command
{
    protected $signature = 'toolkit:run-detached-remote-operation {requestPath}';

    protected $description = 'Ejecuta una operación registrada fuera del worker Laravel y conserva evidencia durable.';

    public function handle(RemoteOperationCoordinator $operations, ExecutionWorkspaceManager $workspaces): int
    {
        $requestPath = (string) $this->argument('requestPath');
        if (is_link($requestPath) || !is_file($requestPath)) {
            $this->error('No se encontró el descriptor privado de la operación.');
            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($requestPath), true);
        if (!is_array($payload) || !is_int($payload['operation_id'] ?? null)) {
            $this->error('El descriptor privado de la operación no es válido.');
            return self::FAILURE;
        }
        $operation = RemoteOperation::query()->with('execution')->find($payload['operation_id']);
        if ($operation === null || $operation->operation_uuid !== ($payload['operation_uuid'] ?? null)
            || !hash_equals($operation->command_sha256, (string) ($payload['command_sha256'] ?? ''))
        ) {
            $this->error('La identidad del descriptor no coincide con la operación durable.');
            return self::FAILURE;
        }
        $expected = $workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'request.json');
        if (realpath($requestPath) === false || realpath($expected) !== realpath($requestPath)) {
            $this->error('El descriptor está fuera del área privada de estado de la ejecución.');
            return self::FAILURE;
        }
        if (!unlink($requestPath)) {
            throw new RuntimeException('No se pudo retirar el descriptor de lanzamiento de un solo uso.');
        }

        $operations->runDetached(
            (int) $operation->getKey(),
            (string) $payload['command_key'],
            is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [],
            (string) ($payload['working_directory'] ?? ''),
            is_array($payload['registered_definition'] ?? null) ? $payload['registered_definition'] : [],
        );

        return self::SUCCESS;
    }
}
