<?php

use App\Support\I18n\Locales;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

pest()->group('arch');

// ADR-062 §5.8 AR-16 (CA-ACAD-015, INV-015, ADR-056 §3.4), sobre el ESQUEMA
// REAL (`pg_catalog`), la técnica que ADR-056 §3.1 prefiere: toda columna
// cuyo nombre termina en `_translations` es `jsonb NOT NULL` y tiene el CHECK
// de ADR-062 §5.1 (nombre canónico `{tabla}__{columna}_check` que emite
// `TenantMigration::translatableColumn()`, y definición con los cuatro
// requisitos del contenido traducible). Nadie escribe ese CHECK a mano.
//
// Límite, dicho para no prometer más de lo que comprueba (ADR-056 §3.4): la
// regla no detecta un campo que debió ser traducible y se declaró `text`;
// eso es criterio de la especificación y de `doc-reviewer`.

/**
 * Excepciones de AR-16: lista CERRADA y nominal (ADR-056 §3.2, ADR-062 §5.8).
 * **Vacía al nacer**; ampliarla exige especificación aprobada expresamente
 * por el usuario.
 *
 * @return array<string, string> `tabla.columna` => motivo
 */
function translatableColumnExceptions(): array
{
    return [];
}

/**
 * Columnas `*_translations` del esquema.
 *
 * @return list<array{tabla: string, columna: string, tipo: string, no_nula: bool}>
 */
function translationsColumns(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla, a.attname AS columna, format_type(a.atttypid, a.atttypmod) AS tipo, a.attnotnull AS no_nula
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        WHERE a.attnum > 0 AND NOT a.attisdropped AND a.attname LIKE '%\_translations' ESCAPE '\' AND c.relname LIKE ?
        ORDER BY c.relname, a.attname
        SQL, [$schema, $tablePrefix.'%']);

    return array_map(static fn (object $r): array => ['tabla' => $r->tabla, 'columna' => $r->columna, 'tipo' => $r->tipo, 'no_nula' => (bool) $r->no_nula], $rows);
}

/**
 * Columnas `*_translations` que incumplen AR-16, con el motivo.
 *
 * @return list<string>
 */
function translatableColumnViolations(string $schema, string $tablePrefix = ''): array
{
    $violations = [];

    foreach (translationsColumns($schema, $tablePrefix) as $col) {
        $label = "{$col['tabla']}.{$col['columna']}";

        if ($col['tipo'] !== 'jsonb') {
            $violations[] = "{$label}: tipo {$col['tipo']}, debe ser jsonb";
        }

        if (! $col['no_nula']) {
            $violations[] = "{$label}: admite NULL, debe ser NOT NULL";
        }

        $check = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(con.oid) AS def
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
            WHERE con.contype = 'c' AND c.relname = ? AND con.conname = ?
            SQL, [$schema, $col['tabla'], TenantMigration::translationsCheckName($col['tabla'], $col['columna'])]);

        if ($check === null) {
            $violations[] = "{$label}: sin el CHECK ".TenantMigration::translationsCheckName($col['tabla'], $col['columna']).' (usa TenantMigration::translatableColumn())';

            continue;
        }

        $required = ['jsonb_typeof', "'object'", "'{}'::jsonb", 'jsonb_path_exists', ...array_map(static fn (string $l): string => "'{$l}'", Locales::VOCABULARY)];
        $missing = array_values(array_filter($required, static fn (string $fragment): bool => ! str_contains($check->def, $fragment)));

        if ($missing !== []) {
            $violations[] = "{$label}: el CHECK no es el de ADR-062 §5.1 (falta ".implode(', ', $missing).')';
        }
    }

    return $violations;
}

test('AR-16 CA-ACAD-015 ADR-062 §5.8: toda columna *_translations del esquema real es jsonb NOT NULL con el CHECK del ayudante', function (): void {
    $exceptions = array_keys(translatableColumnExceptions());
    $violations = array_values(array_filter(
        translatableColumnViolations('public'),
        static fn (string $v): bool => ! in_array(explode(':', $v, 2)[0], $exceptions, true),
    ));

    expect($violations)->toBe([], "columnas *_translations que incumplen ADR-062 §5.8 (usa TenantMigration::translatableColumn()):\n".implode("\n", $violations));
});

test('AR-16 CA-ACAD-015: no vacuidad sobre el esquema real solo (sin sondas): las tablas de acad tienen columna *_translations', function (): void {
    $real = array_values(array_filter(
        translationsColumns('public'),
        static fn (array $c): bool => ! str_starts_with($c['tabla'], 'arch_control_'),
    ));
    $names = array_map(static fn (array $c): string => "{$c['tabla']}.{$c['columna']}", $real);

    expect($names)->toContain('education_levels.name_translations', 'courses.name_translations', 'subjects.name_translations');
});

test('AR-16 ADR-062 §5.8: la lista de excepciones está vacía y solo puede reducirse', function (): void {
    // Ampliarla exige especificación aprobada expresamente por el usuario.
    expect(translatableColumnExceptions())->toBe([]);
});

test('AR-16 CA-ACAD-015 control negativo: detecta tipo text, columna anulable y CHECK ausente o distinto; acepta la columna del ayudante', function (): void {
    // DDL transaccional de PostgreSQL: las tablas de control viven solo
    // dentro de esta transacción de `pgsql_owner` y se revierten siempre.
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_tr_';

    $owner->beginTransaction();

    try {
        $owner->statement("CREATE TABLE {$p}texto (id bigint PRIMARY KEY, name_translations text NOT NULL)");
        $owner->statement("CREATE TABLE {$p}anulable (id bigint PRIMARY KEY, name_translations jsonb)");
        $owner->statement("CREATE TABLE {$p}sin_check (id bigint PRIMARY KEY, name_translations jsonb NOT NULL)");
        $owner->statement("CREATE TABLE {$p}check_parcial (id bigint PRIMARY KEY, name_translations jsonb NOT NULL)");
        $owner->statement('ALTER TABLE '.$p.'check_parcial ADD CONSTRAINT '.TenantMigration::translationsCheckName($p.'check_parcial', 'name_translations')." CHECK (name_translations <> '{}'::jsonb)");
        $owner->statement("CREATE TABLE {$p}check_otro_nombre (id bigint PRIMARY KEY, name_translations jsonb NOT NULL, CONSTRAINT cualquiera CHECK (name_translations <> '{}'::jsonb))");

        TenantMigration::tenantTable("{$p}ayudante", function (Blueprint $table): void {
            TenantMigration::translatableColumn($table, 'name');
            TenantMigration::translatableColumn($table, 'label');
        });

        $violations = translatableColumnViolations('public', $p);
        $tables = array_map(static fn (string $v): string => explode('.', $v, 2)[0], $violations);

        expect(array_values(array_unique($tables)))->toBe([
            $p.'anulable', $p.'check_otro_nombre', $p.'check_parcial', $p.'sin_check', $p.'texto',
        ])->and(implode("\n", $violations))->toContain('tipo text', 'admite NULL', 'sin el CHECK', 'falta')
            ->and(array_map(static fn (array $c): string => $c['tabla'].'.'.$c['columna'], translationsColumns('public', $p.'ayudante')))
            ->toBe([$p.'ayudante.label_translations', $p.'ayudante.name_translations']);
    } finally {
        $owner->rollBack();
    }
});
