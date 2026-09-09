<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    private bool $platformMode = false;

    private ?PlatformAccessPurpose $platformPurpose = null;

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
     *
     * Aviso real, no hipotético (encontrado escribiendo los tests de
     * 0.7.7): si el closure termina en `SomeJob::dispatch(...)` como
     * expresión de retorno (`fn () => SomeJob::dispatch(...)`), el envío
     * a la cola ocurre en el __destruct() de PendingDispatch de Laravel, y
     * ese destructor no llega a ejecutarse hasta DESPUÉS del `finally` de
     * este método — el job se etiqueta con el tenant restaurado, no con
     * el que se acaba de fijar. Dentro de runFor()/eachTenant(), despacha
     * siempre como sentencia suelta (`function () { SomeJob::dispatch(...); }`),
     * nunca como valor de retorno del closure.
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
     * ADR-033 §9: toda clave de limitación de tasa incluye el tenant, para
     * que agotar el límite de uno no afecte a los demás. Los valores
     * numéricos de los límites son configurables por tenant (RMT-005,
     * REQ-BO-003) y no existen todavía — esto es solo la clave, no una
     * política. Falla en cerrado: sin tenant activo no hay clave "genérica"
     * que perdonar; decide el llamador si combinarlo con la IP para
     * limitadores que deban aplicar antes de resolver tenant.
     */
    public function rateLimitKey(string $suffix): string
    {
        return "t{$this->tenantId()}:{$suffix}";
    }

    /**
     * ADR-033 §4, ADR-046 §6: la única puerta sancionada para leer entre
     * tenants desde código de negocio. TenantModel deja de aplicar
     * TenantScope y cambia de conexión a pgsql_platform (BYPASSRLS)
     * mientras dure el closure — no una bandera que cualquier ruta de
     * código pueda activar sola, sino un rol de base de datos distinto
     * con credenciales propias.
     *
     * `$purpose` es el primer parámetro y no tiene valor por defecto,
     * deliberadamente (funcional.md §6.2.1): así ninguna llamada existente
     * sigue compilando sin tocarla y ningún llamador futuro hereda un
     * propósito por omisión.
     *
     * Exige ausencia de tenant activo, con cualquier propósito
     * (funcional.md §6.2.4, ADR-046 §6.4): esta implementación no limpia
     * tenantId() al entrar en modo plataforma, así que "modo plataforma"
     * y "tenant activo" a la vez escribiría con el tenant_id equivocado o
     * violaría un NOT NULL, sin TenantScope que filtre y sobre una
     * conexión BYPASSRLS. El acceso a datos de un tenant concreto desde
     * el backoffice se hace fijando tenant_id a mano dentro del bloque
     * (docblock de BelongsToTenant); la invalidación de caché por tenant
     * se hace fuera del bloque, con runFor().
     *
     * La comprobación de si el propósito es alcanzable desde aquí, y la
     * obligación de auditoría al cierre, las delega en PlatformAccessCheck
     * (ADR-046 §6.3): esta clase no puede importar App\Modules\Backoffice
     * (INV-007), así que pregunta en vez de saber.
     *
     * @throws RuntimeException si hay un tenant activo, o si before()/after()
     *                          deniegan el propósito o encuentran una
     *                          obligación incumplida
     */
    public function runAsPlatform(PlatformAccessPurpose $purpose, Closure $callback): mixed
    {
        if ($this->hasTenant()) {
            throw new RuntimeException(
                "runAsPlatform({$purpose->value}) no se puede invocar con un tenant activo ".
                '(ADR-046 §6.4). Sal del contexto de tenant con leave() o fuera del bloque '.
                'de runFor(), o fija tenant_id a mano dentro del bloque de plataforma si '.
                'necesitas escribir un dato de un tenant concreto.'
            );
        }

        app(PlatformAccessCheck::class)->before($purpose);

        $wasPlatformMode = $this->platformMode;
        $previousPurpose = $this->platformPurpose;
        $this->platformMode = true;
        $this->platformPurpose = $purpose;

        try {
            return $callback();
        } finally {
            // after() se llama con el bloque todavía "abierto" (antes de
            // restaurar platformMode/platformPurpose): es simétrico con
            // before(), que se llama antes de abrirlo, y deja que la
            // comprobación observe el mismo estado que vio el callback.
            app(PlatformAccessCheck::class)->after($purpose);

            $this->platformMode = $wasPlatformMode;
            $this->platformPurpose = $previousPurpose;
        }
    }

    public function isPlatformMode(): bool
    {
        return $this->platformMode;
    }

    /**
     * ADR-046 §6.1: null fuera de modo plataforma. Deja que AuditRecorder
     * y los tests ramifiquen por propósito sin adivinarlo.
     */
    public function platformPurpose(): ?PlatformAccessPurpose
    {
        return $this->platformPurpose;
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
