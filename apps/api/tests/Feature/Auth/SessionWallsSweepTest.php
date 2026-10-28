<?php

use App\Models\User;
use App\Support\Sessions\SessionWall;
use App\Support\Sessions\SessionWallRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ADR-067 §7, CA-067-04 (barrido), INV-002, INV-010, INV-015: el muro va antes que `module-enabled`, `permission:` y el
// controlador en TODA ruta de `api/v1`, incluidas las que se añadan después. Se sustituye el registro del contenedor por
// uno que solo tiene un muro de prueba que siempre bloquea y no exime nada.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Muro de prueba: siempre bloquea, no exime ninguna ruta y no depende de ningún módulo opcional. */
final class AlwaysBlockingTestWall implements SessionWall
{
    public function code(): string
    {
        return 'test-wall';
    }

    public function errorType(): string
    {
        // Un type que ninguna otra capa emite, para distinguir el muro de un 403 de permisos o de módulo.
        return 'ip-not-allowed';
    }

    public function moduleCode(): string
    {
        return 'core';
    }

    public function exemptRouteNames(): array
    {
        return [];
    }

    public function blocks(User $user): bool
    {
        return true;
    }
}

test('CA-067-04: con un muro que siempre bloquea y no exime nada, toda ruta de api/v1 salvo L7 responde 403 con el type de ese muro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('sweep-067');

    $registry = new SessionWallRegistry(['test-wall']);
    $registry->register(AlwaysBlockingTestWall::class);
    $original = app(SessionWallRegistry::class);
    app()->instance(SessionWallRegistry::class, $registry);

    $checked = [];
    $unexpected = [];

    try {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1') || $route->getName() === 'auth.saml.acs') {
                continue;
            }

            $path = substr($route->uri(), strlen('api/v1'));
            // Parámetros de ruta ficticios: el muro tiene que responder antes de resolverlos.
            $path = preg_replace('/\{[^}?]+\??\}/', '01JZZZZZZZZZZZZZZZZZZZZZZZ', $path);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                resetSessionState();
                $response = test()->actingAs($admin)->json($method, coreApiUrl($tenant->slug, $path), []);
                $checked[] = "{$method} {$path}";

                if ($response->status() !== 403 || $response->json('type') !== 'urn:pge:error:ip-not-allowed') {
                    $unexpected[] = "{$method} {$route->uri()} ({$route->getName()}) → {$response->status()} ".(string) $response->json('type');
                }
            }
        }
    } finally {
        app()->instance(SessionWallRegistry::class, $original);
    }

    expect(count($checked))->toBeGreaterThan(50)
        ->and($unexpected)->toBe([], "rutas de api/v1 que no pasan por el muro antes de module-enabled, permission: y el controlador:\n".implode("\n", $unexpected));
});
