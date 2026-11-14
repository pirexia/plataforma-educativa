<?php

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §9 "Transversales". CA-CORE-071/072/073 están cubiertos
 * indirectamente por otros tests del módulo; aquí se ejercitan de forma
 * explícita, referenciando su ID (`INV-015`). CA-CORE-075 verifica de
 * forma sistemática que los cuatro idiomas de `ADR-021` existen para cada
 * literal del catálogo propio del módulo, en vez de una comprobación
 * manual.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-CORE-071
test('CA-CORE-071: una escritura con éxito deja un registro de auditoría con actor, IP, user_agent y request_id', function (): void {
    [$tenant, $admin] = provisionCoreTenant('transversal-071');

    test()->actingAs($admin)
        ->withHeaders(['User-Agent' => 'Pest-Test-Suite/1.0'])
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'regional' => ['timezone' => 'Atlantic/Canary'],
        ])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($admin): void {
        $log = AuditLog::query()
            ->where('auditable_type', 'tenant_setting')
            ->where('event', 'updated')
            ->latest('occurred_at')
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->actor_user_id)->toBe($admin->id)
            ->and($log->ip_address)->not->toBeNull()
            ->and($log->user_agent)->toBe('Pest-Test-Suite/1.0')
            ->and($log->request_id)->not->toBeNull();
    });
});

// CA-CORE-072
test('CA-CORE-072: los recursos expuestos por la API se identifican por public_id ULID, nunca por la clave interna', function (): void {
    [$tenant, $admin] = provisionCoreTenant('transversal-072');

    $responses = [
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/tenant/settings'))->assertOk(),
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?per_page=5'))->assertOk(),
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/roles?per_page=5'))->assertOk(),
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/modules'))->assertOk(),
    ];

    $ulidPattern = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    foreach ($responses as $response) {
        $publicIds = [];
        assertNoBareId($response->json(), $publicIds);

        foreach ($publicIds as $publicId) {
            expect($publicId)->toMatch($ulidPattern);
        }
    }
});

/**
 * @param  array<int|string, mixed>  $node
 * @param  list<string>  $publicIds
 */
function assertNoBareId(mixed $node, array &$publicIds): void
{
    if (! is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {
        expect($key)->not->toBe('id');

        if ($key === 'public_id' && is_string($value)) {
            $publicIds[] = $value;
        }

        if (is_array($value)) {
            assertNoBareId($value, $publicIds);
        }
    }
}

// CA-CORE-073
test('CA-CORE-073: pedir por public_id un recurso de otro tenant devuelve 404, no 403', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('transversal-073-a');
    [$tenantB, $adminB] = provisionCoreTenant('transversal-073-b');

    $foreignUserId = test()->actingAs($adminB)
        ->postJson(coreApiUrl($tenantB->slug, '/users'), [
            'email' => 'ajeno@example.com',
            'person' => ['given_name' => 'Ajeno', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
        ])
        ->assertCreated()
        ->json('public_id');

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/users/{$foreignUserId}"))
        ->assertStatus(404);
});

// CA-CORE-075
test('CA-CORE-075: el catálogo de literales propio del módulo existe en es-ES, en, de y fr', function (): void {
    $locales = ['es' => 'es-ES', 'en' => 'en', 'de' => 'de', 'fr' => 'fr'];

    $flattened = [];

    foreach ($locales as $dir => $label) {
        $catalog = require base_path("lang/{$dir}/core.php");
        $flattened[$label] = flattenKeys($catalog);
    }

    $reference = $flattened['es-ES'];

    foreach ($flattened as $label => $keys) {
        if ($label === 'es-ES') {
            continue;
        }

        $missing = array_diff($reference, $keys);
        $extra = array_diff($keys, $reference);

        expect($missing)->toBeEmpty("Faltan claves en {$label}: ".implode(', ', $missing));
        expect($extra)->toBeEmpty("Sobran claves en {$label} que no están en es-ES: ".implode(', ', $extra));
    }
});

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function flattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = [...$keys, ...flattenKeys($value, $path)];
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}
