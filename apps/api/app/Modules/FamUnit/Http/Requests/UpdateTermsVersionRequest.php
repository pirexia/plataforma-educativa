<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\TermsVersionService;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-FAM-UNIT/api.md §2.8, `PATCH /terms-versions/{public_id}`: `title_translations` y/o `body_translations`, por
 * fusión (`ADR-038 §9.2` regla 4, `ADR-062 §5.2`). `kind` es inmutable tras la creación (`422 terms.kind_not_editable`,
 * `RN-FAM-UNIT-73`). Un `PATCH` sin ningún campo editable responde `200` sin cambios.
 */
class UpdateTermsVersionRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $storedTitle
     * @param  array<string, mixed>  $storedBody
     * @return array{title: ?array<string, string>, body: ?array<string, string>}
     */
    public function payload(TranslationsValidator $translations, array $storedTitle, array $storedBody): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $this->rejectFields($body, ['kind'], 'fam_unit.terms.kind_not_editable', $errors);

        $title = array_key_exists('title_translations', $body)
            ? $translations->validateForPatch($body['title_translations'], $storedTitle, 'title_translations', $errors, TermsVersionService::TITLE_MAX_LENGTH)
            : null;
        $text = array_key_exists('body_translations', $body)
            ? $translations->validateForPatch($body['body_translations'], $storedBody, 'body_translations', $errors, TermsVersionService::BODY_MAX_LENGTH)
            : null;

        $errors->throwIfAny();

        return ['title' => $title, 'body' => $text];
    }
}
