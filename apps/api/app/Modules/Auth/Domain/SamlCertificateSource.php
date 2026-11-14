<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.5` (REQ-AUTH-004, 1.4c). Traza de procedencia de un
 * certificado del IdP: lo que permite que el refresco de metadatos añada
 * sin pisar lo que un administrador subió a mano.
 */
enum SamlCertificateSource: string
{
    case Metadata = 'metadata';
    case Manual = 'manual';
}
