<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// ADR-033 §4 y §7: tenants es la raíz del aislamiento. INV-001.
//
// Estos tests escriben por la conexión pgsql_platform, que NO está en
// $connectionsToTransact (ver TestCase): la fila tiene que quedar
// realmente comprometida para que la conexión pgsql (una sesión distinta)
// pueda verla dentro del mismo test — PostgreSQL no expone escrituras sin
// commit entre sesiones. Limpieza manual al terminar cada test.
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('Tenant::findBySlug() resuelve por la conexión de plataforma sin tenant activo', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'colegio-ficticio-uno']);

    expect(app(TenantContext::class)->hasTenant())->toBeFalse();

    $found = Tenant::findBySlug('colegio-ficticio-uno');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($tenant->id)
        ->and($found->status)->toBe(TenantStatus::Activo);
});

test('Tenant::findBySlug() devuelve null si no existe', function (): void {
    expect(Tenant::findBySlug('no-existe-este-slug'))->toBeNull();
});

test('sin tenant activo, la tabla tenants da cero filas por la conexión de la API (RLS)', function (): void {
    Tenant::factory()->count(3)->create();

    $rows = DB::connection('pgsql')->select('select * from tenants');

    expect($rows)->toBeEmpty();
});

test('con un tenant activo, la conexión de la API solo ve su propia fila', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $context = app(TenantContext::class);
    $context->enter($tenantA->id);

    $rows = DB::connection('pgsql')->select('select id from tenants');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($tenantA->id)
        ->and($rows[0]->id)->not->toBe($tenantB->id);

    $context->leave();
});

test('la conexión de la API no puede insertar una fila con un id de tenant distinto del activo', function (): void {
    $context = app(TenantContext::class);
    $context->enter(999999);

    expect(fn () => DB::connection('pgsql')->statement(
        'insert into tenants (id, public_id, slug, name, status, created_at, updated_at) '.
        "values (888888, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'otro-slug', 'Otro', 'activo', now(), now())"
    ))->toThrow(QueryException::class);

    // No se restaura el contexto tras la excepción a propósito: la
    // violación de RLS deja abortada la transacción de este test hasta que
    // termine (comportamiento normal de PostgreSQL), y DatabaseTransactions
    // la revierte entera al finalizar. Cada test arranca con un
    // TenantContext nuevo (aplicación recreada por test), así que no hay
    // fuga de estado hacia el siguiente test.
});

test('public_id se genera solo si no viene ya fijado', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->public_id)->not->toBeEmpty()
        ->and(strlen($tenant->public_id))->toBe(26);
});
