<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\Api\Rules\InList;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /terms-versions`: filtros `kind` y `status`; orden por `version_number` (defecto
 * descendente, desempate por `id`).
 */
class IndexTermsVersionsRequest extends IndexRequest
{
    /** @var list<string> */
    public const SORTS = ['version_number', '-version_number'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'kind' => ['sometimes', 'string', new InList(TermsVersion::KINDS)],
            'status' => ['sometimes', 'string', new InList([TermsVersion::STATUS_DRAFT, TermsVersion::STATUS_PUBLISHED, TermsVersion::STATUS_RETIRED])],
        ];
    }

    /**
     * @return array{kind: list<string>, status: list<string>}
     */
    public function filters(): array
    {
        return ['kind' => $this->list('kind'), 'status' => $this->list('status')];
    }
}
