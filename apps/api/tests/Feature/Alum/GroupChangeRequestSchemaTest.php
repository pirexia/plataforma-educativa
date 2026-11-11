<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\GroupChangeRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-003 (1.15-c T1), datos.md §1.3, §3, §4, CA-ALUM-052 (motor: pendiente única, CHECK de decisión y de autoría
// propia al aprobar), CA-ALUM-061 (AR-13, sin acciones referenciales), CA-ALUM-018b (RLS), INV-001, INV-003, INV-004,
// INV-005, ADR-029, ADR-035, ADR-057, issue #602: esquema real de `group_change_requests` con base de datos real
// (ADR-033 §10). CA-ALUM-062 (AR-10, confinamiento de consultas): el modelo entra en el mapa de
// `ScopedQueryConfinementTest` con su servicio sancionado en T2 (`GroupChangeService`); hasta entonces no hay fichero que
// nombrar, y el test de existencia de ficheros del mapa fallaría a propósito.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** SQLSTATE del error que lanza la escritura (en su propia transacción), o `null` si se acepta. */
function groupChangeSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

test('datos.md §1.3 ADR-029: group_change_requests tiene las columnas y tipos de la especificación, RLS forzada, campos de autoría y borrado lógico', function (): void {
    $columns = DB::connection('pgsql_owner')->table('information_schema.columns')
        ->where('table_name', 'group_change_requests')->get()->keyBy('column_name');

    expect($columns->keys()->all())->toEqualCanonicalizing([
        'id', 'tenant_id', 'public_id', 'enrollment_id', 'academic_year_id', 'from_group_id', 'to_group_id', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by',
    ])
        ->and($columns['public_id']->data_type)->toBe('character')
        ->and($columns['reason']->data_type)->toBe('text')
        ->and($columns['reason']->is_nullable)->toBe('NO')
        ->and($columns['status']->column_default)->toContain('pendiente')
        ->and($columns['requested_by']->is_nullable)->toBe('NO')
        ->and($columns['decided_by']->is_nullable)->toBe('YES')
        ->and($columns['decided_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['decided_at']->is_nullable)->toBe('YES')
        ->and($columns['decision_note']->is_nullable)->toBe('YES')
        ->and($columns['deleted_at']->data_type)->toBe('timestamp with time zone');

    $rls = DB::connection('pgsql_owner')->selectOne("SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = 'group_change_requests'::regclass");

    expect($rls->on)->toBeTrue()->and($rls->forced)->toBeTrue();
});

test('CA-ALUM-061 ADR-057 #602: lleva el disparador academic_year_write_guard, ninguna FK usa acciones referenciales, todas son MATCH SIMPLE y enrollments tiene su único de destino', function (): void {
    $trigger = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT count(*) AS n FROM pg_trigger t
        JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
        WHERE t.tgrelid = 'group_change_requests'::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
        SQL);
    $referential = DB::connection('pgsql_owner')->select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'group_change_requests'::regclass AND contype = 'f' AND (confdeltype <> 'a' OR confupdtype <> 'a')"
    );
    $foreign = DB::connection('pgsql_owner')->table('pg_constraint')
        ->whereRaw("conrelid = 'group_change_requests'::regclass")->where('contype', 'f')->pluck('confmatchtype', 'conname');
    $enrollmentsUnique = DB::connection('pgsql_owner')->selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'enrollments'::regclass AND conname = 'enrollments_tenant_year_id_unique'"
    );

    expect((int) $trigger->n)->toBe(1)
        ->and($referential)->toBe([])
        ->and($foreign->all())->each->toBe('s')
        ->and($foreign->keys()->all())->toContain(
            'group_change_requests_tenant_year_enrollment_foreign',
            'group_change_requests_tenant_year_from_group_foreign',
            'group_change_requests_tenant_year_to_group_foreign',
            'group_change_requests_decided_by_fkey',
        )
        ->and($enrollmentsUnique->def)->toBe('UNIQUE (tenant_id, academic_year_id, id)');
});

