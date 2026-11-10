<?php

namespace App\Providers;

use App\Services\ImageEngine\GatewayEngine;
use App\Services\ImageEngine\ImageEngine;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sin singleton: si el admin cambia la URL o el token, el próximo uso ya los toma.
        $this->app->bind(ImageEngine::class, fn () => GatewayEngine::fromSettings());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Freno a los clics repetidos en Generar (la cuota diaria es aparte).
        RateLimiter::for('generate', fn (Request $request) => Limit::perMinute(12)
            ->by((string) $request->user()?->id ?: $request->ip())
            ->response(function () {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Demasiados pedidos seguidos. Esperá un momento.']);

                return back();
            }));
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
