<?php

namespace App\Modules\Alum\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ALUM/api.md §6, `POST /enrollments/exports`: exactamente los filtros de `GET /enrollments` (`academic_year`,
 * `course`, `group`, `status`) en el cuerpo JSON, sin paginación, `sort` ni `q` (`ADR-054 §8.2`). Con `q` ⇒
 * `422 alum.validation.export_query_not_allowed` (`CA-ALUM-041`). Que `academic_year` exista y sea legible lo comprueba
 * `EnrollmentService::assertExportable()`, igual que el listado.
 */
class ExportEnrollmentsRequest extends AlumRequest
{
    /**
     * @return array{academic_year: ?string, course: ?string, group: ?string, status: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        if (array_key_exists('q', $body)) {
            $errors->add('q', 'alum.validation.export_query_not_allowed', 'alum.validation.export_query_not_allowed');
        }

        $filters = ['academic_year' => null, 'course' => null, 'group' => null, 'status' => null];

        foreach (array_keys($filters) as $field) {
            $value = $body[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_string($value) || $value === '' || mb_strlen($value) > 64) {
                $errors->add($field, 'alum.validation.text_invalid', 'alum.validation.text_invalid');

                continue;
            }

            if ($field === 'status' && ! in_array($value, IndexEnrollmentsRequest::STATUSES, true)) {
                $errors->add($field, 'alum.validation.status_invalid', 'alum.validation.status_invalid');

                continue;
            }

            $filters[$field] = $value;
        }

        $errors->throwIfAny();

        return $filters;
    }
}
