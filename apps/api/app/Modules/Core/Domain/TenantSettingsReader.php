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

    /**
     * REQ-AUTH/funcional.md §C.4.12, RN-AUTH-69. Métodos de MFA que el
     * tenant admite hoy. Nunca vacío, siempre contiene `totp`, nunca
     * contiene `sms` (garantizado por el `CHECK` de datos.md §C.7.2).
     *
     * @return list<string>
     */
    public function mfaAllowedMethods(): array;

    /**
     * REQ-AUTH/funcional.md §C.4.8, RN-AUTH-65. Días de gracia tras
     * empezar a estar obligado a MFA.
     */
    public function mfaGracePeriodDays(): int;
}
