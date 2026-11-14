<?php

namespace App\Modules\Backoffice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-046 §5, datos.md §2.6.3. Selecciona el almacén de sesión de
 * plataforma por grupo de rutas, antes de `start-session` — mismo patrón
 * que `TenantContext::applyCachePrefix()` con `cache.prefix` y
 * `Cache::forgetDriver()`, probado desde 0.7.
 *
 * Fija SOLO configuración de *sesión*: conexión, tabla, nombre de cookie
 * y vida. **Nunca** `database.default` ni `DB::setDefaultConnection()`
 * — CA-BO-105. Si este middleware dejara `pgsql_platform` puesta de
 * fondo, todo el grupo de rutas de plataforma tendría BYPASSRLS sin
 * pasar por `runAsPlatform()`, vaciando ADR-046 §6 sin ningún síntoma.
 * `session.connection = 'pgsql_platform'` es correcto aquí porque es el
 * manejador de sesión del framework escribiendo en una tabla que
 * `plataforma_app` no puede tocar (REVOKE ALL) — no es código de
 * aplicación pidiendo BYPASSRLS.
 *
 * **Depende de `bootstrap/app.php`** (`$middleware->prependToPriorityList(
 * before: StartSession::class, prepend: self::class)`), y no es opcional:
 * `Illuminate\Routing\SortedMiddleware` reordena la pila final según
 * `$middlewarePriority`, no según el orden declarado en `routes/api.php`.
 * `SubstituteBindings` (grupo global `api`, prioridad 9) va DESPUÉS de
 * `StartSession` (prioridad 3) en esa lista — así que, sin esta entrada
 * de prioridad, el propio ordenador de Laravel adelanta `start-session`
 * por delante de `configure-platform-session` para respetar esa relación,
 * pese a que aquí se declaren en el orden contrario. El síntoma, sin la
 * entrada de prioridad: `StartSession` construye su `Store` (y su cookie
 * de sesión) con el nombre y la conexión del PRODUCTO —no los de
 * `backoffice.session_cookie`—, y lo hace de forma silenciosa: el
 * `Store` sigue siendo válido, la petición no falla, solo la cookie
 * resultante lleva el nombre equivocado (verificado con `CA-BO-` `login
 * completo`/`CA-BO-005`: solo se detecta si algo compara la cookie por
 * nombre, que es exactamente lo que hacen esos dos tests).
 */
class ConfigurePlatformSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.connection' => 'pgsql_platform',
            'session.table' => 'platform_sessions',
            'session.cookie' => config('backoffice.session_cookie'),
            'session.lifetime' => config('backoffice.session_lifetime'),
            // Host-only, sin dominio principal: la sesión de plataforma no
            // puede viajar entre el host de plataforma y el de ningún
            // centro, ni al revés (ADR-033 §2, RMT-009).
            'session.domain' => null,
        ]);

        // El SessionManager (Illuminate\Support\Manager) memoiza el Store
        // ya construido por nombre de driver ('database') — cambiar
        // config() después de que exista uno cacheado no lo reconstruye
        // solo. Sin este forgetDrivers()/forgetInstance(), la petición
        // seguiría usando el Store del producto (nombre de cookie y
        // conexión antiguos) resuelto en una petición anterior dentro del
        // mismo proceso. Mismo mecanismo que `resetSessionState()` en
        // tests/Pest.php usa entre logins.
        //
        // Deliberadamente NO se llama a `app('auth')->forgetGuards()`
        // aquí (a diferencia de `resetSessionState()`, pensado para
        // ejecutarse ENTRE peticiones de un mismo test): dentro de una
        // sola petición, `TestCase::actingAs($admin, 'platform')` ya ha
        // fijado el usuario autenticado en el guard 'platform' ANTES de
        // que esta petición empiece — olvidar los guards aquí descarta
        // esa fijación y el resto de la pila ve la petición como anónima
        // (401 donde debería seguir autenticada). El guard no memoiza el
        // Store incorrecto en este flujo: el orden real de ejecución lo
        // fija `$middlewarePriority` (bootstrap/app.php), que ya deja esta
        // clase antes de `StartSession` — la causa original del nombre de
        // cookie equivocado (ver docblock de la clase) era el ORDEN, no
        // una referencia obsoleta en el guard.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        return $next($request);
    }
}
