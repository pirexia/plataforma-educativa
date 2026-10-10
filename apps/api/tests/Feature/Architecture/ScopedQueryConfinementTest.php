<?php

use App\Models\AuditLog;
use App\Modules\Core\Infrastructure\EloquentAuditQuery;
use App\Modules\Core\Infrastructure\EloquentExportRequestService;
use App\Modules\Core\Infrastructure\Jobs\GenerateAuditLogExport;
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
 * @return array<string, array{model: class-string, files: list<string>}>
 */
function scopedResourceMap(): array
{
    return [
        'auditoria' => [
            'model' => AuditLog::class,
            'files' => [
                'Modules/Core/Infrastructure/EloquentAuditQuery.php',
                'Modules/Core/Infrastructure/Jobs/GenerateAuditLogExport.php',
                'Modules/Core/Infrastructure/EloquentExportRequestService.php',
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
        ->and(array_keys(scopedResourceMap()))->toBe(['auditoria']);
});

test('AR-10 CA-056-11: ningún fichero de app/ fuera de los sancionados hace una consulta estática sobre el modelo de un recurso con ámbito', function (): void {
    $sources = appSources();

    expect(count($sources))->toBeGreaterThan(100);

    foreach (scopedResourceMap() as $resource => $entry) {
        $violations = unsanctionedModelQueries($sources, $entry['model'], $entry['files']);

        expect($violations)->toBe([], "consulta directa sobre {$entry['model']} (recurso {$resource}) sin pasar por ScopedQuery: ".implode('; ', $violations));
    }
});

test('AR-10 CA-056-11 CA-056-15: cada fichero sancionado existe y sigue consultando el modelo', function (): void {
    $sources = appSources();

    foreach (scopedResourceMap() as $entry) {
        foreach ($entry['files'] as $file) {
            expect(isset($sources[$file]))->toBeTrue("{$file}: el fichero sancionado ya no existe, retirarlo del mapa");

            $queries = array_intersect(PhpScanner::staticCallsOn($sources[$file], $entry['model']), SCOPED_MODEL_QUERY_METHODS);

            expect($queries)->not->toBe([], "{$file} ya no consulta {$entry['model']}: retirarlo del mapa de AR-10");
        }
    }
});

foreach ([EloquentAuditQuery::class, GenerateAuditLogExport::class, EloquentExportRequestService::class] as $sanctionedClass) {
    arch("AR-10 CA-056-11: el fichero sancionado {$sanctionedClass} usa ScopedQuery")
        ->expect($sanctionedClass)
        ->toUse(ScopedQuery::class);
}

test('AR-10 CA-056-11: las clases sancionadas de los arch() coinciden con los ficheros del mapa', function (): void {
    $fromClasses = array_map(
        static fn (string $c): string => str_replace('\\', '/', substr($c, strlen('App\\'))).'.php',
        [EloquentAuditQuery::class, GenerateAuditLogExport::class, EloquentExportRequestService::class],
    );

    expect($fromClasses)->toBe(scopedResourceMap()['auditoria']['files']);
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
