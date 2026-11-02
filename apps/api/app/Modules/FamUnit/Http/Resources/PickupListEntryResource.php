<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\FamUnit\Domain\PickupEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §8.1, `PickupListEntry`: una persona de la lista calculada de un alumno, sin `public_id` propio.
 * `photo_url` es una URL firmada de caducidad corta, nula si no hay fotografía o el sujeto no la ve; el documento va siempre
 * enmascarado (`RN-FAM-UNIT-90`) y se omite en los terceros para un sujeto con ámbito `unidad_familiar`
 * (`RN-FAM-UNIT-91`). Nunca se incluye la existencia de un vínculo revocado ni de una persona excluida. Código técnico sin
 * traducir (`ADR-038 §3.2`).
 *
 * @mixin PickupEntry
 */
class PickupListEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PickupEntry $entry */
        $entry = $this->resource;

        return [
            'person' => $entry->personPublicId,
            'display_name' => $entry->displayName,
            'bases' => array_map(static fn (PickupBasis $basis): array => match ($basis->basis) {
                PickupBasis::TUTOR => ['basis' => $basis->basis, 'guardian_link' => $basis->reference, 'relationship' => $basis->relationship],
                PickupBasis::THIRD_PARTY => ['basis' => $basis->basis, 'authorization' => $basis->reference, 'relationship' => $basis->relationship, 'valid_until' => $basis->validUntil],
                default => ['basis' => $basis->basis, 'authorization' => $basis->reference, 'relationship' => $basis->relationship, 'valid_from' => $basis->validFrom, 'valid_until' => $basis->validUntil],
            }, $entry->bases),
            'has_photo' => $entry->hasPhoto,
            'photo_url' => $entry->photoUrl,
            'photo_url_expires_at' => $entry->photoUrlExpiresAt,
            'contact_phone' => $entry->contactPhone,
            'document' => $entry->documentType !== null && $entry->documentMasked !== null
                ? ['type' => $entry->documentType, 'masked' => $entry->documentMasked]
                : null,
        ];
    }
}
