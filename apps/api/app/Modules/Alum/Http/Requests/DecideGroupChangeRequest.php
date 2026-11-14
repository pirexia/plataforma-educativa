<?php

namespace App\Modules\Alum\Http\Requests;

/**
 * REQ-ALUM/api.md §3, `POST /group-change-requests/{public_id}/approval|rejection|cancellation`: `decision_note`
 * (opcional al aprobar y cancelar, obligatoria al rechazar; lo comprueba el servicio).
 */
class DecideGroupChangeRequest extends GroupChangeBodyRequest
{
    protected function allowedKeys(): array
    {
        return ['decision_note'];
    }
}
