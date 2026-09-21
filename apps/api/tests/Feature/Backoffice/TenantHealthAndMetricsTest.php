<?php

use App\Models\ModuleSubscription;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Domain\PlatformCapability;
use App\Modules\Backoffice\Domain\PlatformCapabilityMap;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * REQ-BO-004/REQ-BO-006 reducidos, sub-paso `1.6d`. `RN-BO-83` a
 * `RN-BO-98`, `CA-BO-149` a `CA-BO-166`. `boPlatformHost()`,
 * `boAllowCurrentTestIp()`, `boCreateEnrolledAdmin()`,
 * `boReauthenticatedSessionCookie()`, `boWithReauthenticatedCookie()`
 * están definidas en `PlatformAdminManagementTest.php`;
 * `boSensitiveClient()` en `TenantLifecycleTest.php`.
 */

/**
 * Fixture de dependencias para `dependency_inconsistencies`
 * (`api.md §2.10.1` punto 2, `CA-BO-161` no la usa; la usa el test de
 * `TenantHealthController::show()`).
 */
class HealthFixtureBaseProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'bo-health-base', 'name_key' => 'modules.sync_registry_test', 'phase' => '0'];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class HealthFixtureDependentProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'bo-health-dep', 'name_key' => 'modules.sync_registry_test', 'phase' => '0', 'depends_on' => ['bo-health-base']];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

/** @var list<string> */
const HEALTH_MODULE_FIXTURE_CODES = ['bo-health-base', 'bo-health-dep'];

function registerHealthModuleFixtures(): void
{
    foreach (HEALTH_MODULE_FIXTURE_CODES as $code) {
        DB::connection('pgsql_owner')->table('modules')->updateOrInsert(
            ['code' => $code],
            ['name_key' => 'modules.sync_registry_test', 'phase' => '0', 'retired_at' => null],
        );
    }

    app()->register(new HealthFixtureBaseProvider(app()));
    app()->register(new HealthFixtureDependentProvider(app()));
}

beforeEach(function (): void {
    Mail::fake();
    $this->artisan('platform:sync-registry')->run();
    boAllowCurrentTestIp();
});

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_owner')->statement('TRUNCATE tenant_lifecycle_events');
    DB::connection('pgsql_platform')->table('module_subscriptions')
        ->whereIn('module_code', [...HEALTH_MODULE_FIXTURE_CODES, 'auth', 'core', 'bo-health-retired'])
        ->delete();
    DB::connection('pgsql_owner')->table('modules')->where('code', 'bo-health-retired')->delete();
    DB::connection('pgsql_platform')->table('dual_authorizations')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_challenges')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_recovery_codes')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_invitations')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    // `pgsql_platform`, no la conexión por defecto: algunos tests de este
    // fichero (CA-BO-158a/b/c) dejan deliberadamente la transacción de
    // `pgsql` abortada (SELECT/UPDATE/DELETE denegados sobre
    // failed_jobs) — un DELETE por esa misma conexión aquí fallaría en
    // cascada por "current transaction is aborted", no por su propio
    // motivo.
    DB::connection('pgsql_platform')->table('jobs')->delete();
    DB::connection('pgsql_platform')->table('failed_jobs')->delete();
    // Convención universal del resto de la suite (todos los demás
    // ficheros de Backoffice y de Core la llevan en su afterEach): sin
    // ella, las entradas que este fichero deja en Redis (resolución de
    // tenant, disponibilidad de módulo) sobreviven al proceso y quedan
    // disponibles para el test que se ejecute a continuación.
    Cache::flush();
});

/**
 * Inserta directamente una fila de `failed_jobs` con un `payload` de
 * forma realista (misma clave `tenant_id` en la raíz que
 * `Queue::createPayloadUsing()` produciría, `ADR-033 §8`) y una
 * `exception` con el formato real de `Throwable::__toString()`.
 */
