<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * ADR-046 §6.3. Enlace por defecto: permite `Mantenimiento` bajo consola
 * y deniega los dos propósitos de backoffice. Mientras `App\Modules\
 * Backoffice` no sustituya este enlace (su ServiceProvider lo hace), los
 * propósitos de backoffice son inutilizables — no "permitidos porque
 * todavía no hay quien compruebe" (INV-002, denegar por defecto).
 */
final class DefaultPlatformAccessCheck implements PlatformAccessCheck
{
    public function before(PlatformAccessPurpose $purpose): void
    {
        if ($purpose === PlatformAccessPurpose::Mantenimiento) {
            if (! app()->runningInConsole()) {
                throw new RuntimeException(
                    'runAsPlatform(Mantenimiento) solo es alcanzable desde consola '.
                    '(comandos, tareas programadas y workers de cola). INV-012 ya '.
                    'obliga a que lo pesado vaya en colas: no existe un caso legítimo '.
                    'desde una petición HTTP.'
                );
            }

            return;
        }

        throw new RuntimeException(
            "runAsPlatform({$purpose->value}) no es alcanzable: App\\Modules\\Backoffice ".
            'no ha registrado su implementación de PlatformAccessCheck. Denegado por '.
            'defecto (INV-002) hasta que exista quien compruebe la capacidad y la '.
            'sesión de plataforma.'
        );
    }

    public function after(PlatformAccessPurpose $purpose): void
    {
        // Nada que cerrar: antes de que exista App\Modules\Backoffice no
        // hay ninguna obligación de auditoría que verificar, y before()
        // ya ha impedido que se llegue aquí con un propósito de
        // backoffice.
    }
}
