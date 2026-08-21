<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// ADR-014/ADR-033 §2: toda ruta de negocio, sin excepción, resuelve tenant
// antes de tocar datos. /health queda fuera a propósito (healthcheck del
// contenedor, sin subdominio de tenant).
Route::prefix('v1')->middleware(['resolve-tenant', 'resolve-locale'])->group(function (): void {
    require base_path('routes/api-v1.php');
});
