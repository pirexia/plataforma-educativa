<?php

// funcional.md §7.2 punto 4, CA-AUTH-034, issue #18: el PasswordBroker de
// Laravel es email-céntrico y busca sin predicado de tenant explícito
// (RN-AUTH-07) — prohibido por test de arquitectura, no solo por
// convención, para que el hallazgo no vuelva dentro de seis meses por la
// puerta de otro módulo. pestphp/pest-plugin-arch está instalado
// (vendor/pestphp/pest-plugin-arch), a diferencia de lo que anota
// tests/Feature/Tenancy/IsolationBatteryTest.php para `toExtend` (no
// existe en esta versión), `toBeUsedIn`/`not->toBeUsedIn` sí.

arch('CA-AUTH-034: apps/api/app no usa el PasswordBroker de Laravel ni su DatabaseTokenRepository')
    ->expect([
        'Illuminate\Support\Facades\Password',
        'Illuminate\Auth\Passwords\PasswordBroker',
        'Illuminate\Auth\Passwords\PasswordBrokerManager',
        'Illuminate\Auth\Passwords\DatabaseTokenRepository',
        'Illuminate\Auth\Passwords\CacheTokenRepository',
    ])
    ->not->toBeUsedIn('App');

// funcional.md §7.2 punto 5: config/auth.php propio del proyecto no
// declara 'passwords' (verificado por ausencia de la clave en el fichero,
// no por config('auth.passwords') — Laravel 11+ fuerza el merge de
// guards/providers/passwords de su propio config/auth.php interno aunque
// el de la aplicación exista y omita la clave, así que en runtime
// config('auth.passwords.users') sigue apareciendo con los valores por
// defecto del framework. No es una violación de CA-AUTH-034: el criterio
// es que ningún código de apps/api/app referencie el broker, verificado
// arriba; nada lee ni usa esa clave).
test('CA-AUTH-034: config/auth.php propio del proyecto no declara la clave passwords', function (): void {
    $ownConfigFile = require base_path('config/auth.php');

    expect($ownConfigFile)->not->toHaveKey('passwords');
});

// El propio repositorio de REQ-AUTH sí puede (y debe) implementar la
// interfaz propia sin pasar por el broker — lo verifica indirectamente
// PasswordResetEndpointTest.php al ejercitar el flujo HTTP real.
test('DatabasePasswordResetTokenRepository implementa la interfaz propia del módulo, no el contrato del framework', function (): void {
    $implements = (new ReflectionClass(\App\Modules\Auth\Infrastructure\DatabasePasswordResetTokenRepository::class))
        ->implementsInterface(\App\Modules\Auth\Domain\PasswordResetTokenRepository::class);

    expect($implements)->toBeTrue();
});
