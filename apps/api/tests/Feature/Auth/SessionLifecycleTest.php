<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Infrastructure\Jobs\SendPasswordResetEmail;
use App\Support\Audit\AuditChangeBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §B.4.6, §B.10; ADR-040 §4.3/§4.4. Las siete razones de
// cierre y la auditoría del ciclo de vida de `UserSession` (1.2b).

afterEach(function (): void {
    Carbon::setTestNow();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-101a, RN-AUTH-22
test('CA-AUTH-101a: restablecer la contraseña cierra todas las sesiones con cambio_credencial', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('life-101a');

    loginFor($tenant->slug, $user->email, $password);
    loginFor($tenant->slug, $user->email, $password);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/password-reset-requests'), ['email' => $user->email])
        ->assertStatus(202);

    $rawToken = null;
    Queue::assertPushed(SendPasswordResetEmail::class, function ($job) use (&$rawToken) {
        $rawToken = $job->rawToken;

        return true;
    });

    test()->postJson(coreApiUrl($tenant->slug, '/auth/password-resets'), [
        'token' => $rawToken,
        'password' => 'Otra-Clave-Nueva-2026!',
        'password_confirmation' => 'Otra-Clave-Nueva-2026!',
    ])->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $sessions = UserSession::query()->where('user_id', $user->id)->get();
        expect($sessions)->toHaveCount(2);
        $sessions->each(fn (UserSession $s) => expect($s->end_reason)->toBe(SessionEndReason::CambioCredencial));
    });
});

// CA-AUTH-101b, RN-AUTH-36
test('CA-AUTH-101b: cambiar la contraseña en autoservicio cierra las demás sesiones con cambio_credencial, salvo la actual', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('life-101b');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);

    withSessionCookie($cookie1)
        ->postJson(coreApiUrl($tenant->slug, '/auth/password-changes'), [
            'current_password' => $password,
            'password' => 'Otra-Clave-Nueva-2026!',
            'password_confirmation' => 'Otra-Clave-Nueva-2026!',
        ])
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->whereNull('ended_at')->count())->toBe(1);
        $closed = UserSession::query()->where('user_id', $user->id)->whereNotNull('ended_at')->get();
        expect($closed)->toHaveCount(1)
            ->and($closed->first()->end_reason)->toBe(SessionEndReason::CambioCredencial);
    });
});

// CA-AUTH-101c, funcional.md §B.4.6
test('CA-AUTH-101c: dar de baja al usuario (REQ-CORE) cierra sus sesiones con baja_usuario', function (): void {
    [$tenant, $admin] = provisionCoreTenant('life-101c');
    $victim = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'password' => 'Cl4v3-Correcta-2026!',
            'status' => UserStatus::Activo,
        ]);
    });

    loginFor($tenant->slug, $victim->email, 'Cl4v3-Correcta-2026!');

    $victimPublicId = $victim->public_id;

    resetSessionState();
    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/users/{$victimPublicId}"))
        ->assertStatus(204);

    app(TenantContext::class)->runFor($tenant->id, function () use ($victim): void {
        $session = UserSession::query()->where('user_id', $victim->id)->firstOrFail();
        expect($session->ended_at)->not->toBeNull()
            ->and($session->end_reason)->toBe(SessionEndReason::BajaUsuario);
    });
});

// CA-AUTH-101d, REQ-AUTH-005 punto 1 (ya cerrado en 1.2)
test('CA-AUTH-101d: la expiración por inactividad cierra la sesión con inactividad', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('life-101d');

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    Carbon::setTestNow(now()->addMinutes((int) config('auth-local.session_timeout_default_minutes') + 1));

    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertStatus(401);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
        expect($session->end_reason)->toBe(SessionEndReason::Inactividad);
    });
});

