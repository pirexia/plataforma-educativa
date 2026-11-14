<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8.2. Resultado de `FeatureFlagExplainer`: a
 * diferencia de `FeatureFlagEvaluator::isEnabled()` (un `bool` desnudo,
 * §5.11.8.1 punto 3), aquí el motivo sí viaja — es lo que el backoffice
 * necesita para depurar y lo que el producto no debe entregar.
 */
final class FeatureFlagDecision
{
    public function __construct(
        public readonly bool $enabled,
        public readonly FeatureFlagMatchedBy $matchedBy,
    ) {}

    public static function of(bool $enabled, FeatureFlagMatchedBy $matchedBy): self
    {
        return new self($enabled, $matchedBy);
    }
}
