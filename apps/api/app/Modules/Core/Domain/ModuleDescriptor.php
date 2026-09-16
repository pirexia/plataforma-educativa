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
 */
final class ModuleDescriptor
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public readonly string $code,
        public readonly string $nameKey,
        public readonly string $phase,
        public readonly array $dependsOn = [],
        public readonly bool $essential = false,
    ) {}

    /**
     * @param  array{code: string, name_key: string, phase: string, depends_on?: list<string>, essential?: bool}  $descriptor
     */
    public static function fromArray(array $descriptor): self
    {
        return new self(
            code: $descriptor['code'],
            nameKey: $descriptor['name_key'],
            phase: $descriptor['phase'],
            dependsOn: $descriptor['depends_on'] ?? [],
            essential: $descriptor['essential'] ?? false,
        );
    }
}
