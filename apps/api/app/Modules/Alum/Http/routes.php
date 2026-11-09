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
});