function boInsertFailedJob(?int $tenantId, string $displayName = 'App\\Fake\\Job', ?string $exceptionMessage = null, ?string $serializedCommand = null): string
{
    $uuid = (string) Str::uuid();
    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => $displayName,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 1,
        // Un payload real de un job de correo lleva el destinatario aquí
        // dentro (RN-BO-84): esto, y no el mensaje de la excepción, es lo
        // que jamás debe cruzar a la respuesta.
        'data' => ['commandName' => $displayName, 'command' => $serializedCommand ?? 'serialized-fixture'],
        'tenant_id' => $tenantId,
    ], JSON_THROW_ON_ERROR);

    $message = $exceptionMessage ?? 'SQLSTATE[23505]: Unique violation: 7 ERROR';
    $exception = "Illuminate\\Database\\QueryException: {$message} in /var/www/app/Foo.php:42\nStack trace:\n#0 {main}";

    DB::connection('pgsql_platform')->table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => $exception,
        'failed_at' => now(),
    ]);

    return $uuid;
}

// ---------------------------------------------------------------------
// Ficha de salud (CA-BO-149 a CA-BO-153, CA-BO-158)
// ---------------------------------------------------------------------

test('CA-BO-149: un centro sin trabajos fallidos devuelve 0 medido y omite los bloques sin fuente', function (): void {
    $tenant = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/health");

    $response->assertOk();
    expect($response->json('jobs.queued'))->toBe(0);
    expect($response->json('jobs.failed'))->toBe(0);
    expect($response->json('jobs.failed_recent'))->toBe(0);
    expect($response->json('jobs'))->not->toHaveKey('last_failed_at');
    expect($response->json())->not->toHaveKey('resources');
    expect($response->json())->not->toHaveKey('certificate');
    expect($response->json())->not->toHaveKey('connectors');
    expect($response->json())->not->toHaveKey('platform_incident');
});

test('CA-BO-150: ni la ficha de salud ni el listado de fallidos devuelven el payload, la traza ni el correo de una persona embebido en él', function (): void {
    $tenant = Tenant::factory()->create();
    // El correo de la persona invitada vive DENTRO del payload serializado
    // (`data.command`), exactamente como en un SendInvitationEmail real —
    // eso, y no el mensaje de la excepción (que sí se devuelve,
    // `OPEN-BO-22`), es lo que RN-BO-84 prohíbe que cruce a la respuesta.
    boInsertFailedJob(
        $tenant->id,
        'App\\Modules\\Core\\Infrastructure\\Jobs\\SendInvitationEmail',
        'SQLSTATE[23505]: Unique violation: 7 ERROR',
        serialize(['email' => 'persona@example.com', 'givenName' => 'Ana']),
    );

    [$admin, $secret] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($admin, 'platform');

    $health = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/health")->assertOk();
    $list = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs")->assertOk();

    foreach ([json_encode($health->json()), json_encode($list->json())] as $body) {
        expect($body)->not->toContain('payload');
        expect($body)->not->toContain('Stack trace');
        expect($body)->not->toContain('persona@example.com');
        expect($body)->not->toContain('serialized-fixture');
    }

    expect($list->json('data.0.job_class'))->toBe('App\\Modules\\Core\\Infrastructure\\Jobs\\SendInvitationEmail');
    expect($list->json('data.0.exception_class'))->toBe('Illuminate\\Database\\QueryException');
    // OPEN-BO-22 (resuelta: sí): el MENSAJE de la excepción sí se
    // devuelve, como excepción consciente a RN-BO-33.
    expect($list->json('data.0.exception_message'))->toBe('SQLSTATE[23505]: Unique violation: 7 ERROR');
});

test('CA-BO-151: la versión y las migraciones vienen marcadas de alcance global e idénticas para dos centros', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($admin, 'platform');

    $responseA = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/health")->assertOk();
    $responseB = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantB->public_id}/health")->assertOk();

    expect($responseA->json('platform'))->toBe($responseB->json('platform'));
    expect($responseA->json('platform.version'))->not->toBeEmpty();
});

