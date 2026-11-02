<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Support\Api\ValidationErrorBag;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * REQ-FAM-UNIT/api.md §8.3, `POST /pickup-authorizations`: solo la **forma** del cuerpo. `student_person`, `person`,
 * `authorized_by` y `terms_version` son `public_id` ULID obligatorios; `relationship`, `kind` y `channel` pertenecen a su
 * vocabulario cerrado (`channel` fuera de `presencial` y `telefono` ⇒ `validation.channel_invalid`); `valid_from` es una fecha
 * civil `Y-m-d` y `valid_until`, nula o una fecha no anterior; `reason` es texto de hasta 500 caracteres, obligatorio en una
 * `puntual`; `authorized_at` es un instante ISO 8601 opcional. En `puntual`, `valid_until` se omite o es igual a
 * `valid_from`. Las reglas de negocio (`RN-FAM-UNIT-85`, `-86`, `-88`, `telefono` solo con `puntual`) son del servicio
 * (`INV-010`).
 */
class StorePickupAuthorizationRequest extends FamUnitRequest
{
    public const REASON_MAX_LENGTH = 500;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{student_person: string, person: string, relationship: string, kind: string, valid_from: string, valid_until: ?string, reason: ?string, authorized_by: string, terms_version: string, channel: string, authorized_at: ?CarbonImmutable}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $studentPerson = self::requiredReference($body, 'student_person', $errors);
        $person = self::requiredReference($body, 'person', $errors);
        $authorizedBy = self::requiredReference($body, 'authorized_by', $errors);
        $termsVersion = self::requiredReference($body, 'terms_version', $errors);
        $relationship = self::requiredEnum($body, 'relationship', PickupAuthorization::RELATIONSHIPS, 'fam_unit.validation.pickup_relationship_invalid', $errors);
        $kind = self::requiredEnum($body, 'kind', PickupAuthorization::KINDS, 'fam_unit.validation.pickup_kind_invalid', $errors);
        $channel = self::requiredEnum($body, 'channel', PickupAuthorization::CHANNELS, 'fam_unit.validation.channel_invalid', $errors);
        $validFrom = self::requiredDate($body, 'valid_from', $errors);
        $validUntil = self::optionalDate($body, 'valid_until', $errors);
        $reason = self::optionalReason($body, $errors);
        $authorizedAt = self::optionalInstant($body, 'authorized_at', $errors);

        if ($validFrom !== null && $validUntil !== null) {
            if ($kind === PickupAuthorization::KIND_PUNCTUAL ? $validUntil !== $validFrom : $validUntil < $validFrom) {
                $errors->add('valid_until', 'fam_unit.validation.date_range_invalid', 'fam_unit.validation.date_range_invalid');
            }
        }

        if ($kind === PickupAuthorization::KIND_PUNCTUAL && $reason === null && ! array_key_exists('reason', $errors->toArray())) {
            $errors->add('reason', 'fam_unit.validation.required', 'fam_unit.validation.required');
        }

        $errors->throwIfAny();

        return [
            'student_person' => (string) $studentPerson,
            'person' => (string) $person,
            'relationship' => (string) $relationship,
            'kind' => (string) $kind,
            'valid_from' => (string) $validFrom,
            'valid_until' => $validUntil,
            'reason' => $reason,
            'authorized_by' => (string) $authorizedBy,
            'terms_version' => (string) $termsVersion,
            'channel' => (string) $channel,
            'authorized_at' => $authorizedAt,
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

    /** Fecha civil `Y-m-d` (calendario válido). */
    public static function isCivilDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param  array<string, mixed>  $body */
    private static function requiredDate(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null || $body[$field] === '') {
            $errors->add($field, 'fam_unit.validation.required', 'fam_unit.validation.required');

            return null;
        }

        return self::optionalDate($body, $field, $errors);
    }

    /** @param  array<string, mixed>  $body */
    private static function optionalDate(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null) {
            return null;
        }

        if (! self::isCivilDate($body[$field])) {
            $errors->add($field, 'fam_unit.validation.date_invalid', 'fam_unit.validation.date_invalid');

            return null;
        }

        return $body[$field];
    }

    /** @param  array<string, mixed>  $body */
    private static function optionalReason(array $body, ValidationErrorBag $errors): ?string
    {
        $reason = $body['reason'] ?? null;

        if ($reason === null) {
            return null;
        }

        if (! is_string($reason) || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $errors->add('reason', 'fam_unit.validation.reason_too_long', 'fam_unit.validation.reason_too_long', ['max' => self::REASON_MAX_LENGTH]);

            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }

    /** @param  array<string, mixed>  $body */
    private static function optionalInstant(array $body, string $field, ValidationErrorBag $errors): ?CarbonImmutable
    {
        if (($body[$field] ?? null) === null) {
            return null;
        }

        $value = $body[$field];

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            $errors->add($field, 'fam_unit.validation.datetime_invalid', 'fam_unit.validation.datetime_invalid');

            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $errors->add($field, 'fam_unit.validation.datetime_invalid', 'fam_unit.validation.datetime_invalid');

            return null;
        }
    }
}
