<?php

use App\Support\Files\ForeignObjectKeyException;
use App\Support\Files\PrivateObjectStore;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * ADR-076 §5 (CA-076-05, INV-001): conformidad de PrivateObjectStore contra un servidor S3 REAL (Garage en desarrollo y
 * CI; el proveedor de producción en staging). Grupo `storage-conformance`, fuera de `composer test`
 * (`composer test:conformance`).
 *
 * Se salta (no falla) sin `STORAGE_CONFORMANCE_ENABLED=1`. Con él, usa las variables `AWS_*` del entorno del proceso
 * (`AWS_ENDPOINT` de operación; `AWS_URL_SIGNING_ENDPOINT` opcional, ADR-076 §4). Datos sintéticos: dos centros de
 * fábrica, claves bajo un área única por ejecución y limpieza al terminar.
 *
 * Discrepancia con ADR-076 §5.4 y §2.3 (experimento S-1a, docs/proceso/experimento-garage-s1a.md): una URL firmada
 * caducada recibe 400 (`InvalidRequest: Date is too old`) de Garage, no 403. Lo que se exige es un 4xx que NO sirva el
 * contenido, y se aceptan 400 y 403 (el proveedor real puede devolver 403). El ADR es inmutable y no se edita.
 */

beforeEach(function (): void {
    if (env('STORAGE_CONFORMANCE_ENABLED') !== '1' && env('STORAGE_CONFORMANCE_ENABLED') !== true) {
        $this->markTestSkipped('Conformidad de almacenamiento desactivada: define STORAGE_CONFORMANCE_ENABLED=1 y las variables AWS_* de un S3 real (ADR-076 §5).');
    }

    config(['filesystems.default' => 's3', 'files.disk' => 's3', 'files.signed_url_max_ttl_minutes' => 15]);
    Storage::forgetDisk('s3');

    $this->area = 'conformance/run-'.strtolower(Str::random(12));
    $this->created = [];
    $this->a = Tenant::factory()->create();
    $this->b = Tenant::factory()->create();

    $this->putAs = function (Tenant $tenant, string $contents = 'contenido sintético', string $ext = 'txt'): string {
        app(TenantContext::class)->leave();
        app(TenantContext::class)->enter($tenant->id);
        $key = app(PrivateObjectStore::class)->put($this->area, $contents, $ext);
        $this->created[] = $key;

        return $key;
    };

    $this->enter = function (Tenant $tenant): PrivateObjectStore {
        app(TenantContext::class)->leave();
        app(TenantContext::class)->enter($tenant->id);

        return app(PrivateObjectStore::class);
    };

    $this->fetch = fn (string $url): int => Http::withOptions(['allow_redirects' => false])->timeout(10)->get($url)->status();
});

afterEach(function (): void {
    if (! isset($this->created)) {
        return; // saltado antes de preparar nada
    }

    foreach ($this->created as $key) {
        Storage::disk('s3')->delete($key);
    }

    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->whereIn('id', [$this->a->id, $this->b->id])->delete();
});

test('ADR-076 §5.1: subir, leer, comparar contenido y tamaño, exists y lastModified', function (): void {
    $key = ($this->putAs)($this->a, 'abc-123');
    $store = ($this->enter)($this->a);

    expect($store->get($key))->toBe('abc-123')
        ->and(Storage::disk('s3')->size($key))->toBe(7)
        ->and($store->exists($key))->toBeTrue()
        ->and($store->lastModified($key))->toBeGreaterThan(time() - 300)
        ->and($store->lastModified($key))->toBeLessThanOrEqual(time() + 300);
});

test('ADR-076 §5.2: borrar y comprobar ausencia', function (): void {
    $key = ($this->putAs)($this->a);
    $store = ($this->enter)($this->a);

    expect($store->exists($key))->toBeTrue();

    $store->delete($key);

    expect($store->exists($key))->toBeFalse()
        ->and($store->get($key))->toBeNull();
});

test('ADR-076 §5.3: listar un área solo devuelve claves del centro en curso', function (): void {
    $a1 = ($this->putAs)($this->a, 'a1');
    $a2 = ($this->putAs)($this->a, 'a2');
    $b1 = ($this->putAs)($this->b, 'b1');

    $fromA = ($this->enter)($this->a)->keysIn($this->area);
    $fromB = ($this->enter)($this->b)->keysIn($this->area);

    expect($fromA)->toEqualCanonicalizing([$a1, $a2])
        ->and($fromB)->toBe([$b1]);
});

