<?php

// Rutas de REQ-FAM-UNIT (paso 1.14-a), documentadas en apps/api/openapi/paths/fam-unit.yaml
// (docs/modulos/REQ-FAM-UNIT/api.md §2.1 a §2.5).
// Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale', ...]).
//
// Módulo NO esencial (OPEN-FAM-UNIT-14): toda ruta lleva la cadena
// autenticación -> module-enabled:fam_unit -> permission:<código> (AR-07a,
// AR-07b, RMOD-009). `RequireAuthenticatedUser` va primero para que una
// petición anónima sea 401 aunque el módulo no esté contratado; no se usa el
// alias `auth` porque Laravel lo ordena antes de `resolve-tenant` (500 real).
// Recursos de permiso: `familia` (unidades, tutores, alumnado), `vinculo_tutor` y `revocacion_tutor`
// (permisos.md §2).

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\FamUnit\Http\Controllers\FamilyUnitsController;
use App\Modules\FamUnit\Http\Controllers\FamilyUnitStudentsController;
use App\Modules\FamUnit\Http\Controllers\GuardianLinkRevocationController;
use App\Modules\FamUnit\Http\Controllers\GuardianLinksController;
use App\Modules\FamUnit\Http\Controllers\GuardiansController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireAuthenticatedUser::class, 'module-enabled:fam_unit'])->group(function (): void {
    // api.md §2.1: unidades familiares.
    Route::get('/family-units', [FamilyUnitsController::class, 'index'])->middleware('permission:familia.leer')->name('fam-unit.family-units.index');
    Route::post('/family-units', [FamilyUnitsController::class, 'store'])->middleware('permission:familia.crear')->name('fam-unit.family-units.store');
    Route::get('/family-units/{publicId}', [FamilyUnitsController::class, 'show'])->middleware('permission:familia.leer')->name('fam-unit.family-units.show');
    Route::patch('/family-units/{publicId}', [FamilyUnitsController::class, 'update'])->middleware('permission:familia.actualizar')->name('fam-unit.family-units.update');
    Route::delete('/family-units/{publicId}', [FamilyUnitsController::class, 'destroy'])->middleware('permission:familia.eliminar')->name('fam-unit.family-units.destroy');
    Route::post('/family-units/{publicId}/restore', [FamilyUnitsController::class, 'restore'])->middleware('permission:familia.eliminar')->name('fam-unit.family-units.restore');
    // api.md §2.3: alumnado de la unidad (configurar la unidad exige `actualizar`).
    Route::get('/family-units/{publicId}/students', [FamilyUnitStudentsController::class, 'index'])->middleware('permission:familia.leer')->name('fam-unit.family-units.students.index');
    Route::post('/family-units/{publicId}/students', [FamilyUnitStudentsController::class, 'store'])->middleware('permission:familia.actualizar')->name('fam-unit.family-units.students.store');
    Route::delete('/family-units/{publicId}/students/{membership}', [FamilyUnitStudentsController::class, 'destroy'])->middleware('permission:familia.actualizar')->name('fam-unit.family-units.students.destroy');
    // api.md §2.2: tutores.
    Route::get('/guardians', [GuardiansController::class, 'index'])->middleware('permission:familia.leer')->name('fam-unit.guardians.index');
    Route::post('/guardians', [GuardiansController::class, 'store'])->middleware('permission:familia.crear')->name('fam-unit.guardians.store');
    Route::get('/guardians/{publicId}', [GuardiansController::class, 'show'])->middleware('permission:familia.leer')->name('fam-unit.guardians.show');
    Route::patch('/guardians/{publicId}', [GuardiansController::class, 'update'])->middleware('permission:familia.actualizar')->name('fam-unit.guardians.update');
    Route::delete('/guardians/{publicId}', [GuardiansController::class, 'destroy'])->middleware('permission:familia.eliminar')->name('fam-unit.guardians.destroy');
    // api.md §2.4: vínculos tutor ↔ alumno.
    Route::get('/guardian-links', [GuardianLinksController::class, 'index'])->middleware('permission:vinculo_tutor.leer')->name('fam-unit.guardian-links.index');
    Route::post('/guardian-links', [GuardianLinksController::class, 'store'])->middleware('permission:vinculo_tutor.crear')->name('fam-unit.guardian-links.store');
    Route::get('/guardian-links/{publicId}', [GuardianLinksController::class, 'show'])->middleware('permission:vinculo_tutor.leer')->name('fam-unit.guardian-links.show');
    Route::patch('/guardian-links/{publicId}', [GuardianLinksController::class, 'update'])->middleware('permission:vinculo_tutor.actualizar')->name('fam-unit.guardian-links.update');
    Route::delete('/guardian-links/{publicId}', [GuardianLinksController::class, 'destroy'])->middleware('permission:vinculo_tutor.eliminar')->name('fam-unit.guardian-links.destroy');
    // api.md §2.5: revocación por custodia (revocar = crear, levantar = eliminar).
    Route::post('/guardian-links/{publicId}/revocation', [GuardianLinkRevocationController::class, 'store'])->middleware('permission:revocacion_tutor.crear')->name('fam-unit.guardian-links.revocation.store');
    Route::delete('/guardian-links/{publicId}/revocation', [GuardianLinkRevocationController::class, 'destroy'])->middleware('permission:revocacion_tutor.eliminar')->name('fam-unit.guardian-links.revocation.destroy');
});
