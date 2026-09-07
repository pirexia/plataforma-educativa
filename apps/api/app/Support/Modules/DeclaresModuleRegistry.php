<?php

namespace App\Support\Modules;

/**
 * ADR-034 §2, §5, §7: la fuente de verdad de módulos y permisos es el
 * código de cada bounded context (INV-007), materializada en `modules` y
 * `permissions` por el comando `platform:sync-registry` (0.8.11). Un
 * ServiceProvider de módulo que implemente esta interfaz se recoge solo,
 * sin registrarlo a mano en ningún sitio — misma filosofía que
 * ModuleServiceProviderDiscovery.
 */
interface DeclaresModuleRegistry
{
    /**
     * @return array{code: string, name_key: string, phase: string}
     */
    public function moduleDescriptor(): array;

    /**
     * REQ-PERM/datos.md §3 (1.5): `applicable_scopes` es opcional — su
     * omisión equivale a `['todos']` (funcional.md §3.2 regla 1). Cuando se
     * declara, cada valor debe pertenecer al vocabulario cerrado de
     * `App\Support\Authorization\Scope`; `SyncModuleRegistry` aborta el
     * despliegue si no es así (RN-PERM-01, operacion.md §4.2).
     *
     * @return list<array{code: string, resource: string, action: string, is_special_category?: bool, applicable_scopes?: list<string>}>
     */
    public function declaredPermissions(): array;
}
