<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\GradingScaleValue;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5, §7 y REQ-ACAD/funcional.md §8 (pasos 1.11-b, 1.11d-a y 1.11d-b):
 * descubierto por ModuleServiceProviderDiscovery, sin registro a mano. Declara
 * el descriptor (no esencial, depende de `curso`: OPEN-ACAD-10), el catálogo de
 * permisos de `permisos.md §2` y `§2.2` —la fuente de verdad, INV-007— y el
 * *morph map* de sus nueve modelos auditables (`AR-06`, `datos.md §4`); carga
 * las migraciones del módulo (`AR-03`) y registra en `curso` la validación del
 * cambio de fechas del curso (`RN-ACAD-62`, `OPEN-ACAD-24` = B).
 *
 * Sin resolutores de ámbito: `acad` no registra nada en `ScopeResolverRegistry`
 * (`permisos.md §3.1`, `ADR-063 §5.1`, `CA-ACAD-037`).
 */
class AcadServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Acad/Database/migrations'));

        // ADR-034 §3: `audit_logs` guarda el alias, nunca el FQCN.
        Relation::enforceMorphMap([
            'education_level' => EducationLevel::class,
            'course' => Course::class,
            'group' => Group::class,
            'subgroup' => Subgroup::class,
            'subject' => Subject::class,
            'group_subject' => GroupSubject::class,
            'academic_period' => AcademicPeriod::class,
            'grading_scale' => GradingScale::class,
            'grading_scale_value' => GradingScaleValue::class,
        ]);

        // RN-ACAD-62: «el cambio de fechas no deja fuera ningún período lectivo
        // vivo». `curso` la ejecuta (con su FOR UPDATE retenido, RN-CURSO-51)
        // solo si `acad` está contratado en el centro (RN-CURSO-52).
        $this->app->make(AcademicYearDateChangeRegistry::class)->register($this->app->make(PeriodsOutsideAcademicYearCheck::class));
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'acad',
            'name_key' => 'modules.acad',
            'phase' => '1',
            'depends_on' => ['curso'],
            'essential' => false,
        ];
    }

    public function declaredPermissions(): array
    {
        $permissions = [];

        // permisos.md §2 y §2.2: dos recursos independientes (RN-ACAD-50), cuatro
        // acciones cada uno, todas de ámbito `todos` explícito (ADR-044 §4.1).
        // Ni `exportar` ni `importar` (§2.1): sin endpoint, un permiso invita a
        // concederlo creyendo que hace algo.
        foreach (['estructura_academica', 'configuracion_academica'] as $resource) {
            foreach (['leer', 'crear', 'actualizar', 'eliminar'] as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                    'resource_label_key' => "acad.permissions.resources.{$resource}",
                    'applicable_scopes' => ['todos'],
                ];
            }
        }

        return $permissions;
    }
}
