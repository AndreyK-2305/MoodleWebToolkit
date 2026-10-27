<?php

namespace App\Providers;

use App\Database\OffsetPostgresConnection;
use App\Domain\Artifacts\Contracts\ArtifactReferenceStorage;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Aws\RemoteStorageConfiguration;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Collector\LabFileSecretProvider;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\FakeExecutionProvider;
use App\Domain\Realtime\Contracts\ExecutionEventTransport;
use App\Domain\Realtime\ReverbExecutionEventTransport;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\FakeToolAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dates keep their offset on the way to timestamptz columns; see OffsetPostgresGrammar.
        Connection::resolverFor('pgsql', fn ($connection, string $database, string $prefix, array $config) => new OffsetPostgresConnection($connection, $database, $prefix, $config));
        $this->app->bind(SecretProvider::class, LabFileSecretProvider::class);
        $this->app->bind(ToolAdapter::class, FakeToolAdapter::class);
        $this->app->bind(ExecutionProvider::class, FakeExecutionProvider::class);
        $this->app->bind(ExecutionEventTransport::class, ReverbExecutionEventTransport::class);
        $this->app->bind(ArtifactStorage::class, LocalArtifactStorage::class);
        $this->app->bind(ArtifactReferenceStorage::class, LocalArtifactStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRemoteStorage();
    }

    /**
     * The package bucket saved in «Administración → AWS» overrides the environment's.
     * Long-running workers read it again before every job, so a change applies without a restart.
     */
    protected function configureRemoteStorage(): void
    {
        $storage = $this->app->make(RemoteStorageConfiguration::class);
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            $storage->apply();
        }
        Event::listen(JobProcessing::class, fn () => $storage->apply());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
