<?php

use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\ModuleServiceProviderDiscovery;
use Illuminate\Support\Facades\Route;

pest()->group('arch');

// ADR-056 AR-07a (CA-056-08, INV-002) y AR-07b (CA-056-08, RMOD-009,
// ADR-044 §4.9), INV-015. Sobre la TABLA DE RUTAS registrada (patrón de
// `ADR-051 §5.2` y `CA-BO-013`): lo que de verdad responde, no lo que dice el
// texto de `routes.php`. Calculada en entorno de test, como la suite: incluye
// las rutas de simulador que solo existen aquí.

/**
 * Excepciones de AR-07a: lista CERRADA y nominal por nombre de ruta (ADR-056
 * §3.2). Solo puede reducirse; añadir una exige especificación aprobada por
 * el usuario (OPEN-056-02). Son rutas de autoservicio o públicas que no
 * llevan `permission:` porque no hay recurso con permiso que comprobar.
 *
 * @return array<string, string> nombre de ruta => motivo
 */
function routesWithoutPermissionExceptions(): array
{
    $auth = 'REQ-AUTH: anónima o de autoservicio, se autoriza por identidad o posesión de credencial (REQ-AUTH/permisos.md §1)';
    $me = 'REQ-CORE: autoservicio del propio usuario autenticado';

    return [
        'core.tenant.branding' => 'REQ-CORE: marca pública del centro, anónima por diseño',
        'core.me.show' => $me,
        'core.me.update' => $me,
        'core.me.effective-permissions' => $me,
        'core.feature-flags.index' => 'REQ-CORE: evaluación de feature flags del propio usuario/centro',
        'curso.academic-years.current' => 'REQ-CURSO: curso activo del centro, dato del centro y no de una persona; autoservicio por identidad como GET /me (OPEN-CURSO-15, ampliación aprobada por el usuario el 2026-10-07, ADR-056 §3.2)',
        'core.data-exports.show' => 'REQ-CORE: autoriza por `kind` en el controlador (el permiso depende del tipo de exportación)',
        'auth.csrf-cookie' => $auth,
        'auth.session.store' => $auth,
        'auth.session.destroy' => $auth,
        'auth.invitation-redemptions.store' => $auth,
        'auth.password-reset-requests.store' => $auth,
        'auth.password-resets.store' => $auth,
        'auth.account-unlocks.store' => $auth,
        'auth.password-changes.store' => $auth,
        'auth.sessions.index' => $auth,
        'auth.sessions.destroy' => $auth,
        'auth.sessions.destroy-all' => $auth,
        'auth.mfa.show' => $auth,
        'auth.mfa-enrollments.store' => $auth,
        'auth.mfa-factors.store' => $auth,
        'auth.mfa-factors.destroy' => $auth,
        'auth.mfa-recovery-codes.store' => $auth,
        'auth.mfa-challenges.store' => $auth,
        'auth.mfa-challenges.show' => $auth,
        'auth.mfa-verifications.store' => $auth,
        'auth.identity-providers.index' => $auth,
        'auth.oauth-authorizations.store' => $auth,
        'auth.oauth.google.callback' => $auth,
        'auth.identities.index' => $auth,
        'auth.identities.destroy' => $auth,
        'auth.oauth.fake.authorize' => $auth.' (simulador, solo entorno de test)',
        'auth.oauth.oidc.callback' => $auth,
        'auth.saml.acs' => $auth,
    ];
}

/**
 * @param  list<array{name: ?string, uri: string, middleware: list<string>, module: ?string}>  $routes
 * @param  list<string>  $exceptions  nombres de ruta exentos
 * @return list<string> rutas de `api/v1` sin `permission:` y fuera de la lista
 */
function routesMissingPermission(array $routes, array $exceptions): array
{
    $missing = [];

    foreach ($routes as $route) {
        if (! str_starts_with($route['uri'], 'api/v1')) {
            continue;
        }

        $hasPermission = array_filter($route['middleware'], static fn (string $m): bool => str_starts_with($m, 'permission:')) !== [];

        if ($hasPermission || in_array($route['name'], $exceptions, true)) {
            continue;
        }

        $missing[] = ($route['name'] ?? '(sin nombre)').' '.$route['uri'];
    }

    return $missing;
}

/**
 * RMOD-009, ADR-044 §4.9: toda ruta de un módulo NO esencial lleva
 * `module-enabled:<código>` y, si lleva `permission:`, va ANTES.
 *
 * @param  list<array{name: ?string, uri: string, middleware: list<string>, module: ?string}>  $routes
 * @param  array<string, bool>  $essentialByModuleCode  código => essential
 * @return list<string>
 */
function routesMissingModuleGate(array $routes, array $essentialByModuleCode): array
{
    $violations = [];

    foreach ($routes as $route) {
        $code = $route['module'];

        if ($code === null || ($essentialByModuleCode[$code] ?? true) === true) {
            continue;
        }

        $gate = array_search("module-enabled:{$code}", $route['middleware'], true);
        $permission = null;

        foreach ($route['middleware'] as $index => $middleware) {
            if (str_starts_with($middleware, 'permission:')) {
                $permission = $index;
                break;
            }
        }

        $label = ($route['name'] ?? '(sin nombre)').' '.$route['uri'];

        if ($gate === false) {
            $violations[] = "{$label}: falta module-enabled:{$code}";
        } elseif ($permission !== null && $permission < $gate) {
            $violations[] = "{$label}: permission: va antes que module-enabled:{$code}";
        }
    }

    return $violations;
}

