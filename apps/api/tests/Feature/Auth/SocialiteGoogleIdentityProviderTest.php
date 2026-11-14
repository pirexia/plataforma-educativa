<?php

use App\Modules\Auth\Infrastructure\SocialiteGoogleIdentityProvider;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

// REQ-AUTH-002 (1.4), ADR-042. El único fichero autorizado a importar
// Laravel\Socialite\*: sus dos puntos más delicados (RN-AUTH-92 y la
// normalización de `email_verified`, ADR-042 §4.4) se prueban aquí de
// forma aislada, sin red — `beginAuthorization()` solo construye una URL
// y `normalize()` es puro, ninguno de los dos llama a Google.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * `TenantContext::tenantId()` (usado por `buildRedirectUri()`) solo
 * resuelve dentro de `runFor()` — construir el proveedor y llamar a
 * `beginAuthorization()` tienen que ocurrir en el mismo `runFor()`.
 *
 * @return array{0: string, 1: Tenant}
 */
function beginAuthorizationUrlForTenant(string $slug, string $host): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug]);
    Cache::forget("tenant-resolution:{$tenant->slug}");

    config([
        'auth-local.oauth.driver' => 'google',
        'auth-local.oauth.google_client_id' => 'un-client-id',
        'auth-local.oauth.google_client_secret' => 'un-secreto',
    ]);

    $request = Request::create('https://'.$host.'/api/v1/auth/oauth-authorizations', 'POST');
    $request->setLaravelSession(app('session.store'));

    $tenantContext = app(TenantContext::class);

    $url = $tenantContext->runFor(
        $tenant->id,
        fn () => (new SocialiteGoogleIdentityProvider($request, $tenantContext))->beginAuthorization(),
    );

    return [$url, $tenant];
}

/**
 * Invoca el `normalize()` privado con un `SocialiteUser` construido a
 * mano — ADR-042 §4.4: "no es un test de biblioteca, es el test de la
 * nota de seguridad del requisito", así que no depende de una llamada de
 * red real.
 */
function normalizeRawEmailVerified(mixed $rawValue): bool
{
    $tenant = Tenant::factory()->create();
    Cache::forget("tenant-resolution:{$tenant->slug}");

    config([
        'auth-local.oauth.driver' => 'google',
        'auth-local.oauth.google_client_id' => 'un-client-id',
        'auth-local.oauth.google_client_secret' => 'un-secreto',
    ]);

    $request = Request::create('https://'.$tenant->slug.'.'.config('tenancy.base_domain'), 'GET');
    $tenantContext = app(TenantContext::class);

    $provider = $tenantContext->runFor(
        $tenant->id,
        fn () => new SocialiteGoogleIdentityProvider($request, $tenantContext),
    );

    $raw = ['id' => 'sub-123', 'email' => 'persona@example.com'];

    // La clave puede estar AUSENTE (uno de los ocho casos de ADR-042
    // §4.4): un array_merge con [] no añade `email_verified`.
    if ($rawValue !== 'AUSENTE') {
        $raw['email_verified'] = $rawValue;
    }

    $socialiteUser = (new SocialiteUser)->setRaw($raw)->map(['id' => 'sub-123', 'email' => 'persona@example.com']);

    $method = new ReflectionMethod($provider, 'normalize');
    $method->setAccessible(true);

    $identity = $method->invoke($provider, $socialiteUser);

    return $identity->emailVerified;
}

// ADR-042 §4.4: "Esta regla necesita test propio con los ocho valores de
// entrada (true, 'true', false, 'false', '0', 0, null, clave ausente)
// referenciando REQ-AUTH-002". Lista blanca estricta: solo `true` o la
// cadena 'true' producen verdadero.
test('ADR-042 §4.4: email_verified se normaliza por lista blanca estricta, los ocho valores', function (mixed $raw, bool $expected): void {
    expect(normalizeRawEmailVerified($raw))->toBe($expected);
})->with([
    'true (bool)' => [true, true],
    "'true' (string)" => ['true', true],
    'false (bool)' => [false, false],
    "'false' (string) — (bool) la convertiría en true, prohibido por ADR-042 §4.4" => ['false', false],
    "'0' (string)" => ['0', false],
    '0 (int)' => [0, false],
    'null' => [null, false],
    'clave ausente' => ['AUSENTE', false],
]);

// ADR-042 §4.4: "Prohibido leer verified_email" — la clave deprecated que
// Socialite copia por compatibilidad hacia atrás no debe leerse jamás,
// ni siquiera cuando email_verified está ausente.
test('ADR-042 §4.4: verified_email (clave deprecated) nunca se lee, ni como respaldo', function (): void {
    $tenant = Tenant::factory()->create();
    Cache::forget("tenant-resolution:{$tenant->slug}");

    config([
        'auth-local.oauth.driver' => 'google',
        'auth-local.oauth.google_client_id' => 'un-client-id',
        'auth-local.oauth.google_client_secret' => 'un-secreto',
    ]);

    $request = Request::create('https://'.$tenant->slug.'.'.config('tenancy.base_domain'), 'GET');
    $tenantContext = app(TenantContext::class);

    $provider = $tenantContext->runFor(
        $tenant->id,
        fn () => new SocialiteGoogleIdentityProvider($request, $tenantContext),
    );

    // email_verified ausente, verified_email (deprecated) en true: si el
    // código leyera la clave equivocada, esto normalizaría a true.
    $raw = ['id' => 'sub-456', 'email' => 'otra@example.com', 'verified_email' => true];
    $socialiteUser = (new SocialiteUser)->setRaw($raw)->map(['id' => 'sub-456', 'email' => 'otra@example.com']);

    $method = new ReflectionMethod($provider, 'normalize');
    $method->setAccessible(true);

    expect($method->invoke($provider, $socialiteUser)->emailVerified)->toBeFalse();
});

// CA-AUTH-202: response_type=code, scope=openid email profile, state,
// code_challenge y code_challenge_method=S256.
test('CA-AUTH-202: la URL de autorización lleva PKCE S256 y los tres scopes exactos', function (): void {
    [$url] = beginAuthorizationUrlForTenant('goog-202', 'goog-202.'.config('tenancy.base_domain'));

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // RFC 6749 §3.3: "scope" es una lista separada por espacios sin
    // semántica de orden — se verifica por pertenencia, no por cadena
    // literal (GoogleProvider trae un orden de fábrica que scopes() no
    // sustituye, solo fusiona).
    $scopes = explode(' ', (string) ($query['scope'] ?? ''));

    expect($query['response_type'] ?? null)->toBe('code')
        ->and($scopes)->toHaveCount(3)
        ->and($scopes)->toContain('openid', 'profile', 'email')
        ->and($query['state'] ?? null)->not->toBeEmpty()
        ->and($query['code_challenge'] ?? null)->not->toBeEmpty()
        ->and($query['code_challenge_method'] ?? null)->toBe('S256');
});

// CA-AUTH-203, RN-AUTH-92: la redirect_uri se construye con el slug
// resuelto y el dominio base configurado, nunca con el Host de la
// cabecera — aquí deliberadamente distinto del host real de la petición.
test('CA-AUTH-203: la redirect_uri no contiene un Host ajeno, se construye con el slug y el dominio base', function (): void {
    [$url, $tenant] = beginAuthorizationUrlForTenant('goog-203', 'dominio-ajeno.evil.example');

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $redirectUri = $query['redirect_uri'] ?? '';

    expect($redirectUri)->not->toContain('dominio-ajeno.evil.example')
        ->and($redirectUri)->toBe(
            'https://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/auth/oauth/google/callback'
        );
});
