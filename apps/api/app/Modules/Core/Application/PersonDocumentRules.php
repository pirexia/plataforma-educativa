<?php

namespace App\Modules\Core\Application;

use App\Models\Person;
use App\Modules\Core\Domain\DocumentType;
use App\Support\Api\ValidationErrorBag;

/**
 * RN-CORE-90 a 93 (funcional.md §14.6.4.3) para la API (`POST /users`,
 * `PATCH /users/{id}`): catálogo cerrado con grafía exacta, par tipo-número
 * completo, normalización del número y unicidad sobre el valor
 * normalizado. Un único sitio para `CreateUser` y `UpdateUser`, de modo
 * que alta y edición no puedan divergir (issues #308, #309, #310).
 *
 * El 422 de tipo no admitido **no refleja** el valor recibido (ni en el mensaje ni en
 * `params`): sería texto del cliente devuelto sin sanear (security-reviewer S3).
 *
 * Devuelve los valores **canónicos** que hay que guardar; si añade algún
 * error al `ValidationErrorBag`, los valores devueltos no deben usarse.
 */
final class PersonDocumentRules
{
    public function __construct(
        private readonly DocumentNumberValidator $validator,
    ) {}

    /**
     * @param  ?int  $exceptPersonId  persona excluida de la unicidad (la propia, al editar)
     * @return array{type: ?string, number: ?string}
     */
    public function check(?string $rawType, ?string $rawNumber, ValidationErrorBag $errors, ?int $exceptPersonId = null): array
    {
        if ($rawType === null && $rawNumber === null) {
            return ['type' => null, 'number' => null];
        }

        $type = null;

        if ($rawType !== null) {
            $type = DocumentType::fromCode($rawType);

            if ($type === null) {
                $errors->add('person.document_type', 'core.validation.document_type_invalid', 'core.validation.document_type_invalid');

                return ['type' => null, 'number' => null];
            }
        }

        // RN-CORE-91 (OPEN-CORE-48 = A): los dos o ninguno.
        if ($type === null || $rawNumber === null) {
            $missing = $type === null ? 'person.document_type' : 'person.document_number';
            $errors->add($missing, 'core.validation.document_incomplete', 'core.validation.document_incomplete');

            return ['type' => null, 'number' => null];
        }

        if (! $this->validator->isValid($type, $rawNumber)) {
            $errors->add('person.document_number', 'core.validation.document_number_invalid', 'core.validation.document_number_invalid');

            return ['type' => null, 'number' => null];
        }

        $number = $this->validator->normalize($type, $rawNumber);

        $duplicate = Person::query()
            ->where('document_type', $type->value)
            ->where('document_number', $number)
            ->when($exceptPersonId !== null, fn ($query) => $query->whereKeyNot($exceptPersonId))
            ->exists();

        if ($duplicate) {
            $errors->add('person.document_number', 'core.validation.document_duplicate', 'core.validation.document_duplicate');

            return ['type' => null, 'number' => null];
        }

        return ['type' => $type->value, 'number' => $number];
    }
}
