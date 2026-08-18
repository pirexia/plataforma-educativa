<?php

namespace App\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * ADR-034 §4: del tenant, nunca catálogo compartido — cada centro fija sus
 * propias fechas. academic_year_id es NOT NULL o no existe la columna en
 * cualquier tabla que la referencie; nunca nullable (regla verificada por
 * el test de esquema de 0.8.10, no por este modelo).
 */
class AcademicYear extends TenantModel
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'status' => AcademicYearStatus::class,
    ];
}
