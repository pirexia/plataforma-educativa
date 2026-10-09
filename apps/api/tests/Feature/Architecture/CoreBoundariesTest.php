<?php

use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Http\Middleware\VerifySessionTenant;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Core\Infrastructure\FeatureFlagCatalogCache;
use App\Support\Modules\SyncModuleRegistry;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Architecture\UsesAuthDomainContract;
use Tests\Fixtures\Architecture\UsesAuthDomainModels;
use Tests\Fixtures\Architecture\UsesAuthInfrastructure;
use Tests\Support\ArchitectureModules;

pest()->group('arch');

// ADR-056 AR-02, CA-056-03, INV-007, ADR-044 §4.10, INV-015: el núcleo
// (`App\Support`, `App\Http`, `App\Models`, `App\Providers`) no depende de
// los internos de ningún módulo: la misma frontera que AR-01 (solo su
// `Domain`, excluido `Domain\Models`). Un núcleo que depende de un módulo
// invierte la dirección que `ADR-044 §4.10` fijó.
//
// UN `arch()` POR OBJETIVO: con varios objetivos en una sola llamada
// `not->toUse` pasa en vacío en esta versión de Pest (ver AR-01). Los cuatro
// namespaces son cuatro objetivos, y cada uno de los tres excepcionados es
// su propio objetivo con la dependencia exacta ignorada: la excepción es
// nominal por clase Y por dependencia, no por carpeta.
//
// `ignoring(X)` excluye de la comprobación a cualquier clase cuyo nombre
// empiece por X (como objetivo) y X de los `use` (como dependencia): aquí X
// es siempre un FQCN completo.

/**
 * Excepciones de AR-02: lista CERRADA y nominal (ADR-056 §3.2). Solo puede
 * reducirse; añadir una exige especificación aprobada por el usuario
 * (OPEN-056-02). Su refactorización es el issue de severidad Media del
 * hallazgo 5 de ADR-056 §8, no de este paso.
 *
 * @return array<class-string, array{dependency: class-string, reason: string}>
 */
function coreToModuleExceptions(): array
{
    return [
        EnforceSessionIdleTimeout::class => [
            'dependency' => UserSession::class,
            'reason' => 'middleware de seguridad que lee la sesión de Auth (REQ-AUTH-005); refactorizarlo no es de 1.7b',
        ],
        VerifySessionTenant::class => [
            'dependency' => UserSession::class,
            'reason' => 'middleware de seguridad que lee la sesión de Auth (RN-AUTH-31); refactorizarlo no es de 1.7b',
        ],
        SyncModuleRegistry::class => [
            'dependency' => FeatureFlagCatalogCache::class,
            'reason' => 'invalida la caché del catálogo de flags de Core al sincronizar el registro (REQ-BO-005)',
        ],
    ];
}

/**
 * @return list<string> las cinco capas vedadas de cada módulo
 */
function forbiddenModuleLayers(): array
{
    $layers = [];

    foreach (ArchitectureModules::names() as $module) {
        foreach (['Application', 'Infrastructure', 'Http', 'Database', 'Domain\\Models'] as $layer) {
            $layers[] = "App\\Modules\\{$module}\\{$layer}";
        }
    }

    return $layers;
}

$forbidden = forbiddenModuleLayers();
$excepted = array_keys(coreToModuleExceptions());

foreach (['App\\Support', 'App\\Http', 'App\\Models', 'App\\Providers'] as $namespace) {
    arch("AR-02 INV-007 CA-056-03: {$namespace} no usa los internos de ningún módulo (salvo las excepciones nominales)")
        ->expect($namespace)
        ->not->toUse($forbidden)
        ->ignoring($excepted);
}

foreach (coreToModuleExceptions() as $class => $exception) {
    // La clase excepcionada no puede usar NADA vedado salvo su dependencia exacta.
    arch("AR-02 INV-007 CA-056-03: {$class} solo usa de módulos {$exception['dependency']}")
        ->expect($class)
        ->not->toUse($forbidden)
        ->ignoring($exception['dependency']);

    // CA-056-15: la excepción sigue haciendo falta (el fichero sigue usando esa clase).
    arch("AR-02 CA-056-15: la excepción {$class} sigue usando {$exception['dependency']}, si no, retirarla de la lista")
        ->expect($class)
        ->toUse($exception['dependency']);
}

test('AR-02 CA-056-03: la lista de excepciones es la de ADR-056 §3.3 (3 entradas) y la enumeración de módulos no es vacía', function (): void {
    expect(coreToModuleExceptions())->toHaveCount(3)
        ->and(forbiddenModuleLayers())->toHaveCount(count(ArchitectureModules::names()) * 5)
        ->and(ArchitectureModules::names())->toContain('Auth', 'Backoffice', 'Core');
});

// #378 B1: control negativo PERMANENTE de AR-02, con la misma cadena
// (`not->toUse($forbidden)->ignoring($excepted)`) que los `arch()` de arriba.
test('AR-02 CA-056-03 #378 B1: la regla muerde — usar Infrastructure o Domain\\Models de un módulo lanza', function (string $fixture): void {
    expect(fn () => expect($fixture)->not->toUse(forbiddenModuleLayers())->ignoring(array_keys(coreToModuleExceptions())))
        ->toThrow(ExpectationFailedException::class);
})->with([
    'Infrastructure' => [UsesAuthInfrastructure::class],
    'Domain\\Models' => [UsesAuthDomainModels::class],
]);

test('AR-02 CA-056-03 #378 B1: la regla no muerde por el Domain permitido (control positivo)', function (): void {
    expect(fn () => expect(UsesAuthDomainContract::class)->not->toUse(forbiddenModuleLayers())->ignoring(array_keys(coreToModuleExceptions())))
        ->not->toThrow(ExpectationFailedException::class);
});
