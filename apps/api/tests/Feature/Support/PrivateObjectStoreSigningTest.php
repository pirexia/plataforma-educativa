<?php

use App\Support\Files\PrivateObjectStore;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// ADR-076 §4: endpoint de firma, tope global de caducidad y sumas de verificación del disco s3.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
    $this->s3 = ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'b',
        'endpoint' => 'http://garage:3900', 'use_path_style_endpoint' => true];
    $this->key = "tenants/{$this->tenant->public_id}/area/x.png";
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function useS3Probe(array $config): void
{
    config(['filesystems.disks.s3_probe' => $config, 'files.disk' => 's3_probe']);
}

test('ADR-076 §4: con endpoint de firma configurado la URL empieza por él y no por el de operación', function (): void {
    useS3Probe($this->s3 + ['signing_endpoint' => 'http://127.0.0.1:3900']);

    $url = app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addMinutes(5));

    expect($url)->toStartWith('http://127.0.0.1:3900/b/tenants/')->and($url)->not->toContain('garage');
});

test('ADR-076 §4: con endpoint de firma vacío la URL empieza por el endpoint del disco', function (): void {
    useS3Probe($this->s3 + ['signing_endpoint' => null]);

    expect(app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addMinutes(5)))
        ->toStartWith('http://garage:3900/b/tenants/');
});

test('ADR-076 §4: un TTL mayor que el tope global se recorta al tope', function (): void {
    useS3Probe($this->s3);
    config(['files.signed_url_max_ttl_minutes' => 15]);

    $url = app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addHours(2));

    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect((int) $q['X-Amz-Expires'])->toBeLessThanOrEqual(15 * 60)->toBeGreaterThan(14 * 60);
});

test('ADR-076 §4: un TTL menor que el tope se respeta', function (): void {
    useS3Probe($this->s3);
    config(['files.signed_url_max_ttl_minutes' => 15]);

    $url = app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addMinutes(5));

    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect((int) $q['X-Amz-Expires'])->toBeLessThanOrEqual(5 * 60)->toBeGreaterThan(4 * 60);
});

test('ADR-076 §4: en production y staging un endpoint de firma sin https falla en cerrado', function (string $env): void {
    useS3Probe($this->s3 + ['signing_endpoint' => 'http://127.0.0.1:3900']);
    app()->detectEnvironment(fn (): string => $env);

    try {
        expect(fn () => app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addMinutes(5)))
            ->toThrow(RuntimeException::class, 'https');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with(['production', 'staging']);

test('ADR-076 §4: en production un endpoint de firma https se acepta', function (): void {
    useS3Probe($this->s3 + ['signing_endpoint' => 'https://files.example.com']);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(app(PrivateObjectStore::class)->temporaryUrl($this->key, now()->addMinutes(5)))
            ->toStartWith('https://files.example.com/b/tenants/');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

test('ADR-076 §4: el disco s3 declara when_required para las sumas de verificación y el tope vale 15 por defecto', function (): void {
    expect(config('filesystems.disks.s3.request_checksum_calculation'))->toBe('when_required')
        ->and(config('filesystems.disks.s3.response_checksum_validation'))->toBe('when_required')
        ->and(config('files.signed_url_max_ttl_minutes'))->toBe(15);
});
