<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, ADR-047 §4.3/§4.4, datos.md §11, §12.1. "Un REVOKE que no
 * se prueba no existe" (ADR-045 §4.4). Verificado por privilegios de
 * motor, nunca por la API — un test que pasara por el controlador
 * comprobaría el `where`, no el `GRANT`.
 */

// admin_action_logs y tenant_lifecycle_events están bajo FORCE ROW LEVEL
// SECURITY sin ninguna política permisiva de escritura (ADR-047 §4.3):
// eso bloquea INSERT/UPDATE/DELETE incluso para pgsql_owner (el
// propietario del esquema, sin BYPASSRLS) — es exactamente el cierre
// que la migración documenta, "ni siquiera plataforma_owner puede
// escribirlas". TRUNCATE no pasa por RLS y es el único camino de
// limpieza en tests.
//
// En `beforeEach`, no en `afterEach`: `TestCase::$connectionsToTransact`
// mantiene la transacción de la conexión `pgsql` ABIERTA durante todo
// el test (`DatabaseTransactions` la revierte en el `tearDown()` de
// PHPUnit, que corre DESPUÉS de los `afterEach` de Pest). Un `TRUNCATE`
// en `afterEach` pide un bloqueo exclusivo sobre una tabla que esa
// transacción, todavía abierta, sigue reteniendo — bloqueo real,
// encontrado al ejecutar esta suite (`TRUNCATE` colgado esperando el
// lock). Limpiar al EMPEZAR el siguiente test, cuando la transacción
// del anterior ya se revirtió del todo, no tiene ese problema.
// Limpia también `tenants`, aquí y no en `afterEach`: `admin_action_logs_
// affected_tenant_fk`/`tenant_lifecycle_events_affected_tenant_fk` (sin
// CASCADE, a propósito — RN-BO-31, la referencia sobrevive en la
// auditoría aunque el tenant se purgue en producción, `ADR-004`)
// bloquearían el DELETE de `tenants` mientras quedara una fila del test
// ANTERIOR sin truncar. Truncando antes de borrar tenants, en el mismo
// paso, el orden queda garantizado.
beforeEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs, tenant_lifecycle_events');
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// El último test del fichero deja sus propias filas sin limpiar (su
// `afterEach` tendría el mismo problema de bloqueo que motivó mover la
// limpieza a `beforeEach`): `afterAll()` corre una sola vez, fuera de
// cualquier transacción de test todavía abierta, y es el sitio seguro
// para la última pasada — sin ella, un tenant de este fichero quedaría
// permanentemente sin borrar y bloquearía el `DELETE FROM tenants` de
// limpieza de otros ficheros de la suite.
afterAll(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs, tenant_lifecycle_events');
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * `TestCase::$connectionsToTransact` envuelve TODA la conexión `pgsql`
 * en una única transacción por test (`DatabaseTransactions`). Un error
 * de privilegio esperado dentro de esa transacción la deja abortada
 * para el resto del test — cualquier consulta posterior, aunque fuera a
 * funcionar, falla con "current transaction is aborted". Un `SAVEPOINT`
 * por intento, con `ROLLBACK TO SAVEPOINT` tras el fallo esperado,
 * permite comprobar varias columnas prohibidas en el mismo test sin
 * salir de la transacción exterior que sostiene el aislamiento.
 */
function expectPgsqlPrivilegeErrorThenRecover(Closure $attempt, string $message): void
{
    DB::connection('pgsql')->statement('SAVEPOINT pgsql_privilege_check');

    try {
        expect($attempt)->toThrow(QueryException::class, null, $message);
    } finally {
        DB::connection('pgsql')->statement('ROLLBACK TO SAVEPOINT pgsql_privilege_check');
    }
}

// CA-BO-018: plataforma_app no puede ni leer platform_sessions.
test('CA-BO-018: plataforma_app no puede leer platform_sessions', function (): void {
    DB::connection('pgsql_platform')->table('platform_sessions')->insert([
        'id' => Str::random(40),
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => time(),
    ]);

    expect(fn () => DB::connection('pgsql')->table('platform_sessions')->count())
        ->toThrow(QueryException::class);

    DB::connection('pgsql_platform')->table('platform_sessions')->delete();
});

// ADR-047 §4.4, §11 punto 1: el REVOKE de tabla NO cubre la secuencia.
// Las trece (once en 1.6) tablas nuevas de bigserial, salvo platform_sessions
// (PK text, sin secuencia).
test('CA-BO-098/§12.1: plataforma_app no tiene nextval() sobre ninguna secuencia de las tablas nuevas de 1.6', function (): void {
    $sequences = [
        'platform_admins_id_seq',
        'platform_admin_roles_id_seq',
        'platform_admin_mfa_factors_id_seq',
        'platform_admin_mfa_recovery_codes_id_seq',
        'platform_admin_mfa_challenges_id_seq',
        'platform_ip_allowlist_id_seq',
        'dual_authorizations_id_seq',
        'platform_admin_sessions_id_seq',
        'admin_action_logs_id_seq',
        'tenant_lifecycle_events_id_seq',
    ];

    foreach ($sequences as $sequence) {
        expectPgsqlPrivilegeErrorThenRecover(
            fn () => DB::connection('pgsql')->selectOne("SELECT nextval('{$sequence}')"),
            "secuencia {$sequence}: plataforma_app no debería poder nextval()",
        );
    }
});

test('CA-BO-098: plataforma_app no puede INSERT en admin_action_logs', function (): void {
    expect(fn () => DB::connection('pgsql')->table('admin_action_logs')->insert([
        'public_id' => (string) Str::ulid(),
        'occurred_at' => now(),
        'actor_type' => 'system',
        'subject_type' => 'platform',
        'action' => 'acceso.concedido',
    ]))->toThrow(QueryException::class);
});

test('CA-BO-099: plataforma_app no puede leer columnas fuera del GRANT de admin_action_logs', function (): void {
    $tenant = Tenant::factory()->create();
    $publicId = (string) Str::ulid();

    DB::connection('pgsql_platform')->table('admin_action_logs')->insert([
        'public_id' => $publicId,
        'occurred_at' => now(),
        'actor_type' => 'system',
        'affected_tenant_id' => $tenant->id,
        'subject_type' => 'platform',
        'action' => 'acceso.concedido',
        'reason' => 'motivo interno',
    ]);

    app(TenantContext::class)->enter($tenant->id);

    // public_id, no id, en el WHERE (datos.md §4.3): así el fallo
    // esperado viene de la columna prohibida de la lista, no de
    // referenciar `id` en la cláusula.
    foreach (['reason', 'actor_platform_admin_id', 'ip_address', 'user_agent', 'context', 'changes'] as $column) {
        expectPgsqlPrivilegeErrorThenRecover(
            fn () => DB::connection('pgsql')->table('admin_action_logs')->select($column)->where('public_id', $publicId)->first(),
            "columna {$column} no debería estar concedida a plataforma_app",
        );
    }

    expectPgsqlPrivilegeErrorThenRecover(
        fn () => DB::connection('pgsql')->table('admin_action_logs')->where('public_id', $publicId)->first(),
        'SELECT * no debería funcionar para plataforma_app',
    );

    app(TenantContext::class)->leave();
    // Solo-anexión permanente (RN-BO-29): la limpieza de test pasa por
    // pgsql_owner, no por pgsql_platform (que ya no tiene DELETE).
});

test('CA-BO-100: plataforma_app ve solo las filas de su tenant en admin_action_logs, y ninguna sin contexto', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // public_id, no id: plataforma_app no tiene id concedido (datos.md
    // §4.3), así que el desempate de esta prueba también tiene que
    // apoyarse en public_id, igual que el índice de §4.5.
    $publicIdA = (string) Str::ulid();
    $publicIdB = (string) Str::ulid();
    $publicIdGlobal = (string) Str::ulid();

    DB::connection('pgsql_platform')->table('admin_action_logs')->insert([
        'public_id' => $publicIdA, 'occurred_at' => now(), 'actor_type' => 'system',
        'affected_tenant_id' => $tenantA->id, 'subject_type' => 'platform', 'action' => 'acceso.concedido',
    ]);
    DB::connection('pgsql_platform')->table('admin_action_logs')->insert([
        'public_id' => $publicIdB, 'occurred_at' => now(), 'actor_type' => 'system',
        'affected_tenant_id' => $tenantB->id, 'subject_type' => 'platform', 'action' => 'acceso.concedido',
    ]);
    DB::connection('pgsql_platform')->table('admin_action_logs')->insert([
        'public_id' => $publicIdGlobal, 'occurred_at' => now(), 'actor_type' => 'system',
        'affected_tenant_id' => null, 'subject_type' => 'platform', 'action' => 'acceso.concedido',
    ]);

    $context = app(TenantContext::class);
    $context->enter($tenantA->id);
    $visiblePublicIds = DB::connection('pgsql')->table('admin_action_logs')->pluck('public_id')->all();
    expect($visiblePublicIds)->toContain($publicIdA);
    expect($visiblePublicIds)->not->toContain($publicIdB);
    expect($visiblePublicIds)->not->toContain($publicIdGlobal);
    $context->leave();

    // Sin contexto de tenant: app.current_tenant_id() es nula, y
    // "affected_tenant_id = NULL" no es verdadero para ninguna fila.
    expect(DB::connection('pgsql')->table('admin_action_logs')->count())->toBe(0);
});

