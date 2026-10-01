<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * api.md §3 y §14.1: los filtros estructurados de `users`, en un único
 * sitio. Los usan el listado (`UsersController::index`), el recuento de la
 * exportación (`EloquentExportRequestService`) y el trabajo
 * (`GenerateUserExport`) para que no puedan divergir (ADR-054 §8.2,
 * RN-CORE-85). No acota por ámbito: eso lo hace `ScopedQuery` aparte.
 *
 * Claves: `q` (solo el listado; la exportación lo rechaza antes de llegar
 * aquí, RN-CORE-58), `status`, `role` (public_id) y `locale` (listas),
 * `include_deleted` (bool).
 */
final class UserListFilter
{
    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        if (($filters['include_deleted'] ?? false) === true) {
            $query->withTrashed();
        }

        if (isset($filters['q']) && $filters['q'] !== '') {
            $needle = '%'.$filters['q'].'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->where('email', 'ilike', $needle)
                    ->orWhereHas('person', fn ($p) => $p->where('given_name', 'ilike', $needle)
                        ->orWhere('family_name_1', 'ilike', $needle)
                        ->orWhere('family_name_2', 'ilike', $needle));
            });
        }

        if (isset($filters['status']) && $filters['status'] !== []) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (isset($filters['role']) && $filters['role'] !== []) {
            $query->whereHas('roles', fn ($q) => $q->whereIn('public_id', (array) $filters['role']));
        }

        if (isset($filters['locale']) && $filters['locale'] !== []) {
            $query->whereHas('person', fn ($p) => $p->whereIn('locale', (array) $filters['locale']));
        }

        return $query;
    }
}
