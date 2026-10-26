<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT (1.14-a), docs/modulos/REQ-FAM-UNIT/datos.md §1-§3 y §5, ADR-029, ADR-033, ADR-034 §4, INV-015: esquema
// real de las cuatro tablas, con base de datos real (ADR-033 §10). Los CHECK y los índices únicos de los vínculos se
// prueban junto a sus reglas en `GuardianLinksApiTest`.

const FAM_UNIT_TABLES = ['family_units', 'guardians', 'family_unit_students', 'guardian_student_links'];

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('datos.md §1 §5: las cuatro tablas existen con RLS forzada, public_id char(26), tenant_id, autoría y borrado lógico, y ninguna lleva academic_year_id (ADR-034 §4)', function (): void {
    foreach (FAM_UNIT_TABLES as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $columns = array_column(DB::connection('pgsql_owner')->select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]
        ), 'column_name');
        $publicId = DB::connection('pgsql_owner')->selectOne(
            "SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]
        );
        $policy = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);

        expect($rls->on)->toBeTrue("{$table} sin RLS")
            ->and($rls->forced)->toBeTrue("{$table} sin FORCE RLS")
            ->and((int) $policy->n)->toBe(1)
            ->and($publicId->t)->toBe('character(26)')
            ->and($columns)->toContain('tenant_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at')
            ->and($columns)->not->toContain('academic_year_id');

        // Ningún disparador de curso académico (AR-13 no las mira).
        $trigger = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgrelid = ?::regclass AND tgname = 'academic_year_write_guard'", [$table]);
        expect((int) $trigger->n)->toBe(0);
    }
});

test('datos.md §1 ADR-029: ninguna columna de las cuatro tablas es varchar, timestamp sin zona ni ENUM, y ninguna clave foránea usa ON DELETE CASCADE ni SET NULL (las bajas son lógicas)', function (): void {
    $bad = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT table_name, column_name, data_type FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = ANY (?)
          AND (data_type IN ('character varying', 'timestamp without time zone', 'USER-DEFINED'))
        SQL, ['{'.implode(',', FAM_UNIT_TABLES).'}']);

    expect($bad)->toBe([]);

    $actions = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conrelid::regclass::text AS table_name, conname FROM pg_constraint
        WHERE contype = 'f' AND conrelid::regclass::text = ANY (?)
          AND (confdeltype IN ('c', 'n', 'd') OR confupdtype IN ('c', 'n', 'd'))
        SQL, ['{'.implode(',', FAM_UNIT_TABLES).'}']);

    expect($actions)->toBe([]);
});

test('datos.md §1.1 §1.2 §1.3 RN-FAM-UNIT-01 RN-FAM-UNIT-11 RN-FAM-UNIT-20: los índices únicos son parciales sobre las filas vivas, así que un código, una faceta de tutor o una pertenencia borrados se pueden volver a usar', function (): void {
    [$tenant, $admin] = Fam::setup('fs-unique');
    $unit = Fam::unitId($tenant, $admin, 'REUSA');
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tut', $unit);
    $student = Fam::person($tenant);
    $membership = Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated()->json('public_id');

    $personId = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardians')->where('public_id', $guardian)->value('person_id'));
    $unitId = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('family_units')->where('public_id', $unit)->value('id'));

    $insert = function (string $table, array $values) use ($tenant): ?string {
        try {
            app(TenantContext::class)->runFor($tenant->id, fn () => DB::transaction(fn () => DB::table($table)->insert($values + ['public_id' => (string) Str::ulid()])));
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? null;
        }

        return null;
    };

    // Vivos: 23505.
    expect($insert('family_units', ['code' => 'REUSA']))->toBe('23505')
        ->and($insert('guardians', ['person_id' => $personId, 'family_unit_id' => $unitId]))->toBe('23505')
        ->and($insert('family_unit_students', ['family_unit_id' => $unitId, 'student_person_id' => $student->id]))->toBe('23505');

    // El CHECK de longitud del código (datos.md §1.1).
    expect($insert('family_units', ['code' => '']))->toBe('23514')
        ->and($insert('family_units', ['code' => str_repeat('X', 41)]))->toBe('23514');

    // Borrados lógicamente: se pueden reutilizar.
    Fam::call($tenant, $admin, 'delete', "/family-units/{$unit}/students/{$membership}")->assertOk();
    Fam::call($tenant, $admin, 'delete', "/guardians/{$guardian}")->assertOk();

    expect($insert('guardians', ['person_id' => $personId, 'family_unit_id' => $unitId]))->toBeNull()
        ->and($insert('family_unit_students', ['family_unit_id' => $unitId, 'student_person_id' => $student->id]))->toBeNull();
});

