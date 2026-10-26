<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\FamilyUnitStudents;
use App\Modules\FamUnit\Domain\GuardianRecipients;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5, §7 y REQ-FAM-UNIT/funcional.md §8 (paso 1.14-a): descubierto por ModuleServiceProviderDiscovery, sin
 * registro a mano. Declara el descriptor (no esencial, sin dependencias: `core` y `auth` son esenciales y siempre
 * están activos, `OPEN-FAM-UNIT-14`), el catálogo de permisos de `permisos.md §2` —la fuente de verdad, INV-007— y el
 * *morph map* de sus modelos auditables (`AR-06`, `datos.md §4`); carga las migraciones del módulo (`AR-03`), registra
 * las interfaces públicas de `funcional.md §7` y los dos resolutores del ámbito `unidad_familiar`.
 *
 * Solo declara los permisos de los *endpoints* que existen en `1.14-a` (`familia`, `vinculo_tutor`, `revocacion_tutor`):
 * sin *endpoint*, un permiso invita a concederlo creyendo que hace algo. Los de `consentimiento_imagen`,
 * `condiciones_uso` y `aceptacion_condiciones` entran con `1.14-b`/`-c` (`funcional.md §15`, D-6).
 */
class FamUnitServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        // Memoiza por petición los nombres y ids de personas y usuarios que piden las representaciones (RN-FAM-UNIT-34).
        $this->app->scoped(FamilyRefs::class);

        // §7: una instancia nueva por resolución y ninguna memoización entre peticiones (`RN-FAM-UNIT-34`).
        $this->app->bind(FamilyMembership::class, EloquentFamilyMembership::class);
        $this->app->bind(GuardianRecipients::class, EloquentGuardianRecipients::class);
        $this->app->bind(FamilyUnitStudents::class, FamilyUnitStudentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/FamUnit/Database/migrations'));

        // ADR-034 §3: `audit_logs` guarda el alias, nunca el FQCN.
        Relation::enforceMorphMap([
            'family_unit' => FamilyUnit::class,
            'guardian' => Guardian::class,
            'family_unit_student' => FamilyUnitStudent::class,
            'guardian_student_link' => GuardianStudentLink::class,
        ]);

        // permisos.md §3.1 (`ADR-044 §3.4`): `unidad_familiar` sobre los recursos propios del módulo.
        $registry = $this->app->make(ScopeResolverRegistry::class);
        $registry->register(new FamiliaUnidadFamiliarScopeResolver);
        $registry->register(new VinculoTutorUnidadFamiliarScopeResolver);
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'fam_unit',
            'name_key' => 'modules.fam_unit',
            'phase' => '1',
            'depends_on' => [],
            'essential' => false,
        ];
    }

    public function declaredPermissions(): array
    {
        $permissions = [];

        // permisos.md §2: `familia` y `vinculo_tutor` leen con `todos` o `unidad_familiar` y escriben solo con `todos`
        // (el autoservicio del tutor principal está pendiente de `OPEN-FAM-UNIT-04`). Ni `exportar` ni `importar`
        // (`permisos.md §2.1`).
        foreach (['familia', 'vinculo_tutor'] as $resource) {
            foreach (['leer', 'crear', 'actualizar', 'eliminar'] as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                    'resource_label_key' => "fam_unit.permissions.resources.{$resource}",
                    'applicable_scopes' => $action === 'leer' ? ['todos', 'unidad_familiar'] : ['todos'],
                ];
            }
        }

        // `revocacion_tutor`: leer, crear (revocar) y eliminar (levantar); sin `actualizar` (permisos.md §3). No es
        // categoría especial (`permisos.md §5`): su lectura se limita con permiso propio y `RN-FAM-UNIT-36`.
        foreach (['leer', 'crear', 'eliminar'] as $action) {
            $permissions[] = [
                'code' => "revocacion_tutor.{$action}",
                'resource' => 'revocacion_tutor',
                'action' => $action,
                'is_special_category' => false,
                'resource_label_key' => 'fam_unit.permissions.resources.revocacion_tutor',
                'applicable_scopes' => ['todos'],
            ];
        }

        return $permissions;
    }
}
