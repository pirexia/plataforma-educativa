<?php

namespace App\Modules\Alum\Http\Requests;

/**
 * Base de las peticiones de escritura de matrícula (`api.md §2`). Solo extrae las claves que el endpoint admite y las
 * pasa tal cual: la validación de negocio (`INV-010`) y el orden de errores (404 antes que 422) son del
 * `EnrollmentService`, que además distingue una clave ausente de un `null`.
 */
abstract class EnrollmentBodyRequest extends AlumRequest
{
    /** @return list<string> */
    abstract protected function allowedKeys(): array;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_intersect_key($this->body(), array_flip($this->allowedKeys()));
    }
}
