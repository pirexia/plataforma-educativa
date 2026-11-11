<?php

namespace App\Modules\Alum\Http\Requests;

/**
 * Base de las peticiones de escritura de solicitudes de cambio de grupo (`api.md §3`, 1.15-c). Solo extrae las claves que
 * el endpoint admite y las pasa tal cual: la validación de negocio (`INV-010`, texto 1-500) y el orden de errores (404 antes
 * que 422) son del `GroupChangeService`.
 */
abstract class GroupChangeBodyRequest extends AlumRequest
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
