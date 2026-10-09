<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureQualityHttpHarness;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QualityHttpHarnessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('registrationEnvironments')]
    public function test_route_registration_requires_the_isolated_quality_environment(string $environment, string $harness, string $driver, string $database, bool $registered): void
    {
        $probe = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$route = $app['router']->getRoutes()->getByName('quality.expire-action-authorization');
echo json_encode(['registered' => $route !== null, 'middleware' => $route?->gatherMiddleware() ?? []], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $probe], base_path(), [
            'APP_ENV' => $environment, 'QUALITY_HARNESS' => $harness,
            'DB_CONNECTION' => $driver, 'DB_DATABASE' => $database,
        ], timeout: 15);
        $this->assertSame(0, $process->run(), 'The isolated route-registration probe failed.');
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($registered, $result['registered']);
        if ($registered) {
            $this->assertContains('web', $result['middleware']);
            $this->assertContains('auth', $result['middleware']);
            $this->assertContains(EnsureQualityHttpHarness::class, $result['middleware']);
        }
    }

    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function registrationEnvironments(): iterable
    {
        yield 'explicit isolated E2E' => ['testing', '1', 'pgsql', 'moodle_toolkit_e2e', true];
        yield 'production with all quality settings' => ['production', '1', 'pgsql', 'moodle_toolkit_e2e', false];
        yield 'missing opt-in' => ['testing', '', 'pgsql', 'moodle_toolkit_e2e', false];
        yield 'different database' => ['testing', '1', 'pgsql', 'moodle_toolkit_testing', false];
        yield 'different database driver' => ['testing', '1', 'sqlite', 'moodle_toolkit_e2e', false];
    }

    public function test_authenticated_action_removes_confirmation_and_keeps_observation_without_returning_secrets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $originalHarness = getenv('QUALITY_HARNESS');
        try {
            $this->registerQualityRoute();
            $this->postJson('/__quality/expire-action-authorization')
                ->assertNoContent()->assertSessionMissing('auth.password_confirmed_at');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertOk()->assertSessionMissing('auth.password_confirmed_at');
        } finally {
            $this->restoreEnvironment('QUALITY_HARNESS', $originalHarness);
        }
    }

    public function test_a_cached_route_and_its_action_recheck_runtime_guards(): void
    {
        $originalHarness = getenv('QUALITY_HARNESS');
        $originalEnvironment = getenv('APP_ENV');
        try {
            $this->registerQualityRoute();
            $route = Route::getRoutes()->getByName('quality.expire-action-authorization');
            $this->assertNotNull($route);
            $action = $route->getAction('uses');
            $this->assertInstanceOf(Closure::class, $action);
            $request = Request::create('/__quality/expire-action-authorization', 'POST');
            $request->setLaravelSession($this->app['session']->driver());
            $request->session()->put('auth.password_confirmed_at', now()->timestamp);

            // Simulate a testing route cache loaded under a different runtime.
            foreach ([['QUALITY_HARNESS', '0'], ['APP_ENV', 'production']] as [$name, $value]) {
                putenv('QUALITY_HARNESS=1');
                putenv('APP_ENV=testing');
                putenv($name.'='.$value);
                foreach ([
                    fn () => (new EnsureQualityHttpHarness)->handle($request, fn () => response()->noContent()),
                    fn () => $action($request),
                ] as $invoke) {
                    try {
                        $invoke();
                        $this->fail('A cached quality route accepted a forbidden runtime.');
                    } catch (HttpException $exception) {
                        $this->assertSame(404, $exception->getStatusCode());
                    }
                }
                $this->assertTrue($request->session()->has('auth.password_confirmed_at'));
            }
        } finally {
            $this->restoreEnvironment('QUALITY_HARNESS', $originalHarness);
            $this->restoreEnvironment('APP_ENV', $originalEnvironment);
        }
    }

    public function test_expiration_requires_an_authenticated_session(): void
    {
        $originalHarness = getenv('QUALITY_HARNESS');
        $confirmedAt = now()->timestamp;
        try {
            $this->registerQualityRoute();
            $this->withSession(['auth.password_confirmed_at' => $confirmedAt])
                ->postJson('/__quality/expire-action-authorization')
                ->assertUnauthorized()->assertSessionHas('auth.password_confirmed_at', $confirmedAt);
        } finally {
            $this->restoreEnvironment('QUALITY_HARNESS', $originalHarness);
        }
    }

    private function registerQualityRoute(): void
    {
        putenv('QUALITY_HARNESS=1');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'moodle_toolkit_e2e']);
        require base_path('tests/Support/quality-http-routes.php');
        Route::getRoutes()->refreshNameLookups();
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
