<?php

use App\Support\Files\ForeignObjectKeyException;
use App\Support\Files\PrivateObjectStore;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// ADR-068 §5.3, CA-068-02, INV-001: PrivateObjectStore pone y comprueba el prefijo del centro en curso.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
    $this->a = Tenant::factory()->create();
    $this->b = Tenant::factory()->create();
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-068-02: la clave lleva el prefijo del centro en curso, un ULID y la extensión, y nunca datos de la persona', function (): void {
    app(TenantContext::class)->enter($this->a->id);

    $key = app(PrivateObjectStore::class)->put('fam-unit/pickup-photos', 'bytes', 'jpg');

    expect($key)->toMatch('#^tenants/'.$this->a->public_id.'/fam-unit/pickup-photos/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#')
        ->and(Storage::disk('local')->get($key))->toBe('bytes');
});

test('CA-068-02: con el centro A, firmar, leer, comprobar o borrar una clave del prefijo de B lanza, y el objeto de B sigue ahí', function (): void {
    app(TenantContext::class)->enter($this->b->id);
    $foreign = app(PrivateObjectStore::class)->put('fam-unit/pickup-photos', 'de-b', 'png');

    app(TenantContext::class)->leave();
    app(TenantContext::class)->enter($this->a->id);
    $store = app(PrivateObjectStore::class);

    expect(fn () => $store->temporaryUrl($foreign, now()->addMinutes(5)))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $store->delete($foreign))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $store->get($foreign))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $store->exists($foreign))->toThrow(ForeignObjectKeyException::class)
        ->and(Storage::disk('local')->exists($foreign))->toBeTrue();

    // Ni siquiera el propio prefijo sin objeto, ni un recorrido «..» que salga del prefijo.
    $own = "tenants/{$this->a->public_id}/";
    expect(fn () => $store->delete($own))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $store->delete("{$own}../{$this->b->public_id}/x.png"))->toThrow(ForeignObjectKeyException::class);
});

test('CA-068-02: firma y borra las propias, y un área o una extensión no válidas lanzan', function (): void {
    app(TenantContext::class)->enter($this->a->id);
    $store = app(PrivateObjectStore::class);
    $key = $store->put('fam-unit/pickup-photos', 'x', 'webp');

    expect($store->exists($key))->toBeTrue()
        ->and($store->temporaryUrl($key, now()->addMinutes(5)))->toBeString()->toContain(basename($key))
        ->and($store->keysIn('fam-unit/pickup-photos'))->toBe([$key]);

    $store->delete($key);
    expect($store->exists($key))->toBeFalse();

    expect(fn () => $store->put('../otro', 'x', 'jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->put('Fam Unit', 'x', 'jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->put('fam-unit', 'x', '../jpg'))->toThrow(InvalidArgumentException::class);
});

test('INV-001: sin centro en curso no se escribe', function (): void {
    expect(fn () => app(PrivateObjectStore::class)->put('fam-unit/pickup-photos', 'x', 'jpg'))->toThrow(TenantContextMissing::class);
});

test('ADR-068 §5.3 ADR-013: fuera de los tests, un disco local es un error (fallo en cerrado) en escribir, firmar, leer, borrar y listar', function (): void {
    app(TenantContext::class)->enter($this->a->id);
    $store = app(PrivateObjectStore::class);
    $key = $store->put('fam-unit/pickup-photos', 'x', 'jpg');
    $original = app()->environment();
    app()['env'] = 'local';

    try {
        expect(fn () => $store->put('fam-unit/pickup-photos', 'x', 'jpg'))->toThrow(RuntimeException::class, 'disco local')
            ->and(fn () => $store->temporaryUrl($key, now()->addMinutes(5)))->toThrow(RuntimeException::class, 'disco local')
            ->and(fn () => $store->get($key))->toThrow(RuntimeException::class, 'disco local')
            ->and(fn () => $store->delete($key))->toThrow(RuntimeException::class, 'disco local')
            ->and(fn () => $store->keysIn('fam-unit/pickup-photos'))->toThrow(RuntimeException::class, 'disco local');
    } finally {
        app()['env'] = $original;
    }

    // Un disco S3 no se rechaza (la comprobación es por controlador, no por entorno): se configura uno cualquiera de ese tipo.
    config(['filesystems.disks.s3_probe' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'b', 'endpoint' => 'http://127.0.0.1:1', 'use_path_style_endpoint' => true], 'files.disk' => 's3_probe']);
    app()['env'] = 'local';

    try {
        expect($store->temporaryUrl($key, now()->addMinutes(5)))->toStartWith('http://127.0.0.1:1/b/tenants/');
    } finally {
        app()['env'] = $original;
    }
});
