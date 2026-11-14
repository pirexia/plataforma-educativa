<?php

namespace App\Support\FeatureFlags;

/**
 * `RN-BO-36`, funcional.md §5.11.2: la unidad de reparto la declara quien
 * programa la funcionalidad, en el descriptor del módulo — nunca la
 * regla ni el operador.
 */
enum FeatureFlagRolloutUnit: string
{
    case Tenant = 'tenant';
    case User = 'user';
}
