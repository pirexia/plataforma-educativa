<?php

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), docs/modulos/REQ-PRL/datos.md §8.4 a §8.7, ADR-029, ADR-033, ADR-034 §6, ADR-068 §5.1, CA-068-07, CA-PRL-004,
// CA-PRL-031 a CA-PRL-034 (1.14b-d1, datos.md §8.9), INV-001, INV-004, INV-005, INV-015: esquema real de `student_movements` y `pickup_alerts`, con base de datos real (ADR-033 §10).

const PRL_TABLES = ['student_movements', 'pickup_alerts'];

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> */
function prlColumns(string $table): array
{
    return array_column(DB::connection('pgsql_owner')->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), 'column_name');
}

/**
 * Intenta insertar `$row` como el rol de la aplicación, dentro del centro de `$row['tenant_id']` y en un punto de guardado que
 * **siempre se deshace**. Devuelve el mensaje de error de base de datos, o `null` si la fila era admisible.
 */
function prlTryInsert(string $table, array $row): ?string
{
    return app(TenantContext::class)->runFor((int) $row['tenant_id'], function () use ($table, $row): ?string {
        $connection = DB::connection('pgsql');

        try {
            $connection->transaction(function () use ($connection, $table, $row): void {
                $connection->table($table)->insert($row);

                throw new RuntimeException('deshacer');
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        } catch (RuntimeException) {
            return null;
        }

        return null;
    });
}

/** Una salida admisible de un tercero (`basis = tercero`) de una familia de `doorFamily()`, para clonarla con `$overrides`. */
function prlMovementRow(array $f, array $overrides = []): array
{
    static $n = 0;
    $n++;
    $tenantId = $f['tenant']->id;
    $authorizationId = app(TenantContext::class)->runFor($tenantId, fn () => (int) DB::table('pickup_authorizations')->value('id'));
    $abueloId = app(TenantContext::class)->runFor($tenantId, fn () => (int) DB::table('people')->where('public_id', $f['abuelo']['person'])->value('id'));

    return $overrides + [
        'tenant_id' => $tenantId,
        'public_id' => '01JZZZZZZZZZZZZZZZZZZZ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'student_person_id' => $f['student']->id,
        'direction' => 'salida',
        'off_schedule' => false,
        'picked_up_by_person_id' => $abueloId,
        'basis' => 'tercero',
        'pickup_authorization_id' => $authorizationId,
        'verification_method' => 'foto',
        'delivered_by' => $f['conserje']->id,
        'recorded_by' => $f['conserje']->id,
        'occurred_at' => now(),
    ];
}

function prlAlertRow(array $f, array $overrides = []): array
{
    static $n = 0;
    $n++;

    return $overrides + [
        'tenant_id' => $f['tenant']->id,
        'public_id' => '01JZZZZZZZZZZZZZZZZZZY'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'student_person_id' => $f['student']->id,
        'kind' => 'persona_no_autorizada',
        'presenter_description' => 'Persona sin identificar',
        'status' => 'abierta',
        'raised_by' => $f['conserje']->id,
        'raised_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('datos.md §8.4 §8.5 ADR-033 ADR-034 §6 INV-005: las dos tablas existen con RLS forzada, public_id char(26), tenant_id y UNIQUE (tenant_id, id); pickup_alerts con borrado lógico y autoría; student_movements, de solo anexar; ninguna con academic_year_id', function (): void {
    foreach (PRL_TABLES as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $publicId = DB::connection('pgsql_owner')->selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]);
        $unique = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexdef LIKE 'CREATE UNIQUE INDEX % (tenant_id, id)'", [$table]);
        $policy = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);

        expect($rls->on)->toBeTrue("{$table} sin RLS")
            ->and($rls->forced)->toBeTrue("{$table} sin FORCE RLS")
            ->and((int) $policy->n)->toBe(1)
            ->and((int) $unique->n)->toBe(1)
            ->and($publicId->t)->toBe('character(26)')
            ->and(prlColumns($table))->toContain('tenant_id', 'public_id', 'student_person_id', 'idempotency_key', 'idempotency_body_hash')->not->toContain('academic_year_id');
    }

    expect(prlColumns('pickup_alerts'))->toContain('created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at')
        // Solo anexar: sin borrado lógico ni marcas de modificación (`tenantTableAppendOnly()`).
        ->and(prlColumns('student_movements'))->not->toContain('deleted_at', 'updated_at', 'updated_by')
        ->and(prlColumns('student_movements'))->toContain(
            'direction', 'off_schedule', 'off_schedule_reason', 'notes', 'picked_up_by_person_id', 'basis', 'guardian_student_link_id', 'pickup_authorization_id',
            'verification_method', 'delivered_by', 'recorded_by', 'corrects_movement_id', 'correction_reason', 'occurred_at',
        )
        ->and(prlColumns('pickup_alerts'))->toContain(
            'kind', 'presenter_description', 'status', 'raised_by', 'raised_at', 'principal_contacted_at', 'resolution', 'resolution_notes', 'resolved_by', 'resolved_at',
        );
});

