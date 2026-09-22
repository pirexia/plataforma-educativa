<?php

namespace App\Modules\Core\Infrastructure;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * `OPEN-BO-24`, resuelta durante esta implementación por la recomendación
 * **(b)** de `funcional.md §5.11.9.2`: el catálogo completo de *flags*
 * con sus reglas vigentes se cachea en **una sola entrada**, bajo un
 * prefijo global — no bajo `t{tenant_id}:` — para que la lectura no
 * cueste una consulta por *flag* y la escritura no tenga que recorrer el
 * prefijo de los N centros (`CA-BO-088`, `operacion.md §4.3`).
 *
 * **Por qué se salta el mecanismo habitual de `Cache::remember()`/
 * `config('cache.prefix')`, y no es un descuido**: `TenantContext::enter()`
 * fija `cache.prefix` a `t{tenant_id}:` en cada petición de un centro
 * (`ADR-033 §9`) — es exactamente el mecanismo que aísla `modules:{code}:
 * enabled` por tenant (`operacion.md §4.2`). Aplicado aquí produciría una
 * copia idéntica del catálogo por cada uno de los N centros, y CA-BO-088
 * exige lo contrario: invalidar sin recorrer esos N prefijos. Se accede
 * al conector Redis directamente (`REDIS_CACHE_CONNECTION`, la misma
 * conexión que usa el *store* `cache`), con una clave fija que **no**
 * depende del tenant activo — el prefijo de cliente de Redis
 * (`REDIS_PREFIX`, `config('database.redis.options.prefix')`) sigue
 * aplicándose igual, así que no colisiona con otra instalación.
 *
 * Invalidación explícita en cada escritura (`EloquentFeatureFlagAdministration`,
 * `SyncModuleRegistry` al retirar un *flag*), no sólo TTL: borrar una
 * única clave es barato y dentro del presupuesto de una petición
 * (`INV-012`), a diferencia de recorrer N prefijos. **Carrera residual
 * declarada y no cerrada** (mismo criterio que `operacion.md §4.2` punto
 * 3 para `modules:{code}:enabled`): un lector que repuebla la caché entre
 * el borrado y el `COMMIT` de la escritura puede volver a cachear el
 * valor anterior durante el TTL — acotada por `BO_FLAG_CACHE_TTL`, nunca
 * indefinida.
 *
 * **Propiedad que se conserva decida lo que decida esta clase**: una
 * entrada ausente nunca significa «expuesto» — un fallo de Redis, una
 * clave nunca escrita o recién invalidada hacen que el llamador recalcule
 * contra la base de datos, y el cálculo sin reglas da `false` (`RN-BO-35`,
 * `operacion.md §3`).
 */
final class FeatureFlagCatalogCache
{
    private const KEY = 'feature-flags:catalog:v1';

    /**
     * @return array<string, array<string, mixed>>|null indexado por `key` de *flag*
     */
    public function get(): ?array
    {
        $raw = $this->connection()->get(self::KEY);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $snapshot
     */
    public function put(array $snapshot, int $ttlSeconds): void
    {
        $this->connection()->setex(self::KEY, max(1, $ttlSeconds), json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function forget(): void
    {
        $this->connection()->del(self::KEY);
    }

    private function connection(): Connection
    {
        return Redis::connection((string) config('cache.stores.redis.connection', 'cache'));
    }
}
