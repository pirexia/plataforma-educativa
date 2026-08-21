<?php

use App\Models\Permission;
use Illuminate\Support\Facades\DB;

// permisos.md §2, §9: tras `platform:sync-registry`, `permissions`
// contiene exactamente los 20 códigos declarados por CoreServiceProvider,
// con module_code = 'core', ninguno marcado retired_at.

test('platform:sync-registry materializa exactamente los 20 permisos de permisos.md §2 para el módulo core', function (): void {
    $this->artisan('platform:sync-registry')->run();

    $codes = Permission::query()->where('module_code', 'core')->pluck('code')->sort()->values()->all();

    expect($codes)->toBe([
        'asignacion_rol.crear', 'asignacion_rol.eliminar', 'asignacion_rol.leer',
        'auditoria.exportar', 'auditoria.leer',
        'configuracion.actualizar', 'configuracion.leer',
        'invitacion.crear', 'invitacion.eliminar', 'invitacion.leer',
        'modulo.actualizar', 'modulo.leer',
        'permiso.leer',
        'rol.leer',
        'usuario.actualizar', 'usuario.crear', 'usuario.eliminar', 'usuario.exportar', 'usuario.importar', 'usuario.leer',
    ]);

    expect(Permission::query()->where('module_code', 'core')->whereNotNull('retired_at')->count())->toBe(0);

    // `auditoria` no tiene crear/actualizar/eliminar por diseño (append-only,
    // permisos.md §3).
    foreach (['auditoria.crear', 'auditoria.actualizar', 'auditoria.eliminar'] as $absent) {
        expect(in_array($absent, $codes, true))->toBeFalse();
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
