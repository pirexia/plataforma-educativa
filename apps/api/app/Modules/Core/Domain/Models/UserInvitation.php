<?php

namespace App\Modules\Core\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §A.2. Selective: `token_hash` lo redacta automáticamente el
 * patrón global `*token*` de config('audit.secret_attribute_patterns') —
 * no hace falta declararlo aquí.
 *
 * @mixin IdeHelperUserInvitation
 */
class UserInvitation extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_invitations';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'expires_at', 'accepted_at', 'revoked_at', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
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
     * api.md §4: `status` es derivado, nunca una columna (RN-CORE-09).
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'aceptada',
            $this->revoked_at !== null => 'revocada',
            $this->expires_at->isPast() => 'caducada',
            default => 'vigente',
        };
    }

    public function isLive(): bool
    {
        return $this->status() === 'vigente';
    }
}
