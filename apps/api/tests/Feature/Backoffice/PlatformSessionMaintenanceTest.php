<?php

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Infrastructure\Jobs\CloseOrphanedPlatformSessions;
use Illuminate\Support\Str;

/**
 * ADR-047 §5.1, §11 punto 2, datos.md §2.6.3, §2.7.2. CA-BO-104:
 * barrido de sesiones huérfanas de plataforma. CA-BO-105: el middleware
 * de sesión de plataforma nunca cambia la conexión por defecto de la
 * aplicación.
 */
afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// CA-BO-104: una fila viva cuyo session_id ya no existe en
// platform_sessions queda cerrada con end_reason = caducidad y deja de
// aparecer en la consulta de sesiones vivas; una fila viva cuya sesión sí
// existe no se toca.
test('CA-BO-104: bo:close-orphaned-sessions cierra solo las sesiones huérfanas', function (): void {
    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'password' => 'hash',
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $liveSessionId = Str::random(40);
    DB::connection('pgsql_platform')->table('platform_sessions')->insert([
        'id' => $liveSessionId,
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => time(),
    ]);

    $orphan = PlatformAdminSession::create([
        'platform_admin_id' => $admin->id,
        'session_id' => Str::random(40),
        'started_at' => now(),
        'last_seen_at' => now(),
    ]);
    $alive = PlatformAdminSession::create([
        'platform_admin_id' => $admin->id,
        'session_id' => $liveSessionId,
        'started_at' => now(),
        'last_seen_at' => now(),
    ]);

    CloseOrphanedPlatformSessions::dispatchSync();

    $orphan->refresh();
    $alive->refresh();

    expect($orphan->session_id)->toBeNull();
    expect($orphan->ended_at)->not->toBeNull();
    expect($orphan->end_reason)->toBe(PlatformAdminSessionEndReason::Caducidad);

    expect($alive->session_id)->toBe($liveSessionId);
    expect($alive->ended_at)->toBeNull();

    $liveIds = PlatformAdminSession::query()->whereNull('ended_at')->pluck('id')->all();
    expect($liveIds)->toContain($alive->id);
    expect($liveIds)->not->toContain($orphan->id);
});

// CA-BO-105: tras pasar por el middleware que selecciona el almacén de
// sesión de plataforma, la conexión de base de datos POR DEFECTO de la
// aplicación no ha cambiado — sigue siendo la del producto, no
// pgsql_platform. Verificado con una petición real, no una llamada
// directa al middleware.
test('CA-BO-105: la conexión por defecto de la aplicación no cambia tras una petición de plataforma', function (): void {
    PlatformIpAllowlistEntry::create([
        'cidr' => '127.0.0.1/32',
        'description' => 'Suite de tests',
        'enabled' => true,
    ]);

    $defaultBefore = config('database.default');
    expect($defaultBefore)->not->toBe('pgsql_platform');

    $this->getJson('http://'.config('backoffice.host').'/api/platform/v1/csrf-cookie');

    expect(config('database.default'))->toBe($defaultBefore);
    expect(config('session.connection'))->toBe('pgsql_platform');

    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// ADR-046 §5, RN-BO-02: la cookie de sesión de plataforma emitida por una
// petición real lleva EL NOMBRE PROPIO del backoffice
// (`backoffice.session_cookie`), nunca el del producto
// (`config('session.cookie')` por defecto). Regresión de un fallo real
// encontrado y corregido en 1.6: `Illuminate\Routing\SortedMiddleware`
// reordena la pila según `$middlewarePriority`, no según el orden
// declarado en `routes/api.php` — `SubstituteBindings` (grupo global
// `api`) va DESPUÉS de `StartSession` en esa lista de prioridad, así que
// sin `$middleware->prependToPriorityList(before: StartSession::class,
// prepend: ConfigurePlatformSession::class)` en `bootstrap/app.php`,
// Laravel adelantaba `start-session` por delante de
// `configure-platform-session` pese al orden contrario declarado en la
// ruta: la sesión se creaba con el nombre y la conexión del PRODUCTO, en
// silencio, sin que ningún test de estado (CA-BO-105 incluido) lo
// detectara — solo se ve comparando la cookie por nombre.
test('la cookie de sesión de una petición real de plataforma lleva el nombre propio del backoffice, no el del producto', function (): void {
    PlatformIpAllowlistEntry::create([
        'cidr' => '127.0.0.1/32',
        'description' => 'Suite de tests',
        'enabled' => true,
    ]);

    $productCookieName = config('session.cookie');

    $response = $this->getJson('http://'.config('backoffice.host').'/api/platform/v1/csrf-cookie');

    $cookieNames = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());

    expect($cookieNames)->toContain(config('backoffice.session_cookie'));
    expect($cookieNames)->not->toContain($productCookieName);

    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});
