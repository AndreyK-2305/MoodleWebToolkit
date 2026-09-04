<?php

namespace App\Domain\Executions;

use App\Exceptions\ExecutionDispatchFailed;
use App\Jobs\RunExecutionUnit;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecutionCommandDispatcher
{
    public function dispatch(ExecutionCommand $command): void
    {
        $command = ExecutionCommand::query()->with('execution')->findOrFail((int) $command->getKey());

        if ($command->processed_at !== null) {
            return;
        }

        try {
            DB::transaction(function () use ($command): void {
                $locked = ExecutionCommand::query()->lockForUpdate()->findOrFail((int) $command->getKey());

                if ($locked->processed_at !== null) {
                    return;
                }

                $locked->dispatched_at = now();
                $locked->dispatch_attempts = ((int) $locked->dispatch_attempts) + 1;
                $locked->save();
            });
            Bus::dispatch((new RunExecutionUnit((int) $command->getKey()))->onQueue('executions'));
        } catch (Throwable $exception) {
            DB::transaction(function () use ($command): void {
                $locked = ExecutionCommand::query()->lockForUpdate()->find((int) $command->getKey());

                if ($locked !== null && $locked->processed_at === null) {
                    $locked->dispatched_at = null;
                    $locked->save();
                }
            });
            ExecutionLog::query()->create([
                'execution_id' => $command->execution_id,
                'stream' => 'SYSTEM',
                'level' => 'ERROR',
                'message' => 'El comando persistido no pudo enviarse a la cola y quedó pendiente de recuperación.',
                'context' => ['exception_type' => $exception::class],
            ]);

            throw new ExecutionDispatchFailed($command->execution->uuid);
        }
    }
}