test('CA-BO-101: plataforma_app no puede INSERT en tenant_lifecycle_events', function (): void {
    $tenant = Tenant::factory()->create();

    expectPgsqlPrivilegeErrorThenRecover(
        fn () => DB::connection('pgsql')->table('tenant_lifecycle_events')->insert([
            'public_id' => (string) Str::ulid(),
            'affected_tenant_id' => $tenant->id,
            'to_status' => 'activo',
            'reason' => 'motivo',
            'occurred_at' => now(),
        ]),
        'plataforma_app no debería poder INSERT en tenant_lifecycle_events',
    );

    expectPgsqlPrivilegeErrorThenRecover(
        fn () => DB::connection('pgsql')->selectOne("SELECT nextval('tenant_lifecycle_events_id_seq')"),
        'plataforma_app no debería poder nextval() sobre tenant_lifecycle_events_id_seq',
    );
});

test('CA-BO-102/103: plataforma_app solo ve las seis columnas concedidas de tenant_lifecycle_events, y solo su propio tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $publicId = (string) Str::ulid();

    DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->insert([
        'public_id' => $publicId,
        'affected_tenant_id' => $tenant->id,
        'to_status' => 'activo',
        'reason' => 'motivo interno',
        'occurred_at' => now(),
    ]);

    app(TenantContext::class)->enter($tenant->id);

    // public_id, no id: plataforma_app no tiene id concedido (datos.md
    // §5.3).
    foreach (['reason', 'performed_by', 'dual_authorization_id'] as $column) {
        expectPgsqlPrivilegeErrorThenRecover(
            fn () => DB::connection('pgsql')->table('tenant_lifecycle_events')->select($column)->where('public_id', $publicId)->first(),
            "columna {$column} no debería estar concedida a plataforma_app",
        );
    }

    $row = DB::connection('pgsql')->table('tenant_lifecycle_events')
        ->select(['public_id', 'affected_tenant_id', 'from_status', 'to_status', 'occurred_at', 'grace_period_ends_at'])
        ->where('public_id', $publicId)->first();
    expect($row)->not->toBeNull();

    app(TenantContext::class)->leave();
});

