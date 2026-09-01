<?php

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\ClaimsSource;
use App\Modules\Auth\Domain\EmailClaim;
use App\Modules\Auth\Domain\ProvisioningMode;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * datos.md §F.2. El catálogo de proveedores OIDC de un centro
 * (`ADR-043 §3.5` punto 1: tabla de tenant, sin excepción de *tenancy*).
 *
 * `Full`: sin datos personales (`ADR-035 §8`). URLs, un `client_id`, una
 * lista de dominios y tres conmutadores — el mismo perfil que
 * `AcademicYear`/`Role`/`ModuleSubscription`.
 *
 * Ninguna columna de `protocol` (SAML es 1.4c), `jwks_uri` (no se
 * verifica firma) ni mapeo de atributos hacia `people` (`funcional.md
 * §F.5.2`).
 *
 * @mixin IdeHelperIdentityProvider
 */
class IdentityProvider extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'identity_providers';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'display_name',
        'discovery_url',
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'userinfo_endpoint',
        'claims_source',
        'email_claim',
        'scopes',
        'client_id',
        'allowed_email_domains',
        'provisioning_mode',
        'is_enabled',
        'discovery_fetched_at',
        'discovery_failed_at',
    ];

    protected $casts = [
        'claims_source' => ClaimsSource::class,
        'email_claim' => EmailClaim::class,
        'scopes' => 'array',
        'allowed_email_domains' => 'array',
        'provisioning_mode' => ProvisioningMode::class,
        'is_enabled' => 'boolean',
        'discovery_fetched_at' => 'datetime',
        'discovery_failed_at' => 'datetime',
    ];

    /**
     * @return HasMany<IdentityProviderSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(IdentityProviderSecret::class);
    }

    /**
     * `datos.md §F.3`: la vigente, de `activated_at` más reciente,
     * todavía no retirada. `null` ⇒ `auth.sso.provider.enabled_without_secret`
     * (`operacion.md §F.8`).
     */
    public function activeSecret(): ?IdentityProviderSecret
    {
        return $this->secrets()
            ->whereNull('retired_at')
            ->orderByDesc('activated_at')
            ->first();
    }
}
