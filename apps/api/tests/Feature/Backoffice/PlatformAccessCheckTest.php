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

// Issue #218 (Media, security-reviewer, hallado en 1.6c): en el camino
// de ÉXITO, si after() lanza (el bloque terminó sin dejar rastro en
// admin_action_logs), la restauración de platformMode/platformPurpose
// ocurría DESPUÉS de esa llamada — así que nunca llegaba a ejecutarse.
// platformMode es un singleton de vida de proceso (causa raíz de #196):
// una fuga aquí se autoperpetúa en cualquier runAsPlatform() posterior
// del mismo proceso. Ahora esa llamada está en try/finally.
test('issue #218: si after() lanza en el camino de éxito, platformMode se restaura igualmente', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);

    expect(fn () => $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, fn () => 'ok, pero sin escribir nada'))
        ->toThrow(RuntimeException::class);

    // La propiedad que #218 exige: pese a que after() lanzó, el estado
    // no queda corrompido para la siguiente llamada del mismo proceso.
    expect($context->isPlatformMode())->toBeFalse();

    Auth::guard('platform')->logout();
});

// CA-BO-028 (segunda parte, ADR-046 §6.5) — issue #215 (Alta, hallado en
// 1.6c): la obligación de auditar sigue vigente al pie de la letra
// ("también si el callback lanzó", ADR-046 §6.3) — `after()` se sigue
// llamando siempre, sin reabrir el ADR. Lo único que cambia es que su
// resultado nunca sustituye a la excepción real del callback: si esta ya
// lanzó (una `ApiException` de negocio, o un control de flujo legítimo
// como `ModuleChangeWasNoOp` de `REQ-BO-002`, que es un ÉXITO sin
// escritura), esa es la señal que importa. Antes de este arreglo, un
// `422` de validación limpio (esencial, retirado, dependencias sin
// confirmar — RN-BO-65/66/67/71, todos legítimos DENTRO del bloque, sin
// escribir nada) quedaba enmascarado por el `RuntimeException` de "sin
// rastro" de `after()`, convirtiéndose en un `500` de plataforma confuso.
test('CA-BO-028: si el callback lanza, la excepción original propaga y no se enmascara con la regla de cierre', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);
    $checkpoint = (int) (AdminActionLog::query()->max('id') ?? 0);

    expect(fn () => $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, function (): void {
        throw new DomainException('fallo del callback');
    }))->toThrow(DomainException::class, 'fallo del callback');

    // after() sí se invocó (issue #218, defensa en profundidad intacta:
    // si algo hubiera quedado escrito pese a la excepción, se habría
    // reportado) y, como no había nada que auditar tras la reversión de
    // la transacción, no dejó ninguna fila nueva.
    expect((int) (AdminActionLog::query()->max('id') ?? 0))->toBe($checkpoint);

    // El estado de plataforma queda restaurado igual que antes del
    // cambio: un fallo no debe dejar `isPlatformMode()` a true.
    expect($context->isPlatformMode())->toBeFalse();

    Auth::guard('platform')->logout();
});

// Issue #215, la parte que de verdad prueba la corrección: cuando el
// callback lanza Y after() también habría lanzado (nada escrito), la
// excepción que propaga es la del callback, nunca la de after(). Antes
// del arreglo esto era exactamente el 422→500 que #215 reporta.
test('CA-BO-028: la excepción de after() nunca sustituye a la del callback cuando las dos ocurrirían', function (): void {
    $admin = makePlatformAdminForAccessCheck();
    Auth::guard('platform')->login($admin);

    $context = app(TenantContext::class);

    $thrown = null;

    try {
        $context->runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, function (): void {
            throw new DomainException('422 de negocio, sin escribir nada');
        });
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(DomainException::class);
    expect($thrown->getMessage())->toBe('422 de negocio, sin escribir nada');

    Auth::guard('platform')->logout();
});
