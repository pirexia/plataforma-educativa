<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Domain\Models\UserImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §7. Recurso desnudo (ADR-038 §3.1). `report_url` es una URL
 * firmada de caducidad corta, regenerada en cada respuesta — nunca se
 * cachea (mismo criterio que `TenantSettingsResource` con el branding).
 *
 * @mixin UserImport
 */
class UserImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'row_count' => $this->row_count,
            'error_count' => $this->error_count,
            'created_count' => $this->created_count,
            'error_summary' => $this->error_summary,
            'report_url' => $this->report_object_key !== null
                ? Storage::disk(config('filesystems.default'))->temporaryUrl(
                    $this->report_object_key,
                    now()->addMinutes((int) config('core.signed_url_ttl_minutes')),
                )
                : null,
            'validated_at' => $this->validated_at,
            'executed_at' => $this->executed_at,
        ];
    }
}
