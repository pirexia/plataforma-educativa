<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dónde vive "el tenant actual" durante la petición, un job o un comando.
 * ADR-033 sección 3: falla en cerrado. tenantId() nunca devuelve null.
 *
 * Registrado como singleton (TenancyServiceProvider) para que todo el
 * proceso PHP comparta el mismo estado durante su ciclo de vida.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    private readonly string $basePrefix;

    public function __construct()
    {
        $this->basePrefix = (string) config('cache.prefix');
    }

    public function enter(int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->applyToConnection();
        $this->applyCachePrefix();
    }

    public function leave(): void
    {
        $this->tenantId = null;
        $this->applyToConnection();
        $this->applyCachePrefix();
    }

    /**
     * Guarda el tenant anterior, entra en el nuevo, ejecuta y restaura
     * siempre el anterior — incluso si el closure lanza una excepción.
     */
    public function runFor(int $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;

        try {
            $this->enter($tenantId);

            return $callback();
        } finally {
            if ($previous === null) {
                $this->leave();
            } else {
                $this->enter($previous);
            }
        }
    }

    /**
     * @throws TenantContextMissing si no hay tenant activo
     */
    public function tenantId(): int
    {
        return $this->tenantId ?? throw new TenantContextMissing;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Reaplica el GUC de PostgreSQL sobre una conexión con el tenant ya
     * guardado en memoria (o lo limpia si no hay tenant). Se usa tanto desde
     * enter()/leave() como desde el listener de ConnectionEstablished, para
     * que una reconexión no herede el ajuste de una conexión anterior sin
     * que nadie lo haya vuelto a fijar explícitamente.
     */
    public function applyToConnection(?string $connection = null): void
    {
        DB::connection($connection)->statement(
            "select set_config('app.tenant_id', ?, false)",
            [$this->tenantId === null ? '' : (string) $this->tenantId]
        );
    }

    private function applyCachePrefix(): void
    {
        $prefix = $this->tenantId === null
            ? $this->basePrefix
            : "t{$this->tenantId}:";

        config(['cache.prefix' => $prefix]);

        // El store resuelto guarda su prefijo al construirse (Laravel no lo
        // relee de config en cada operación): sin esto, cambiar config()
        // no tiene ningún efecto sobre el store ya instanciado.
        Cache::forgetDriver(config('cache.default'));
    }
}
