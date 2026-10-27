<?php

use App\Modules\FamUnit\Application\GuardianService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/datos.md §1.6, §1.8, §3, §4, §5, ADR-029, ADR-033, ADR-034 §6,
// ADR-062 §5.1 (AR-16), ADR-066 §5.8, INV-001, INV-015: esquema real de `terms_versions` e `image_consent_decisions`, con base
// de datos real (ADR-033 §10). Los CHECK que dependen de una regla se prueban también junto a ella.

const IMAGE_CONSENT_TABLES = ['terms_versions', 'image_consent_decisions'];

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> */
function schemaColumns(string $table): array
{
    return array_column(DB::connection('pgsql_owner')->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), 'column_name');
}

test('datos.md §1.6 §1.8 §5: las dos tablas existen con RLS forzada, public_id char(26), tenant_id y UNIQUE (tenant_id, id); la de decisiones es de solo anexar y ninguna lleva academic_year_id', function (): void {
    foreach (IMAGE_CONSENT_TABLES as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $publicId = DB::connection('pgsql_owner')->selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]);
        $unique = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexdef LIKE 'CREATE UNIQUE INDEX % (tenant_id, id)'", [$table]);
        $policy = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);

        expect($rls->on)->toBeTrue("{$table} sin RLS")
            ->and($rls->forced)->toBeTrue("{$table} sin FORCE RLS")
            ->and((int) $policy->n)->toBe(1)
            ->and((int) $unique->n)->toBe(1)
            ->and($publicId->t)->toBe('character(26)')
            ->and(schemaColumns($table))->toContain('tenant_id')->not->toContain('academic_year_id');
    }

    // Terms: ordinaria (autoría, marcas de tiempo y borrado lógico).
    expect(schemaColumns('terms_versions'))->toContain('created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'kind', 'version_number', 'title_translations', 'body_translations', 'status', 'requires_reacceptance', 'published_at');

    // Decisiones: de solo anexar — sin deleted_at, updated_at ni updated_by (ADR-034 §6).
    expect(schemaColumns('image_consent_decisions'))->not->toContain('deleted_at', 'updated_at', 'updated_by', 'created_by', 'created_at')
        ->and(schemaColumns('image_consent_decisions'))->toContain('student_person_id', 'level', 'decision', 'guardian_id', 'recorded_by', 'channel', 'consent_text_version_id', 'shown_locale', 'occurred_at', 'ip_address', 'user_agent');

    // REVOKE UPDATE, DELETE a los dos roles de aplicación (no al propietario).
    foreach (['plataforma_app', 'plataforma_platform'] as $role) {
        foreach (['UPDATE', 'DELETE'] as $privilege) {
            $has = DB::connection('pgsql_owner')->selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'image_consent_decisions', $privilege]);
            expect($has->ok)->toBeFalse("{$role} conserva {$privilege} sobre image_consent_decisions");
        }
    }

    expect(DB::connection('pgsql_owner')->selectOne("SELECT has_table_privilege('plataforma_app', 'image_consent_decisions', 'INSERT') AS ok")->ok)->toBeTrue();
});

test('datos.md §1.6 §1.8 ADR-029 AR-04: ninguna columna es varchar, timestamp sin zona ni ENUM, el instante es timestamptz, la IP es inet y las únicas FK con acción referencial son RESTRICT', function (): void {
    $bad = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT table_name, column_name, data_type FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = ANY (?)
          AND data_type IN ('character varying', 'timestamp without time zone', 'USER-DEFINED')
        SQL, ['{'.implode(',', IMAGE_CONSENT_TABLES).'}']);

    expect($bad)->toBe([]);

    $types = collect(DB::connection('pgsql_owner')->select(
        "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'image_consent_decisions'"
    ))->pluck('data_type', 'column_name');

    expect($types['occurred_at'])->toBe('timestamp with time zone')->and($types['ip_address'])->toBe('inet')->and($types['user_agent'])->toBe('text');

    // Ninguna FK con CASCADE ni SET NULL (las bajas son lógicas y la evidencia no se borra).
    $actions = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conrelid::regclass::text AS table_name, conname FROM pg_constraint
        WHERE contype = 'f' AND conrelid::regclass::text = ANY (?)
          AND (confdeltype IN ('c', 'n', 'd') OR confupdtype IN ('c', 'n', 'd'))
        SQL, ['{'.implode(',', IMAGE_CONSENT_TABLES).'}']);

    expect($actions)->toBe([]);

    // La FK al texto es RESTRICT (a) y compuesta con el centro.
    $restrict = DB::connection('pgsql_owner')->selectOne(
        "SELECT confdeltype, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'image_consent_decisions_tenant_text_version_foreign'"
    );
    expect($restrict->confdeltype)->toBe('r')->and($restrict->def)->toContain('FOREIGN KEY (tenant_id, consent_text_version_id) REFERENCES terms_versions(tenant_id, id)');
});