test('CA-068-07 RN-PRL-01: ninguna tabla de prl guarda personas autorizadas, copias de la lista ni el estado de una autorización — solo referencias de evidencia', function (): void {
    $forbidden = ['authorized_persons', 'allowed_persons', 'pickup_list', 'authorization_status', 'is_authorized', 'authorized', 'valid_from', 'valid_until', 'relationship', 'terms_version_id', 'withdrawn_at', 'has_photo', 'object_key', 'document_number', 'document_type', 'contact_phone', 'given_name', 'family_name_1'];

    foreach (PRL_TABLES as $table) {
        expect(array_values(array_intersect(prlColumns($table), $forbidden)))->toBe([], "{$table} copia datos de fam_unit/Core");
    }

    // Las únicas columnas hacia fam_unit son las dos claves de evidencia de la salida, compuestas por tenant (ADR-033 §6).
    $references = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conname, confrelid::regclass::text AS referenced, confdeltype AS on_delete
        FROM pg_constraint
        WHERE contype = 'f' AND conrelid IN ('student_movements'::regclass, 'pickup_alerts'::regclass)
          AND confrelid::regclass::text IN ('guardian_student_links', 'pickup_authorizations', 'guardians', 'pickup_photos', 'terms_versions')
        SQL);

    expect(array_column($references, 'referenced'))->toEqualCanonicalizing(['guardian_student_links', 'pickup_authorizations'])
        ->and(array_unique(array_column($references, 'on_delete')))->toBe(['a']);
});

test('datos.md §8.4 ADR-029 AR-04: ninguna columna es varchar, timestamp sin zona ni ENUM, los instantes son timestamptz y ninguna FK tiene acción en cascada', function (): void {
    $type = fn (string $table, string $column): string => DB::connection('pgsql_owner')->selectOne('SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = ?', [$table, $column])->t;

    expect($type('student_movements', 'occurred_at'))->toContain('with time zone')
        ->and($type('pickup_alerts', 'raised_at'))->toContain('with time zone')
        ->and($type('pickup_alerts', 'principal_contacted_at'))->toContain('with time zone')
        ->and($type('pickup_alerts', 'resolved_at'))->toContain('with time zone')
        ->and($type('student_movements', 'off_schedule'))->toBe('boolean')
        ->and($type('student_movements', 'notes'))->toBe('text');

    $bad = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla, a.attname AS columna FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
        WHERE c.relname = ANY (?) AND a.attnum > 0 AND NOT a.attisdropped
          AND (format_type(a.atttypid, a.atttypmod) LIKE 'character varying%' OR format_type(a.atttypid, a.atttypmod) LIKE 'timestamp%without time zone' OR t.typtype = 'e')
        SQL, ['{'.implode(',', PRL_TABLES).'}']);
    $cascading = DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid::regclass::text = ANY (?) AND confdeltype IN ('c', 'n', 'd')", ['{'.implode(',', PRL_TABLES).'}']);

    expect($bad)->toBe([])->and($cascading)->toBe([]);
});

