<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// funcional.md §C.4.7, §C.4.8, §C.4.9, §C.4.12. Obligatoriedad,
// gracia, muro de alta y métodos admitidos por el tenant
// (REQ-AUTH-003, 1.3).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * Usuario del tenant con un único rol personalizado con
 * `mfa_required = $required`, sin depender del editor de roles (1.5).
 */
function userWithMfaRequiredRole(Tenant $tenant, bool $required, string $email = 'obligado@example.com'): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($required, $email): User {
        $role = Role::create([
            'code' => 'rol-test-'.Str::random(8),
            'name' => 'Rol de prueba',
            'is_system' => false,
            'mfa_required' => $required,
        ]);

        $person = Person::factory()->create(['contact_email' => $email]);
        $user = User::factory()->for($person)->create(['email' => $email, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });
}

// CA-AUTH-126, RN-AUTH-62
test('CA-AUTH-126: con dos roles, uno con mfa_required, MfaPolicy declara obligado (OR, no AND)', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-126']);
    Cache::forget("tenant-resolution:{$tenant->slug}");

    $user = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $roleTrue = Role::create(['code' => 'rol-true', 'name' => 'Con MFA', 'is_system' => false, 'mfa_required' => true]);
        $roleFalse = Role::create(['code' => 'rol-false', 'name' => 'Sin MFA', 'is_system' => false, 'mfa_required' => false]);

        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach([$roleTrue->id, $roleFalse->id]);

        return $user;
    });

    $obligated = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(MfaPolicy::class)->resolve($user)->isObligated(),
    );

    expect($obligated)->toBeTrue();
});

// CA-AUTH-127, RN-AUTH-65
test('CA-AUTH-127: PATCH /roles con mfa_required=true materializa la obligación de los usuarios afectados', function (): void {
    // Sin Queue::fake(): el listener (QUEUE_CONNECTION=sync en tests) debe
    // ejecutarse de verdad para poder comprobar la fila materializada.
    [$tenant, $admin] = provisionCoreTenant('mfa-127');

    $user = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $role = Role::create(['code' => 'rol-127', 'name' => 'Rol 127', 'is_system' => false, 'mfa_required' => false]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    $rolePublicId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => Role::where('code', 'rol-127')->firstOrFail()->public_id,
    );

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$rolePublicId}"), ['mfa_required' => true])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $obligation = UserMfaObligation::query()->where('user_id', $user->id)->whereNull('resolved_at')->first();
        expect($obligation)->not->toBeNull()
            ->and($obligation->obligated_since->diffInDays($obligation->grace_deadline_at))
            ->toBe((float) config('auth-local.mfa.grace_default_days'));
    });
});

// CA-AUTH-128
test('CA-AUTH-128: un usuario obligado dentro de la gracia inicia sesión con normalidad', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-128']);
    Cache::forget("tenant-resolution:{$tenant->slug}");
    $password = 'Cl4v3-Correcta-2026!';

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($password): User {
        $role = Role::create(['code' => 'rol-128', 'name' => 'Rol 128', 'is_system' => false, 'mfa_required' => true]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['password' => $password, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    expect($login->json('mfa.obligated'))->toBeTrue()
        ->and($login->json('mfa.enforced'))->toBeFalse();

    withSessionCookie(sessionCookieValue($login))
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();
});

// CA-AUTH-129, CA-AUTH-132
test('CA-AUTH-129: gracia vencida responde el muro en cualquier endpoint salvo la lista blanca', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-129']);
    Cache::forget("tenant-resolution:{$tenant->slug}");
    $password = 'Cl4v3-Correcta-2026!';

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($password): User {
        $role = Role::create(['code' => 'rol-129', 'name' => 'Rol 129', 'is_system' => false, 'mfa_required' => true]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['password' => $password, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        // Materializa una obligación ya vencida directamente (sin esperar
        // los 7 días por defecto).
        UserMfaObligation::create([
            'user_id' => $user->id,
            'obligated_since' => now()->subDays(10),
            'grace_deadline_at' => now()->subDay(),
            'trigger' => 'rol_asignado',
        ]);

        return $user;
    });

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    $cookie = sessionCookieValue($login);

    // Permitidos.
    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();
    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();

    // Bloqueado: cualquier otro endpoint.
    $blocked = withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertStatus(403);

    expect($blocked->json('type'))->toBe('urn:pge:error:mfa-enrollment-required');

    // CA-AUTH-132: obligado, sin factor, no puede "desactivar" nada —
    // pero si tuviera un factor, no podría desactivarlo (409). Aquí se
    // verifica la mitad alcanzable sin factor: el muro impide cualquier
    // otra acción salvo la lista blanca, DELETE /auth/session incluido.
    withSessionCookie($cookie)->deleteJson(coreApiUrl($tenant->slug, '/auth/session'))->assertNoContent();
});

// CA-AUTH-130, CA-AUTH-131
test('CA-AUTH-130/131: la gracia vence a mitad de sesión sin destruirla, y confirmar el factor levanta el muro', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-130']);
    Cache::forget("tenant-resolution:{$tenant->slug}");
    $password = 'Cl4v3-Correcta-2026!';

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($password): User {
        $role = Role::create(['code' => 'rol-130', 'name' => 'Rol 130', 'is_system' => false, 'mfa_required' => true]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['password' => $password, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    // Login DENTRO de la gracia (recién obligado, sin fila todavía).
    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();

    // La gracia vence a mitad de la sesión: se adelanta el plazo. El
    // CHECK grace_deadline_at > obligated_since exige mover también
    // obligated_since hacia atrás, o la propia UPDATE lo viola.
    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        UserMfaObligation::query()->where('user_id', $user->id)->update([
            'obligated_since' => now()->subDays(10),
            'grace_deadline_at' => now()->subMinute(),
        ]);
    });

    // CA-AUTH-130: la MISMA cookie sigue sirviendo (sesión no destruida),
    // pero ahora topa con el muro.
    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertStatus(403);

    // Da de alta y confirma su factor desde el muro (ambos permitidos).
    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => currentTotpCode($enroll->json('secret')),
        ])
        ->assertStatus(201);

    // CA-AUTH-131: la petición siguiente, con la MISMA cookie, ya no
    // topa con el muro — sin regenerar ni cerrar la sesión.
    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();
});

