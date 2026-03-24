<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // \App\Http\Middleware\ApiVersionMiddleware::class . ':v1',
            \App\Http\Middleware\CheckUserActive::class,
            \App\Http\Middleware\LogActivityMiddleware::class,
            \App\Http\Middleware\RateLimitMiddleware::class,
        ],

        'kine' => [
            'auth:sanctum',
            'check.user.active',
            'role:kine',
            'throttle:kine',
            'activity.log',
        ],

        'patient' => [
            'auth:sanctum',
            'check.user.active',
            'role:patient',
            'throttle:patient',
            'activity.log',
        ],

        'admin' => [
            'auth:sanctum',
            'role:admin',
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,

        // Custom middleware for Hub Kiné
        'role' => \App\Http\Middleware\RoleMiddleware::class,
        'throttle.custom' => \App\Http\Middleware\RateLimitMiddleware::class,
        // 'api.version' => \App\Http\Middleware\ApiVersionMiddleware::class,
        'activity.log' => \App\Http\Middleware\LogActivityMiddleware::class,
        'check.user.active' => \App\Http\Middleware\CheckUserActive::class,
        'verify.device' => \App\Http\Middleware\VerifyDevice::class,
    ];

    /**
     * The application's middleware priority.
     *
     * @var array<int, class-string|string>
     */
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    /**
     * The bootstrap classes for the application.
     *
     * This method returns an array of classes that should be run during the
     * application's bootstrap process.
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return array_merge(
            parent::bootstrappers(),
            [
                // Add any custom bootstrappers here
            ]
        );
    }
}
