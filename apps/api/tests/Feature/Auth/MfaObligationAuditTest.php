<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Issue #381, INV-003, ADR-035: el cierre de una obligación MFA
// (`resolved_at`) es una modificación de un modelo `Auditable`. Se hacía con
// `UserMfaObligation::query()->…->update()`, que no dispara eventos de
// modelo y por tanto no auditaba. Estos tests fijan que ahora sí lo hace en
// los dos caminos (confirmar el primer factor, conceder una excepción).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @return list<AuditLog>
 */
function obligationResolvedAuditRows(int $obligationId): array
{
    return AuditLog::query()
        ->where('auditable_type', 'user_mfa_obligation')
        ->where('auditable_id', $obligationId)
        ->where('event', 'updated')
        ->get()
        ->filter(fn (AuditLog $row): bool => array_key_exists('resolved_at', (array) $row->changes))
        ->values()
        ->all();
}

test('INV-003 #381: confirmar el primer factor cierra la obligación abierta y deja una fila de auditoría updated con resolved_at', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-381-a']);
    Cache::forget("tenant-resolution:{$tenant->slug}");
    $password = 'Cl4v3-Correcta-2026!';

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($password): User {
        $role = Role::create(['code' => 'rol-381a', 'name' => 'Rol 381a', 'is_system' => false, 'mfa_required' => true]);
        $user = User::factory()->for(Person::factory()->create())->create(['password' => $password, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), ['email' => $user->email, 'password' => $password])->assertOk();
    $cookie = sessionCookieValue($login);

    // La obligación se materializa al iniciar sesión (RN-AUTH-65).
    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => currentTotpCode($enroll->json('secret')),
        ])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $obligation = UserMfaObligation::query()->where('user_id', $user->id)->firstOrFail();

        expect($obligation->resolved_at)->not->toBeNull()
            ->and(obligationResolvedAuditRows($obligation->id))->toHaveCount(1);
    });
});

test('INV-003 #381: conceder una excepción MFA cierra la obligación abierta y deja una fila de auditoría updated con resolved_at', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-381-b');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::create([
        'code' => 'rol-381b', 'name' => 'Rol 381b', 'is_system' => false, 'mfa_required' => true,
    ]));

    $target = app(TenantContext::class)->runFor($tenant->id, function () use ($role): User {
        $user = User::factory()->for(Person::factory()->create())->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);
        UserMfaObligation::create([
            'user_id' => $user->id,
            'obligated_since' => now()->subDays(20),
            'grace_deadline_at' => now()->subDay(),
            'trigger' => 'rol_asignado',
        ]);

        return $user;
    });

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin dispositivo compatible, pendiente de sustitución.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        $obligation = UserMfaObligation::query()->where('user_id', $target->id)->firstOrFail();

        expect($obligation->resolved_at)->not->toBeNull()
            ->and(obligationResolvedAuditRows($obligation->id))->toHaveCount(1);
    });
});
