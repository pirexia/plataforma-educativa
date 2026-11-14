<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-048 §4.2. Objeto de valor con los datos del primer Administrador de
 * Centro que `TenantProvisioner` crea. Separado de `TenantInitialSettings`
 * a propósito: es lo que hace posible `provisionFromTemplate()` sin
 * duplicar nada — el clon no trae ajustes (los hereda del origen) pero sí
 * trae administrador, y un único objeto «petición de aprovisionamiento»
 * obligaría a un campo opcional que a veces es obligatorio.
 */
final readonly class TenantAdministrator
{
    public function __construct(
        public string $email,
        public string $givenName,
        public string $familyName,
    ) {}
}
