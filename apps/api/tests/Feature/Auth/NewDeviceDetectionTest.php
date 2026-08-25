<?php

use App\Modules\Auth\Domain\Models\UserKnownDevice;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Infrastructure\Jobs\SendNewDeviceLoginEmail;
use App\Modules\Auth\Infrastructure\Mail\NewDeviceLoginMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// funcional.md §B.4.5, §B.6, RN-AUTH-45/46/50. REQ-AUTH-005 punto 4:
// detección de acceso desde dispositivo no reconocido (1.2b).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function loginWithDevice(
    string $slug,
    string $email,
    string $password,
    ?string $deviceCookieValue = null,
    ?string $userAgent = null,
) {
    resetSessionState();

    $request = test()->withCredentials();

    if ($deviceCookieValue !== null) {
        $request = $request->withUnencryptedCookie('pge_device', $deviceCookieValue);
    }

    if ($userAgent !== null) {
        $request = $request->withHeader('User-Agent', $userAgent);
    }

    return $request->postJson(coreApiUrl($slug, '/auth/session'), ['email' => $email, 'password' => $password]);
}

// CA-AUTH-093, RN-AUTH-45, RN-AUTH-46, INV-012
test('CA-AUTH-093: login sin cookie pge_device emite la cookie, registra el dispositivo y encola la alerta', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('dev-093');

    $login = loginWithDevice($tenant->slug, $user->email, $password)->assertOk();

    $cookie = $login->getCookie('pge_device', false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getDomain())->toBeEmpty();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $devices = UserKnownDevice::query()->where('user_id', $user->id)->get();
        expect($devices)->toHaveCount(1);
        expect($devices->first()->device_token_hash)->not->toBeEmpty();
    });

    Queue::assertPushed(SendNewDeviceLoginEmail::class, fn ($job) => $job->userPublicId === $user->public_id);
});

// CA-AUTH-094, RN-AUTH-46
test('CA-AUTH-094: presentar la cookie pge_device conocida no registra dispositivo nuevo ni encola alerta', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('dev-094');

    $first = loginWithDevice($tenant->slug, $user->email, $password)->assertOk();
    $deviceCookie = $first->getCookie('pge_device', false)->getValue();

    Queue::fake();
    loginWithDevice($tenant->slug, $user->email, $password, $deviceCookie)->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $devices = UserKnownDevice::query()->where('user_id', $user->id)->get();
        expect($devices)->toHaveCount(1)
            ->and($devices->first()->login_count)->toBe(2);
    });

    Queue::assertNotPushed(SendNewDeviceLoginEmail::class);
});

// CA-AUTH-095, RN-AUTH-46
test('CA-AUTH-095: la cookie de dispositivo del usuario A presentada por el usuario B es un dispositivo nuevo para B, y no toca el de A', function (): void {
    Queue::fake();
    [$tenant, $userA, $passwordA] = provisionActiveUser('dev-095', ['email' => 'usuario-a@example.com']);
    $rawPasswordB = 'Cl4v3-Correcta-2026!';
    $userB = app(TenantContext::class)->runFor($tenant->id, function () use ($rawPasswordB) {
        $person = \App\Models\Person::factory()->create();

        return \App\Models\User::factory()->for($person)->create([
            'email' => 'usuario-b@example.com',
            'password' => $rawPasswordB,
            'status' => \App\Models\UserStatus::Activo,
        ]);
    });
    $passwordB = $rawPasswordB;

    $loginA = loginWithDevice($tenant->slug, $userA->email, $passwordA)->assertOk();
    $cookieA = $loginA->getCookie('pge_device', false)->getValue();

    $deviceAId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => UserKnownDevice::query()->where('user_id', $userA->id)->firstOrFail()->id,
    );

    Queue::fake();
    loginWithDevice($tenant->slug, $userB->email, $passwordB, $cookieA)->assertOk();

    Queue::assertPushed(SendNewDeviceLoginEmail::class, fn ($job) => $job->userPublicId === $userB->public_id);

    app(TenantContext::class)->runFor($tenant->id, function () use ($userB, $deviceAId): void {
        expect(UserKnownDevice::query()->where('user_id', $userB->id)->count())->toBe(1);

        $deviceA = UserKnownDevice::query()->find($deviceAId);
        expect($deviceA->login_count)->toBe(1);
    });
});

// CA-AUTH-096, RN-AUTH-08, RN-AUTH-45, INV-001
test('CA-AUTH-096: una cookie de dispositivo obtenida en el tenant A, forzada en el tenant B, produce un dispositivo nuevo en B sin tocar A', function (): void {
    Queue::fake();
    [$tenantA, $userA, $passwordA] = provisionActiveUser('dev-096a');
    [$tenantB, $userB, $passwordB] = provisionActiveUser('dev-096b');

    $loginA = loginWithDevice($tenantA->slug, $userA->email, $passwordA)->assertOk();
    $cookieA = $loginA->getCookie('pge_device', false)->getValue();

    Queue::fake();
    // El navegador real jamás enviaría esta cookie host-only a otro
    // tenant (CA-AUTH-003 ya cubre el atributo); aquí se fuerza a mano
    // para comprobar que, aunque llegara, el servidor la trata como
    // dispositivo nuevo del tenant B sin cruzar datos con A.
    loginWithDevice($tenantB->slug, $userB->email, $passwordB, $cookieA)->assertOk();

    app(TenantContext::class)->runFor($tenantB->id, function () use ($userB): void {
        expect(UserKnownDevice::query()->where('user_id', $userB->id)->count())->toBe(1);
    });
    app(TenantContext::class)->runFor($tenantA->id, function () use ($userA): void {
        $deviceA = UserKnownDevice::query()->where('user_id', $userA->id)->firstOrFail();
        expect($deviceA->login_count)->toBe(1);
    });
});

