<?php

// Rutas de REQ-CORE (paso 1.1), documentadas en
// apps/api/openapi/paths/core.yaml. Se registran aquí, no en
// routes/api-v1.php directamente, para que el módulo sea autocontenido
// (INV-007). Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
//
// Se va ampliando subpaso a subpaso conforme se implementan los
// controladores (ver docs/modulos/REQ-CORE/api.md).

use App\Modules\Core\Http\Controllers\AuditLogsController;
use App\Modules\Core\Http\Controllers\DataExportsController;
use App\Modules\Core\Http\Controllers\EffectivePermissionsController;
use App\Modules\Core\Http\Controllers\InvitationsController;
use App\Modules\Core\Http\Controllers\MeController;
use App\Modules\Core\Http\Controllers\ModulesController;
use App\Modules\Core\Http\Controllers\PermissionsController;
use App\Modules\Core\Http\Controllers\RolesController;
use App\Modules\Core\Http\Controllers\TenantController;
use App\Modules\Core\Http\Controllers\TenantSettingsAssetsController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Core\Http\Controllers\UserImportsController;
use App\Modules\Core\Http\Controllers\UserRolesController;
use App\Modules\Core\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// api.md §2. GET /tenant/branding es el único endpoint sin autenticación
// del módulo (funcional.md §4.8): sin middleware `permission`, resuelto
// solo por host.
Route::get('/tenant/branding', [TenantController::class, 'branding'])->name('core.tenant.branding');

Route::get('/tenant', [TenantController::class, 'show'])
    ->middleware('permission:configuracion.leer')
    ->name('core.tenant.show');

Route::get('/tenant/settings', [TenantSettingsController::class, 'show'])
    ->middleware('permission:configuracion.leer')
    ->name('core.tenant-settings.show');

Route::patch('/tenant/settings', [TenantSettingsController::class, 'update'])
    ->middleware('permission:configuracion.actualizar')
    ->name('core.tenant-settings.update');

// api.md §2.2/§2.3. {kind} ∈ {logo, favicon, login-background}; un valor
// fuera de ese conjunto se resuelve a 404 dentro del controlador
// (BrandingAssetKind::tryFrom), no aquí, para que el mensaje de error
// pase por ApiException como cualquier otro (ADR-038 §6).
Route::put('/tenant/settings/assets/{kind}', [TenantSettingsAssetsController::class, 'update'])
    ->middleware('permission:configuracion.actualizar')
    ->name('core.tenant-settings.assets.update');

Route::delete('/tenant/settings/assets/{kind}', [TenantSettingsAssetsController::class, 'destroy'])
    ->middleware('permission:configuracion.actualizar')
    ->name('core.tenant-settings.assets.destroy');

// api.md §3. /me: autoservicio por identidad, no por permiso
// (funcional.md §4.9, permisos.md §5) — sin middleware `permission`.
Route::get('/me', [MeController::class, 'show'])->name('core.me.show');
Route::patch('/me', [MeController::class, 'update'])->name('core.me.update');

Route::get('/users', [UsersController::class, 'index'])
    ->middleware('permission:usuario.leer')
    ->name('core.users.index');

Route::post('/users', [UsersController::class, 'store'])
    ->middleware('permission:usuario.crear')
    ->name('core.users.store');

Route::get('/users/{publicId}', [UsersController::class, 'show'])
    ->middleware('permission:usuario.leer')
    ->name('core.users.show');

Route::patch('/users/{publicId}', [UsersController::class, 'update'])
    ->middleware('permission:usuario.actualizar')
    ->name('core.users.update');

Route::delete('/users/{publicId}', [UsersController::class, 'destroy'])
    ->middleware('permission:usuario.eliminar')
    ->name('core.users.destroy');

Route::post('/users/{publicId}/restore', [UsersController::class, 'restore'])
    ->middleware('permission:usuario.eliminar')
    ->name('core.users.restore');

Route::post('/users/{publicId}/status', [UsersController::class, 'updateStatus'])
    ->middleware('permission:usuario.actualizar')
    ->name('core.users.status');

Route::get('/invitations', [InvitationsController::class, 'index'])
    ->middleware('permission:invitacion.leer')
    ->name('core.invitations.index');

Route::post('/users/{publicId}/invitations', [InvitationsController::class, 'store'])
    ->middleware('permission:invitacion.crear')
    ->name('core.invitations.store');

Route::delete('/invitations/{publicId}', [InvitationsController::class, 'destroy'])
    ->middleware('permission:invitacion.eliminar')
    ->name('core.invitations.destroy');

Route::get('/roles', [RolesController::class, 'index'])
    ->middleware('permission:rol.leer')
    ->name('core.roles.index');

// REQ-PERM/api.md §3 (1.5, RPERM-005/006): alta y clonación comparten
// ruta, verbo y permiso.
Route::post('/roles', [RolesController::class, 'store'])
    ->middleware('permission:rol.crear')
    ->name('core.roles.store');

