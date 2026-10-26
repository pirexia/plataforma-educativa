<?php

use App\Models\AuditLog;
use App\Modules\Acad\Application\FacilityBookingService;
use App\Modules\Acad\Domain\Models\FacilityBooking;
use App\Modules\Core\Infrastructure\EloquentAuditQuery;
use App\Modules\Core\Infrastructure\EloquentExportRequestService;
use App\Modules\Core\Infrastructure\Jobs\GenerateAuditLogExport;
use App\Modules\FamUnit\Application\FamilyUnitService;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Application\GuardianService;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Support\Authorization\ScopedQuery;
use Tests\Support\ArchitectureModules;
use Tests\Support\PhpScanner;

pest()->group('arch');

// ADR-056 AR-10, CA-056-11, ADR-044 §8 (3), ADR-044 §4.2, INV-002, INV-015: la
// consulta de un recurso con ámbito restringido pasa por `ScopedQuery`.
// Todo permiso con `applicable_scopes` distinto de `['todos']` tiene su
// recurso en el mapa cerrado de abajo (recurso → modelo y ficheros
// sancionados), y ningún otro fichero de `app/` hace una llamada ESTÁTICA DE
// CONSULTA sobre ese modelo.
//
// Honestidad de la regla (ADR-056 §3.3): el escáner ve `AuditLog::query()`,
// no un acceso por relación (`$user->auditLogs()`) ni `DB::table(...)`: son
// falsos negativos, no positivos. La regla atrapa el error común y no da
// una garantía que no tiene. La tercera pieza —criterio de aceptación de
// acceso denegado en listado Y en detalle por recurso— sigue siendo
// obligatoria en cada especificación (ADR-044 §4.2).
//
// «Llamada estática de consulta» = una de esta lista cerrada. Quedan fuera a
// propósito `create`, `insert`, `upsert`, etc. (escrituras: `AuditRecorder`
// escribe con `AuditLog::create`) y `::class` (morph map).
const SCOPED_MODEL_QUERY_METHODS = [
    'query', 'on', 'onWriteConnection',
    'where', 'whereIn', 'whereKey', 'whereNull', 'whereNotNull', 'whereHas', 'whereDate', 'has',
    'find', 'findOrFail', 'findMany', 'findOr', 'all', 'first', 'firstOrFail', 'firstWhere', 'sole',
    'with', 'withTrashed', 'onlyTrashed', 'withoutGlobalScope', 'withoutGlobalScopes',
    'select', 'selectRaw', 'get', 'cursor', 'lazy', 'chunk', 'paginate', 'simplePaginate',
    'count', 'exists', 'doesntExist', 'pluck', 'value', 'max', 'min', 'sum', 'avg',
    'latest', 'oldest', 'orderBy',
];

/**
 * Mapa CERRADO recurso → modelo y ficheros sancionados (relativos a `app/`).
 * Solo puede reducirse; ampliarlo (un recurso o un fichero) exige
 * especificación aprobada por el usuario (OPEN-056-02), y un recurso nuevo
 * con ámbito restringido tiene que entrar aquí con su especificación.
 *
 * `models`: los modelos del recurso (uno solo salvo `familia`, que cubre tres, `permisos.md §3.1`). `files`: los
 * ficheros sancionados para consultar CUALQUIERA de ellos.
 *
 * @return array<string, array{models: list<class-string>, files: list<string>}>
 */
