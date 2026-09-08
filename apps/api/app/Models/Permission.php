<?php

namespace App\Models;

use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-034 §2, §7: catálogo de plataforma, no dato del tenant. Modelo
 * plano, no TenantModel — sin tenant_id, sin RLS, sin borrado lógico
 * (retired_at cumple ese papel a su manera: nunca se borra una fila).
 * Escritura reservada al comando de 0.8.11 (REVOKE en la migración, no
 * solo en este modelo).
 *
 * REQ-PERM/datos.md §3 (1.5): `applicable_scopes` — qué ámbitos admite este
 * permiso. `NULL` en la columna se interpreta como `['todos']`
 * (funcional.md §3.2 regla 1): el valor que deja el sistema exactamente
 * como estaba y el que menos permite.
 *
 * @mixin IdeHelperPermission
 */
class Permission extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'resource',
        'action',
        'module_code',
        'is_special_category',
        'applicable_scopes',
        'retired_at',
    ];

    protected $casts = [
        'is_special_category' => 'boolean',
        'applicable_scopes' => 'array',
        'retired_at' => 'datetime',
    ];

    /**
     * @return list<Scope>
     */
    public function applicableScopes(): array
    {
        $raw = $this->applicable_scopes ?? [Scope::Todos->value];

        return array_map(static fn (string $value): Scope => Scope::from($value), $raw);
    }
}
