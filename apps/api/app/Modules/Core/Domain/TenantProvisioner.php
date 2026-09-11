<?php

namespace App\Modules\Core\Domain;

use App\Support\Tenancy\Tenant;

/**
 * ADR-048 §4.1: contrato síncrono, no evento. `REQ-BO` (1.6b) lo consume
 * desde sus trabajos en cola para el alta y la clonación de un tenant sin
 * importar código interno de `REQ-CORE` (INV-007) — el mismo patrón que
 * ya usan `TenantSettingsReader`, `UserDirectory` e `InvitationRedeemer`
 * desde `REQ-AUTH` (ADR-048 §1.2).
 *
 * `Tenant` es `App\Support\Tenancy\Tenant` (núcleo compartido, no un
 * modelo de `REQ-CORE`): pasarlo no cruza ninguna frontera.
 *
 * La asincronía la pone el llamador con su propio trabajo en cola
 * (`INV-012`), nunca este contrato: `ProvisionTenant`/`CloneTenant` en
 * `REQ-BO` son quienes encolan, no `TenantProvisioner`.
 */
interface TenantProvisioner
{
    /**
     * Aprovisiona un tenant recién creado: configuración inicial, los 16
     * roles predefinidos y sus concesiones, y el primer Administrador de
     * Centro, invitado. Idempotente (funcional.md §5.3.3): una segunda
     * llamada sobre un tenant ya aprovisionado no duplica nada y responde
     * `AlreadyProvisioned`.
     */
    public function provision(
        Tenant $tenant,
        TenantInitialSettings $settings,
        TenantAdministrator $administrator,
    ): TenantProvisioningOutcome;

    /**
     * Aprovisiona `$target` copiando de `$source` su configuración
     * operativa, sus roles —predefinidos y personalizados— y su matriz de
     * concesiones (RN-BO-59, funcional.md §5.6.2). El administrador **no**
     * se copia: se crea nuevo a partir de `$administrator` (RN-BO-21).
     *
     * La lectura de `$source` ocurre en un único punto en el tiempo,
     * dentro de la misma operación que la escritura de `$target`
     * (funcional.md §5.6.4): entre las dos no hay ventana en la que un
     * cambio concurrente en el origen produzca un clon mitad viejo, mitad
     * nuevo. Idempotente por la misma comprobación que `provision()`.
     */
    public function provisionFromTemplate(
        Tenant $source,
        Tenant $target,
        TenantAdministrator $administrator,
    ): TenantProvisioningOutcome;
}
