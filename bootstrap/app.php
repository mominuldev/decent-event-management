<?php

use App\Http\Middleware\EnsureDeviceActive;
use App\Http\Middleware\EnsureGateAssigned;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureIpnFromAllowlistedIp;
use App\Http\Middleware\EnsureRecentlyReauthenticated;
use App\Http\Middleware\EnsureWithinCheckInWindow;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('api/scanner')
                ->group(base_path('routes/scanner.php'));

            Route::prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'device.active' => EnsureDeviceActive::class,
            'checkin.window' => EnsureWithinCheckInWindow::class,
            'gate.assigned' => EnsureGateAssigned::class,
            'idempotent' => EnsureIdempotency::class,
            'ipn.allowlist' => EnsureIpnFromAllowlistedIp::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'reauth' => EnsureRecentlyReauthenticated::class,
        ]);

        $middleware->throttleApi();

        $middleware->append(SetSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
