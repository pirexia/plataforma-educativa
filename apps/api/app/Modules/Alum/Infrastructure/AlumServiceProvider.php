<?php

namespace App\Modules\Alum\Infrastructure;

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
        ]);

        // permisos.md §3.1 (`ADR-044 §3.4`): `unidad_familiar` sobre `alumno`. `grupo` llega con 1.15-b.
        $this->app->make(ScopeResolverRegistry::class)->register(new AlumnoUnidadFamiliarScopeResolver);
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
        // permisos.md §2 (1.15-a): solo `alumno`; `matricula` llega con 1.15-b. `leer` admite `unidad_familiar` (el
        // tutor lee a sus alumnos; `grupo` se añade en 1.15-b con su resolutor); el resto, solo `todos`. Ninguno es de
        // categoría especial ni hay `exportar`/`importar` sobre `alumno`.
        $permissions = [];

        foreach (['leer', 'crear', 'actualizar', 'eliminar'] as $action) {
            $permissions[] = [
                'code' => "alumno.{$action}",
                'resource' => 'alumno',
                'action' => $action,
                'is_special_category' => false,
                'resource_label_key' => 'alum.permissions.resources.alumno',
                'applicable_scopes' => $action === 'leer' ? ['todos', 'unidad_familiar'] : ['todos'],
            ];
        }

        return $permissions;
    }
}