// CA-BO-075 (parcial, IsolationBatteryTest #8 cubre el resto): ninguna de
// las tablas de 1.6 lleva `tenant_id`.
test('CA-BO-075: ninguna de las once tablas nuevas de 1.6 tiene una columna tenant_id', function (): void {
    $tables = [
        'platform_admins', 'platform_admin_roles', 'platform_admin_mfa_factors',
        'platform_admin_mfa_recovery_codes', 'platform_admin_mfa_challenges', 'platform_ip_allowlist',
        'dual_authorizations', 'platform_sessions', 'platform_admin_sessions',
        'admin_action_logs', 'tenant_lifecycle_events',
    ];

    foreach ($tables as $table) {
        $hasTenantId = DB::selectOne(
            "SELECT 1 AS found FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'tenant_id'",
            [$table]
        );

        expect($hasTenantId)->toBeNull("tabla `{$table}`: tiene una columna tenant_id, prohibido por ADR-047 §4.2");
    }
});

// CA-BO-021, RN-BO-29: admin_action_logs / tenant_lifecycle_events son
// append-only: ni siquiera plataforma_platform puede UPDATE/DELETE.
test('CA-BO-021: admin_action_logs y tenant_lifecycle_events rechazan UPDATE y DELETE también para plataforma_platform', function (): void {
    $tenant = Tenant::factory()->create();

    $logId = DB::connection('pgsql_platform')->table('admin_action_logs')->insertGetId([
        'public_id' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_type' => 'system',
        'subject_type' => 'platform', 'action' => 'acceso.concedido',
    ]);

    expect(fn () => DB::connection('pgsql_platform')->table('admin_action_logs')->where('id', $logId)->update(['reason' => 'x']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::connection('pgsql_platform')->table('admin_action_logs')->where('id', $logId)->delete())
        ->toThrow(QueryException::class);

    $eventId = DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->insertGetId([
        'public_id' => (string) Str::ulid(), 'affected_tenant_id' => $tenant->id,
        'to_status' => 'activo', 'reason' => 'motivo', 'occurred_at' => now(),
    ]);

    expect(fn () => DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('id', $eventId)->update(['reason' => 'x']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('id', $eventId)->delete())
        ->toThrow(QueryException::class);

    // Limpieza vía pgsql_owner (RN-BO-29: ni plataforma_app ni
    // plataforma_platform pueden hacerlo, y las dos comprobaciones de
    // arriba ya lo demostraron) — si no, el tenant creado en este test
    // queda referenciado para siempre y bloquea el DELETE FROM tenants
    // de limpieza de otros ficheros de test.
});
