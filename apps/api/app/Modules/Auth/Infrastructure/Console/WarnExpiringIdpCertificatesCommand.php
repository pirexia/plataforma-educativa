<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Domain\Models\IdentityProviderCertificate;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `operacion.md §G.4`, `CA-AUTH-335` (REQ-AUTH-004, 1.4c). Diaria. Avisa
 * de los certificados de un IdP cuya `not_after` está a menos de
 * `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS` (reutilizada, sin duplicar, `§G.2.1`)
 * — es lo que evita el modo de fallo de `ADR-043 §2.4`: caída del SSO de
 * un centro el día del vencimiento, con un mensaje que no apunta al
 * certificado. Hermana de `WarnExpiringClientSecretsCommand`.
 */
class WarnExpiringIdpCertificatesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:warn-expiring-idp-certificates';

    protected $description = 'Emite una alerta operativa por cada certificado de firma de un IdP SAML próximo a caducar, para cada tenant activo';

    public function handle(): int
    {
        $warningDays = (int) config('auth-local.sso.secret_expiry_warning_days');

        $this->eachTenant(function (Tenant $tenant) use ($warningDays): void {
            $expiring = IdentityProviderCertificate::query()
                ->whereNull('retired_at')
                ->where('not_after', '<=', now()->addDays($warningDays))
                ->get();

            foreach ($expiring as $certificate) {
                Log::channel(config('logging.default'))->warning('auth.saml.certificate.expiring', [
                    'tenant_id' => $tenant->id,
                    'identity_provider_id' => $certificate->identity_provider_id,
                    'not_after' => $certificate->not_after->toISOString(),
                ]);
            }
        });

        return self::SUCCESS;
    }
}
