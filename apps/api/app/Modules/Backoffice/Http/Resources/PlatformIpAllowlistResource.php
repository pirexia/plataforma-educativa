<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.3.
 *
 * @mixin PlatformIpAllowlistEntry
 */
class PlatformIpAllowlistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'cidr' => $this->cidr,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
        ];
    }
}
