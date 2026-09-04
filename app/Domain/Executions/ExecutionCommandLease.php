<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\ClaimedExecutionCommand;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ExecutionCommandLease
{
    public const ABANDONMENT_GRACE_SECONDS = 60;

    /** Broker/job execution window plus a fixed reconciliation safety margin. */
    public function durationSeconds(): int
    {
        return max(
            ExecutionQueueConfiguration::JOB_TIMEOUT_SECONDS,
            (int) config('queue.connections.redis.retry_after'),
        ) + self::ABANDONMENT_GRACE_SECONDS;
    }

    public function claim(int $commandId): ?ClaimedExecutionCommand
    {
        return DB::transaction(function () use ($commandId): ?ClaimedExecutionCommand {
            $command = $this->lockCommand($commandId);

            if ($command === null || $command->processed_at !== null || $command->processing_started_at !== null) {
                return null;
            }

            $owner = (string) Str::uuid();
            // Eloquent serializes datetimes without their offset. Normalize lease
            // instants to UTC before persistence so PostgreSQL and PHP compare the
            // same instant even when APP_TIMEZONE is not UTC.
            $startedAt = now()->utc()->toImmutable();
            $command->processing_started_at = $startedAt;
            $command->lease_owner = $owner;
            $command->lease_expires_at = $startedAt->addSeconds($this->durationSeconds());
            $command->save();

            return new ClaimedExecutionCommand($command, $owner);
        }, attempts: 3);
    }

    public function lockCommand(int $commandId): ?ExecutionCommand
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Los comandos sólo pueden bloquearse dentro de una transacción.');
        }

        $seed = ExecutionCommand::query()->select(['id', 'execution_id'])->find($commandId);

        if ($seed === null) {
            return null;
        }

        $projectId = Execution::query()
            ->whereKey($seed->execution_id)
            ->value('project_id');

        if ($projectId === null) {
            return null;
        }

        $project = Project::query()->lockForUpdate()->find((int) $projectId);
        $execution = Execution::query()->lockForUpdate()->find((int) $seed->execution_id);
        $command = ExecutionCommand::query()->lockForUpdate()->find($commandId);

        if ($project === null || $execution === null || $command === null) {
            return null;
        }

        if ($execution->project_id !== $project->getKey() || $command->execution_id !== $execution->getKey()) {
            throw new LogicException('La identidad bloqueada del comando no coincide con su ejecución y proyecto.');
        }

        $execution->setRelation('project', $project);
        $command->setRelation('execution', $execution);

        return $command;
    }

    public function isOwnedAndActive(ExecutionCommand $command, string $owner): bool
    {
        return $command->processed_at === null
            && $command->lease_owner !== null
            && hash_equals($command->lease_owner, $owner)
            && $command->lease_expires_at !== null
            && $command->lease_expires_at->isFuture();
    }

    public function isAbandoned(ExecutionCommand $command): bool
    {
        if ($command->processed_at !== null || $command->processing_started_at === null) {
            return false;
        }

        if ($command->lease_expires_at !== null) {
            return $command->lease_expires_at->lessThanOrEqualTo(now()->utc());
        }

        // processing_started_at predates leases and was serialized in the
        // application's wall-clock timezone. shiftTimezone preserves that wall
        // clock while restoring the instant represented by legacy rows.
        return $command->processing_started_at
            ->shiftTimezone((string) config('app.timezone'))
            ->addSeconds($this->durationSeconds())
            ->lessThanOrEqualTo(now());
    }

    public function legacyAbandonedBefore(): CarbonImmutable
    {
        return now()->toImmutable()->subSeconds($this->durationSeconds());
    }

    public function finish(ExecutionCommand $command): void
    {
        $command->processed_at = now()->utc();
        $command->lease_owner = null;
        $command->lease_expires_at = null;
        $command->save();
    }

    public function releaseForRetry(ExecutionCommand $command): void
    {
        $command->processing_started_at = null;
        $command->lease_owner = null;
        $command->lease_expires_at = null;
        $command->dispatched_at = null;
        $command->save();
    }
}
