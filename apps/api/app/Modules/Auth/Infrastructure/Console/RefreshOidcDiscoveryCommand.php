<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Application\DiscoveryRefreshService;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `operacion.md §F.4`. Diaria. Para cada proveedor **activo** cuyo
 * `discovery_fetched_at` sea anterior a `AUTH_SSO_DISCOVERY_REFRESH_DAYS`,
 * revalida el documento con las mismas cinco guardas que el alta y
 * actualiza los *endpoints*. Si falla, conserva los anteriores: un
 * emisor momentáneamente inalcanzable no deja a un centro sin SSO.
 * Programado desde `routes/console.php`.
 */
class RefreshOidcDiscoveryCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:refresh-oidc-discovery';

    protected $description = 'Revalida el documento de descubrimiento de los proveedores OIDC activos con más de N días desde el último refresco, para cada tenant activo';

    public function __construct(
        private readonly DiscoveryRefreshService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $refreshDays = (int) config('auth-local.sso.discovery_refresh_days');

        $this->eachTenant(function (Tenant $tenant) use ($refreshDays): void {
            $providers = IdentityProvider::query()
                ->where('is_enabled', true)
                ->where('discovery_fetched_at', '<', now()->subDays($refreshDays))
                ->get();

            foreach ($providers as $provider) {
                try {
                    $this->service->refresh($provider);
                } catch (DiscoveryValidationException $e) {
                    // operacion.md §F.8: auth.sso.discovery.refresh_failed
                    // por proveedor. Sin backend de métricas: el registro
                    // de aplicación es la fuente de la alerta operativa.
                    Log::channel(config('logging.default'))->warning('auth.sso.discovery.refresh_failed', [
                        'tenant_id' => $tenant->id,
                        'identity_provider_id' => $provider->id,
                        'code' => $e->failureCode->value,
                    ]);
                }
            }
        });

        return self::SUCCESS;
    }
}
