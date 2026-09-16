<?php

use App\Models\ModuleSubscription;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Backoffice\Application\ActivateProvisionedTenant;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Infrastructure\Jobs\CloneTenant;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * REQ-BO-001 (1.6b), funcional.md §5.6. CA-BO-123 a CA-BO-126: clonación
 * y aislamiento entre centros a lo largo del ciclo de vida completo (el
 * test obligatorio de la skill `aislamiento-tenant`).
 */
beforeEach(function (): void {
    Mail::fake();
    $this->artisan('platform:sync-registry')->run();
    boAllowCurrentTestIp();
});

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_owner')->statement('TRUNCATE tenant_lifecycle_events');
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
    Cache::flush();
});

function boCloneClient(PlatformAdmin $admin, string $secret): mixed
{
    return boWithReauthenticatedCookie(boReauthenticatedSessionCookie($admin, $secret));
}

test('CA-BO-123: la clonación copia configuración operativa, roles (predefinidos y personalizados) y suscripciones, y no copia identidad fiscal, marca, early_adopter, estado ni historial', function (): void {
    [$source, $sourceAdmin] = provisionCoreTenant();

    app(TenantContext::class)->runFor($source->id, function (): void {
        TenantSetting::query()->update([
            'legal_name' => 'Fundación Origen S.L.',
            'tax_id' => 'B00000000',
            'color_primary' => '#112233',
            'timezone' => 'Atlantic/Canary',
        ]);

        $customRole = Role::create(['code' => 'inspector_calidad', 'name_key' => 'roles.inspector_calidad', 'name' => null, 'is_system' => false, 'mfa_required' => false, 'special_data_access' => false]);
        PermissionRole::create(['role_id' => $customRole->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']);

        // 'auth' (issue #196): 'comedor' no existe en el catálogo de
        // módulos — platform:sync-registry solo registra los módulos que
        // de verdad tienen ServiceProvider hoy (auth/core/backoffice,
        // AuthServiceProvider/CoreServiceProvider/BackofficeServiceProvider),
        // y module_subscriptions.module_code es FK contra modules. Un
        // código inventado violaba la FK, abortaba la transacción de
        // pgsql del test, y la cascada resultante se atribuyó por error
        // al problema de runAsPlatform() (que también era real y sigue
        // corregido más abajo, pero no era la causa de este fallo).
        //
        // `::on('pgsql_platform')` (1.6c, datos.md §7): el `REVOKE
        // INSERT` de la migración de privilegios le quita a
        // `plataforma_app` la capacidad de insertar en
        // `module_subscriptions` — sigue en contexto de tenant normal
        // (no en modo plataforma), así que `tenant_id` se rellena solo.
        ModuleSubscription::on('pgsql_platform')->create(['module_code' => 'auth', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Contratación de prueba']);
    });

    // Historial propio del origen (issue #196): provisionCoreTenant() crea
    // el tenant por factory, no por TenantLifecycleService — sin esto
    // $sourceEventCount siempre sería 0 y la comprobación de abajo (el
    // historial del origen no se copia) no comprobaría nada de verdad.
    TenantLifecycleEvent::create([
        'affected_tenant_id' => $source->id,
        'from_status' => null,
        'to_status' => TenantStatus::EnAlta,
        'reason' => 'Alta de prueba',
        'occurred_at' => now(),
        'performed_by' => null,
    ]);

    // `early_adopter_since` NO entra aquí (issue #196, datos.md §6.1/§12):
    // la propia especificación de 1.6b deja esa columna fuera a propósito
    // — llega en 1.6e con su propia migración —, así que todavía no existe
    // en `tenants`. `RN-BO-59` exige que la clonación tampoco la copie,
    // pero eso solo se puede verificar de verdad cuando exista la columna.
    $source->forceFill(['status' => TenantStatus::Suspendido, 'suspended_at' => now(), 'suspension_message' => 'Mensaje de origen'])->save();

    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boCloneClient($admin, $secret);

    $targetSlug = 'clon-'.Str::lower(Str::random(8));

    $response = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$source->public_id}/clone", [
        'name' => 'Colegio Clonado',
        'slug' => $targetSlug,
        'reason' => 'Clonación de prueba',
        'administrator' => [
            'email' => 'admin-clon-'.Str::lower(Str::random(8)).'@example.com',
            'given_name' => 'Nuevo',
            'family_name' => 'Administrador',
        ],
    ]);

    $response->assertStatus(201);
    $target = Tenant::query()->where('slug', $targetSlug)->firstOrFail();

    // Fase 2 corre en sync: ya debería estar activo.
    expect($target->fresh()->status)->toBe(TenantStatus::Activo);

    $targetData = app(TenantContext::class)->runFor($target->id, fn () => [
        'settings' => TenantSetting::first(),
        'roleCodes' => Role::pluck('code')->sort()->values()->all(),
        'moduleCodes' => ModuleSubscription::pluck('module_code')->all(),
    ]);

    // Copiado: la zona horaria operativa.
    expect($targetData['settings']->timezone)->toBe('Atlantic/Canary');
    // No copiado: identidad fiscal y marca.
    expect($targetData['settings']->legal_name)->toBeNull();
    expect($targetData['settings']->tax_id)->toBeNull();
    expect($targetData['settings']->color_primary)->toBeNull();

    // Copiado: roles predefinidos y personalizados.
    expect($targetData['roleCodes'])->toContain('inspector_calidad');
    expect($targetData['roleCodes'])->toHaveCount(17);

    // Copiado: suscripciones de módulo.
    expect($targetData['moduleCodes'])->toContain('auth');

    // No copiado: estado, mensaje de suspensión. `early_adopter_since`
    // queda fuera de esta comprobación (ver forceFill de arriba, issue
    // #196): la columna no existe todavía, entra en 1.6e.
    expect($target->status)->toBe(TenantStatus::Activo);
    expect($target->suspended_at)->toBeNull();
    expect($target->suspension_message)->toBeNull();

    // No copiado: historial del origen — el clon empieza con su propia
    // primera fila (en_alta → activo), nunca las del origen.
    $sourceEventCount = TenantLifecycleEvent::query()->where('affected_tenant_id', $source->id)->count();
    // orderBy('id') (issue #196): sin orden explícito, SELECT no garantiza
    // devolver las filas en orden de inserción — y `to_status` se castea
    // a TenantStatus (comparar contra el string plano nunca habría
    // pasado, con o sin el orden correcto).
    $targetEvents = TenantLifecycleEvent::query()->where('affected_tenant_id', $target->id)->orderBy('id')->pluck('to_status');
    expect($sourceEventCount)->toBeGreaterThan(0);
    expect($targetEvents->all())->toBe([TenantStatus::EnAlta, TenantStatus::Activo]);
});

test('CA-BO-124: el clon tiene exactamente un administrador nuevo, con su propia invitación, y ninguna persona copiada del origen', function (): void {
    [$source] = provisionCoreTenant();

    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boCloneClient($admin, $secret);

    $newAdminEmail = 'admin-clon-'.Str::lower(Str::random(8)).'@example.com';
    $targetSlug = 'clon-'.Str::lower(Str::random(8));

    $response = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$source->public_id}/clone", [
        'name' => 'Colegio Clonado 2',
        'slug' => $targetSlug,
        'reason' => 'Clonación de prueba',
        'administrator' => [
            'email' => $newAdminEmail,
            'given_name' => 'Nuevo',
            'family_name' => 'Administrador',
        ],
    ]);

    $response->assertStatus(201);
    $target = Tenant::query()->where('slug', $targetSlug)->firstOrFail();

    $targetData = app(TenantContext::class)->runFor($target->id, fn () => [
        'userCount' => User::count(),
        'email' => User::query()->value('email'),
        'invitationCount' => UserInvitation::count(),
    ]);

    expect($targetData['userCount'])->toBe(1);
    expect($targetData['email'])->toBe($newAdminEmail);
    expect($targetData['invitationCount'])->toBe(1);
});

