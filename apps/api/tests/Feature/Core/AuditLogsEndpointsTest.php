<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-CORE-050
test('CA-CORE-050: GET /audit-logs devuelve solo registros del propio tenant, orden descendente, con cursor', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-050');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'uno@example.com',
        'person' => ['given_name' => 'Uno', 'family_name_1' => 'Test'],
        'send_invitation' => false,
    ])->assertCreated();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?limit=5'))
        ->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
    $occurredAts = collect($response->json('data'))->pluck('occurred_at');
    expect($occurredAts->values()->all())->toBe($occurredAts->sortDesc()->values()->all());
});

// CA-CORE-051
test('CA-CORE-051: sin auditoria.leer, GET /audit-logs devuelve 403', function (): void {
    [$tenant] = provisionCoreTenant('audit-051');
    $secretaria = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 's@example.com']);
        $user = User::factory()->for($person)->create(['email' => 's@example.com']);
        $role = Role::where('code', 'secretaria')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });

    test()->actingAs($secretaria)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertStatus(403);
});

// CA-CORE-052
test('CA-CORE-052: un registro con changes redactado conserva el objeto redacted sin exponer ningún valor', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-052');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'con-documento@example.com',
        'person' => [
            'given_name' => 'Con', 'family_name_1' => 'Documento',
            'document_type' => 'DNI', 'document_number' => '00000000T',
        ],
        'send_invitation' => false,
    ])->assertCreated();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?event=created&auditable_type=person&limit=10'))
        ->assertOk();

    $personLog = collect($response->json('data'))->firstWhere('auditable_type', 'person');
    expect($personLog)->not->toBeNull()
        // Alta (created): no hay "antes", from_empty es true por diseño
        // (mismo criterio que AuditObserverTest de 0.9).
        ->and($personLog['changes']['document_number'])->toEqual(['redacted' => 'identifier', 'from_empty' => true, 'to_empty' => false]);

    $encoded = json_encode($personLog['changes']);
    expect($encoded)->not->toContain('00000000T');
});

// CA-CORE-053, CA-CORE-054
test('CA-CORE-053/054: solicitar una exportación la audita como exported, se genera en cola y se descarga por URL firmada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-053');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv'])
        ->assertStatus(202);

    $exportId = $response->json('public_id');
    expect($exportId)->not->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function () use ($exportId): void {
        $log = AuditLog::where('auditable_public_id', $exportId)->where('event', 'exported')->first();
        expect($log)->not->toBeNull();
    });

    $download = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$exportId}"))
        ->assertOk();

    expect($download->json('status'))->toBe('completada')
        ->and($download->json('download_url'))->not->toBeNull();
});
