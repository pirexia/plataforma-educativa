<?php

namespace App\Modules\FamUnit\Domain\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * REQ-FAM-UNIT-004, `ADR-066 §5.2`, `OPEN-066-04`, issue #483. Cast `datetime` que conserva los **microsegundos** al
 * escribir. El cast `datetime` de Eloquent serializa con el formato del modelo (`Y-m-d H:i:s`) y trunca la fracción aunque la
 * columna sea `timestamptz(6)`; este cast la escribe completa. Solo lo llevan `image_consent_decisions.occurred_at` y
 * `guardian_student_links.legal_guardian_verified_at`, las dos mitades de la comparación estricta que decide si una decisión
 * cuenta (las marcas `created_at`/`updated_at` siguen a segundo).
 *
 * @implements CastsAttributes<Carbon|null, CarbonInterface|string|null>
 */
class MicrosecondDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))->format('Y-m-d H:i:s.uP');
    }
}
