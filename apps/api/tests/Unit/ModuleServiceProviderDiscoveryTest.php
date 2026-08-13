<?php

use App\Support\Modules\ModuleServiceProviderDiscovery;

// Paso 0.4 del plan: los módulos (bounded contexts) se autoregistran por
// convención, sin tocar bootstrap/providers.php a mano (INV-007).

function fakeModulesPath(): string
{
    return sys_get_temp_dir().'/module-discovery-test-'.uniqid();
}

test('descubre un ServiceProvider que sigue la convención', function (): void {
    $modulesPath = fakeModulesPath();
    $providerDir = "{$modulesPath}/Demo/Infrastructure";
    mkdir($providerDir, recursive: true);

    file_put_contents("{$providerDir}/DemoServiceProvider.php", <<<'PHP'
        <?php
        namespace App\Modules\Demo\Infrastructure;
        class DemoServiceProvider extends \Illuminate\Support\ServiceProvider {}
        PHP);
    require "{$providerDir}/DemoServiceProvider.php";

    $providers = ModuleServiceProviderDiscovery::discover($modulesPath);

    expect($providers)->toBe(['App\Modules\Demo\Infrastructure\DemoServiceProvider']);
});

test('ignora clases que no extienden ServiceProvider', function (): void {
    $modulesPath = fakeModulesPath();
    $providerDir = "{$modulesPath}/Otro/Infrastructure";
    mkdir($providerDir, recursive: true);

    file_put_contents("{$providerDir}/OtroServiceProvider.php", <<<'PHP'
        <?php
        namespace App\Modules\Otro\Infrastructure;
        class OtroServiceProvider {}
        PHP);
    require "{$providerDir}/OtroServiceProvider.php";

    expect(ModuleServiceProviderDiscovery::discover($modulesPath))->toBe([]);
});

test('devuelve una lista vacía si el directorio no existe', function (): void {
    expect(ModuleServiceProviderDiscovery::discover(fakeModulesPath()))->toBe([]);
});
