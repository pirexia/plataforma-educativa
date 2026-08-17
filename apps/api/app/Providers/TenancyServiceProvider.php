<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Infraestructura de aislamiento multi-tenant (ADR-033). No es un bounded
 * context de negocio (INV-007): vive en app/Providers, no en app/Modules,
 * y se registra a mano en bootstrap/providers.php.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantStorage::class);
    }

    public function boot(): void
    {
        // PHP-FPM no reutiliza conexiones entre peticiones, pero un worker
        // de colas sí: si la conexión se cae y Laravel reconecta a mitad de
        // proceso, el GUC de la conexión anterior se ha perdido. Sin este
        // listener, esa reconexión quedaría sin tenant hasta la siguiente
        // llamada a enter(), leyendo con el filtro de RLS vacío.
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName === 'pgsql') {
                $this->app->make(TenantContext::class)->applyToConnection('pgsql');
            }
        });

        $this->registerTenantAwareQueues();
    }

    /**
     * ADR-033 §8. El worker de colas es un proceso de larga vida que
     * procesa jobs de tenants distintos en secuencia: es el vector de fuga
     * más probable de todo el sistema. Nadie tiene que acordarse de nada
     * al despachar un job (createPayloadUsing estampa el tenant solo);
     * entrar y salir del contexto es responsabilidad del propio mecanismo
     * de colas, no del código de cada job.
     */
    private function registerTenantAwareQueues(): void
    {
        // Queue::$createPayloadCallbacks es un array estático de la clase,
        // no atado al contenedor: si la aplicación se reconstruye dentro
        // del mismo proceso (los tests de Laravel lo hacen en cada método;
        // en producción, un futuro Octane), este boot() se ejecuta otra
        // vez y el closure anterior queda registrado igual, atado a un
        // contenedor ya viejo — createPayloadUsing(null) lo limpia antes
        // de volver a registrar, para que solo exista uno vivo siempre.
        Queue::createPayloadUsing(null);

        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return ['tenant_id' => $context->hasTenant() ? $context->tenantId() : null];
        });

        Event::listen(function (JobProcessing $event): void {
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            if (is_int($tenantId)) {
                $this->app->make(TenantContext::class)->enter($tenantId);
            }
        });

        $leaveAfterJob = function (): void {
            $this->app->make(TenantContext::class)->leave();
        };

        Event::listen(JobProcessed::class, $leaveAfterJob);
        Event::listen(JobFailed::class, $leaveAfterJob);
        Event::listen(JobExceptionOccurred::class, $leaveAfterJob);

        // Preferimos un worker caído a un job que arranca con el tenant de
        // la iteración anterior porque algo se saltó la salida.
        Queue::looping(function (): void {
            if ($this->app->make(TenantContext::class)->hasTenant()) {
                throw new RuntimeException(
                    'TenantContext no estaba limpio al empezar un nuevo ciclo del '.
                    'worker de colas (ADR-033 §8). Abortando antes de procesar el '.
                    'siguiente job con el tenant equivocado.'
                );
            }
        });
    }
}
