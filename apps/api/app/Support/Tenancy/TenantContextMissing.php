<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Se lanza al pedir el tenant activo (TenantContext::tenantId()) sin que
 * ninguno esté fijado. INV-001: el sistema falla en cerrado, nunca lee ni
 * escribe datos de negocio "sin filtro" por ausencia de tenant.
 */
class TenantContextMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No hay tenant activo. Todo acceso a datos de negocio requiere '.
            'TenantContext::enter() o TenantContext::runFor() antes de la '.
            'primera consulta. Si esto ocurre en un comando de consola, usa '.
            'el trait RunsPerTenant; si es un job, revisa que no se haya '.
            'saltado el listener JobProcessing.'
        );
    }
}
