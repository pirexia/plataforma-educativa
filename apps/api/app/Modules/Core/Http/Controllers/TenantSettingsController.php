<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Http\Resources\TenantSettingsResource;
use Illuminate\Routing\Controller;

/**
 * api.md §2. `GET /tenant/settings`: `configuracion.leer`. La fila puede
 * no existir todavía si el tenant no se ha aprovisionado — en ese caso se
 * construye en memoria con los valores por defecto de datos.md §A.1, sin
 * crear la fila (crearla es cosa de `tenant:provision-defaults`, no de
 * una lectura).
 */
class TenantSettingsController extends Controller
{
    public function show(): TenantSettingsResource
    {
        $settings = TenantSetting::query()->first() ?? new TenantSetting([
            'default_locale' => 'es-ES',
            'active_locales' => ['es-ES'],
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'fiscal_country_code' => 'ES',
        ]);

        return new TenantSettingsResource($settings);
    }
}
