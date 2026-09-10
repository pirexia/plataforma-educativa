<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequireMfaEnrollment;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveApiLocale;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\VerifySessionTenant;
use App\Modules\Backoffice\Http\Middleware\ConfigurePlatformSession;
use App\Modules\Backoffice\Http\Middleware\EnforcePlatformIpAllowlist;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformCapability;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformHost;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformMfa;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformReauthentication;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformSessionIdleTimeout;
use App\Modules\Backoffice\Http\Middleware\ResolvePlatformLocale;
use App\Support\Api\ProblemResponseFactory;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
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
            // REQ-AUTH (1.2), api.md §8 (posición 3): cookie de sesión +
            // CSRF bajo /api/v1. Imprescindible para que el propio
            // mecanismo de Laravel funcione: sin ella, ni la cookie de
            // sesión ni la cookie XSRF-TOKEN viajan cifradas, y
            // PreventRequestForgery (alias 'csrf') intenta descifrar la
            // cabecera X-XSRF-TOKEN igualmente — el CSRF fallaría siempre.
            'encrypt-cookies' => EncryptCookies::class,
            'add-queued-cookies' => AddQueuedCookiesToResponse::class,
            'start-session' => StartSession::class,
            'csrf' => ValidateCsrfToken::class,
            'verify-session-tenant' => VerifySessionTenant::class,
            'session-idle-timeout' => EnforceSessionIdleTimeout::class,
            // REQ-AUTH-003 (1.3), funcional.md §C.4.9: el muro de alta.
            'require-mfa-enrollment' => RequireMfaEnrollment::class,

            // REQ-BO (1.6), ADR-046 §4.1, api.md §1.1: pila propia del
            // grupo /api/platform/*, sin ninguno de los tres alias de
            // arriba que son de tenant.
            'require-platform-host' => RequirePlatformHost::class,
            'enforce-platform-ip-allowlist' => EnforcePlatformIpAllowlist::class,
            'configure-platform-session' => ConfigurePlatformSession::class,
            'require-platform-mfa' => RequirePlatformMfa::class,
            'require-platform-capability' => RequirePlatformCapability::class,
            'require-platform-reauthentication' => RequirePlatformReauthentication::class,
            'require-platform-session-idle-timeout' => RequirePlatformSessionIdleTimeout::class,
            'resolve-platform-locale' => ResolvePlatformLocale::class,
        ]);

        // ADR-046 §4.5, RN-BO-48, RN-BO-06. `Illuminate\Routing\SortedMiddleware`
        // reordena la pila final según `$middlewarePriority`, no según el
        // orden declarado en `routes/api.php` — verificado en ejecución
        // (hallazgo real de 1.6, ver docblock de `ConfigurePlatformSession`).
        // `EncryptCookies`/`AddQueuedCookiesToResponse`/`StartSession` ya
        // están en esa lista; `SubstituteBindings` (grupo global `api`)
        // también, y detrás de `StartSession`. Sin declarar aquí la
        // posición relativa de las cuatro piezas propias de plataforma que
        // SÍ importan (dos por orden de negocio, dos por su propio
        // contrato con el driver de sesión), el ordenador las deja fuera
        // de la lista de prioridad y las trata como "sin posición" —
        // libres de que Laravel las reordene alrededor de las que sí
        // tienen prioridad, que es exactamente lo que rompía la cookie de
        // sesión de plataforma en silencio.
        //
        // Orden deseado, de fuera adentro: RequirePlatformHost (404 antes
        // que nada) → EnforcePlatformIpAllowlist (403 antes de sesión y
        // credenciales) → EncryptCookies → AddQueuedCookiesToResponse →
        // ConfigurePlatformSession (fija el almacén ANTES de StartSession)
        // → StartSession. Cada llamada inserta justo delante del ancla
        // indicada en el array YA MODIFICADO por las llamadas anteriores,
        // así que el orden de estas cuatro líneas importa tanto como los
        // argumentos.
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: RequirePlatformHost::class,
        );
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: EnforcePlatformIpAllowlist::class,
        );
        $middleware->prependToPriorityList(
            before: StartSession::class,
            prepend: ConfigurePlatformSession::class,
        );
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
