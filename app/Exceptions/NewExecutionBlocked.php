<?php

namespace App\Exceptions;

use App\Enums\ProjectStatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewExecutionBlocked extends DomainException
{
    public function __construct(ProjectStatus $status)
    {
        parent::__construct("No se puede crear una ejecución con el proyecto en {$status->value}.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['execution' => $this->getMessage()]);
    }
}
