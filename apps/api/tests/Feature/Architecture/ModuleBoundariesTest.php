<?php

use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Auth\Infrastructure\Google2FaTotpVerifier;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Architecture\UsesAuthDomainContract;
use Tests\Fixtures\Architecture\UsesAuthDomainModels;
use Tests\Fixtures\Architecture\UsesAuthInfrastructure;
use Tests\Support\ArchitectureModules;

pest()->group('arch');

// ADR-056 AR-01, INV-007, CA-056-02, RNF-MANT-003: un módulo solo usa de
// otro su `Domain` EXCLUIDO `Domain\Models`; nunca `Application`,
// `Infrastructure`, `Http`, `Database` ni cualquier otra carpeta o clase de
// su raíz (regla invertida, #378 B2: lo vedado se calcula por sistema de
// ficheros con `ArchitectureModules::forbiddenFor()`). Un `arch()` por módulo (no por
// pareja), generado por enumeración del sistema de ficheros: un módulo
// nuevo queda vigilado sin tocar este test.
//
// Un solo objetivo por `arch()` a propósito: `expect([A, B])->not->toUse(...)`
// con varios objetivos pasa en vacío en esta versión de Pest (el cierre
// positivo lanza al primer objetivo que no usa la dependencia y la
// inversión lo da por bueno). Con un objetivo y varias dependencias sí
// funciona, y es lo que se usa aquí.

$moduleNames = ArchitectureModules::names();

foreach ($moduleNames as $module) {
    arch("AR-01 INV-007 CA-056-02: el módulo {$module} solo usa de otro módulo su Domain, excluido Domain\\Models")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse(ArchitectureModules::forbiddenFor($module));
}

test('AR-01 CA-056-02: la enumeración de módulos no es vacía y cubre los tres existentes', function (): void {
    expect(ArchitectureModules::names())->toContain('Auth', 'Backoffice', 'Core');
});

// CA-056-02: al retirar el enlace redundante de `BackofficeServiceProvider`
// el contenedor sigue resolviendo las dos interfaces al adaptador de Auth
// (el enlace vive en `AuthServiceProvider`).
test('CA-056-02: MfaVerifier y TotpProvisioner siguen resolviéndose a Google2FaTotpVerifier', function (): void {
    expect(app(MfaVerifier::class))->toBeInstanceOf(Google2FaTotpVerifier::class)
        ->and(app(TotpProvisioner::class))->toBeInstanceOf(Google2FaTotpVerifier::class);
});

// #378 B2: el cálculo de lo vedado es el de la regla invertida, probado sobre
// un árbol temporal (no se crea nada en `app/Modules/`).
test('AR-01 CA-056-02 #378 B2: una carpeta o clase nueva en la raíz de un módulo queda vedada, Domain no, Domain\\Models sí', function (): void {
    $tmp = sys_get_temp_dir().'/ar01-'.bin2hex(random_bytes(4));
    mkdir("{$tmp}/Demo/Domain/Models", 0777, true);
    mkdir("{$tmp}/Demo/Listeners", 0777, true);
    mkdir("{$tmp}/Demo/Application", 0777, true);
    touch("{$tmp}/Demo/DemoHelper.php");

    try {
        expect(ArchitectureModules::forbiddenNamespacesOf('Demo', $tmp))->toBe([
            'App\\Modules\\Demo\\Application',
            'App\\Modules\\Demo\\DemoHelper',
            'App\\Modules\\Demo\\Domain\\Models',
            'App\\Modules\\Demo\\Listeners',
        ]);
    } finally {
        @unlink("{$tmp}/Demo/DemoHelper.php");
        foreach (['Demo/Domain/Models', 'Demo/Domain', 'Demo/Listeners', 'Demo/Application', 'Demo', ''] as $dir) {
            @rmdir(rtrim("{$tmp}/{$dir}", '/'));
        }
    }
});

// #378 B1: control negativo PERMANENTE. Si una versión de Pest dejara
// `not->toUse` pasando en vacío (ya ocurrió con varios objetivos), estos
// controles fallarían. Cada fixture de `tests/Fixtures/Architecture/` viola
// la frontera a propósito; el positivo prueba que la regla no lanza siempre.
test('AR-01 CA-056-02 #378 B1: la regla muerde — usar Infrastructure o Domain\\Models de otro módulo lanza', function (string $fixture): void {
    $forbidden = ArchitectureModules::forbiddenFor('Backoffice');

    expect($forbidden)->toContain('App\\Modules\\Auth\\Infrastructure', 'App\\Modules\\Auth\\Domain\\Models')
        ->and(fn () => expect($fixture)->not->toUse($forbidden))->toThrow(ExpectationFailedException::class);
})->with([
    'Infrastructure' => [UsesAuthInfrastructure::class],
    'Domain\\Models' => [UsesAuthDomainModels::class],
]);

test('AR-01 CA-056-02 #378 B1: la regla no muerde por el Domain permitido (control positivo)', function (): void {
    expect(fn () => expect(UsesAuthDomainContract::class)->not->toUse(ArchitectureModules::forbiddenFor('Backoffice')))
        ->not->toThrow(ExpectationFailedException::class);
});