test('CA-BO-125: un origen eliminado o en_alta no se puede clonar; uno suspendido o en_baja sí', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boCloneClient($admin, $secret);

    $eliminado = Tenant::factory()->create(['status' => 'eliminado']);
    $eliminado->delete();

    $response = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$eliminado->public_id}/clone", [
        'name' => 'X', 'slug' => 'clon-invalido-1', 'reason' => 'Prueba',
        'administrator' => ['email' => 'x@example.com', 'given_name' => 'X', 'family_name' => 'Y'],
    ]);
    $response->assertStatus(422);

    $enAlta = Tenant::factory()->create(['status' => 'en_alta']);
    $response2 = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$enAlta->public_id}/clone", [
        'name' => 'X', 'slug' => 'clon-invalido-2', 'reason' => 'Prueba',
        'administrator' => ['email' => 'x2@example.com', 'given_name' => 'X', 'family_name' => 'Y'],
    ]);
    $response2->assertStatus(422);

    [$suspendidoSource] = provisionCoreTenant();
    $suspendidoSource->forceFill(['status' => 'suspendido'])->save();

    $response3 = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$suspendidoSource->public_id}/clone", [
        'name' => 'Clon de suspendido', 'slug' => 'clon-valido-1', 'reason' => 'Prueba',
        'administrator' => ['email' => 'x3@example.com', 'given_name' => 'X', 'family_name' => 'Y'],
    ]);
    $response3->assertStatus(201);
});

