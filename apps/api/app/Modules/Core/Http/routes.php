<?php

// Rutas de REQ-CORE (paso 1.1), documentadas en
// apps/api/openapi/paths/core.yaml. Se registran aquí, no en
// routes/api-v1.php directamente, para que el módulo sea autocontenido
// (INV-007). Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
//
// Se va ampliando subpaso a subpaso conforme se implementan los
// controladores (ver docs/modulos/REQ-CORE/api.md).

use App\Modules\Core\Http\Controllers\InvitationsController;
use App\Modules\Core\Http\Controllers\MeController;
use App\Modules\Core\Http\Controllers\TenantController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
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
