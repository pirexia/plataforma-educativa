<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-CORE-001. Catálogo cerrado de comunidades autónomas y ciudades
 * autónomas de España (ISO 3166-2:ES sin el prefijo "ES-"). `datos.md`
 * §A.1 fija la columna como texto libre con CHECK a nivel de aplicación,
 * no de base de datos — esta es la lista de referencia única.
 */
final class AutonomousCommunity
{
    public const array CODES = [
        'AN', 'AR', 'AS', 'IB', 'CN', 'CB', 'CL', 'CM', 'CT',
        'EX', 'GA', 'MD', 'MC', 'NC', 'PV', 'RI', 'VC', 'CE', 'ML',
    ];
}
