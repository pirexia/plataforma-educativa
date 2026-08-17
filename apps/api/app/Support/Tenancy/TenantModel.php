<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-033 §4: base obligatoria de todo modelo de negocio (INV-001).
 * withoutGlobalScope(TenantScope::class) queda prohibido en app/Modules/**
 * — verificado por un test de arquitectura en 0.7.11, no por este fichero.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;

    public function getConnectionName(): ?string
    {
        if (app(TenantContext::class)->isPlatformMode()) {
            return 'pgsql_platform';
        }

        return parent::getConnectionName();
    }
}
