<?php

namespace App\Modules\Prl\Infrastructure;

use App\Modules\Prl\Application\PrlRefs;
use App\Modules\Prl\Domain\Models\PickupAlert;
use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5 y REQ-PRL/funcional.md (paso 1.14b-b, `REQ-PRL-004`, `ADR-068 §5.1`): descubierto por
 * ModuleServiceProviderDiscovery, sin registro a mano. Declara el descriptor (`phase 1`, `depends_on ['fam_unit']`, no esencial:
 * la puerta consume la lista maestra de `fam_unit` y no se puede contratar sin ella, `RMOD-006`), el catálogo de permisos de
 * `permisos.md §8.2` de `REQ-PRL` —la fuente de verdad, INV-007— y el *morph map* de sus modelos auditables (`AR-06`,
 * `ADR-068 §6` `L10`); carga las migraciones del módulo (`AR-03`).
 *
 * En `1.14b-b` el módulo contiene **solo** `REQ-PRL-004`; el resto de `REQ-PRL` llega en fase 2 al mismo módulo
 * (`ADR-068 §5.1`). Solo declara los permisos de los *endpoints* que existen. `prl` **no** usa permisos de `fam_unit`: lee la
 * lista por la interfaz `AuthorizedPickupList` con su propio permiso (`entrega_menor.leer`).
 */
class PrlServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        // Memoiza por petición los nombres y `public_id` que piden las representaciones (una consulta por tipo, no por fila).
        $this->app->scoped(PrlRefs::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Prl/Database/migrations'));

        // ADR-034 §3: `audit_logs` guarda el alias, nunca el FQCN. ADR-068 §6 `L10`: alias estables.
        Relation::enforceMorphMap([
            'student_movement' => StudentMovement::class,
            'pickup_alert' => PickupAlert::class,
        ]);
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'prl',
            'name_key' => 'modules.prl',
            'phase' => '1',
            'depends_on' => ['fam_unit'],
            'essential' => false,
        ];
    }

    public function declaredPermissions(): array
    {
        $permissions = [];

        // permisos.md §8.2: `entrega_menor` (consulta de la lista en la puerta y registro de movimientos) y `alerta_entrega`
        // (abrir, consultar y resolver). Solo ámbito `todos`: que una familia vea las salidas de su hijo no lo pide
        // `REQ-PRL-004` (se valora en `1.22`). Ni `exportar` (vía de fuga de fotos de terceros) ni `importar`; ninguno de
        // categoría especial (`funcional.md §21.10` de `REQ-FAM-UNIT`).
        foreach (['entrega_menor' => ['leer', 'crear'], 'alerta_entrega' => ['leer', 'crear', 'actualizar']] as $resource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                    'resource_label_key' => "prl.permissions.resources.{$resource}",
                    'applicable_scopes' => ['todos'],
                ];
            }
        }

        return $permissions;
    }
}
