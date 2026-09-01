<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Modules\Auth\Domain\LinkMethod;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §E.2. El vínculo entre un usuario y una cuenta externa
 * concreta. Ninguna columna de token (`RN-AUTH-95`) ni de nombre o
 * fotografía (`RN-AUTH-88`).
 *
 * Selective: `subject` y `email_at_link` se declaran secretos a mano
 * (`ADR-035`) — identificadores personales que `config('audit.
 * secret_attribute_patterns')` no cubre (no encajan en `*password*`,
 * `*secret*`, `*token*` ni `*recovery_code*`). `last_login_at` no se
 * registra: cambia en cada acceso y no dice nada que el evento `login`
 * no diga ya (mismo criterio que `user_mfa_factors.last_used_at`).
 *
 * @mixin IdeHelperUserIdentity
 */
class UserIdentity extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_identities';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'provider', 'link_method', 'linked_at', 'email_verified_at_link', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['subject', 'email_at_link'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'user_id',
        'provider',
        'subject',
        'email_at_link',
        'email_verified_at_link',
        'link_method',
        'linked_at',
        'last_login_at',
    ];

    protected $casts = [
        'link_method' => LinkMethod::class,
        'email_verified_at_link' => 'boolean',
        'linked_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
