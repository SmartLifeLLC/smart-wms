<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withSchedule(function (Schedule $schedule): void {
        // WMS Wave Generation - runs at 6:00, 7:00, and 8:00 daily
        $schedule->command('wms:generate-waves')->dailyAt('06:00');
        $schedule->command('wms:generate-waves')->dailyAt('07:00');
        $schedule->command('wms:generate-waves')->dailyAt('08:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
