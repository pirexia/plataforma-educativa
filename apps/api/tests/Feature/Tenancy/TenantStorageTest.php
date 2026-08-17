<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissing;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// ADR-033 §9. Disco 'local' real (storage_path('app/private')): se limpia
// el subdirectorio tenants/ al terminar cada test.

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/private/tenants'));
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('sin tenant activo, disk() lanza TenantContextMissing', function (): void {
    expect(fn () => app(TenantStorage::class)->disk())
        ->toThrow(TenantContextMissing::class);
});

test('escribe bajo tenants/{public_id}/, no bajo el id interno', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenant->id);
    app(TenantStorage::class)->disk()->put('saludo.txt', 'hola');
    $context->leave();

    expect(File::exists(storage_path("app/private/tenants/{$tenant->public_id}/saludo.txt")))->toBeTrue()
        ->and(File::exists(storage_path("app/private/tenants/{$tenant->id}/saludo.txt")))->toBeFalse();
});

test('dos tenants no ven los ficheros del otro con la misma ruta relativa', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantA->id);
    app(TenantStorage::class)->disk()->put('informe.txt', 'de A');
    $context->leave();

    $context->enter($tenantB->id);
    $disk = app(TenantStorage::class)->disk();

    expect($disk->exists('informe.txt'))->toBeFalse();

    $disk->put('informe.txt', 'de B');
    $context->leave();

    expect(File::get(storage_path("app/private/tenants/{$tenantA->public_id}/informe.txt")))->toBe('de A')
        ->and(File::get(storage_path("app/private/tenants/{$tenantB->public_id}/informe.txt")))->toBe('de B');
});
