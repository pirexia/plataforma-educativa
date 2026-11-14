<?php

use App\Models\AuditLog;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-002 (1.15-b T1), datos.md §1.2, §3, §4, CA-ALUM-021 y CA-ALUM-024 (motor), CA-ALUM-022/-023 (índice único),
// CA-ALUM-033 (grupo opcional), CA-ALUM-061 (AR-13, sin acciones referenciales), CA-ALUM-018b (RLS), INV-001, INV-003,
// INV-004, INV-005, ADR-029, ADR-057: esquema real de `enrollments` con base de datos real (ADR-033 §10).

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** SQLSTATE del error que lanza la escritura (en su propia transacción), o `null` si se acepta. */
function enrollmentSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

test('datos.md §1.2 ADR-029: enrollments tiene las columnas y tipos de la especificación, RLS forzada, campos de autoría y borrado lógico', function (): void {
    $columns = DB::connection('pgsql_owner')->table('information_schema.columns')
        ->where('table_name', 'enrollments')->get()->keyBy('column_name');

    expect($columns->keys()->all())->toEqualCanonicalizing([
        'id', 'tenant_id', 'public_id', 'student_id', 'academic_year_id', 'course_id', 'group_id', 'status', 'enrolled_on',
        'withdrawn_on', 'withdrawal_reason', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
    ])
        ->and($columns['public_id']->data_type)->toBe('character')
        ->and($columns['status']->data_type)->toBe('text')
        ->and($columns['status']->column_default)->toContain('activa')
        ->and($columns['enrolled_on']->data_type)->toBe('date')
        ->and($columns['enrolled_on']->is_nullable)->toBe('NO')
        ->and($columns['group_id']->is_nullable)->toBe('YES')
        ->and($columns['withdrawn_on']->is_nullable)->toBe('YES')
        ->and($columns['withdrawal_reason']->is_nullable)->toBe('YES')
        ->and($columns['deleted_at']->data_type)->toBe('timestamp with time zone');

    $rls = DB::connection('pgsql_owner')->selectOne("SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = 'enrollments'::regclass");

    expect($rls->on)->toBeTrue()->and($rls->forced)->toBeTrue();
});

test('CA-ALUM-061 ADR-057: enrollments lleva el disparador academic_year_write_guard habilitado y ninguna clave foránea usa CASCADE, SET NULL ni SET DEFAULT', function (): void {
    $trigger = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT count(*) AS n FROM pg_trigger t
        JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
        WHERE t.tgrelid = 'enrollments'::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
        SQL);
    $referential = DB::connection('pgsql_owner')->select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'enrollments'::regclass AND contype = 'f' AND (confdeltype <> 'a' OR confupdtype <> 'a')"
    );
    $matchTypes = DB::connection('pgsql_owner')->table('pg_constraint')
        ->whereRaw("conrelid = 'enrollments'::regclass")->where('contype', 'f')->pluck('confmatchtype', 'conname');

    expect((int) $trigger->n)->toBe(1)
        ->and($referential)->toBe([])
        ->and($matchTypes['enrollments_tenant_year_course_foreign'])->toBe('s')
        ->and($matchTypes['enrollments_tenant_course_group_foreign'])->toBe('s');
});

test('datos.md §3 AR-16: toda clave foránea compuesta de enrollments (salvo autoría) tiene un índice cuyo prefijo son sus columnas, y existen los índices de la especificación', function (): void {
    $uncovered = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid = 'enrollments'::regclass
          AND c.conname NOT LIKE '%\_created\_by\_foreign' AND c.conname NOT LIKE '%\_updated\_by\_foreign'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        SQL);
    $indexes = DB::connection('pgsql_owner')->table('pg_indexes')->where('tablename', 'enrollments')->pluck('indexdef', 'indexname');

    expect($uncovered)->toBe([])
        ->and($indexes['enrollments_tenant_year_student_active_unique'])->toContain('UNIQUE', "status = 'activa'", 'deleted_at IS NULL')
        ->and($indexes['enrollments_tenant_group_status_idx'])->toContain('(tenant_id, group_id, status)', 'deleted_at IS NULL')
        ->and($indexes['enrollments_tenant_year_course_idx'])->toContain('(tenant_id, academic_year_id, course_id)');
});

