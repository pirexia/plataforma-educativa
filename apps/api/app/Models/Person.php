<?php

namespace App\Models;

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
 */
class Person extends TenantModel
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasPublicId;

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

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
