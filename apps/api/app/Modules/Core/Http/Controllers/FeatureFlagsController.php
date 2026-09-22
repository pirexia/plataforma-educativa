<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Domain\ModuleCatalog;
use App\Support\FeatureFlags\FeatureFlagEvaluator;
use Illuminate\Routing\Controller;

/**
 * api.md §2.14: el dato es de `REQ-BO-005`, pero la ruta vive en la
 * aplicación del tenant — mismo criterio que `GET /api/v1/platform-actions`
 * (§2.9). Autorización por identidad del portador, sin permiso (mismo
 * argumento que `MeController::show()`, funcional.md §4.9): la respuesta
 * depende del propio sujeto.
 */
class FeatureFlagsController extends Controller
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly FeatureFlagEvaluator $evaluator,
    ) {}

    /**
     * `CA-BO-097`, `CA-BO-176`: sólo las claves que evalúan verdadero para
     * quien pregunta. Las claves declaradas se leen del catálogo en
     * proceso (`ModuleCatalog`, sin consulta), y cada una se evalúa por
     * `FeatureFlagEvaluator::isEnabled()` — el mismo camino, cacheado, que
     * usaría cualquier otro módulo del producto (`RN-BO-99`).
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $exposed = [];

        foreach ($this->catalog->all() as $descriptor) {
            foreach ($descriptor->featureFlags as $flag) {
                if ($this->evaluator->isEnabled($flag->key)) {
                    $exposed[] = $flag->key;
                }
            }
        }

        return ['data' => $exposed];
    }
}
