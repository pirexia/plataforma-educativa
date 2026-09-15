<?php

namespace App\Modules\Core\Domain\Events;

/**
 * `ADR-045 §4.8`, `RMOD-010`. Emitido por `REQ-CORE`, nunca por el
 * backoffice directamente — el mismo evento se emite cuando la escritura
 * viene de la activación masiva o de un comando de consola. Uno por
 * centro y por módulo, arrastrados incluidos, jamás uno agregado
 * (`RN-BO-27`). Sin consumidor en 1.6c; `REQ-COM-003` (1.19) es el
 * primero.
 */
final class ModuleContracted
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $moduleCode,
        public readonly ?int $actorPlatformAdminId,
    ) {}
}
