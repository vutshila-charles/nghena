<?php

namespace App\Providers;

use App\Services\Face\FaceProvider;
use App\Services\Face\UnavailableFaceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FaceProvider::class, function () {
            $provider = config('services.face.provider');

            return $provider && class_exists($provider)
                ? $this->app->make($provider)
                : $this->app->make(UnavailableFaceProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administer', fn ($user) => $user->is_active && $user->isAdmin());

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('attendance', fn (Request $request) => Limit::perMinute(20)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
