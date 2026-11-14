<?php

use Illuminate\Support\Facades\DB;

pest()->group('arch');

// ADR-056 AR-04 (CA-056-05) y AR-05 (CA-056-06), ADR-029, INV-015. Se
// comprueban sobre el ESQUEMA REAL de la base de test migrada
// (`pg_catalog`), no sobre el texto de las migraciones: `TenantMigration`
// añade columnas que el texto no muestra y `DB::statement` escribe SQL
// crudo (precedente #51). `pg_catalog` y no `information_schema`: esta
// última solo muestra las tablas sobre las que el rol de la conexión tiene
// privilegios, y `plataforma_app` no ve las `platform_*`.
//
// Cada consulta recibe el esquema como parámetro para poder aplicarla a un
// esquema de control con violaciones deliberadas (control negativo
// permanente: demuestra que el SQL detecta lo que debe).

/**
 * ADR-029: ninguna `character varying`, ninguna `timestamp without time
 * zone` (con o sin precisión: `timestamp(0) without time zone`), ningún
 * `ENUM` de PostgreSQL, y `character(n)` solo con n = 26 (ULID). Se mira
 * `time without time zone` a propósito NO: es una hora de reloj, no una
 * marca de tiempo (ADR-056 §3.4).
 *
 * @return list<string> `tabla.columna tipo`
 */
function adr029ColumnViolations(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla, a.attname AS columna, format_type(a.atttypid, a.atttypmod) AS tipo
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        JOIN pg_type t ON t.oid = a.atttypid
        WHERE a.attnum > 0 AND NOT a.attisdropped AND c.relname LIKE ?
          AND (
                format_type(a.atttypid, a.atttypmod) LIKE 'character varying%'
             OR format_type(a.atttypid, a.atttypmod) LIKE 'timestamp%without time zone'
             OR format_type(a.atttypid, a.atttypmod) = 'bpchar'
             OR (format_type(a.atttypid, a.atttypmod) LIKE 'character(%' AND format_type(a.atttypid, a.atttypmod) <> 'character(26)')
             OR t.typtype = 'e'
          )
        ORDER BY c.relname, a.attname
        SQL, [$schema, $tablePrefix.'%']);

    return array_map(fn (object $r): string => "{$r->tabla}.{$r->columna} {$r->tipo}", $rows);
}

/**
 * @return list<string> nombres de tipos ENUM del esquema
 */
function adr029EnumTypes(string $schema, string $namePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(
        "SELECT t.typname FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE t.typtype = 'e' AND n.nspname = ? AND t.typname LIKE ? ORDER BY 1",
        [$schema, $namePrefix.'%'],
    );

    return array_map(fn (object $r): string => $r->typname, $rows);
}

/**
 * AR-05: toda columna `public_id` es `character(26)`, `NOT NULL` y tiene un
 * índice único de una sola columna.
 *
 * @return array{checked: int, violations: list<string>}
 */
function publicIdShapeViolations(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla, format_type(a.atttypid, a.atttypmod) AS tipo, a.attnotnull AS no_nulo,
               (SELECT count(*) FROM pg_index i
                 WHERE i.indrelid = c.oid AND i.indisunique AND i.indnatts = 1 AND i.indkey[0] = a.attnum) AS unicos
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        WHERE a.attname = 'public_id' AND NOT a.attisdropped AND c.relname LIKE ?
        ORDER BY c.relname
        SQL, [$schema, $tablePrefix.'%']);

    $violations = [];

    foreach ($rows as $r) {
        if ($r->tipo !== 'character(26)') {
            $violations[] = "{$r->tabla}.public_id es {$r->tipo}, no character(26)";
        }
        if (! $r->no_nulo) {
            $violations[] = "{$r->tabla}.public_id admite NULL";
        }
        if ((int) $r->unicos < 1) {
            $violations[] = "{$r->tabla}.public_id no tiene índice único de una sola columna";
        }
    }

    return ['checked' => count($rows), 'violations' => $violations];
}

