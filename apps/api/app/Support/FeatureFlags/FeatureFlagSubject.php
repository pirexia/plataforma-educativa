<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8.2. Lo que hace posible evaluar **sin entrar en el
 * tenant** — la condición que `ADR-046 §6.4` impone al backoffice: en vez
 * de tomar el sujeto del contexto ambiental (`TenantContext`, el guard
 * `web`), como hace `FeatureFlagEvaluator::isEnabled()`, se recibe
 * explícito.
 *
 * `$roleCodes` es opcional y vacío por omisión: `GET /tenants/{id}/
 * feature-flags` (api.md §2.13) evalúa el centro sin persona concreta, y
 * un sujeto sin usuario ni roles es exactamente el caso de `RN-BO-41`
 * (sin sujeto usuario, un flag con reglas de rol es falso).
 */
final class FeatureFlagSubject
{
    /**
     * @param  list<string>  $roleCodes  Códigos de rol del usuario, nunca su fila (RN-BO-40).
     * @param  bool  $isEarlyAdopter  `tenants.early_adopter_since IS NOT NULL` (datos.md §6.1). Lo resuelve quien construye el sujeto — el evaluador desde el contexto, el backoffice desde el `Tenant` que ya tiene en mano — nunca el motor de decisión, que es puro y no toca base de datos (RN-BO-101).
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $tenantPublicId,
        public readonly ?string $userPublicId = null,
        public readonly array $roleCodes = [],
        public readonly bool $isEarlyAdopter = false,
    ) {}

    public static function forTenant(int $tenantId, string $tenantPublicId): self
    {
        return new self($tenantId, $tenantPublicId);
    }
}
