<?php

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Modules\Backoffice\Infrastructure\BackofficeAccessCheck;
use App\Support\Tenancy\DefaultPlatformAccessCheck;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * ADR-046 §6, funcional.md §6.2. `runAsPlatform()` con propósito
 * declarado, ausencia de tenant activo obligatoria, y regla de cierre
 * para `BackofficeEscritura`.
 */
function makePlatformAdminForAccessCheck(): PlatformAdmin
{
    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'Admin de prueba',
        'password' => 'hash-de-prueba',
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => PlatformRole::Superadministrador]);

    return $admin;
}

afterEach(function (): void {
    // admin_action_logs es de solo-anexión permanente: FORCE ROW LEVEL
    // SECURITY sin política permisiva de escritura bloquea
    // INSERT/UPDATE/DELETE incluso para pgsql_owner (RN-BO-29,
    // ADR-047 §4.3) — DELETE ejecuta sin error pero afecta cero filas.
    // TRUNCATE no pasa por RLS y es el único camino de limpieza en
    // tests. Antes de borrar platform_admins/tenants: una fila que
    // quedara referenciándolos bloquearía ese DELETE por FK.
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// CA-BO-025 (enlace por defecto): DefaultPlatformAccessCheck deniega los
// dos propósitos de backoffice, sin comprobar nada más — comprueba
// directamente el enlace por defecto, no el que sustituye
// BackofficeServiceProvider (ya registrado en la app de test).
test('CA-BO-025: DefaultPlatformAccessCheck deniega los dos propósitos de backoffice', function (): void {
    $default = new DefaultPlatformAccessCheck;

    expect(fn () => $default->before(PlatformAccessPurpose::BackofficeLectura))->toThrow(RuntimeException::class);
    expect(fn () => $default->before(PlatformAccessPurpose::BackofficeEscritura))->toThrow(RuntimeException::class);
});

test('CA-BO-025: DefaultPlatformAccessCheck permite Mantenimiento solo desde consola', function (): void {
    $default = new DefaultPlatformAccessCheck;

    // La suite de tests corre bajo PHPUnit en CLI: runningInConsole() es verdadero.
    expect(fn () => $default->before(PlatformAccessPurpose::Mantenimiento))->not->toThrow(RuntimeException::class);
});

// CA-BO-025 (BackofficeAccessCheck real): exige un platform_admin
// autenticado en el guard 'platform' para los dos propósitos de backoffice.
test('CA-BO-025: BackofficeAccessCheck exige sesión de plataforma para los propósitos de backoffice', function (): void {
    $check = new BackofficeAccessCheck;

    expect(fn () => $check->before(PlatformAccessPurpose::BackofficeLectura))->toThrow(RuntimeException::class);

    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    expect(fn () => $check->before(PlatformAccessPurpose::BackofficeLectura))->not->toThrow(RuntimeException::class);

    Auth::guard('platform')->logout();
});

// CA-BO-027: runAsPlatform() lanza con tenant activo, con los tres propósitos.
test('CA-BO-027: runAsPlatform() lanza con cualquier propósito si hay un tenant activo', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    foreach (PlatformAccessPurpose::cases() as $purpose) {
        expect(fn () => $context->runAsPlatform($purpose, fn () => true))
            ->toThrow(RuntimeException::class, 'tenant activo');
    }

    $context->leave();
});

// CA-BO-026: el mantenimiento por consola no escribe N entradas de auditoría.
test('CA-BO-026: runAsPlatform(Mantenimiento) no exige ni produce entradas en admin_action_logs', function (): void {
    $before = AdminActionLog::query()->count();

    $context = app(TenantContext::class);
    $result = $context->runAsPlatform(PlatformAccessPurpose::Mantenimiento, fn () => 'ok');

    expect($result)->toBe('ok');
    expect(AdminActionLog::query()->count())->toBe($before);
});

// CA-BO-028: un bloque BackofficeEscritura que no escribe en
// admin_action_logs lanza al cerrarse.
test('CA-BO-028: BackofficeEscritura sin ninguna entrada en admin_action_logs lanza al cerrar el bloque', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);

    expect(fn () => $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, fn () => true))
        ->toThrow(RuntimeException::class);

    Auth::guard('platform')->logout();
});

test('CA-BO-028: BackofficeEscritura que sí escribe en admin_action_logs no lanza', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);

    $result = $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, function () {
        AdminActionLog::create([
            'public_id' => (string) Str::ulid(),
            'occurred_at' => now(),
            'actor_type' => 'platform_admin',
            'actor_platform_admin_id' => Auth::guard('platform')->id(),
            'subject_type' => 'platform',
            'action' => 'admin.actualizado',
        ]);

        return 'ok';
    });

    expect($result)->toBe('ok');

    Auth::guard('platform')->logout();
});

// CA-BO-028 (segunda parte, ADR-046 §6.5): también lanza si el callback
// ya había lanzado — after() se llama en el finally.
test('CA-BO-028: la regla de cierre corre también cuando el callback lanza', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);

    expect(fn () => $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, function (): void {
        throw new DomainException('fallo del callback');
    }))->toThrow(RuntimeException::class);

    Auth::guard('platform')->logout();
});
