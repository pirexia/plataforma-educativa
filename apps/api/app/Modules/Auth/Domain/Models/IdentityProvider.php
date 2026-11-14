<?php

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\ClaimsSource;
use App\Modules\Auth\Domain\EmailClaim;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\ProvisioningMode;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * datos.md §F.2, ampliado en `datos.md §G.2` (REQ-AUTH-004, 1.4c: el
 * discriminador `protocol`). El catálogo de proveedores de identidad de
 * un centro, OIDC y SAML (`ADR-043 §3.5` punto 1: tabla de tenant, sin
 * excepción de *tenancy*).
 *
 * `Full`: sin datos personales (`ADR-035 §8`). URLs, un `client_id`, una
 * lista de dominios y varios conmutadores — el mismo perfil que
 * `AcademicYear`/`Role`/`ModuleSubscription`.
 *
 * `protocol` es **inmutable tras el alta** (`RN-AUTH-114`): no lo impone
 * un `CHECK` (no ve el valor anterior), lo impone el servicio
 * (`IdentityProviderService`/`SamlIdentityProviderAdminService`,
 * `CA-AUTH-316`). Ninguna columna de mapeo de atributos hacia `people`
 * (`funcional.md §F.5.2`, `§G.5.2`).
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
        'protocol',
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
        'protocol' => Protocol::class,
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
     * `datos.md §G.3`. `null` en toda fila `protocol = 'oidc'`.
     *
     * @return HasOne<SamlIdentityProviderSettings, $this>
     */
    public function samlSettings(): HasOne
    {
        return $this->hasOne(SamlIdentityProviderSettings::class);
    }

    /**
     * `datos.md §G.5`. Vacía en toda fila `protocol = 'oidc'`.
     *
     * @return HasMany<IdentityProviderCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(IdentityProviderCertificate::class);
    }

    /**
     * `datos.md §G.5`, `RN-AUTH-125`: los certificados admisibles son
     * **todos** los activos y vigentes a la vez, no uno elegido — a
     * diferencia de `activeSecret()`, que sí elige el más reciente.
     * "Activo" (`retired_at IS NULL`) y "vigente" (dentro de
     * `not_before`/`not_after`) son dos condiciones distintas (`datos.md
     * §G.5`: *"no hay columna `is_active`… `not_before`/`not_after`
     * deciden la vigencia"*), y las dos se exigen aquí: un certificado
     * sin retirar pero ya caducado no protege nada y no debe contar como
     * admisible para firmar, para el aviso de "última vigente"
     * (`CA-AUTH-330`) ni para el bloqueo de activación (`CA-AUTH-331`).
     *
     * @return Collection<int, IdentityProviderCertificate>
     */
    public function activeCertificates(): Collection
    {
        return $this->certificates()
            ->whereNull('retired_at')
            ->where('not_before', '<=', now())
            ->where('not_after', '>', now())
            ->get();
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
