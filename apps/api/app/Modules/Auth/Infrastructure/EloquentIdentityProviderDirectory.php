<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\IdentityProviderDirectory;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use Illuminate\Support\Collection;

/**
 * `funcional.md §F.7.2`. `TenantModel`/RLS ya acotan toda consulta al
 * tenant activo (`INV-001`) — ningún método de esta clase añade
 * `where('tenant_id', ...)` a mano.
 */
final class EloquentIdentityProviderDirectory implements IdentityProviderDirectory
{
    public function activeCatalog(): Collection
    {
        return IdentityProvider::query()
            ->where('is_enabled', true)
            ->orderBy('display_name')
            ->get();
    }

    public function findByPublicId(string $publicId): ?IdentityProvider
    {
        return IdentityProvider::query()->where('public_id', $publicId)->first();
    }

    public function findById(int $id): ?IdentityProvider
    {
        return IdentityProvider::query()->find($id);
    }
}
