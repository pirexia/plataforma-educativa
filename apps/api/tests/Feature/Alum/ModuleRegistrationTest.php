<?php

use App\Modules\Alum\Infrastructure\AlumServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

// ADR-056 §3.5 (generado por `php artisan make:module`), AR-03, ADR-034 §5:
// el módulo Alum se descubre solo y declara su código; no es esencial.

test('AR-03 ADR-056: el ServiceProvider de Alum se descubre sin registro a mano', function (): void {
    expect(ModuleServiceProviderDiscovery::discover(app_path('Modules')))->toContain(AlumServiceProvider::class);
});

test('ADR-034 §5 ADR-056: el descriptor de Alum declara su código, la clave de nombre y no es esencial', function (): void {
    $descriptor = (new AlumServiceProvider(app()))->moduleDescriptor();

    expect($descriptor['code'])->toBe('alum')
        ->and($descriptor['name_key'])->toBe('modules.alum')
        ->and($descriptor['essential'])->toBeFalse()
        ->and($descriptor['depends_on'])->toBe(['curso', 'acad', 'fam_unit']);
});
