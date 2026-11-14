<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use Illuminate\Support\Collection;

/**
 * `funcional.md §F.7.2`. Los proveedores catalogados de un tenant, para
 * la pantalla de login (`GET /auth/identity-providers`, anónimo) y para
 * el arranque del flujo (`POST /auth/oauth-authorizations`). `1.4c`
 * añadirá los suyos sin tocar a los consumidores.
 */
interface IdentityProviderDirectory
{
    /**
     * @return Collection<int, IdentityProvider>
     */
    public function activeCatalog(): Collection;

    /**
     * Por `public_id`, **sin filtrar por activo**: el llamador decide qué
     * hacer con un proveedor no activo (`RN-AUTH-102`). `null` si no
     * existe o es de otro tenant (RLS ya lo garantiza).
     */
    public function findByPublicId(string $publicId): ?IdentityProvider;

    /**
     * Por identificador interno — lo que viaja en el *payload* de la
     * sesión (`RN-AUTH-103`), nunca en la URL.
     */
    public function findById(int $id): ?IdentityProvider;
}
