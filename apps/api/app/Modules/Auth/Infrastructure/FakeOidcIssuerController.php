<?php

namespace App\Modules\Auth\Infrastructure;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * `operacion.md §F.10`. El emisor OIDC mínimo que permite recorrer el
 * flujo institucional **entero y real** en `local`/`testing`, sin
 * dominio público (`0.10b` pendiente): documento de descubrimiento,
 * autorización, canje de código con verificación de PKCE `S256` real, y
 * un `id_token` con los *claims* que el formulario declara, incluido
 * `hd` para poder probar `CA-AUTH-284` de verdad.
 *
 * No es una pantalla del producto (sin i18n, sin *branding*): es una
 * herramienta de desarrollo con dos barreras que le impiden llegar a
 * producción (`§F.10.3`) — esta clase ni siquiera se instancia si la
 * ruta no está registrada, y `routes/api.php` solo la registra en
 * `local`/`testing`.
 *
 * A diferencia de `FakeIdentityProvider` (1.4, `AUTH_OAUTH_DRIVER=fake`),
 * que sustituye la implementación de `ExternalIdentityProvider` sin
 * ninguna petición HTTP real, este emisor **es un servidor HTTP de
 * verdad**: `GenericOidcProvider` le habla exactamente igual que a
 * Entra ID o a Google Workspace, incluida la validación de las cinco
 * guardas de `funcional.md §F.4.2` sobre su documento de descubrimiento.
 */
