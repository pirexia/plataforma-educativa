<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearReadDeniedException;
use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Modules\Curso\Domain\AcademicYearWriteGuard;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * ADR-034 §2, §5, §7 y REQ-CURSO/funcional.md §8 (paso 1.10): descubierto
 * por ModuleServiceProviderDiscovery, sin registro a mano. Declara el
 * descriptor del módulo (esencial, `depends_on []`: OPEN-CURSO-01) y el
 * catálogo de permisos de `permisos.md §2`, que `platform:sync-registry`
 * materializa.
 *
 * Sin `loadMigrationsFrom`: la única migración de 1.10 (la función del
 * bloqueo de escritura) y la de `academic_years` viven en
 * `apps/api/database/migrations/` (esquema del núcleo, `datos.md §1.4`).
 */
class CursoServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        // RN-CURSO-24: memoizado por petición, sin caché entre peticiones.
        // `scoped` lo reinicia entre peticiones y trabajos en cola.
        $this->app->scoped(EloquentAcademicYearContext::class);
        $this->app->bind(AcademicYearContext::class, static fn ($app): AcademicYearContext => $app->make(EloquentAcademicYearContext::class));

        $this->app->bind(AcademicYearDirectory::class, EloquentAcademicYearDirectory::class);
        $this->app->bind(AcademicYearWriteGuard::class, EloquentAcademicYearWriteGuard::class);
        $this->app->bind(AcademicYearReadAccess::class, PermissionAcademicYearReadAccess::class);

        // RN-CURSO-30: vacío en 1.10; cada módulo registra la suya.
        $this->app->singleton(AcademicYearClosureRegistry::class, InMemoryAcademicYearClosureRegistry::class);
    }

    public function boot(): void
    {
        // OPEN-CURSO-03: el MISMO alias `academic_year` que tenía
        // AppServiceProvider — `audit_logs` guarda el alias, nunca el FQCN
        // (ADR-034 §3): ninguna fila de auditoría cambia de significado.
        Relation::enforceMorphMap([
            'academic_year' => AcademicYear::class,
        ]);

        $this->registerErrorTranslation();
    }

    /**
     * ADR-057 §5.5: el módulo registra el mapeo de `QueryException` con
     * `SQLSTATE CY001` (y de sus dos excepciones de dominio) a la respuesta
     * de error de la API. El núcleo no conoce el `SQLSTATE`.
     */
    private function registerErrorTranslation(): void
    {
        /** @var object $handler Larastan lo tipa como el decorador de Collision (solo consola); en HTTP es `Illuminate\Foundation\Exceptions\Handler`. */
        $handler = $this->app->make(ExceptionHandler::class);

        // En consola el manejador es el decorador de Collision, que no tiene
        // `map()` ni renderiza respuestas HTTP: no hay nada que traducir.
        if (! method_exists($handler, 'map')) {
            return;
        }

        $translator = new AcademicYearClosedTranslator;
        $map = static fn (Throwable $e): Throwable => $translator->translate($e);

        foreach ([
            QueryException::class,
            AcademicYearReadOnlyException::class,
            AcademicYearReadDeniedException::class,
        ] as $class) {
            $handler->map($class, $map);
        }
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'curso',
            'name_key' => 'modules.curso',
            'phase' => '1',
            'depends_on' => [],
            'essential' => true,
        ];
    }

    public function declaredPermissions(): array
    {
        $permissions = [];

        // permisos.md §2: todos de ámbito `todos`, explícito en cada entrada
        // (ADR-044 §4.1). Un curso es del centro entero; el ámbito del dato
        // lo pone el permiso del módulo dueño.
        foreach ([
            'curso_academico' => ['leer', 'crear', 'actualizar'],
            'estado_curso_academico' => ['actualizar'],
            'curso_historico' => ['leer'],
        ] as $resource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                    'resource_label_key' => "curso.permissions.resources.{$resource}",
                    'applicable_scopes' => ['todos'],
                ];
            }
        }

        return $permissions;
    }
}
