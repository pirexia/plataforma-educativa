<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §1 (0.8.3). Sin modelo Person todavía (llega en 0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function insertPerson(string $givenName, ?string $documentType = 'dni', ?string $documentNumber = '00000000X'): int
{
    return DB::table('people')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'given_name' => $givenName,
        'family_name_1' => 'Apellido',
        'document_type' => $documentType,
        'document_number' => $documentNumber,
    ]);
}

test('dos personas con el mismo documento en tenants distintos conviven', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    DB::connection('pgsql_platform')->table('people')->insert([
        [
            'tenant_id' => $tenantA->id, 'public_id' => (string) Str::ulid(), 'given_name' => 'Ana',
            'family_name_1' => 'García', 'document_type' => 'dni', 'document_number' => '00000000X',
            'locale' => 'es', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'tenant_id' => $tenantB->id, 'public_id' => (string) Str::ulid(), 'given_name' => 'Ana',
            'family_name_1' => 'García', 'document_type' => 'dni', 'document_number' => '00000000X',
            'locale' => 'es', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $total = DB::connection('pgsql_platform')->table('people')
        ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])
        ->where('document_number', '00000000X')
        ->count();

    expect($total)->toBe(2);

    DB::connection('pgsql_platform')->table('people')
        ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])->delete();
});

test('en el mismo tenant, el mismo documento no puede repetirse', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    insertPerson('Ana');

    expect(fn () => insertPerson('Otra Ana'))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php): el índice único
    // parcial deja la transacción abortada.
});

test('dos personas sin documento informado conviven en el mismo tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    insertPerson('Alumno menor 1', null, null);
    insertPerson('Alumno menor 2', null, null);

    $count = DB::table('people')->where('tenant_id', $tenant->id)->count();
    $context->leave();

    expect($count)->toBe(2);
});

test('tras borrado lógico, el mismo documento puede volver a darse de alta', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $id = insertPerson('Ana');
    DB::table('people')->where('id', $id)->update(['deleted_at' => now()]);

    insertPerson('Ana (readmitida)');

    $count = DB::table('people')->where('tenant_id', $tenant->id)->where('document_number', '00000000X')->count();
    $context->leave();

    expect($count)->toBe(2);
});
