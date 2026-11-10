<?php

namespace App\Modules\Alum\Infrastructure;

use App\Modules\Acad\Domain\StructureDeletionVetoRegistry;
use App\Modules\Acad\Domain\StructureEntity;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\Student;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-056 §3.5 (generado por `php artisan make:module`): descubierto por
 * ModuleServiceProviderDiscovery, sin registro a mano. Declara el descriptor
 * (no esencial; depende de `curso`, `acad` y `fam_unit`, OPEN-ALUM-28 = A) y el catálogo de permisos de
 * REQ-ALUM/permisos.md §2 (`AR-03`, `ADR-034 §5`). 1.15-a: solo `alumno`.
 */
class AlumServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Alum/Database/migrations'));

        // ADR-034 §3: `audit_logs` guarda el alias, nunca el FQCN.
        Relation::enforceMorphMap([
            'alum_student' => Student::class,
            'alum_enrollment' => Enrollment::class,
        ]);

        // permisos.md §3.1 (`ADR-044 §3.4`): cuatro resolutores de 1.15-a/1.15-b (`GroupChangeRequest` llega con 1.15-c).
        $resolvers = $this->app->make(ScopeResolverRegistry::class);
        $resolvers->register(new AlumnoUnidadFamiliarScopeResolver);
        $resolvers->register(new AlumnoGrupoScopeResolver);
        $resolvers->register(new MatriculaGrupoScopeResolver);
        $resolvers->register(new MatriculaUnidadFamiliarScopeResolver);

        // RN-ALUM-26, RN-ACAD-21 (CA-ALUM-027): dos vetos independientes. El de curso de etapa cuenta también las
        // matrículas sin grupo.
        $vetoes = $this->app->make(StructureDeletionVetoRegistry::class);
        $vetoes->register(new EnrollmentStructureDeletionVeto(StructureEntity::Group));
        $vetoes->register(new EnrollmentStructureDeletionVeto(StructureEntity::Course));
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'alum',
            'name_key' => 'modules.alum',
            'phase' => '1',
            'depends_on' => ['curso', 'acad', 'fam_unit'],
            'essential' => false,
        ];
    }

    public function declaredPermissions(): array
    {
        // permisos.md §2 (1.15-b): diez códigos. `leer` de `alumno` y de `matricula` admite `grupo` (resolutor de `alum`
        // sobre `TeachingMembership`, RN-ACAD-98) y `unidad_familiar`; el resto, solo `todos`. Ninguno es de categoría
        // especial. Sin `alumno.exportar` ni `*.importar` (§2.1).
        $permissions = [];

        $catalog = [
            'alumno' => ['leer', 'crear', 'actualizar', 'eliminar'],
            'matricula' => ['leer', 'crear', 'actualizar', 'eliminar', 'aprobar', 'exportar'],
        ];

        foreach ($catalog as $resource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                    'resource_label_key' => "alum.permissions.resources.{$resource}",
                    'applicable_scopes' => $action === 'leer' ? ['todos', 'grupo', 'unidad_familiar'] : ['todos'],
                ];
            }
        }

        return $permissions;
    }
}
