<?php

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, datos.md §3, funcional.md §12.2.1. `dual_authorizations` es
 * infraestructura de esquema en 1.6: la migración construye la tabla y
 * la restricción de aprobador distinto (docblock de la migración,
 * confirmado también en `DualAuthorization` y en `ExpireDualAuthorizations`)
 * — los *endpoints* que la disparan (`POST /dual-authorizations/...`,
 * api.md §2.8) llegan con la primera operación real del vocabulario
 * cerrado (`tenant.eliminar` en 1.6b, `modulo.descontratar_masivo` en
 * 1.6c). Hoy no hay ningún consumidor que cree solicitudes.
 *
 * Por eso, de §13.5, **solo CA-BO-062** es verificable en 1.6: es la
 * única que el propio criterio dice que se comprueba "escribiendo por
 * SQL directo", sin pasar por ningún *endpoint*. El resto de §13.5
 * exige un flujo real de negocio que no existe todavía en este sub-paso
 * y queda deferido:
 *
 * - CA-BO-061: exige un intento real de eliminar un tenant (1.6b).
 * - CA-BO-063: exige el servicio de ejecución que usa el payload
 *   congelado (1.6b/1.6c, no especificado como pieza de 1.6).
 * - CA-BO-064: exige `POST /dual-authorizations/{id}/approval`, que no
 *   existe hasta 1.6b.
 * - CA-BO-065: exige `POST /tenants/{id}/transitions` con
 *   `confirmation_name` (1.6b).
 * - CA-BO-066: exige la desactivación masiva de módulos (1.6c).
 * - CA-BO-067: exige el ciclo completo solicitud→aprobación→ejecución,
 *   que no se puede completar sin una acción real que ejecutar (1.6b).
 *
 * Se deja constancia explícita en vez de marcar estos seis como
 * cubiertos: no es una decisión de este agente relajar su alcance, es
 * el resultado de que 1.6 no construye la superficie que los hace
 * observables (funcional.md §12.2.1, ya verificado y documentado en el
 * código de la migración de `dual_authorizations`).
 */
afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('dual_authorizations')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
});

function daCreateAdmin(): PlatformAdmin
{
    return PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'password' => 'hash',
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);
}

// CA-BO-062: quien aprueba no puede ser quien solicita — lo garantiza un
// CHECK de base de datos, comprobado escribiendo por SQL directo, no por
// la API (RN-BO-19, datos.md §3.1).
test('CA-BO-062: la base de datos rechaza que el aprobador sea el mismo que el solicitante', function (): void {
    $admin = daCreateAdmin();

    $id = DB::connection('pgsql_platform')->table('dual_authorizations')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'action' => 'tenant.eliminar',
        'payload' => json_encode(['tenant_public_id' => (string) Str::ulid()]),
        'payload_fingerprint' => hash('sha256', 'x'),
        'reason' => 'motivo de prueba',
        'requested_by' => $admin->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'pendiente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::connection('pgsql_platform')->table('dual_authorizations')->where('id', $id)->update([
        'status' => 'aprobada',
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]))->toThrow(QueryException::class);
});

// Restricción complementaria de datos.md §3.1: un aprobador DISTINTO sí
// se acepta.
test('la base de datos acepta que el aprobador sea distinto del solicitante', function (): void {
    $requester = daCreateAdmin();
    $approver = daCreateAdmin();

    $id = DB::connection('pgsql_platform')->table('dual_authorizations')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'action' => 'tenant.eliminar',
        'payload' => json_encode(['tenant_public_id' => (string) Str::ulid()]),
        'payload_fingerprint' => hash('sha256', 'x'),
        'reason' => 'motivo de prueba',
        'requested_by' => $requester->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'pendiente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('pgsql_platform')->table('dual_authorizations')->where('id', $id)->update([
        'status' => 'aprobada',
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    $row = DB::connection('pgsql_platform')->table('dual_authorizations')->where('id', $id)->first();
    expect($row->status)->toBe('aprobada');
});

// datos.md §3.2: no hay aprobación sin aprobador ni fecha de aprobación.
test('la base de datos rechaza status = aprobada sin approved_by/approved_at', function (): void {
    $admin = daCreateAdmin();

    expect(fn () => DB::connection('pgsql_platform')->table('dual_authorizations')->insert([
        'public_id' => (string) Str::ulid(),
        'action' => 'tenant.eliminar',
        'payload' => json_encode(['tenant_public_id' => (string) Str::ulid()]),
        'payload_fingerprint' => hash('sha256', 'x'),
        'reason' => 'motivo de prueba',
        'requested_by' => $admin->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'aprobada',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// datos.md §3.2: una sola solicitud viva por (action, payload_fingerprint).
test('la base de datos rechaza dos solicitudes pendientes con la misma acción y huella', function (): void {
    $admin = daCreateAdmin();
    $fingerprint = hash('sha256', 'misma-operacion');

    DB::connection('pgsql_platform')->table('dual_authorizations')->insert([
        'public_id' => (string) Str::ulid(),
        'action' => 'tenant.eliminar',
        'payload' => json_encode(['x' => 1]),
        'payload_fingerprint' => $fingerprint,
        'reason' => 'motivo',
        'requested_by' => $admin->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'pendiente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::connection('pgsql_platform')->table('dual_authorizations')->insert([
        'public_id' => (string) Str::ulid(),
        'action' => 'tenant.eliminar',
        'payload' => json_encode(['x' => 2]),
        'payload_fingerprint' => $fingerprint,
        'reason' => 'motivo',
        'requested_by' => $admin->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'pendiente',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