// Hallazgo propio de la revisión de seguridad de 1.2b (RN-AUTH-31,
// RN-AUTH-44). En tráfico real esta rama de VerifySessionTenant es una
// SEGUNDA barrera: mientras `TenantScope` (User extends TenantModel) siga
// vigente, `Auth::user()` nunca resuelve a un usuario de OTRO tenant que
// el resuelto por host — así que la discrepancia con un usuario YA
// resuelto solo se da si esa barrera falla alguna vez, que es exactamente
// lo que esta reverificación existe para blindar (ADR-033 §2). `actingAs()`
// (como ya hace `CA-AUTH-101c`) es la única forma de fijar el `Guard` sin
// pasar por esa comprobación, y es la manera de ejercitar esta rama sin
// depender de que `TenantScope`/RLS fallen de verdad.
//
// Antes del fix: `AuditLog::create()` escribía con `tenant_id` = tenant
// del host pero `actor_user_id` = usuario de OTRO tenant, violando la FK
// compuesta `audit_logs_actor_fk` — la petición terminaba en un 500 sin
// controlar (no en el 401 documentado) y la excepción interrumpía el
// método ANTES de invalidar la sesión, que quedaba viva.
test('RN-AUTH-31/RN-AUTH-44: una discrepancia de tenant responde 401 (no 500), y audita/cierra bajo el tenant real de la sesión', function (): void {
    [$tenantA, $userA, $passwordA] = provisionActiveUser('life-mismatch-a');
    [$tenantB] = provisionActiveUser('life-mismatch-b');

    // Sesión real de userA bajo tenant A: la fila de user_sessions que la
    // reverificación tiene que encontrar y cerrar. Se guarda la cookie
    // real para que StartSession RESUMA exactamente esta sesión en la
    // petición siguiente: session()->getId() consultado fuera de una
    // petición real despachada nunca coincide con el id que StartSession
    // genera para esa petición si no hay cookie — sin cookie real, cada
    // getJson() de prueba obtiene un id de sesión nuevo, desconectado del
    // de este login, y la reverificación nunca encontraba la fila que
    // cerrar (encontrado verificando este test).
    $login = loginFor($tenantA->slug, $userA->email, $passwordA);
    $cookie = sessionCookieValue($login);

    $sessionPublicId = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => UserSession::query()->where('user_id', $userA->id)->firstOrFail()->public_id,
    );

    // Cookie real de tenant A (para que la sesión resumida sea la real,
    // con su session_id real) + actingAs() (para forzar que Auth::user()
    // resuelva a userA pese al tenant del host — TenantScope impide que
    // esto ocurra por sí solo con una sesión real, que es justo el fallo
    // hipotético de TenantScope/RLS que esta reverificación cubre como
    // defensa en profundidad). resetSessionState() va ANTES de
    // actingAs(): actingAs() no puede seguirse de un reseteo, porque
    // resetSessionState() olvida los guards y anularía su override.
    resetSessionState();
    test()->withCredentials()->withUnencryptedCookie(config('session.cookie'), $cookie);
    test()->actingAs($userA);
    test()->getJson(coreApiUrl($tenantB->slug, '/me'))
        ->assertStatus(401);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($userA, $sessionPublicId): void {
        $session = UserSession::query()->where('public_id', $sessionPublicId)->firstOrFail();
        expect($session->ended_at)->not->toBeNull()
            ->and($session->end_reason)->toBe(SessionEndReason::TenantIncoherente);

        expect(
            AuditLog::query()
                ->where('auditable_type', 'user')
                ->where('event', 'logout')
                ->where('actor_user_id', $userA->id)
                ->exists()
        )->toBeTrue();
    });
});

