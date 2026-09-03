<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IdempotencyKeyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La Idempotency-Key ya fue usada con una solicitud diferente.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['idempotency_key' => $this->getMessage()]);
    }
}
