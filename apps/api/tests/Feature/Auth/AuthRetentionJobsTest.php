<?php

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserKnownDevice;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Infrastructure\Jobs\CloseOrphanedUserSessions;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUserKnownDevices;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUserSessions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * datos.md §B.7, operacion.md §B.3/§B.3.1. Sin estos tres tests, las
 * tareas de mantenimiento de 1.2b (revisión de db-reviewer sobre
 * 2026_08_25_100100/100200) no tenían ninguna cobertura directa —
 * hallazgo propio de la revisión, severidad Media (INV-015): el
 * comportamiento estaba descrito en la especificación pero no verificado.
 * Mismo patrón que `tests/Feature/Core/CorePurgeJobsTest.php`: cada job se
 * despacha dentro de `TenantContext::runFor()` para que el listener de
 * `TenancyServiceProvider` (payload `tenant_id`) fije el contexto correcto
 * antes de `handle()`.
 *
 * Cada test verifica también el aislamiento entre tenants (`INV-001`): el
 * job despachado para un tenant no debe tocar filas de otro, ni por RLS
 * (segunda barrera) ni por el scope global (primera barrera, `TenantScope`).
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

test('PurgeUserSessions borra físicamente solo las sesiones cerradas fuera de la retención del tenant despachado', function (): void {
    [$tenantA, $userA] = provisionActiveUser('purge-sessions-a');
    [$tenantB] = provisionActiveUser('purge-sessions-b');
    $userB = app(TenantContext::class)->runFor($tenantB->id, fn () => User::query()->firstOrFail());

    $ids = app(TenantContext::class)->runFor($tenantA->id, function () use ($userA) {
        $old = UserSession::create([
            'user_id' => $userA->id,
            'session_id' => (string) Str::ulid(),
            'started_at' => now()->subDays(120),
            'ended_at' => now()->subDays(91),
            'end_reason' => SessionEndReason::Logout,
        ]);
        $recent = UserSession::create([
            'user_id' => $userA->id,
            'session_id' => (string) Str::ulid(),
            'started_at' => now()->subDays(20),
            'ended_at' => now()->subDays(5),
            'end_reason' => SessionEndReason::Logout,
        ]);
        $live = UserSession::create([
            'user_id' => $userA->id,
            'session_id' => (string) Str::ulid(),
            'started_at' => now(),
        ]);

        return [$old->id, $recent->id, $live->id];
    });

    $oldInB = app(TenantContext::class)->runFor($tenantB->id, function () use ($userB) {
        return UserSession::create([
            'user_id' => $userB->id,
            'session_id' => (string) Str::ulid(),
            'started_at' => now()->subDays(120),
            'ended_at' => now()->subDays(91),
            'end_reason' => SessionEndReason::Logout,
        ])->id;
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($tenantA): void {
        PurgeUserSessions::dispatch($tenantA->id);
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($ids): void {
        [$oldId, $recentId, $liveId] = $ids;

        expect(UserSession::withTrashed()->find($oldId))->toBeNull();
        expect(UserSession::find($recentId))->not->toBeNull();
        expect(UserSession::find($liveId))->not->toBeNull();
    });

    // La sesión, igualmente vencida, del OTRO tenant sobrevive: el job
    // solo se despachó para tenantA (RLS + TenantScope, INV-001).
    app(TenantContext::class)->runFor($tenantB->id, function () use ($oldInB): void {
        expect(UserSession::withTrashed()->find($oldInB))->not->toBeNull();
    });
});

test('PurgeUserKnownDevices borra físicamente solo los dispositivos sin uso del tenant despachado', function (): void {
    [$tenantA, $userA] = provisionActiveUser('purge-devices-a');
    [$tenantB] = provisionActiveUser('purge-devices-b');
    $userB = app(TenantContext::class)->runFor($tenantB->id, fn () => User::query()->firstOrFail());

    $ids = app(TenantContext::class)->runFor($tenantA->id, function () use ($userA) {
        $stale = UserKnownDevice::create([
            'user_id' => $userA->id,
            'device_token_hash' => hash('sha256', 'stale-device'),
            'first_seen_at' => now()->subDays(500),
            'last_seen_at' => now()->subDays(370),
        ]);
        $fresh = UserKnownDevice::create([
            'user_id' => $userA->id,
            'device_token_hash' => hash('sha256', 'fresh-device'),
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now()->subDays(1),
        ]);

        return [$stale->id, $fresh->id];
    });

    $staleInB = app(TenantContext::class)->runFor($tenantB->id, function () use ($userB) {
        return UserKnownDevice::create([
            'user_id' => $userB->id,
            'device_token_hash' => hash('sha256', 'stale-device-b'),
            'first_seen_at' => now()->subDays(500),
            'last_seen_at' => now()->subDays(370),
        ])->id;
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($tenantA): void {
        PurgeUserKnownDevices::dispatch($tenantA->id);
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($ids): void {
        [$staleId, $freshId] = $ids;

        expect(UserKnownDevice::withTrashed()->find($staleId))->toBeNull();
        expect(UserKnownDevice::find($freshId))->not->toBeNull();
    });

    app(TenantContext::class)->runFor($tenantB->id, function () use ($staleInB): void {
        expect(UserKnownDevice::withTrashed()->find($staleInB))->not->toBeNull();
    });
});

test('CloseOrphanedUserSessions cierra como caducidad solo las filas vivas sin sesión de framework del tenant despachado', function (): void {
    [$tenantA, $userA, $passwordA] = provisionActiveUser('orphan-sessions-a');
    [$tenantB, $userB, $passwordB] = provisionActiveUser('orphan-sessions-b');

    $loginA = loginFor($tenantA->slug, $userA->email, $passwordA);
    $cookieA = sessionCookieValue($loginA);

    resetSessionState();
    $loginB = loginFor($tenantB->slug, $userB->email, $passwordB);

    // CA-AUTH-087 (UserSessionsTest.php): tras un login HTTP real, el
    // guard 'web' queda con ese usuario cacheado aunque la llamada
    // siguiente no pase por ningún middleware. Sin este reseteo, los
    // `UserSession::create()` de más abajo (tenantA) heredarían un
    // `created_by` de `userB` — de OTRO tenant — y violarían la FK
    // compuesta (tenant_id, created_by) hacia `users`.
    resetSessionState();

    // Se simula el recolector del framework: se borra la fila de
    // `sessions` de cada tenant, sin pasar por ningún endpoint — mismo
    // truco que CA-AUTH-084 (UserSessionsTest.php).
    [$orphanIdA, $liveIdA] = app(TenantContext::class)->runFor($tenantA->id, function () use ($userA) {
        $orphan = UserSession::query()->where('user_id', $userA->id)->firstOrFail();
        DB::table('sessions')->where('id', $orphan->session_id)->delete();

        $live = UserSession::create([
            'user_id' => $userA->id,
            'session_id' => (string) Str::ulid(),
            'started_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => $live->session_id,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->timestamp,
        ]);

        return [$orphan->id, $live->id];
    });

    $orphanIdB = app(TenantContext::class)->runFor($tenantB->id, function () use ($userB) {
        $orphan = UserSession::query()->where('user_id', $userB->id)->firstOrFail();
        DB::table('sessions')->where('id', $orphan->session_id)->delete();

        return $orphan->id;
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($tenantA): void {
        CloseOrphanedUserSessions::dispatch($tenantA->id);
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($orphanIdA, $liveIdA): void {
        $orphan = UserSession::findOrFail($orphanIdA);
        expect($orphan->ended_at)->not->toBeNull()
            ->and($orphan->end_reason)->toBe(SessionEndReason::Caducidad);

        $live = UserSession::findOrFail($liveIdA);
        expect($live->ended_at)->toBeNull();
    });

    // La sesión huérfana del OTRO tenant sigue viva: el job solo se
    // despachó para tenantA.
    app(TenantContext::class)->runFor($tenantB->id, function () use ($orphanIdB): void {
        expect(UserSession::findOrFail($orphanIdB)->ended_at)->toBeNull();
    });
});
