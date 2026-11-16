<?php

use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Http\Resources\UserImportResource;
use App\Modules\Core\Infrastructure\BrandingAssetUrlSigner;
use App\Support\Files\ForeignObjectKeyException;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// ADR-076 §3.1/§4, issue #625: las URL firmadas de marca, exportaciones e informes de importación pasan por
// `PrivateObjectStore::temporaryUrl` (endpoint de firma, tope de caducidad, aislamiento por centro, INV-001).

beforeEach(function (): void {
    config([
        'filesystems.disks.s3_probe' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
            'bucket' => 'b', 'endpoint' => 'http://garage:3900', 'use_path_style_endpoint' => true,
            'signing_endpoint' => 'http://127.0.0.1:3900'],
        'files.disk' => 's3_probe',
        'files.signed_url_max_ttl_minutes' => 15,
    ]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function su_expires(string $url): int
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return (int) $q['X-Amz-Expires'];
}

// --- BrandingAssetUrlSigner -------------------------------------------------------------------------------------

test('ADR-076 #625 marca: la URL empieza por el endpoint de firma; clave ajena lanza ForeignObjectKeyException; TTL recortado', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->runFor($a->id, function () use ($a, $b): void {
        $signer = app(BrandingAssetUrlSigner::class);

        expect($signer->sign(null))->toBeNull()
            ->and($signer->sign("tenants/{$a->public_id}/branding/logo/x.png"))->toStartWith('http://127.0.0.1:3900/b/tenants/');

        expect(fn () => $signer->sign("tenants/{$b->public_id}/branding/logo/x.png"))->toThrow(ForeignObjectKeyException::class);

        config(['core.signed_url_ttl_minutes' => 120]);
        expect(su_expires($signer->sign("tenants/{$a->public_id}/branding/logo/x.png")))->toBeLessThanOrEqual(15 * 60);
    });
});

// --- UserImportResource -----------------------------------------------------------------------------------------

test('ADR-076 #625 informe de importación: endpoint de firma, aislamiento y tope de TTL', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->runFor($a->id, function () use ($a, $b): void {
        $own = new UserImport(['report_object_key' => "tenants/{$a->public_id}/imports/1/report.csv"]);
        $url = (new UserImportResource($own))->resolve()['report_url'];

        expect($url)->toStartWith('http://127.0.0.1:3900/b/tenants/');

        config(['core.signed_url_ttl_minutes' => 120]);
        expect(su_expires((new UserImportResource($own))->resolve()['report_url']))->toBeLessThanOrEqual(15 * 60);

        $foreign = new UserImport(['report_object_key' => "tenants/{$b->public_id}/imports/1/report.csv"]);
        expect(fn () => (new UserImportResource($foreign))->resolve())->toThrow(ForeignObjectKeyException::class);
    });
});

// --- DataExportsController --------------------------------------------------------------------------------------

test('ADR-076 #625 exportación: download_url con endpoint de firma y TTL acotado; clave de otro centro no se firma', function (): void {
    [$tenant, $admin] = provisionCoreTenant('su-625');
    $other = Tenant::factory()->create();
    Queue::fake();

    $id = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users/exports'), [])
        ->assertStatus(202)->json('public_id');
    resetSessionState();

    $complete = fn (string $key) => app(TenantContext::class)->runFor($tenant->id, function () use ($id, $key): void {
        DataExport::where('public_id', $id)->firstOrFail()->forceFill([
            'status' => 'completada', 'object_key' => $key, 'row_count' => 1, 'expires_at' => now()->addDay(),
        ])->save();
    });

    config(['core.signed_url_ttl_minutes' => 120]);
    $complete("tenants/{$tenant->public_id}/exports/x.csv");

    $url = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))
        ->assertOk()->json('download_url');
    resetSessionState();

    expect($url)->toStartWith('http://127.0.0.1:3900/b/tenants/')->and(su_expires($url))->toBeLessThanOrEqual(15 * 60);

    $complete("tenants/{$other->public_id}/exports/x.csv");

    $response = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"));
    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->and($response->json('download_url'))->toBeNull();
});
