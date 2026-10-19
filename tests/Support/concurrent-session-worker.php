<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'moodle_toolkit_testing') {
    exit(2);
}
[$script, $mode, $sessionId, $prefix, $confirmedAt] = $argv;
if (! in_array($mode, ['observe', 'confirm', 'expire'], true)) {
    exit(2);
}
config(['session.driver' => 'database', 'session.block_store' => 'redis']);
$method = $mode === 'expire' ? 'POST' : 'GET';
$request = Request::create('/quality/session-'.$mode, $method, cookies: [config('session.cookie') => $sessionId]);
$request->setRouteResolver(fn () => new Route([$method], '/quality/session-'.$mode, fn () => null));
$app->instance('request', $request);
$userId = DB::table('sessions')->where('id', $sessionId)->value('user_id');
if ($userId !== null) {
    Auth::guard('web')->setUser(User::query()->findOrFail($userId));
}
Redis::setex($prefix.':'.$mode.':attempted', 30, '1');
$response = $app->make(StartSession::class)->handle($request, function (Request $request) use ($mode, $prefix, $confirmedAt) {
    if ($mode === 'observe') {
        $request->session()->put('observation_seen', true);
        Redis::setex($prefix.':observe:loaded', 30, '1');
        if (Redis::blpop([$prefix.':release'], 10) === null) {
            throw new RuntimeException('Session barrier was not released.');
        }
    } elseif ($mode === 'confirm') {
        $request->session()->put('auth.password_confirmed_at', (int) $confirmedAt);
    } else {
        $request->session()->forget('auth.password_confirmed_at');
        Redis::setex($prefix.':expire:loaded', 30, '1');
    }

    return response()->json(['confirmed_at' => $request->session()->get('auth.password_confirmed_at')]);
});
echo $response->getContent();
