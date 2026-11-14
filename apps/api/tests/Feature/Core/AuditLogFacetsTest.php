<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\AuditCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-CORE-245, S10 (api.md §14.4, OPEN-CORE-34 = B).
test('CA-CORE-245: GET /audit-logs/facets devuelve el catálogo declarado y no consulta audit_logs', function (): void {
    [$tenant, $admin] = provisionCoreTenant('facets-245');

    // La primera petición autenticada tras el aprovisionamiento escribe su
    // propio registro (inicio de sesión del actor de la prueba): se descarta
    // con una petición previa para medir solo la de `facets`.
    test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    DB::connection('pgsql_platform')->enableQueryLog();
    DB::connection('pgsql_platform')->flushQueryLog();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs/facets'))
        ->assertOk();

    $queries = collect(DB::getQueryLog())
        ->merge(DB::connection('pgsql_platform')->getQueryLog())
        ->pluck('query');

    expect($queries->filter(fn (string $q) => str_contains($q, 'audit_logs'))->all())->toBe([]);

    $body = $response->json();

    expect(array_keys($body))->toBe(['modules', 'auditable_types', 'events', 'actor_types'])
        ->and($body['modules'])->toBe(AuditCatalog::modules())
        ->and($body['events'])->toHaveCount(9)
        ->and($body['events'])->toContain('login', 'logout', 'password_reset_requested')
        ->and($body['actor_types'])->toBe(['user', 'system', 'console', 'import', 'platform', 'anonymous'])
        ->and($body['auditable_types'])->toContain(['alias' => 'user', 'module' => 'core'])
        ->and(collect($body['auditable_types'])->pluck('module')->unique()->values()->all())->toBe($body['modules']);
});

test('CA-CORE-245: las facetas ofrecidas son exactamente las que GET /audit-logs acepta', function (): void {
    [$tenant, $admin] = provisionCoreTenant('facets-245b');

    $facets = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/audit-logs/facets'))->assertOk()->json();

    test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?actor_type='.implode(',', $facets['actor_types']).'&module='.implode(',', $facets['modules']).'&event='.implode(',', $facets['events'])))
        ->assertOk();
});

test('CA-CORE-245 (INV-002): sin auditoria.leer, GET /audit-logs/facets devuelve 403; sin sesión, 401', function (): void {
    [$tenant] = provisionCoreTenant('facets-403');

    $secretaria = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 's@example.com']);
        $user = User::factory()->for($person)->create(['email' => 's@example.com']);
        $user->roles()->attach(Role::where('code', 'secretaria')->firstOrFail()->id);

        return $user;
    });

    test()->actingAs($secretaria)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs/facets'))
        ->assertStatus(403);

    resetSessionState();

    test()->getJson(coreApiUrl($tenant->slug, '/audit-logs/facets'))->assertStatus(401);
});

test('CA-CORE-245 (INV-001): las facetas no dependen de los datos de ningún tenant', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('facets-iso-a');
    [$tenantB, $adminB] = provisionCoreTenant('facets-iso-b');

    // Actividad solo en A: si la respuesta saliera de audit_logs, B vería otra cosa.
    test()->actingAs($adminA)->postJson(coreApiUrl($tenantA->slug, '/users'), [
        'email' => 'solo-a@example.com',
        'person' => ['given_name' => 'Solo', 'family_name_1' => 'A'],
        'send_invitation' => false,
    ])->assertCreated();

    $a = test()->actingAs($adminA)->getJson(coreApiUrl($tenantA->slug, '/audit-logs/facets'))->assertOk()->json();

    resetSessionState();

    $b = test()->actingAs($adminB)->getJson(coreApiUrl($tenantB->slug, '/audit-logs/facets'))->assertOk()->json();

    expect($b)->toBe($a)
        ->and(json_encode($b))->not->toContain('solo-a@example.com');
});
