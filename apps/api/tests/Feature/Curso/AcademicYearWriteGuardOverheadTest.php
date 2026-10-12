<?php

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcademicYearProbe;
use Tests\Support\CursoTestHelpers;
use Tests\Support\ProbeTables;

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

/**
 * CA-ACAD-023 (REQ-ACAD, 1.11-b, issue #382, CA-057-09): la misma medición con
 * la tabla REAL `subjects` (la más simple con clave foránea de tres columnas)
 * frente a una tabla de control idéntica cuya columna de curso NO se llama
 * `academic_year_id` (sin disparador). Tres corridas, cada una mediana de
 * cinco repeticiones; 20.000 filas en una sentencia y 1.000 sentencias por
 * repetición (variables `CURSO_OVERHEAD_BULK` y `CURSO_OVERHEAD_SINGLE`).
 */
test('CA-ACAD-023 CA-057-09 #382: sobrecarga medida del disparador en la tabla real subjects, masiva y fila a fila, frente a una tabla de control sin disparador', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tablesBefore = ProbeTables::snapshot();
    $owner->statement('DROP TABLE IF EXISTS subjects_control');

    TenantMigration::tenantTable('subjects_control', function (Blueprint $table): void {
        $table->ulid('public_id')->unique();
        TenantMigration::tenantForeignId($table, 'year_ref', 'academic_years');
        $table->foreignId('course_id');
        $table->text('code');
        TenantMigration::translatableColumn($table, 'name');
        $table->integer('position');
        $table->foreign(['tenant_id', 'year_ref', 'course_id'], 'subjects_control_year_course_foreign')->references(['tenant_id', 'academic_year_id', 'id'])->on('courses');
        $table->unique(['tenant_id', 'course_id', 'id'], 'subjects_control_tenant_course_id_unique');
    });
    $owner->statement('CREATE UNIQUE INDEX subjects_control_tenant_course_code_unique ON subjects_control (tenant_id, course_id, code) WHERE deleted_at IS NULL');
    $owner->statement('ALTER TABLE subjects_control ADD CONSTRAINT subjects_control_position_check CHECK (position >= 1)');

    $year = CursoTestHelpers::year($this->tenant, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($year): void {
        $level = EducationLevel::create(['academic_year_id' => $year->id, 'code' => 'ESO', 'name_translations' => ['es-ES' => 'ESO'], 'stage' => 'eso', 'regime' => 'publico', 'position' => 1]);
        $course = Course::create(['academic_year_id' => $year->id, 'education_level_id' => $level->id, 'code' => '1ESO', 'name_translations' => ['es-ES' => '1'], 'position' => 1]);
        $this->courseId = $course->id;
    });

    $bulk = (int) (getenv('CURSO_OVERHEAD_BULK') ?: 20000);
    $single = (int) (getenv('CURSO_OVERHEAD_SINGLE') ?: 1000);
    $corridas = 3;
    $repetitions = 5;
    $names = '{"es-ES": "Asignatura"}';

    app(TenantContext::class)->enter($this->tenant->id);

    $median = static function (array $values): float {
        sort($values);

        return $values[intdiv(count($values), 2)];
    };

    $perRun = ['bulk' => [], 'single' => []];
    $serial = 0;

    for ($corrida = 0; $corrida < $corridas; $corrida++) {
        $acc = ['bulk' => ['con' => [], 'sin' => []], 'single' => ['con' => [], 'sin' => []]];

        for ($rep = 0; $rep < $repetitions; $rep++) {
            $order = ($corrida + $rep) % 2 === 0 ? ['con', 'sin'] : ['sin', 'con'];

            foreach ($order as $variant) {
                [$table, $column] = $variant === 'con' ? ['subjects', 'academic_year_id'] : ['subjects_control', 'year_ref'];
                $serial++;

                $acc['bulk'][$variant][] = timed(fn () => DB::statement(
                    "insert into {$table} (public_id, {$column}, course_id, code, name_translations, position) select lpad((?::bigint * 1000000 + g)::text, 26, '0'), ?, ?, 'b{$serial}-' || g, ?::jsonb, 1 from generate_series(1, ?) g",
                    [$serial, $year->id, $this->courseId, $names, $bulk],
                ));

                $acc['single'][$variant][] = timed(function () use ($table, $column, $year, $single, $serial, $names): void {
                    for ($n = 0; $n < $single; $n++) {
                        DB::table($table)->insert([
                            'public_id' => str_pad((string) ($serial * 1000000 + 500000 + $n), 26, '0', STR_PAD_LEFT),
                            $column => $year->id, 'course_id' => $this->courseId, 'code' => "s{$serial}-{$n}", 'name_translations' => $names, 'position' => 1,
                        ]);
                    }
                });
            }
        }

        foreach (['bulk', 'single'] as $mode) {
            $con = $median($acc[$mode]['con']);
            $sin = $median($acc[$mode]['sin']);
            $perRun[$mode][] = [$con, $sin, ($con - $sin) / $sin * 100];
        }
    }

    $lines = ["CA-ACAD-023 sobrecarga del disparador en subjects ({$corridas} corridas, mediana de {$repetitions} repeticiones)"];

    foreach (['bulk' => "masiva ({$bulk} filas por sentencia)", 'single' => "fila a fila ({$single} sentencias)"] as $mode => $label) {
        foreach ($perRun[$mode] as $i => [$con, $sin, $pct]) {
            $lines[] = sprintf('  %s, corrida %d: con disparador %.4fs, sin disparador %.4fs, sobrecarga %+.1f %%', $label, $i + 1, $con, $sin, $pct);
        }
    }

    fwrite(STDERR, "\n".implode("\n", $lines)."\n");

    $with = DB::table('subjects')->count();
    $without = DB::table('subjects_control')->count();

    // La tabla de control la crea el propietario fuera de la transacción del test: se borra
    // siempre, cerrando antes la transacción del test (un DROP esperaría a sus bloqueos).
    $leftovers = ProbeTables::dropAndReportLeftovers(['subjects_control'], $tablesBefore);

    expect($with)->toBe($without)->and($with)->toBe($corridas * $repetitions * ($bulk + $single))->and($leftovers)->toBe([]);
});
