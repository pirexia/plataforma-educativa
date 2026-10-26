<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\FamUnit\GuardianStudentLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §1.4, §4 (1.14-a, `REQ-FAM-UNIT-002`). Vínculo tutor ↔ alumno con su tipo de relación,
 * representación legal, nivel de acceso, categorías restringidas, contacto de emergencia y estado (`activo` o
 * `revocado`). Puede cruzar unidades (C2).
 *
 * ADR-035: `Selective`. Se registra el valor de todos los códigos y marcas (ninguno es texto libre ni dato
 * personal: `RN-FAM-UNIT-36`, el detalle judicial nunca vive aquí) y de las ids de quien verificó y revocó; las
 * claves foráneas a tutor y alumno se redactan como `identifier`. Escrituras por instancia, nunca masivas (AR-15).
 * Los demás módulos no usan este modelo (AR-01): el acceso es por `FamilyMembership` (`funcional.md §7.1`).
 */
class GuardianStudentLink extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<GuardianStudentLinkFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'guardian_student_links';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'relationship', 'is_legal_guardian', 'legal_guardian_verified_at', 'access_level', 'restricted_categories',
        'is_emergency_contact', 'status', 'revocation_reason', 'revoked_at', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** @var list<string> */
    protected $fillable = [
        'guardian_id',
        'student_person_id',
        'relationship',
        'is_legal_guardian',
        'legal_guardian_verified_at',
        'legal_guardian_verified_by',
        'access_level',
        'restricted_categories',
        'is_emergency_contact',
        'status',
        'revocation_reason',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'is_legal_guardian' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'restricted_categories' => 'array',
            'legal_guardian_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GuardianStudentLinkFactory
    {
        return GuardianStudentLinkFactory::new();
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }
}
