<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureQualityHttpHarness
{
    public static function enabled(): bool
    {
        return app()->environment('testing')
            && getenv('APP_ENV') === 'testing'
            && getenv('QUALITY_HARNESS') === '1'
            && config('database.default') === 'pgsql'
            && config('database.connections.pgsql.database') === 'moodle_toolkit_e2e';
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Recheck at request time, including when routes were cached elsewhere.
        abort_unless(self::enabled(), 404);

        return $next($request);
    }
}
