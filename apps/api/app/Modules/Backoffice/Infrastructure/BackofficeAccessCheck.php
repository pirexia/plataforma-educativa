<?php

namespace App\Modules\Backoffice\Infrastructure;

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Support\Tenancy\PlatformAccessCheck;
use App\Support\Tenancy\PlatformAccessPurpose;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ADR-046 §6.3, §6.4, §6.5, funcional.md §6.2.3. Sustituye al enlace por
 * defecto (`DefaultPlatformAccessCheck`) en cuanto este módulo se
 * registra: sí sabe consultar el guard `platform` y `admin_action_logs`.
 *
 * `before()` solo comprueba que el propósito es alcanzable desde aquí
 * (consola para `Mantenimiento`, sesión de plataforma autenticada para
 * los dos de backoffice) — la capacidad concreta la comprueba el
 * llamador antes de entrar en el bloque (RequirePlatformCapability), no
 * esta primitiva.
 */
final class BackofficeAccessCheck implements PlatformAccessCheck
{
    /**
     * Pila de puntos de control, uno por bloque `BackofficeEscritura`
     * abierto: el `id` máximo de `admin_action_logs` visto en `before()`.
     * Pila y no una sola variable, por si algún día un bloque se anida
     * dentro de otro — no ocurre hoy, pero una sola variable rompería en
     * silencio el día que ocurra.
     *
     * @var list<int>
     */
    private array $writeCheckpoints = [];

    public function before(PlatformAccessPurpose $purpose): void
    {
        match ($purpose) {
            PlatformAccessPurpose::Mantenimiento => $this->requireConsole($purpose),
            PlatformAccessPurpose::BackofficeLectura,
            PlatformAccessPurpose::BackofficeEscritura => $this->requireAuthenticatedPlatformAdmin($purpose),
        };

        if ($purpose === PlatformAccessPurpose::BackofficeEscritura) {
            $this->writeCheckpoints[] = (int) (AdminActionLog::query()->max('id') ?? 0);
        }
    }

    public function after(PlatformAccessPurpose $purpose): void
    {
        if ($purpose !== PlatformAccessPurpose::BackofficeEscritura) {
            return;
        }

        $checkpoint = array_pop($this->writeCheckpoints) ?? 0;
        $latest = (int) (AdminActionLog::query()->max('id') ?? 0);

        if ($latest <= $checkpoint) {
            throw new RuntimeException(
                'Un bloque runAsPlatform(BackofficeEscritura) terminó sin dejar ninguna '.
                'entrada en admin_action_logs (ADR-046 §6.5). La escritura de plataforma '.
                'sin rastro no se completa: si la operación no necesitaba dejar rastro, '.
                'el propósito correcto era BackofficeLectura.'
            );
        }
    }

    private function requireConsole(PlatformAccessPurpose $purpose): void
    {
        if (! app()->runningInConsole()) {
            throw new RuntimeException(
                "runAsPlatform({$purpose->value}) solo es alcanzable desde consola ".
                '(comandos, tareas programadas y workers de cola).'
            );
        }
    }

    private function requireAuthenticatedPlatformAdmin(PlatformAccessPurpose $purpose): void
    {
        if (! Auth::guard('platform')->check()) {
            throw new RuntimeException(
                "runAsPlatform({$purpose->value}) exige un administrador de plataforma ".
                "autenticado en el guard 'platform'."
            );
        }
    }
}
