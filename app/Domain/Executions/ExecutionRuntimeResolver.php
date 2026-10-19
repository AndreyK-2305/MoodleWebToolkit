<?php

namespace App\Domain\Executions;

use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Tools\CollectorAdapter;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Exceptions\ToolOperationBlocked;
use App\Models\ExecutionCommand;

final class ExecutionRuntimeResolver
{
    /** @return array{provider: ExecutionProvider, adapter: ToolAdapter} */
    public function resolve(ExecutionCommand $command, ExecutionProvider $defaultProvider, ToolAdapter $defaultAdapter): array
    {
        $binding = $command->execution->toolBinding;
        if ($binding?->adapter_key === CollectorExecutionPreparation::ADAPTER_KEY) {
            if ($binding->provider_key !== 'local-registered-process' || $binding->runtime_configuration_id === null) {
                throw new ToolOperationBlocked('El binding real carece de provider o runtime aprobado.');
            }

            return ['provider' => app(LocalToolExecutionProvider::class), 'adapter' => app(CollectorAdapter::class)];
        }
        if (($command->payload['adapter'] ?? null) === CollectorExecutionPreparation::ADAPTER_KEY) {
            throw new ToolOperationBlocked('Una ejecución real sin binding no puede usar el modo demostrativo.');
        }

        return ['provider' => $defaultProvider, 'adapter' => $defaultAdapter];
    }
}
