<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Support\Facades\DB;

// REQ-ACAD-002 (1.12-a), issue #446 (db-reviewer c), docs/modulos/REQ-ACAD/datos.md §3.2: la migración
// `2026_10_20_100100_drop_redundant_year_prefix_indexes` retira el índice `(tenant_id, academic_year_id)` de
// `tenantForeignId()` solo donde otro índice no parcial ya empieza por esas dos columnas. Con base de datos
// real (ADR-033 §10). INV-015.

const REDUNDANT_YEAR_INDEX_TABLES = ['teaching_assignments', 'group_tutors', 'timetable_frames', 'time_slots', 'timetable_sessions'];

/** Índices válidos y no parciales de la tabla cuyas dos primeras columnas son (tenant_id, academic_year_id). */
function yearPrefixIndexes(string $table): array
{
    return array_map(
        fn (object $row): string => $row->relname,
        DB::connection('pgsql_owner')->select(
            <<<'SQL'
            SELECT c.relname
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_attribute a1 ON a1.attrelid = i.indrelid AND a1.attnum = i.indkey[0]
            JOIN pg_attribute a2 ON a2.attrelid = i.indrelid AND a2.attnum = i.indkey[1]
            WHERE i.indrelid = ?::regclass AND i.indisvalid AND i.indpred IS NULL
              AND a1.attname = 'tenant_id' AND a2.attname = 'academic_year_id'
            ORDER BY c.relname
            SQL,
            [$table],
        ),
    );
}

function indexExists(string $name): bool
{
    return DB::connection('pgsql_owner')->selectOne('SELECT 1 AS ok FROM pg_class WHERE relkind = \'i\' AND relname = ?', [$name]) !== null;
}

it('CA-ACAD-038 (#446-c): las cinco tablas pierden el índice del ayudante y siguen cubiertas por prefijo', function (): void {
    foreach (REDUNDANT_YEAR_INDEX_TABLES as $table) {
        $helper = TenantMigration::indexName($table, 'academic_year_id');
        $prefixed = yearPrefixIndexes($table);

        expect(indexExists($helper))->toBeFalse("{$table}: el índice redundante sigue ahí")
            // Debe quedar al menos un índice ancho que sirva la FK `(tenant_id, academic_year_id)` y el listado por curso.
            ->and($prefixed)->not->toBeEmpty("{$table}: ya no hay ningún índice que empiece por (tenant_id, academic_year_id)");
    }
});

it('CA-ACAD-038 (#446-c): facility_bookings conserva su índice porque ningún otro empieza por (tenant_id, academic_year_id)', function (): void {
    expect(yearPrefixIndexes('facility_bookings'))->toBe([TenantMigration::indexName('facility_bookings', 'academic_year_id')]);
});

it('CA-ACAD-038 (#446-c): down() recrea los índices y up() los vuelve a retirar', function (): void {
    $migration = require base_path('app/Modules/Acad/Database/migrations/2026_10_20_100100_drop_redundant_year_prefix_indexes.php');

    try {
        $migration->down();

        foreach (REDUNDANT_YEAR_INDEX_TABLES as $table) {
            expect(indexExists(TenantMigration::indexName($table, 'academic_year_id')))->toBeTrue();
        }

        // Idempotente: un segundo down() no falla.
        $migration->down();
    } finally {
        $migration->up();
    }

    foreach (REDUNDANT_YEAR_INDEX_TABLES as $table) {
        expect(indexExists(TenantMigration::indexName($table, 'academic_year_id')))->toBeFalse();
    }
});
