<?php

namespace App\Support\Authorization;

use App\Models\Role;

/**
 * funcional.md §7.10, api.md §7.1: una fila de procedencia de
 * `GET /users/{id}/effective-permissions` — de qué rol viene cada
 * concesión o denegación, con su ámbito y si es inerte (y por qué). Es lo
 * que distingue «no concedido» de «concedido pero inerte» (RPERM-009).
 */
final class PermissionSource
{
    public function __construct(
        public readonly Role $role,
        public readonly string $effect,
        public readonly ?Scope $scope,
        public readonly bool $inert,
        public readonly ?string $inertReason,
    ) {}
}
