<?php

namespace App\Modules\Prl\Http\Resources;

use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\FamUnit\Domain\PickupEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-PRL/api.md §8.4, `PickupListEntry` de la puerta (la misma forma que `GET /pickup-lists/{…}`): una persona de la lista
 * calculada de un alumno. `photo_url` es una URL firmada de caducidad corta que `fam_unit` genera en cada respuesta (nula si
 * no hay fotografía o no se pudo firmar: se verifica por documento, `OPEN-FAM-UNIT-47`); el documento va siempre enmascarado
 * (`RN-FAM-UNIT-90`). Nunca se incluye la existencia de un vínculo revocado ni de una persona excluida (`OPEN-FAM-UNIT-34`).
 * Código técnico sin traducir (`ADR-038 §3.2`).
 *
 * @mixin PickupEntry
 */
class DoorPickupEntryResource extends JsonResource
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