test('datos.md §3 AR-16: existen el índice del cálculo del estado y el único de una publicada por tipo, y cada clave foránea compuesta (salvo la de autoría) tiene un índice cuyo prefijo es (tenant_id, columna)', function (): void {
    $indexes = collect(DB::connection('pgsql_owner')->select(
        "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ANY (?)", ['{'.implode(',', IMAGE_CONSENT_TABLES).'}']
    ))->pluck('indexdef', 'indexname');

    expect($indexes['image_consent_decisions_state_idx'])->toContain('(tenant_id, student_person_id, level, guardian_id, occurred_at DESC, id DESC)')
        ->and($indexes['terms_versions_tenant_kind_published_unique'])->toContain('UNIQUE')->toContain("(status = 'publicada'")->toContain('deleted_at IS NULL')
        ->and($indexes['terms_versions_tenant_kind_number_unique'])->toContain('(tenant_id, kind, version_number)');

    // db-reviewer B-1: el índice (tenant_id, student_person_id) de la FK sería redundante con el del estado, que ya empieza por él.
    expect($indexes->keys()->all())->not->toContain(TenantMigration::indexName('image_consent_decisions', 'student_person_id'));

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
        SQL, ['{'.implode(',', IMAGE_CONSENT_TABLES).'}']);

    expect($uncovered)->toBe([]);
});

