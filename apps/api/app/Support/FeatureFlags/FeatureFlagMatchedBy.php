<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8.2, api.md §2.13. Vocabulario de `FeatureFlagDecision
 * ::matchedBy` — documentado como enumerado extensible (`ADR-038 §7.3`):
 * un cliente nuevo puede recibir un valor que no conocía sin que eso sea
 * una ruptura de compatibilidad.
 */
enum FeatureFlagMatchedBy: string
{
    case Tenant = 'tenant';
    case EarlyAdopters = 'early_adopters';
    case Percentage = 'percentage';
    case Global = 'global';
    case ForcedOff = 'forced_off';
    case Retired = 'retired';
    case ModuleDisabled = 'module_disabled';
    case RoleFiltered = 'role_filtered';
    case None = 'none';
}