test('CA-BO-152: el reintento reencola el payload literal, con el tenant_id original intacto y los intentos reiniciados', function (): void {
    $tenantA = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    // RN-BO-85: lo que hace que el trabajo vuelva a correr dentro del
    // tenant correcto es que el *worker* lea `tenant_id` de la raíz del
    // payload reencolado — es exactamente ese campo, y sólo ése, lo que
    // este test comprueba que sobrevive intacto al camino de reintento
    // (localizar, reencolar, borrar, auditar), sin recomponerse.
    $originalPayload = [
        'uuid' => (string) Str::uuid(),
        'displayName' => 'Fixture\\JobA',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'Fixture\\JobA', 'command' => 'serialized-fixture'],
        'attempts' => 3,
        'tenant_id' => $tenantA->id,
    ];
    $uuid = $originalPayload['uuid'];

    DB::connection('pgsql_platform')->table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode($originalPayload, JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: fallo de prueba\nStack trace:\n#0 {main}",
        'failed_at' => now(),
    ]);

    $retry = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Diagnóstico de prueba, CA-BO-152'],
    );
    $retry->assertOk();

    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $requeued = DB::connection('pgsql_platform')->table('jobs')->latest('id')->first();
    expect($requeued)->not->toBeNull();
    $requeuedPayload = json_decode($requeued->payload, true);

    // El tenant_id sobrevive literal — es lo único que hace que el
    // worker vuelva a entrar en el contexto correcto (RN-BO-85).
    expect($requeuedPayload['tenant_id'])->toBe($tenantA->id);
    // Los intentos se reinician; el resto del payload no se toca.
    expect($requeuedPayload['attempts'])->toBe(0);
    expect($requeuedPayload['uuid'])->toBe($originalPayload['uuid']);
    expect($requeuedPayload['data'])->toBe($originalPayload['data']);
    expect($requeued->queue)->toBe('default');

    $log = AdminActionLog::query()->where('action', 'job.reintentado')->where('subject_public_id', $uuid)->first();
    expect($log)->not->toBeNull();
    expect($log->subject_type)->toBe('failed_job');
    expect($log->affected_tenant_id)->toBe($tenantA->id);
});

test('CA-BO-153: reintentar el uuid de un trabajo de otro centro responde 404, no 403', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $uuid = boInsertFailedJob($tenantB->id);

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Intento cruzado'],
    );

    $response->assertNotFound();
    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});

test('CA-BO-153b: reintentar un uuid inexistente responde 404', function (): void {
    $tenant = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/".Str::uuid().'/retry',
        ['reason' => 'No existe'],
    );

    $response->assertNotFound();
});

test('CA-BO-154: en_alta, activo, suspendido y en_baja admiten reintento; eliminado responde 409', function (string $status, int $expectedStatusCode) {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::from($status)]);

    if ($status === 'eliminado') {
        $tenant->delete();
    }

    $uuid = boInsertFailedJob($tenant->id);

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Prueba de estado'],
    );

    $response->assertStatus($expectedStatusCode);

    if ($expectedStatusCode === 409) {
        expect($response->json('detail'))->not->toBeEmpty();
    }
})->with([
    ['en_alta', 200],
    ['activo', 200],
    ['suspendido', 200],
    ['en_baja', 200],
    ['eliminado', 409],
]);

test('CA-BO-155: reintento sin motivo responde 422 y no borra la fila; con motivo audita job.reintentado', function (): void {
    $tenant = Tenant::factory()->create();
    $uuid = boInsertFailedJob($tenant->id);

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $withoutReason = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        [],
    );
    $withoutReason->assertStatus(422);
    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();

    $withEmptyReason = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => '   '],
    );
    $withEmptyReason->assertStatus(422);

    $withReason = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Reintento con motivo'],
    );
    $withReason->assertOk();

    $log = AdminActionLog::query()->where('action', 'job.reintentado')->where('subject_public_id', $uuid)->first();
    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Reintento con motivo');
    expect($log->affected_tenant_id)->toBe($tenant->id);
});