test('datos.md §8.6 AR-16: existen los índices del historial, de las salidas del día, de las alertas abiertas y los únicos de idempotencia, y cada FK compuesta (salvo la de autoría) tiene un índice completo (no parcial) con prefijo (tenant_id, columna)', function (): void {
    $indexes = collect(DB::connection('pgsql_owner')->select(
        "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ANY (?)", ['{'.implode(',', PRL_TABLES).'}']
    ))->pluck('indexdef', 'indexname');

    expect($indexes['student_movements_student_history_idx'])->toContain('(tenant_id, student_person_id, occurred_at DESC, id DESC)')
        ->and($indexes['student_movements_day_idx'])->toContain('(tenant_id, occurred_at DESC, id DESC)')
        ->and($indexes['student_movements_idempotency_unique'])->toContain('UNIQUE')->toContain('(tenant_id, recorded_by, idempotency_key)')->toContain('idempotency_key IS NOT NULL')
        ->and($indexes['pickup_alerts_idempotency_unique'])->toContain('UNIQUE')->toContain('(tenant_id, raised_by, idempotency_key)')->toContain('idempotency_key IS NOT NULL')
        ->and($indexes['pickup_alerts_status_raised_idx'])->toContain('(tenant_id, status, raised_at DESC)')->toContain('deleted_at IS NULL');

    $uncovered = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS table_name, c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid::regclass::text = ANY (?)
          AND c.conname NOT LIKE '%\_created\_by\_foreign' AND c.conname NOT LIKE '%\_updated\_by\_foreign'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid
                AND i.indpred IS NULL
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        SQL, ['{'.implode(',', PRL_TABLES).'}']);

    // `db-reviewer`: el índice de la FK tiene que ser completo (`indpred IS NULL`): uno parcial no lo usa el motor para comprobarla.
    expect($uncovered)->toBe([])
        ->and($indexes->keys()->all())->toContain(TenantMigration::indexName('student_movements', 'pickup_authorization_id'))
        ->toContain(TenantMigration::indexName('student_movements', 'guardian_student_link_id'))
        ->toContain(TenantMigration::indexName('student_movements', 'corrects_movement_id'));
});

test('datos.md §8.4 RN-PRL-04 CA-PRL-004: el rol de la aplicación y el de plataforma no tienen UPDATE ni DELETE sobre student_movements, pero sí INSERT y SELECT; pickup_alerts sí se actualiza', function (): void {
    foreach (['plataforma_app', 'plataforma_platform'] as $role) {
        $has = fn (string $table, string $privilege): bool => (bool) DB::connection('pgsql_owner')->selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, $table, $privilege])->ok;

        expect($has('student_movements', 'INSERT'))->toBeTrue()
            ->and($has('student_movements', 'SELECT'))->toBeTrue()
            ->and($has('student_movements', 'UPDATE'))->toBeFalse("{$role} puede UPDATE student_movements")
            ->and($has('student_movements', 'DELETE'))->toBeFalse("{$role} puede DELETE student_movements")
            ->and($has('pickup_alerts', 'UPDATE'))->toBeTrue();
    }
});

