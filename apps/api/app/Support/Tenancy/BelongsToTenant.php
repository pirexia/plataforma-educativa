<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * ADR-033 §4. Global scope de lectura + relleno automático en creating +
 * bloqueo de cambio en updating. Rellenar solo pasa en modo normal: en modo
 * plataforma (TenantContext::runAsPlatform()) hay que fijar tenant_id a
 * mano, porque "el tenant activo" no significa nada ahí.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if (! isset($model->tenant_id) && ! $context->isPlatformMode()) {
                $model->tenant_id = $context->tenantId();
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new RuntimeException(
                    'tenant_id no se puede modificar tras la creación (ADR-033 §4).'
                );
            }
        });
    }
}
