<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-045 §4.5, §4.9; funcional.md §5.8.2, §5.8.3, `OPEN-BO-18`. Forma
 * inmutable del descriptor que cada `ServiceProvider` de módulo declara
 * en `DeclaresModuleRegistry::moduleDescriptor()`. `depends_on` y
 * `essential` **no se materializan** en la tabla `modules` (`datos.md
 * §8`): una copia en base de datos de algo que declara el código se
 * desincroniza en el primer despliegue en que alguien olvide correr
 * `platform:sync-registry`, y falla en silencio (`ADR-034 §5`, `ADR-045
 * §9`).
 *
 * `featureFlags` (sub-paso `1.6e`, `datos.md §9.7`, `datos.md §9.1`):
 * mismo reparto exacto que `dependsOn`/`essential` — se declara en el
 * código, con valor por defecto `[]`, es aditivo puro y ningún
 * descriptor existente necesita tocarse. `platform:sync-registry` los
 * materializa en `feature_flags` (`RN-BO-34`).
 */
final class ModuleDescriptor
{
    /**
     * @param  list<string>  $dependsOn
     * @param  list<FeatureFlagDescriptor>  $featureFlags
     */
    public function __construct(
        public readonly string $code,
        public readonly string $nameKey,
        public readonly string $phase,
        public readonly array $dependsOn = [],
        public readonly bool $essential = false,
        public readonly array $featureFlags = [],
    ) {}

    /**
     * @param  array{code: string, name_key: string, phase: string, depends_on?: list<string>, essential?: bool, feature_flags?: list<array{key: string, name_key: string, description_key: string, rollout_unit?: string}>}  $descriptor
     */
    public static function fromArray(array $descriptor): self
    {
        return new self(
            code: $descriptor['code'],
            nameKey: $descriptor['name_key'],
            phase: $descriptor['phase'],
            dependsOn: $descriptor['depends_on'] ?? [],
            essential: $descriptor['essential'] ?? false,
            featureFlags: array_map(
                static fn (array $flag): FeatureFlagDescriptor => FeatureFlagDescriptor::fromArray($flag, $descriptor['code']),
                $descriptor['feature_flags'] ?? [],
            ),
        );
    }
}