Route::get('/roles/{publicId}', [RolesController::class, 'show'])
    ->middleware('permission:rol.leer')
    ->name('core.roles.show');

// REQ-AUTH/funcional.md §C.2.2, §C.16 (1.3): acotado a mfa_required
// (RN-AUTH-70). REQ-PERM/api.md §4 (1.5): mismo método, misma ruta, mismo
// permiso base — el editor completo se abre en PatchRole.
Route::patch('/roles/{publicId}', [RolesController::class, 'update'])
    ->middleware('permission:rol.actualizar')
    ->name('core.roles.update');

// REQ-PERM/api.md §6 (1.5, RN-PERM-16/17).
Route::delete('/roles/{publicId}', [RolesController::class, 'destroy'])
    ->middleware('permission:rol.eliminar')
    ->name('core.roles.destroy');

// REQ-PERM/api.md §5 (1.5): reemplazo completo de las concesiones de un
// rol (ADR-038 §9.1).
Route::put('/roles/{publicId}/permissions', [RolesController::class, 'replacePermissions'])
    ->middleware('permission:rol.actualizar')
    ->name('core.roles.permissions.replace');

Route::get('/permissions', [PermissionsController::class, 'index'])
    ->middleware('permission:permiso.leer')
    ->name('core.permissions.index');

Route::get('/users/{publicId}/roles', [UserRolesController::class, 'index'])
    ->middleware('permission:asignacion_rol.leer')
    ->name('core.user-roles.index');

Route::put('/users/{publicId}/roles', [UserRolesController::class, 'replace'])
    ->middleware('permission:asignacion_rol.crear')
    ->name('core.user-roles.replace');

// REQ-PERM/api.md §7 (1.5, RPERM-009). Dos rutas, un solo controlador y
// un solo cálculo (`ComputeEffectivePermissions`) — la diferencia está
// entera en cómo se autoriza cada una (funcional.md §7.11).
Route::get('/users/{publicId}/effective-permissions', [EffectivePermissionsController::class, 'show'])
    ->middleware('permission:permiso_efectivo.leer')
    ->name('core.users.effective-permissions');

// Sin middleware `permission:` — y nunca lo llevará (funcional.md §7.11,
// permisos.md §2.2): autoservicio autorizado por identidad del portador
// de la cookie, como GET /me.
Route::get('/me/effective-permissions', [EffectivePermissionsController::class, 'mine'])
    ->name('core.me.effective-permissions');

Route::get('/modules', [ModulesController::class, 'index'])
    ->middleware('permission:modulo.leer')
    ->name('core.modules.index');

Route::patch('/module-subscriptions/{publicId}', [ModulesController::class, 'updateSettings'])
    ->middleware('permission:modulo.actualizar')
    ->name('core.module-subscriptions.update');

// api.md §7. Esquema de columnas fijo, dos fases (funcional.md §4.4).
// `execute` es el primer y único consumidor de `Idempotency-Key` en 1.1
// (ADR-038 §8): `idempotent:user-imports.execute` es el identificador
// estable del endpoint que exige ADR-038 §8.3, no la ruta con parámetros.
Route::get('/user-imports', [UserImportsController::class, 'index'])
    ->middleware('permission:usuario.importar')
    ->name('core.user-imports.index');

Route::post('/user-imports', [UserImportsController::class, 'store'])
    ->middleware('permission:usuario.importar')
    ->name('core.user-imports.store');

Route::get('/user-imports/{publicId}', [UserImportsController::class, 'show'])
    ->middleware('permission:usuario.importar')
    ->name('core.user-imports.show');

Route::post('/user-imports/{publicId}/execute', [UserImportsController::class, 'execute'])
    ->middleware(['permission:usuario.importar', 'idempotent:user-imports.execute'])
    ->name('core.user-imports.execute');

Route::delete('/user-imports/{publicId}', [UserImportsController::class, 'destroy'])
    ->middleware('permission:usuario.importar')
    ->name('core.user-imports.destroy');

Route::get('/audit-logs', [AuditLogsController::class, 'index'])
    ->middleware('permission:auditoria.leer')
    ->name('core.audit-logs.index');

Route::post('/audit-logs/exports', [AuditLogsController::class, 'storeExport'])
    ->middleware('permission:auditoria.exportar')
    ->name('core.audit-logs.exports');

// api.md §8: "el permiso del recurso exportado" — en 1.1 el único `kind`
// es `audit_logs`, así que se fija auditoria.exportar aquí. Cuando otro
// módulo use ExportRequestService con un `kind` propio, este middleware
// tendrá que resolverse por `kind`, no antes: no hay un segundo caso
// todavía con el que acertar el diseño (funcional.md §7).
Route::get('/data-exports/{publicId}', [DataExportsController::class, 'show'])
    ->middleware('permission:auditoria.exportar')
    ->name('core.data-exports.show');
