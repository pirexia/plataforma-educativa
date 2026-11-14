<?php

use App\Support\Audit\Auditable;
use Tests\Support\PhpScanner;

pest()->group('arch');

// AR-15 (issue #380, INV-003, ADR-035, ADR-040, ADR-056 §3.2, INV-015).
// Una escritura MASIVA sobre un modelo `Auditable` (`Modelo::where(..)->update()`,
// `->delete()`, `::upsert()`…) se ejecuta como una sola sentencia SQL y NO
// dispara los eventos de modelo que alimentan el observador de auditoría: el
// cambio no deja rastro en `audit_logs` (INV-003). Lo correcto es actualizar o
// borrar POR INSTANCIA (cada modelo dispara su evento) o auditar de forma
// explícita (como en #381). Esta regla lo comprueba: ningún fichero de `app/`
// encadena una escritura masiva sobre un modelo `Auditable`, salvo la lista
// CERRADA y nominal de abajo.
//
// Honestidad de la regla (ADR-056 §3.3, no se promete más de lo que se
// comprueba): alcance = cadenas que ARRANCAN en una llamada estática sobre la
// clase del modelo (`Modelo::query()->where()->update()`). NO ve una cadena
// partida en varias sentencias (`$q = Modelo::query(); $q->delete()`) ni una
// que arranca en una relación o instancia (`$usuario->sesiones()->delete()`),
// ni DML crudo (`DB::table()`, `DB::statement()`): falsos negativos, no
// positivos. El DML crudo sobre tablas de CURSO lo cubre el disparador
// `academic_year_write_guard` (ADR-057), no esta regla; sobre el resto de
// tablas de negocio no tiene cobertura automática y queda en revisión.

/** Métodos de Builder/Model que escriben o borran en bloque, sin eventos de modelo. */
const BULK_WRITE_METHODS = [
    'update', 'delete', 'forceDelete', 'restore', 'truncate',
    'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'upsert', 'updateOrInsert',
    'increment', 'decrement', 'incrementEach', 'decrementEach',
];

/** Métodos tras los que la cadena ya trabaja sobre una INSTANCIA (eventos de modelo disparados). */
const INSTANCE_YIELDING_METHODS = [
    'find', 'findOrFail', 'findOrNew', 'findOr', 'findMany', 'first', 'firstOrFail', 'firstOr', 'firstOrCreate',
    'firstOrNew', 'firstWhere', 'sole', 'get', 'cursor', 'lazy', 'create', 'make', 'updateOrCreate', 'sharedLock',
];

/**
 * Excepciones de AR-15: lista CERRADA y nominal (ADR-056 §3.2), fichero
 * relativo a `app/` => motivo. Solo puede reducirse; añadir una entrada exige
 * especificación aprobada expresamente por el usuario (OPEN-056-02).
 *
 * @return array<string, string>
 */
function bulkWriteExceptions(): array
{
    return [
        'Modules/Auth/Infrastructure/Jobs/PurgeMfaFactors.php' => 'purga física por retención (30 días) de factores ya borrados lógicamente, y auditados al borrarse (REQ-AUTH-003, RN-AUTH-85, datos.md §C.11)',
        'Modules/Auth/Infrastructure/Jobs/PurgeMfaEnrollments.php' => 'purga física por retención de altas de factor sin confirmar y vencidas: material de credencial sin finalidad, no una traza (REQ-AUTH-003, RN-AUTH-85, datos.md §D.7)',
        'Modules/Auth/Infrastructure/Jobs/PurgeUserKnownDevices.php' => 'purga física por retención (365 días) de dispositivos conocidos caducados (REQ-AUTH-001, RN-AUTH-45); ADR-040 §4.3',
        'Modules/Auth/Infrastructure/Jobs/PurgeUserSessions.php' => 'purga física por retención de sesiones ya cerradas, que se auditaron al cerrarse; ADR-040 §4.3 (REQ-AUTH-001)',
    ];
}

/**
 * Modelos `Auditable` concretos bajo `app/`.
 *
 * @return list<class-string>
 */
function auditableModelClasses(): array
{
    $models = [];
    $appPath = app_path();

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $class = 'App\\'.str_replace('/', '\\', substr($file, strlen($appPath) + 1, -4));

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && is_subclass_of($class, Auditable::class)) {
            $models[] = $class;
        }
    }

    return $models;
}

/**
 * @return array<string, string> ruta relativa a `app/` => código
 */
function bulkWriteSources(): array
{
    $appPath = app_path();
    $sources = [];

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $sources[substr($file, strlen($appPath) + 1)] = file_get_contents($file);
    }

    return $sources;
}

/**
 * Escrituras masivas del fichero sobre los modelos dados.
 *
 * @param  list<class-string>  $models
 * @return list<string> `Modelo::método->…->masivo`
 */
