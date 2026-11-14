<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.6. Excepción temporal nominal (RN-AUTH-68): `expires_at` es
 * `NOT NULL` en el esquema — "no existe la exención permanente" no es una
 * validación de aplicación, la garantiza el motor.
 *
 * Full: `reason` es contenido del centro sobre otra persona, no dato
 * personal en sí (`ADR-035 §8`, mismo criterio que `roles.name`), aunque
 * pueda contener información sensible según lo que se escriba
 * (datos.md §C.11, punto 1) — el manual de administración debe advertirlo.
 *
 * @mixin IdeHelperUserMfaExemption
 */
class UserMfaExemption extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_mfa_exemptions';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'reason', 'expires_at', 'granted_by', 'revoked_at', 'revoked_by', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'user_id',
        'reason',
        'expires_at',
        'granted_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && now()->lessThan($this->expires_at);
    }
}
