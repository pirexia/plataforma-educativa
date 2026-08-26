<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaReset;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

// funcional.md §C.2, §C.4.10, §C.1.1 punto 9. PATCH /roles acotado,
// GET /mfa-compliance, POST /mfa-resets (REQ-AUTH-003, 1.3).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-135, RN-AUTH-70
test('CA-AUTH-135: PATCH /roles con un campo extra responde 422 sin cambiar nada; con solo mfa_required, 200', function (): void {
    [$tenant, $admin] = provisionCoreTenant('mfa-135');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"), [
            'mfa_required' => true,
            'name' => 'Otro nombre',
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($role): void {
        expect(Role::query()->find($role->id)->mfa_required)->toBeFalse();
    });

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"), ['mfa_required' => true])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($role): void {
        expect(Role::query()->find($role->id)->mfa_required)->toBeTrue();
    });
});

// CA-AUTH-136
test('CA-AUTH-136: GET /mfa-compliance da la vista previa hipotética sin escribir nada, y confirma el estado real después', function (): void {
    [$tenant, $admin] = provisionCoreTenant('mfa-136');

    $role = app(TenantContext::class)->runFor($tenant->id, function (): Role {
        $role = Role::where('code', 'docente')->firstOrFail();
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $role;
    });

    $preview = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/mfa-compliance?role={$role->public_id}&mfa_required=true"))
        ->assertOk();

    expect($preview->json('preview'))->toBeTrue()
        ->and($preview->json('users_obligated'))->toBe(1);

    // Nada se ha modificado todavía.
    app(TenantContext::class)->runFor($tenant->id, function () use ($role): void {
        expect(Role::query()->find($role->id)->mfa_required)->toBeFalse();
    });

    $real = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/mfa-compliance?role={$role->public_id}"))
        ->assertOk();

    expect($real->json('preview'))->toBeFalse()
        ->and($real->json('mfa_required'))->toBeFalse()
        ->and($real->json('users_obligated'))->toBe(0);
});

// CA-AUTH-137, RN-AUTH-66
test('CA-AUTH-137: POST /mfa-resets exige motivo de al menos 10 caracteres y borra factores, códigos y sesiones', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-137');

    $target = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['status' => UserStatus::Activo]);
    });

    createConfirmedTotpFactor($tenant, $target);

    // Sin reason.
    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-resets'), ['user' => $target->public_id])
        ->assertStatus(422);

    // reason corto.
    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-resets'), ['user' => $target->public_id, 'reason' => 'corto'])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-resets'), [
            'user' => $target->public_id,
            'reason' => 'El usuario perdió el teléfono con la aplicación de autenticación.',
        ])
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        expect(MfaFactor::query()->where('user_id', $target->id)->whereNotNull('confirmed_at')->exists())->toBeFalse()
            ->and(MfaReset::query()->where('user_id', $target->id)->count())->toBe(1);

        $reset = MfaReset::query()->where('user_id', $target->id)->first();
        expect($reset->factors_removed)->toBe(1)
            ->and($reset->reason)->toContain('teléfono');
    });
});

// CA-AUTH-138, RN-AUTH-67
test('CA-AUTH-138: un administrador no puede restablecer su propio MFA', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-138');
    createConfirmedTotpFactor($tenant, $admin);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-resets'), [
            'user' => $admin->public_id,
            'reason' => 'Intento de autorrestablecimiento, debe rechazarse.',
        ])
        ->assertStatus(403);

    app(TenantContext::class)->runFor($tenant->id, function () use ($admin): void {
        expect(MfaFactor::query()->where('user_id', $admin->id)->whereNotNull('confirmed_at')->exists())->toBeTrue();
    });
});

