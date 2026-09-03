<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExecutionAlreadyActive extends DomainException
{
    public function __construct()
    {
        parent::__construct('El proyecto ya tiene una ejecución activa.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['execution' => $this->getMessage()]);
    }
}
