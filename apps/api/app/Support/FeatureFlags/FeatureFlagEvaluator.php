<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8, §5.11.8.1. Precedente literal y verificado:
 * `App\Support\Modules\ModuleAvailability`. Esta interfaz la posee
 * `REQ-CORE` (la implementa `EloquentFeatureFlagEvaluator`); cualquier
 * módulo del producto la consume sin importar nada de
 * `App\Modules\Core` (INV-007).
 *
 * Una sola operación, un solo argumento y ningún sujeto: el tenant y el
 * usuario se toman del contexto (`TenantContext`, el guard `web`), nunca
 * de un parámetro — `INV-001` no se sostiene sobre que nadie pase un
 * segundo argumento (`RN-BO-100`). No hay forma de evaluar para otro
 * centro desde el camino de petición: para eso está `FeatureFlagExplainer`,
 * que exige un sujeto explícito y cuyo enlace por defecto deniega fuera
 * del backoffice (§5.11.8.2).
 */
interface FeatureFlagEvaluator
{
    /**
     * Falla en cerrado sin contexto de tenant activo: `false`, nunca el
     * valor de otro tenant y nunca una excepción (`RN-BO-100`). Una clave
     * desconocida en el catálogo, un *flag* `retired_at`, o un *flag* en
     * `forced_off` también dan `false` (`RN-BO-35`, `RN-BO-44`,
     * `RN-BO-37`). Perezoso: no ejecuta ni una consulta ni una lectura de
     * caché si nadie llama a este método (`RN-BO-106`).
     */
    public function isEnabled(string $flagKey): bool;
}