test('AR-04 CA-056-05 ADR-029: el esquema public no tiene varchar, timestamp sin zona, character(n≠26) ni ENUM fuera de las excepciones del framework', function (): void {
    // Lista CERRADA y nominal (ADR-056 §3.2): esquema propio de Laravel
    // (`php artisan make:cache-table`, `queue:table`, `session:table`), no
    // tablas de negocio. Solo puede reducirse; ampliarla exige
    // especificación aprobada por el usuario (OPEN-056-02).
    $frameworkExceptions = [
        'cache.key character varying(255)' => 'tabla `cache` de Laravel',
        'cache_locks.key character varying(255)' => 'tabla `cache_locks` de Laravel',
        'cache_locks.owner character varying(255)' => 'tabla `cache_locks` de Laravel',
        'failed_jobs.connection character varying(255)' => 'tabla `failed_jobs` de Laravel',
        'failed_jobs.queue character varying(255)' => 'tabla `failed_jobs` de Laravel',
        'failed_jobs.uuid character varying(255)' => 'tabla `failed_jobs` de Laravel',
        'failed_jobs.failed_at timestamp(0) without time zone' => 'tabla `failed_jobs` de Laravel',
        'job_batches.id character varying(255)' => 'tabla `job_batches` de Laravel',
        'job_batches.name character varying(255)' => 'tabla `job_batches` de Laravel',
        'jobs.queue character varying(255)' => 'tabla `jobs` de Laravel',
        'migrations.migration character varying(255)' => 'tabla `migrations` de Laravel',
        'sessions.id character varying(255)' => 'tabla `sessions` de Laravel',
        'sessions.ip_address character varying(45)' => 'tabla `sessions` de Laravel',
    ];

    $found = adr029ColumnViolations('public');

    // Cada excepción se verifica viva: si la tabla del framework ya cumple
    // ADR-029, la entrada sobra y el test lo exige.
    $stale = array_values(array_diff(array_keys($frameworkExceptions), $found));
    expect($stale)->toBe([], 'excepciones de AR-04 que ya no hacen falta, retirarlas de la lista: '.implode(', ', $stale));

    $outside = array_values(array_diff($found, array_keys($frameworkExceptions)));
    expect($outside)->toBe([], 'columnas que incumplen ADR-029 (varchar / timestamp sin zona / character(n≠26) / ENUM): '.implode(', ', $outside));

    expect(adr029EnumTypes('public'))->toBe([], 'tipos ENUM de PostgreSQL en el esquema public (ADR-029)');
});

test('AR-05 CA-056-06: toda columna public_id es character(26) NOT NULL con índice único de una sola columna, sin excepciones', function (): void {
    $result = publicIdShapeViolations('public');

    expect($result['checked'])->toBeGreaterThanOrEqual(30)
        ->and($result['violations'])->toBe([], implode('; ', $result['violations']));
});

test('AR-04 AR-05 control negativo: las consultas detectan las violaciones de tablas de control', function (): void {
    // DDL transaccional de PostgreSQL: las tablas de control viven solo
    // dentro de esta transacción de `pgsql_owner` y se revierten siempre.
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_';

    $owner->beginTransaction();

    try {
        $owner->statement("CREATE TYPE {$p}color AS ENUM ('a', 'b')");
        $owner->statement("CREATE TABLE {$p}malas (
            id bigint PRIMARY KEY,
            nombre varchar(10),
            creado timestamp,
            creado_p timestamp(0),
            codigo char(2),
            sin_tipo bpchar,
            uno char,
            color {$p}color,
            hora time without time zone
        )");
        // Tres tablas con public_id: tipo text, admite NULL, sin índice único.
        $owner->statement("CREATE TABLE {$p}pid_text (id bigint PRIMARY KEY, public_id text NOT NULL UNIQUE)");
        $owner->statement("CREATE TABLE {$p}pid_null (id bigint PRIMARY KEY, public_id char(26) UNIQUE)");
        $owner->statement("CREATE TABLE {$p}pid_sin_unico (id bigint PRIMARY KEY, public_id char(26) NOT NULL)");
        $owner->statement("CREATE TABLE {$p}pid_ok (id bigint PRIMARY KEY, public_id char(26) NOT NULL UNIQUE, ulid char(26))");

        expect(adr029ColumnViolations('public', $p))->toBe([
            "{$p}malas.codigo character(2)",
            "{$p}malas.color {$p}color",
            "{$p}malas.creado timestamp without time zone",
            "{$p}malas.creado_p timestamp(0) without time zone",
            "{$p}malas.nombre character varying(10)",
            "{$p}malas.sin_tipo bpchar",
            "{$p}malas.uno character(1)",
        ]);

        expect(adr029EnumTypes('public', $p))->toBe(["{$p}color"]);

        $result = publicIdShapeViolations('public', $p);
        expect($result['checked'])->toBe(4)
            ->and($result['violations'])->toBe([
                "{$p}pid_null.public_id admite NULL",
                "{$p}pid_sin_unico.public_id no tiene índice único de una sola columna",
                "{$p}pid_text.public_id es text, no character(26)",
            ]);
    } finally {
        $owner->rollBack();
    }
});
