<?php

namespace App\Modules\Auth\Domain;

use Carbon\CarbonImmutable;

/**
 * `datos.md §G.5` (REQ-AUTH-004, 1.4c). Un `KeyDescriptor use="signing"`
 * ya analizado: PEM, huella y vigencia extraídas del propio certificado
 * (`RN-AUTH-126`), nunca tecleadas.
 */
final readonly class SamlMetadataCertificate
{
    public function __construct(
        public string $pem,
        public string $fingerprintSha256,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
    ) {}
}