test('CA-BO-156: reintentar el mismo uuid dos veces responde 404 la segunda, sin un segundo trabajo encolado', function (): void {
    $tenant = Tenant::factory()->create();
    $uuid = boInsertFailedJob($tenant->id);

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $first = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Primer reintento'],
    );
    $first->assertOk();

    $jobsAfterFirst = DB::table('jobs')->count();

    $second = $client->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'Segundo reintento'],
    );
    $second->assertNotFound();

    expect(DB::table('jobs')->count())->toBe($jobsAfterFirst);
});

test('CA-BO-157: no existe ningún camino para reintentar más de un trabajo en una sola llamada', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/platform/v1/failed-jobs')) {
            continue;
        }

        expect(false)->toBeTrue("Ruta inesperada de reintento masivo: {$route->uri()}");
    }

    expect(true)->toBeTrue();
});

// CA-BO-158 (privilegios de motor, no de API — patrón de CA-BO-018/030):
// cuatro tests, uno por operación, porque en PostgreSQL una sentencia que
// falla deja "abortada" la transacción de la propia conexión hasta que
// termina (`DatabaseTransactions` envuelve cada test en una transacción
// sobre `pgsql`) — comprobar más de una operación fallida por test haría
// que la segunda comprobación fallara por la transacción ya abortada de
// la primera, no por el privilegio que se quiere probar.
test('CA-BO-158a: plataforma_app no puede SELECT sobre failed_jobs', function (): void {
    expect(fn () => DB::connection('pgsql')->table('failed_jobs')->count())->toThrow(QueryException::class);
});

test('CA-BO-158b: plataforma_app no puede UPDATE sobre failed_jobs', function (): void {
    expect(fn () => DB::connection('pgsql')->table('failed_jobs')->where('id', 1)->update(['queue' => 'x']))->toThrow(QueryException::class);
});

test('CA-BO-158c: plataforma_app no puede DELETE sobre failed_jobs', function (): void {
    expect(fn () => DB::connection('pgsql')->table('failed_jobs')->delete())->toThrow(QueryException::class);
});

test('CA-BO-158d: plataforma_app sí puede INSERT sobre failed_jobs, que es lo que el worker necesita para registrar un fallo', function (): void {
    // No se comprueba aquí que `pgsql_platform` vea la fila: dentro de un
    // test, `pgsql` vive en una transacción que nunca se compromete
    // (`DatabaseTransactions`), así que otra sesión (`pgsql_platform`) no
    // puede verla todavía — no es un defecto del `GRANT`, es aislamiento
    // de transacción real entre dos conexiones. La única aserción posible
    // y suficiente aquí es que el propio INSERT no lance por privilegios.
    DB::connection('pgsql')->table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['tenant_id' => null, 'displayName' => 'X']),
        'exception' => 'Exception: x',
        'failed_at' => now(),
    ]);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------
// dependency_inconsistencies reutiliza el cálculo de 1.6c (punto 2 de
// api.md §2.10.1)
// ---------------------------------------------------------------------

test('la ficha de salud reutiliza el cálculo de incoherencias de dependencia de GET /tenants/{id}/modules', function (): void {
    registerHealthModuleFixtures();
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        ModuleSubscription::on('pgsql_platform')->create([
            'tenant_id' => $tenant->id,
            'module_code' => 'bo-health-dep',
            'enabled' => true,
            'enabled_at' => now(),
            'reason' => 'Contratado sin su dependencia',
        ]);
    });

    [$admin, $secret] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($admin, 'platform');

    $health = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/health")->assertOk();
    $modules = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules")->assertOk();

    expect($health->json('modules.dependency_inconsistencies'))->toBe([
        ['module_code' => 'bo-health-dep', 'missing_dependencies' => ['bo-health-base']],
    ]);

    $depEntry = collect($modules->json('data'))->firstWhere('code', 'bo-health-dep');
    expect($depEntry['missing_dependencies'])->toBe(['bo-health-base']);
    expect($health->json('modules.contracted'))->toBe(1);
});

