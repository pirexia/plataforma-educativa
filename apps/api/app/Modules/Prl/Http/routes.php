<?php

// Rutas del módulo Prl (ADR-056 §3.5, generado por `php artisan make:module`).
// Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale', ...]).
//
// Módulo NO esencial: toda ruta lleva la cadena autenticación ->
// module-enabled:prl -> permission:<código> (AR-07a, AR-07b, RMOD-009).
// `RequireAuthenticatedUser` va primero y no se usa el alias `auth`: Laravel lo
// ordena antes de `resolve-tenant` (500 real).

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Prl\Http\Controllers\DoorController;
use App\Modules\Prl\Http\Controllers\PickupAlertsController;
use App\Modules\Prl\Http\Controllers\StudentMovementsController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireAuthenticatedUser::class, 'module-enabled:prl'])->group(function (): void {
    // REQ-PRL/api.md §8.4 (1.14b-b, `REQ-PRL-004`): la puerta. Cadena RequireAuthenticatedUser → module-enabled:prl →
    // permission:<código>; ningún permiso de `prl` admite ámbito distinto de `todos` (`permisos.md §8.2`).
    Route::get('/door/students', [DoorController::class, 'students'])->middleware('permission:entrega_menor.leer')->name('prl.door.students');
    Route::get('/door/students/{studentPersonPublicId}/pickup-list', [DoorController::class, 'pickupList'])->middleware('permission:entrega_menor.leer')->name('prl.door.pickup-list');
    // Movimientos de solo anexar (`RN-PRL-04`): sin PATCH ni DELETE.
    Route::post('/student-movements', [StudentMovementsController::class, 'store'])->middleware('permission:entrega_menor.crear')->name('prl.student-movements.store');
    Route::get('/student-movements', [StudentMovementsController::class, 'index'])->middleware('permission:entrega_menor.leer')->name('prl.student-movements.index');
    Route::get('/student-movements/{publicId}', [StudentMovementsController::class, 'show'])->middleware('permission:entrega_menor.leer')->name('prl.student-movements.show');
    // Alertas por persona no autorizada (`RN-PRL-08`): sin DELETE (evidencia).
    Route::post('/pickup-alerts', [PickupAlertsController::class, 'store'])->middleware('permission:alerta_entrega.crear')->name('prl.pickup-alerts.store');
    Route::get('/pickup-alerts', [PickupAlertsController::class, 'index'])->middleware('permission:alerta_entrega.leer')->name('prl.pickup-alerts.index');
    Route::get('/pickup-alerts/{publicId}', [PickupAlertsController::class, 'show'])->middleware('permission:alerta_entrega.leer')->name('prl.pickup-alerts.show');
    Route::patch('/pickup-alerts/{publicId}', [PickupAlertsController::class, 'update'])->middleware('permission:alerta_entrega.actualizar')->name('prl.pickup-alerts.update');
});
