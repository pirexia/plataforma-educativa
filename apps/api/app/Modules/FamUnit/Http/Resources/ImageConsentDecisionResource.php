<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.6. **No** se exponen `ip_address` ni `user_agent`: se conservan como evidencia y se consultan,
 * en caso de litigio, por el procedimiento de `REQ-PRIV-003`. `consent_text_version` es `null` solo en un
 * `no_autorizo` sin texto publicado (`RN-FAM-UNIT-68`). Los `public_id` de alumno, tutor y autor los precarga el
 * controlador en `FamilyRefs` (memoizado por petición).
 *
 * @mixin ImageConsentDecision
 */
class ImageConsentDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(FamilyRefs::class);
        $version = $this->termsVersion;

        return [
            'public_id' => $this->public_id,
            'student_person' => $refs->person((int) $this->student_person_id)?->publicId,
            'level' => $this->level,
            'decision' => $this->decision,
            'guardian' => $refs->guardianPublicId((int) $this->guardian_id),
            'recorded_by' => $refs->actorPublicId((int) $this->recorded_by),
            'channel' => $this->channel,
            'consent_text_version' => $version === null ? null : [
                'public_id' => $version->public_id,
                'kind' => $version->kind,
                'version_number' => (int) $version->version_number,
            ],
            'shown_locale' => $this->shown_locale,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