// CA-AUTH-097, funcional.md §B.6.4
test('CA-AUTH-097: un User-Agent irreconocible no rompe el login y produce "desconocido"', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('dev-097');

    loginWithDevice($tenant->slug, $user->email, $password, null, 'esto-no-es-un-user-agent-real-1234')->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
        expect($session->client_browser)->toBe('desconocido')
            ->and($session->client_platform)->toBe('desconocido')
            ->and($session->client_device_type->value)->toBe('desconocido');
    });
});

// CA-AUTH-098, RN-AUTH-46
test('CA-AUTH-098: por encima del tope diario, se registran todos los dispositivos pero solo se encola el máximo de alertas', function (): void {
    // El foco de este test es RN-AUTH-46 (tope de alertas), no el límite
    // de tasa de CA-AUTH-074 (su propio test) — se sube para que los
    // varios logins legítimos sin cookie no choquen con él.
    config(['auth-local.rate_limits.session_email.max' => 200]);
    config(['auth-local.rate_limits.session_ip.max' => 200]);

    [$tenant, $user, $password] = provisionActiveUser('dev-098');
    $cap = (int) config('auth-local.new_device_alerts_per_day');
    $attempts = $cap + 2;

    Queue::fake();

    for ($i = 0; $i < $attempts; $i++) {
        loginWithDevice($tenant->slug, $user->email, $password, null, "UA-{$i}")->assertOk();
    }

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $attempts, $cap): void {
        expect(UserKnownDevice::query()->where('user_id', $user->id)->count())->toBe($attempts);
        expect(UserKnownDevice::query()->where('user_id', $user->id)->whereNotNull('alerted_at')->count())->toBe($cap);
    });

    Queue::assertPushed(SendNewDeviceLoginEmail::class, $cap);
});

// CA-AUTH-099, funcional.md §B.4.5 encabezado
test('CA-AUTH-099: un login fallido desde un dispositivo desconocido no registra dispositivo, no encola alerta y no crea sesión', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('dev-099');

    loginWithDevice($tenant->slug, $user->email, 'contraseña-incorrecta')->assertStatus(401);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserKnownDevice::query()->where('user_id', $user->id)->count())->toBe(0);
        expect(UserSession::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    Queue::assertNotPushed(SendNewDeviceLoginEmail::class);
});

// CA-AUTH-100, RN-AUTH-50, INV-009
test('CA-AUTH-100: la alerta de dispositivo nuevo va en el idioma preferido del destinatario y no lleva enlace accionable sin sesión', function (): void {
    Mail::fake();
    [$tenant, $user, $password] = provisionActiveUser('dev-100', [], ['locale' => 'fr']);

    loginWithDevice($tenant->slug, $user->email, $password)->assertOk();

    Mail::assertSent(NewDeviceLoginMail::class, fn (NewDeviceLoginMail $mail): bool => $mail->hasTo($user->email));

    $sent = null;
    Mail::assertSent(NewDeviceLoginMail::class, function (NewDeviceLoginMail $mail) use (&$sent): bool {
        $sent = $mail;

        return true;
    });
    expect($sent)->not->toBeNull();

    // El correo se compone y se envía en el idioma preferido del
    // destinatario (fr, fijado arriba vía person.locale) — REQ-CORE-006
    // capa 2, mismo mecanismo que el resto de correos del módulo.
    // Mail::to(...)->locale('fr')->send(...) deja ese idioma fijado en
    // el propio Mailable (Mailable::$locale), así que render() ya
    // compone en fr sin necesidad de forzar el locale de la aplicación.
    $rendered = $sent->render();
    // fr: __('auth.mail.new_device_login.cta') === 'Vérifier mes sessions actives'
    expect($rendered)->toContain('Vérifier mes sessions actives')
        ->and($rendered)->toContain('/cuenta/sesiones');

    // Ningún enlace salvo el de /cuenta/sesiones (RN-AUTH-50): ni token,
    // ni una ruta de un solo clic que ejecute una acción.
    preg_match_all('/href="([^"]+)"/', $rendered, $matches);
    foreach ($matches[1] as $href) {
        expect($href)->toContain('/cuenta/sesiones')
            ->and($href)->not->toContain('token');
    }
});

// CA-AUTH-100: comprobación explícita de las cuatro traducciones (INV-009).
test('CA-AUTH-100: el asunto de la alerta existe traducido en los cuatro idiomas', function (): void {
    foreach (['es' => 'Nuevo acceso', 'en' => 'New sign-in', 'de' => 'Neue Anmeldung', 'fr' => 'Nouvelle connexion'] as $locale => $expectedFragment) {
        app()->setLocale($locale);
        $subject = __('auth.mail.new_device_login.subject', ['tenant' => 'Centro ficticio']);
        expect($subject)->toContain($expectedFragment);
    }

    app()->setLocale('es');
});
