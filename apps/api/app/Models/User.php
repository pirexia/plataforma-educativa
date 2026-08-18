<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * ADR-034 §1: la credencial, no la identidad — esa es Person. Extiende
 * TenantModel, no Authenticatable: implementar el contrato
 * Illuminate\Contracts\Auth\Authenticatable sobre esta base (guards,
 * remember-token, proveedor de config/auth.php) es tarea de REQ-AUTH
 * (1.2), no de 0.8. Hasta entonces no hay ningún flujo de login real que
 * lo necesite.
 *
 * ADR-035 §8: Selective. `email` se redacta como `identifier` (se asume la
 * pérdida de diff — 1.2 la cubre como evento de seguridad, ver ADR-035
 * §8); `password`/`remember_token` los redacta la regla 1 (patrón global
 * de secretos), sin necesidad de declararlos aquí.
 */
#[Fillable(['person_id', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicId;
    use Notifiable;
    use RecordsAuditTrail;

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'status', 'email_verified_at', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