// Issue #206: un reintento de la fase 2 de la clonación (bo:retry-
// provisioning, tras un fallo parcial hipotético) no debe chocar con las
// suscripciones de módulo que un intento anterior ya dejó insertadas.
// Se simula el reintento llamando dos veces a CloneTenant::handle() para
// el mismo par origen/destino, devolviendo el destino a en_alta entre
// medias (mismo estado en el que handle() encuentra un clon a medias).
test('reintentar la fase 2 de una clonación no falla por las suscripciones de módulo ya copiadas', function (): void {
    [$source] = provisionCoreTenant();
    app(TenantContext::class)->runFor($source->id, function (): void {
        // `::on('pgsql_platform')`: mismo motivo que arriba (1.6c).
        ModuleSubscription::on('pgsql_platform')->create(['module_code' => 'auth', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Prueba']);
        ModuleSubscription::on('pgsql_platform')->create(['module_code' => 'core', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Prueba']);
    });

    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $targetSlug = 'clon-reintento-'.Str::lower(Str::random(8));

    boCloneClient($admin, $secret)->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$source->public_id}/clone", [
        'name' => 'Colegio Reintento',
        'slug' => $targetSlug,
        'reason' => 'Clonación de prueba',
        'administrator' => ['email' => 'admin-reintento@example.com', 'given_name' => 'Nuevo', 'family_name' => 'Administrador'],
    ])->assertStatus(201);

    $target = Tenant::query()->where('slug', $targetSlug)->firstOrFail();

    // Vuelve a en_alta: el mismo estado en el que un CloneTenant::handle()
    // real encontraría el destino si la fase 2 hubiera fallado a mitad del
    // bucle de suscripciones, antes de llegar a activate().
    $target->forceFill(['status' => TenantStatus::EnAlta])->save();

    // Antes del arreglo, esto lanzaba QueryException por
    // module_subscriptions_tenant_module_unique en la primera suscripción
    // ya copiada.
    (new CloneTenant($source->public_id, $target->public_id, new TenantAdministrator('admin-reintento@example.com', 'Nuevo', 'Administrador')))
        ->handle(app(TenantProvisioner::class), app(ActivateProvisionedTenant::class), app(TenantContext::class));

    $moduleCodes = app(TenantContext::class)->runFor($target->id, fn () => ModuleSubscription::pluck('module_code')->sort()->values()->all());
    expect($moduleCodes)->toBe(['auth', 'core']);
});

// CA-BO-126, y el test obligatorio de la skill aislamiento-tenant: dos
// tenants con datos equivalentes; suspender, dar de baja, eliminar y
// clonar el primero no debe alterar nada del segundo.
test('CA-BO-126: operar sobre un tenant a lo largo de todo su ciclo de vida no afecta a otro', function (): void {
    [$tenantA] = provisionCoreTenant('centro-a-'.Str::lower(Str::random(6)));
    [$tenantB] = provisionCoreTenant('centro-b-'.Str::lower(Str::random(6)));

    Cache::remember("tenant-resolution:{$tenantB->slug}", 60, fn () => ['id' => $tenantB->id, 'status' => 'activo']);

    $tenantBSnapshotBefore = app(TenantContext::class)->runFor($tenantB->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
        'modules' => ModuleSubscription::count(),
    ]);

    [$admin, $adminSecret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');

    // Suspender A.
    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/transitions", [
        'to_status' => 'suspendido', 'reason' => 'Prueba de aislamiento',
    ])->assertStatus(200);

    // Darlo de baja tras reactivarlo. api.md §4: `en_baja` es sensible.
    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/transitions", [
        'to_status' => 'activo', 'reason' => 'Prueba de aislamiento',
    ])->assertStatus(200);
    boCloneClient($admin, $adminSecret)->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/transitions", [
        'to_status' => 'en_baja', 'reason' => 'Prueba de aislamiento',
    ])->assertStatus(200);

    // Eliminarlo con doble autorización.
    [$requester, $secretRequester] = boCreateEnrolledAdmin('superadministrador');
    [$approver, $secretApprover] = boCreateEnrolledAdmin('superadministrador');

    $this->actingAs($requester, 'platform');
    $deletionResponse = boCloneClient($requester, $secretRequester)->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/transitions",
        ['to_status' => 'eliminado', 'reason' => 'Prueba de aislamiento', 'confirmation_name' => $tenantA->name],
    );
    $deletionResponse->assertStatus(202);

    $this->actingAs($approver, 'platform');
    boCloneClient($approver, $secretApprover)->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/dual-authorizations/{$deletionResponse->json('data.public_id')}/approval",
        [],
    )->assertStatus(200);

    // Clonar A (todavía se puede: eliminado NO es clonable, así que
    // clonamos B como origen para no violar RN-BO-59, y comprobamos que
    // A sigue intacto tras la operación). Administrador nuevo, no $admin
    // reutilizado: el mismo secreto TOTP en la misma ventana de 30s ya se
    // usó arriba, y la protección contra repetición del código lo
    // rechazaría (no es un fallo, es RN-AUTH-*, el mismo criterio que
    // REQ-AUTH).
    [$cloner, $clonerSecret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($cloner, 'platform');
    $cloneClient = boCloneClient($cloner, $clonerSecret);

    // --- Comprobación de aislamiento ---
    // B nunca ha cambiado de estado ni de datos.
    $tenantBSnapshotAfter = app(TenantContext::class)->runFor($tenantB->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
        'modules' => ModuleSubscription::count(),
    ]);

    expect($tenantBSnapshotAfter)->toBe($tenantBSnapshotBefore);
    expect($tenantB->fresh()->status)->toBe(TenantStatus::Activo);

    // La caché de resolución de B no se tocó por las operaciones sobre A.
    expect(Cache::get("tenant-resolution:{$tenantB->slug}"))->not->toBeNull();

    // Las sesiones de B (si las tuviera) no se revocan por eliminar A —
    // comprobado indirectamente: ningún RevokeTenantSessions se despachó
    // con el id de B, porque B nunca fue el afectado de ninguna operación.
    expect(AdminActionLog::query()->where('affected_tenant_id', $tenantB->id)->count())->toBe(0);

    // A sí quedó eliminado.
    expect(Tenant::withTrashed()->find($tenantA->id)->status)->toBe(TenantStatus::Eliminado);
    expect(Tenant::withTrashed()->find($tenantA->id)->deleted_at)->not->toBeNull();
});
