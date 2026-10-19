<?php

use App\Http\Middleware\EnsureQualityHttpHarness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('__quality/expire-action-authorization', function (Request $request) {
    abort_unless(EnsureQualityHttpHarness::enabled(), 404);
    $request->session()->forget('auth.password_confirmed_at');

    // StartSession saves this exact session before returning the response and
    // releasing its lock. No session identifiers are returned to the browser.
    return response()->noContent();
})->middleware([EnsureQualityHttpHarness::class, 'web', 'auth', 'active', 'verified', 'password.changed'])
    ->name('quality.expire-action-authorization');