test('datos.md §1.1: principal_guardian_id es una clave foránea compuesta a guardians (no admite un tutor de otro centro) y la unidad no se puede borrar físicamente con tutores', function (): void {
    [$tenantA, $adminA] = Fam::setup('fs-fk-a');
    [$tenantB, $adminB] = Fam::setup('fs-fk-b');
    $unitA = Fam::unitId($tenantA, $adminA);
    $unitB = Fam::unitId($tenantB, $adminB);
    [, $guardianB] = Fam::familyUser($tenantB, $adminB, 'tut', $unitB);

    $guardianBId = app(TenantContext::class)->runFor($tenantB->id, fn () => DB::table('guardians')->where('public_id', $guardianB)->value('id'));
    $state = null;

    try {
        app(TenantContext::class)->runFor($tenantA->id, fn () => DB::transaction(fn () => DB::table('family_units')->where('public_id', $unitA)->update(['principal_guardian_id' => $guardianBId])));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('23503');

    // Borrado físico de una unidad con tutores: la FK sin acción lo impide (las bajas son lógicas).
    $state = null;

    try {
        app(TenantContext::class)->runFor($tenantB->id, fn () => DB::transaction(fn () => DB::table('family_units')->where('public_id', $unitB)->delete()));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('23503');
});

test('datos.md §3: existen el índice caliente de vínculos activos por tutor y el de búsqueda por prefijo de código, y cada clave foránea compuesta (salvo la de autoría, `created_by`/`updated_by`) tiene su índice (tenant_id, columna)', function (): void {
    $indexes = array_column(DB::connection('pgsql_owner')->select(
        "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = ANY (?)", ['{'.implode(',', FAM_UNIT_TABLES).'}']
    ), 'indexname');

    expect($indexes)->toContain('guardian_student_links_tenant_guardian_active_idx', 'family_units_tenant_code_prefix_idx', 'guardian_student_links_tenant_student_emergency_unique', 'guardian_student_links_tenant_guardian_student_unique');

    // Toda FK compuesta (tenant_id, columna) tiene un índice cuyo prefijo es esas dos columnas (AR-16).
    $uncovered = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS table_name, c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid::regclass::text = ANY (?)
          AND c.conname NOT LIKE '%\_created\_by\_foreign' AND c.conname NOT LIKE '%\_updated\_by\_foreign'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        SQL, ['{'.implode(',', FAM_UNIT_TABLES).'}']);

    expect($uncovered)->toBe([]);
});

test('INV-001 ADR-033 §5: la RLS de las cuatro tablas oculta las filas de otro centro y rechaza escribir con un tenant_id ajeno', function (): void {
    [$tenantA, $adminA] = Fam::setup('fs-rls-a');
    $tenantB = Tenant::factory()->create();
    Fam::unitId($tenantA, $adminA, 'A1');

    app(TenantContext::class)->enter($tenantB->id);

    foreach (FAM_UNIT_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    $state = null;

    try {
        DB::transaction(fn () => DB::table('family_units')->insert(['tenant_id' => $tenantA->id, 'public_id' => (string) Str::ulid(), 'code' => 'HACK']));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('42501');
});