/**
 * Tabla de rutas registrada, normalizada.
 *
 * @return list<array{name: ?string, uri: string, middleware: list<string>, module: ?string}>
 */
function registeredRouteTable(): array
{
    $codes = [];

    foreach (ModuleServiceProviderDiscovery::discover(app_path('Modules')) as $provider) {
        $instance = new $provider(app());

        if ($instance instanceof DeclaresModuleRegistry) {
            $codes[explode('\\', $provider)[2]] = $instance->moduleDescriptor()['code'];
        }
    }

    $table = [];

    foreach (Route::getRoutes() as $route) {
        $controller = $route->getControllerClass();
        $module = null;

        if (is_string($controller) && str_starts_with($controller, 'App\\Modules\\')) {
            $module = $codes[explode('\\', $controller)[2]] ?? null;
        }

        $table[] = [
            'name' => $route->getName(),
            'uri' => $route->uri(),
            'middleware' => array_values(array_filter($route->gatherMiddleware(), 'is_string')),
            'module' => $module,
        ];
    }

    return $table;
}

/**
 * @return array<string, bool> código de módulo => essential
 */
function moduleEssentialByCode(): array
{
    $essential = [];

    foreach (ModuleServiceProviderDiscovery::discover(app_path('Modules')) as $provider) {
        $instance = new $provider(app());

        if ($instance instanceof DeclaresModuleRegistry) {
            $descriptor = $instance->moduleDescriptor();
            $essential[$descriptor['code']] = $descriptor['essential'] ?? false;
        }
    }

    return $essential;
}

test('AR-07a CA-056-08 INV-002: toda ruta de api/v1 lleva permission: o está en la lista cerrada', function (): void {
    $table = registeredRouteTable();
    $apiV1 = array_filter($table, static fn (array $r): bool => str_starts_with($r['uri'], 'api/v1'));

    expect(count($apiV1))->toBeGreaterThan(50);

    $missing = routesMissingPermission($table, array_keys(routesWithoutPermissionExceptions()));

    expect($missing)->toBe([], "rutas de api/v1 sin permission: (INV-002, denegar por defecto):\n".implode("\n", $missing));
});

test('AR-07a CA-056-15: cada excepción nominal existe como ruta de api/v1 y sigue sin permission:', function (): void {
    $table = registeredRouteTable();
    $byName = [];

    foreach ($table as $route) {
        if ($route['name'] !== null && str_starts_with($route['uri'], 'api/v1')) {
            $byName[$route['name']] = $route;
        }
    }

    $stale = [];

    foreach (array_keys(routesWithoutPermissionExceptions()) as $name) {
        if (! isset($byName[$name])) {
            $stale[] = "{$name}: la ruta ya no existe en api/v1";

            continue;
        }

        if (array_filter($byName[$name]['middleware'], static fn (string $m): bool => str_starts_with($m, 'permission:')) !== []) {
            $stale[] = "{$name}: ya lleva permission:";
        }
    }

    expect($stale)->toBe([], "excepciones de AR-07a que ya no hacen falta, retirarlas de la lista:\n".implode("\n", $stale))
        ->and(routesWithoutPermissionExceptions())->toHaveCount(34);
});

test('AR-07b CA-056-08 RMOD-009: toda ruta de un módulo no esencial lleva module-enabled:<código> antes de permission:', function (): void {
    $essential = moduleEssentialByCode();

    expect($essential)->not->toBe([]);

    $violations = routesMissingModuleGate(registeredRouteTable(), $essential);

    expect($violations)->toBe([], "rutas de módulos activables sin module-enabled (RMOD-009):\n".implode("\n", $violations));
});

test('AR-07a AR-07b control negativo: las funciones detectan ruta sin permission:, sin module-enabled y en orden inverso', function (): void {
    $routes = [
        ['name' => 'x.ok', 'uri' => 'api/v1/x', 'middleware' => ['module-enabled:x', 'permission:x.leer'], 'module' => 'x'],
        ['name' => 'x.sin-permiso', 'uri' => 'api/v1/x/a', 'middleware' => ['module-enabled:x'], 'module' => 'x'],
        ['name' => 'x.sin-modulo', 'uri' => 'api/v1/x/b', 'middleware' => ['permission:x.leer'], 'module' => 'x'],
        ['name' => 'x.orden', 'uri' => 'api/v1/x/c', 'middleware' => ['permission:x.leer', 'module-enabled:x'], 'module' => 'x'],
        ['name' => 'x.exenta', 'uri' => 'api/v1/x/d', 'middleware' => ['module-enabled:x'], 'module' => 'x'],
        ['name' => 'esencial.sin', 'uri' => 'api/v1/e', 'middleware' => ['permission:e.leer'], 'module' => 'e'],
        ['name' => 'fuera', 'uri' => 'api/platform/z', 'middleware' => [], 'module' => null],
    ];

    expect(routesMissingPermission($routes, ['x.exenta']))->toBe(['x.sin-permiso api/v1/x/a'])
        ->and(routesMissingModuleGate($routes, ['x' => false, 'e' => true]))->toBe([
            'x.sin-modulo api/v1/x/b: falta module-enabled:x',
            'x.orden api/v1/x/c: permission: va antes que module-enabled:x',
        ]);
});
