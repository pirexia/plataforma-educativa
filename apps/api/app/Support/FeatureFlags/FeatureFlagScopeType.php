<?php

namespace App\Support\FeatureFlags;

/**
 * `datos.md §9.3`. Vocabulario cerrado de `feature_flag_rules.scope_type`,
 * los cuatro ejes de exposición más el global — funcional.md §5.11.4.
 */
enum FeatureFlagScopeType: string
{
    case Global = 'global';
    case Tenant = 'tenant';
    case EarlyAdopters = 'early_adopters';
    case Percentage = 'percentage';
    case Role = 'role';
}
