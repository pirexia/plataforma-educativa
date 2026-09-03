<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.3` (REQ-AUTH-004, 1.4c). Lista blanca cerrada en el motor.
 * `transient` NO está: `RN-AUTH-123`, un identificador que cambia en cada
 * acceso no puede sostener un vínculo.
 */
enum SamlNameIdFormat: string
{
    case EmailAddress = 'emailAddress';
    case Persistent = 'persistent';
    case Unspecified = 'unspecified';

    /**
     * El valor completo de la especificación SAML 2.0, tal como aparece
     * en el atributo `Format` de la aserción y en nuestros metadatos de
     * SP. `funcional.md §G.5.1`: el `NameIDFormat` recibido tiene que
     * coincidir con el catalogado.
     */
    public function urn(): string
    {
        return match ($this) {
            self::EmailAddress => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            self::Persistent => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            self::Unspecified => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        };
    }

    public static function fromUrn(string $urn): ?self
    {
        return match ($urn) {
            'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => self::EmailAddress,
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => self::Persistent,
            'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' => self::Unspecified,
            default => null,
        };
    }
}
