<?php

namespace App\Support\FeatureFlags;

/**
 * INV-007, ADR-056 AR-02: permite a `App\Support` (p. ej. `SyncModuleRegistry`)
 * invalidar la caché del catálogo de *flags* (OPEN-BO-24) sin importar la
 * implementación de `Core\Infrastructure`.
 */
interface FeatureFlagCatalogInvalidator
{
    public function forget(): void;
}
