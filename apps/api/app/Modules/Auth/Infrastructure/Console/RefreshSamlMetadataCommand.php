<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Application\SamlMetadataRefreshService;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlMetadataSource;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `operacion.md §G.4` (REQ-AUTH-004, 1.4c). Diaria. Para cada proveedor
 * SAML **activo** de origen URL cuyo `metadata_fetched_at` sea anterior a
 * `AUTH_SAML_METADATA_REFRESH_DAYS`, revalida los metadatos. Si falla,
 * conserva todo lo anterior (`CA-AUTH-326`). Hermana de
 * `RefreshOidcDiscoveryCommand`.
 */
class RefreshSamlMetadataCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:refresh-saml-metadata';

    protected $description = 'Revalida los metadatos de los proveedores SAML activos de origen URL con más de N días desde el último refresco, para cada tenant activo';

    public function __construct(
        private readonly SamlMetadataRefreshService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $refreshDays = (int) config('auth-local.saml.metadata_refresh_days');

        $this->eachTenant(function (Tenant $tenant) use ($refreshDays): void {
            $providers = IdentityProvider::query()
                ->where('protocol', Protocol::Saml)
                ->where('is_enabled', true)
                ->whereHas('samlSettings', function ($query) use ($refreshDays): void {
                    $query->where('metadata_source', SamlMetadataSource::Url)
                        ->where(function ($query) use ($refreshDays): void {
                            $query->whereNull('metadata_fetched_at')
                                ->orWhere('metadata_fetched_at', '<', now()->subDays($refreshDays));
                        });
                })
                ->get();

            foreach ($providers as $provider) {
                try {
                    $this->service->refresh($provider);
                } catch (SamlMetadataValidationException $e) {
                    Log::channel(config('logging.default'))->warning('auth.saml.metadata.refresh_failed', [
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