// ---------------------------------------------------------------------
// Métricas (CA-BO-159 a CA-BO-162)
// ---------------------------------------------------------------------

test('CA-BO-159: tenants_by_status incluye los cinco estados, con eliminado distinto de cero', function (): void {
    Tenant::factory()->create(['status' => TenantStatus::EnAlta]);
    Tenant::factory()->create(['status' => TenantStatus::Activo]);
    Tenant::factory()->create(['status' => TenantStatus::Suspendido]);
    Tenant::factory()->create(['status' => TenantStatus::EnBaja]);
    $deleted = Tenant::factory()->create(['status' => TenantStatus::Eliminado]);
    $deleted->delete();

    [$admin, $secret] = boCreateEnrolledAdmin('comercial');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform')->assertOk();

    expect($response->json('tenants_by_status.en_alta'))->toBeGreaterThanOrEqual(1);
    expect($response->json('tenants_by_status.activo'))->toBeGreaterThanOrEqual(1);
    expect($response->json('tenants_by_status.suspendido'))->toBeGreaterThanOrEqual(1);
    expect($response->json('tenants_by_status.en_baja'))->toBeGreaterThanOrEqual(1);
    expect($response->json('tenants_by_status.eliminado'))->toBeGreaterThanOrEqual(1);
});

test('CA-BO-160: altas, bajas y eliminaciones son tres series separadas y no existe churn', function (): void {
    $created = Tenant::factory()->create();
    TenantLifecycleEvent::create([
        'affected_tenant_id' => $created->id,
        'from_status' => null,
        'to_status' => TenantStatus::EnAlta,
        'reason' => 'Alta de prueba',
        'occurred_at' => now(),
        'performed_by' => null,
    ]);

    $closed = Tenant::factory()->create(['status' => TenantStatus::EnBaja]);
    TenantLifecycleEvent::create([
        'affected_tenant_id' => $closed->id,
        'from_status' => TenantStatus::Activo,
        'to_status' => TenantStatus::EnBaja,
        'reason' => 'Baja de prueba',
        'occurred_at' => now(),
        'performed_by' => null,
        // tenant_lifecycle_events_grace_period_matches_status_check: toda
        // fila `to_status = en_baja` exige grace_period_ends_at.
        'grace_period_ends_at' => now()->addDays(90),
    ]);

    // tenant_lifecycle_events_deletion_requires_dual_auth_check: toda fila
    // `to_status = eliminado` exige una doble autorización ya ejecutada.
    [$requester] = boCreateEnrolledAdmin('superadministrador');
    [$approver] = boCreateEnrolledAdmin('superadministrador');
    $authorization = DualAuthorization::create([
        'action' => 'tenant.eliminar',
        'payload' => ['x' => 1],
        'payload_fingerprint' => Str::random(32),
        'reason' => 'Eliminación de prueba',
        'requested_by' => $requester->id,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'status' => 'ejecutada',
        // dual_authorizations_approved_coherence_check: 'ejecutada' exige
        // approved_by/approved_at rellenos (fix de la migración de
        // 2026-09-11), y approved_by <> requested_by (RN-BO-19).
        'approved_by' => $approver->id,
        'approved_at' => now(),
        'executed_at' => now(),
    ]);

    $deleted = Tenant::factory()->create(['status' => TenantStatus::Eliminado]);
    TenantLifecycleEvent::create([
        'affected_tenant_id' => $deleted->id,
        'from_status' => TenantStatus::EnBaja,
        'to_status' => TenantStatus::Eliminado,
        'reason' => 'Eliminación de prueba',
        'occurred_at' => now(),
        'performed_by' => null,
        'dual_authorization_id' => $authorization->id,
    ]);
    $deleted->delete();

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform')->assertOk();

    expect($response->json('created'))->toBeGreaterThanOrEqual(1);
    expect($response->json('closed'))->toBeGreaterThanOrEqual(1);
    expect($response->json('deleted'))->toBeGreaterThanOrEqual(1);
    expect($response->json())->not->toHaveKey('churn');
});

