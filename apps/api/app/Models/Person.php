<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ADR-034 §1: la identidad, no la cuenta. `User` es una faceta de
 * autenticación 0..1 (puede no existir: una persona sin acceso al
 * portal). Las facetas de alumno/tutor/empleado llegan con sus propios
 * módulos (REQ-ALUM, REQ-FAM-UNIT, REQ-RRHH), no en 0.8.
 *
 * ADR-035 §8: Selective. Todo identificador se redacta salvo la lista de
 * inclusión — dato de mayor volumen personal del sistema (el `created` de
 * cada alumno/tutor/empleado escribe su identidad completa si no se
 * clasifica).
 */
class Person extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $fillable = [
        'given_name',
        'family_name_1',
        'family_name_2',
        'birth_date',
        'document_type',
        'document_number',
        'contact_email',
        'contact_phone',
        'locale',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'locale', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