test('datos.md §3 AR-16: toda clave foránea de group_change_requests (salvo autoría) tiene un índice cuyo prefijo son sus columnas, y existen los índices de la especificación', function (): void {
    $uncovered = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid = 'group_change_requests'::regclass
          AND c.conname NOT LIKE '%\_created\_by\_foreign' AND c.conname NOT LIKE '%\_updated\_by\_foreign'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        SQL);
    $indexes = DB::connection('pgsql_owner')->table('pg_indexes')->where('tablename', 'group_change_requests')->pluck('indexdef', 'indexname');

    expect($uncovered)->toBe([])
        ->and($indexes['group_change_requests_tenant_enrollment_pending_unique'])->toContain('UNIQUE', '(tenant_id, enrollment_id)', "status = 'pendiente'", 'deleted_at IS NULL')
        ->and($indexes['group_change_requests_tenant_year_status_created_idx'])->toContain('(tenant_id, academic_year_id, status, created_at DESC, id DESC)')
        ->and($indexes['group_change_requests_tenant_year_enrollment_idx'])->toContain('(tenant_id, academic_year_id, enrollment_id)');
});

test('CA-ALUM-052 RN-ALUM-40 RN-ALUM-44: los CHECK del motor rechazan por SQL directo estado, longitudes, grupos iguales, decisión incoherente y aprobación por el propio solicitante', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $base = GroupChangeRequest::factory()->create();
        $keep = ['enrollment_id' => $base->enrollment_id, 'from_group_id' => $base->from_group_id, 'to_group_id' => $base->to_group_id];
        $decider = User::factory()->create();
        $requester = User::factory()->create();
        $decided = ['decided_by' => $decider->id, 'decided_at' => now()];

        $invalid = [
            'estado fuera de vocabulario' => ['status' => 'anulada'] + $decided,
            'grupo origen igual al destino' => ['to_group_id' => $base->from_group_id, 'status' => 'rechazada'] + $decided,
            'motivo vacío' => ['reason' => '', 'status' => 'rechazada'] + $decided,
            'motivo de 501 caracteres' => ['reason' => str_repeat('a', 501), 'status' => 'rechazada'] + $decided,
            'nota de 501 caracteres' => ['decision_note' => str_repeat('a', 501), 'status' => 'rechazada'] + $decided,
            'pendiente con autor de decisión' => ['status' => 'pendiente', 'decided_by' => $decider->id],
            'pendiente con instante de decisión' => ['status' => 'pendiente', 'decided_at' => now()],
            'rechazada sin autor' => ['status' => 'rechazada', 'decided_by' => null, 'decided_at' => now()],
            'cancelada sin instante' => ['status' => 'cancelada', 'decided_by' => $decider->id, 'decided_at' => null],
            'aprobada por el propio solicitante' => ['status' => 'aprobada', 'requested_by' => $requester->id, 'decided_by' => $requester->id, 'decided_at' => now()],
        ];

        foreach ($invalid as $case => $row) {
            expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($row + $keep)))->toBe('23514', $case);
        }

        // Coherentes: motivo y nota en el límite, y rechazar o cancelar la propia solicitud se admite (RN-ALUM-44).
        $valid = [
            'motivo y nota de 500' => ['reason' => str_repeat('a', 500), 'decision_note' => str_repeat('b', 500), 'status' => 'rechazada'] + $decided,
            'aprobada por otro' => ['status' => 'aprobada', 'requested_by' => $requester->id] + $decided,
            'rechazada por el solicitante' => ['status' => 'rechazada', 'requested_by' => $requester->id, 'decided_by' => $requester->id, 'decided_at' => now()],
            'cancelada por el solicitante' => ['status' => 'cancelada', 'requested_by' => $requester->id, 'decided_by' => $requester->id, 'decided_at' => now()],
        ];

        foreach ($valid as $case => $row) {
            expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($row + $keep)))->toBeNull($case);
        }
    });
});

