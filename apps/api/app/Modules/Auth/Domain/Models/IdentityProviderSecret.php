<?php

namespace App\Modules\Auth\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §F.3. La credencial de cliente de un proveedor, cifrada con
 * `APP_KEY` (`RN-AUTH-112`). **De solo escritura a través de la API**:
 * nadie la relee en claro, ni siquiera quien la cargó — se descifra
 * únicamente dentro del canje de código, en memoria.
 *
 * Selective: `client_secret` se declara secreto a mano (paso 1 del orden
 * de `ADR-035 §4`, absoluto y anterior al patrón global de
 * `config('audit.secret_attribute_patterns')`, que también lo cubriría
 * por `*secret*` como defensa en profundidad — `funcional.md §F.0.4`).
 *
 * @mixin IdeHelperIdentityProviderSecret
 */
class IdentityProviderSecret extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'identity_provider_secrets';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'identity_provider_id', 'expires_at', 'activated_at', 'retired_at', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['client_secret'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'identity_provider_id',
        'client_secret',
        'expires_at',
        'activated_at',
        'retired_at',
    ];

    protected $casts = [
        // RN-AUTH-112: cifrado con APP_KEY, nunca en claro en la base de
        // datos. Mismo patrón que user_mfa_factors.secret_encrypted (§C.2).
        'client_secret' => 'encrypted',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }
}
