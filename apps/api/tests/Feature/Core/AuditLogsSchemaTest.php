<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §3 (0.8.8). Sin modelo AuditLog ni AppendOnlyModel todavía (0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function insertAuditLog(): int
{
    return DB::table('audit_logs')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'occurred_at' => now(),
        'actor_type' => 'system',
        'auditable_type' => 'academic_year',
        'auditable_id' => 1,
        'event' => 'created',
    ]);
}

test('INSERT funciona en audit_logs', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $id = insertAuditLog();
    $count = DB::table('audit_logs')->where('id', $id)->count();

    $context->leave();

    expect($count)->toBe(1);
});

test('UPDATE es rechazado por el motor, no por convención de aplicación', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $id = insertAuditLog();

    expect(fn () => DB::table('audit_logs')->where('id', $id)->update(['event' => 'updated']))
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});

test('DELETE es rechazado por el motor, no por convención de aplicación', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $id = insertAuditLog();

    expect(fn () => DB::table('audit_logs')->where('id', $id)->delete())
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});

// Hallazgo propio de revisión de seguridad: plataforma_platform (BYPASSRLS,
// ADR-033 §5) es la conexión con más probabilidad de tocar estas filas por
// error en un futuro script de mantenimiento entre tenants — el REVOKE
// tiene que cubrirla también, no solo plataforma_app.
test('plataforma_platform tampoco puede UPDATE ni DELETE en audit_logs', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);
    $id = insertAuditLog();
    $context->leave();

    expect(fn () => DB::connection('pgsql_platform')->table('audit_logs')->where('id', $id)->update(['event' => 'updated']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::connection('pgsql_platform')->table('audit_logs')->where('id', $id)->delete())
        ->toThrow(QueryException::class);
});

test('actor_type solo admite los valores del CHECK', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expect(fn () => DB::table('audit_logs')->insert([
        'public_id' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_type' => 'alien',
        'auditable_type' => 'academic_year', 'auditable_id' => 1, 'event' => 'created',
    ]))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});

test('actor_user_id de otro tenant es rechazado por la FK compuesta', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantB->id);
    $personInB = DB::table('people')->insertGetId([
        'public_id' => (string) Str::ulid(), 'given_name' => 'Ana', 'family_name_1' => 'García',
    ]);
    $userInB = DB::table('users')->insertGetId([
        'public_id' => (string) Str::ulid(), 'person_id' => $personInB,
        'email' => 'ana@example.com', 'password' => 'hash-de-prueba',
    ]);
    $context->leave();

    $context->enter($tenantA->id);
    expect(fn () => DB::table('audit_logs')->insert([
        'public_id' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_type' => 'user',
        'actor_user_id' => $userInB, 'auditable_type' => 'academic_year', 'auditable_id' => 1,
        'event' => 'created',
    ]))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});
