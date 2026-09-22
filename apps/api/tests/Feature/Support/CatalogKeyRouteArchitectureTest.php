<?php

use App\Support\Api\CatalogKeyRouteParameters;
use Illuminate\Support\Facades\Route;

/**
 * `ADR-051 §5.2`: «se recorren las rutas registradas y todo parámetro de
 * ruta es o `public_id`/`publicId`, o una entrada declarada en ese
 * registro. Cualquier otro parámetro falla la suite.»
 *
 * Dos excepciones que la propia enmienda ya nombra y que no son
 * identificadores de fila (`ADR-051 §3`): `kind` (`/tenant/settings/
 * assets/{kind}`, selecciona un aspecto, no una fila) y `uuid`
 * (`/tenants/{public_id}/failed-jobs/{uuid}/retry`, la tabla nativa de
 * Laravel `failed_jobs`, anterior a `ADR-029` y fuera de su alcance).
 */

// ADR-051 §5.2, CA-BO (test de arquitectura de 1.6e, funcional.md §15.5 punto 11).
test('ADR-051 §5.2: todo parámetro de ruta es public_id, publicId, *PublicId, o una clave de catálogo registrada', function (): void {
    // `kind`/`uuid`: ADR-051 §3 (selector de aspecto, tabla nativa de
    // Laravel anterior a ADR-029). `path`/`fallbackPlaceholder`: rutas
    // que registra el propio framework (`storage:link`,
    // `Route::fallback()`), fuera del alcance de este ADR por completo —
    // no direccionan ninguna entidad de negocio.
    $exempt = ['kind', 'uuid', 'path', 'fallbackPlaceholder'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        foreach ($route->parameterNames() as $parameter) {
            $checked++;

            $isPublicId = $parameter === 'public_id'
                || $parameter === 'publicId'
                || str_ends_with($parameter, 'PublicId')
                || str_ends_with($parameter, '_public_id');

            $isRegisteredCatalogKey = array_key_exists($parameter, CatalogKeyRouteParameters::REGISTRY);

            $isExempt = in_array($parameter, $exempt, true);

            expect($isPublicId || $isRegisteredCatalogKey || $isExempt)->toBeTrue(
                "{$route->uri()}: el parámetro «{$parameter}» no es public_id, no está en CatalogKeyRouteParameters::REGISTRY, ".
                'y no está en la lista de excepciones declaradas (ADR-051 §2, §3, §5.1).'
            );
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

test('ADR-051 §5.1: el registro de claves de catálogo sólo declara claves con el formato de datos.md §9.1 o de código de módulo', function (): void {
    foreach (CatalogKeyRouteParameters::REGISTRY as $parameter => $source) {
        expect($parameter)->toMatch('/^[a-z][a-z0-9_]*$/');
        expect(str_contains($source, '.'))->toBeTrue("«{$source}» debería ser \"tabla.columna\"");
    }
});
