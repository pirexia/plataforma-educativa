<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\UnlockReason;
use App\Modules\Auth\Infrastructure\Jobs\SendAccountLockedEmail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §4.4, RN-AUTH-14 a RN-AUTH-19/RN-AUTH-38. Bloqueo de cuenta
// tras 5 intentos fallidos consecutivos.

beforeEach(function (): void {
    // El foco de estos tests es el bloqueo, no CA-AUTH-074 (su propio
    // test en SessionEndpointTest.php) — varios tests encadenan más de
    // cinco intentos sobre el mismo (tenant, email).
    config(['auth-local.rate_limits.session_email.max' => 200]);
    config(['auth-local.rate_limits.session_ip.max' => 200]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function attachAuthTestRole(Tenant $tenant, string $email, string $roleCode): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($email, $roleCode) {
        $person = Person::factory()->create(['contact_email' => $email]);
        $user = User::factory()->for($person)->create(['email' => $email]);
        $role = Role::where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });
}

function failLoginTimes(string $slug, string $email, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->postJson(coreApiUrl($slug, '/auth/session'), [
            'email' => $email, 'password' => 'contraseña-incorrecta-'.$i,
        ]);
    }
}

// CA-AUTH-026, RN-AUTH-16
test('CA-AUTH-026: el quinto fallo consecutivo bloquea, y con bloqueo vivo ni la contraseña correcta entra', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('lock-026');

    failLoginTimes($tenant->slug, $user->email, 5);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(AccountLockout::query()->where('email', $user->email)->whereNull('unlocked_at')->exists())->toBeTrue();
    });

    // Sexto intento con contraseña incorrecta: 423.
    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => 'todavia-incorrecta',
    ])->assertStatus(423)->assertJson(['type' => 'urn:pge:error:account-locked']);

    // Contraseña CORRECTA: sigue en 423, no entra (RN-AUTH-16: no se
    // verifica la contraseña con bloqueo vivo).
    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(423);
});

// CA-AUTH-025, RN-AUTH-14
test('CA-AUTH-025: cuatro fallos y un acierto a la quinta entra y pone el contador a cero', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('lock-025');

    failLoginTimes($tenant->slug, $user->email, 4);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    // Cuatro fallos más NO deberían bloquear: el contador se puso a cero.
    failLoginTimes($tenant->slug, $user->email, 4);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(AccountLockout::query()->where('email', $user->email)->exists())->toBeFalse();
    });
});

// CA-AUTH-027, RN-AUTH-15: bloqueo fantasma indistinguible de uno real.
test('CA-AUTH-027: cinco fallos sobre un correo inexistente producen la misma respuesta 423 que una cuenta real bloqueada', function (): void {
    Queue::fake();
    [$tenantReal, $realUser] = provisionActiveUser('lock-027-a');
    [$tenantPhantom] = provisionActiveUser('lock-027-b');

    failLoginTimes($tenantReal->slug, $realUser->email, 5);
    failLoginTimes($tenantPhantom->slug, 'nadie-existe@example.com', 5);

    $realLocked = test()->postJson(coreApiUrl($tenantReal->slug, '/auth/session'), [
        'email' => $realUser->email, 'password' => 'x',
    ])->assertStatus(423)->json();

    $phantomLocked = test()->postJson(coreApiUrl($tenantPhantom->slug, '/auth/session'), [
        'email' => 'nadie-existe@example.com', 'password' => 'x',
    ])->assertStatus(423)->json();

    $strip = fn (array $body) => collect($body)->except('request_id')->all();

    expect($strip($realLocked))->toBe($strip($phantomLocked));

    app(TenantContext::class)->runFor($tenantPhantom->id, function (): void {
        expect(AccountLockout::query()->where('email', 'nadie-existe@example.com')->whereNull('user_id')->exists())->toBeTrue();
    });
});

// CA-AUTH-028, INV-012
test('CA-AUTH-028: el bloqueo de una cuenta real encola el correo de aviso; el fantasma no encola nada', function (): void {
    Queue::fake();
    [$tenantReal, $realUser] = provisionActiveUser('lock-028-a');
    [$tenantPhantom] = provisionActiveUser('lock-028-b');

    failLoginTimes($tenantReal->slug, $realUser->email, 5);
    Queue::assertPushed(SendAccountLockedEmail::class, fn ($job) => $job->recipientEmail === $realUser->email);

    Queue::fake();
    failLoginTimes($tenantPhantom->slug, 'fantasma@example.com', 5);
    Queue::assertNotPushed(SendAccountLockedEmail::class);
});

