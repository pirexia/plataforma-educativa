<?php

use App\Http\Middleware\ResolveTenant;
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
        // ADR-014/ADR-033 §2: primero del grupo web, antes de sesión. El
        // grupo api NO lo lleva global a propósito: /api/health (fuera de
        // v1, usado por el healthcheck del contenedor) tiene que responder
        // sin tenant. Las rutas de negocio van bajo /api/v1, que sí lo
        // aplica explícitamente (ver routes/api.php).
        $middleware->prependToGroup('web', ResolveTenant::class);
        $middleware->alias(['resolve-tenant' => ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