class FakeOidcIssuerController extends Controller
{
    /**
     * `?broken=<guarda>` (solo `local`/`testing`, igual que el resto de
     * esta clase): produce deliberadamente un documento que incumple la
     * guarda 5 nombrada, para poder probarlas sin depender de un tercero
     * real (`CA-AUTH-261`, `funcional.md §F.4.2`). Sin el parámetro, el
     * documento es siempre válido.
     */
    public function discovery(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        // `?issuer_suffix=` (solo tests): permite catalogar dos
        // proveedores de prueba distintos contra el mismo emisor
        // simulado sin chocar con UNIQUE(tenant_id, issuer). Nunca
        // usado por un proveedor que vaya a completar un login de
        // verdad: el token_endpoint no lo conoce, así que su `iss`
        // no incluiría el sufijo (RN-AUTH-104 lo rechazaría).
        $issuerSuffix = (string) $request->query('issuer_suffix', '');

        $document = [
            // El origen basta (originOf() de CurlDiscoveryDocumentValidator
            // solo compara esquema+host+puerto, no la ruta) — el valor
            // exacto se conserva tal cual y se reutiliza como `iss` del
            // id_token (RN-AUTH-104).
            'issuer' => $this->baseUrl($request).$issuerSuffix,
            'authorization_endpoint' => route('sso-simulator.authorize'),
            'token_endpoint' => route('sso-simulator.token'),
            'userinfo_endpoint' => route('sso-simulator.userinfo'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
        ];

        switch ($request->query('broken')) {
            case 'emisor_no_coincide':
                $document['issuer'] = 'https://otro-origen.example.test';
                break;
            case 'endpoint_no_seguro':
                $document['token_endpoint'] = str_replace('https://', 'http://', $document['token_endpoint']);
                $document['token_endpoint'] = str_replace('http://localhost', 'ftp://localhost', $document['token_endpoint']);
                break;
            case 'flujo_no_admitido':
                $document['response_types_supported'] = ['token'];
                break;
            case 'documento_no_valido':
                unset($document['authorization_endpoint']);
                break;
        }

        return response()->json($document);
    }

    public function authorize(Request $request): Response|RedirectResponse
    {
        $this->guardEnvironment();

        if ($request->boolean('submit')) {
            return $this->redirectWithCode($request);
        }

        return response($this->renderForm($request), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function token(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        $claims = self::decode((string) $request->input('code', ''));

        if ($claims === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $codeVerifier = (string) $request->input('code_verifier', '');
        $expectedChallenge = (string) ($claims['code_challenge'] ?? '');

        // RFC 7636 §4.6: verificación real de PKCE, no simulada — el
        // resto del flujo es el de verdad (operacion.md §F.10.1).
        if ($codeVerifier === '' || ! hash_equals($expectedChallenge, self::codeChallengeFor($codeVerifier))) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $now = now()->timestamp;
        $idTokenClaims = array_filter([
            'iss' => $claims['iss_override'] ?? $this->baseUrl($request),
            'aud' => $claims['client_id'] ?? '',
            'sub' => $claims['sub'] ?? '',
            'exp' => $now + 300 + (int) ($claims['exp_offset_seconds'] ?? 0),
            'iat' => $now + (int) ($claims['iat_offset_seconds'] ?? 0),
            'nonce' => $claims['nonce_override'] ?? $claims['nonce'] ?? null,
            'email' => $claims['email'] ?? null,
            'email_verified' => $claims['email_verified'] ?? null,
            'preferred_username' => $claims['preferred_username'] ?? null,
            'upn' => $claims['upn'] ?? null,
            'hd' => $claims['hd'] ?? null,
            'name' => $claims['name'] ?? null,
            'given_name' => $claims['given_name'] ?? null,
            'family_name' => $claims['family_name'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $accessToken = self::encode($idTokenClaims);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => self::encodeJwt($idTokenClaims),
        ]);
    }

    /**
     * `funcional.md §F.3.2`: el conmutador `claims_source = 'userinfo'`.
     * El `access_token` de este emisor simulado ES los *claims*
     * codificados (`§F.10`, herramienta de desarrollo): decodificarlo es
     * exactamente lo que un `userinfo` real haría con un token opaco.
     */
    public function userinfo(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        $token = (string) str($request->header('Authorization', ''))->after('Bearer ');
        $claims = self::decode($token);

        if ($claims === null) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        unset($claims['code_challenge'], $claims['client_id'], $claims['redirect_uri']);

        return response()->json($claims);
    }

    private function redirectWithCode(Request $request): RedirectResponse
    {
        $redirectUri = (string) $request->query('redirect_uri', '');
        $state = (string) $request->query('state', '');

        if ($request->boolean('cancel')) {
            return redirect()->away($redirectUri.'?'.http_build_query([
                'error' => 'access_denied',
                'state' => $state,
            ]));
        }

        $code = self::encode([
            'sub' => $request->string('sub')->value(),
            'email' => (string) str($request->string('email')->value())->lower()->trim(),
            'email_verified' => $request->boolean('email_verified'),
            'preferred_username' => $request->string('preferred_username')->value() ?: null,
            'upn' => $request->string('upn')->value() ?: null,
            'hd' => $request->string('hd')->value() ?: null,
            'given_name' => $request->string('given_name')->value() ?: null,
            'family_name' => $request->string('family_name')->value() ?: null,
            'nonce' => $request->string('nonce')->value() ?: null,
            'code_challenge' => $request->string('code_challenge')->value(),
            'client_id' => $request->string('client_id')->value(),
            // Solo tests (RN-AUTH-104, CA-AUTH-277/284): permite fijar un
            // `iss` distinto del real, para probar el rechazo por
            // discrepancia y para poder simular un proveedor catalogado
            // con `issuer = 'https://accounts.google.com'` sin depender
            // de la red real.
            'iss_override' => $request->string('iss_override')->value() ?: null,
            'exp_offset_seconds' => $request->string('exp_offset_seconds')->value() ?: null,
            'iat_offset_seconds' => $request->string('iat_offset_seconds')->value() ?: null,
            'nonce_override' => $request->string('nonce_override')->value() ?: null,
        ]);

        return redirect()->away($redirectUri.'?'.http_build_query([
            'code' => $code,
            'state' => $state,
        ]));
    }

    private function renderForm(Request $request): string
    {
        $hidden = [];

        foreach (['client_id', 'redirect_uri', 'state', 'nonce', 'code_challenge', 'code_challenge_method', 'response_type', 'scope'] as $field) {
            $value = htmlspecialchars((string) $request->query($field, ''), ENT_QUOTES, 'UTF-8');
            $hidden[] = "<input type=\"hidden\" name=\"{$field}\" value=\"{$value}\">";
        }

        $hiddenFields = implode("\n", $hidden);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es">
            <head><meta charset="utf-8"><title>Emisor OIDC simulado (solo desarrollo)</title></head>
            <body style="font-family: sans-serif; max-width: 32rem; margin: 3rem auto;">
                <h1>Emisor OIDC simulado</h1>
                <p>Esta pantalla no existe fuera de <code>local</code>/<code>testing</code>
                   (operacion.md §F.10). Rellena los datos que el directorio del
                   centro devolvería.</p>
                <form method="get" action="">
                    {$hiddenFields}
                    <input type="hidden" name="submit" value="1">
                    <p><label>sub (identificador estable)<br>
                        <input type="text" name="sub" value="fake-oidc-subject-1" required></label></p>
                    <p><label>email<br>
                        <input type="email" name="email" value="persona@sucentro.example.com"></label></p>
                    <p><label><input type="checkbox" name="email_verified" value="1" checked> email_verified</label></p>
                    <p><label>hd (Google Workspace)<br><input type="text" name="hd" value=""></label></p>
                    <p><label>given_name<br><input type="text" name="given_name" value="Nombre"></label></p>
                    <p><label>family_name<br><input type="text" name="family_name" value="Apellidos"></label></p>
                    <p>
                        <button type="submit">Continuar</button>
                        <button type="submit" name="cancel" value="1">Cancelar (simula acceso denegado)</button>
                    </p>
                </form>
            </body>
            </html>
            HTML;
    }

    private function guardEnvironment(): void
    {
        // Segunda barrera además de la ausencia de ruta en routes/api.php
        // (§F.10.3, mismo patrón que FakeGoogleAuthorizationController).
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('FakeOidcIssuerController no está disponible fuera de local/testing.');
        }
    }

    private function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().'/_sso-simulator';
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function encode(array $claims): string
    {
        return base64_encode((string) json_encode($claims));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Un JWT con forma real (tres segmentos base64url) y sin firma
     * verificable — `GenericOidcProvider` no la comprueba
     * (`funcional.md §F.3.2`, OpenID Connect Core 1.0 §3.1.3.7).
     *
     * @param  array<string, mixed>  $claims
     */
    private static function encodeJwt(array $claims): string
    {
        $segment = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $segment(['alg' => 'none', 'typ' => 'JWT']).'.'.$segment($claims).'.fake-oidc-simulator';
    }

    private static function codeChallengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