// CA-AUTH-023, RN-AUTH-14
test('CA-AUTH-023: pasados los minutos de bloqueo, el login vuelve a funcionar y la fila queda con unlock_reason=caducidad', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('lock-023');

    failLoginTimes($tenant->slug, $user->email, 5);

    Carbon::setTestNow(Carbon::now()->addMinutes(16));

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $lockout = AccountLockout::query()->where('email', $user->email)->latest('id')->first();
        expect($lockout->unlocked_at)->not->toBeNull()
            ->and($lockout->unlock_reason)->toBe(UnlockReason::Caducidad);
    });
});

// CA-AUTH-024, RN-AUTH-38, RN-AUTH-17
test('CA-AUTH-024: un bloqueo vencido y sin cerrar no impide crear el siguiente tras más fallos', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('lock-024');

    failLoginTimes($tenant->slug, $user->email, 5);

    $firstLockoutId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => AccountLockout::query()->where('email', $user->email)->whereNull('unlocked_at')->firstOrFail()->id,
    );

    // Vence, pero nadie (ni CloseExpiredLockouts) lo ha cerrado todavía.
    Carbon::setTestNow(Carbon::now()->addMinutes(16));

    // No debe lanzar una violación de índice único (tenant_id, email) WHERE
    // unlocked_at IS NULL: el camino de login cierra el vencido en la
    // misma transacción antes de crear el siguiente (RN-AUTH-38).
    failLoginTimes($tenant->slug, $user->email, 5);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $firstLockoutId): void {
        $first = AccountLockout::query()->find($firstLockoutId);
        expect($first->unlock_reason)->toBe(UnlockReason::Caducidad);

        $liveCount = AccountLockout::query()->where('email', $user->email)->whereNull('unlocked_at')->count();
        expect($liveCount)->toBe(1);
    });
});

// CA-AUTH-029, RN-AUTH-13
test('CA-AUTH-029: el desbloqueo por el token de correo funciona una sola vez, dentro de la ventana', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('lock-029');

    failLoginTimes($tenant->slug, $user->email, 5);

    $rawToken = null;
    Queue::assertPushed(SendAccountLockedEmail::class, function ($job) use (&$rawToken) {
        $rawToken = $job->rawUnlockToken;

        return true;
    });

    test()->postJson(coreApiUrl($tenant->slug, '/auth/account-unlocks'), ['token' => $rawToken])
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $lockout = AccountLockout::query()->where('email', $user->email)->latest('id')->first();
        expect($lockout->unlocked_at)->not->toBeNull()
            ->and($lockout->unlock_reason)->toBe(UnlockReason::Correo)
            ->and($lockout->unlocked_by)->toBeNull();
    });

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    // Segundo uso del mismo token: 410.
    test()->postJson(coreApiUrl($tenant->slug, '/auth/account-unlocks'), ['token' => $rawToken])
        ->assertStatus(410)
        ->assertJson(['type' => 'urn:pge:error:gone']);
});

test('desbloqueo por correo: token caducado responde 410', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('lock-029b');

    failLoginTimes($tenant->slug, $user->email, 5);

    $rawToken = null;
    Queue::assertPushed(SendAccountLockedEmail::class, function ($job) use (&$rawToken) {
        $rawToken = $job->rawUnlockToken;

        return true;
    });

    Carbon::setTestNow(Carbon::now()->addHours(25));

    test()->postJson(coreApiUrl($tenant->slug, '/auth/account-unlocks'), ['token' => $rawToken])
        ->assertStatus(410);
});

test('desbloqueo por correo: token inexistente responde 410, cuerpo idéntico al caducado', function (): void {
    [$tenant] = provisionActiveUser('lock-029c');

    test()->postJson(coreApiUrl($tenant->slug, '/auth/account-unlocks'), ['token' => 'no-existe-este-token'])
        ->assertStatus(410)
        ->assertJson(['type' => 'urn:pge:error:gone']);
});

// CA-AUTH-030, INV-003
test('CA-AUTH-030: un Administrador de Centro desbloquea con DELETE /account-lockouts/{public_id}', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('lock-030');
    $victim = attachAuthTestRole($tenant, 'victima@example.com', 'docente');

    failLoginTimes($tenant->slug, $victim->email, 5);

    $publicId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => AccountLockout::query()->where('email', $victim->email)->firstOrFail()->public_id,
    );

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/account-lockouts/{$publicId}"))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($publicId, $admin): void {
        $lockout = AccountLockout::query()->where('public_id', $publicId)->firstOrFail();
        expect($lockout->unlock_reason)->toBe(UnlockReason::Administrador)
            ->and($lockout->unlocked_by)->toBe($admin->id);

        $log = \App\Models\AuditLog::where('auditable_type', 'account_lockout')
            ->where('event', 'updated')->latest('id')->first();
        expect($log)->not->toBeNull();
    });
});

