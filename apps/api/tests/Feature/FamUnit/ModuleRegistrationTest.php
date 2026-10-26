<?php

use App\Modules\FamUnit\Infrastructure\FamUnitServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

// ADR-056 §3.5 (generado por `php artisan make:module`), AR-03, ADR-034 §5:
// el módulo FamUnit se descubre solo y declara su código; no es esencial.

test('AR-03 ADR-056: el ServiceProvider de FamUnit se descubre sin registro a mano', function (): void {
    expect(ModuleServiceProviderDiscovery::discover(app_path('Modules')))->toContain(FamUnitServiceProvider::class);
});

test('ADR-034 §5 ADR-056: el descriptor de FamUnit declara su código, la clave de nombre y no es esencial', function (): void {
    $descriptor = (new FamUnitServiceProvider(app()))->moduleDescriptor();

    expect($descriptor['code'])->toBe('fam_unit')
        ->and($descriptor['name_key'])->toBe('modules.fam_unit')
        ->and($descriptor['essential'])->toBeFalse()
        ->and($descriptor['depends_on'])->toBe([]);
});
