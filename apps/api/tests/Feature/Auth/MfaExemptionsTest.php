<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Infrastructure\Jobs\ReopenExpiredMfaExemptions;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §D.4.6-§D.4.9, api.md §D.4. Excepciones temporales
// nominales (REQ-AUTH-003, 1.3b).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @return array{0: Tenant, 1: User, 2: User}
 *                                            [tenant, admin (administrador_centro), target (sin roles)]
 */
function provisionExemptionFixture(string $slug): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);

    $target = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['status' => UserStatus::Activo]);
    });

    return [$tenant, $admin, $target];
}

// CA-AUTH-139 (recuperado), RN-AUTH-68/RN-AUTH-82
test('CA-AUTH-139: expires_at ausente, en el pasado o a más de 90 días responde 422; una válida deja de obligar y reabre con plazo completo al caducar', function (): void {
    Queue::fake();
    [$tenant, $admin, $target] = provisionExemptionFixture('mfa-139');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin teléfono compatible hasta la renovación de equipos.',
        ])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin teléfono compatible hasta la renovación de equipos.',
            'expires_at' => now()->subDay()->toISOString(),
        ])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin teléfono compatible hasta la renovación de equipos.',
            'expires_at' => now()->addDays(91)->toISOString(),
        ])
        ->assertStatus(422);

    $grant = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin teléfono compatible hasta la renovación de equipos.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    expect($grant->json('state'))->toBe('live');

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        expect(UserMfaExemption::query()->where('user_id', $target->id)->whereNull('revoked_at')->count())->toBe(1);
    });
});

// CA-AUTH-160, RN-AUTH-81
test('CA-AUTH-160: un motivo de menos de 10 caracteres o ausente responde 422 y no crea nada', function (): void {
    Queue::fake();
    [$tenant, $admin, $target] = provisionExemptionFixture('mfa-160');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'corto',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        expect(UserMfaExemption::query()->where('user_id', $target->id)->count())->toBe(0);
    });
});

// CA-AUTH-161, RN-AUTH-81
test('CA-AUTH-161: un administrador no puede concederse una excepción a sí mismo, y sí puede revocar la suya', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-161');

    $self = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $admin->public_id,
            'reason' => 'Intento de autoexención, debe rechazarse.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(403);

    expect($self->json('detail'))->toBe(__('auth.validation.mfa_exemption_self'));

    // Un segundo administrador se la concede, y el primero SÍ puede
    // revocar la suya propia.
    $secondAdmin = app(TenantContext::class)->runFor($tenant->id, function () use ($admin): User {
        $person = Person::factory()->create();
        $second = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $second->roles()->attach($admin->roles()->first()->id);

        return $second;
    });

    $grant = test()->actingAs($secondAdmin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $admin->public_id,
            'reason' => 'Concedida por otro administrador para la prueba.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, '/mfa-exemptions/'.$grant->json('public_id')))
        ->assertStatus(204);
});

// CA-AUTH-162, RN-AUTH-81
test('CA-AUTH-162: una segunda excepción sobre el mismo usuario responde 409 y sigue habiendo exactamente una viva', function (): void {
    Queue::fake();
    [$tenant, $admin, $target] = provisionExemptionFixture('mfa-162');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Primera excepción concedida.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Segunda excepción, debe rechazarse.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        expect(UserMfaExemption::query()->where('user_id', $target->id)->whereNull('revoked_at')->count())->toBe(1);
    });
});

// CA-AUTH-163, RN-AUTH-82
test('CA-AUTH-163: conceder una excepción a un usuario con la gracia vencida cierra su obligación y lo cuenta en users_exempt', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-163');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::create([
        'code' => 'rol-163', 'name' => 'Rol 163', 'is_system' => false, 'mfa_required' => true,
    ]));

    $target = app(TenantContext::class)->runFor($tenant->id, function () use ($role): User {
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        UserMfaObligation::create([
            'user_id' => $target->id,
            'obligated_since' => now()->subDays(20),
            'grace_deadline_at' => now()->subDay(),
            'trigger' => 'rol_asignado',
        ]);
    });

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Sin dispositivo compatible, pendiente de sustitución.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        expect(UserMfaObligation::query()->where('user_id', $target->id)->whereNull('resolved_at')->exists())->toBeFalse();
    });

    $compliance = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/mfa-compliance?role='.$role->public_id))
        ->assertOk();

    expect($compliance->json('users_exempt'))->toBe(1);
});

