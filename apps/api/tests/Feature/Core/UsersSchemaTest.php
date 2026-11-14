<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §1, §8 (0.8.4). Sin modelo User/Person todavía (llegan en 0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function insertPersonRaw(): int
{
    return DB::table('people')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'given_name' => 'Ana',
        'family_name_1' => 'García',
    ]);
}

function insertUserRaw(int $personId, string $email): int
{
    return DB::table('users')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'person_id' => $personId,
        'email' => $email,
        'password' => 'hash-de-prueba',
    ]);
}

test('una cuenta pertenece a una persona con FK compuesta, nunca a otro tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantB->id);
    $personInB = insertPersonRaw();
    $context->leave();

    $context->enter($tenantA->id);
    expect(fn () => insertUserRaw($personInB, 'ana@example.com'))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php): la violación de FK
    // deja abortada la transacción hasta que termine el test.
});

test('como mucho una cuenta viva por persona y tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $personId = insertPersonRaw();
    insertUserRaw($personId, 'ana@example.com');

    expect(fn () => insertUserRaw($personId, 'ana.otra@example.com'))->toThrow(QueryException::class);
});

test('el email es único por tenant, no globalmente', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $context = app(TenantContext::class);

    // Los dos inserts deben tener éxito sin lanzar QueryException: si el
    // índice fuera único a nivel global (no parcial por tenant), el
    // segundo insert violaría la restricción. La lectura ocurre por la
    // misma conexión con la que se escribió, dentro del mismo contexto de
    // tenant, para no toparse con la visibilidad entre conexiones.
    $context->enter($tenantA->id);
    insertUserRaw(insertPersonRaw(), 'compartido@example.com');
    $countInA = DB::table('users')->where('email', 'compartido@example.com')->count();
    $context->leave();

    $context->enter($tenantB->id);
    insertUserRaw(insertPersonRaw(), 'compartido@example.com');
    $countInB = DB::table('users')->where('email', 'compartido@example.com')->count();
    $context->leave();

    expect($countInA)->toBe(1)->and($countInB)->toBe(1);
});

// ADR-034 §8: hallazgo de seguridad — antes de 0.8.4, password_reset_tokens
// tenía email como PK global; un token del tenant A servía en el B para la
// cuenta homónima. La PK compuesta (tenant_id, email) lo impide en el motor.
test('un token de recuperación del tenant A no colisiona con el del tenant B para el mismo email', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantA->id);
    DB::table('password_reset_tokens')->insert([
        'email' => 'ana@example.com', 'token' => 'token-de-A', 'created_at' => now(),
    ]);
    $context->leave();

    $context->enter($tenantB->id);
    DB::table('password_reset_tokens')->insert([
        'email' => 'ana@example.com', 'token' => 'token-de-B', 'created_at' => now(),
    ]);

    // Con el tenant B activo, el token visible para ese email es el propio:
    // el de A no se ve (RLS) y no pudo sobrescribirlo (PK compuesta) — si
    // la PK siguiera siendo solo `email`, este segundo insert habría
    // fallado por violación de clave primaria en vez de convivir.
    $tokenEnB = DB::table('password_reset_tokens')->where('email', 'ana@example.com')->value('token');
    $context->leave();

    expect($tokenEnB)->toBe('token-de-B');
});
