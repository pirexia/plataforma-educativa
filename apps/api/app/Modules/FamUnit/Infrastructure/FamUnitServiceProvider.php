<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\FamilyUnitStudents;
use App\Modules\FamUnit\Domain\GuardianRecipients;
use App\Modules\FamUnit\Domain\ImageConsentPolicy;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Modules\FamUnit\Domain\Models\TermsAcceptance;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Sessions\SessionWallRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5, §7 y REQ-FAM-UNIT/funcional.md §8 (paso 1.14-a): descubierto por ModuleServiceProviderDiscovery, sin
 * registro a mano. Declara el descriptor (no esencial, sin dependencias: `core` y `auth` son esenciales y siempre
 * están activos, `OPEN-FAM-UNIT-14`), el catálogo de permisos de `permisos.md §2` —la fuente de verdad, INV-007— y el
 * *morph map* de sus modelos auditables (`AR-06`, `datos.md §4`); carga las migraciones del módulo (`AR-03`), registra
 * las interfaces públicas de `funcional.md §7` y los dos resolutores del ámbito `unidad_familiar`.
 *
 * Solo declara los permisos de los *endpoints* que existen: sin *endpoint*, un permiso invita a concederlo creyendo que
 * hace algo. `1.14-a`: `familia`, `vinculo_tutor`, `revocacion_tutor`. `1.14-b`: `consentimiento_imagen` y `texto_legal`
 * (`ADR-066`, `OPEN-066-03`: el recurso de las versiones de texto legal se llama `texto_legal`). `1.14-c`:
 * `aceptacion_condiciones` (`funcional.md §15`, D-6) y el muro de condiciones de uso (`ADR-067`).
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
        // §7.3 (`INV-014`, `ADR-066 §5.3`): sin memoización entre llamadas; retirar un consentimiento surte efecto en la siguiente.
        $this->app->bind(ImageConsentPolicy::class, EloquentImageConsentPolicy::class);
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
            'terms_version' => TermsVersion::class,
            'terms_acceptance' => TermsAcceptance::class,
            'image_consent_decision' => ImageConsentDecision::class,
        ]);

        // permisos.md §3.1 (`ADR-044 §3.4`): `unidad_familiar` sobre los recursos propios del módulo.
        $registry = $this->app->make(ScopeResolverRegistry::class);
        $registry->register(new FamiliaUnidadFamiliarScopeResolver);
        $registry->register(new VinculoTutorUnidadFamiliarScopeResolver);
        $registry->register(new ConsentimientoImagenUnidadFamiliarScopeResolver);

        // ADR-067 §5.7: el muro de condiciones de uso, segundo en el orden cerrado L6. Se registra la clase (el
        // middleware la resuelve en cada petición) y siempre: si el módulo está activo lo decide `RequireSessionWalls`.
        $this->app->make(SessionWallRegistry::class)->register(TermsAcceptanceWall::class);
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

        // `consentimiento_imagen` (1.14-b, permisos.md §2): leer y actualizar admiten `unidad_familiar` (el tutor decide sobre
        // sus alumnos); exportar es del centro. `actualizar` y no `crear`: la decisión cambia el estado, que internamente
        // se guarde como fila nueva es implementación.
        foreach (['leer' => ['todos', 'unidad_familiar'], 'actualizar' => ['todos', 'unidad_familiar'], 'exportar' => ['todos']] as $action => $scopes) {
            $permissions[] = [
                'code' => "consentimiento_imagen.{$action}",
                'resource' => 'consentimiento_imagen',
                'action' => $action,
                'is_special_category' => false,
                'resource_label_key' => 'fam_unit.permissions.resources.consentimiento_imagen',
                'applicable_scopes' => $scopes,
            ];
        }

        // `texto_legal` (1.14-b, permisos.md §2): versiones de los textos legales del centro; solo `todos`.
        foreach (['leer', 'crear', 'actualizar', 'eliminar', 'publicar'] as $action) {
            $permissions[] = [
                'code' => "texto_legal.{$action}",
                'resource' => 'texto_legal',
                'action' => $action,
                'is_special_category' => false,
                'resource_label_key' => 'fam_unit.permissions.resources.texto_legal',
                'applicable_scopes' => ['todos'],
            ];
        }

        // `aceptacion_condiciones` (1.14-c, permisos.md §2): consulta administrativa de las aceptaciones; solo lectura y
        // solo `todos`. Quien redacta las condiciones no tiene por qué verlas: recurso aparte de `texto_legal`.
        $permissions[] = [
            'code' => 'aceptacion_condiciones.leer',
            'resource' => 'aceptacion_condiciones',
            'action' => 'leer',
            'is_special_category' => false,
            'resource_label_key' => 'fam_unit.permissions.resources.aceptacion_condiciones',
            'applicable_scopes' => ['todos'],
        ];

        return $permissions;
    }
}
