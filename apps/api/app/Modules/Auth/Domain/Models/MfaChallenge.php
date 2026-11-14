<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Modules\Auth\Domain\MfaMethod;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.4. **No implementa `Auditable`, a propósito**: es un
 * artefacto transitorio de cinco minutos, mismo trato que
 * `password_reset_tokens` (`§A.5`). Registrar su creación y su consumo
 * escribiría dos filas de `audit_logs` por cada login con MFA para decir
 * algo que el evento `login` ya dice mejor y una sola vez (funcional.md
 * §C.10). El intento fallido va a `login_attempts`, no aquí.
 *
 * `session_id` **nunca sale por la API** (RN-AUTH-40) y es la única
 * credencial que autoriza el desafío (RN-AUTH-53): se busca siempre por
 * `(tenant_id, session_id)`, nunca por `public_id`.
 *
 * @mixin IdeHelperMfaChallenge
 */
class MfaChallenge extends TenantModel
{
    use HasPublicId;

    protected $table = 'mfa_challenges';

    protected $fillable = [
        'user_id',
        'session_id',
        'method',
        'code_hash',
        'code_expires_at',
        'expires_at',
        'attempts',
        'deliveries',
        'consumed_at',
        'ip_address',
    ];

    protected $casts = [
        'method' => MfaMethod::class,
        'code_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function isLive(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }
}
