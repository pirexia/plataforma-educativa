<?php

// Rutas de REQ-FAM-UNIT (pasos 1.14-a, 1.14-b y 1.14-c), documentadas en apps/api/openapi/paths/fam-unit.yaml
// (docs/modulos/REQ-FAM-UNIT/api.md §2.1 a §2.5, §2.7 y §2.8). Todas pasan antes por el registro de muros de sesión
// (`require-session-walls`, ADR-067) del grupo `v1`.
// Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale', ...]).
//
// Módulo NO esencial (OPEN-FAM-UNIT-14): toda ruta lleva la cadena
// autenticación -> module-enabled:fam_unit -> permission:<código> (AR-07a,
// AR-07b, RMOD-009). `RequireAuthenticatedUser` va primero para que una
// petición anónima sea 401 aunque el módulo no esté contratado; no se usa el
// alias `auth` porque Laravel lo ordena antes de `resolve-tenant` (500 real).
// Recursos de permiso: `familia` (unidades, tutores, alumnado), `vinculo_tutor`, `revocacion_tutor`,
// `consentimiento_imagen`, `texto_legal` y `aceptacion_condiciones` (permisos.md §2); `/me/terms*` van por identidad (L1).

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\FamUnit\Http\Controllers\FamilyUnitsController;
use App\Modules\FamUnit\Http\Controllers\FamilyUnitStudentsController;
use App\Modules\FamUnit\Http\Controllers\GuardianLinkRevocationController;
use App\Modules\FamUnit\Http\Controllers\GuardianLinksController;
use App\Modules\FamUnit\Http\Controllers\GuardiansController;
use App\Modules\FamUnit\Http\Controllers\ImageConsentDecisionsController;
use App\Modules\FamUnit\Http\Controllers\ImageConsentsController;
use App\Modules\FamUnit\Http\Controllers\ImageConsentTextsController;
use App\Modules\FamUnit\Http\Controllers\MeTermsController;
use App\Modules\FamUnit\Http\Controllers\TermsAcceptancesController;
use App\Modules\FamUnit\Http\Controllers\TermsVersionsController;
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
    Route::delete('/family-units/{publicId}/students/{membershipPublicId}', [FamilyUnitStudentsController::class, 'destroy'])->middleware('permission:familia.actualizar')->name('fam-unit.family-units.students.destroy');
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
    // api.md §2.7: consentimiento de imagen (1.14-b). El estado vigente se calcula, no se guarda (ADR-066 §5.2).
    // `/image-consents/exports` va antes que cualquier ruta con parámetro bajo `/image-consents`.
    Route::post('/image-consents/exports', [ImageConsentsController::class, 'storeExport'])->middleware('permission:consentimiento_imagen.exportar')->name('fam-unit.image-consents.exports');
    Route::get('/image-consents', [ImageConsentsController::class, 'index'])->middleware('permission:consentimiento_imagen.leer')->name('fam-unit.image-consents.index');
    Route::get('/image-consent-decisions', [ImageConsentDecisionsController::class, 'index'])->middleware('permission:consentimiento_imagen.leer')->name('fam-unit.image-consent-decisions.index');
    Route::post('/image-consent-decisions', [ImageConsentDecisionsController::class, 'store'])->middleware('permission:consentimiento_imagen.actualizar')->name('fam-unit.image-consent-decisions.store');
    // RN-FAM-UNIT-75: el texto publicado de cada nivel; el ámbito no filtra (basta el permiso con cualquiera de sus ámbitos).
    Route::get('/image-consent-texts', [ImageConsentTextsController::class, 'index'])->middleware('permission:consentimiento_imagen.leer')->name('fam-unit.image-consent-texts.index');
    // api.md §2.8: versiones de los textos legales (permisos del recurso `texto_legal`); en 1.14-b solo los tipos de imagen.
    Route::get('/terms-versions', [TermsVersionsController::class, 'index'])->middleware('permission:texto_legal.leer')->name('fam-unit.terms-versions.index');
    Route::post('/terms-versions', [TermsVersionsController::class, 'store'])->middleware('permission:texto_legal.crear')->name('fam-unit.terms-versions.store');
    Route::get('/terms-versions/{publicId}', [TermsVersionsController::class, 'show'])->middleware('permission:texto_legal.leer')->name('fam-unit.terms-versions.show');
    Route::patch('/terms-versions/{publicId}', [TermsVersionsController::class, 'update'])->middleware('permission:texto_legal.actualizar')->name('fam-unit.terms-versions.update');
    Route::delete('/terms-versions/{publicId}', [TermsVersionsController::class, 'destroy'])->middleware('permission:texto_legal.eliminar')->name('fam-unit.terms-versions.destroy');
    Route::post('/terms-versions/{publicId}/publish', [TermsVersionsController::class, 'publish'])->middleware('permission:texto_legal.publicar')->name('fam-unit.terms-versions.publish');
    // api.md §2.8 (1.14-c): consulta administrativa de las aceptaciones de las condiciones de uso.
    Route::get('/terms-acceptances', [TermsAcceptancesController::class, 'index'])->middleware('permission:aceptacion_condiciones.leer')->name('fam-unit.terms-acceptances.index');
    // api.md §2.8 (1.14-c, ADR-067): autoservicio por identidad, sin `permission:` (ampliación L1 de AR-07a) y exento del
    // muro de condiciones (L3). `module-enabled:fam_unit` (AR-07b) va en el grupo.
    Route::get('/me/terms', [MeTermsController::class, 'show'])->name('fam-unit.me-terms.show');
    Route::post('/me/terms-acceptances', [MeTermsController::class, 'store'])->name('fam-unit.me-terms-acceptances.store');
});
