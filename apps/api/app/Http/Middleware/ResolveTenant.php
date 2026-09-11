<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantHost;
use App\Support\Tenancy\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-014 + ADR-033 §2: primero del grupo de rutas de negocio, antes de
 * cualquier acceso a datos. El tenant sale solo del host de la petición.
 *
 * REQ-BO/funcional.md §5.4.1, `RN-BO-50` (1.6b): el mapa completo de
 * respuestas por estado. `404` queda reservado a un *host* que no
 * corresponde a **ningún** tenant, vivo ni borrado — nunca se revela qué
 * centros existen ni cuáles han dejado de estarlo. Cualquier estado
 * distinto de `activo` responde `503` con `Retry-After` y su propio
 * mensaje: `en_alta` (aprovisionando), `suspendido` (mensaje configurado
 * o el del catálogo), `en_baja` (cerrando) y `eliminado` (cerrado, para
 * siempre mientras su DNS siga apuntando aquí).
 *
 * La búsqueda incluye los borrados lógicos (`RN-BO-50`): un tenant
 * `eliminado` lleva `deleted_at`, y sin buscarlo entre los borrados el
 * `404` de "no existe" se confundiría con el de "existe pero cerrado". Se
 * prefiere siempre el tenant **vivo** cuando el `slug` está reutilizado
 * (`funcional.md §5.4.2`); entre borrados, el de `deleted_at` más
 * reciente.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = TenantHost::slugFrom($request->getHost());

        if ($slug === null) {
            abort(404);
        }

        // Se cachean solo id y status (array plano), no el modelo Eloquent:
        // serializar el objeto entero en Redis es fràgil de deserializar
        // (puede llegar a fallar con "incomplete object" según el estado
        // de autoload en el momento de leer), y no hace falta nada más del
        // tenant en este punto de la petición. La forma del valor cacheado
        // no cambia en 1.6b (datos.md §6.3): solo cambia la consulta que
        // la rellena y el mapa de respuestas de abajo.
        /** @var array{id: int, status: string}|null $cached */
        $cached = Cache::remember(
            "tenant-resolution:{$slug}",
            60,
            function () use ($slug): ?array {
                $tenant = Tenant::query()->where('slug', $slug)->first()
                    ?? Tenant::onlyTrashed()->where('slug', $slug)->orderByDesc('deleted_at')->first();

                return $tenant instanceof Tenant
                    ? ['id' => $tenant->id, 'status' => $tenant->status->value]
                    : null;
            },
        );

        if ($cached === null) {
            abort(404);
        }

        $status = TenantStatus::from($cached['status']);

        if ($status !== TenantStatus::Activo) {
            $this->abortForInactiveStatus($status, $cached['id']);
        }

        $this->context->enter($cached['id']);

        return $next($request);
    }

    /**
     * @return never
     */
    private function abortForInactiveStatus(TenantStatus $status, int $tenantId): void
    {
        $message = match ($status) {
            TenantStatus::EnAlta => __('tenancy.provisioning'),
            TenantStatus::Suspendido => $this->suspensionMessage($tenantId),
            TenantStatus::EnBaja => __('tenancy.closing'),
            TenantStatus::Eliminado => __('tenancy.closed'),
            TenantStatus::Activo => throw new LogicException('Estado activo no debería llegar aquí.'),
        };

        abort(503, $message, ['Retry-After' => '60']);
    }

    /**
     * `suspension_message` no viaja en la caché (datos.md §6.3): el
     * camino del 503 es frío y una consulta por petición es un coste
     * despreciable en el único caso en que se paga. `withTrashed()` por
     * si el tenant hubiera sido eliminado entre el instante en que se
     * cacheó `suspendido` y ahora (ventana de 60s).
     */
    private function suspensionMessage(int $tenantId): string
    {
        $tenant = Tenant::withTrashed()->where('id', $tenantId)->first();

        if ($tenant === null || $tenant->suspension_message === null) {
            return __('tenancy.suspended');
        }

        return $tenant->suspension_message;
    }

    /**
     * En PHP-FPM clásico (un proceso por petición) esto es irrelevante: el
     * contexto muere con el proceso de todas formas. Se hace explícito
     * igualmente porque es barato y evita que el estado sobreviva a la
     * petición en cualquier entorno de proceso persistente (un futuro
     * Octane, o el propio cliente de test de Laravel, que reutiliza el
     * contenedor entre llamadas dentro de un mismo test).
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->leave();
    }
}
