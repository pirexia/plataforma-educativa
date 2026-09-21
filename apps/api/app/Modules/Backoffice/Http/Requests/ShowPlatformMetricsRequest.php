<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §2.10.4`, `GET /metrics/platform`. Valida la **forma** de
 * `occurred_at_from`/`occurred_at_to` (fecha real, no una cadena
 * cualquiera); la validación de **negocio** —`from` posterior a
 * `to`— sigue en `PlatformMetricsController::platform()` vía
 * `BoValidationError` (`bo.metrics.invalid_period`), porque compara los
 * dos campos entre sí y no es una regla de un solo campo. Sin esto, un
 * valor mal formado llegaba a `Carbon::parse()` sin capturar y producía
 * un `500` (hallazgo de `/codex:review` sobre `1.6d`).
 */
class ShowPlatformMetricsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'occurred_at_from' => ['sometimes', 'date'],
            'occurred_at_to' => ['sometimes', 'date'],
        ];
    }
}
