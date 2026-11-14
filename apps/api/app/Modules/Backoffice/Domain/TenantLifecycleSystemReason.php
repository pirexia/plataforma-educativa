<?php

namespace App\Modules\Backoffice\Domain;

/**
 * RN-BO-54: las transiciones con `actor_type = 'system'` escriben en
 * `reason` una CLAVE del catálogo de traducción, nunca una frase —
 * `tenant_lifecycle_events.reason` es `NOT NULL` y esas transiciones no
 * tienen operador que lo redacte, así que una frase escrita en el código
 * sería un literal visible (INV-009). El motivo escrito por una persona
 * sigue siendo texto libre; esto no le aplica.
 *
 * En 1.6b la única transición con actor `system` es `en_alta` → `activo`
 * (la produce `ProvisionTenant` al terminar sin error, RN-BO-52).
 */
enum TenantLifecycleSystemReason: string
{
    case Provisioned = 'bo.tenant_lifecycle.reason.provisioned';
}
