<?php

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ADR-033 §3: TenantContext es el punto donde el sistema falla en cerrado.
// INV-001, RNF-MANT-006.

function currentTenantGuc(): ?string
{
    return DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?: null;
}

test('tenantId() lanza TenantContextMissing si no hay tenant activo', function (): void {
    expect(fn () => app(TenantContext::class)->tenantId())
        ->toThrow(TenantContextMissing::class);
});

test('enter() fija el tenant en memoria y en el GUC de PostgreSQL', function (): void {
    $context = app(TenantContext::class);

    $context->enter(42);

    expect($context->tenantId())->toBe(42)
        ->and($context->hasTenant())->toBeTrue()
        ->and(currentTenantGuc())->toBe('42');

    $context->leave();
});

test('leave() limpia el tenant en memoria y en el GUC', function (): void {
    $context = app(TenantContext::class);

    $context->enter(7);
    $context->leave();

    expect($context->hasTenant())->toBeFalse()
        ->and(fn () => $context->tenantId())->toThrow(TenantContextMissing::class)
        ->and(currentTenantGuc())->toBeNull();
});

test('runFor() restaura el tenant anterior al terminar, incluso si el closure lanza', function (): void {
    $context = app(TenantContext::class);
    $context->enter(1);

    try {
        $context->runFor(2, function (): void {
            throw new RuntimeException('fallo dentro del closure');
        });
    } catch (RuntimeException) {
        // esperado
    }

    expect($context->tenantId())->toBe(1)
        ->and(currentTenantGuc())->toBe('1');

    $context->leave();
});

test('runFor() vuelve a "sin tenant" si no había ninguno antes de entrar', function (): void {
    $context = app(TenantContext::class);

    $result = $context->runFor(9, fn () => currentTenantGuc());

    expect($result)->toBe('9')
        ->and($context->hasTenant())->toBeFalse()
        ->and(currentTenantGuc())->toBeNull();
});

test('el prefijo de caché cambia con el tenant y aísla las claves', function (): void {
    $context = app(TenantContext::class);

    $context->enter(101);
    Cache::put('saludo', 'hola-101', 60);

    $context->enter(202);
    expect(Cache::get('saludo'))->toBeNull();
    Cache::put('saludo', 'hola-202', 60);

    $context->enter(101);
    expect(Cache::get('saludo'))->toBe('hola-101');

    Cache::forget('saludo');
    $context->enter(202);
    Cache::forget('saludo');
    $context->leave();
});
