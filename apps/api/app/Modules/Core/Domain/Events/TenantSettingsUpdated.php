<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Cambio de configuración del centro. Consumidores
 * previstos: invalidación de caché (ya cubierta por
 * `TenantSettingsCache::forget()` en el propio controlador, no por este
 * evento), `REQ-CALIF`/`REQ-ECON` (moneda, idioma de documentos).
 */
final class TenantSettingsUpdated
{
    public function __construct(
        public readonly int $tenantId,
    ) {}
}