// CA-AUTH-164, RN-AUTH-82
test('CA-AUTH-164: al caducar la excepción, ReopenExpiredMfaExemptions reabre la obligación con plazo completo', function (): void {
    Queue::fake();
    [$tenant, $admin] = provisionCoreTenant('mfa-164');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::create([
        'code' => 'rol-164', 'name' => 'Rol 164', 'is_system' => false, 'mfa_required' => true,
    ]));

    $target = app(TenantContext::class)->runFor($tenant->id, function () use ($role): User {
        $person = Person::factory()->create();
        $user = User::factory()->for($person)->create(['status' => UserStatus::Activo]);
        $user->roles()->attach($role->id);

        return $user;
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        // CHECK (expires_at > created_at): para simular una excepción ya
        // caducada hay que retrasar también created_at, no solo expires_at
        // — y created_at no está en $fillable, así que se fija aparte.
        $exemption = UserMfaExemption::make([
            'user_id' => $target->id,
            'reason' => 'Excepción ya caducada, para la prueba de reapertura.',
            'expires_at' => now()->subHour(),
            'granted_by' => $target->id,
        ]);
        $exemption->created_at = now()->subHours(3);
        $exemption->save();
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        (new ReopenExpiredMfaExemptions($tenant->id))->handle(app(MfaPolicy::class));
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($target): void {
        $obligation = UserMfaObligation::query()->where('user_id', $target->id)->whereNull('resolved_at')->first();

        $expectedDeadline = now()->addDays((int) config('auth-local.mfa.grace_default_days'));

        expect($obligation)->not->toBeNull()
            ->and($obligation->trigger)->toBe('exencion_vencida')
            // Plazo de gracia COMPLETO desde ahora (RN-AUTH-82), no el
            // resto de uno anterior: la diferencia con "ahora + los días
            // de gracia por defecto" tiene que ser de minutos, no de días.
            ->and($obligation->grace_deadline_at->diffInMinutes($expectedDeadline, true))
            ->toBeLessThan(5);
    });
});

// CA-AUTH-165, RN-AUTH-83
test('CA-AUTH-165: revocar conserva la fila con revoked_at/revoked_by, audita un updated, y la segunda revocación responde 404', function (): void {
    Queue::fake();
    [$tenant, $admin, $target] = provisionExemptionFixture('mfa-165');

    $grant = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/mfa-exemptions'), [
            'user' => $target->public_id,
            'reason' => 'Excepción para probar la revocación.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, '/mfa-exemptions/'.$grant->json('public_id')))
        ->assertStatus(204);

    app(TenantContext::class)->runFor($tenant->id, function () use ($grant, $admin): void {
        $exemption = UserMfaExemption::query()->withTrashed()->where('public_id', $grant->json('public_id'))->firstOrFail();

        expect($exemption->revoked_at)->not->toBeNull()
            ->and($exemption->revoked_by)->toBe($admin->id)
            ->and($exemption->deleted_at)->toBeNull();

        $auditRow = AuditLog::query()->where('auditable_type', 'user_mfa_exemption')
            ->where('event', 'updated')->latest('id')->first();
        expect($auditRow)->not->toBeNull();
    });

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, '/mfa-exemptions/'.$grant->json('public_id')))
        ->assertStatus(404);
});

