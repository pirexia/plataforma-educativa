<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\ProbeTables;

// Revisión de seguridad de 1.11-a (B4): ProbeTables solo borra en bases de test,
// con identificador acotado y esquema calificado. INV-015.

test('ProbeTables rechaza un nombre de tabla que no es un identificador simple (nada se interpola sin comprobar)', function (): void {
    expect(fn () => ProbeTables::dropAndReportLeftovers(['x; DROP TABLE tenants'], ProbeTables::snapshot()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => ProbeTables::dropAndReportLeftovers(['public.tenants'], ProbeTables::snapshot()))
        ->toThrow(InvalidArgumentException::class);
});

test('ProbeTables opera en una base plataforma_test* y devuelve las tablas que sobran respecto a la foto', function (): void {
    $database = DB::connection('pgsql_owner')->selectOne('SELECT current_database() AS d')->d;

    expect($database)->toMatch('/^plataforma_test(_[0-9]+)?$/');

    $before = ProbeTables::snapshot();
    DB::connection('pgsql_owner')->statement('CREATE TABLE probe_tables_leftover (id int)');

    try {
        expect(ProbeTables::dropAndReportLeftovers([], $before))->toBe(['probe_tables_leftover'])
            ->and(ProbeTables::dropAndReportLeftovers(['probe_tables_leftover'], $before))->toBe([]);
    } finally {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS public.probe_tables_leftover');
    }
});
