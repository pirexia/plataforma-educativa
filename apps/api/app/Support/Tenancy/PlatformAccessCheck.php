<?php

namespace App\Support\Tenancy;

/**
 * ADR-046 §6.3. `TenantContext` no puede importar `App\Modules\Backoffice`
 * (INV-007): no sabe qué es un administrador de plataforma, lo pregunta
 * a través de esta interfaz. El enlace por defecto (`DefaultPlatformAccessCheck`)
 * deniega los dos propósitos de backoffice; `App\Modules\Backoffice` lo
 * sustituye por una implementación que sí sabe consultar el guard
 * `platform` y `admin_action_logs`.
 */
interface PlatformAccessCheck
{
    /**
     * Antes de abrir el bloque. Lanza si el propósito no es alcanzable
     * desde donde se invoca.
     */
    public function before(PlatformAccessPurpose $purpose): void;

    /**
     * Al cerrar el bloque, también si el callback lanzó (se llama desde
     * un `finally`). Lanza si quedó una obligación sin cumplir.
     */
    public function after(PlatformAccessPurpose $purpose): void;
}
