<?php

use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Auth\Infrastructure\Google2FaTotpVerifier;

pest()->group('arch');

// ADR-056 AR-01, INV-007, CA-056-02, RNF-MANT-003: un módulo solo usa de
// otro su `Domain` EXCLUIDO `Domain\Models`; nunca `Application`,
// `Infrastructure`, `Http` ni `Database`. Un `arch()` por módulo (no por
// pareja), generado por enumeración del sistema de ficheros: un módulo
// nuevo queda vigilado sin tocar este test.
//
// Un solo objetivo por `arch()` a propósito: `expect([A, B])->not->toUse(...)`
// con varios objetivos pasa en vacío en esta versión de Pest (el cierre
// positivo lanza al primer objetivo que no usa la dependencia y la
// inversión lo da por bueno). Con un objetivo y varias dependencias sí
// funciona, y es lo que se usa aquí.

/**
 * Directorios de `app/Modules/`. Función de colección: `base_path()` aún no
 * existe cuando Pest carga el fichero.
 *
 * @return list<string>
 */
function architectureModuleNames(): array
{
    $names = array_map('basename', glob(dirname(__DIR__, 3).'/app/Modules/*', GLOB_ONLYDIR) ?: []);
    sort($names);

    return $names;
}

$moduleNames = architectureModuleNames();

foreach ($moduleNames as $module) {
    $forbidden = [];

    foreach (array_diff($moduleNames, [$module]) as $other) {
        foreach (['Application', 'Infrastructure', 'Http', 'Database', 'Domain\\Models'] as $layer) {
            $forbidden[] = "App\\Modules\\{$other}\\{$layer}";
        }
    }

    arch("AR-01 INV-007 CA-056-02: el módulo {$module} no usa Application, Infrastructure, Http, Database ni Domain\\Models de otro módulo")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($forbidden);
}

test('AR-01 CA-056-02: la enumeración de módulos no es vacía y cubre los tres existentes', function (): void {
    expect(architectureModuleNames())->toContain('Auth', 'Backoffice', 'Core');
});

// CA-056-02: al retirar el enlace redundante de `BackofficeServiceProvider`
// el contenedor sigue resolviendo las dos interfaces al adaptador de Auth
// (el enlace vive en `AuthServiceProvider`).
test('CA-056-02: MfaVerifier y TotpProvisioner siguen resolviéndose a Google2FaTotpVerifier', function (): void {
    expect(app(MfaVerifier::class))->toBeInstanceOf(Google2FaTotpVerifier::class)
        ->and(app(TotpProvisioner::class))->toBeInstanceOf(Google2FaTotpVerifier::class);
});