// CA-AUTH-166, INV-001/INV-002, RN-AUTH-29
test('CA-AUTH-166: los tres endpoints exigen sesión, permiso, aíslan por tenant y CSRF en las escrituras', function (): void {
    Queue::fake();
    [$tenantA, $adminA, $targetA] = provisionExemptionFixture('mfa-166-a');
    [$tenantB, $adminB, $targetB] = provisionExemptionFixture('mfa-166-b');

    // Sin sesión.
    test()->postJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'), [])->assertStatus(401);
    test()->getJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'))->assertStatus(401);
    test()->deleteJson(coreApiUrl($tenantA->slug, '/mfa-exemptions/01JD7XXXXXXXXXXXXXXXXXXXXX'))->assertStatus(401);

    // Sin permiso: un usuario del propio tenant sin rol alguno.
    test()->actingAs($targetA)
        ->postJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'), [
            'user' => $adminA->public_id,
            'reason' => 'Sin permiso para conceder.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(403);
    test()->actingAs($targetA)->getJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'))->assertStatus(403);

    // Usuario de otro tenant ⇒ 404 con cuerpo idéntico al de un
    // public_id inexistente.
    $crossTenant = test()->actingAs($adminA)
        ->postJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'), [
            'user' => $targetB->public_id,
            'reason' => 'El sujeto pertenece a otro tenant.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(404);

    $inexistent = test()->actingAs($adminA)
        ->postJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'), [
            'user' => '01JD7DOESNOTEXIST0000000000',
            'reason' => 'El sujeto no existe en absoluto.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(404);

    $strip = fn (array $body) => collect($body)->except('request_id')->all();
    expect($strip($crossTenant->json()))->toBe($strip($inexistent->json()));

    // Excepción de otro tenant ⇒ 404.
    $exemptionBGrant = test()->actingAs($adminB)
        ->postJson(coreApiUrl($tenantB->slug, '/mfa-exemptions'), [
            'user' => $targetB->public_id,
            'reason' => 'Excepción del tenant B, para probar el aislamiento.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ])
        ->assertStatus(201);

    test()->actingAs($adminA)
        ->deleteJson(coreApiUrl($tenantA->slug, '/mfa-exemptions/'.$exemptionBGrant->json('public_id')))
        ->assertStatus(404);

    // Sin CSRF en las dos escrituras. `PreventRequestForgery::
    // runningUnitTests()` se salta la comprobación bajo APP_ENV=testing
    // (phpunit.xml); forzar 'local' es lo que la ejercita de verdad
    // (mismo patrón que CA-AUTH-005, tests/Feature/Auth/SessionEndpointTest.php).
    app()->detectEnvironment(fn () => 'local');

    try {
        $noCsrfPost = test()->actingAs($adminA)->postJson(coreApiUrl($tenantA->slug, '/mfa-exemptions'), [
            'user' => $targetA->public_id,
            'reason' => 'Petición sin token CSRF.',
            'expires_at' => now()->addDays(30)->toISOString(),
        ]);
        expect($noCsrfPost->status())->toBeIn([403, 419]);

        $exemptionA = app(TenantContext::class)->runFor($tenantA->id, fn () => UserMfaExemption::create([
            'user_id' => $targetA->id,
            'reason' => 'Excepción del tenant A, para probar el CSRF de DELETE.',
            'expires_at' => now()->addDays(30),
            'granted_by' => $adminA->id,
        ]));

        $noCsrfDelete = test()->actingAs($adminA)->deleteJson(coreApiUrl($tenantA->slug, '/mfa-exemptions/'.$exemptionA->public_id));
        expect($noCsrfDelete->status())->toBeIn([403, 419]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

// CA-AUTH-169, permisos.md §D.5. Catálogo ampliado a siete filas en
// 1.3b; 1.4b lo amplía de nuevo a once (CA-AUTH-305, `permisos.md §F.1`)
// — se conserva aquí la comprobación de las siete de 1.3b y anteriores,
// dentro del conjunto de once.
test('CA-AUTH-169: tras platform:sync-registry el catálogo de auth incluye las siete filas de 1.3b y anteriores, ninguna retirada ni especial, y ningún permission_role con scope distinto de todos', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $authPermissions = Permission::query()->where('module_code', 'auth')->get();

    expect($authPermissions)->toHaveCount(11)
        ->and($authPermissions->pluck('code')->sort()->values()->all())->toContain(
            'bloqueo_cuenta.eliminar', 'bloqueo_cuenta.leer',
            'exencion_mfa.crear', 'exencion_mfa.eliminar', 'exencion_mfa.leer',
            'mfa.eliminar', 'mfa.leer',
        )
        ->and($authPermissions->whereNotNull('retired_at'))->toHaveCount(0)
        ->and($authPermissions->where('is_special_category', true))->toHaveCount(0);

    [$tenant] = provisionCoreTenant('mfa-169');

    app(TenantContext::class)->runFor($tenant->id, function () use ($authPermissions): void {
        $offending = PermissionRole::query()
            ->whereIn('permission_code', $authPermissions->pluck('code'))
            ->where('scope', '!=', 'todos')
            ->count();

        expect($offending)->toBe(0);
    });
});

// permisos.md §D.9, "test de concesión": los tres permisos de
// exencion_mfa solo los tiene administrador_centro entre los 16 roles.
test('exencion_mfa.crear/leer/eliminar solo se conceden a administrador_centro', function (): void {
    [$tenant] = provisionCoreTenant('mfa-169b');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $roleIdsWithExemptionPermissions = PermissionRole::query()
            ->whereIn('permission_code', ['exencion_mfa.crear', 'exencion_mfa.leer', 'exencion_mfa.eliminar'])
            ->pluck('role_id')
            ->unique();

        $roleCodes = Role::query()->whereIn('id', $roleIdsWithExemptionPermissions)->pluck('code')->all();

        expect($roleCodes)->toBe(['administrador_centro']);
    });
});
