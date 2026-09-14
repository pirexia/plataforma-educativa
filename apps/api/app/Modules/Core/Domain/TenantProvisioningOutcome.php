<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-048 §4.3. La idempotencia de `TenantProvisioner` deja de ser
 * silenciosa: `Provisioned` cuando la operación ha escrito de verdad,
 * `AlreadyProvisioned` cuando la comprobación de idempotencia (existencia
 * de `tenant_settings` en el destino) ha encontrado que no había nada que
 * hacer. `bo:retry-provisioning` (CA-BO-108) y el test de idempotencia lo
 * usan para no tener que contar filas.
 *
 * El fallo viaja como excepción, nunca como un tercer caso: un enumerado
 * `Failed` se podría ignorar por descuido (ADR-048 §4.3).
 */
enum TenantProvisioningOutcome
{
    case Provisioned;
    case AlreadyProvisioned;
}
