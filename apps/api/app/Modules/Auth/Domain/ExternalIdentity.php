<?php

namespace App\Modules\Auth\Domain;

/**
 * `ADR-042 §4.3`. El resultado del *callback*, ya normalizado. Objeto de
 * valor `final readonly` — firma copiada literalmente del ADR.
 *
 * Nada más: sin `token`, sin `refreshToken`, sin `approvedScopes`, sin el
 * array crudo del proveedor (`RN-AUTH-95`, `ADR-042 §4.3`).
 */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $providerUserId,  // `sub`. La identidad estable, no el correo (RN-AUTH-86)
        public string $email,
        public bool $emailVerified,     // bool de primera clase, normalizado en un solo sitio (ADR-042 §4.4)
        public ?string $displayName,    // `name`
        public ?string $givenName,      // `given_name`
        public ?string $familyName,     // `family_name`, TAL CUAL lo da Google (ADR-042 §4.6) — sin partir
        public ?string $avatarUrl,      // `picture`
    ) {}
}
