<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §2 (0.8.5). Sin modelo Role/User todavía (llegan en 0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function insertRole(string $code, ?string $nameKey = 'roles.docente', ?string $name = null): int
{
    return DB::table('roles')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'code' => $code,
        'name_key' => $nameKey,
        'name' => $name,
    ]);
}

function insertUserForRoleTest(): int
{
    $personId = DB::table('people')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'given_name' => 'Ana',
        'family_name_1' => 'García',
    ]);

    return DB::table('users')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'person_id' => $personId,
        'email' => 'ana-'.Str::random(8).'@example.com',
        'password' => 'hash-de-prueba',
    ]);
}

test('el CHECK exige name_key o name, nunca los dos ni ninguno', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expect(fn () => insertRole('ambos', 'roles.docente', 'Docente'))->toThrow(QueryException::class);
});

test('role_user con un rol de otro tenant es rechazada por la FK compuesta', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantB->id);
    $roleInB = insertRole('docente');
    $context->leave();

    $context->enter($tenantA->id);
    $userInA = insertUserForRoleTest();

    expect(fn () => DB::table('role_user')->insert(['user_id' => $userInA, 'role_id' => $roleInB]))
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});

test('el mismo código de rol puede repetirse en tenants distintos', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantA->id);
    insertRole('docente');
    $countInA = DB::table('roles')->where('code', 'docente')->count();
    $context->leave();

    $context->enter($tenantB->id);
    insertRole('docente');
    $countInB = DB::table('roles')->where('code', 'docente')->count();
    $context->leave();

    expect($countInA)->toBe(1)->and($countInB)->toBe(1);
});
