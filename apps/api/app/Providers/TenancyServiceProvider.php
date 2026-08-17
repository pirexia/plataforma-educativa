<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }
}
