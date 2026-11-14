<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.3 (1.14-b, `RN-FAM-UNIT-67`, `ADR-066 §5.3`): los niveles del consentimiento de imagen,
 * enumerado público del propio `Domain` que usan los publicadores al consultar `ImageConsentPolicy`. Son exactamente
 * dos por decisión del usuario (2026-10-27): no hay consentimiento por red social ni por medio. Los valores son los de
 * la API y la base de datos.
 */
enum ImageConsentLevel: string
{
    /** Portal web del centro: web pública, galerías, publicaciones internas. */
    case Web = 'web';

    /** Redes sociales, *newsletters* y prensa. */
    case SocialNetworks = 'redes_sociales';
}
