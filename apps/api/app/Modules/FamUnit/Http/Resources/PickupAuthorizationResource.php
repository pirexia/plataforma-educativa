<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\PickupViewContext;
use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §8.1, `PickupAuthorization`. **No** se exponen `ip_address` ni `user_agent`: se conservan como
 * evidencia. `reason` (texto libre) solo con `autorizado_recogida.leer` de ámbito `todos`; `ineffective_reason`
 * `persona_excluida` solo con `revocacion_tutor.leer` (sin él, `no_vigente`: no se revela una resolución judicial,
 * `RN-FAM-UNIT-36`). `is_effective` es el cálculo de ahora, no un dato guardado. Los `public_id` de alumno, persona, tutor y
 * usuario los precarga el controlador en `FamilyRefs`; el estado, en `PickupViewContext`.
 *
 * @mixin PickupAuthorization
 */
class PickupAuthorizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(FamilyRefs::class);
        $view = app(PickupViewContext::class);
        $state = $view->effectiveness[(int) $this->id] ?? ['effective' => false, 'reason' => null];
        $person = $refs->person((int) $this->person_id);
        $version = $this->termsVersion;
        $reason = $state['reason'] === 'persona_excluida' && ! $view->showExcluded ? 'no_vigente' : $state['reason'];

        $data = [
            'public_id' => $this->public_id,
            'student_person' => $refs->person((int) $this->student_person_id)?->publicId,
            'person' => ['public_id' => $person?->publicId, 'display_name' => $person?->displayName ?? ''],
            'relationship' => $this->relationship,
            'kind' => $this->kind,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
        ];

        if ($view->showReason) {
            $data['reason'] = $this->reason;
        }

        return $data + [
            'authorized_by' => $refs->guardianPublicId((int) $this->authorized_by_guardian_id),
            'terms_version' => $version === null ? null : [
                'public_id' => $version->public_id,
                'kind' => $version->kind,
                'version_number' => (int) $version->version_number,
            ],
            'channel' => $this->channel,
            'authorized_at' => $this->authorized_at,
            'recorded_by' => $refs->actorPublicId((int) $this->recorded_by),
            'is_effective' => $state['effective'],
            'ineffective_reason' => $reason,
            'withdrawn_at' => $this->withdrawn_at,
            'created_at' => $this->created_at,
        ];
    }
}