function scopedResourceMap(): array
{
    return [
        'auditoria' => [
            'models' => [AuditLog::class],
            'files' => [
                'Modules/Core/Infrastructure/EloquentAuditQuery.php',
                'Modules/Core/Infrastructure/Jobs/GenerateAuditLogExport.php',
                'Modules/Core/Infrastructure/EloquentExportRequestService.php',
            ],
        ],
        // 1.12-b (OPEN-ACAD-42, aprobada la ampliación del mapa): `reservas.eliminar` admite
        // `propios`. Toda consulta de `FacilityBooking` vive en un solo servicio.
        'reservas' => [
            'models' => [FacilityBooking::class],
            'files' => [
                'Modules/Acad/Application/FacilityBookingService.php',
            ],
        ],
        // 1.14-a (REQ-FAM-UNIT/permisos.md §3.1, aprobada la ampliación del mapa con la especificación): `familia.leer`
        // admite `unidad_familiar`. Tres modelos, un servicio sancionado por modelo (los tres del recurso, que se
        // piden entre sí unidad, tutores y alumnado dentro de la misma regla de dependencias de `Application`).
        'familia' => [
            'models' => [FamilyUnit::class, Guardian::class, FamilyUnitStudent::class],
            'files' => [
                'Modules/FamUnit/Application/FamilyUnitService.php',
                'Modules/FamUnit/Application/GuardianService.php',
                'Modules/FamUnit/Application/FamilyUnitStudentService.php',
            ],
        ],
        // `vinculo_tutor.leer` admite `unidad_familiar`: toda consulta de `GuardianStudentLink` vive en un solo servicio.
        'vinculo_tutor' => [
            'models' => [GuardianStudentLink::class],
            'files' => [
                'Modules/FamUnit/Application/GuardianLinkService.php',
            ],
        ],
    ];
}

/**
 * @param  array<string, string>  $sources  ruta relativa => código
 * @param  list<string>  $sanctioned
 * @return list<string> ficheros no sancionados que consultan el modelo
 */
function unsanctionedModelQueries(array $sources, string $model, array $sanctioned): array
{
    $found = [];

    foreach ($sources as $path => $source) {
        if (in_array($path, $sanctioned, true)) {
            continue;
        }

        $queries = array_intersect(PhpScanner::staticCallsOn($source, $model), SCOPED_MODEL_QUERY_METHODS);

        if ($queries !== []) {
            $found[] = $path.' ('.implode(', ', array_unique($queries)).')';
        }
    }

    return $found;
}

/**
 * @return array<string, string> ruta relativa a `app/` => código
 */
function appSources(): array
{
    $appPath = app_path();
    $sources = [];

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $sources[substr($file, strlen($appPath) + 1)] = file_get_contents($file);
    }

    return $sources;
}

test('AR-10 CA-056-11: todo permiso con ámbito distinto de todos tiene su recurso en el mapa cerrado', function (): void {
    $map = scopedResourceMap();
    $permissions = ArchitectureModules::declaredPermissions();

    expect(count($permissions))->toBeGreaterThan(0);

    $missing = [];

    foreach ($permissions as $permission) {
        if (($permission['applicable_scopes'] ?? ['todos']) !== ['todos'] && ! isset($map[$permission['resource']])) {
            $missing[$permission['resource']] = $permission['code'];
        }
    }

    expect($missing)->toBe([], 'recursos con ámbito restringido sin entrada en el mapa de AR-10 (ADR-044 §8 (3), ScopedQuery): '.implode(', ', array_keys($missing)));
});

test('AR-10 CA-056-11 CA-056-15: cada recurso del mapa sigue teniendo un permiso con ámbito restringido', function (): void {
    $scoped = [];

    foreach (ArchitectureModules::declaredPermissions() as $permission) {
        if (($permission['applicable_scopes'] ?? ['todos']) !== ['todos']) {
            $scoped[$permission['resource']] = true;
        }
    }

    $stale = array_values(array_diff(array_keys(scopedResourceMap()), array_keys($scoped)));

    expect($stale)->toBe([], 'entradas del mapa de AR-10 que ya no hacen falta, retirarlas: '.implode(', ', $stale))
        ->and(array_keys(scopedResourceMap()))->toBe(['auditoria', 'reservas', 'familia', 'vinculo_tutor']);
});

test('AR-10 CA-056-11: ningún fichero de app/ fuera de los sancionados hace una consulta estática sobre el modelo de un recurso con ámbito', function (): void {
    $sources = appSources();

    expect(count($sources))->toBeGreaterThan(100);

    foreach (scopedResourceMap() as $resource => $entry) {
        foreach ($entry['models'] as $model) {
            $violations = unsanctionedModelQueries($sources, $model, $entry['files']);

            expect($violations)->toBe([], "consulta directa sobre {$model} (recurso {$resource}) sin pasar por ScopedQuery: ".implode('; ', $violations));
        }
    }
});

