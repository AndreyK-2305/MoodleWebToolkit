<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfirmActionPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:web'],
        ], [
            'password.current_password' => 'La contraseña es incorrecta.',
        ]);

        $confirmedAt = (int) now()->timestamp;
        $request->session()->put('auth.password_confirmed_at', $confirmedAt);

        return response()->json([
            'confirmed_at' => $confirmedAt,
            'expires_at' => $confirmedAt + (int) config('auth.password_timeout'),
        ]);
    }
}
