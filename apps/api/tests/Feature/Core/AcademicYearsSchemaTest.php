<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §4 (0.8.2). Sin modelo AcademicYear todavía (llega en 0.8.9):
// inserciones directas por DB::table(), igual que TenantMigrationTest.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function insertAcademicYear(string $code, string $status, string $startsOn = '2026-09-01', string $endsOn = '2027-06-30'): int
{
    return DB::table('academic_years')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'code' => $code,
        'status' => $status,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
    ]);
}

test('no se pueden crear dos cursos activos en el mismo tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    insertAcademicYear('2026-2027', 'activo');

    expect(fn () => insertAcademicYear('2027-2028', 'activo'))
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php): el índice único
    // parcial deja la transacción abortada hasta que DatabaseTransactions
    // la revierte al terminar el test.
});

test('sí se puede tener un curso activo y uno en planificación a la vez', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    insertAcademicYear('2026-2027', 'activo');
    insertAcademicYear('2027-2028', 'planificacion');

    $count = DB::table('academic_years')->where('tenant_id', $tenant->id)->count();

    $context->leave();

    expect($count)->toBe(2);
});

test('dos tenants distintos pueden tener cada uno su propio curso activo', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Por pgsql_platform directamente para los dos: una escritura por
    // `pgsql` (contexto A) e inmediatamente una lectura por
    // `pgsql_platform` no vería la fila todavía sin comprometer — son dos
    // conexiones físicas distintas, la misma razón por la que
    // TenantModelTest inserta sus filas "cruzadas" por pgsql_platform.
    DB::connection('pgsql_platform')->table('academic_years')->insert([
        [
            'tenant_id' => $tenantA->id, 'public_id' => (string) Str::ulid(), 'code' => '2026-2027',
            'status' => 'activo', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'tenant_id' => $tenantB->id, 'public_id' => (string) Str::ulid(), 'code' => '2026-2027',
            'status' => 'activo', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $total = DB::connection('pgsql_platform')->table('academic_years')
        ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])
        ->count();

    expect($total)->toBe(2);

    DB::connection('pgsql_platform')->table('academic_years')
        ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])->delete();
});

test('ends_on debe ser posterior a starts_on', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expect(fn () => insertAcademicYear('2026-2027', 'planificacion', '2027-06-30', '2026-09-01'))
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php): el CHECK deja la
    // transacción abortada hasta que DatabaseTransactions la revierte al
    // terminar el test.
});

test('el mismo código puede reutilizarse tras borrado lógico', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $id = insertAcademicYear('2020-2021', 'archivado');
    DB::table('academic_years')->where('id', $id)->update(['deleted_at' => now()]);

    insertAcademicYear('2020-2021', 'archivado');

    $count = DB::table('academic_years')->where('tenant_id', $tenant->id)->where('code', '2020-2021')->count();

    $context->leave();

    expect($count)->toBe(2);
});
