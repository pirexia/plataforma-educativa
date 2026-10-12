<?php

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), INV-001, ADR-033 §4/§5, CA-ACAD-033 a nivel de
// modelo y de motor (los endpoints llegan en el lote 2). Dos centros con
// estructuras equivalentes: autenticado el contexto en el primero, ninguna
// consulta devuelve, cuenta, modifica ni borra datos del segundo, ni por el
// modelo (scope global) ni por SQL directo (RLS, segunda barrera).

beforeEach(function (): void {
    $this->a = Tenant::factory()->create();
    $this->b = Tenant::factory()->create();

    // Estructuras equivalentes: mismos códigos en los dos centros.
    $this->treeA = AcadTestHelpers::tree($this->a, CursoTestHelpers::year($this->a, '2026-2027', AcademicYearStatus::Activo));
    $this->treeB = AcadTestHelpers::tree($this->b, CursoTestHelpers::year($this->b, '2026-2027', AcademicYearStatus::Activo));
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, array{0: string, 1: class-string, 2: string}> clave del árbol => [tabla, modelo] */
function acadIsolationEntities(): array
{
    return [
        'level' => ['education_levels', EducationLevel::class],
        'course' => ['courses', Course::class],
        'group' => ['groups', Group::class],
        'subgroup' => ['subgroups', Subgroup::class],
        'subject' => ['subjects', Subject::class],
        'groupSubject' => ['group_subjects', GroupSubject::class],
    ];
}

test('CA-ACAD-033 INV-001 ADR-033 §4: por el modelo, cada entidad solo ve las filas de su tenant; la del otro centro no se encuentra por id ni por public_id', function (): void {
    app(TenantContext::class)->enter($this->a->id);

    foreach (acadIsolationEntities() as $key => [$table, $class]) {
        $foreign = $this->treeB[$key];

        expect($class::query()->count())->toBe(1, $table)
            ->and($class::query()->pluck('id')->all())->toBe([$this->treeA[$key]->id], $table)
            ->and($class::query()->find($foreign->id))->toBeNull($table)
            ->and($class::query()->where('public_id', $foreign->public_id)->first())->toBeNull($table)
            ->and($class::withTrashed()->where('public_id', $foreign->public_id)->first())->toBeNull($table);
    }
});

test('CA-ACAD-033 INV-001 ADR-033 §5: por SQL directo (sin scope de modelo), RLS devuelve solo las filas del tenant en contexto', function (): void {
    app(TenantContext::class)->enter($this->a->id);

    foreach (acadIsolationEntities() as [$table]) {
        $tenants = DB::table($table)->pluck('tenant_id')->unique()->values()->all();

        expect($tenants)->toBe([$this->a->id], $table)
            ->and(DB::table($table)->where('tenant_id', $this->b->id)->count())->toBe(0, $table);
    }
});

test('CA-ACAD-033 INV-001: modificar o borrar por el id de una fila del otro centro no afecta a ninguna fila, y la del otro centro no cambia', function (): void {
    foreach (acadIsolationEntities() as $key => [$table, $class]) {
        $foreignId = $this->treeB[$key]->id;

        app(TenantContext::class)->enter($this->a->id);

        // `query()->update()/delete()` solo en tests: en código de producción lo veta AR-15.
        $change = match ($key) {
            'group', 'subgroup' => ['name' => 'hackeado'],
            'groupSubject' => ['updated_at' => now()->addDay()],
            default => ['position' => 9],
        };
        $updated = $class::query()->whereKey($foreignId)->update($change);
        $deleted = $class::query()->whereKey($foreignId)->delete();

        expect($updated)->toBe(0, $table)->and($deleted)->toBe(0, $table);

        app(TenantContext::class)->leave();
        app(TenantContext::class)->enter($this->b->id);

        $row = DB::table($table)->where('id', $foreignId)->first();

        expect($row)->not->toBeNull($table)->and($row->deleted_at)->toBeNull($table);

        app(TenantContext::class)->leave();
    }
});

test('CA-ACAD-033 INV-001: una escritura con tenant_id ajeno la rechaza RLS (WITH CHECK)', function (): void {
    app(TenantContext::class)->enter($this->a->id);

    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('subjects')->insert([
        'tenant_id' => $this->b->id,
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $this->treeB['subject']->academic_year_id,
        'course_id' => $this->treeB['course']->id,
        'code' => 'ROBO',
        'name_translations' => json_encode(['es-ES' => 'Robo']),
        'position' => 5,
    ]));

    // 42501: nueva fila viola la política de seguridad a nivel de fila.
    expect($state)->toBe('42501');
});

test('CA-ACAD-033 INV-001 ADR-033 §6: la FK compuesta con tenant_id impide colgar una fila propia de un padre de otro centro (23503)', function (): void {
    app(TenantContext::class)->enter($this->a->id);

    // El padre ajeno ni siquiera es visible para la comprobación del propio tenant: la FK incluye tenant_id.
    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('courses')->insert([
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $this->treeA['level']->academic_year_id,
        'education_level_id' => $this->treeB['level']->id,
        'code' => 'ROBO',
        'name_translations' => json_encode(['es-ES' => 'Robo']),
        'position' => 5,
    ]));

    expect($state)->toBe('23503');
});

test('CA-ACAD-033 INV-001: con códigos equivalentes en los dos centros, la unicidad es por tenant (no hay colisión entre centros)', function (): void {
    // Los dos árboles usan los mismos códigos y se han creado sin conflicto.
    expect($this->treeA['subject']->code)->toBe($this->treeB['subject']->code)
        ->and($this->treeA['subject']->tenant_id)->not->toBe($this->treeB['subject']->tenant_id)
        ->and($this->treeA['subject']->public_id)->not->toBe($this->treeB['subject']->public_id);
});
