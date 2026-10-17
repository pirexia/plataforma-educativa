<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Application\ScaleDecimal;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\GradingScaleValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §11.2. Los campos numéricos viajan como **cadena** con
 * exactamente `decimals` cifras decimales (`RN-ACAD-75`); `null` en la
 * cualitativa. `values` siempre presente, en orden de `position`, solo los
 * vivos. En la mixta `is_passing` es derivado (`range_min ≥ passing_min_value`,
 * `RN-ACAD-87`); en la cualitativa, el guardado. Ni `id` ni `*_id` internos, ni
 * `scale_type` de los valores, ni autoría, ni `deleted_at`.
 *
 * @mixin GradingScale
 */
class GradingScaleResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $decimals = $this->decimals;
        $passing = ScaleDecimal::fromStored($this->passing_min_value);

        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'code' => $this->code,
            'name' => $this->resolvedName($this->name_translations),
            'name_translations' => $this->name_translations,
            'scale_type' => $this->scale_type,
            'min_value' => $this->decimal($this->min_value, $decimals),
            'max_value' => $this->decimal($this->max_value, $decimals),
            'decimals' => $decimals,
            'passing_min_value' => $this->decimal($this->passing_min_value, $decimals),
            'values' => $this->values->map(fn (GradingScaleValue $value): array => $this->value($value, $decimals, $passing))->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function value(GradingScaleValue $value, ?int $decimals, ?int $passing): array
    {
        $isMixta = $value->range_min !== null;
        $rangeMin = ScaleDecimal::fromStored($value->range_min);

        return [
            'public_id' => $value->public_id,
            'code' => $value->code,
            'position' => $value->position,
            'label' => $this->resolvedName($value->label_translations),
            'label_translations' => $value->label_translations,
            'is_passing' => $isMixta ? ($rangeMin !== null && $passing !== null && $rangeMin >= $passing) : $value->is_passing,
            'range_min' => $this->decimal($value->range_min, $decimals),
            'range_max' => $this->decimal($value->range_max, $decimals),
        ];
    }

    private function decimal(mixed $stored, ?int $decimals): ?string
    {
        $hundredths = ScaleDecimal::fromStored($stored);

        return $hundredths === null || $decimals === null ? null : ScaleDecimal::format($hundredths, $decimals);
    }
}