test('AR-10 CA-056-11 CA-056-15: cada fichero sancionado existe y sigue consultando el modelo', function (): void {
    $sources = appSources();

    foreach (scopedResourceMap() as $entry) {
        foreach ($entry['files'] as $file) {
            expect(isset($sources[$file]))->toBeTrue("{$file}: el fichero sancionado ya no existe, retirarlo del mapa");

            // Basta con que consulte alguno de los modelos del recurso.
            $queries = [];

            foreach ($entry['models'] as $model) {
                $queries = [...$queries, ...array_intersect(PhpScanner::staticCallsOn($sources[$file], $model), SCOPED_MODEL_QUERY_METHODS)];
            }

            expect($queries)->not->toBe([], "{$file} ya no consulta ningún modelo de su recurso: retirarlo del mapa de AR-10");
        }
    }
});

foreach ([EloquentAuditQuery::class, GenerateAuditLogExport::class, EloquentExportRequestService::class] as $sanctionedClass) {
    arch("AR-10 CA-056-11: el fichero sancionado {$sanctionedClass} usa ScopedQuery")
        ->expect($sanctionedClass)
        ->toUse(ScopedQuery::class);
}

arch('AR-10 CA-056-11 CA-ACAD-139: el fichero sancionado de reservas usa ScopedQuery')
    ->expect(FacilityBookingService::class)
    ->toUse(ScopedQuery::class);

foreach ([FamilyUnitService::class, GuardianService::class, FamilyUnitStudentService::class, GuardianLinkService::class] as $sanctionedClass) {
    arch("AR-10 CA-056-11 CA-FAM-UNIT-046: el fichero sancionado {$sanctionedClass} usa ScopedQuery")
        ->expect($sanctionedClass)
        ->toUse(ScopedQuery::class);
}

test('AR-10 CA-056-11: las clases sancionadas de los arch() coinciden con los ficheros del mapa', function (): void {
    $toPath = static fn (string $c): string => str_replace('\\', '/', substr($c, strlen('App\\'))).'.php';

    expect(array_map($toPath, [EloquentAuditQuery::class, GenerateAuditLogExport::class, EloquentExportRequestService::class]))->toBe(scopedResourceMap()['auditoria']['files'])
        ->and(array_map($toPath, [FacilityBookingService::class]))->toBe(scopedResourceMap()['reservas']['files'])
        ->and(array_map($toPath, [FamilyUnitService::class, GuardianService::class, FamilyUnitStudentService::class]))->toBe(scopedResourceMap()['familia']['files'])
        ->and(array_map($toPath, [GuardianLinkService::class]))->toBe(scopedResourceMap()['vinculo_tutor']['files']);
});

test('AR-10 CA-056-14 control negativo: la función detecta consulta no sancionada y no detecta ::class, escrituras ni comentarios', function (): void {
    $model = 'App\Models\AuditLog';
    $sources = [
        'A/Mala.php' => "<?php\nnamespace App\\A;\nuse App\\Models\\AuditLog;\nclass Mala { function f() { return AuditLog::query()->get(); } }",
        'A/MalaFqcn.php' => "<?php\nnamespace App\\A;\nclass MalaFqcn { function f() { return \\App\\Models\\AuditLog::where('a', 1); } }",
        'A/Escribe.php' => "<?php\nnamespace App\\A;\nuse App\\Models\\AuditLog;\nclass Escribe { function f() { return AuditLog::create([]); } }",
        'A/MorphMap.php' => "<?php\nnamespace App\\A;\nuse App\\Models\\AuditLog;\nclass MorphMap { function f() { return [AuditLog::class]; } }",
        'A/Comentario.php' => "<?php\nnamespace App\\A;\nuse App\\Models\\AuditLog;\n// AuditLog::query()\nclass Comentario {}",
        'A/Sancionada.php' => "<?php\nnamespace App\\A;\nuse App\\Models\\AuditLog;\nclass Sancionada { function f() { return AuditLog::query(); } }",
    ];

    expect(unsanctionedModelQueries($sources, $model, ['A/Sancionada.php']))->toBe([
        'A/Mala.php (query)',
        'A/MalaFqcn.php (where)',
    ]);
});
