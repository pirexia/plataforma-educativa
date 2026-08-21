<?php

namespace App\Support\Tenancy;

use App\Support\Database\HasPublicId;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Raíz del aislamiento (ADR-033 §7): no lleva tenant_id, se identifica a sí
 * misma. Conexión de plataforma (BYPASSRLS) a propósito: resolver un tenant
 * por slug (middleware ResolveTenant, 0.7.5) ocurre ANTES de que exista
 * contexto de tenant, y sobre la conexión pgsql (rol plataforma_app) la
 * política `id = app.current_tenant_id()` daría siempre cero filas sin
 * tenant activo. La política RLS de la tabla sigue ahí como red de
 * seguridad para el día en que algo la consulte por la conexión pgsql: en
 * ese caso solo vería su propia fila, nunca las de otro tenant.
 *
 * @mixin IdeHelperTenant
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'slug',
        'name',
        'status',
    ];

    protected $casts = [
        'status' => TenantStatus::class,
    ];

    public static function findBySlug(string $slug): ?self
    {
        return static::query()->where('slug', $slug)->first();
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return TenantFactory::new();
    }
}
