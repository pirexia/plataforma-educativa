<?php

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcademicYearProbe;
use Tests\Support\CursoTestHelpers;

// CA-057-09, ADR-057 §5.2 y «Consecuencias» (coste por fila escrita del
// disparador), INV-015. ADR-057 manda MEDIR y anotar el número real, no darlo
// por bueno; si supera la sobrecarga que se midió para RLS en 0.8.12
// (≈ +1,24 % en una lectura, docs/historial/0.8-modelo-de-datos-nucleo.md),
// es un hallazgo que se registra como issue, no una cifra que se ajusta.
//
// La tabla de control es idéntica a la sonda (misma forma, mismas claves
// foráneas, RLS) pero su columna NO se llama `academic_year_id`, así que
// `TenantMigration` no le pone el disparador y `AR-13` no la vigila. Es la
// única forma de aislar el coste del disparador sin deshabilitarlo (un
// `ALTER TABLE … DISABLE TRIGGER` bloquearía la tabla).
//
// Este test no afirma ningún umbral: afirma que ambas vías escriben lo mismo
// y deja la medición en la salida de error estándar (`--no-output` la quita).

beforeEach(function (): void {
    AcademicYearProbe::ensureTable();

    if (! Schema::connection('pgsql_owner')->hasTable('academic_year_probes_control')) {
        TenantMigration::tenantTable('academic_year_probes_control', function (Blueprint $table): void {
            $table->text('name');
            TenantMigration::tenantForeignId($table, 'year_ref', 'academic_years');
        });
    }

    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return float segundos */
function timed(Closure $work): float
{
    $start = hrtime(true);
    $work();

    return (hrtime(true) - $start) / 1e9;
}

test('CA-057-09 ADR-057: sobrecarga medida del disparador en una inserción masiva y fila a fila, con y sin disparador', function (): void {
    $year = CursoTestHelpers::year($this->tenant, '2025-2026', AcademicYearStatus::Activo);
    $bulk = (int) (getenv('CURSO_OVERHEAD_BULK') ?: 20000);
    $single = (int) (getenv('CURSO_OVERHEAD_SINGLE') ?: 300);
    $runs = 3;

    app(TenantContext::class)->enter($this->tenant->id);

    $results = ['bulk' => ['con' => [], 'sin' => []], 'single' => ['con' => [], 'sin' => []]];

    for ($i = 0; $i < $runs; $i++) {
        // Alternando el orden para no favorecer a ninguna vía con la caché.
        $order = $i % 2 === 0 ? ['con', 'sin'] : ['sin', 'con'];

        foreach ($order as $variant) {
            [$table, $column] = $variant === 'con' ? [AcademicYearProbe::TABLE, 'academic_year_id'] : ['academic_year_probes_control', 'year_ref'];

            $results['bulk'][$variant][] = timed(fn () => DB::statement(
                "insert into {$table} (name, {$column}) select 'fila-' || g, ? from generate_series(1, ?) g",
                [$year->id, $bulk],
            ));

            $results['single'][$variant][] = timed(function () use ($table, $column, $year, $single): void {
                for ($n = 0; $n < $single; $n++) {
                    DB::table($table)->insert(['name' => "fila-{$n}", $column => $year->id]);
                }
            });
        }
    }

    $median = static function (array $values): float {
        sort($values);

        return $values[intdiv(count($values), 2)];
    };

    $lines = ["CA-057-09 sobrecarga del disparador (mediana de {$runs} corridas)"];

    foreach (['bulk' => "masiva ({$bulk} filas por sentencia)", 'single' => "fila a fila ({$single} sentencias)"] as $mode => $label) {
        $con = $median($results[$mode]['con']);
        $sin = $median($results[$mode]['sin']);
        $lines[] = sprintf('  %s: con disparador %.4fs, sin disparador %.4fs, sobrecarga %+.1f %%', $label, $con, $sin, ($con - $sin) / $sin * 100);
    }

    fwrite(STDERR, "\n".implode("\n", $lines)."\n");

    $with = DB::table(AcademicYearProbe::TABLE)->count();
    $without = DB::table('academic_year_probes_control')->count();

    expect($with)->toBe($without)->and($with)->toBe($runs * ($bulk + $single));
});
