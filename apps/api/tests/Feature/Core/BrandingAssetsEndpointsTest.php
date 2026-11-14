<?php

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// Firma binaria mínima de un PNG válido de 1x1 px (dominio público).
function corePngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
}

// CA-CORE-005
test('CA-CORE-005: un PNG renombrado a .svg se rechaza por tipo real distinto del declarado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-005');

    $file = UploadedFile::fake()->createWithContent('logo.svg', corePngBytes());

    $response = test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $file])
        ->assertStatus(422);

    expect($response->json('errors.file.0.code'))->toBe('core.validation.file_type_mismatch');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $settings = TenantSetting::query()->first();
        expect($settings?->logo_object_key)->toBeNull();
    });

    expect(Storage::disk('local')->allFiles("tenants/{$tenant->public_id}/branding/logo"))->toBeEmpty();
});

// CA-CORE-006
test('CA-CORE-006: un SVG con <script> y onload se sanea antes de almacenarse', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-006');

    $malicious = <<<'SVG'
    <?xml version="1.0" encoding="UTF-8"?>
    <svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)" width="10" height="10">
      <script>alert('xss')</script>
      <rect width="10" height="10" onclick="evil()" />
      <image href="https://evil.example.com/tracker.png" />
    </svg>
    SVG;

    $file = UploadedFile::fake()->createWithContent('logo.svg', $malicious);

    test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $file])
        ->assertOk();

    $objectKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    expect($objectKey)->not->toBeNull();

    $stored = Storage::disk('local')->get($objectKey);

    expect($stored)->not->toContain('<script')
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('evil.example.com');
});

// CA-CORE-005/006 complementario: subida válida y borrado
test('un logo PNG válido se sube, se lee por URL firmada y se puede borrar', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-ok');

    $file = UploadedFile::fake()->createWithContent('logo.png', corePngBytes());

    $upload = test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $file])
        ->assertOk();

    expect($upload->json('kind'))->toBe('logo')
        ->and($upload->json('url'))->not->toBeNull();

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'))
        ->assertNoContent();

    $objectKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    expect($objectKey)->toBeNull();
});

test('DELETE sobre un tipo de activo sin logo devuelve 404', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-404');

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, '/tenant/settings/assets/favicon'))
        ->assertStatus(404);
});

test('un fichero que excede el tamaño máximo del tipo de activo se rechaza con 413', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-413');

    $file = UploadedFile::fake()->create('logo.png', 2000, 'image/png');

    test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $file])
        ->assertStatus(413);
});

test('un fondo de login en SVG se rechaza con 415: login-background no admite SVG', function (): void {
    [$tenant, $admin] = provisionCoreTenant('branding-415');

    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"></svg>';
    $file = UploadedFile::fake()->createWithContent('fondo.svg', $svg);

    test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/login-background'), [], [], ['file' => $file])
        ->assertStatus(415);
});

// Issue #53 (hallazgo de security-reviewer, INV-001): PUT/DELETE .../assets/{kind}
// no reciben un identificador ajeno en la ruta (operan sobre la configuración
// del propio tenant resuelto por host), así que el aislamiento a comprobar es
// que la clave de objeto y la fila de tenant_settings de un tenant no se ven
// alteradas ni expuestas por la subida de otro tenant.
test('el logo de un tenant no se ve afectado por la subida de otro tenant y queda bajo su propio prefijo', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('branding-tenant-a');
    [$tenantB, $adminB] = provisionCoreTenant('branding-tenant-b');

    $fileA = UploadedFile::fake()->createWithContent('logo.png', corePngBytes());
    test()->actingAs($adminA)
        ->call('PUT', coreApiUrl($tenantA->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $fileA])
        ->assertOk();

    $keyA = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    $fileB = UploadedFile::fake()->createWithContent('logo.png', corePngBytes());
    test()->actingAs($adminB)
        ->call('PUT', coreApiUrl($tenantB->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $fileB])
        ->assertOk();

    $keyAAfterB = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );
    $keyB = app(TenantContext::class)->runFor(
        $tenantB->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    expect($keyAAfterB)->toBe($keyA)
        ->and($keyA)->toStartWith("tenants/{$tenantA->public_id}/branding/logo/")
        ->and($keyB)->toStartWith("tenants/{$tenantB->public_id}/branding/logo/")
        ->and($keyB)->not->toBe($keyA);

    // La borrada de B no debe tocar la configuración de A.
    test()->actingAs($adminB)
        ->deleteJson(coreApiUrl($tenantB->slug, '/tenant/settings/assets/logo'))
        ->assertNoContent();

    $keyAAfterBDelete = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    expect($keyAAfterBDelete)->toBe($keyA);
});