test('datos.md §8.4 RN-PRL-07 RN-PRL-04: el motor hace cumplir los CHECK de student_movements — vocabularios, fuera de horario con motivo, «otro» con nota, salida con persona, persona/base/método juntos, base coherente con la referencia, corrección con motivo e idempotencia', function (): void {
    Storage::fake('local');
    $f = Prl::doorFamily('prls-checks');
    $row = fn (array $o = []) => prlMovementRow($f, $o);
    $link = app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('guardian_student_links')->value('id'));
    $isNull = ['picked_up_by_person_id' => null, 'basis' => null, 'verification_method' => null, 'pickup_authorization_id' => null];

    expect(prlTryInsert('student_movements', $row()))->toBeNull()
        ->and(prlTryInsert('student_movements', $row(['direction' => 'traslado'])))->toContain('student_movements_direction_check')
        // Entrada sin persona: admisible; con persona incompleta, no.
        ->and(prlTryInsert('student_movements', $row($isNull + ['direction' => 'entrada'])))->toBeNull()
        ->and(prlTryInsert('student_movements', $row($isNull)))->toContain('student_movements_exit_needs_person_check')
        ->and(prlTryInsert('student_movements', $row(['basis' => null])))->toContain('student_movements_person_basis_method_check')
        ->and(prlTryInsert('student_movements', $row(['verification_method' => null])))->toContain('student_movements_person_basis_method_check')
        ->and(prlTryInsert('student_movements', $row(['verification_method' => 'huella'])))->toContain('student_movements_verification_method_check')
        ->and(prlTryInsert('student_movements', $row(['basis' => 'vecino'])))->toContain('student_movements_basis_check')
        // La base casa con la referencia presente.
        ->and(prlTryInsert('student_movements', $row(['basis' => 'tutor'])))->toContain('student_movements_basis_reference_check')
        ->and(prlTryInsert('student_movements', $row(['basis' => 'tutor', 'pickup_authorization_id' => null, 'guardian_student_link_id' => $link])))->toBeNull()
        ->and(prlTryInsert('student_movements', $row(['guardian_student_link_id' => $link])))->toContain('student_movements_basis_reference_check')
        ->and(prlTryInsert('student_movements', $row(['pickup_authorization_id' => null])))->toContain('student_movements_basis_reference_check')
        // Fuera de horario.
        ->and(prlTryInsert('student_movements', $row(['off_schedule' => true])))->toContain('student_movements_off_schedule_needs_reason_check')
        ->and(prlTryInsert('student_movements', $row(['off_schedule' => true, 'off_schedule_reason' => 'vacaciones'])))->toContain('student_movements_off_schedule_reason_check')
        ->and(prlTryInsert('student_movements', $row(['off_schedule' => true, 'off_schedule_reason' => 'medico'])))->toBeNull()
        ->and(prlTryInsert('student_movements', $row(['off_schedule' => true, 'off_schedule_reason' => 'otro'])))->toContain('student_movements_other_reason_needs_notes_check')
        ->and(prlTryInsert('student_movements', $row(['off_schedule' => true, 'off_schedule_reason' => 'otro', 'notes' => 'x'])))->toBeNull()
        ->and(prlTryInsert('student_movements', $row(['notes' => str_repeat('x', 501)])))->toContain('student_movements_notes_length_check')
        // Corrección: movimiento y motivo juntos.
        ->and(prlTryInsert('student_movements', $row(['correction_reason' => 'x'])))->toContain('student_movements_correction_coherence_check')
        ->and(prlTryInsert('student_movements', $row(['idempotency_key' => 'k'])))->toContain('student_movements_idempotency_coherence_check')
        ->and(prlTryInsert('student_movements', $row(['idempotency_key' => str_repeat('k', 256), 'idempotency_body_hash' => 'h'])))->toContain('student_movements_idempotency_key_length_check')
        ->and(prlTryInsert('student_movements', $row(['idempotency_key' => str_repeat('k', 255), 'idempotency_body_hash' => 'h'])))->toBeNull()
        ->and(prlTryInsert('student_movements', $row(['idempotency_key' => 'k', 'idempotency_body_hash' => 'h'])))->toBeNull();
});

