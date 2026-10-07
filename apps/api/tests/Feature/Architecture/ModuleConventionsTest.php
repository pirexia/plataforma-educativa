<?php

use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\ModuleServiceProviderDiscovery;
use Illuminate\Support\ServiceProvider;
use Tests\Support\ArchitectureModules;
use Tests\Support\PhpScanner;

pest()->group('arch');

// ADR-056 AR-03, CA-056-04, RNF-MANT-003, ADR-034 §5, INV-015. Convención de
// módulo: todo directorio de `app/Modules/` tiene su `ServiceProvider`
// descubierto por `ModuleServiceProviderDiscovery`, declara el catálogo de
// módulo/permisos y carga sus migraciones. Se genera por enumeración del
// sistema de ficheros: un módulo nuevo queda vigilado sin tocar este test.

/**
 * Excepciones a `implements DeclaresModuleRegistry`: lista CERRADA y nominal
 * (ADR-056 §3.2). Solo puede reducirse; añadir una exige especificación
 * aprobada por el usuario (OPEN-056-02).
 *
 * @return array<string, string> módulo => motivo
 */
function declaresModuleRegistryExceptions(): array
{
    return [
        'Backoffice' => 'módulo de plataforma, no activable: sin fila en `modules` ni catálogo de `permissions` (REQ-BO/funcional.md §10, ADR-046/ADR-047)',
    ];
}

$moduleNames = ArchitectureModules::names();

foreach ($moduleNames as $module) {
    $providerClass = "App\\Modules\\{$module}\\Infrastructure\\{$module}ServiceProvider";

    test("AR-03 CA-056-04: {$module} tiene Infrastructure/{$module}ServiceProvider.php y aparece en ModuleServiceProviderDiscovery::discover()", function () use ($module, $providerClass): void {
        $file = app_path("Modules/{$module}/Infrastructure/{$module}ServiceProvider.php");

        expect(is_file($file))->toBeTrue("falta {$file}")
            ->and(ModuleServiceProviderDiscovery::discover(app_path('Modules')))->toContain($providerClass);
    });

    arch("AR-03 CA-056-04: {$providerClass} extiende ServiceProvider")
        ->expect($providerClass)
        ->toExtend(ServiceProvider::class);

    test("AR-03 CA-056-04: {$module} implementa DeclaresModuleRegistry salvo excepción nominal, y la excepción sigue haciendo falta", function () use ($module, $providerClass): void {
        $exceptions = declaresModuleRegistryExceptions();
        $implements = is_subclass_of($providerClass, DeclaresModuleRegistry::class);

        if (array_key_exists($module, $exceptions)) {
            expect($implements)->toBeFalse("{$module} ya implementa DeclaresModuleRegistry: retirarlo de la lista de excepciones de AR-03");

            return;
        }

        expect($implements)->toBeTrue("{$providerClass} debe implementar DeclaresModuleRegistry (ADR-034 §5)");
    });

    test("AR-03 CA-056-04: si {$module} tiene Database/migrations, su ruta está entre las del migrator", function () use ($module): void {
        $dir = app_path("Modules/{$module}/Database/migrations");

        if (! is_dir($dir)) {
            expect(true)->toBeTrue();

            return;
        }

        $paths = array_map(static fn (string $p): string|false => realpath($p), app('migrator')->paths());

        expect($paths)->toContain(realpath($dir));
    });
}

test('AR-03 CA-056-04: ninguna otra clase *ServiceProvider en App\Modules además de la de cada módulo', function (): void {
    $expected = array_map(
        static fn (string $m): string => app_path("Modules/{$m}/Infrastructure/{$m}ServiceProvider.php"),
        ArchitectureModules::names(),
    );

    $found = array_values(array_filter(
        PhpScanner::phpFiles(app_path('Modules')),
        static fn (string $f): bool => str_ends_with($f, 'ServiceProvider.php'),
    ));

    expect(count($found))->toBeGreaterThan(0)
        ->and(array_values(array_diff($found, $expected)))->toBe([], 'ServiceProvider no convencional en App\Modules (ADR-056 AR-03)');
});

test('AR-03 CA-056-04: la enumeración de módulos no es vacía y la única excepción declarada es Backoffice', function (): void {
    expect(ArchitectureModules::names())->toContain('Auth', 'Backoffice', 'Core')
        ->and(array_keys(declaresModuleRegistryExceptions()))->toBe(['Backoffice']);
});
