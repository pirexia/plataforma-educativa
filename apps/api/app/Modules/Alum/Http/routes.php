<?php

// Rutas del módulo Alum (ADR-056 §3.5, generado por `php artisan make:module`).
// Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale', ...]).
//
// Módulo NO esencial: toda ruta lleva la cadena autenticación ->
// module-enabled:alum -> permission:<código> (AR-07a, AR-07b, RMOD-009).
// `RequireAuthenticatedUser` va primero y no se usa el alias `auth`: Laravel lo
// ordena antes de `resolve-tenant` (500 real).

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Alum\Http\Controllers\EnrollmentsController;
use App\Modules\Alum\Http\Controllers\GroupChangeRequestsController;
use App\Modules\Alum\Http\Controllers\StudentsController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireAuthenticatedUser::class, 'module-enabled:alum'])->group(function (): void {
    // api.md §1 (1.15-a): alumnado. `POST /students` lleva `Idempotency-Key` obligatoria (`RN-ALUM-27`, ADR-038 §8): va
    // tras `permission:`. Sin matrículas (1.15-b) ni foto (1.15-c).
    Route::get('/students', [StudentsController::class, 'index'])->middleware('permission:alumno.leer')->name('alum.students.index');
    Route::post('/students', [StudentsController::class, 'store'])->middleware(['permission:alumno.crear', 'idempotent:alum.students.store'])->name('alum.students.store');
    Route::get('/students/{publicId}', [StudentsController::class, 'show'])->middleware('permission:alumno.leer')->name('alum.students.show');
    Route::patch('/students/{publicId}', [StudentsController::class, 'update'])->middleware('permission:alumno.actualizar')->name('alum.students.update');
    Route::delete('/students/{publicId}', [StudentsController::class, 'destroy'])->middleware('permission:alumno.eliminar')->name('alum.students.destroy');
    Route::post('/students/{publicId}/restore', [StudentsController::class, 'restore'])->middleware('permission:alumno.eliminar')->name('alum.students.restore');

    // api.md §2 (1.15-b): matrícula. Sin `Idempotency-Key` (`RN-ALUM-27`): el índice único de `RN-ALUM-21` y la transición
    // de estado hacen seguro el reintento.
    // La exportación se declara antes que las rutas con `{publicId}` (`api.md §6`).
    Route::post('/enrollments/exports', [EnrollmentsController::class, 'storeExport'])->middleware('permission:matricula.exportar')->name('alum.enrollments.exports.store');
    Route::get('/enrollments', [EnrollmentsController::class, 'index'])->middleware('permission:matricula.leer')->name('alum.enrollments.index');
    Route::post('/enrollments', [EnrollmentsController::class, 'store'])->middleware('permission:matricula.crear')->name('alum.enrollments.store');
    Route::get('/enrollments/{publicId}', [EnrollmentsController::class, 'show'])->middleware('permission:matricula.leer')->name('alum.enrollments.show');
    Route::patch('/enrollments/{publicId}', [EnrollmentsController::class, 'update'])->middleware('permission:matricula.actualizar')->name('alum.enrollments.update');
    Route::post('/enrollments/{publicId}/withdrawal', [EnrollmentsController::class, 'withdraw'])->middleware('permission:matricula.actualizar')->name('alum.enrollments.withdraw');
    Route::delete('/enrollments/{publicId}', [EnrollmentsController::class, 'destroy'])->middleware('permission:matricula.eliminar')->name('alum.enrollments.destroy');

    // api.md §3 (1.15-c): solicitudes de cambio de grupo. Sin `Idempotency-Key`: el único de solicitud pendiente
    // (`RN-ALUM-41`) y las transiciones de estado hacen seguro el reintento.
    Route::get('/group-change-requests', [GroupChangeRequestsController::class, 'index'])->middleware('permission:matricula.leer')->name('alum.group-change-requests.index');
    Route::post('/group-change-requests', [GroupChangeRequestsController::class, 'store'])->middleware('permission:matricula.actualizar')->name('alum.group-change-requests.store');
    Route::get('/group-change-requests/{publicId}', [GroupChangeRequestsController::class, 'show'])->middleware('permission:matricula.leer')->name('alum.group-change-requests.show');
    Route::post('/group-change-requests/{publicId}/approval', [GroupChangeRequestsController::class, 'approve'])->middleware('permission:matricula.aprobar')->name('alum.group-change-requests.approve');
    Route::post('/group-change-requests/{publicId}/rejection', [GroupChangeRequestsController::class, 'reject'])->middleware('permission:matricula.aprobar')->name('alum.group-change-requests.reject');
    Route::post('/group-change-requests/{publicId}/cancellation', [GroupChangeRequestsController::class, 'cancel'])->middleware('permission:matricula.actualizar')->name('alum.group-change-requests.cancel');
});