// CA-AUTH-030: segundo DELETE sobre un bloqueo ya levantado -> 409.
test('DELETE /account-lockouts/{public_id} sobre un bloqueo ya levantado responde 409', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('lock-030b');
    $victim = attachAuthTestRole($tenant, 'ya-libre@example.com', 'docente');

    failLoginTimes($tenant->slug, $victim->email, 5);

    $publicId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => AccountLockout::query()->where('email', $victim->email)->firstOrFail()->public_id,
    );

    test()->actingAs($admin)->deleteJson(coreApiUrl($tenant->slug, "/account-lockouts/{$publicId}"))->assertNoContent();
    test()->actingAs($admin)->deleteJson(coreApiUrl($tenant->slug, "/account-lockouts/{$publicId}"))->assertStatus(409);
});

// CA-AUTH-031, CA-AUTH-070
test('CA-AUTH-031/070: sin sesión 401, sin permiso 403, bloqueo de otro tenant 404', function (): void {
    Queue::fake();
    [$tenantA, $adminA] = provisionCoreTenant('lock-031-a');
    [$tenantB, $adminB] = provisionCoreTenant('lock-031-b');
    $victimA = attachAuthTestRole($tenantA, 'victima-a@example.com', 'docente');
    $sinPermiso = attachAuthTestRole($tenantA, 'sin-permiso@example.com', 'docente');

    failLoginTimes($tenantA->slug, $victimA->email, 5);

    $publicIdA = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => AccountLockout::query()->where('email', $victimA->email)->firstOrFail()->public_id,
    );

    // 401: sin sesión.
    test()->deleteJson(coreApiUrl($tenantA->slug, "/account-lockouts/{$publicIdA}"))->assertStatus(401);
    test()->getJson(coreApiUrl($tenantA->slug, '/account-lockouts'))->assertStatus(401);

    // 403: autenticado sin bloqueo_cuenta.eliminar/leer.
    test()->actingAs($sinPermiso)
        ->deleteJson(coreApiUrl($tenantA->slug, "/account-lockouts/{$publicIdA}"))
        ->assertStatus(403);
    test()->actingAs($sinPermiso)
        ->getJson(coreApiUrl($tenantA->slug, '/account-lockouts'))
        ->assertStatus(403);

    // 404: el admin del tenant B no ve el bloqueo del tenant A.
    test()->actingAs($adminB)
        ->deleteJson(coreApiUrl($tenantB->slug, "/account-lockouts/{$publicIdA}"))
        ->assertStatus(404);
});

// api.md §5: filtro status y búsqueda q del listado.
test('GET /account-lockouts filtra por status y busca por correo', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('lock-list');
    $victim1 = attachAuthTestRole($tenant, 'buscar-uno@example.com', 'docente');
    $victim2 = attachAuthTestRole($tenant, 'buscar-dos@example.com', 'docente');

    failLoginTimes($tenant->slug, $victim1->email, 5);
    failLoginTimes($tenant->slug, $victim2->email, 5);

    $publicId2 = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => AccountLockout::query()->where('email', $victim2->email)->firstOrFail()->public_id,
    );
    test()->actingAs($admin)->deleteJson(coreApiUrl($tenant->slug, "/account-lockouts/{$publicId2}"))->assertNoContent();

    $vigentes = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/account-lockouts?status=vigente'))
        ->assertOk()->json('data');
    expect(collect($vigentes)->pluck('email')->all())->toBe([$victim1->email]);

    $levantados = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/account-lockouts?status=levantado'))
        ->assertOk()->json('data');
    expect(collect($levantados)->pluck('email')->all())->toBe([$victim2->email]);

    $busqueda = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/account-lockouts?status=vigente,levantado&q=buscar-dos'))
        ->assertOk()->json('data');
    expect(collect($busqueda)->pluck('email')->all())->toBe([$victim2->email]);

    // Nunca se expone material de token.
    foreach ([...$vigentes, ...$levantados] as $row) {
        expect($row)->not->toHaveKey('unlock_token_hash');
    }
});