test('CA-BO-160b: occurred_at_from posterior a occurred_at_to responde 422', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform?occurred_at_from=2026-09-16&occurred_at_to=2026-01-01');

    $response->assertStatus(422);
});

test('CA-BO-160c: (regresión, hallazgo de /codex:review) occurred_at_from mal formado responde 422, no 500', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform?occurred_at_from=no-es-una-fecha');

    $response->assertStatus(422);
});

test('CA-BO-152b: (regresión, hallazgo de /codex:review) failed_at_from mal formado responde 422, no 500', function (): void {
    $tenant = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/tenants/'.$tenant->public_id.'/failed-jobs?failed_at_from=no-es-una-fecha');

    $response->assertStatus(422);
});

test('CA-BO-152c: (regresión, hallazgo de /codex:review) limit=0 y limit negativo responden 422, no paginación rota', function (): void {
    $tenant = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/tenants/'.$tenant->public_id.'/failed-jobs?limit=0')
        ->assertStatus(422);

    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/tenants/'.$tenant->public_id.'/failed-jobs?limit=-5')
        ->assertStatus(422);

    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/tenants/'.$tenant->public_id.'/failed-jobs?limit=201')
        ->assertStatus(422);
});

test('CA-BO-161: adopción por módulo se construye sobre el catálogo declarado, con esencial marcado y sin recuento, y un retirado con su recuento real', function (): void {
    registerHealthModuleFixtures();

    $tenantWithBoth = Tenant::factory()->create();
    $tenantWithBaseOnly = Tenant::factory()->create();
    Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenantWithBoth->id, function () use ($tenantWithBoth): void {
        ModuleSubscription::on('pgsql_platform')->create([
            'tenant_id' => $tenantWithBoth->id, 'module_code' => 'bo-health-base', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'x',
        ]);
    });
    app(TenantContext::class)->runFor($tenantWithBaseOnly->id, function () use ($tenantWithBaseOnly): void {
        ModuleSubscription::on('pgsql_platform')->create([
            'tenant_id' => $tenantWithBaseOnly->id, 'module_code' => 'bo-health-base', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'x',
        ]);
    });

    // Módulo retirado: fila en `modules` con retired_at, sin ServiceProvider
    // — es decir, ModuleCatalog::all() nunca lo devuelve — y con una
    // suscripción viva de un centro (RN-BO-70, RMOD-007: sigue facturando).
    DB::connection('pgsql_owner')->table('modules')->updateOrInsert(
        ['code' => 'bo-health-retired'],
        ['name_key' => 'modules.sync_registry_test', 'phase' => '0', 'retired_at' => now()],
    );
    app(TenantContext::class)->runFor($tenantWithBoth->id, function () use ($tenantWithBoth): void {
        ModuleSubscription::on('pgsql_platform')->create([
            'tenant_id' => $tenantWithBoth->id, 'module_code' => 'bo-health-retired', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'x',
        ]);
    });

    [$admin, $secret] = boCreateEnrolledAdmin('comercial');
    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/module-adoption')->assertOk();
    $data = collect($response->json('data'));

    $base = $data->firstWhere('module_code', 'bo-health-base');
    expect($base['contracted_tenants'])->toBe(2);
    expect($base['retired'])->toBeFalse();

    $dep = $data->firstWhere('module_code', 'bo-health-dep');
    expect($dep['contracted_tenants'])->toBe(0);

    $core = $data->firstWhere('module_code', 'core');
    expect($core['essential'])->toBeTrue();
    expect($core)->not->toHaveKey('contracted_tenants');

    $retired = $data->firstWhere('module_code', 'bo-health-retired');
    expect($retired)->not->toBeNull();
    expect($retired['retired'])->toBeTrue();
    expect($retired['contracted_tenants'])->toBe(1);

    expect($response->json('of_tenants'))->toBeGreaterThanOrEqual(3);
});

