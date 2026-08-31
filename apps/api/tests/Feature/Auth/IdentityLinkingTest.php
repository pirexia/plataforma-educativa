<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityUnlinkedEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// funcional.md §E.4.4, §E.4.5, api.md §E.5. Vinculación y desvinculación
// desde el perfil (REQ-AUTH-002, 1.4). Multi-tenant (CA-AUTH-215).

beforeEach(function (): void {
    config(['auth-local.oauth.driver' => 'fake']);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function fakeGoogleClaimsForLinking(array $overrides = []): array
{
    return array_merge([
        'sub' => 'fake-sub-'.Str::random(8),
        'email' => 'externo-'.Str::random(6).'@gmail.com',
        'email_verified' => '1',
        'given_name' => 'Nombre',
        'family_name' => 'Apellidos',
    ], $overrides);
}

/**
 * Login local real (no Google) para obtener una sesión autenticada
 * completa, sin depender de MFA — `provisionActiveUser()` no da de alta
 * ningún factor.
 */
function localSessionCookie(string $slug, string $email, string $password): string
{
    return sessionCookieValue(loginFor($slug, $email, $password));
}

// CA-AUTH-222, funcional.md §E.4.4 punto 3
test('CA-AUTH-222: vincular desde el perfil con otro correo en Google crea la fila con link_method=perfil', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-222', ['email' => 'local-222@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'local-222@example.com', $password);

    [$url, $beginCookie] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    $callback = completeFakeGoogleFlow($url, $beginCookie, fakeGoogleClaimsForLinking(['email' => 'otro-completamente-distinto@gmail.com']));

    expect(oauthCallbackResultCode($callback))->toBe('vinculado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $identity = UserIdentity::query()->where('user_id', $user->id)->first();
        expect($identity)->not->toBeNull();
        expect($identity->link_method)->toBe(LinkMethod::Perfil);
    });
});

// CA-AUTH-223, RN-AUTH-89
test('CA-AUTH-223: una cuenta de Google ya vinculada a otro usuario se rechaza, sin crear fila, vía índice único', function (): void {
    Queue::fake();
    [$tenant, $userA, $passwordA] = provisionActiveUser('link-223-a', ['email' => 'a-223@example.com']);
    [, $userB, $passwordB] = provisionActiveUser(null, ['email' => 'b-223@example.com']);
    // Mismo tenant para ambos: provisionActiveUser crea un tenant nuevo
    // por llamada, así que se reutiliza el de A para B directamente.
    $userB = app(TenantContext::class)->runFor($tenant->id, function () use ($passwordB): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => 'b-223@example.com',
            'password' => $passwordB,
            'status' => UserStatus::Activo,
        ]);
    });

    $sub = 'sub-compartido-223';

    $cookieA = localSessionCookie($tenant->slug, 'a-223@example.com', $passwordA);
    [$urlA, $beginA] = beginFakeGoogleFlow($tenant->slug, 'link', $cookieA);
    completeFakeGoogleFlow($urlA, $beginA, fakeGoogleClaimsForLinking(['sub' => $sub]))
        ->assertRedirect();

    $cookieB = localSessionCookie($tenant->slug, 'b-223@example.com', $passwordB);
    [$urlB, $beginB] = beginFakeGoogleFlow($tenant->slug, 'link', $cookieB);
    $secondAttempt = completeFakeGoogleFlow($urlB, $beginB, fakeGoogleClaimsForLinking(['sub' => $sub]));

    expect(oauthCallbackResultCode($secondAttempt))->toBe('proveedor_ya_vinculado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($userA, $userB): void {
        expect(UserIdentity::query()->where('user_id', $userA->id)->count())->toBe(1);
        expect(UserIdentity::query()->where('user_id', $userB->id)->count())->toBe(0);
    });
});

// CA-AUTH-224, RN-AUTH-89
test('CA-AUTH-224: vincular una segunda cuenta de Google se rechaza sin sustituir la existente', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-224', ['email' => 'unico-224@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'unico-224@example.com', $password);

    [$url1, $begin1] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url1, $begin1, fakeGoogleClaimsForLinking(['sub' => 'primera-224']))->assertRedirect();

    [$url2, $begin2] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    $second = completeFakeGoogleFlow($url2, $begin2, fakeGoogleClaimsForLinking(['sub' => 'segunda-224']));

    expect(oauthCallbackResultCode($second))->toBe('ya_vinculado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $live = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($live)->toHaveCount(1);
        expect($live->first()->subject)->toBe('primera-224');
    });
});

// CA-AUTH-225, RN-AUTH-96/RN-AUTH-36
test('CA-AUTH-225: desvincular sin contraseña o con la incorrecta responde 422, el vínculo sigue vivo y cuenta hacia el bloqueo', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-225', ['email' => 'desvincular-225@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'desvincular-225@example.com', $password);

    [$url, $begin] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url, $begin, fakeGoogleClaimsForLinking())->assertRedirect();

    $publicId = app(TenantContext::class)->runFor($tenant->id, fn () => UserIdentity::query()->where('user_id', $user->id)->first()->public_id);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$publicId}"), ['current_password' => 'incorrecta-de-verdad'])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->whereNull('deleted_at')->count())->toBe(1);
        expect(LoginAttempt::query()->where('outcome', 'credenciales_invalidas')->count())->toBe(1);
    });
});

