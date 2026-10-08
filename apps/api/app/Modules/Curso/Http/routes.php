<?php

// Rutas de REQ-CURSO (paso 1.10), documentadas en
// apps/api/openapi/paths/curso.yaml. Requerido desde routes/api-v1.php, ya
// dentro del grupo prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
// Módulo ESENCIAL (OPEN-CURSO-01): sus rutas no llevan `module-enabled:`
// (AR-07b solo lo exige a los no esenciales).

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
