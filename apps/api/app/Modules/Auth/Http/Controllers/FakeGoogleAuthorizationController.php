<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Infrastructure\FakeIdentityProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `operacion.md §E.10.2`. El «formulario mínimo» del proveedor simulado:
 * pinta `sub`, `email` y una casilla `email_verified`, y al enviarlo
 * redirige al *callback* real con un `code` que `FakeIdentityProvider`
 * sabe canjear. No es una pantalla del producto (sin i18n, sin
 * *branding*): es una herramienta de desarrollo con dos barreras que le
 * impiden llegar a producción (`§E.10.3`) — esta clase ni siquiera se
 * instancia si la ruta no está registrada, y `routes.php` solo la
 * registra en `local`/`testing`.
 */
class FakeGoogleAuthorizationController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        // Segunda barrera además de la ausencia de ruta en routes.php:
        // aunque algo resolviera esta clase por error, se niega a actuar
        // fuera de local/testing (CA-AUTH-230).
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('FakeGoogleAuthorizationController no está disponible fuera de local/testing.');
        }

        $state = (string) $request->query('state', '');

        if ($request->boolean('submit')) {
            return $this->redirectToCallback($request, $state);
        }

        return response($this->renderForm($state), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function redirectToCallback(Request $request, string $state): RedirectResponse
    {
        $callbackUrl = $request->getSchemeAndHttpHost().'/api/v1/auth/oauth/google/callback';

        if ($request->boolean('cancel')) {
            return redirect()->away($callbackUrl.'?'.http_build_query([
                'error' => 'access_denied',
                'state' => $state,
            ]));
        }

        $code = FakeIdentityProvider::encodeCode([
            'sub' => $request->string('sub')->value(),
            'email' => Str::lower(trim($request->string('email')->value())),
            'email_verified' => $request->boolean('email_verified'),
            'given_name' => $request->string('given_name')->value() ?: null,
            'family_name' => $request->string('family_name')->value() ?: null,
        ]);

        return redirect()->away($callbackUrl.'?'.http_build_query([
            'code' => $code,
            'state' => $state,
        ]));
    }

    private function renderForm(string $state): string
    {
        $stateAttr = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es">
            <head><meta charset="utf-8"><title>Proveedor de identidad simulado (solo desarrollo)</title></head>
            <body style="font-family: sans-serif; max-width: 32rem; margin: 3rem auto;">
                <h1>Proveedor de identidad simulado</h1>
                <p>Esta pantalla no existe fuera de <code>local</code>/<code>testing</code>
                   (operacion.md §E.10). Rellena los datos que el proveedor real
                   devolvería en el <em>callback</em>.</p>
                <form method="get" action="">
                    <input type="hidden" name="state" value="{$stateAttr}">
                    <input type="hidden" name="submit" value="1">
                    <p>
                        <label>sub (identificador estable)<br>
                            <input type="text" name="sub" value="fake-subject-1" required>
                        </label>
                    </p>
                    <p>
                        <label>email<br>
                            <input type="email" name="email" value="persona@example.com" required>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="email_verified" value="1" checked>
                            email_verified
                        </label>
                    </p>
                    <p>
                        <label>given_name<br><input type="text" name="given_name" value="Nombre"></label>
                    </p>
                    <p>
                        <label>family_name<br><input type="text" name="family_name" value="Apellidos"></label>
                    </p>
                    <p>
                        <button type="submit">Continuar</button>
                        <button type="submit" name="cancel" value="1">Cancelar (simula acceso denegado)</button>
                    </p>
                </form>
            </body>
            </html>
            HTML;
    }
}
