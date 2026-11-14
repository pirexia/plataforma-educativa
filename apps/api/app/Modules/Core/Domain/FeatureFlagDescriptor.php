<?php

namespace App\Modules\Core\Domain;

use App\Support\FeatureFlags\FeatureFlagRolloutUnit;

/**
 * `datos.md §9.1`. Forma inmutable del descriptor de *flag* que cada
 * módulo declara dentro de `DeclaresModuleRegistry::moduleDescriptor()`
 * (clave `feature_flags`), mismo reparto que `ModuleDescriptor` para
 * `depends_on`/`essential`. `key`, `module_code` (el propio módulo dueño,
 * nunca declarado a mano: nulo sólo cuando el propio módulo es `core`),
 * `name_key`/`description_key` (`INV-009`) y `rollout_unit` (`RN-BO-36`)
 * — lo materializa `platform:sync-registry` en `feature_flags`.
 */
final class FeatureFlagDescriptor
{
    public function __construct(
        public readonly string $key,
        public readonly string $nameKey,
        public readonly string $descriptionKey,
        public readonly FeatureFlagRolloutUnit $rolloutUnit,
        public readonly ?string $moduleCode,
    ) {}

    /**
     * @param  array{key: string, name_key: string, description_key: string, rollout_unit?: string}  $flag
     */
    public static function fromArray(array $flag, string $moduleCode): self
    {
        return new self(
            key: $flag['key'],
            nameKey: $flag['name_key'],
            descriptionKey: $flag['description_key'],
            rolloutUnit: FeatureFlagRolloutUnit::from($flag['rollout_unit'] ?? 'tenant'),
            // `core` es el único módulo cuyos flags son "del núcleo o
            // transversales" en el sentido de datos.md §9.2 (module_code
            // nulo); el resto declara su propio código como dueño.
            moduleCode: $moduleCode === 'core' ? null : $moduleCode,
        );
    }
}
