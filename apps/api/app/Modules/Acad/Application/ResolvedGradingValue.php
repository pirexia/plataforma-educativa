<?php

namespace App\Modules\Acad\Application;

use App\Modules\Acad\Domain\Models\GradingScaleValue;

/**
 * Un valor o tramo de una escala en su estado **resultante** (`RN-ACAD-88`):
 * ya validado, con `existing` si actualiza un valor vivo de la escala (`null`
 * si es un alta). Los límites, en centésimas (`ScaleDecimal`).
 */
final readonly class ResolvedGradingValue
{
    /**
     * @param  array<string, string>  $labelTranslations
     */
    public function __construct(
        public ?GradingScaleValue $existing,
        public string $code,
        public array $labelTranslations,
        public ?bool $isPassing,
        public ?int $rangeMin,
        public ?int $rangeMax,
    ) {}
}
