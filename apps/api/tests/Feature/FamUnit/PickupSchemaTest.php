<?php

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-005 (1.14b-a), docs/modulos/REQ-FAM-UNIT/datos.md §8.1 a §8.3, §8.6, ADR-029, ADR-033, ADR-034 §6, ADR-068,
// OPEN-FAM-UNIT-35, INV-001, INV-004, INV-005, INV-015: esquema real de `pickup_photos`, `pickup_authorizations` y la ampliación
// del CHECK de `terms_versions.kind`, con base de datos real (ADR-033 §10).

const PICKUP_TABLES = ['pickup_photos', 'pickup_authorizations'];

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> */
function pickupColumns(string $table): array
{
    return array_column(DB::connection('pgsql_owner')->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), 'column_name');
}

/**
 * Intenta insertar `$row` como el rol de la aplicación, dentro del centro de `$row['tenant_id']` y en un punto de guardado
 * que **siempre se deshace**. Devuelve el mensaje de error de base de datos, o `null` si la fila era admisible.
 */
function pickupTryInsert(string $table, array $row): ?string
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

/**
 * Como `pickupTryInsert()` pero por la conexión de plataforma (que salta la RLS y confirma de verdad lo que no se deshace):
 * solo para `terms_versions`, donde la migración de su CHECK necesita que el test no deje bloqueos de escritura abiertos.
 */
function pickupTryInsertCommitted(string $table, array $row): ?string
{
    $platform = DB::connection('pgsql_platform');

    try {
        $platform->transaction(function () use ($platform, $table, $row): void {
            $platform->table($table)->insert($row);

            throw new RuntimeException('deshacer');
        });
    } catch (QueryException $e) {
        return $e->getMessage();
    } catch (RuntimeException) {
        return null;
    }

    return null;
}

/** Una autorización permanente admisible de una familia de `pickupFamily()`, para clonarla con `$overrides`. */
function pickupRow(array $f, int $personId, array $overrides = []): array
{
    static $n = 0;
    $n++;

    return $overrides + [
        'tenant_id' => $f['tenant']->id,
        'public_id' => '01JZZZZZZZZZZZZZZZZZZZ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'student_person_id' => $f['student']->id,
        'person_id' => $personId,
        'relationship' => 'abuelo',
        'kind' => 'permanente',
        'valid_from' => '2026-01-01',
        'valid_until' => null,
        'reason' => null,
        'authorized_by_guardian_id' => app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('guardians')->value('id')),
        'terms_version_id' => app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('terms_versions')->where('kind', 'autorizacion_recogida')->value('id')),
        'channel' => 'presencial',
        'authorized_at' => now(),
        'recorded_by' => $f['admin']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('datos.md §8.1 §8.2 ADR-033 ADR-034 §6: las dos tablas existen con RLS forzada, public_id char(26), tenant_id, UNIQUE (tenant_id, id), borrado lógico y autoría, y ninguna lleva academic_year_id', function (): void {
    foreach (PICKUP_TABLES as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $publicId = DB::connection('pgsql_owner')->selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]);
        $unique = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexdef LIKE 'CREATE UNIQUE INDEX % (tenant_id, id)'", [$table]);
        $policy = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);

        expect($rls->on)->toBeTrue("{$table} sin RLS")
            ->and($rls->forced)->toBeTrue("{$table} sin FORCE RLS")
            ->and((int) $policy->n)->toBe(1)
            ->and((int) $unique->n)->toBe(1)
            ->and($publicId->t)->toBe('character(26)')
            ->and(pickupColumns($table))->toContain('tenant_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at')->not->toContain('academic_year_id');
    }

    expect(pickupColumns('pickup_photos'))->toContain('person_id', 'object_key', 'mime_type', 'byte_size')
        ->and(pickupColumns('pickup_authorizations'))->toContain(
            'student_person_id', 'person_id', 'relationship', 'kind', 'valid_from', 'valid_until', 'reason', 'authorized_by_guardian_id',
            'terms_version_id', 'channel', 'authorized_at', 'recorded_by', 'ip_address', 'user_agent', 'withdrawn_at', 'withdrawn_by',
        );
});