test('CA-BO-162 (aislamiento obligatorio): las métricas agregadas incluyen a tres centros dentro de runAsPlatform, salen reducidas fuera, y la ficha de A no contiene nada de B ni de C', function (): void {
    registerHealthModuleFixtures();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantC = Tenant::factory()->create();

    foreach ([$tenantA, $tenantB, $tenantC] as $tenant) {
        app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
            ModuleSubscription::on('pgsql_platform')->create([
                'tenant_id' => $tenant->id, 'module_code' => 'bo-health-base', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'x',
            ]);
        });
    }

    $uuidA = boInsertFailedJob($tenantA->id, 'Fixture\\JobA');
    boInsertFailedJob($tenantB->id, 'Fixture\\JobB');
    boInsertFailedJob($tenantC->id, 'Fixture\\JobC');

    [$admin, $secret] = boCreateEnrolledAdmin('comercial');
    $this->actingAs($admin, 'platform');

    $adoption = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/module-adoption')->assertOk();
    $base = collect($adoption->json('data'))->firstWhere('module_code', 'bo-health-base');
    expect($base['contracted_tenants'])->toBe(3);

    // RN-BO-94: fuera de runAsPlatform(), TenantScope SÍ filtra —
    // ModuleSubscription es TenantModel. Se demuestra entrando en el
    // contexto de un solo tenant y repitiendo la consulta cruda: el
    // resultado sale reducido y menor, sin ningún error.
    $reduced = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => ModuleSubscription::query()->where('module_code', 'bo-health-base')->where('enabled', true)->count(),
    );
    expect($reduced)->toBe(1);
    expect($reduced)->toBeLessThan(3);

    // La ficha de A no contiene ni un trabajo, módulo ni evento de B o C.
    $soporte = boCreateEnrolledAdmin('soporte');
    $this->actingAs($soporte[0], 'platform');
    $healthA = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/health")->assertOk();
    $failedJobsA = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/failed-jobs")->assertOk();

    expect($healthA->json('jobs.failed'))->toBe(1);
    expect($failedJobsA->json('data'))->toHaveCount(1);
    expect($failedJobsA->json('data.0.uuid'))->toBe($uuidA);
});

// ---------------------------------------------------------------------
// Permisos (CA-BO-163, CA-BO-165)
// ---------------------------------------------------------------------

test('CA-BO-163: PlatformCapability declara exactamente salud.leer, job.reintentar y metrica.leer como capacidades nuevas', function (): void {
    $values = array_map(fn (PlatformCapability $c) => $c->value, PlatformCapability::cases());

    expect($values)->toContain('salud.leer');
    expect($values)->toContain('job.reintentar');
    expect($values)->toContain('metrica.leer');

    // Exactamente tres: ningún otro valor nuevo de salud/métricas cuela.
    $healthAndMetrics = array_values(array_filter(
        $values,
        fn (string $v) => in_array($v, ['salud.leer', 'job.reintentar', 'metrica.leer'], true),
    ));
    expect($healthAndMetrics)->toHaveCount(3);
});

test('CA-BO-163b: el mapa de capacidades reparte salud/métricas exactamente como permisos.md §4.5', function (): void {
    $expected = [
        'soporte' => ['salud.leer' => true, 'job.reintentar' => false, 'metrica.leer' => false],
        'operaciones' => ['salud.leer' => true, 'job.reintentar' => true, 'metrica.leer' => true],
        'comercial' => ['salud.leer' => false, 'job.reintentar' => false, 'metrica.leer' => true],
        'superadministrador' => ['salud.leer' => true, 'job.reintentar' => true, 'metrica.leer' => true],
    ];

    foreach ($expected as $role => $capabilities) {
        foreach ($capabilities as $capability => $granted) {
            $result = PlatformCapabilityMap::grants([PlatformRole::from($role)], PlatformCapability::from($capability));
            expect($result)->toBe($granted, "{$role} · {$capability}");
        }
    }
});

