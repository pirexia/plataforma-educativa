<?php

use App\Models\Permission;
use App\Support\Authorization\Scope;
use Illuminate\Support\Facades\DB;

// permisos.md §2, §9: tras `platform:sync-registry`, `permissions`
// contiene exactamente los 25 códigos declarados por CoreServiceProvider
// (20 de 1.1 + rol.actualizar de 1.3 + los cuatro de REQ-PERM/permisos.md
// §2: rol.crear, rol.eliminar, rol_datos_especiales.actualizar,
// permiso_efectivo.leer), con module_code = 'core', ninguno marcado
// retired_at.

test('platform:sync-registry materializa exactamente los 25 permisos de permisos.md §2 para el módulo core', function (): void {
    $this->artisan('platform:sync-registry')->run();

    $codes = Permission::query()->where('module_code', 'core')->pluck('code')->sort()->values()->all();

    expect($codes)->toBe([
        'asignacion_rol.crear', 'asignacion_rol.eliminar', 'asignacion_rol.leer',
        'auditoria.exportar', 'auditoria.leer',
        'configuracion.actualizar', 'configuracion.leer',
        'invitacion.crear', 'invitacion.eliminar', 'invitacion.leer',
        'modulo.actualizar', 'modulo.leer',
        'permiso.leer', 'permiso_efectivo.leer',
        'rol.actualizar', 'rol.crear', 'rol.eliminar', 'rol.leer',
        'rol_datos_especiales.actualizar',
        'usuario.actualizar', 'usuario.crear', 'usuario.eliminar', 'usuario.exportar', 'usuario.importar', 'usuario.leer',
    ]);

    expect(Permission::query()->where('module_code', 'core')->whereNotNull('retired_at')->count())->toBe(0);

    // `auditoria` no tiene crear/actualizar/eliminar por diseño (append-only,
    // permisos.md §3).
    foreach (['auditoria.crear', 'auditoria.actualizar', 'auditoria.eliminar'] as $absent) {
        expect(in_array($absent, $codes, true))->toBeFalse();
    }
});

// REQ-PERM/permisos.md §3.1, `CA-PERM-007`: el resolutor real de 1.5 —
// `auditoria.leer`/`.exportar` declaran `['todos','propios']`; el resto del
// catálogo de `core` no declara nada y admite exactamente `['todos']`
// (funcional.md §3.2 regla 1).
test('CA-PERM-007: applicable_scopes de auditoria es [todos,propios] y el resto del catálogo de core admite solo todos', function (): void {
    $this->artisan('platform:sync-registry')->run();

    foreach (['auditoria.leer', 'auditoria.exportar'] as $code) {
        $permission = Permission::query()->find($code);
        expect($permission->applicableScopes())->toEqualCanonicalizing([
            Scope::Todos, Scope::Propios,
        ]);
    }

    $others = Permission::query()->where('module_code', 'core')
        ->whereNotIn('code', ['auditoria.leer', 'auditoria.exportar'])
        ->get();

    foreach ($others as $permission) {
        expect($permission->applicable_scopes)->toBeNull()
            ->and($permission->applicableScopes())->toBe([Scope::Todos]);
    }
});

test('platform:sync-registry registra el módulo core en el catálogo de módulos', function (): void {
    $this->artisan('platform:sync-registry')->run();

    $module = DB::connection('pgsql_owner')->table('modules')->where('code', 'core')->first();

    expect($module)->not->toBeNull()
        ->and($module->name_key)->toBe('modules.core')
        ->and($module->phase)->toBe('1')
        ->and($module->retired_at)->toBeNull();
});
