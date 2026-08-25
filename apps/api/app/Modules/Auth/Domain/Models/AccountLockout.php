<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Modules\Auth\Domain\UnlockReason;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * datos.md §A.2. Selective: `email` se redacta como `identifier` (dato
 * personal directo, mismo criterio que `users.email`); `unlock_token_hash`
 * lo redacta automáticamente el patrón global `*token*` sin declararlo.
 * La creación de la fila es un evento `created` y el desbloqueo un
 * `updated`, ambos por el *observer* de 0.9 — ningún código de este
 * módulo llama a AuditRecorder para bloqueo/desbloqueo (funcional.md §10.1).
 *
 * @mixin IdeHelperAccountLockout
 */
class AccountLockout extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'account_lockouts';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'failed_count', 'locked_at', 'unlocked_at', 'unlocked_by', 'unlock_reason', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'email',
        'user_id',
        'failed_count',
        'locked_at',
        'unlock_token_hash',
        'unlock_token_expires_at',
        'unlocked_at',
        'unlocked_by',
        'unlock_reason',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'unlock_token_expires_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'unlock_reason' => UnlockReason::class,
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
    public function unlockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    /**
     * api.md §5: `status` es derivado, nunca una columna.
     */
    public function status(): string
    {
        return $this->unlocked_at !== null ? 'levantado' : 'vigente';
    }

    public function isLive(): bool
    {
        return $this->unlocked_at === null;
    }

    public function isExpired(): bool
    {
        return $this->isLive() && now()->greaterThanOrEqualTo($this->expiresAt());
    }

    public function expiresAt(): Carbon
    {
        return $this->locked_at->clone()->addMinutes((int) config('auth-local.lockout_minutes'));
    }
}
