<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * ADR-033 §4: capa de ergonomía, no la barrera de seguridad — esa es RLS.
 * Sin tenant activo y sin modo plataforma, tenantId() lanza
 * TenantContextMissing: una consulta Eloquent sin contexto revienta, no
 * devuelve "todo" ni "nada" en silencio.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformMode()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), '=', $context->tenantId());
    }
}
