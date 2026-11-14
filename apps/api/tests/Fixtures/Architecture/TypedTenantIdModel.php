<?php

namespace Tests\Fixtures\Architecture;

use App\Support\Tenancy\TenantModel;

/**
 * Fixture de control positivo (ADR-056 AR-06, #378 B4): modelo correcto
 * sobre una tabla con `tenant_id`. NO es código de producción.
 */
final class TypedTenantIdModel extends TenantModel
{
    protected $table = 'ar06_ctrl_gadgets';
}