// CA-AUTH-226
test('CA-AUTH-226: desvincular con la contraseña correcta borra lógicamente, audita y encola el aviso', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-226', ['email' => 'ok-226@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'ok-226@example.com', $password);

    $sub = 'sub-226';
    [$url, $begin] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url, $begin, fakeGoogleClaimsForLinking(['sub' => $sub]))->assertRedirect();

    $publicId = app(TenantContext::class)->runFor($tenant->id, fn () => UserIdentity::query()->where('user_id', $user->id)->first()->public_id);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$publicId}"), ['current_password' => $password])
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($publicId): void {
        $identity = UserIdentity::withTrashed()->where('public_id', $publicId)->first();
        expect($identity->deleted_at)->not->toBeNull();
        expect(AuditLog::query()->where('auditable_type', 'user_identity')->where('event', 'deleted')->count())->toBe(1);
    });

    Queue::assertPushed(SendIdentityUnlinkedEmail::class);

    // Un login posterior con esa cuenta de Google ya no entra.
    [$url2, $anonCookie] = beginFakeGoogleFlow($tenant->slug);
    $after = completeFakeGoogleFlow($url2, $anonCookie, fakeGoogleClaimsForLinking(['sub' => $sub]));
    expect(oauthCallbackResultCode($after))->toBe('sin_cuenta');
});

// CA-AUTH-227, RN-AUTH-96
test('CA-AUTH-227: si el vínculo fuera la única forma de entrar, desvincular responde 409 y el vínculo sigue vivo', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-227', ['email' => 'sin-otra-227@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'sin-otra-227@example.com', $password);

    [$url, $begin] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url, $begin, fakeGoogleClaimsForLinking())->assertRedirect();

    $publicId = app(TenantContext::class)->runFor($tenant->id, function () use ($user): string {
        $identity = UserIdentity::query()->where('user_id', $user->id)->first();
        // §E.4.5 punto 3: estado hoy inalcanzable por el propio esquema
        // (users.password NOT NULL) — se construye a mano, forzando el
        // hueco que la guarda cubre para 1.4b.
        DB::table('users')->where('id', $user->id)->update(['password' => '']);

        return $identity->public_id;
    });

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$publicId}"), ['current_password' => $password])
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->whereNull('deleted_at')->count())->toBe(1);
    });
});

// CA-AUTH-228
test('CA-AUTH-228: desvincular y volver a vincular deja dos filas, una borrada y una viva, no una revivida', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-228', ['email' => 'revincula-228@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'revincula-228@example.com', $password);
    $sub = 'sub-228';

    [$url1, $begin1] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url1, $begin1, fakeGoogleClaimsForLinking(['sub' => $sub]))->assertRedirect();

    $firstPublicId = app(TenantContext::class)->runFor($tenant->id, fn () => UserIdentity::query()->where('user_id', $user->id)->first()->public_id);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$firstPublicId}"), ['current_password' => $password])
        ->assertNoContent();

    [$url2, $begin2] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url2, $begin2, fakeGoogleClaimsForLinking(['sub' => $sub]))->assertRedirect();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $firstPublicId): void {
        $all = UserIdentity::withTrashed()->where('user_id', $user->id)->get();
        expect($all)->toHaveCount(2);

        $first = $all->firstWhere('public_id', $firstPublicId);
        expect($first->deleted_at)->not->toBeNull();

        $live = $all->firstWhere('deleted_at', null);
        expect($live)->not->toBeNull();
        expect($live->public_id)->not->toBe($firstPublicId);
    });
});

// CA-AUTH-215, ADR-038 §6.4, RN-AUTH-07
test('CA-AUTH-215: un public_id de vínculo de otro tenant responde 404, nunca 403, y la fila del otro tenant sigue viva', function (): void {
    Queue::fake();
    [$tenantA, $userA, $passwordA] = provisionActiveUser('link-215-a', ['email' => 'a-215@example.com']);
    [$tenantB, $userB, $passwordB] = provisionActiveUser('link-215-b', ['email' => 'b-215@example.com']);

    $cookieB = localSessionCookie($tenantB->slug, 'b-215@example.com', $passwordB);
    [$urlB, $beginB] = beginFakeGoogleFlow($tenantB->slug, 'link', $cookieB);
    completeFakeGoogleFlow($urlB, $beginB, fakeGoogleClaimsForLinking())->assertRedirect();

    $publicIdB = app(TenantContext::class)->runFor($tenantB->id, fn () => UserIdentity::query()->where('user_id', $userB->id)->first()->public_id);

    $cookieA = localSessionCookie($tenantA->slug, 'a-215@example.com', $passwordA);

    withSessionCookie($cookieA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/auth/identities/{$publicIdB}"), ['current_password' => $passwordA])
        ->assertNotFound();

    app(TenantContext::class)->runFor($tenantB->id, function () use ($userB): void {
        expect(UserIdentity::query()->where('user_id', $userB->id)->whereNull('deleted_at')->count())->toBe(1);
    });
});

// api.md §E.5: GET /auth/identities enmascara el correo y expone
// link_method, linked_at, last_login_at.
test('GET /auth/identities devuelve el correo enmascarado y el link_method', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('link-list', ['email' => 'lista@example.com']);
    $cookie = localSessionCookie($tenant->slug, 'lista@example.com', $password);

    [$url, $begin] = beginFakeGoogleFlow($tenant->slug, 'link', $cookie);
    completeFakeGoogleFlow($url, $begin, fakeGoogleClaimsForLinking(['email' => 'externo@gmail.com']))->assertRedirect();

    $list = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();

    expect($list->json('meta.total'))->toBe(1)
        ->and($list->json('data.0.email_at_link'))->not->toBe('externo@gmail.com')
        ->and($list->json('data.0.email_at_link'))->toContain('@')
        ->and($list->json('data.0.link_method'))->toBe('perfil')
        ->and($list->json('data.0'))->not->toHaveKey('subject');
});
