<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8.2. El conjunto de reglas de un *flag* concreto,
 * propuesto (vista previa) o vigente (evaluación). Lleva la clave y la
 * unidad de reparto del propio *flag* porque las dos son necesarias para
 * decidir y `explainWith()` no recibe la clave por separado (§5.11.8.2):
 * el llamador las resuelve una vez contra el catálogo y construye este
 * objeto, nunca el evaluador.
 */
final class FeatureFlagRuleSet
{
    /**
     * @param  list<FeatureFlagRuleInput>  $rules
     */
    public function __construct(
        public readonly string $flagKey,
        public readonly FeatureFlagRolloutUnit $rolloutUnit,
        public readonly array $rules,
        public readonly ?string $moduleCode = null,
    ) {}
}
