<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §2, §7 (0.8.6). Sin modelo Permission/Role todavía (0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('plataforma_app no puede escribir en permissions', function (): void {
    expect(fn () => DB::connection('pgsql')->table('permissions')->insert([
        'code' => 'alumnado.ver', 'resource' => 'alumnado', 'action' => 'ver', 'module_code' => 'REQ-ALUM',
    ]))->toThrow(QueryException::class);
});

test('plataforma_owner sí puede materializar el catálogo de permissions', function (): void {
    DB::connection('pgsql_owner')->table('permissions')->insert([
        'code' => 'alumnado.ver-test', 'resource' => 'alumnado', 'action' => 'ver', 'module_code' => 'REQ-ALUM',
    ]);

    $found = DB::connection('pgsql')->table('permissions')->where('code', 'alumnado.ver-test')->exists();

    expect($found)->toBeTrue();

    DB::connection('pgsql_owner')->table('permissions')->where('code', 'alumnado.ver-test')->delete();
});

test('permission_role con un permission_code inexistente es rechazada por la FK', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $roleId = DB::table('roles')->insertGetId([
        'public_id' => (string) Str::ulid(), 'code' => 'docente', 'name_key' => 'roles.docente',
    ]);

    expect(fn () => DB::table('permission_role')->insert([
        'role_id' => $roleId, 'permission_code' => 'no-existe.accion', 'effect' => 'allow',
    ]))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).
});

test('effect solo admite allow o deny', function (): void {
    DB::connection('pgsql_owner')->table('permissions')->insert([
        'code' => 'alumnado.editar-test', 'resource' => 'alumnado', 'action' => 'editar', 'module_code' => 'REQ-ALUM',
    ]);

    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $roleId = DB::table('roles')->insertGetId([
        'public_id' => (string) Str::ulid(), 'code' => 'docente', 'name_key' => 'roles.docente',
    ]);

    expect(fn () => DB::table('permission_role')->insert([
        'role_id' => $roleId, 'permission_code' => 'alumnado.editar-test', 'effect' => 'quiza',
    ]))->toThrow(QueryException::class);
    // Sin leave() aquí a propósito (ver TenantTest.php).

    DB::connection('pgsql_owner')->table('permissions')->where('code', 'alumnado.editar-test')->delete();
});
