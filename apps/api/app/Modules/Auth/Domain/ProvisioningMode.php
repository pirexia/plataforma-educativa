<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §F.2`, `ADR-043 §8.1`. **No existe `creacion`**, y no se deja
 * el valor preparado: la creación automática de personas no se implementa
 * en 1.4b (decisión del usuario, 2026-09-01).
 */
enum ProvisioningMode: string
{
    case Desactivado = 'desactivado';
    case Emparejamiento = 'emparejamiento';
}
