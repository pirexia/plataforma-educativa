<?php

namespace App\Modules\Backoffice\Application;

use App\Support\Api\ApiException;
use Illuminate\Http\Request;

/**
 * RN-BO-08, funcional.md §5.2. La comprobación que hace
 * `RequirePlatformMiddleware\RequirePlatformReauthentication` para toda la
 * lista **fija** de `api.md §4`. Se extrae aquí porque `1.6b` tiene una
 * operación cuya sensibilidad **depende del cuerpo de la petición**:
 * `POST /tenants/{id}/transitions` solo es sensible cuando
 * `to_status ∈ {en_baja, eliminado}` (api.md §4) — el resto de
 * transiciones (suspender, reactivar, rescatar) no lo son, y una
 * declaración estática de middleware no puede condicionarse al cuerpo.
 * `TenantsController::transitions()` invoca este mismo método a mano
 * solo quando corresponde, en vez de duplicar la lógica de la ventana.
 */
final class PlatformReauthenticationCheck
{
    public const SESSION_KEY = 'platform_reauthenticated_at';

    public static function ensureFresh(Request $request): void
    {
        $reauthenticatedAt = $request->session()->get(self::SESSION_KEY);
        $windowMinutes = (int) config('backoffice.reauthentication_window_minutes');

        if ($reauthenticatedAt === null || now()->diffInMinutes($reauthenticatedAt, true) > $windowMinutes) {
            throw ApiException::reauthenticationRequired();
        }
    }
}
