<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\TermsVersionService;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-FAM-UNIT/api.md §2.8, `POST /terms-versions`: `kind`, `title_translations` y `body_translations` (`ADR-062`:
 * contenido del centro; el idioma por defecto del centro es obligatorio y solo se admiten idiomas activos). `kind`
 * ausente ⇒ `validation.required`; fuera del vocabulario o `condiciones_uso` (hasta `1.14-c`) los decide el servicio.
 */
class StoreTermsVersionRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{kind: string, title: array<string, string>, body: array<string, string>}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $kind = $body['kind'] ?? null;

        if ($kind === null || $kind === '') {
            $errors->add('kind', 'fam_unit.validation.required', 'fam_unit.validation.required');
        } elseif (! is_string($kind)) {
            $errors->add('kind', 'fam_unit.terms.kind_invalid', 'fam_unit.terms.kind_invalid');
        }

        $title = $translations->validateForCreate($body['title_translations'] ?? null, 'title_translations', $errors, TermsVersionService::TITLE_MAX_LENGTH);
        $text = $translations->validateForCreate($body['body_translations'] ?? null, 'body_translations', $errors, TermsVersionService::BODY_MAX_LENGTH);

        $errors->throwIfAny();

        return ['kind' => (string) $kind, 'title' => (array) $title, 'body' => (array) $text];
    }
}
