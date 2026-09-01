<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Domain\Models\IdentityProviderSecret;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `operacion.md §F.4`. Diaria. Marca —vía alerta operativa— toda
 * credencial vigente cuya `expires_at` esté a menos de
 * `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS`. Es lo que evita la caída total
 * sin aviso de `funcional.md §F.3.5`: el administrador del centro ve el
 * mismo estado en `/administracion/sso` (`secret_status.expiring_soon`,
 * `api.md §F.2`) sin esperar a que corra este comando; este comando es
 * quien alimenta la alerta al operador de plataforma (`operacion.md
 * §F.8`). Programado desde `routes/console.php`.
 */
class WarnExpiringClientSecretsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:warn-expiring-client-secrets';

    protected $description = 'Emite una alerta operativa por cada credencial de cliente de un proveedor OIDC próxima a caducar, para cada tenant activo';

    public function handle(): int
    {
        $warningDays = (int) config('auth-local.sso.secret_expiry_warning_days');

        $this->eachTenant(function (Tenant $tenant) use ($warningDays): void {
            $expiring = IdentityProviderSecret::query()
                ->whereNull('retired_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays($warningDays))
                ->get();

            foreach ($expiring as $secret) {
                Log::channel(config('logging.default'))->warning('auth.sso.secret.expiring', [
                    'tenant_id' => $tenant->id,
                    'identity_provider_id' => $secret->identity_provider_id,
                    'expires_at' => $secret->expires_at?->toISOString(),
                ]);
            }
        });

        return self::SUCCESS;
    }
}
