<?php

namespace App\Support\Modules;

/**
 * ADR-044 §4.9, REQ-PERM/operacion.md §6.2: el resolutor de permisos
 * necesita un único booleano — «¿este tenant puede usar este módulo
 * ahora?» — para hacer inertes las concesiones de un módulo no utilizable
 * (RMOD-009). Esta interfaz la posee `REQ-CORE` (la implementa
 * `EloquentModuleAvailability`); `App\Support\Authorization` la consume
 * sin importar nada de `App\Modules\Core` (INV-007).
 *
 * Antes de 1.5 esta lectura vivía duplicada dentro de `EnsureModuleEnabled`.
 * Con esta interfaz hay una sola definición de «utilizable», consumida por
 * el middleware y por el resolutor.
 *
 * `REQ-CORE` y `REQ-AUTH` no son desactivables (sus `module_code`, `core` y
 * `auth`, deben responder siempre `true`, sin necesidad de fila en
 * `module_subscriptions`) — la implementación lo resuelve, esta interfaz
 * solo expone el booleano.
 */
interface ModuleAvailability
{
    /**
     * Falla en cerrado fuera de un contexto de tenant activo: `false`,
     * nunca el valor de otro tenant.
     */
    public function isEnabled(string $moduleCode): bool;
}
