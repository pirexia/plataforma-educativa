<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\PickupPersonView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §8.1, `PickupPerson`. El documento va **completo** (tipo y número): solo lo ven quienes llegan por
 * estos *endpoints*, con `autorizado_recogida.crear` o `.actualizar` (`RN-FAM-UNIT-90`, `OPEN-FAM-UNIT-38`); en la lista de
 * recogida va siempre enmascarado. Nunca incluye la clave del objeto de la fotografía. `reused` y `data_mismatch` solo en la
 * respuesta de `POST /pickup-persons`. Una persona reutilizada que estos *endpoints* no alcanzan solo expone su
 * identificador y los dos indicadores.
 *
 * @mixin PickupPersonView
 */
class PickupPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PickupPersonView $view */
        $view = $this->resource;

        $data = ['person' => $view->person->publicId];

        if ($view->identified) {
            $data += [
                'display_name' => $view->person->displayName,
                'document' => $view->contact?->documentType !== null && $view->contact?->documentNumber !== null
                    ? ['type' => $view->contact->documentType, 'number' => $view->contact->documentNumber]
                    : null,
                'contact_phone' => $view->contact?->contactPhone,
                'has_photo' => $view->photo !== null,
                'photo_updated_at' => $view->photo?->created_at,
            ];
        }

        if ($view->reused !== null) {
            $data['reused'] = $view->reused;
            $data['data_mismatch'] = (bool) $view->dataMismatch;
        }

        return $data;
    }
}
