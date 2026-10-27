<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.7, `POST /image-consent-decisions`: `student_person`, `level` y `decision` obligatorios;
 * `on_behalf_of` (`public_id` del tutor que decidió, solo el centro), `channel` (si viene, debe corresponder al modo:
 * `portal` para el propio tutor, `presencial` en nombre de un tutor) y `consent_text_version` (solo `presencial`). Solo
 * la forma; las reglas de negocio (`RN-FAM-UNIT-41`, `-68`, `-69`) las aplica el servidor en el servicio (`INV-010`).
 */
class StoreImageConsentDecisionRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{student_person: string, level: string, decision: string, on_behalf_of: ?string, channel: ?string, consent_text_version: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $student = self::requiredReference($body, 'student_person', $errors);
        $level = self::requiredEnum($body, 'level', ImageConsentDecision::LEVELS, 'fam_unit.validation.level_invalid', $errors);
        $decision = self::requiredEnum($body, 'decision', ImageConsentDecision::DECISIONS, 'fam_unit.validation.decision_invalid', $errors);
        $channel = array_key_exists('channel', $body) && $body['channel'] !== null
            ? self::optionalEnum($body, 'channel', ImageConsentDecision::CHANNELS, 'fam_unit.validation.channel_invalid', $errors)
            : null;
        $onBehalfOf = self::optionalReference($body, 'on_behalf_of', $errors);
        $version = self::optionalReference($body, 'consent_text_version', $errors);

        $errors->throwIfAny();

        return [
            'student_person' => (string) $student,
            'level' => (string) $level,
            'decision' => (string) $decision,
            'on_behalf_of' => $onBehalfOf,
            'channel' => $channel,
            'consent_text_version' => $version,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     */
    private static function requiredEnum(array $body, string $field, array $allowed, string $errorKey, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null || $body[$field] === '') {
            $errors->add($field, 'fam_unit.validation.required', 'fam_unit.validation.required');

            return null;
        }

        return self::optionalEnum($body, $field, $allowed, $errorKey, $errors);
    }

    /**
     * Referencia opcional: ausente o `null` ⇒ `null`; presente, un `public_id` ULID.
     *
     * @param  array<string, mixed>  $body
     */
    private static function optionalReference(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null) {
            return null;
        }

        if (! self::isUlid($body[$field])) {
            $errors->add($field, 'fam_unit.validation.reference_invalid', 'fam_unit.validation.reference_invalid');

            return null;
        }

        return $body[$field];
    }
}
