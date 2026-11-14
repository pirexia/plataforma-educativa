<?php

namespace App\Support\Http;

/**
 * INV-013: cada petición lleva un request_id propagado a logs y trazas.
 * Singleton por ciclo de vida del proceso (igual que TenantContext):
 * AssignRequestId lo fija al principio de la petición HTTP; en consola o
 * jobs se queda a null, que es el valor correcto (no hay petición).
 */
final class RequestId
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public function current(): ?string
    {
        return $this->id;
    }
}
