<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Application\DocumentNumberValidator;
use App\Modules\Core\Application\PersonDocumentRules;
use App\Modules\Core\Domain\DocumentType;
use App\Modules\Core\Domain\PersonCorrection;
use App\Modules\Core\Domain\PersonDataCorrection;
use App\Modules\Core\Domain\PersonNotCorrectableException;
use App\Modules\Core\Domain\PersonRegistrar;
use App\Modules\Core\Domain\PersonRegistration;
use App\Modules\Core\Domain\PersonRegistrationOutcome;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR-068 §5.2 (D2, `OPEN-068-02`, `OPEN-068-07`). `Person::query()` aplica `TenantScope` y excluye los borrados; RLS lo
 * refuerza (`INV-001`). La creación y la corrección las audita el ciclo de vida de `Person` (`INV-003`).
 *
 * **Reutilizar no modifica**: la persona existente no se toca nunca, ni siquiera para rellenar un hueco (`RN-FAM-UNIT-89`).
 * La carrera entre dos altas simultáneas con el mismo documento la cierra el índice único parcial
 * `people_tenant_document_unique`: la inserción va en un punto de guardado (transacción anidada), y la que pierde relee y
 * reutiliza. La validación del documento es la de `PersonDocumentRules` (catálogo, par completo, formato y dígito de
 * control) **sin** su comprobación de unicidad, porque aquí un documento ya existente no es un error sino la clave de
 * reutilización.
 */
final class EloquentPersonRegistrar implements PersonRegistrar
{
    public function __construct(
        private readonly DocumentNumberValidator $documents,
        private readonly TenantSettingsReader $settings,
        private readonly PersonDocumentRules $documentRules,
    ) {}

    public function registerOrReuse(PersonRegistration $data): PersonRegistrationOutcome
    {
        $givenName = trim($data->givenName);
        $familyName1 = trim($data->familyName1);

        // Nombre y primer apellido son obligatorios: lo valida el *endpoint* del módulo; aquí es una guarda de contrato.
        if ($givenName === '' || $familyName1 === '') {
            throw new InvalidArgumentException('El nombre y el primer apellido de una persona son obligatorios.');
        }

        $errors = new ValidationErrorBag;
        [$type, $number] = $this->canonicalDocument($data->documentType, $data->documentNumber, $errors);
        $errors->throwIfAny();

        return DB::transaction(function () use ($data, $givenName, $familyName1, $type, $number): PersonRegistrationOutcome {
            if ($type !== null) {
                $existing = $this->liveByDocument($type, $number);

                if ($existing !== null) {
                    return $this->reuse($existing, $data, $givenName, $familyName1);
                }
            }

            try {
                // Punto de guardado: una violación del índice único aborta solo este tramo, no la transacción exterior.
                return DB::transaction(fn (): PersonRegistrationOutcome => $this->create($data, $givenName, $familyName1, $type, $number));
            } catch (UniqueConstraintViolationException $e) {
                $existing = $type === null ? null : $this->liveByDocument($type, $number);

                if ($existing === null) {
                    throw $e;
                }

                return $this->reuse($existing, $data, $givenName, $familyName1);
            }
        });
    }

    public function correctUnaccounted(int $personId, PersonCorrection $data): void
    {
        DB::transaction(function () use ($personId, $data): void {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->first();

            // Sin persona viva del centro, o con usuario vivo: no se corrige por aquí (se corrige por `/users`).
            if ($person === null || User::query()->where('person_id', $personId)->exists()) {
                throw new PersonNotCorrectableException;
            }

            foreach ($data->changes as $column => $value) {
                $person->{$column} = $value === null ? null : trim($value);
            }

            $person->save();
        });
    }