test('CA-ALUM-024 RN-ALUM-22: el CHECK del motor rechaza por SQL directo una baja incoherente y un motivo fuera del vocabulario', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $base = Enrollment::factory()->create();
        $other = ['student_id' => $base->student_id, 'academic_year_id' => $base->academic_year_id, 'course_id' => $base->course_id];

        // Baja sin fecha, baja sin motivo, fecha de baja anterior a la matrícula, activa con datos de baja, estado fuera de vocabulario.
        $invalid = [
            ['status' => 'baja', 'withdrawn_on' => null, 'withdrawal_reason' => 'traslado'],
            ['status' => 'baja', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => null],
            ['status' => 'baja', 'withdrawn_on' => '2027-09-07', 'withdrawal_reason' => 'traslado'],
            ['status' => 'activa', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => null],
            ['status' => 'activa', 'withdrawn_on' => null, 'withdrawal_reason' => 'otro'],
            ['status' => 'baja', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => 'desconocido'],
            ['status' => 'anulada', 'withdrawn_on' => null, 'withdrawal_reason' => null],
        ];

        foreach ($invalid as $i => $row) {
            expect(enrollmentSqlState(fn () => Enrollment::factory()->create($other + $row)))->toBe('23514', "caso {$i}");
        }

        // Coherentes: baja el mismo día de la matrícula y las tres razones del vocabulario.
        foreach (['traslado', 'baja_voluntaria', 'otro'] as $reason) {
            expect(enrollmentSqlState(fn () => Enrollment::factory()->withdrawn($reason, '2027-09-08')->create($other)))->toBeNull($reason);
        }
    });
});

test('CA-ALUM-022 CA-ALUM-023 RN-ALUM-21: solo una matrícula activa viva por alumno y curso académico; la baja, la anulación lógica y otro curso la liberan', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $first = Enrollment::factory()->create();
        $same = ['student_id' => $first->student_id, 'academic_year_id' => $first->academic_year_id, 'course_id' => $first->course_id];

        expect(enrollmentSqlState(fn () => Enrollment::factory()->create($same)))->toBe('23505');

        // CA-ALUM-023: con la anterior en baja por traslado, se puede matricular de nuevo y la anterior se conserva.
        $first->forceFill(['status' => 'baja', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => 'traslado'])->save();
        $second = Enrollment::factory()->create($same);

        expect($second->exists)->toBeTrue()
            ->and(Enrollment::query()->where('student_id', $first->student_id)->count())->toBe(2)
            ->and(enrollmentSqlState(fn () => Enrollment::factory()->create($same)))->toBe('23505');

        // RN-ALUM-25: anulada (borrado lógico) la vigente, vuelve a ser posible.
        $second->delete();

        expect(Enrollment::factory()->create($same)->exists)->toBeTrue();
    });
});

test('CA-ALUM-021 RN-ALUM-20: el motor rechaza un curso de etapa de otro curso académico y un grupo de otro curso de etapa; CA-ALUM-033: el grupo es opcional', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $a = Enrollment::factory()->inGroup()->create();
        $b = Enrollment::factory()->inActiveYear()->inGroup()->create();   // otro curso académico, otro curso de etapa, otro grupo

        expect($a->group_id)->not->toBeNull()
            ->and(Enrollment::factory()->create()->group_id)->toBeNull();

        // Curso de etapa de otro curso académico.
        expect(enrollmentSqlState(fn () => Enrollment::factory()->create(['academic_year_id' => $a->academic_year_id, 'course_id' => $b->course_id])))->toBe('23503');
        // Grupo de otro curso de etapa.
        expect(enrollmentSqlState(fn () => Enrollment::factory()->create(['academic_year_id' => $a->academic_year_id, 'course_id' => $a->course_id, 'group_id' => $b->group_id])))->toBe('23503');
        // Grupo propio del curso de etapa: se admite.
        expect(enrollmentSqlState(fn () => Enrollment::factory()->create(['academic_year_id' => $a->academic_year_id, 'course_id' => $a->course_id, 'group_id' => $a->group_id])))->toBeNull();
    });
});

test('CA-ALUM-018b INV-001: la FK a students es compuesta con el tenant y la RLS oculta las matrículas de otro centro', function (): void {
    $other = Tenant::factory()->create();
    $foreignStudentId = app(TenantContext::class)->runFor($other->id, fn () => Enrollment::factory()->create()->student_id);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($foreignStudentId): void {
        expect(enrollmentSqlState(fn () => Enrollment::factory()->create(['student_id' => $foreignStudentId])))->toBe('23503')
            ->and(Enrollment::query()->count())->toBe(0)
            ->and(DB::table('enrollments')->count())->toBe(0);
    });
});

test('INV-003 INV-004 ADR-035 ADR-034 §3: crear, modificar y anular una matrícula se audita con el alias alum_enrollment; el borrado es lógico', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $enrollment = Enrollment::factory()->create();
        $enrollment->forceFill(['status' => 'baja', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => 'otro'])->save();
        $enrollment->delete();

        $events = AuditLog::query()->where('auditable_type', 'alum_enrollment')->where('auditable_id', $enrollment->id)->pluck('event')->all();

        expect($events)->toContain('created')->and($events)->toContain('updated')->and($events)->toContain('deleted')
            ->and(Enrollment::withTrashed()->whereKey($enrollment->id)->exists())->toBeTrue()
            ->and(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeFalse();
    });
});
