<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies('*', headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_AWS_ELB);

        $middleware->alias([
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
            'filament.auth' => \App\Http\Middleware\FilamentAuth::class,
            'jx.basic' => \App\Http\Middleware\JxBasicAuth::class,
            'swagger.basic' => \App\Http\Middleware\SwaggerBasicAuth::class,
        ]);

        $middleware->redirectGuestsTo('/admin/login');

        // JXサーバーエンドポイントはCSRF検証から除外
        $middleware->validateCsrfTokens(except: [
            'jx-server',
            'jx-server/*',
            'admin/error-inquiries',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