test('CA-ALUM-052 RN-ALUM-41: solo una solicitud pendiente viva por matrícula; resolverla o anularla lógicamente libera la plaza', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $first = GroupChangeRequest::factory()->create();
        $same = ['enrollment_id' => $first->enrollment_id, 'from_group_id' => $first->from_group_id, 'to_group_id' => $first->to_group_id];

        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($same)))->toBe('23505');

        // Resueltas no cuentan, aunque haya varias de la misma matrícula.
        GroupChangeRequest::factory()->decided('rechazada')->create($same);
        GroupChangeRequest::factory()->decided('cancelada')->create($same);

        $first->forceFill(['status' => 'cancelada', 'decided_by' => User::factory()->create()->id, 'decided_at' => now()])->save();
        $second = GroupChangeRequest::factory()->create($same);

        expect($second->exists)->toBeTrue()
            ->and(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($same)))->toBe('23505');

        $second->delete();

        expect(GroupChangeRequest::factory()->create($same)->exists)->toBeTrue();
    });
});

test('CA-ALUM-052 datos.md §1.3: el motor rechaza un curso académico distinto del de la matrícula, un grupo de otro curso académico y un decisor de otro centro', function (): void {
    $other = Tenant::factory()->create();
    $foreignUserId = app(TenantContext::class)->runFor($other->id, fn () => User::factory()->create()->id);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($foreignUserId): void {
        $a = Enrollment::factory()->inGroup()->create();
        $b = Enrollment::factory()->inActiveYear()->inGroup()->create();   // otro curso académico y otros grupos
        $base = ['enrollment_id' => $a->id, 'academic_year_id' => $a->academic_year_id, 'from_group_id' => $a->group_id];

        // Curso académico que diverge del de la matrícula.
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create(['academic_year_id' => $b->academic_year_id] + $base)))->toBe('23503');
        // Grupo destino y grupo origen de otro curso académico.
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create(['to_group_id' => $b->group_id] + $base)))->toBe('23503');
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create(['from_group_id' => $b->group_id] + $base)))->toBe('23503');
        // Decisor de otro centro (la FK compuesta lleva el tenant).
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->decided()->create(['decided_by' => $foreignUserId] + $base)))->toBe('23503');
        // Coherente: la matrícula, su curso académico y un grupo del mismo curso académico.
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($base)))->toBeNull();
    });
});

test('CA-ALUM-018b INV-001: la FK a la matrícula es compuesta con el tenant y la RLS oculta las solicitudes de otro centro', function (): void {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->runFor($other->id, function (): array {
        $request = GroupChangeRequest::factory()->create();

        return $request->only(['enrollment_id', 'academic_year_id', 'from_group_id', 'to_group_id']);
    });

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($foreign): void {
        expect(groupChangeSqlState(fn () => GroupChangeRequest::factory()->create($foreign)))->toBe('23503')
            ->and(GroupChangeRequest::query()->count())->toBe(0)
            ->and(DB::table('group_change_requests')->count())->toBe(0);
    });
});

test('INV-003 INV-004 ADR-035 ADR-034 §3: crear, resolver y anular una solicitud se audita con el alias alum_group_change_request sin registrar motivo ni nota; el borrado es lógico', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $request = GroupChangeRequest::factory()->create(['reason' => 'Motivo ficticio reservado']);
        $request->forceFill([
            'status' => 'rechazada', 'decided_by' => User::factory()->create()->id, 'decided_at' => now(), 'decision_note' => 'Nota ficticia reservada',
        ])->save();
        $request->delete();

        $logs = AuditLog::query()->where('auditable_type', 'alum_group_change_request')->where('auditable_id', $request->id)->get();
        $payload = $logs->map(fn (AuditLog $log): string => json_encode($log->changes))->implode('|');

        expect($logs->pluck('event')->all())->toContain('created')->and($logs->pluck('event')->all())->toContain('updated')->and($logs->pluck('event')->all())->toContain('deleted')
            ->and($payload)->not->toContain('Motivo ficticio reservado')->and($payload)->not->toContain('Nota ficticia reservada')
            ->and(GroupChangeRequest::withTrashed()->whereKey($request->id)->exists())->toBeTrue()
            ->and(GroupChangeRequest::query()->whereKey($request->id)->exists())->toBeFalse();
    });
});
