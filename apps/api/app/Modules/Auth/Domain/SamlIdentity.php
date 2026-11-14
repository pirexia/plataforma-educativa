<?php

namespace App\Modules\Auth\Domain;

use Carbon\CarbonImmutable;

/**
 * `funcional.md §G.3.6` (REQ-AUTH-004, 1.4c). El objeto de valor de la
 * identidad SAML: propio, no `ExternalIdentity`. `ADR-043 §10.9` decisión
 * 5: SAML nunca consume `emailVerified` para nada (la fusión automática es
 * imposible por esquema para cualquier proveedor catalogado — `CHECK
 * user_identities_fusion_no_provider_check`), así que este VO no lleva
 * ese campo.
 *
 * Firma fijada por la especificación, copiada literalmente.
 */
final readonly class SamlIdentity
{
    public function __construct(
        /** El identificador estable, RN-AUTH-123. */
        public string $nameId,
        public SamlNameIdFormat $nameIdFormat,
        /** Del atributo configurado, o del propio NameID si el formato es emailAddress. */
        public ?string $email,
        /** Para saml_consumed_assertions. */
        public string $assertionId,
        public CarbonImmutable $notOnOrAfter,
    ) {}
}
