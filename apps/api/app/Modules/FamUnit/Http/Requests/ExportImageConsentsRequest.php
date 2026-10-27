<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §4, `POST /image-consents/exports`: paridad de filtros con `GET /image-consents` (`family_unit`,
 * `student_person`, `level`, `state`), sin paginación ni orden y **sin `q`** (`422 fam_unit.export.q_not_allowed`,
 * `ADR-054 §9`). Cada filtro admite una lista JSON o un texto de valores separados por comas.
 */
class ExportImageConsentsRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{family_unit: list<string>, student_person: list<string>, level: list<string>, state: list<string>}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        if (array_key_exists('q', $body)) {
            $errors->add('q', 'fam_unit.export.q_not_allowed', 'fam_unit.export.q_not_allowed');
        }

        $filters = [
            'family_unit' => self::references($body, 'family_unit', $errors),
            'student_person' => self::references($body, 'student_person', $errors),
            'level' => self::vocabulary($body, 'level', ImageConsentDecision::LEVELS, 'fam_unit.validation.level_invalid', $errors),
            'state' => self::vocabulary($body, 'state', ImageConsentService::STATES, 'fam_unit.validation.state_invalid', $errors),
        ];

        $errors->throwIfAny();

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private static function list(array $body, string $field): array
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        }

        return is_array($value) && array_is_list($value) ? array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : "\0", $value) : ["\0"];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private static function references(array $body, string $field, ValidationErrorBag $errors): array
    {
        $items = self::list($body, $field);

        foreach ($items as $item) {
            if (! self::isUlid($item)) {
                $errors->add($field, 'fam_unit.validation.reference_invalid', 'fam_unit.validation.reference_invalid');

                return [];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function vocabulary(array $body, string $field, array $allowed, string $errorKey, ValidationErrorBag $errors): array
    {
        $items = self::list($body, $field);

        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                $errors->add($field, $errorKey, $errorKey);

                return [];
            }
        }

        return array_values(array_unique($items));
    }
}
