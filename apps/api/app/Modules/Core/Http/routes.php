<?php

// Rutas de REQ-CORE (paso 1.1), documentadas en
// apps/api/openapi/paths/core.yaml. Se registran aquí, no en
// routes/api-v1.php directamente, para que el módulo sea autocontenido
// (INV-007). Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
//
// Se va ampliando subpaso a subpaso conforme se implementan los
// controladores (ver docs/modulos/REQ-CORE/api.md).

use App\Modules\Core\Http\Controllers\TenantController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
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
