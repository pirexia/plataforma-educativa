<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Cache;

/**
 * Issue #7, REQ-BO/funcional.md §6.3, `RN-BO-14`/`RN-BO-51`. La clave
 * `tenant-resolution:{slug}` la escribe `ResolveTenant` **antes** de que
 * `TenantContext::enter()` cambie `cache.prefix`, así que vive bajo el
 * prefijo base de caché — nunca bajo `t{tenant_id}:`. El backoffice corre
 * sin tenant, luego también escribe bajo el prefijo base: las dos partes
 * nombran la misma clave sin hacer nada especial. Por eso este helper es
 * un único punto con nombre, en vez de que cada llamador construya la
 * cadena de la clave a mano.
 *
 * Se invoca siempre **después** de confirmar la transacción
 * (`DB::afterCommit`/`->afterCommit()`), nunca dentro: invalidar dentro
 * abre la ventana en la que una petición concurrente relee el valor
 * anterior de la base de datos y lo vuelve a cachear 60s (funcional.md
 * §6.3, punto 2).
 */
final class TenantResolutionCache
{
    public static function forget(string $slug): void
    {
        Cache::forget("tenant-resolution:{$slug}");
    }
}
