<?php

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\SamlCertificateSource;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §G.5 (REQ-AUTH-004, 1.4c). La ventana de rotación de los
 * certificados de firma de un IdP SAML. Con `public_id`: sí se expone en
 * URL (`DELETE .
 *
 * ../certificates/{public_id}`).
 *
 * Selective: `certificate` (el PEM) se declara secreto a mano
 * (`ADR-043 §3.5.5`, `RN-AUTH-127`, `CA-AUTH-333`) — no porque sea
 * secreto (es una clave pública), sino por proporción: un bloque de
 * kilobytes que `audit_logs` no tiene por qué duplicar en cada cambio.
 * `config('audit.secret_attribute_patterns')` no cubre `certificate`, así
 * que esta declaración explícita no es defensa en profundidad aquí: es
 * la única barrera.
 *
 * @mixin IdeHelperIdentityProviderCertificate
 */
class IdentityProviderCertificate extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'identity_provider_certificates';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'identity_provider_id', 'fingerprint_sha256', 'not_before', 'not_after', 'source', 'retired_at',
        'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['certificate'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'identity_provider_id',
        'certificate',
        'fingerprint_sha256',
        'not_before',
        'not_after',
        'source',
        'retired_at',
    ];

    protected $casts = [
        'source' => SamlCertificateSource::class,
        'not_before' => 'datetime',
        'not_after' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }

    /**
     * `datos.md §G.5`: "activo" es exactamente `retired_at IS NULL`
     * (más `deleted_at IS NULL`, ya filtrado por el `SoftDeletes` global
     * de `TenantModel`) — sin columna `is_active` que pueda contradecirlo.
     */
    public function isActive(): bool
    {
        return $this->retired_at === null;
    }
}