test('ADR-076 §5.4: URL firmada sin credenciales sirve el contenido; caducada, con firma alterada o con clave cambiada, no', function (): void {
    $key = ($this->putAs)($this->a, 'secreto-sintetico');
    $store = ($this->enter)($this->a);

    $url = $store->temporaryUrl($key, now()->addMinutes(5));
    $ok = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($url);
    expect($ok->status())->toBe(200)->and($ok->body())->toBe('secreto-sintetico');

    // Firma alterada → 403.
    $at = strpos($url, 'X-Amz-Signature=') + strlen('X-Amz-Signature=');
    $tampered = substr_replace($url, $url[$at] === '0' ? '1' : '0', $at, 1);
    expect($tampered)->not->toBe($url);
    $tamperedResponse = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($tampered);
    expect($tamperedResponse->status())->toBe(403)->and($tamperedResponse->body())->not->toContain('secreto-sintetico');

    // Clave cambiada a mano (misma firma, otro nombre) → 403.
    $changed = str_replace(basename($key), Str::upper((string) Str::ulid()).'.txt', $url);
    expect($changed)->not->toBe($url)
        ->and(($this->fetch)($changed))->toBe(403);

    // Caducada: Garage responde 400 («Date is too old»), el ADR dice 403; se acepta cualquiera de los dos.
    $short = $store->temporaryUrl($key, now()->addSeconds(2));
    expect(($this->fetch)($short))->toBe(200);
    sleep(5);
    $expired = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($short);
    expect($expired->status())->toBeIn([400, 403])->and($expired->body())->not->toContain('secreto-sintetico');
});

test('ADR-076 §5.5: aislamiento entre dos centros: A no lee, firma, lista ni borra claves de B, y su URL no sirve para B', function (): void {
    $keyA = ($this->putAs)($this->a, 'de-a');
    $keyB = ($this->putAs)($this->b, 'de-b');

    $storeA = ($this->enter)($this->a);

    expect(fn () => $storeA->get($keyB))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $storeA->exists($keyB))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $storeA->lastModified($keyB))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $storeA->temporaryUrl($keyB, now()->addMinutes(5)))->toThrow(ForeignObjectKeyException::class)
        ->and(fn () => $storeA->delete($keyB))->toThrow(ForeignObjectKeyException::class)
        ->and($storeA->keysIn($this->area))->toBe([$keyA])
        ->and(Storage::disk('s3')->exists($keyB))->toBeTrue();

    // La URL de A, con la ruta cambiada a la clave de B, no sirve el objeto de B.
    $urlA = $storeA->temporaryUrl($keyA, now()->addMinutes(5));
    $forged = str_replace($keyA, $keyB, $urlA);
    expect($forged)->not->toBe($urlA);
    $response = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($forged);
    expect($response->status())->toBe(403)->and($response->body())->not->toContain('de-b');
});

test('ADR-076 §5.6: el endpoint de firma es distinto del de operación (si está configurado)', function (): void {
    $operation = (string) config('filesystems.disks.s3.endpoint');
    $signing = config('filesystems.disks.s3.signing_endpoint');

    if (! is_string($signing) || $signing === '' || rtrim($signing, '/') === rtrim($operation, '/')) {
        $this->markTestSkipped('AWS_URL_SIGNING_ENDPOINT no está configurado distinto de AWS_ENDPOINT: el punto 6 de ADR-076 §5 aplica solo entonces.');
    }

    $key = ($this->putAs)($this->a, 'firma-aparte');
    $url = ($this->enter)($this->a)->temporaryUrl($key, now()->addMinutes(5));

    $signingHost = parse_url($signing, PHP_URL_HOST).':'.(parse_url($signing, PHP_URL_PORT) ?? 80);
    $operationHost = parse_url($operation, PHP_URL_HOST).':'.(parse_url($operation, PHP_URL_PORT) ?? 80);

    // La URL lleva el host de firma, no el de operación, y sirve el objeto.
    expect(parse_url($url, PHP_URL_HOST).':'.(parse_url($url, PHP_URL_PORT) ?? 80))->toBe($signingHost)
        ->and(($this->fetch)($url))->toBe(200);

    // Reescribir el host al de operación después de firmar invalida la firma (SigV4 firma `Host`): motivo de §4.
    $rewritten = preg_replace('#^(https?://)[^/]+#', '${1}'.$operationHost, $url);
    expect(($this->fetch)($rewritten))->toBe(403);
});

test('ADR-076 §5.7: con when_required, subida y lectura sin error y el cliente lleva esa configuración', function (): void {
    expect(config('filesystems.disks.s3.request_checksum_calculation'))->toBe('when_required')
        ->and(config('filesystems.disks.s3.response_checksum_validation'))->toBe('when_required');

    $key = ($this->putAs)($this->a, str_repeat('x', 20000), 'bin');
    $store = ($this->enter)($this->a);

    expect(strlen((string) $store->get($key)))->toBe(20000);
});
