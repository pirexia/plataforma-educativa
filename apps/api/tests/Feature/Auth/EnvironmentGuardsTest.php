<?php

use App\Modules\Auth\Infrastructure\PasswordPolicyEnvironmentGuard;
use App\Modules\Auth\Infrastructure\SessionEnvironmentGuard;

// funcional.md §6.2, operacion.md §2.2, issue #8. Mismo patrón que
// tests/Feature/Core/DocumentValidationConfigTest.php: se instancia la
// guarda directamente y se manipula config() antes de llamar a verify(),
// en vez de reiniciar el proceso — las guardas son puro PHP sin más
// dependencias que config().

afterEach(function (): void {
    // Deja la configuración tal como la fijan los <env> de phpunit.xml,
    // para no arrastrar valores de un test a otro.
    config([
        'session.domain' => '',
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.partitioned' => false,
        'session.secure' => null,
        'session.lifetime' => 480,
        'session.driver' => 'database',
        'auth-local.session_timeout_max_minutes' => 480,
        'auth-local.password_min_length' => 12,
        'hashing.bcrypt.rounds' => 12,
    ]);
});

// CA-AUTH-001
test('CA-AUTH-001: SESSION_DOMAIN con cualquier valor no vacío aborta el arranque, en todos los entornos', function (): void {
    config(['session.domain' => 'plataforma.test']);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_DOMAIN');
});

test('CA-AUTH-001: SESSION_DOMAIN vacío no aborta el arranque', function (): void {
    config(['session.domain' => '']);

    expect(fn () => (new SessionEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});

test('RN-AUTH-26: SESSION_HTTP_ONLY distinto de true aborta el arranque', function (): void {
    config(['session.http_only' => false]);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_HTTP_ONLY');
});

test('RN-AUTH-27: SESSION_SAME_SITE=none está prohibido', function (): void {
    config(['session.same_site' => 'none']);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_SAME_SITE');
});

test('SESSION_PARTITIONED_COOKIE=true sin SameSite=None aborta el arranque', function (): void {
    config(['session.partitioned' => true]);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_PARTITIONED_COOKIE');
});

test('en producción, SESSION_SECURE_COOKIE distinto de true aborta el arranque', function (): void {
    config(['session.secure' => false]);
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => (new SessionEnvironmentGuard)->verify())
            ->toThrow(RuntimeException::class, 'SESSION_SECURE_COOKIE');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('fuera de producción, SESSION_SECURE_COOKIE=false no aborta el arranque', function (): void {
    config(['session.secure' => false]);

    expect(fn () => (new SessionEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});

// CA-AUTH-052
test('CA-AUTH-052: SESSION_LIFETIME menor que AUTH_SESSION_TIMEOUT_MAX_MINUTES aborta el arranque', function (): void {
    config(['session.lifetime' => 120, 'auth-local.session_timeout_max_minutes' => 480]);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_LIFETIME');
});

test('CA-AUTH-052: SESSION_LIFETIME igual al máximo no aborta el arranque', function (): void {
    config(['session.lifetime' => 480, 'auth-local.session_timeout_max_minutes' => 480]);

    expect(fn () => (new SessionEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});

// CA-AUTH-103, RN-AUTH-49 (1.2b): sin guarda, la revocación no tendría
// nada que borrar y respondería 204 sin haber cerrado nada realmente.
test('CA-AUTH-103: SESSION_DRIVER distinto de "database" aborta el arranque, en todos los entornos', function (): void {
    config(['session.driver' => 'array']);

    expect(fn () => (new SessionEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'SESSION_DRIVER');
});

test('SESSION_DRIVER=database no aborta el arranque', function (): void {
    config(['session.driver' => 'database']);

    expect(fn () => (new SessionEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});

// operacion.md §2.1: guarda documentada, ausente hasta este hallazgo
// (issue de severidad media, corregido en la misma sesión).
test('RN-AUTH-01: AUTH_PASSWORD_MIN_LENGTH por debajo de 12 aborta el arranque', function (): void {
    config(['auth-local.password_min_length' => 8]);

    expect(fn () => (new PasswordPolicyEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'AUTH_PASSWORD_MIN_LENGTH');
});

test('RN-AUTH-01: AUTH_PASSWORD_MIN_LENGTH en 12 o por encima no aborta el arranque', function (): void {
    config(['auth-local.password_min_length' => 14]);

    expect(fn () => (new PasswordPolicyEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});

test('RN-AUTH-03: AUTH_BCRYPT_ROUNDS por debajo de 12 aborta el arranque', function (): void {
    config(['hashing.bcrypt.rounds' => 4]);

    expect(fn () => (new PasswordPolicyEnvironmentGuard)->verify())
        ->toThrow(RuntimeException::class, 'AUTH_BCRYPT_ROUNDS');
});

test('RN-AUTH-03: AUTH_BCRYPT_ROUNDS en 12 o por encima no aborta el arranque', function (): void {
    config(['hashing.bcrypt.rounds' => 13]);

    expect(fn () => (new PasswordPolicyEnvironmentGuard)->verify())->not->toThrow(RuntimeException::class);
});
