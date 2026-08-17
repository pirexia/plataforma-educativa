<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantHost;
use App\Support\Tenancy\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-014 + ADR-033 §2: primero del grupo de rutas de negocio, antes de
 * cualquier acceso a datos. El tenant sale solo del host de la petición.
 *
 * Tenant inexistente → 404. Suspendido → 503. Nunca se distingue de forma
 * observable "no existe" de "existe pero no es tuyo": ambos casos que no
 * sean "activo" devuelven 404, salvo la suspensión, que sí es visible
 * (REQ-BO-001: "Reversible en un clic", el centro necesita saber que está
 * suspendido y no confundirlo con un slug equivocado).
 *
 * La reverificación del tenant contra el de la sesión autenticada (ADR-033
 * §2) queda pendiente de REQ-AUTH (paso 1.2): no hay todavía sesión de
 * usuario que reverificar.
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
        // tenant en este punto de la petición.
        /** @var array{id: int, status: string}|null $cached */
        $cached = Cache::remember(
            "tenant-resolution:{$slug}",
            60,
            function () use ($slug): ?array {
                $tenant = Tenant::findBySlug($slug);

                return $tenant instanceof Tenant
                    ? ['id' => $tenant->id, 'status' => $tenant->status->value]
                    : null;
            },
        );

        if ($cached === null) {
            abort(404);
        }

        $status = TenantStatus::from($cached['status']);

        if ($status === TenantStatus::Suspendido) {
            abort(503, __('tenancy.suspended'));
        }

        if ($status !== TenantStatus::Activo) {
            // en_alta, en_baja, eliminado: no accesible, y no se distingue
            // de "no existe" (INV-002, denegar por defecto).
            abort(404);
        }

        $this->context->enter($cached['id']);

        return $next($request);
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
