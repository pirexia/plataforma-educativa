<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /terms-acceptances`: cursor sobre `(accepted_at DESC, id DESC)` (`ADR-038 §4.4`); filtros
 * `terms_version` y `user` por `public_id` (un valor mal formado es `422`); `limit` de 1 a 200 (defecto 50). Sin orden:
 * es fijo.
 */
class IndexTermsAcceptancesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'terms_version' => ['sometimes', 'string', new ListOfUlids],
            'user' => ['sometimes', 'string', new ListOfUlids],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return list<string>
     */
    public function termsVersions(): array
    {
        return $this->ulidList('terms_version');
    }

    /**
     * @return list<string>
     */
    public function users(): array
    {
        return $this->ulidList('user');
    }

    public function cursorValue(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * @return list<string>
     */
    private function ulidList(string $key): array
    {
        $value = $this->query($key);

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }
}
