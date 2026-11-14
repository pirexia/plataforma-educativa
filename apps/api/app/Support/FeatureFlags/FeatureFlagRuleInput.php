<?php

namespace App\Support\FeatureFlags;

/**
 * `datos.md §9.3`, api.md §2.11.1. Una regla dentro de un conjunto
 * propuesto o vigente. Cada eje lleva exactamente su columna y ninguna
 * otra — la misma restricción que el motor impone con `CHECK`
 * (`feature_flag_rules_tenant_axis_check` y análogas), reafirmada aquí en
 * el tipo para que un conjunto incoherente no llegue a construirse.
 */
final class FeatureFlagRuleInput
{
    /**
     * @param  ?int  $tenantId  Clave interna (`tenants.id`), la que compara el motor de decisión — nunca se expone. Resuelta por quien construye el conjunto (`EloquentFeatureFlagAdministration`, desde `tenant_public_id`) para que el motor no necesite una carga perezosa por regla en el camino de evaluación.
     * @param  ?string  $tenantPublicId  ULID, sólo para eco en la API (`ADR-029`) — el motor no lo usa para comparar.
     */
    public function __construct(
        public readonly FeatureFlagScopeType $scopeType,
        public readonly bool $enabled = true,
        public readonly ?int $tenantId = null,
        public readonly ?string $tenantPublicId = null,
        public readonly ?string $roleCode = null,
        public readonly ?int $percentage = null,
        public readonly ?string $publicId = null,
    ) {}
}
