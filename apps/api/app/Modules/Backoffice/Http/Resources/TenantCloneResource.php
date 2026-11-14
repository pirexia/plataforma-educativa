<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;

/**
 * api.md §2.4.3. La misma forma de `TenantResource` con un bloque
 * adicional que dice **qué se ha copiado y qué no** (`CA-BO-123`): un
 * operador que clona espera que el clon sea igual al origen, y lo que no
 * se copia es justamente lo que no espera.
 *
 * @mixin Tenant
 */
class TenantCloneResource extends TenantResource
{
    public function __construct(
        Tenant $resource,
        private readonly string $sourceTenantPublicId,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'cloned_from' => [
                'tenant_public_id' => $this->sourceTenantPublicId,
                'copied' => ['settings.operativos', 'roles', 'role_permissions', 'module_subscriptions'],
                'not_copied' => [
                    'settings.fiscales', 'settings.marca', 'personas', 'usuarios',
                    'invitaciones', 'auditoria', 'estado', 'early_adopter',
                ],
            ],
        ];
    }
}
