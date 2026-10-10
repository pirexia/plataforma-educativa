<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Lang;

/**
 * REQ-PERM/api.md §14.3 (1.5b, S-PERM-2, `RN-PERM-28`): etiqueta traducida
 * de un recurso del catálogo de permisos. La clave de traducción la declara
 * el módulo dueño junto a cada recurso de su `declaredPermissions()`
 * (`resource_label_key`, `DeclaresModuleRegistry`) y vive en sus propios
 * `lang/{es,en,de,fr}`: el cliente no lleva ninguna lista de recursos
 * ajenos (`INV-007`, `INV-009`).
 *
 * Rama por defecto en servidor: si el módulo no declara la clave, o la
 * clave no tiene traducción, la etiqueta es el código del recurso. La
 * respuesta nunca queda vacía; quien falla es el test del catálogo
 * (`CA-PERM-134`), no la respuesta.
 *
 * Fuente: los `ServiceProvider` de módulo descubiertos
 * (`ModuleServiceProviderDiscovery`), la misma que lee
 * `platform:sync-registry`. Lo declarado es código, no datos de tenant:
 * se memoiza por proceso.
 */
final class PermissionResourceLabels
{
    /** @var array<string, string>|null recurso → clave de traducción */
    private ?array $keys = null;

    public function label(string $resource): string
    {
        $key = $this->keys()[$resource] ?? null;

        if ($key !== null && Lang::has($key)) {
            return (string) __($key);
        }

        return $resource;
    }

    /**
     * @return array<string, string>
     */
    public function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $keys = [];

        foreach (ModuleServiceProviderDiscovery::discover(app_path('Modules')) as $class) {
            $provider = app()->getProvider($class);

            if (! $provider instanceof DeclaresModuleRegistry) {
                continue;
            }

            foreach ($provider->declaredPermissions() as $permission) {
                if (isset($permission['resource_label_key'])) {
                    $keys[$permission['resource']] = $permission['resource_label_key'];
                }
            }
        }

        return $this->keys = $keys;
    }
}