function bulkWritesIn(string $source, array $models): array
{
    $found = [];

    foreach ($models as $model) {
        foreach (PhpScanner::chainsFromStaticCallOn($source, $model) as $chain) {
            foreach ($chain as $method) {
                if (in_array($method, INSTANCE_YIELDING_METHODS, true)) {
                    break;
                }

                if (in_array($method, BULK_WRITE_METHODS, true)) {
                    $found[] = substr(strrchr($model, '\\'), 1).'::'.implode('->', $chain);

                    break;
                }
            }
        }
    }

    return array_values(array_unique($found));
}

/**
 * @param  array<string, string>  $sources
 * @param  list<class-string>  $models
 * @param  list<string>  $allowed
 * @return list<string> `fichero (hallazgos)` no permitidos
 */
function unsanctionedBulkWrites(array $sources, array $models, array $allowed): array
{
    $violations = [];

    foreach ($sources as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        $found = bulkWritesIn($source, $models);

        if ($found !== []) {
            $violations[] = $path.' ('.implode('; ', $found).')';
        }
    }

    return $violations;
}

test('AR-15 #380 INV-003: ninguna escritura masiva sobre un modelo Auditable fuera de la lista cerrada', function (): void {
    $models = auditableModelClasses();

    expect(count($models))->toBeGreaterThan(15)
        ->and(count(bulkWriteSources()))->toBeGreaterThan(100);

    $violations = unsanctionedBulkWrites(bulkWriteSources(), $models, array_keys(bulkWriteExceptions()));

    expect($violations)->toBe([], "escritura masiva sobre modelo Auditable (no dispara la auditoría, INV-003; actualizar por instancia o auditar explícitamente):\n".implode("\n", $violations));
});

test('AR-15 #380 ADR-056 §3.2: cada excepción existe, sigue escribiendo en bloque sobre un modelo Auditable y la lista solo puede reducirse', function (): void {
    $sources = bulkWriteSources();
    $models = auditableModelClasses();

    foreach (bulkWriteExceptions() as $file => $motive) {
        expect($motive)->not->toBe('')
            ->and(isset($sources[$file]))->toBeTrue("{$file}: el fichero ya no existe, retirarlo de la lista de AR-15")
            ->and(bulkWritesIn($sources[$file], $models))->not->toBe([], "{$file} ya no escribe en bloque sobre un modelo Auditable: retirarlo de la lista de AR-15");
    }

    // Ratchet real: la lista solo puede reducirse (<= 4), nunca crecer.
    expect(count(bulkWriteExceptions()))->toBeLessThanOrEqual(4, 'la lista de excepciones de AR-15 solo puede reducirse: tenía 4 entradas y ahora tiene más');
});

test('AR-15 #380 CA-056-14 control negativo: detecta la escritura masiva y no la de instancia, la de un modelo no Auditable, ni comentarios, ni ::class, ni un fichero de la lista', function (): void {
    $models = ['App\\M\\Auditado'];
    $head = "<?php\nnamespace App\\A;\nuse App\\M\\Auditado;\nuse App\\M\\Otro;\n";
    $sources = [
        'A/Query.php' => $head."class Q { function f() { Auditado::query()->where('a', 1)->update(['b' => 2]); } }",
        'A/Delete.php' => $head."class D { function f() { return Auditado::where('a', 1)->whereNull('b')->forceDelete(); } }",
        'A/Upsert.php' => $head."class U { function f() { Auditado::upsert([], ['a']); } }",
        'A/Closure.php' => $head."class C { function f() { Auditado::where(function (\$q) { \$q->where('x', 1); })->delete(); } }",
        'A/Fqcn.php' => "<?php\nnamespace App\\A;\nclass F { function f() { \\App\\M\\Auditado::query()->delete(); } }",
        'A/PorInstancia.php' => $head."class I { function f() { Auditado::find(1)->delete(); Auditado::where('a', 1)->first()->update([]); Auditado::query()->get()->each(fn (\$m) => \$m->delete()); Auditado::create([]); } }",
        'A/NoAuditado.php' => $head."class N { function f() { Otro::query()->where('a', 1)->delete(); } }",
        'A/Comentario.php' => $head."// Auditado::query()->delete()\nclass K { function f() { return [Auditado::class]; } }",
        'A/Listada.php' => $head.'class L { function f() { Auditado::query()->delete(); } }',
    ];

    expect(unsanctionedBulkWrites($sources, $models, ['A/Listada.php']))->toBe([
        'A/Query.php (Auditado::query->where->update)',
        'A/Delete.php (Auditado::where->whereNull->forceDelete)',
        'A/Upsert.php (Auditado::upsert)',
        'A/Closure.php (Auditado::where->delete)',
        'A/Fqcn.php (Auditado::query->delete)',
    ]);
});
