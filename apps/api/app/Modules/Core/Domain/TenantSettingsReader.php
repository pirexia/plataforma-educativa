<?php

namespace App\Modules\Core\Domain;

/**
 * funcional.md §7: interfaz pública para que cualquier módulo que genere
 * documentos o importes conozca el idioma y la moneda del centro sin
 * consultar `tenant_settings` directamente (INV-007).
 */
interface TenantSettingsReader
{
    public function defaultLocale(): string;

    /** @return list<string> */
    public function activeLocales(): array;

    public function timezone(): string;

    public function currency(): string;

    /**
     * REQ-AUTH/funcional.md §8.1, §1.4. Minutos de inactividad tras los
     * que la sesión expira (REQ-AUTH-005 punto 1).
     */
    public function sessionTimeoutMinutes(): int;
}
