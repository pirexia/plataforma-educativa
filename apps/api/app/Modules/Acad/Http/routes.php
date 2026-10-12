<?php

// Rutas de REQ-ACAD (paso 1.11-b), documentadas en
// apps/api/openapi/paths/acad.yaml (docs/modulos/REQ-ACAD/api.md §2).
// Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale', ...]).
//
// Módulo NO esencial (OPEN-ACAD-10): toda ruta lleva la cadena
// autenticación -> module-enabled:acad -> permission:<código> (AR-07a,
// AR-07b, RMOD-009). `RequireAuthenticatedUser` va primero para que una
// petición anónima sea 401 aunque el módulo no esté contratado; no se usa el
// alias `auth` porque Laravel lo ordena antes de `resolve-tenant` (500 real). Los permisos son del recurso
// `estructura_academica`, ámbito `todos` (permisos.md §2).

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Acad\Http\Controllers\CoursesController;
use App\Modules\Acad\Http\Controllers\EducationLevelsController;
use App\Modules\Acad\Http\Controllers\GroupsController;
use App\Modules\Acad\Http\Controllers\GroupSubjectsController;
use App\Modules\Acad\Http\Controllers\SubgroupsController;
use App\Modules\Acad\Http\Controllers\SubjectsController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireAuthenticatedUser::class, 'module-enabled:acad'])->group(function (): void {
    Route::get('/education-levels', [EducationLevelsController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.education-levels.index');
    Route::post('/education-levels', [EducationLevelsController::class, 'store'])->middleware('permission:estructura_academica.crear')->name('acad.education-levels.store');
    Route::get('/education-levels/{publicId}', [EducationLevelsController::class, 'show'])->middleware('permission:estructura_academica.leer')->name('acad.education-levels.show');
    Route::patch('/education-levels/{publicId}', [EducationLevelsController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.education-levels.update');
    Route::delete('/education-levels/{publicId}', [EducationLevelsController::class, 'destroy'])->middleware('permission:estructura_academica.eliminar')->name('acad.education-levels.destroy');
    Route::get('/courses', [CoursesController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.courses.index');
    Route::post('/courses', [CoursesController::class, 'store'])->middleware('permission:estructura_academica.crear')->name('acad.courses.store');
    Route::get('/courses/{publicId}', [CoursesController::class, 'show'])->middleware('permission:estructura_academica.leer')->name('acad.courses.show');
    Route::patch('/courses/{publicId}', [CoursesController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.courses.update');
    Route::delete('/courses/{publicId}', [CoursesController::class, 'destroy'])->middleware('permission:estructura_academica.eliminar')->name('acad.courses.destroy');
    Route::get('/groups', [GroupsController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.groups.index');
    Route::post('/groups', [GroupsController::class, 'store'])->middleware('permission:estructura_academica.crear')->name('acad.groups.store');
    Route::get('/groups/{publicId}', [GroupsController::class, 'show'])->middleware('permission:estructura_academica.leer')->name('acad.groups.show');
    Route::patch('/groups/{publicId}', [GroupsController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.groups.update');
    Route::delete('/groups/{publicId}', [GroupsController::class, 'destroy'])->middleware('permission:estructura_academica.eliminar')->name('acad.groups.destroy');
    Route::get('/subjects', [SubjectsController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.subjects.index');
    Route::post('/subjects', [SubjectsController::class, 'store'])->middleware('permission:estructura_academica.crear')->name('acad.subjects.store');
    Route::get('/subjects/{publicId}', [SubjectsController::class, 'show'])->middleware('permission:estructura_academica.leer')->name('acad.subjects.show');
    Route::patch('/subjects/{publicId}', [SubjectsController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.subjects.update');
    Route::delete('/subjects/{publicId}', [SubjectsController::class, 'destroy'])->middleware('permission:estructura_academica.eliminar')->name('acad.subjects.destroy');
    Route::get('/groups/{groupPublicId}/subgroups', [SubgroupsController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.groups.subgroups.index');
    Route::post('/groups/{groupPublicId}/subgroups', [SubgroupsController::class, 'store'])->middleware('permission:estructura_academica.crear')->name('acad.groups.subgroups.store');
    Route::get('/subgroups/{publicId}', [SubgroupsController::class, 'show'])->middleware('permission:estructura_academica.leer')->name('acad.subgroups.show');
    Route::patch('/subgroups/{publicId}', [SubgroupsController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.subgroups.update');
    Route::delete('/subgroups/{publicId}', [SubgroupsController::class, 'destroy'])->middleware('permission:estructura_academica.eliminar')->name('acad.subgroups.destroy');
    Route::get('/groups/{groupPublicId}/subjects', [GroupSubjectsController::class, 'index'])->middleware('permission:estructura_academica.leer')->name('acad.groups.subjects.index');
    Route::put('/groups/{groupPublicId}/subjects', [GroupSubjectsController::class, 'update'])->middleware('permission:estructura_academica.actualizar')->name('acad.groups.subjects.update');
});
