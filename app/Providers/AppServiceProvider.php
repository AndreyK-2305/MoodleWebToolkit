<?php

namespace App\Providers;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\FakeExecutionProvider;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\FakeToolAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ToolAdapter::class, FakeToolAdapter::class);
        $this->app->bind(ExecutionProvider::class, FakeExecutionProvider::class);
        $this->app->bind(ArtifactStorage::class, LocalArtifactStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
