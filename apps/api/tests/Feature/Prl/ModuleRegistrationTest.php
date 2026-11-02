<?php

use App\Modules\Prl\Infrastructure\PrlServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

// ADR-056 §3.5 (generado por `php artisan make:module`), AR-03, ADR-034 §5, ADR-068 §5.1, CA-068-07, CA-PRL-011:
// el módulo Prl se descubre solo, declara su código, no es esencial y depende de `fam_unit`.

test('AR-03 ADR-056: el ServiceProvider de Prl se descubre sin registro a mano', function (): void {
    expect(ModuleServiceProviderDiscovery::discover(app_path('Modules')))->toContain(PrlServiceProvider::class);
});

test('CA-068-07 CA-PRL-011 ADR-068 §5.1: el descriptor de Prl declara su código, la clave de nombre, no es esencial y depende de fam_unit (REQ-PRL-004)', function (): void {
    $descriptor = (new PrlServiceProvider(app()))->moduleDescriptor();

    expect($descriptor['code'])->toBe('prl')
        ->and($descriptor['name_key'])->toBe('modules.prl')
        ->and($descriptor['phase'])->toBe('1')
        ->and($descriptor['essential'])->toBeFalse()
        ->and($descriptor['depends_on'])->toBe(['fam_unit']);
});
