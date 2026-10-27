<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\TermsVersionService;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.7. `title` y `body` resueltos en el servidor en el idioma de la petición (`ADR-062 §5.4`, solo
 * lectura); las traducciones completas van aparte para que el centro las edite. `kind` es inmutable tras la creación.
 *
 * @mixin TermsVersion
 */
class TermsVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolved = app(TermsVersionService::class)->resolve($this->resource);

        return [
            'public_id' => $this->public_id,
            'kind' => $this->kind,
            'version_number' => (int) $this->version_number,
            'title' => $resolved['title'],
            'title_translations' => $this->title_translations,
            'body' => $resolved['body'],
            'body_translations' => $this->body_translations,
            'status' => $this->status,
            'requires_reacceptance' => (bool) $this->requires_reacceptance,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
