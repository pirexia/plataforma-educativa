<?php

namespace App\Support\Api;

/**
 * `ADR-051 §5.1`: registro único, comprobable, de los parámetros de ruta
 * que transportan una **clave de catálogo** en vez de `public_id`/
 * `publicId` — la enmienda de `ADR-029` que admite direccionar por clave
 * cuando se cumplen las seis condiciones de `ADR-051 §2`, verificadas en
 * el `datos.md` del módulo que la declara, nunca por analogía con esta
 * lista.
 *
 * Un solo sitio, y el test de arquitectura (`ADR-051 §5.2`,
 * `CatalogKeyRouteArchitectureTest`) lo lee de aquí: cualquier parámetro
 * de ruta que no sea `public_id`/`publicId` y no esté en este registro
 * hace fallar la suite. Añadir una entrada exige haber demostrado las
 * seis condiciones en el `datos.md` del módulo — este fichero no las
 * comprueba, sólo las declara.
 */
final class CatalogKeyRouteParameters
{
    /**
     * @var array<string, string> nombre del parámetro de ruta => `tabla.columna` que expone
     */
    public const REGISTRY = [
        // feature_flags.key (1.6e, ADR-051 §4): cuatro rutas de
        // api.md §2.11 — /feature-flags/{key}, .../rules/preview,
        // .../state, .../rules.
        'key' => 'feature_flags.key',

        // modules.code (1.6c, ya expuesta desde antes de este ADR —
        // ADR-051 §6, issue #238): PUT /tenants/{public_id}/modules/{module_code}.
        'module_code' => 'modules.code',
    ];
}