// CA-AUTH-132, RN-AUTH-61
test('CA-AUTH-132: un usuario obligado no puede desactivar su último factor utilizable', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['slug' => 'mfa-132']);
    Cache::forget("tenant-resolution:{$tenant->slug}");
    $password = 'Cl4v3-Correcta-2026!';

    [$user, $secret] = app(TenantContext::class)->runFor($tenant->id, function () use ($password): array {
        $role = Role::create(['code' => 'rol-132', 'name' => 'Rol 132', 'is_system' => false, 'mfa_required' => true]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['password' => $password, 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return [$user, null];
    });

    $secret = createConfirmedTotpFactor($tenant, $user, true);
    $factorPublicId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => MfaFactor::query()->where('user_id', $user->id)->firstOrFail()->public_id,
    );

    $login = loginWithTotpFor($tenant->slug, $user->email, $password, $secret);
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/mfa-factors/{$factorPublicId}"), ['current_password' => $password])
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($factorPublicId): void {
        expect(MfaFactor::query()->where('public_id', $factorPublicId)->whereNotNull('confirmed_at')->exists())->toBeTrue();
    });
});

// CA-AUTH-134, RN-AUTH-69
test('CA-AUTH-134: guardar mfa_allowed_methods sin totp, o con sms, responde 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('mfa-134');

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'security' => ['mfa_allowed_methods' => ['email']],
        ])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'security' => ['mfa_allowed_methods' => ['totp', 'sms']],
        ])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'security' => ['mfa_allowed_methods' => ['totp', 'email']],
        ])
        ->assertOk();
});

// CA-AUTH-133, RN-AUTH-69 punto 5. El método de entrega por correo no se
// puede dar de alta por HTTP en 1.3 (§C.16, el correo como segundo
// factor es 1.3b) — se crea el factor confirmado directamente para
// probar el mecanismo de reconciliación (ReconcileMfaAllowedMethodsChange,
// activado por TenantSettingsUpdated), que sí es de este paso.
test('CA-AUTH-133: retirar un método deja de admitir sus factores y reabre la obligación con plazo completo', function (): void {
    // Sin Queue::fake(): el listener debe ejecutarse de verdad.
    [$tenant, $admin] = provisionCoreTenant('mfa-133');

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): User {
        $role = Role::create(['code' => 'rol-133', 'name' => 'Rol 133', 'is_system' => false, 'mfa_required' => true]);
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        // Tenant admite email (necesario para que el CHECK de
        // mfa_allowed_methods lo acepte primero).
        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            ['mfa_allowed_methods' => json_encode(['totp', 'email']), 'mfa_grace_period_days' => 7, 'created_at' => now(), 'updated_at' => now()],
        );

        MfaFactor::create([
            'user_id' => $user->id,
            'method' => MfaMethod::Email,
            'confirmed_at' => now(),
        ]);

        return $user;
    });

    // Sin comprobación previa vía app(MfaPolicy::class) aquí a propósito:
    // MfaPolicy memoiza por instancia (scoped, RN-AUTH-62) y solo se
    // reinicia al terminar un ciclo real de Kernel::handle()/terminate()
    // — llamarla directamente contaminaría el memo con el estado ANTES
    // del PATCH de más abajo, que sí pasa por el kernel.
    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'security' => ['mfa_allowed_methods' => ['totp']],
        ])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $obligation = UserMfaObligation::query()->where('user_id', $user->id)->whereNull('resolved_at')->first();
        expect($obligation)->not->toBeNull()
            ->and($obligation->trigger)->toBe('metodo_retirado')
            ->and($obligation->obligated_since->diffInDays($obligation->grace_deadline_at))
            ->toBe(7.0);
    });
});