    public function correctPerson(int $personId, PersonDataCorrection $data): void
    {
        DB::transaction(function () use ($personId, $data): void {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->first();

            if ($person === null) {
                throw new PersonNotCorrectableException;
            }

            $changes = $data->changes;
            $errors = new ValidationErrorBag;

            // Mismas reglas que `UpdateUser` (RN-CORE-93): el par de documento resultante, no cada campo suelto.
            $typeProvided = array_key_exists('document_type', $changes);
            $numberProvided = array_key_exists('document_number', $changes);
            $document = null;

            if ($typeProvided || $numberProvided) {
                $document = $this->documentRules->check(
                    $typeProvided ? $changes['document_type'] : $person->document_type,
                    $numberProvided ? $changes['document_number'] : $person->document_number,
                    $errors,
                    $personId,
                );
            }

            if (array_key_exists('locale', $changes) && $changes['locale'] !== null && ! in_array($changes['locale'], $this->settings->activeLocales(), true)) {
                $errors->add('locale', 'core.validation.locale_not_active', 'core.validation.locale_not_active', ['locale' => $changes['locale']]);
            }

            $errors->throwIfAny();

            foreach ($changes as $column => $value) {
                $person->{$column} = $value === null ? null : trim($value);
            }

            if ($document !== null) {
                // Valor canónico (RN-CORE-92) para los campos enviados.
                if ($typeProvided) {
                    $person->document_type = $document['type'];
                }

                if ($numberProvided) {
                    $person->document_number = $document['number'];
                }
            }

            $person->save();
        });
    }

    private function create(PersonRegistration $data, string $givenName, string $familyName1, ?string $type, ?string $number): PersonRegistrationOutcome
    {
        $person = Person::create([
            'given_name' => $givenName,
            'family_name_1' => $familyName1,
            'family_name_2' => $this->clean($data->familyName2),
            'document_type' => $type,
            'document_number' => $number,
            'contact_phone' => $this->clean($data->contactPhone),
            'locale' => $this->settings->defaultLocale(),
        ]);

        return new PersonRegistrationOutcome((int) $person->id, (string) $person->public_id, false, false);
    }

    private function reuse(Person $existing, PersonRegistration $data, string $givenName, string $familyName1): PersonRegistrationOutcome
    {
        // Solo cuentan los datos aportados: no se avisa de que falte un segundo apellido o un teléfono que no se dio.
        $familyName2 = $this->clean($data->familyName2);
        $phone = $this->clean($data->contactPhone);

        $mismatch = trim((string) $existing->given_name) !== $givenName
            || trim((string) $existing->family_name_1) !== $familyName1
            || ($familyName2 !== null && trim((string) $existing->family_name_2) !== $familyName2)
            || ($phone !== null && trim((string) $existing->contact_phone) !== $phone);

        return new PersonRegistrationOutcome((int) $existing->id, (string) $existing->public_id, true, $mismatch);
    }

    private function liveByDocument(string $type, ?string $number): ?Person
    {
        return Person::query()->where('document_type', $type)->where('document_number', $number)->first();
    }

    /**
     * `RN-CORE-90` a `-92`: catálogo cerrado, par completo, formato y dígito de control, y valor canónico. Sin la
     * comprobación de unicidad de `PersonDocumentRules` (aquí un duplicado es la clave de reutilización).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function canonicalDocument(?string $rawType, ?string $rawNumber, ValidationErrorBag $errors): array
    {
        if ($rawType === null && $rawNumber === null) {
            return [null, null];
        }

        $type = DocumentType::fromCode($rawType);

        if ($rawType !== null && $type === null) {
            $errors->add('document_type', 'core.validation.document_type_invalid', 'core.validation.document_type_invalid');

            return [null, null];
        }

        if ($type === null || $rawNumber === null) {
            $missing = $type === null ? 'document_type' : 'document_number';
            $errors->add($missing, 'core.validation.document_incomplete', 'core.validation.document_incomplete');

            return [null, null];
        }

        if (! $this->documents->isValid($type, $rawNumber)) {
            $errors->add('document_number', 'core.validation.document_number_invalid', 'core.validation.document_number_invalid');

            return [null, null];
        }

        return [$type->value, $this->documents->normalize($type, $rawNumber)];
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
