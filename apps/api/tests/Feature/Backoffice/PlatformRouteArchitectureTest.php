<?php

use App\Modules\Backoffice\Http\Middleware\EnforcePlatformIpAllowlist;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformCapability;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformHost;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformMfa;
use Illuminate\Support\Facades\Route;

/**
 * ADR-046 §4.5, api.md §0/§1.1. Cuatro aserciones sobre
 * `Route::getRoutes()` — la verdad efectiva, no el texto de los
 * ficheros.
 */

// CA-BO-011: ninguna ruta de /api/platform/* lleva los tres middleware de tenant.
test('CA-BO-011: ninguna ruta de /api/platform/* lleva resolve-tenant, verify-session-tenant o require-mfa-enrollment', function (): void {
    $forbidden = ['resolve-tenant', 'verify-session-tenant', 'require-mfa-enrollment'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        $checked++;
        $middleware = $route->gatherMiddleware();

        foreach ($forbidden as $alias) {
            expect($middleware)->not->toContain($alias, "{$route->uri()}: lleva {$alias}, prohibido en el grupo de plataforma");
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

// CA-BO-013: toda ruta de /api/platform/* lleva la pila completa y en
// orden — comprobado por presencia. `require-platform-capability` lleva
// parámetro (`:xxx`), así que se compara por prefijo.
test('CA-BO-013: toda ruta de /api/platform/* lleva la pila completa de plataforma, en orden', function (): void {
    $expectedPrefixes = [
        'require-platform-host',
        'enforce-platform-ip-allowlist',
        'encrypt-cookies',
        'add-queued-cookies',
        'configure-platform-session',
        'start-session',
        'csrf',
        'require-platform-session-idle-timeout',
        'resolve-platform-locale',
        'require-platform-mfa',
        'require-platform-capability',
    ];

    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        $checked++;
        $middleware = array_values($route->gatherMiddleware());

        $positions = [];

        foreach ($expectedPrefixes as $expected) {
            $index = null;

            foreach ($middleware as $i => $actual) {
                if ($actual === $expected || str_starts_with($actual, "{$expected}:")) {
                    $index = $i;
                    break;
                }
            }

            expect($index)->not->toBeNull("{$route->uri()}: falta {$expected} en la pila");
            $positions[] = $index;
        }

        expect($positions)->toBe(
            collect($positions)->sort()->values()->all(),
            "{$route->uri()}: la pila no respeta el orden de api.md §1.1"
        );
    }

    expect($checked)->toBeGreaterThan(0);
});

// CA-BO-014: ninguna ruta de /api/v1/* ni del grupo web usa la
// maquinaria del guard 'platform' — ninguno de sus middleware propios.
test('CA-BO-014: ninguna ruta fuera de /api/platform/* usa middleware del guard platform', function (): void {
    $platformOnly = [
        'require-platform-host', 'enforce-platform-ip-allowlist', 'configure-platform-session',
        'require-platform-mfa', 'require-platform-capability', 'require-platform-reauthentication',
        'require-platform-session-idle-timeout', 'resolve-platform-locale',
    ];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        foreach ($middleware as $m) {
            $alias = explode(':', $m)[0];

            expect(in_array($alias, $platformOnly, true))
                ->toBeFalse("{$route->uri()}: usa {$alias}, exclusivo del grupo de plataforma");
        }
    }
});

// CA-BO-015: ninguna ruta fuera de /api/platform/* apunta a un
// controlador de App\Modules\Backoffice.
test('CA-BO-015: ninguna ruta fuera de /api/platform/* apunta a un controlador de App\\Modules\\Backoffice', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        $controller = is_string($route->getActionName()) ? $route->getActionName() : '';

        expect($controller)->not->toContain('App\\Modules\\Backoffice', "{$route->uri()}: apunta a un controlador de Backoffice fuera de su grupo");
    }
});

// Issue #174 (OPEN-BO-13): RequirePlatformMfa no puede volver a llevar una
// lista de nombres de ruta como excepción — es exactamente lo que la
// decisión del usuario descartó («existe una lista que alguien puede
// ampliar»). Comprueba, por reflexión, que la clase no declara ninguna
// constante ni propiedad estática que parezca una lista de rutas: la
// excepción sólo puede venir del parámetro `:exento` en `routes.php`.
test('issue #174: RequirePlatformMfa no declara ninguna constante de exención por nombre de ruta', function (): void {
    $reflection = new ReflectionClass(RequirePlatformMfa::class);

    foreach ($reflection->getConstants() as $name => $value) {
        expect(is_array($value))->toBeFalse(
            "RequirePlatformMfa::{$name} es un array — parece una lista de excepciones por nombre de ruta, prohibida por OPEN-BO-13"
        );
    }

    foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
        expect($property->getName())->not->toContain('ROUTE', "RequirePlatformMfa tiene una propiedad estática {$property->getName()} que sugiere una lista de rutas");
    }
});

// Issue #174: comprueba, sobre Route::getRoutes(), que las rutas
// pre-MFA/pre-sesión (y sólo ellas) llevan `require-platform-mfa:exento`
// — la propia lista de la especificación (api.md §1.1.1), pero ahora
// comprobada contra el parámetro de cada ruta, no contra una constante de
// la clase.
test('issue #174: sólo las rutas de pre-autenticación y alta de MFA llevan require-platform-mfa:exento', function (): void {
    $expectedExempt = [
        'platform.csrf-cookie',
        'platform.auth.session.store',
        'platform.auth.session.mfa.store',
        'platform.auth.session.destroy',
        'platform.admin-invitation-redemptions.store',
        'platform.me.show',
        'platform.mfa.factors.store',
        'platform.mfa.factors.confirm',
        'platform.mfa.recovery-codes.index',
    ];

    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        $checked++;
        $isExempt = in_array('require-platform-mfa:exento', $route->gatherMiddleware(), true);

        expect($isExempt)->toBe(
            in_array($route->getName(), $expectedExempt, true),
            "{$route->uri()}: exención de MFA inesperada (o ausente)"
        );
    }

    expect($checked)->toBeGreaterThan(0);
});

// funcional.md §3.4 condición 5: los alias resuelven a una clase real y
// arrancable — si no, ninguna ruta de plataforma respondería nada y
// CA-BO-013 ya lo habría detectado por ausencia; esta prueba lo hace
// explícito resolviendo la clase desde el contenedor, sin depender de
// la forma interna en la que Router guarda el mapa de alias.
test('los alias de middleware de plataforma resuelven a una clase instanciable', function (): void {
    foreach ([
        RequirePlatformHost::class,
        EnforcePlatformIpAllowlist::class,
        RequirePlatformMfa::class,
        RequirePlatformCapability::class,
    ] as $class) {
        expect(app($class))->toBeInstanceOf($class);
    }
});
