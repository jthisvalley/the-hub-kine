<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('kine', function (Request $request) {
            return $request->user()?->isKine()
                ? Limit::perMinute(100)->by($request->user()->id)
                : Limit::none();
        });

        RateLimiter::for('patient', function (Request $request) {
            return $request->user()?->isPatient()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::none();
        });
    }
}