test('datos.md §8.5 RN-PRL-08 CA-PRL-008: el motor hace cumplir los CHECK de pickup_alerts — tipo, estado, resolución, cerrada ⇔ resolución completa, longitudes e idempotencia', function (): void {
    $f = Prl::doorFamily('prls-alerts');
    $row = fn (array $o = []) => prlAlertRow($f, $o);
    $closed = ['status' => 'cerrada', 'resolution' => 'no_entregado', 'resolved_at' => now(), 'resolved_by' => $f['direccion']->id];

    expect(prlTryInsert('pickup_alerts', $row()))->toBeNull()
        ->and(prlTryInsert('pickup_alerts', $row(['kind' => 'alumno_no_recogido'])))->toContain('pickup_alerts_kind_check')
        ->and(prlTryInsert('pickup_alerts', $row(['status' => 'pendiente'])))->toContain('pickup_alerts_status_check')
        ->and(prlTryInsert('pickup_alerts', $row(['presenter_description' => ''])))->toContain('pickup_alerts_presenter_description_check')
        ->and(prlTryInsert('pickup_alerts', $row(['presenter_description' => str_repeat('x', 501)])))->toContain('pickup_alerts_presenter_description_check')
        // Cerrada ⇔ resolución, instante y autor.
        ->and(prlTryInsert('pickup_alerts', $row($closed)))->toBeNull()
        ->and(prlTryInsert('pickup_alerts', $row(['status' => 'cerrada'])))->toContain('pickup_alerts_closed_coherence_check')
        // Y una abierta no lleva nada de la resolución (no queda a medio cerrar ni reabierta con restos).
        ->and(prlTryInsert('pickup_alerts', $row(['resolution' => 'no_entregado'])))->toContain('pickup_alerts_open_without_resolution_check')
        ->and(prlTryInsert('pickup_alerts', $row(['resolved_at' => now()])))->toContain('pickup_alerts_open_without_resolution_check')
        ->and(prlTryInsert('pickup_alerts', $row(['resolution_notes' => 'x'])))->toContain('pickup_alerts_open_without_resolution_check')
        ->and(prlTryInsert('pickup_alerts', $row(['resolution' => 'huir'] + $closed)))->toContain('pickup_alerts_resolution_check')
        ->and(prlTryInsert('pickup_alerts', $row($closed + ['resolution_notes' => str_repeat('x', 1001)])))->toContain('pickup_alerts_resolution_notes_length_check')
        ->and(prlTryInsert('pickup_alerts', $row(['idempotency_key' => str_repeat('k', 256), 'idempotency_body_hash' => 'h'])))->toContain('pickup_alerts_idempotency_key_length_check')
        ->and(prlTryInsert('pickup_alerts', $row(['idempotency_key' => 'k'])))->toContain('pickup_alerts_idempotency_coherence_check');
});

test('datos.md §8.4 RN-PRL-09: el único de idempotencia choca por (centro, usuario, clave) y no entre usuarios ni entre centros; una fila sin clave no choca nunca', function (): void {
    $f = Prl::doorFamily('prls-idem');
    $tenantId = $f['tenant']->id;
    $db = DB::connection('pgsql');

    app(TenantContext::class)->runFor($tenantId, function () use ($f, $db): void {
        $first = prlMovementRow($f, ['idempotency_key' => 'k1', 'idempotency_body_hash' => 'h']);
        $db->table('student_movements')->insert($first);
        $insert = static function (array $row) use ($db): ?string {
            try {
                $db->transaction(fn () => $db->table('student_movements')->insert($row));
            } catch (QueryException $e) {
                return $e->getMessage();
            }

            return null;
        };

        expect($insert(prlMovementRow($f, ['idempotency_key' => 'k1', 'idempotency_body_hash' => 'h'])))->toContain('student_movements_idempotency_unique')
            ->and($insert(prlMovementRow($f, ['idempotency_key' => 'k1', 'idempotency_body_hash' => 'h', 'recorded_by' => $f['direccion']->id])))->toBeNull()
            ->and($insert(prlMovementRow($f)))->toBeNull()
            ->and($insert(prlMovementRow($f)))->toBeNull();
    });
});

