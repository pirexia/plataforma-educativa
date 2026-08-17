<?php

namespace App\Support\Database;

use Illuminate\Support\Str;

/**
 * ADR-029: clave primaria interna bigint (nunca sale de la aplicación) más
 * public_id ULID (único, indexado) para todo lo expuesto en URL o API.
 * Reutilizable por cualquier entidad futura que cumpla esa regla.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
