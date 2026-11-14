<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Support\Tenancy\Tenant;

/**
 * api.md §2.4: una transición simple responde `200` con el tenant
 * actualizado; `to_status = 'eliminado'` responde `202` con la
 * `dual_authorization` pendiente y **no ha eliminado nada todavía**. Uno
 * de los dos campos es siempre `null`.
 */
final readonly class TenantTransitionResult
{
    public function __construct(
        public ?Tenant $tenant,
        public ?DualAuthorization $dualAuthorization,
    ) {}

    public static function simple(Tenant $tenant): self
    {
        return new self($tenant, null);
    }

    public static function pendingAuthorization(DualAuthorization $authorization): self
    {
        return new self(null, $authorization);
    }
}
