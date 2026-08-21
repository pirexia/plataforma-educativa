<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveApiLocale;
use App\Http\Middleware\ResolveTenant;
use App\Support\Api\ProblemResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // INV-013: antes que nada, en los dos grupos — a diferencia de
        // ResolveTenant, request_id no depende de tenant y tiene que
        // existir incluso en /api/health.
        $middleware->prependToGroup('web', AssignRequestId::class);
        $middleware->prependToGroup('api', AssignRequestId::class);

        // ADR-014/ADR-033 §2: primero del grupo web, antes de sesión. El
        // grupo api NO lo lleva global a propósito: /api/health (fuera de
        // v1, usado por el healthcheck del contenedor) tiene que responder
        // sin tenant. Las rutas de negocio van bajo /api/v1, que sí lo
        // aplica explícitamente (ver routes/api.php).
        $middleware->prependToGroup('web', ResolveTenant::class);
        $middleware->alias([
            'resolve-tenant' => ResolveTenant::class,
            'resolve-locale' => ResolveApiLocale::class,
            'permission' => RequirePermission::class,
            'module-enabled' => EnsureModuleEnabled::class,
            'idempotent' => RequireIdempotencyKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ADR-038 §6: toda respuesta 4xx/5xx de /api/* es application/
        // problem+json, sin excepción. shouldRenderJsonWhen() ya decide
        // CUÁNDO se sirve JSON; este render() decide la FORMA de ese JSON,
        // en vez del array {message, errors} por defecto de Laravel.
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemResponseFactory::render($e, $request);
        });
    })->create();
