<?php

namespace App\Domain\Idempotency;

use App\Exceptions\IdempotencyKeyConflict;
use App\Models\IdempotencyReceipt;

final class IdempotencyRegistry
{
    public function find(
        int $executionId,
        int $userId,
        string $action,
        string $key,
        string $payloadHash,
    ): ?IdempotencyReceipt {
        $receipt = IdempotencyReceipt::query()
            ->where('execution_id', $executionId)
            ->where('user_id', $userId)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($receipt !== null && ! hash_equals($receipt->payload_hash, $payloadHash)) {
            throw new IdempotencyKeyConflict;
        }

        return $receipt;
    }

    public function record(
        int $executionId,
        int $userId,
        string $action,
        string $resourceType,
        int $resourceId,
        string $scope,
        string $key,
        string $payloadHash,
        string $resultType,
        int $resultId,
        int $responseStatus,
    ): IdempotencyReceipt {
        return IdempotencyReceipt::query()->create([
            'execution_id' => $executionId,
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'scope' => $scope,
            'idempotency_key' => $key,
            'payload_hash' => $payloadHash,
            'result_type' => $resultType,
            'result_id' => $resultId,
            'response_status' => $responseStatus,
        ]);
    }
}