test('ADR-062 §5.1 AR-16 datos.md §1.8: title_translations y body_translations son jsonb NOT NULL con el CHECK del ayudante, que rechaza un objeto vacío, un idioma fuera del vocabulario y un valor que no es cadena', function (): void {
    [$tenant] = Fam::setup('is-i18n');

    foreach (['title_translations', 'body_translations'] as $column) {
        $info = DB::connection('pgsql_owner')->selectOne("SELECT data_type, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'terms_versions' AND column_name = ?", [$column]);
        $check = DB::connection('pgsql_owner')->selectOne('SELECT count(*) AS n FROM pg_constraint WHERE conname = ?', ["terms_versions__{$column}_check"]);

        expect($info->data_type)->toBe('jsonb')->and($info->is_nullable)->toBe('NO')->and((int) $check->n)->toBe(1);
    }

    $insert = static fn (array $overrides) => static fn () => DB::connection('pgsql')->transaction(static fn () => DB::connection('pgsql')->table('terms_versions')->insert($overrides + [
        'tenant_id' => app(TenantContext::class)->tenantId(), 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'kind' => 'consentimiento_imagen_web', 'version_number' => 1,
        'title_translations' => '{"es-ES":"t"}', 'body_translations' => '{"es-ES":"c"}', 'status' => 'borrador', 'requires_reacceptance' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    app(TenantContext::class)->runFor($tenant->id, function () use ($insert): void {
        expect($insert(['title_translations' => '{}']))->toThrow(QueryException::class, 'terms_versions__title_translations_check')
            ->and($insert(['body_translations' => '{"xx":"c"}']))->toThrow(QueryException::class, 'terms_versions__body_translations_check')
            ->and($insert(['body_translations' => '{"es-ES":5}']))->toThrow(QueryException::class, 'terms_versions__body_translations_check')
            ->and($insert([]))->not->toThrow(QueryException::class);
    });
});

test('datos.md §1.8 §1.6: los CHECK de enumerados y de coherencia — kind, status, published_at, version_number, level, decision, channel y shown_locale — los hace cumplir el motor', function (): void {
    $f = Fam::consentFamily('is-checks', twoGuardians: false);
    $first = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    $template = app(TenantContext::class)->runFor($f['tenant']->id, fn () => (array) DB::table('image_consent_decisions')->first());
    unset($template['id']);

    $version = fn (array $o) => fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('terms_versions')->insert($o + [
        'tenant_id' => $f['tenant']->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'kind' => 'consentimiento_imagen_web', 'version_number' => 50,
        'title_translations' => '{"es-ES":"t"}', 'body_translations' => '{"es-ES":"c"}', 'status' => 'borrador', 'requires_reacceptance' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $decision = fn (array $o) => fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('image_consent_decisions')->insert($o + ['public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZY'] + $template));

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($version, $decision): void {
        expect($version(['kind' => 'otro']))->toThrow(QueryException::class, 'terms_versions_kind_check')
            ->and($version(['status' => 'otro', 'published_at' => now()]))->toThrow(QueryException::class, 'terms_versions_status_check')
            ->and($version(['version_number' => 0]))->toThrow(QueryException::class, 'terms_versions_version_number_check')
            // borrador con fecha de publicación, y publicada sin ella: incoherente.
            ->and($version(['published_at' => now()]))->toThrow(QueryException::class, 'terms_versions_published_at_check')
            ->and($version(['status' => 'retirada']))->toThrow(QueryException::class, 'terms_versions_published_at_check')
            // la re-aceptación solo existe en condiciones de uso (kind = condiciones_uso sí la admite: 1.14-c).
            ->and($version(['requires_reacceptance' => true]))->toThrow(QueryException::class, 'terms_versions_reacceptance_kind_check')
            ->and($version(['kind' => 'condiciones_uso', 'requires_reacceptance' => true]))->not->toThrow(QueryException::class)
            ->and($decision(['level' => 'twitter']))->toThrow(QueryException::class, 'image_consent_decisions_level_check')
            ->and($decision(['decision' => 'pendiente']))->toThrow(QueryException::class, 'image_consent_decisions_decision_check')
            ->and($decision(['channel' => 'correo']))->toThrow(QueryException::class, 'image_consent_decisions_channel_check')
            ->and($decision(['shown_locale' => 'it']))->toThrow(QueryException::class, 'image_consent_decisions_shown_locale_check')
            ->and($decision(['shown_locale' => null]))->not->toThrow(QueryException::class);
    });

    expect($first->json('decision.shown_locale'))->toBe('es-ES');
});

test('datos.md §1.6 INV-001 ADR-033 §5: la RLS oculta las decisiones de otro centro y rechaza escribir con un tenant_id ajeno; una decisión de otro centro no puede apuntar a un tutor, alumno ni versión de este', function (): void {
    $a = Fam::consentFamily('is-rls-a', twoGuardians: false);
    $b = Fam::consentFamily('is-rls-b', twoGuardians: false);
    $row = app(TenantContext::class)->runFor($b['tenant']->id, fn () => (array) DB::table('terms_versions')->first());

    // Una decisión del centro A que referencia la versión del B: la clave foránea compuesta la rechaza (23503).
    $fk = null;

    try {
        app(TenantContext::class)->runFor($a['tenant']->id, function () use ($a, $row): void {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('image_consent_decisions')->insert([
                'tenant_id' => $a['tenant']->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'student_person_id' => $a['student']->id, 'level' => 'web', 'decision' => 'autorizo',
                'guardian_id' => app(GuardianService::class)->guardianIdForPerson($a['r1']->person_id), 'recorded_by' => $a['r1']->id, 'channel' => 'portal',
                'consent_text_version_id' => $row['id'], 'occurred_at' => now(),
            ]));
        });
    } catch (QueryException $e) {
        $fk = $e->errorInfo[0] ?? null;
    }

    expect($fk)->toBe('23503');

    // Con el contexto de B no se ve nada de A, y escribir con el tenant_id de A es 42501.
    $state = null;

    try {
        app(TenantContext::class)->runFor($b['tenant']->id, function () use ($a, $b): void {
            expect(DB::table('image_consent_decisions')->where('tenant_id', $a['tenant']->id)->count())->toBe(0);

            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('image_consent_decisions')->insert([
                'tenant_id' => $a['tenant']->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZY', 'student_person_id' => $b['student']->id, 'level' => 'web', 'decision' => 'no_autorizo',
                'guardian_id' => 1, 'recorded_by' => 1, 'channel' => 'portal', 'occurred_at' => now(),
            ]));
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('42501');
});
