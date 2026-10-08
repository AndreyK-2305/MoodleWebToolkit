<?php

namespace App\Domain\Realtime;

use App\Domain\Realtime\Contracts\ExecutionEventTransport;
use App\Models\ExecutionEventOutbox;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecutionEventOutboxPublisher
{
    public function __construct(private readonly ExecutionEventTransport $transport) {}

    public function publish(int $outboxId): bool
    {
        if (DB::transactionLevel() !== 0) {
            return false;
        }

        return DB::transaction(function () use ($outboxId): bool {
            $row = ExecutionEventOutbox::query()->with('event.execution.project')->lockForUpdate()->find($outboxId);
            if ($row === null || $row->published_at !== null) {
                return true;
            }
            if ($row->available_at->isFuture()) {
                return false;
            }
            $row->delivery_attempts++;
            try {
                $this->transport->publish($row->event);
                $row->published_at = now()->utc();
                $row->last_failure_code = null;
                $row->save();

                return true;
            } catch (Throwable) {
                $row->last_failure_code = 'BROADCAST_UNAVAILABLE';
                $row->available_at = now()->utc()->addSeconds(min(300, 5 * (2 ** min(6, $row->delivery_attempts - 1))));
                $row->save();

                return false;
            }
        }, attempts: 3);
    }

    public function recover(int $limit = 20): int
    {
        $published = 0;
        $ids = ExecutionEventOutbox::query()->whereNull('published_at')->where('available_at', '<=', now()->utc())
            ->orderBy('id')->limit(min(1000, max(1, $limit)))->pluck('id');
        foreach ($ids as $id) {
            if ($this->publish((int) $id)) {
                $published++;
            }
        }

        return $published;
    }
}