test('datos.md §8.4 INV-001 ADR-033 §6: la RLS oculta los movimientos de otro centro y rechaza escribir con un tenant_id ajeno; una salida de un centro no puede referenciar el alumno ni la autorización de otro', function (): void {
    $a = Prl::doorFamily('prls-rls-a');
    $b = Prl::doorFamily('prls-rls-b');
    Prl::exit($b)->assertCreated();

    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($b): void {
        expect(DB::table('student_movements')->where('tenant_id', $b['tenant']->id)->count())->toBe(0)
            ->and(DB::table('student_movements')->count())->toBe(0);
    });

    $state = null;

    try {
        app(TenantContext::class)->runFor($a['tenant']->id, function () use ($b, $a): void {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_movements')->insert(prlMovementRow($b, ['tenant_id' => $b['tenant']->id, 'delivered_by' => $a['conserje']->id])));
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('42501');

    // Una salida del centro A con el alumno y la autorización del centro B: la clave foránea compuesta la rechaza (23503).
    $crossed = prlMovementRow($b, ['tenant_id' => $a['tenant']->id, 'delivered_by' => $a['conserje']->id, 'recorded_by' => $a['conserje']->id]);
    expect(prlTryInsert('student_movements', $crossed))->toContain('23503');
});

// REQ-PRL-004 (1.14b-d1), datos.md §8.9, RN-PRL-17 a RN-PRL-19, OPEN-PRL-05, CA-PRL-031 a CA-PRL-033 (CA-PRL-034, en `tests/Concurrency/PrlConcurrencyTest.php`): endurecimiento en el motor (migración *expand* reversible).

/**
 * Ejecuta `$work($connection)` con la conexión `$name` dentro del centro `$tenantId` y **siempre deshace**. Devuelve el SQLSTATE del
 * primer error de base de datos, o `null` si no hubo ninguno.
 */
function prlHardeningAttempt(string $name, int $tenantId, Closure $work): ?string
{
    $connection = DB::connection($name);

    try {
        $connection->transaction(function () use ($connection, $tenantId, $work): void {
            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
            $work($connection);

            throw new RuntimeException('deshacer');
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? 'sin-sqlstate';
    } catch (RuntimeException) {
        return null;
    }

    return null;
}

/** Inserta una alerta cerrada válida y devuelve su `id`, con la conexión de aplicación y dentro del centro. */
function prlInsertClosedAlert(array $f): int
{
    return app(TenantContext::class)->runFor($f['tenant']->id, fn (): int => (int) DB::connection('pgsql')->table('pickup_alerts')->insertGetId(
        prlAlertRow($f, ['status' => 'cerrada', 'resolution' => 'no_entregado', 'resolved_at' => now(), 'resolved_by' => $f['direccion']->id]),
    ));
}

test('CA-PRL-031 RN-PRL-18: el rol de la aplicación y el de plataforma no tienen DELETE sobre pickup_alerts, y el motor lo rechaza con permission denied; el UPDATE sigue permitido', function (): void {
    $f = Prl::doorFamily('prlh-delete');
    $id = prlInsertClosedAlert($f);

    foreach (['plataforma_app', 'plataforma_platform'] as $role) {
        $has = fn (string $privilege): bool => (bool) DB::connection('pgsql_owner')->selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'pickup_alerts', $privilege])->ok;

        expect($has('DELETE'))->toBeFalse("{$role} puede DELETE pickup_alerts")
            ->and($has('UPDATE'))->toBeTrue()
            ->and($has('INSERT'))->toBeTrue()
            ->and($has('SELECT'))->toBeTrue();
    }

    foreach (['pgsql', 'pgsql_platform'] as $connection) {
        expect(prlHardeningAttempt($connection, $f['tenant']->id, fn ($c) => $c->table('pickup_alerts')->where('id', $id)->delete()))->toBe('42501');
    }
});

test('CA-PRL-032 RN-PRL-17: el rol de la aplicación no puede hacer UPDATE de una alerta cerrada (cualquier columna, también deleted_at); una abierta se cierra', function (): void {
    $f = Prl::doorFamily('prlh-closed');
    $closedId = prlInsertClosedAlert($f);
    $openId = app(TenantContext::class)->runFor($f['tenant']->id, fn (): int => (int) DB::connection('pgsql')->table('pickup_alerts')->insertGetId(prlAlertRow($f)));
    $tenantId = $f['tenant']->id;

    // Solo la conexión de aplicación: la fila vive en la transacción del test y las otras sesiones no la ven. Plataforma y propietario,
    // con datos confirmados, en `tests/Concurrency/PrlConcurrencyTest.php`.
    $connection = 'pgsql';

    foreach ([['resolution_notes' => 'cambio'], ['deleted_at' => now()], ['status' => 'cerrada']] as $change) {
        expect(prlHardeningAttempt($connection, $tenantId, fn ($c) => $c->table('pickup_alerts')->where('id', $closedId)->update($change)))->toBe('23001', "{$connection} actualizó una alerta cerrada");
    }

    $close = ['status' => 'cerrada', 'resolution' => 'no_entregado', 'resolved_at' => now(), 'resolved_by' => $f['direccion']->id];

    expect(prlHardeningAttempt($connection, $tenantId, function ($c) use ($openId, $close): void {
        expect($c->table('pickup_alerts')->where('id', $openId)->update($close))->toBe(1);
    }))->toBeNull();

    // La alerta cerrada sigue intacta.
    expect(DB::connection('pgsql_platform')->table('pickup_alerts')->where('id', $closedId)->value('resolution_notes'))->toBeNull();
});

test('CA-PRL-033 RN-PRL-19: el motor rechaza un motivo con off_schedule = false y una corrección que referencia un movimiento de otro alumno; la corrección del mismo alumno se acepta', function (): void {
    $f = Prl::doorFamily('prlh-checks');
    Prl::exit($f)->assertCreated();
    $tenantId = $f['tenant']->id;
    $originalId = app(TenantContext::class)->runFor($tenantId, fn () => (int) DB::table('student_movements')->value('id'));
    $abueloId = app(TenantContext::class)->runFor($tenantId, fn () => (int) DB::table('people')->where('public_id', $f['abuelo']['person'])->value('id'));

    // La fila se compone **fuera** de la transacción: `prlMovementRow()` abre su propio contexto de centro y lo cerraría.
    $insert = function (array $overrides) use ($f, $tenantId): ?string {
        $row = prlMovementRow($f, $overrides);

        return prlHardeningAttempt('pgsql', $tenantId, fn ($c) => $c->table('student_movements')->insert($row));
    };

    expect($insert(['off_schedule' => false, 'off_schedule_reason' => 'medico']))->toBe('23514')
        ->and($insert(['off_schedule' => true, 'off_schedule_reason' => 'medico']))->toBeNull()
        ->and($insert(['corrects_movement_id' => $originalId, 'correction_reason' => 'error de registro']))->toBeNull()
        // Otro alumno (aquí, otra persona del centro) corrigiendo un movimiento del alumno: la FK compuesta lo impide (23503).
        ->and($insert(['student_person_id' => $abueloId, 'corrects_movement_id' => $originalId, 'correction_reason' => 'error de registro']))->toBe('23503');

    $constraints = DB::connection('pgsql_owner')->select("SELECT conname, convalidated FROM pg_constraint WHERE conrelid = 'student_movements'::regclass AND conname IN ('student_movements_reason_requires_off_schedule_check', 'student_movements_corrects_same_student_foreign', 'student_movements_tenant_corrects_movement_id_foreign')");

    expect(collect($constraints)->pluck('convalidated', 'conname')->sortKeys()->all())->toBe([
        'student_movements_corrects_same_student_foreign' => true,
        'student_movements_reason_requires_off_schedule_check' => true,
        'student_movements_tenant_corrects_movement_id_foreign' => true,
    ]);
});
