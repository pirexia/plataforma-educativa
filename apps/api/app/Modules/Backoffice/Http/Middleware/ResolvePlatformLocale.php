<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-021, ADR-038 §11. El idioma del backoffice no depende de un
 * tenant (no hay `active_locales` de centro que consultar aquí): resuelve
 * el idioma del propio `platform_admin` autenticado —
 * `platform_admins.locale`, uno de los cuatro de `ADR-021`— o `es-ES` por
 * defecto sin sesión.
 */
class ResolvePlatformLocale
{
    /** @var array<string, string> */
    private const TO_LARAVEL_LOCALE = [
        'es-ES' => 'es',
        'en' => 'en',
        'de' => 'de',
        'fr' => 'fr',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();
        $resolved = $admin instanceof PlatformAdmin ? $admin->locale : 'es-ES';

        App::setLocale(self::TO_LARAVEL_LOCALE[$resolved] ?? 'es');

        $response = $next($request);
        $response->headers->set('Content-Language', $resolved);

        return $response;
    }
}
