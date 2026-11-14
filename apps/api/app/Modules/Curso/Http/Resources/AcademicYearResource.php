<?php

namespace App\Modules\Curso\Http\Resources;

use App\Modules\Curso\Domain\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-CURSO/api.md §1 (1.10). `status` viaja sin traducir (ADR-038 §3.2);
 * las fechas civiles en `AAAA-MM-DD`. No se exponen `id`, `tenant_id`,
 * `created_by`, `updated_by` ni `deleted_at`.
 *
 * @mixin AcademicYear
 */
class AcademicYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'status' => $this->status->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
