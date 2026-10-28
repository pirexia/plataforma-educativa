<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-003 (1.14-c), docs/modulos/REQ-FAM-UNIT/datos.md §1.9, §3, §4, §5, §6, ADR-029, ADR-033, ADR-034 §6, ADR-067
// §5.7, INV-001, INV-015: esquema real de `terms_acceptances`, con base de datos real (ADR-033 §10).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('datos.md §1.9 §5: terms_acceptances existe con RLS forzada, public_id char(26), tenant_id, UNIQUE (tenant_id, id), de solo anexar y sin academic_year_id', function (): void {
    $owner = DB::connection('pgsql_owner');
    $rls = $owner->selectOne("SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = 'terms_acceptances'::regclass");
    $publicId = $owner->selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = 'terms_acceptances'::regclass AND attname = 'public_id'");
    $unique = $owner->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'terms_acceptances' AND indexdef LIKE 'CREATE UNIQUE INDEX % (tenant_id, id)'");
    $policy = $owner->selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = 'terms_acceptances' AND policyname = 'tenant_isolation'");
    $columns = array_column($owner->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'terms_acceptances'"), 'column_name');

    expect($rls->on)->toBeTrue()->and($rls->forced)->toBeTrue()->and((int) $policy->n)->toBe(1)->and((int) $unique->n)->toBe(1)
        ->and($publicId->t)->toBe('character(26)')
        ->and($columns)->toContain('tenant_id', 'user_id', 'terms_version_id', 'shown_locale', 'accepted_at', 'ip_address', 'user_agent')
        ->and($columns)->not->toContain('academic_year_id', 'deleted_at', 'updated_at', 'updated_by', 'created_by');

    foreach (['plataforma_app', 'plataforma_platform'] as $role) {
        foreach (['UPDATE', 'DELETE'] as $privilege) {
            expect($owner->selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'terms_acceptances', $privilege])->ok)->toBeFalse("{$role} conserva {$privilege} sobre terms_acceptances");
        }
    }

    expect($owner->selectOne("SELECT has_table_privilege('plataforma_app', 'terms_acceptances', 'INSERT') AS ok")->ok)->toBeTrue();
});

test('datos.md §1.9 ADR-029 AR-04: ninguna columna es varchar ni timestamp sin zona, el instante es timestamptz y la IP inet; las FK no tienen acción referencial y hay índice del muro', function (): void {
    $owner = DB::connection('pgsql_owner');
    $types = collect($owner->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'terms_acceptances'"))->pluck('data_type', 'column_name');

    expect($types['accepted_at'])->toBe('timestamp with time zone')->and($types['ip_address'])->toBe('inet')->and($types['shown_locale'])->toBe('text')->and($types->values()->all())->not->toContain('character varying', 'timestamp without time zone');

    $actions = $owner->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = 'terms_acceptances'::regclass AND (confdeltype IN ('c', 'n', 'd') OR confupdtype IN ('c', 'n', 'd'))");
    expect($actions)->toBe([]);

    $indexes = collect($owner->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'terms_acceptances'"))->pluck('indexdef', 'indexname');
    expect($indexes['terms_acceptances_user_accepted_idx'])->toContain('(tenant_id, user_id, accepted_at DESC)')
        ->and($indexes['terms_acceptances_tenant_user_version_unique'])->toContain('UNIQUE')->toContain('(tenant_id, user_id, terms_version_id)');

    $uncovered = $owner->select(<<<'SQL'
        SELECT c.conname FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid = 'terms_acceptances'::regclass
          AND NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey)
        SQL);
    expect($uncovered)->toBe([]);
});

test('RN-FAM-UNIT-64 RN-FAM-UNIT-65 datos.md §1.9: el motor rechaza una segunda aceptación de la misma versión por el mismo usuario y un shown_locale fuera del vocabulario; la FK a la versión exige misma pertenencia al centro', function (): void {
    $f = Fam::termsFamily('ts-unique');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    $version = Fam::publishTerms($tenant, $admin);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertCreated();

    app(TenantContext::class)->runFor($tenant->id, function () use ($tutor, $version): void {
        $versionId = DB::table('terms_versions')->where('public_id', $version)->value('id');
        $insert = static fn (array $overrides) => static fn () => DB::connection('pgsql')->transaction(static fn () => DB::connection('pgsql')->table('terms_acceptances')->insert($overrides + [
            'tenant_id' => app(TenantContext::class)->tenantId(), 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZY', 'user_id' => $tutor->id,
            'terms_version_id' => $versionId, 'shown_locale' => 'es-ES', 'accepted_at' => now(),
        ]));

        expect($insert([]))->toThrow(QueryException::class, 'terms_acceptances_tenant_user_version_unique')
            ->and($insert(['shown_locale' => 'xx', 'terms_version_id' => 999999]))->toThrow(QueryException::class);
    });
});
