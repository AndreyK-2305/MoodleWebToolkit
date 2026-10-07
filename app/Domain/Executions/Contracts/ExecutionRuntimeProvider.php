<?php

namespace App\Domain\Executions\Contracts;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\ToolDistribution;

interface ExecutionRuntimeProvider extends ExecutionProvider
{
    /** @return array<string, mixed> */
    public function prepare(Execution $execution, ToolDistribution $distribution): array;

    /** @return array<string, mixed> */
    public function deploy(Execution $execution, ToolDistribution $distribution): array;

    /** @param array<string, string> $parameters */
    public function start(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters = []): RemoteOperation;

    /** @return array<string, mixed> */
    public function inspect(RemoteOperation $operation): array;

    public function poll(RemoteOperation $operation): RemoteOperation;

    /** @return list<array<string, mixed>> */
    public function readEvents(RemoteOperation $operation, int $afterSequence = 0): array;

    /** @return list<array<string, mixed>> */
    public function readLogs(RemoteOperation $operation, int $afterId = 0): array;

    public function heartbeat(RemoteOperation $operation): RemoteOperation;

    public function reconcile(RemoteOperation $operation): RemoteOperation;

    public function cancel(RemoteOperation $operation): RemoteOperation;

    public function stopRuntime(RemoteOperation $operation): RemoteOperation;

    /** @return list<\App\Models\Artifact> */
    public function collectArtifacts(RemoteOperation $operation): array;

    public function verifyTermination(RemoteOperation $operation): bool;

    public function cleanup(Execution $execution): void;
}
