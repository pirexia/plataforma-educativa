<?php

namespace Tests\Fixtures\Architecture;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture de control negativo (ADR-056 AR-06, #378 B4): modelo con
 * `tenant_id` que extiende `Model` directamente, sin pasar por
 * `TenantModel`/`AppendOnlyModel`. NO es código de producción.
 */
final class RawTenantIdModel extends Model
{
    protected $table = 'ar06_ctrl_widgets';
}
