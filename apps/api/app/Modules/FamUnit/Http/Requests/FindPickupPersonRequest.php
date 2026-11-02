<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * REQ-FAM-UNIT/api.md §8.2, `GET /pickup-persons?document_type=&document_number=`: los dos parámetros son obligatorios
 * (la búsqueda es solo por documento: no hay directorio de personas). El catálogo y el formato los trata
 * `PersonDirectory::findByDocument()`; un documento que no existe o no es válido no devuelve nada.
 */
class FindPickupPersonRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:32'],
            'document_number' => ['required', 'string', 'max:32'],
        ];
    }
}
