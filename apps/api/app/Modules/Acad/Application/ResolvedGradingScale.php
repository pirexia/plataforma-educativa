<?php

namespace App\Modules\Acad\Application;

/**
 * Una escala en su estado **resultante** tras aplicar el cuerpo de un alta o
 * de un `PATCH` (`funcional.md §4.9.3`): ya validado por completo. Los campos
 * numéricos, en centésimas (`ScaleDecimal`); `decimals`, entero 0 a 2. En la
 * numérica no hay valores; en la cualitativa y la mixta, la lista completa y
 * ordenada (la `position` es el orden de la lista, `RN-ACAD-83`).
 * `valuesSent` indica si el cuerpo traía `values` (si no, no se tocan).
 */
final readonly class ResolvedGradingScale
{
    /**
     * @param  array<string, string>  $nameTranslations
     * @param  list<ResolvedGradingValue>  $values
     */
    public function __construct(
        public string $code,
        public array $nameTranslations,
        public ?int $min,
        public ?int $max,
        public ?int $decimals,
        public ?int $passing,
        public array $values,
        public bool $valuesSent,
    ) {}
}
