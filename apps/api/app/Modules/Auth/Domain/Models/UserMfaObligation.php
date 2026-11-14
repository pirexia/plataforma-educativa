<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.5. Una fila por período de obligación (RN-AUTH-65): cumplir
 * cierra la fila (`resolved_at`), volver a quedar sin factor abre otra
 * con plazo completo. Sin `public_id`: no se expone individualmente.
 *
 * Full: sin ningún dato personal más allá de la relación con el usuario.
 *
 * @mixin IdeHelperUserMfaObligation
 */
class UserMfaObligation extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'user_mfa_obligations';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'user_id',
        'obligated_since',
        'grace_deadline_at',
        'resolved_at',
        'trigger',
    ];

    protected $casts = [
        'obligated_since' => 'datetime',
        'grace_deadline_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isPastDeadline(): bool
    {
        return $this->isOpen() && now()->greaterThan($this->grace_deadline_at);
    }
}