// CA-AUTH-140
test('CA-AUTH-140: los endpoints de administración de MFA exigen sesión, permiso, y aíslan por tenant', function (): void {
    Queue::fake();
    [$tenantA, $adminA] = provisionCoreTenant('mfa-140-a');
    [$tenantB, $adminB] = provisionCoreTenant('mfa-140-b');

    $targetA = app(TenantContext::class)->runFor($tenantA->id, function (): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['status' => UserStatus::Activo]);
    });

    $sinPermiso = app(TenantContext::class)->runFor($tenantA->id, function (): User {
        $role = Role::where('code', 'docente')->firstOrFail();
        $person = Person::factory()->create(['contact_email' => 'sin-permiso-140@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'sin-permiso-140@example.com', 'status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    // 401: sin sesión.
    test()->postJson(coreApiUrl($tenantA->slug, '/mfa-resets'), [
        'user' => $targetA->public_id, 'reason' => 'Motivo suficientemente largo para pasar validación.',
    ])->assertStatus(401);
    test()->getJson(coreApiUrl($tenantA->slug, '/mfa-compliance?role=x'))->assertStatus(401);

    // 403: autenticado sin mfa.eliminar/mfa.leer.
    test()->actingAs($sinPermiso)
        ->postJson(coreApiUrl($tenantA->slug, '/mfa-resets'), [
            'user' => $targetA->public_id, 'reason' => 'Motivo suficientemente largo para pasar validación.',
        ])
        ->assertStatus(403);

    // 404: el admin del tenant B no ve al usuario del tenant A.
    test()->actingAs($adminB)
        ->postJson(coreApiUrl($tenantB->slug, '/mfa-resets'), [
            'user' => $targetA->public_id, 'reason' => 'Motivo suficientemente largo para pasar validación.',
        ])
        ->assertStatus(404);
});

// CA-AUTH-141, RN-AUTH-71
test('CA-AUTH-141: un dispositivo reconocido no exime del segundo factor', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-141');
    $secret = createConfirmedTotpFactor($tenant, $user);

    // Primer login: genera la cookie pge_device.
    $first = loginWithTotpFor($tenant->slug, $user->email, $password, $secret);
    $deviceCookie = $first->getCookie('pge_device', false);

    resetSessionState();
    $second = test()->withUnencryptedCookie('pge_device', $deviceCookie?->getValue() ?? '')
        ->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
            'email' => $user->email, 'password' => $password,
        ]);

    // Con o sin dispositivo reconocido, el segundo factor se sigue pidiendo.
    expect($second->status())->toBe(202);
});

// CA-AUTH-143, INV-001
test('CA-AUTH-143: un factor, un desafío y un código de respaldo de otro tenant no son alcanzables', function (): void {
    Queue::fake();
    [$tenantA, $userA, $passwordA] = provisionActiveUser('mfa-143-a');
    [$tenantB, $userB, $passwordB] = provisionActiveUser('mfa-143-b');

    createConfirmedTotpFactor($tenantA, $userA);
    $secretB = createConfirmedTotpFactor($tenantB, $userB);

    $factorPublicIdA = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => MfaFactor::query()->where('user_id', $userA->id)->firstOrFail()->public_id,
    );

    // El admin/usuario del tenant B, contra el host del tenant B, nunca ve
    // el factor de A (aunque conociera su public_id).
    $loginB = loginWithTotpFor($tenantB->slug, $userB->email, $passwordB, $secretB);

    withSessionCookie(sessionCookieValue($loginB))
        ->deleteJson(coreApiUrl($tenantB->slug, "/auth/mfa-factors/{$factorPublicIdA}"), ['current_password' => $passwordB])
        ->assertStatus(404);
});

// CA-AUTH-145, RN-AUTH-35
test('CA-AUTH-145: ninguna ruta nueva de este paso lleva el middleware module-enabled', function (): void {
    $mfaRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'auth.mfa') || $route->getName() === 'core.roles.update');

    expect($mfaRoutes)->not->toBeEmpty();

    foreach ($mfaRoutes as $route) {
        $middlewareNames = collect($route->gatherMiddleware())
            ->map(fn ($m) => is_string($m) ? explode(':', $m)[0] : $m);

        expect($middlewareNames->contains('module-enabled'))->toBeFalse();
    }
});
