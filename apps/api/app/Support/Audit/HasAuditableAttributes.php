<?php

namespace App\Support\Audit;

/**
 * ADR-035 §2: implementación de las dos propiedades de Auditable. No
 * declara las propiedades aquí a propósito — PHP 8.3+ trata como error
 * fatal (no como el solapamiento permitido de siempre) que una clase
 * redeclare con un valor por defecto distinto una propiedad *typed* ya
 * declarada por un trait. Cada modelo que use este trait declara sus dos
 * propiedades en su propio cuerpo de clase (array vacío para Full, que no
 * las consulta pero las declara igual por explicitud).
 */
trait HasAuditableAttributes
{
    /** @return array<int, string> */
    public function auditRecordedAttributes(): array
    {
        return $this->auditRecordedAttributes;
    }

    /** @return array<int, string> */
    public function auditSecretAttributes(): array
    {
        return $this->auditSecretAttributes;
    }
}
