<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Infrastructure\Jobs\MaterializeMfaObligations;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaChallenges;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaEnrollments;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaFactors;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §D.1.1 puntos 12-17, §D.2.2, §D.4.1.1 (issue #109, pieza
// 4 de 1.3b). Las cuatro tareas de mantenimiento recuperadas, con su
// registro en el scheduler — QUEUE_CONNECTION=sync en tests (phpunit.xml),
// así que dispatch() ejecuta el job en el momento, dentro del mismo
// TenantContext::runFor() que fija el tenant_id (mismo patrón que
// AuthRetentionJobsTest.php).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-170, RN-AUTH-85
test('CA-AUTH-170: PurgeMfaEnrollments borra físicamente solo las altas sin confirmar y vencidas', function (): void {
    [$tenantA, $userA] = provisionActiveUser('purge-enroll-a');
    [$tenantB, $userB] = provisionActiveUser('purge-enroll-b');

    $ids = app(TenantContext::class)->runFor($tenantA->id, function () use ($userA): array {
        $expired = MfaFactor::create([
            'user_id' => $userA->id, 'method' => 'totp', 'secret_encrypted' => 'x',
            'expires_at' => now()->subMinute(),
        ]);
        $live = MfaFactor::create([
            'user_id' => $userA->id, 'method' => 'totp', 'secret_encrypted' => 'y',
            'expires_at' => now()->addMinutes(5),
        ]);

        return [$expired->id, $live->id];
    });

    $confirmedInA = app(TenantContext::class)->runFor($tenantA->id, fn () => MfaFactor::create([
        'user_id' => $userA->id, 'method' => 'totp', 'secret_encrypted' => 'z', 'confirmed_at' => now(),
    ])->id);

    $expiredInB = app(TenantContext::class)->runFor($tenantB->id, fn () => MfaFactor::create([
        'user_id' => $userB->id, 'method' => 'totp', 'secret_encrypted' => 'w',
        'expires_at' => now()->subMinute(),
    ])->id);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($tenantA): void {
        PurgeMfaEnrollments::dispatch($tenantA->id);
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($ids, $confirmedInA): void {
        [$expiredId, $liveId] = $ids;

        expect(MfaFactor::withTrashed()->find($expiredId))->toBeNull()
            ->and(MfaFactor::find($liveId))->not->toBeNull()
            ->and(MfaFactor::find($confirmedInA))->not->toBeNull();
    });

    // Solo se despachó para tenantA (INV-001).
    app(TenantContext::class)->runFor($tenantB->id, function () use ($expiredInB): void {
        expect(MfaFactor::withTrashed()->find($expiredInB))->not->toBeNull();
    });
});

// CA-AUTH-171, RN-AUTH-85
test('CA-AUTH-171: PurgeMfaFactors borra físicamente solo los factores borrados lógicamente hace más de AUTH_MFA_FACTOR_PURGE_DAYS', function (): void {
    [$tenant, $user] = provisionActiveUser('purge-factors');

    [$oldId, $recentId, $liveId] = app(TenantContext::class)->runFor($tenant->id, function () use ($user): array {
        $old = MfaFactor::create(['user_id' => $user->id, 'method' => 'totp', 'secret_encrypted' => 'a', 'confirmed_at' => now()->subDays(60)]);
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(31)])->save();

        $recent = MfaFactor::create(['user_id' => $user->id, 'method' => 'totp', 'secret_encrypted' => 'b', 'confirmed_at' => now()->subDays(10)]);
        $recent->delete();

        $live = MfaFactor::create(['user_id' => $user->id, 'method' => 'totp', 'secret_encrypted' => 'c', 'confirmed_at' => now()]);

        return [$old->id, $recent->id, $live->id];
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeMfaFactors::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($oldId, $recentId, $liveId): void {
        expect(MfaFactor::withTrashed()->find($oldId))->toBeNull()
            ->and(MfaFactor::withTrashed()->find($recentId))->not->toBeNull()
            ->and(MfaFactor::find($liveId))->not->toBeNull();
    });
});

