<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Auth;

/**
 * ADR-034 §6: created_by/updated_by (INV-005), rellenos desde la sesión
 * autenticada cuando la hay. Sin REQ-AUTH todavía (1.2), Auth::id() no
 * devuelve nada y las dos columnas se quedan en null — exactamente el
 * comportamiento correcto para consola, jobs del sistema, seeders e
 * importaciones, que tampoco tienen usuario.
 */
trait RecordsAuthorship
{
    public static function bootRecordsAuthorship(): void
    {
        static::creating(function ($model): void {
            if (! isset($model->created_by)) {
                $model->created_by = Auth::id();
            }

            if (! isset($model->updated_by)) {
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            $model->updated_by = Auth::id();
        });
    }
}
