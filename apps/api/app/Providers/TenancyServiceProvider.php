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

        // Pila de contextos previos, no un simple "leave()" (hallazgo
        // propio, severidad Alta, corregido en la misma sesión —
        // REQ-CORE-1.1, primer job de negocio real del proyecto,
        // SendInvitationEmail): con QUEUE_CONNECTION=sync (forzado en
        // phpunit.xml para tests, y opción real de despliegue) un job se
        // ejecuta en línea dentro de la propia petición HTTP que lo
        // despachó. Un "leave()" incondicional al terminar el job vaciaba
        // el contexto de tenant para el RESTO de esa petición —
        // exactamente lo que un worker real (sin contexto ambiente que
        // preservar) necesita, pero lo contrario de lo que necesita una
        // petición HTTP que sigue usando datos de tenant después de
        // despachar. Guardar y restaurar el contexto anterior (en vez de
        // limpiarlo sin más) sirve a los dos casos: un worker real no
        // tiene contexto previo (se restaura a "sin tenant", igual que
        // antes) y una petición síncrona recupera el suyo.
        $previousContexts = [];

        Event::listen(function (JobProcessing $event) use (&$previousContexts): void {
            $context = $this->app->make(TenantContext::class);
            $previousContexts[] = $context->hasTenant() ? $context->tenantId() : null;

            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            if (is_int($tenantId)) {
                $context->enter($tenantId);
            }
        });

        $restorePreviousContext = function () use (&$previousContexts): void {
            $context = $this->app->make(TenantContext::class);
            $previous = array_pop($previousContexts);

            if ($previous === null) {
                $context->leave();
            } else {
                $context->enter($previous);
            }
        };

        Event::listen(JobProcessed::class, $restorePreviousContext);
        Event::listen(JobFailed::class, $restorePreviousContext);
        Event::listen(JobExceptionOccurred::class, $restorePreviousContext);

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
