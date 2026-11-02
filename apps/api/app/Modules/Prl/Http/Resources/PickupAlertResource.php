<?php

namespace App\Modules\Prl\Http\Resources;

use App\Modules\Prl\Application\PrlRefs;
use App\Modules\Prl\Domain\Models\PickupAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-PRL/api.md §8.4, `PickupAlert`. El alumno se nombra por el `public_id` de su persona y los usuarios por el suyo. Con
 * `$guidance` (alta y detalle) añade el `protocol` de actuación y los teléfonos del tutor principal y del contacto de
 * emergencia, **calculados al leer y nunca guardados** (`RN-PRL-08`, `RN-PRL-01`); en el listado no van. Código técnico sin
 * traducir (`ADR-038 §3.2`).
 *
 * @mixin PickupAlert
 */
class PickupAlertResource extends JsonResource
{
    /** @var array{protocol: string, contacts: array{principal_phone: ?string, emergency_phone: ?string}}|null */
    public ?array $guidance = null;

    /**
     * @param  array{protocol: string, contacts: array{principal_phone: ?string, emergency_phone: ?string}}|null  $guidance
     */
    public function withGuidance(?array $guidance): static
    {
        $this->guidance = $guidance;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(PrlRefs::class);

        $data = [
            'public_id' => $this->public_id,
            'student_person' => $refs->person((int) $this->student_person_id)?->publicId,
            'kind' => $this->kind,
            'presenter_description' => $this->presenter_description,
            'status' => $this->status,
            'raised_by' => $refs->userPublicId((int) $this->raised_by),
            'raised_at' => $this->raised_at,
            'principal_contacted_at' => $this->principal_contacted_at,
            'resolution' => $this->resolution,
            'resolution_notes' => $this->resolution_notes,
            'resolved_by' => $refs->userPublicId($this->resolved_by === null ? null : (int) $this->resolved_by),
            'resolved_at' => $this->resolved_at,
        ];

        if ($this->guidance !== null) {
            $data['protocol'] = $this->guidance['protocol'];
            $data['contacts'] = $this->guidance['contacts'];
        }

        return $data;
    }
}