test('datos.md §8.2 ADR-029 AR-04: ninguna columna es varchar, timestamp sin zona ni ENUM, las fechas de vigencia son date, los instantes timestamptz, la IP inet y ninguna FK tiene acción en cascada', function (): void {
    $types = fn (string $table, string $column): string => DB::connection('pgsql_owner')->selectOne('SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = ?', [$table, $column])->t;

    expect($types('pickup_authorizations', 'valid_from'))->toBe('date')
        ->and($types('pickup_authorizations', 'valid_until'))->toBe('date')
        ->and($types('pickup_authorizations', 'authorized_at'))->toContain('with time zone')
        ->and($types('pickup_authorizations', 'withdrawn_at'))->toContain('with time zone')
        ->and($types('pickup_authorizations', 'ip_address'))->toBe('inet')
        ->and($types('pickup_authorizations', 'reason'))->toBe('text')
        ->and($types('pickup_photos', 'object_key'))->toBe('text')
        ->and($types('pickup_photos', 'byte_size'))->toBe('integer');

    $bad = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla, a.attname AS columna FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
        WHERE c.relname = ANY (?) AND a.attnum > 0 AND NOT a.attisdropped
          AND (format_type(a.atttypid, a.atttypmod) LIKE 'character varying%' OR format_type(a.atttypid, a.atttypmod) LIKE 'timestamp%without time zone' OR t.typtype = 'e')
        SQL, ['{'.implode(',', PICKUP_TABLES).'}']);

    $cascading = DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid::regclass::text = ANY (?) AND confdeltype IN ('c', 'n', 'd')", ['{'.implode(',', PICKUP_TABLES).'}']);

    expect($bad)->toBe([])->and($cascading)->toBe([]);
});

test('datos.md §8.6 AR-16: existen los únicos parciales y el índice de la lista, y cada clave foránea compuesta (salvo la de autoría) tiene un índice cuyo prefijo es (tenant_id, columna)', function (): void {
    $indexes = collect(DB::connection('pgsql_owner')->select(
        "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ANY (?)", ['{'.implode(',', PICKUP_TABLES).'}']
    ))->pluck('indexdef', 'indexname');

    expect($indexes['pickup_photos_tenant_person_live_unique'])->toContain('UNIQUE')->toContain('(tenant_id, person_id)')->toContain('deleted_at IS NULL')
        ->and($indexes['pickup_authorizations_permanent_live_unique'])->toContain('UNIQUE')->toContain('(tenant_id, student_person_id, person_id)')->toContain("kind = 'permanente'")->toContain('withdrawn_at IS NULL')
        ->and($indexes['pickup_authorizations_punctual_live_unique'])->toContain('UNIQUE')->toContain('(tenant_id, student_person_id, person_id, valid_from)')->toContain("kind = 'puntual'")
        ->and($indexes['pickup_authorizations_student_validity_idx'])->toContain('(tenant_id, student_person_id, valid_from, valid_until)')->toContain('withdrawn_at IS NULL')->toContain('deleted_at IS NULL');

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
        SQL, ['{'.implode(',', PICKUP_TABLES).'}']);

    expect($uncovered)->toBe([]);
    // Los índices de las FK que ya cubre un único parcial no se duplican.
    expect($indexes->keys()->all())->not->toContain(TenantMigration::indexName('pickup_authorizations', 'student_person_id'))
        ->not->toContain(TenantMigration::indexName('pickup_photos', 'person_id'));
});

test('datos.md §8.2 OPEN-FAM-UNIT-35 CA-FAM-UNIT-131: el motor hace cumplir los CHECK — telefono solo con puntual, puntual de un día y con motivo, persona distinta del alumno, rango de fechas, vocabularios y coherencia de la retirada', function (): void {
    $f = Fam::pickupFamily('ps-checks');
    $person = Fam::person($f['tenant'], 'Abuelo', 'Esquema');
    $row = fn (array $o = []) => pickupRow($f, $person->id, $o);

    expect(pickupTryInsert('pickup_authorizations', $row()))->toBeNull()
        // telefono ⇒ puntual (con puntual completo sí se admite)
        ->and(pickupTryInsert('pickup_authorizations', $row(['channel' => 'telefono'])))->toContain('pickup_authorizations_telephone_punctual_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['channel' => 'telefono', 'kind' => 'puntual', 'valid_until' => '2026-01-01', 'reason' => 'Imprevisto'])))->toBeNull()
        ->and(pickupTryInsert('pickup_authorizations', $row(['channel' => 'portal'])))->toContain('pickup_authorizations_channel_check')
        // puntual: un día y motivo
        ->and(pickupTryInsert('pickup_authorizations', $row(['kind' => 'puntual', 'valid_until' => '2026-01-02', 'reason' => 'x'])))->toContain('pickup_authorizations_punctual_single_day_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['kind' => 'puntual', 'valid_until' => '2026-01-01', 'reason' => null])))->toContain('pickup_authorizations_punctual_reason_check')
        // persona distinta del alumno, rango de fechas, vocabularios, longitud del motivo
        ->and(pickupTryInsert('pickup_authorizations', $row(['person_id' => $f['student']->id])))->toContain('pickup_authorizations_person_not_student_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['valid_until' => '2025-12-31'])))->toContain('pickup_authorizations_valid_range_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['relationship' => 'vecino'])))->toContain('pickup_authorizations_relationship_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['kind' => 'anual'])))->toContain('pickup_authorizations_kind_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['reason' => str_repeat('x', 501)])))->toContain('pickup_authorizations_reason_length_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['reason' => str_repeat('x', 500)])))->toBeNull()
        // retirada: las dos columnas o ninguna
        ->and(pickupTryInsert('pickup_authorizations', $row(['withdrawn_at' => now()])))->toContain('pickup_authorizations_withdrawal_coherence_check')
        ->and(pickupTryInsert('pickup_authorizations', $row(['withdrawn_at' => now(), 'withdrawn_by' => $f['admin']->id])))->toBeNull();
});

