<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\BoValidationError;
use App\Modules\Backoffice\Application\PlatformMetricsService;
use App\Modules\Backoffice\Http\Requests\ShowPlatformMetricsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * `REQ-BO-006` reducido, sub-paso `1.6d`. `funcional.md §5.10`,
 * `api.md §2.10.4`, `§2.10.5`. Capacidad `metrica.leer` (`routes.php`).
 */
class PlatformMetricsController extends Controller
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly PlatformMetricsService $metrics,
    ) {}

    /**
     * `GET /metrics/platform`. `occurred_at_from`/`occurred_at_to`,
     * inclusivos, por omisión los últimos 30 días (`ADR-038 §5.2`).
     */
    public function platform(ShowPlatformMetricsRequest $request): JsonResponse
    {
        $from = $request->filled('occurred_at_from')
            ? Carbon::parse($request->string('occurred_at_from')->value())
            : now()->subDays(self::DEFAULT_WINDOW_DAYS);

        $to = $request->filled('occurred_at_to')
            ? Carbon::parse($request->string('occurred_at_to')->value())
            : now();

        if ($from->greaterThan($to)) {
            throw BoValidationError::forField('occurred_at_from', 'bo.metrics.invalid_period');
        }

        return response()->json($this->metrics->platform($from, $to));
    }

    /**
     * `GET /metrics/module-adoption`. Sin parámetros: es el catálogo
     * declarado entero, no una consulta acotada en el tiempo.
     */
    public function moduleAdoption(): JsonResponse
    {
        return response()->json($this->metrics->moduleAdoption());
    }
}
