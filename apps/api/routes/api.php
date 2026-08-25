<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// ADR-014/ADR-033 §2: toda ruta de negocio, sin excepción, resuelve tenant
// antes de tocar datos. /health queda fuera a propósito (healthcheck del
// contenedor, sin subdominio de tenant).
//
// REQ-AUTH/api.md §8: orden fijado, un intercambio de dos posiciones aquí
// es un fallo de seguridad silencioso.
//   1. AssignRequestId       — global, prependToGroup('api') en bootstrap/app.php
//   2. resolve-tenant        — antes de sesión y de cualquier acceso a datos
//   3. encrypt-cookies       — antes de leer/escribir cualquier cookie cifrada
//   4. add-queued-cookies    — framework
//   5. start-session         — la sesión pertenece a un tenant ya resuelto
//   6. csrf                  — después de la sesión, de donde sale el token
//   7. verify-session-tenant — RN-AUTH-31: tenant_id del payload vs. host
//   8. session-idle-timeout  — después de la reverificación
//   9. resolve-locale        — movido: ahora corre con $request->user() ya resuelto
Route::prefix('v1')->middleware([
    'resolve-tenant',
    'encrypt-cookies',
    'add-queued-cookies',
    'start-session',
    'csrf',
    'verify-session-tenant',
    'session-idle-timeout',
    'resolve-locale',
])->group(function (): void {
    require base_path('routes/api-v1.php');
});
