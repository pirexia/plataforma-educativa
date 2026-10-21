<?php

// Rutas de REQ-CURSO (paso 1.10), documentadas en
// apps/api/openapi/paths/curso.yaml. Requerido desde routes/api-v1.php, ya
// dentro del grupo prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
// Módulo ESENCIAL (OPEN-CURSO-01): sus rutas no llevan `module-enabled:`
// (AR-07b solo lo exige a los no esenciales).

use App\Modules\Curso\Http\Controllers\AcademicYearRolloversController;
use App\Modules\Curso\Http\Controllers\AcademicYearsController;
use Illuminate\Support\Facades\Route;

Route::get('/academic-years', [AcademicYearsController::class, 'index'])
    ->middleware('permission:curso_academico.leer')
    ->name('curso.academic-years.index');

// api.md §2: `current` se registra ANTES que `/{publicId}` para que no se
// interprete como un public_id. Autoservicio por identidad, sin permiso
// (OPEN-CURSO-15, permisos.md §2.2): amplía con esta única ruta la lista
// cerrada de excepciones de AR-07a.
Route::get('/academic-years/current', [AcademicYearsController::class, 'current'])
    ->name('curso.academic-years.current');

// REQ-ACAD-001 (1.11-a), OPEN-ACAD-11, issue #409, CA-ACAD-043: opciones del
// selector de curso académico. Autoservicio por identidad, sin permiso: amplía
// con esta única ruta la lista cerrada de excepciones de AR-07a (aprobado).
// Se registra ANTES que `/{publicId}`.
Route::get('/academic-years/selectable', [AcademicYearsController::class, 'selectable'])
    ->name('curso.academic-years.selectable');

Route::get('/academic-years/{publicId}', [AcademicYearsController::class, 'show'])
    ->middleware('permission:curso_academico.leer')
    ->name('curso.academic-years.show');

Route::post('/academic-years', [AcademicYearsController::class, 'store'])
    ->middleware('permission:curso_academico.crear')
    ->name('curso.academic-years.store');

Route::patch('/academic-years/{publicId}', [AcademicYearsController::class, 'update'])
    ->middleware('permission:curso_academico.actualizar')
    ->name('curso.academic-years.update');

Route::post('/academic-years/{publicId}/status', [AcademicYearsController::class, 'updateStatus'])
    ->middleware('permission:estado_curso_academico.actualizar')
    ->name('curso.academic-years.status');

// REQ-CURSO 1.11c, ADR-059, RN-CURSO-40: reapertura de un curso cerrado. Permiso
// propio (`estado_curso_academico.actualizar` no basta) y endpoint propio:
// autorizar según el cuerpo de `…/status` no lo ve AR-07.
Route::post('/academic-years/{publicId}/reopen', [AcademicYearsController::class, 'reopen'])
    ->middleware('permission:reapertura_curso_academico.actualizar')
    ->name('curso.academic-years.reopen');

// REQ-CURSO 1.12b (`REQ-CURSO-002`), api.md §2.1, RN-CURSO-78: apertura del curso siguiente. `elements` y
// `preview` se registran ANTES que `/{publicId}` para que no se interpreten como un public_id. Permiso propio
// (`apertura_curso_academico`); la autorización por elemento la aplica el servicio (RN-CURSO-78).
Route::get('/academic-year-rollovers/elements', [AcademicYearRolloversController::class, 'elements'])
    ->middleware('permission:apertura_curso_academico.crear')
    ->name('curso.academic-year-rollovers.elements');

Route::get('/academic-year-rollovers/preview', [AcademicYearRolloversController::class, 'preview'])
    ->middleware('permission:apertura_curso_academico.crear')
    ->name('curso.academic-year-rollovers.preview');

Route::post('/academic-year-rollovers', [AcademicYearRolloversController::class, 'store'])
    ->middleware('permission:apertura_curso_academico.crear')
    ->name('curso.academic-year-rollovers.store');

Route::get('/academic-year-rollovers', [AcademicYearRolloversController::class, 'index'])
    ->middleware('permission:apertura_curso_academico.leer')
    ->name('curso.academic-year-rollovers.index');

Route::get('/academic-year-rollovers/{publicId}', [AcademicYearRolloversController::class, 'show'])
    ->middleware('permission:apertura_curso_academico.leer')
    ->name('curso.academic-year-rollovers.show');

Route::post('/academic-year-rollovers/{publicId}/revert', [AcademicYearRolloversController::class, 'revert'])
    ->middleware('permission:apertura_curso_academico.eliminar')
    ->name('curso.academic-year-rollovers.revert');

Route::post('/academic-year-rollovers/{publicId}/cancel', [AcademicYearRolloversController::class, 'cancel'])
    ->middleware('permission:apertura_curso_academico.eliminar')
    ->name('curso.academic-year-rollovers.cancel');