// CA-AUTH-172, RN-AUTH-85
test('CA-AUTH-172: PurgeMfaChallenges borra los desafíos consumidos hace más de AUTH_MFA_CHALLENGE_RETENTION_HOURS, y un desafío vivo no se toca', function (): void {
    [$tenant, $user] = provisionActiveUser('purge-challenges');

    [$oldConsumedId, $recentConsumedId, $liveId] = app(TenantContext::class)->runFor($tenant->id, function () use ($user): array {
        $oldConsumed = MfaChallenge::create([
            'user_id' => $user->id, 'session_id' => 'sess-old', 'method' => 'totp',
            'expires_at' => now()->subDays(2), 'consumed_at' => now()->subHours(25),
        ]);
        $recentConsumed = MfaChallenge::create([
            'user_id' => $user->id, 'session_id' => 'sess-recent', 'method' => 'totp',
            'expires_at' => now()->subHour(), 'consumed_at' => now()->subHour(),
        ]);
        $live = MfaChallenge::create([
            'user_id' => $user->id, 'session_id' => 'sess-live', 'method' => 'totp',
            'expires_at' => now()->subDays(3),
        ]);

        return [$oldConsumed->id, $recentConsumed->id, $live->id];
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeMfaChallenges::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($oldConsumedId, $recentConsumedId, $liveId): void {
        expect(MfaChallenge::query()->find($oldConsumedId))->toBeNull()
            ->and(MfaChallenge::query()->find($recentConsumedId))->not->toBeNull()
            // Un desafío vencido pero NUNCA consumido no se purga: solo
            // consumed_at gobierna esta tarea (§D.2.2, a diferencia de
            // expires_at).
            ->and(MfaChallenge::query()->find($liveId))->not->toBeNull();
    });
});

// CA-AUTH-173, RN-AUTH-65
test('CA-AUTH-173: MaterializeMfaObligations crea la obligación perdida de un usuario obligado, y una segunda ejecución no duplica', function (): void {
    [$tenant, $admin] = provisionCoreTenant('materialize-173');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::create([
        'code' => 'rol-173', 'name' => 'Rol 173', 'is_system' => false, 'mfa_required' => true,
    ]));

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($role): User {
        $person = Person::factory()->create();
        $u = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $u->roles()->attach($role->id);

        return $u;
    });

    // El listener de PATCH /roles no se ha disparado: no hay fila.
    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserMfaObligation::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        MaterializeMfaObligations::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserMfaObligation::query()->where('user_id', $user->id)->whereNull('resolved_at')->count())->toBe(1);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        MaterializeMfaObligations::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserMfaObligation::query()->where('user_id', $user->id)->count())->toBe(1);
    });
});

// CA-AUTH-174, RunsPerTenant
test('CA-AUTH-174: las cinco tareas están registradas en el scheduler con su cadencia, y se ejecutan para los dos tenants', function (): void {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $purgeEvent = $events->first(fn ($e) => str_contains($e->command ?? '', 'auth:purge-maintenance'));
    $obligationsEvent = $events->first(fn ($e) => str_contains($e->command ?? '', 'auth:mfa-obligations'));

    expect($purgeEvent)->not->toBeNull()
        ->and($purgeEvent->expression)->toBe('0 0 * * *')
        ->and($obligationsEvent)->not->toBeNull()
        ->and($obligationsEvent->expression)->toBe('0 * * * *');

    // Ejecución real con dos tenants (sin Queue::fake: QUEUE_CONNECTION=sync
    // en tests ejecuta el job en el momento) — el efecto en las dos bases
    // es la prueba de que RunsPerTenant no se quedó en el primer tenant.
    [$tenantA, $userA] = provisionActiveUser('sched-174-a');
    [$tenantB, $userB] = provisionActiveUser('sched-174-b');

    [$expiredA, $expiredB] = [
        app(TenantContext::class)->runFor($tenantA->id, fn () => MfaFactor::create([
            'user_id' => $userA->id, 'method' => 'totp', 'secret_encrypted' => 'a', 'expires_at' => now()->subMinute(),
        ])->id),
        app(TenantContext::class)->runFor($tenantB->id, fn () => MfaFactor::create([
            'user_id' => $userB->id, 'method' => 'totp', 'secret_encrypted' => 'b', 'expires_at' => now()->subMinute(),
        ])->id),
    ];

    Artisan::call('auth:purge-maintenance');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($expiredA): void {
        expect(MfaFactor::withTrashed()->find($expiredA))->toBeNull();
    });
    app(TenantContext::class)->runFor($tenantB->id, function () use ($expiredB): void {
        expect(MfaFactor::withTrashed()->find($expiredB))->toBeNull();
    });
});

// CA-AUTH-175, RN-AUTH-79, funcional.md §D.2.3
test('CA-AUTH-175: el tope de entregas por desafío se lee de la configuración, no está escrito a mano', function (): void {
    Queue::fake();
    config(['auth-local.mfa.max_deliveries' => 1]);

    [$tenant, $user, $password] = provisionActiveUser('mfa-175');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    // La apertura ya cuenta como la primera entrega: con el tope en 1,
    // el primer reenvío debe agotarlo ya.
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'), ['method' => 'email'])
        ->assertStatus(429);
});