test('CA-BO-165: soporte lee salud y recibe 403 al reintentar; operaciones reintenta; comercial recibe 403 en salud y 200 en métricas', function (): void {
    $tenant = Tenant::factory()->create();
    $uuid = boInsertFailedJob($tenant->id);

    [$soporte, $soporteSecret] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($soporte, 'platform');
    $soporteClient = boSensitiveClient($soporte, $soporteSecret);

    $soporteClient->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/health")->assertOk();
    $soporteClient->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs")->assertOk();
    $soporteClient->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'soporte no puede'],
    )->assertForbidden();
    $soporteClient->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform')->assertForbidden();

    [$operaciones, $operacionesSecret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($operaciones, 'platform');
    $operacionesClient = boSensitiveClient($operaciones, $operacionesSecret);

    $operacionesClient->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/failed-jobs/{$uuid}/retry",
        ['reason' => 'operaciones sí puede'],
    )->assertOk();

    [$comercial, $comercialSecret] = boCreateEnrolledAdmin('comercial');
    $this->actingAs($comercial, 'platform');

    $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/health")->assertForbidden();
    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/platform')->assertOk();
    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/metrics/module-adoption')->assertOk();
});

// ---------------------------------------------------------------------
// Regresiones de privilegios (CA-BO-164, CA-BO-166)
// ---------------------------------------------------------------------

test('CA-BO-164: bo:retry-provisioning reencola con los privilegios reales de los tres roles', function (): void {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::EnAlta]);

    $uuid = (string) Str::uuid();
    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => 'App\\Modules\\Backoffice\\Infrastructure\\Jobs\\ProvisionTenant',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'ProvisionTenant', 'command' => serialize(['tenantPublicId' => $tenant->public_id])],
        // RN-BO-90: los cuatro trabajos que el backoffice despacha llevan
        // tenant_id nulo, por construcción.
        'tenant_id' => null,
    ], JSON_THROW_ON_ERROR);

    DB::connection('pgsql_platform')->table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => "RuntimeException: fallo simulado de aprovisionamiento\nStack trace:\n#0 {main}",
        'failed_at' => now(),
    ]);

    $this->artisan('bo:retry-provisioning', ['slug' => $tenant->slug])
        ->expectsOutputToContain('reencolado')
        ->assertExitCode(0);

    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
    expect(DB::table('jobs')->count())->toBeGreaterThanOrEqual(1);

    $log = AdminActionLog::query()->where('action', 'job.reintentado')->where('subject_public_id', $uuid)->first();
    expect($log)->not->toBeNull();
    expect($log->actor_type->value)->toBe('console');
    expect($log->affected_tenant_id)->toBe($tenant->id);
});

test('CA-BO-166: bo:purge-failed-jobs borra lo anterior a 24 horas y conserva lo reciente; queue:prune-failed ya no está programado', function (): void {
    $old = (string) Str::uuid();
    $recent = (string) Str::uuid();

    foreach ([[$old, now()->subHours(25)], [$recent, now()->subHours(1)]] as [$uuid, $failedAt]) {
        DB::connection('pgsql_platform')->table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['tenant_id' => null, 'displayName' => 'X']),
            'exception' => 'Exception: x',
            'failed_at' => $failedAt,
        ]);
    }

    $this->artisan('bo:purge-failed-jobs')->assertExitCode(0);

    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $old)->exists())->toBeFalse();
    expect(DB::connection('pgsql_platform')->table('failed_jobs')->where('uuid', $recent)->exists())->toBeTrue();

    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $purgeEvent = $events->first(fn ($e) => str_contains($e->command ?? '', 'bo:purge-failed-jobs'));
    $legacyEvent = $events->first(fn ($e) => str_contains($e->command ?? '', 'queue:prune-failed'));

    expect($purgeEvent)->not->toBeNull();
    expect($purgeEvent->expression)->toBe('0 0 * * *');
    expect($legacyEvent)->toBeNull();
});
