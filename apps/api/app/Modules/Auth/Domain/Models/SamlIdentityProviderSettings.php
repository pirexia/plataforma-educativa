<?php

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\SamlMetadataSource;
use App\Modules\Auth\Domain\SamlNameIdFormat;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §G.3 (REQ-AUTH-004, 1.4c). La hija 1:1 de un proveedor SAML.
 *
 * Sin `HasPublicId`: no se expone nunca en una URL propia (`api.md §G.2`).
 *
 * `Full`: sin datos personales (configuración técnica y metadatos
 * públicos de una institución). **Salvedad declarada a mano**
 * (`ADR-043 §3.5.5`): `metadata_xml` va en `$auditSecretAttributes` — no
 * porque sea secreto, sino porque es un documento de decenas de
 * kilobytes con certificados dentro y `Full` lo copiaría entero en
 * `audit_logs` en cada modificación. Se registra que cambió, no su valor.
 *
 * @mixin IdeHelperSamlIdentityProviderSettings
 */
class SamlIdentityProviderSettings extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'saml_identity_provider_settings';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['metadata_xml'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'identity_provider_id',
        'metadata_source',
        'metadata_url',
        'metadata_xml',
        'name_id_format',
        'email_attribute',
        'sign_authn_requests',
        'metadata_fetched_at',
        'metadata_failed_at',
    ];

    protected $casts = [
        'metadata_source' => SamlMetadataSource::class,
        'name_id_format' => SamlNameIdFormat::class,
        'sign_authn_requests' => 'boolean',
        'metadata_fetched_at' => 'datetime',
        'metadata_failed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }
}