test('datos.md §8.1 §8.2 RN-FAM-UNIT-83 RN-FAM-UNIT-88: los CHECK de la fotografía y los únicos parciales — una foto viva por persona, una permanente viva por par y una puntual viva por fecha — se cumplen por índice y se liberan al retirar o dar de baja', function (): void {
    $f = Fam::pickupFamily('ps-unique');
    $person = Fam::person($f['tenant'], 'Abuelo', 'Unico');
    $tenantId = $f['tenant']->id;
    $photo = fn (array $o = []) => $o + ['tenant_id' => $tenantId, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZP', 'person_id' => $person->id, 'object_key' => "tenants/x/fam-unit/pickup-photos/{$person->id}.jpg", 'mime_type' => 'image/jpeg', 'byte_size' => 10, 'created_at' => now(), 'updated_at' => now()];

    expect(pickupTryInsert('pickup_photos', $photo()))->toBeNull()
        ->and(pickupTryInsert('pickup_photos', $photo(['mime_type' => 'image/svg+xml'])))->toContain('pickup_photos_mime_type_check')
        ->and(pickupTryInsert('pickup_photos', $photo(['mime_type' => 'image/gif'])))->toContain('pickup_photos_mime_type_check')
        ->and(pickupTryInsert('pickup_photos', $photo(['byte_size' => 0])))->toContain('pickup_photos_byte_size_check');

    // Dos filas vivas de la misma persona chocan; con la primera dada de baja, no.
    app(TenantContext::class)->runFor($tenantId, function () use ($photo): void {
        $db = DB::connection('pgsql');
        $db->table('pickup_photos')->insert($photo());

        $second = null;

        try {
            $db->transaction(fn () => $db->table('pickup_photos')->insert($photo(['public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZQ'])));
        } catch (QueryException $e) {
            $second = $e->getMessage();
        }

        expect($second)->toContain('pickup_photos_tenant_person_live_unique');

        $db->table('pickup_photos')->where('public_id', '01JZZZZZZZZZZZZZZZZZZZZZZP')->update(['deleted_at' => now()]);
        $db->table('pickup_photos')->insert($photo(['public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZQ']));

        expect($db->table('pickup_photos')->whereNull('deleted_at')->count())->toBe(1);
    });
});

test('datos.md §8.2 RN-FAM-UNIT-88: dos permanentes vivas del mismo par chocan, dos puntuales de la misma fecha también; retirada la primera, la segunda entra, y una puntual y una permanente conviven', function (): void {
    $f = Fam::pickupFamily('ps-unique2');
    $person = Fam::person($f['tenant'], 'Abuelo', 'Unico');
    $db = DB::connection('pgsql');
    $row = fn (array $o = []) => pickupRow($f, $person->id, $o);
    $insert = static function (array $row) use ($db): ?string {
        try {
            $db->transaction(fn () => $db->table('pickup_authorizations')->insert($row));
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return null;
    };

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($insert, $row, $db, $person, $f): void {
        expect($insert($row()))->toBeNull()
            ->and($insert($row()))->toContain('pickup_authorizations_permanent_live_unique')
            ->and($insert($row(['kind' => 'puntual', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02', 'reason' => 'Día 1'])))->toBeNull()
            ->and($insert($row(['kind' => 'puntual', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02', 'reason' => 'Otra vez'])))->toContain('pickup_authorizations_punctual_live_unique')
            // otra fecha, sí
            ->and($insert($row(['kind' => 'puntual', 'valid_from' => '2026-11-03', 'valid_until' => '2026-11-03', 'reason' => 'Día 2'])))->toBeNull();

        // Retirar la permanente libera el hueco.
        $db->table('pickup_authorizations')->where('kind', 'permanente')->where('person_id', $person->id)->update(['withdrawn_at' => now(), 'withdrawn_by' => $f['admin']->id]);
        expect($insert($row()))->toBeNull();
    });
});

test('datos.md §8.2 ADR-066 §5.8: terms_version_id es RESTRICT (el texto firmado es evidencia) y una autorización de otro centro no puede apuntar a un alumno, persona ni texto de este', function (): void {
    $a = Fam::pickupFamily('ps-fk-a');
    $b = Fam::pickupFamily('ps-fk-b');
    $personB = Fam::person($b['tenant'], 'Abuelo', 'Ajeno');

    $definition = DB::connection('pgsql_owner')->selectOne("SELECT confdeltype AS t, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pickup_authorizations_tenant_terms_version_foreign'");
    expect($definition->t)->toBe('r')->and($definition->def)->toContain('FOREIGN KEY (tenant_id, terms_version_id) REFERENCES terms_versions(tenant_id, id)');

    // Una autorización del centro A con la persona del centro B: la clave foránea compuesta la rechaza.
    $state = pickupTryInsert('pickup_authorizations', pickupRow($a, $personB->id));
    expect($state)->toContain('23503');
});

test('datos.md §8.3 ADR-068 L2: el CHECK de terms_versions.kind admite autorizacion_recogida y sigue rechazando cualquier otro tipo; la migración es reintentable y su down() restaura los tres valores', function (): void {
    [$tenant] = provisionCoreTenant('ps-kind');
    $row = fn (array $o) => $o + [
        'tenant_id' => $tenant->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZ9K', 'kind' => 'autorizacion_recogida', 'version_number' => 90,
        'title_translations' => '{"es-ES":"t"}', 'body_translations' => '{"es-ES":"c"}', 'status' => 'borrador', 'requires_reacceptance' => false,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(pickupTryInsertCommitted('terms_versions', $row([])))->toBeNull()
        ->and(pickupTryInsertCommitted('terms_versions', $row(['kind' => 'otro'])))->toContain('terms_versions_kind_check')
        // La re-aceptación sigue siendo solo de condiciones de uso.
        ->and(pickupTryInsertCommitted('terms_versions', $row(['requires_reacceptance' => true])))->toContain('terms_versions_reacceptance_kind_check');

    $owner = DB::connection('pgsql_owner');
    $state = fn (): object => $owner->selectOne("SELECT convalidated AS v, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'terms_versions_kind_check'");
    $migration = require app_path('Modules/FamUnit/Database/migrations/2026_10_30_100200_add_autorizacion_recogida_to_terms_versions_kind_check.php');

    expect($state()->v)->toBeTrue()->and($state()->def)->toContain('autorizacion_recogida');

    // up() relanzado con la restricción ya ampliada y validada no la toca.
    $oid = $owner->selectOne("SELECT oid FROM pg_constraint WHERE conname = 'terms_versions_kind_check'")->oid;
    $migration->up();
    expect($owner->selectOne("SELECT oid FROM pg_constraint WHERE conname = 'terms_versions_kind_check'")->oid)->toBe($oid);

    // down() con una versión del tipo nuevo (evidencia, committed por la conexión de plataforma) se niega.
    $platform = DB::connection('pgsql_platform');
    $platform->table('terms_versions')->insert($row(['version_number' => 91, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZ9L']));

    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'autorizacion_recogida');
        expect($state()->def)->toContain('autorizacion_recogida');
    } finally {
        $platform->table('terms_versions')->where('public_id', '01JZZZZZZZZZZZZZZZZZZZZZ9L')->delete();
    }

    // Sin filas del tipo nuevo, down() restaura los tres valores y up() los vuelve a cuatro.
    try {
        $migration->down();
        expect($state()->def)->not->toContain('autorizacion_recogida')->and($state()->v)->toBeTrue();
    } finally {
        $migration->up();
    }

    expect($state()->def)->toContain('autorizacion_recogida')->and($state()->v)->toBeTrue();
});

test('INV-001 ADR-033 §5: la RLS oculta las autorizaciones y fotografías de otro centro y rechaza escribir con un tenant_id ajeno', function (): void {
    $a = Fam::pickupFamily('ps-rls-a');
    $b = Fam::pickupFamily('ps-rls-b');
    $thirdB = Fam::thirdParty($b, 1001, photo: false);

    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($b): void {
        expect(DB::table('pickup_authorizations')->where('tenant_id', $b['tenant']->id)->count())->toBe(0)
            ->and(DB::table('pickup_authorizations')->count())->toBe(0);
    });

    $state = null;

    try {
        app(TenantContext::class)->runFor($a['tenant']->id, function () use ($b): void {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('pickup_photos')->insert([
                'tenant_id' => $b['tenant']->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'person_id' => 1, 'object_key' => 'x', 'mime_type' => 'image/png', 'byte_size' => 1,
            ]));
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('42501')
        ->and($thirdB['authorization'])->toBeString();
});
