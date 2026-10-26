<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\FamilyUnitService;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.1, `POST /family-units`: `code` opcional. Sin él (ausente o `null`) lo genera el sistema
 * (`funcional.md §15`, D-1).
 */
class StoreFamilyUnitRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{code: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $code = null;

        if (array_key_exists('code', $body) && $body['code'] !== null) {
            $code = self::code($body['code'], $errors);
        }

        $errors->throwIfAny();

        return ['code' => $code];
    }

    /** `code_invalid`: texto de 1 a 40 caracteres sin caracteres de control (`datos.md §1.1`). */
    public static function code(mixed $value, ValidationErrorBag $errors): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== ''
                && mb_check_encoding($trimmed, 'UTF-8')
                && mb_strlen($trimmed) <= FamilyUnitService::CODE_MAX_LENGTH
                && preg_match('/[\x00-\x1F\x7F]/', $trimmed) !== 1) {
                return $trimmed;
            }
        }

        $errors->add('code', 'fam_unit.family_unit.code_invalid', 'fam_unit.family_unit.code_invalid');

        return null;
    }
}
