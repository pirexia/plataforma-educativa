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
     * @return list<array{code: string, resource: string, action: string, is_special_category?: bool}>
     */
    public function declaredPermissions(): array;
}
