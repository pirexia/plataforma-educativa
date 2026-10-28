<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Domain\Models\TermsAcceptance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.8. **No** se exponen `ip_address` ni `user_agent`: se conservan como evidencia y se consultan,
 * en caso de litigio, por el procedimiento de `REQ-PRIV-003`. El `public_id` del usuario lo precarga el controlador en
 * `FamilyRefs` (memoizado por petición).
 *
 * @mixin TermsAcceptance
 */
class TermsAcceptanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->termsVersion;

        return [
            'public_id' => $this->public_id,
            'user' => app(FamilyRefs::class)->actorPublicId((int) $this->user_id),
            'terms_version' => $version?->public_id,
            'version_number' => $version === null ? null : (int) $version->version_number,
            'shown_locale' => $this->shown_locale,
            'accepted_at' => $this->accepted_at,
        ];
    }
}