// CA-AUTH-102, INV-003, ADR-035, ADR-039 §4.5, ADR-040 §6 (revocación individual)
test('CA-AUTH-102a: revocar una sesión individualmente queda auditada con session_id redactado', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('life-102a');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);

    $target = withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk()
        ->json('data');
    $other = collect($target)->firstWhere('current', false);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$other['public_id']}"))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $log = AuditLog::query()
            ->where('auditable_type', 'user_session')
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        expect($log->changes)->toHaveKey('end_reason')
            ->and($log->changes)->toHaveKey('ended_by');

        $raw = json_encode($log->getAttributes());
        // El identificador de sesión no aparece en la fila de auditoría,
        // ni siquiera si `changes` lo llevara sin redactar por error.
        foreach (UserSession::query()->pluck('session_id') as $sessionId) {
            expect($raw)->not->toContain($sessionId);
        }
    });
});

// datos.md §B.2: "session_id se declara en auditSecretAttributes,
// explícitamente... es el punto que la revisión de seguridad debe
// comprobar línea a línea". Comprobación directa del mecanismo real
// (no un patrón automático: `session_id` no contiene "token" ni
// "password" ni "secret") — si algún día se retira la declaración, este
// test falla incluso aunque `session_id` no llegue a estar "dirty" en
// ningún flujo de producción real.
test('ADR-040/datos.md §B.2: session_id se redacta como secreto vía AuditChangeBuilder, no por un patrón automático', function (): void {
    // Instancia no persistida: AuditChangeBuilder solo necesita un
    // Auditable con su política y sus secretos declarados, no una fila
    // real — deliberado, para no depender de tenant/login en este test.
    $session = new UserSession;

    expect($session->auditSecretAttributes())->toContain('session_id');

    $builder = app(AuditChangeBuilder::class);
    $result = $builder->build($session, ['session_id' => ['id-antiguo', 'id-nuevo']]);

    expect($result['session_id'])->toBe(['redacted' => 'secret']);
});

// ADR-040 §6 (la trampa de la revocación masiva): DELETE /auth/sessions
// también debe auditar cada cierre, modelo a modelo.
test('CA-AUTH-102b: la revocación masiva (scope=all) audita cada sesión cerrada, no solo la primera', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('life-102b');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);
    loginFor($tenant->slug, $user->email, $password);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions?scope=all'))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $updates = AuditLog::query()
            ->where('auditable_type', 'user_session')
            ->where('event', 'updated')
            ->get();

        // Las tres sesiones cerradas, las tres auditadas — no un UPDATE
        // masivo que el observer no vería.
        expect($updates)->toHaveCount(3);
    });
});

// ADR-040 §4.4, primer test: un login escribe exactamente una fila
// `login` sobre `User`, y ninguna `created` sobre `UserSession` — no
// "una fila en total": el propio login también registra, por su cuenta
// y sin llamada manual, el alta del dispositivo nuevo (`created` sobre
// `UserKnownDevice`, funcional.md §B.10), que no es lo que este ADR
// excluye.
test('ADR-040: un login escribe login sobre User y ninguna created sobre UserSession', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('life-adr040a');

    loginFor($tenant->slug, $user->email, $password);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'user')->where('event', 'login')->count())->toBe(1);
        expect(AuditLog::query()->where('auditable_type', 'user_session')->where('event', 'created')->exists())
            ->toBeFalse();
        // El otro lado de §B.10: el alta del dispositivo SÍ se audita,
        // por el observer, sin llamada manual.
        expect(AuditLog::query()->where('auditable_type', 'user_known_device')->where('event', 'created')->count())
            ->toBe(1);
    });
});

// ADR-040 §4.4, tercer test: exactamente un modelo declara exclusión, y
// es exactamente ['created'] — el guardián de §4.5.
test('ADR-040: UserSession es el único modelo del repositorio que declara auditExcludedEvents()', function (): void {
    $map = Relation::morphMap();

    $exclusions = [];

    foreach ($map as $alias => $class) {
        $excluded = (new $class)->auditExcludedEvents();

        if ($excluded !== []) {
            $exclusions[$alias] = $excluded;
        }
    }

    expect($exclusions)->toBe(['user_session' => ['created']]);
});
