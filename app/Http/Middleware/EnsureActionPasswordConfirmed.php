<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActionPasswordConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (is_numeric($confirmedAt)
            && ((int) now()->timestamp - (int) $confirmedAt) < (int) config('auth.password_timeout')
        ) {
            return $next($request);
        }

        $request->session()->flash('action_confirmation_required', true);
        $payload = [
            'message' => 'Confirme su contraseña para modificar datos. El seguimiento permanece disponible.',
            'code' => 'PASSWORD_CONFIRMATION_REQUIRED',
        ];

        if ($request->expectsJson()) {
            return new JsonResponse($payload, 423);
        }

        return new RedirectResponse(url()->previous(), 303);
    }
}
