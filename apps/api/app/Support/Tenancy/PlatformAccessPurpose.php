<?php

namespace App\Support\Tenancy;

/**
 * ADR-046 §6.1. El propósito con el que se abre un bloque
 * `TenantContext::runAsPlatform()` — primer parámetro, sin valor por
 * defecto a propósito, para que ningún llamador existente siga
 * compilando sin tocarlo y ningún llamador futuro herede un propósito
 * por omisión (funcional.md §6.2.1).
 *
 * Vive en App\Support\Tenancy, junto a TenantContext, TenantScope y
 * TenantContextMissing: es infraestructura de aislamiento compartida por
 * todo el producto, no código de REQ-BO (funcional.md §12.2.1).
 */
enum PlatformAccessPurpose: string
{
    /**
     * Mantenimiento sin sujeto: comandos, tareas programadas y workers de
     * cola, que corren bajo `artisan`. Solo alcanzable con
     * `app()->runningInConsole()` verdadero (funcional.md §6.2.2).
     */
    case Mantenimiento = 'mantenimiento';

    /**
     * Administrador de plataforma autenticado en el guard `platform`,
     * comprobando datos sin escribir. Sin obligación de auditoría: sin
     * sujeto que escriba, no hay nada que registrar.
     */
    case BackofficeLectura = 'backoffice_lectura';

    /**
     * Igual que BackofficeLectura, pero con obligación de dejar al menos
     * una entrada en `admin_action_logs` dentro del bloque — comprobada
     * al cerrarse, no prometida (funcional.md §6.2.4).
     */
    case BackofficeEscritura = 'backoffice_escritura';
}
