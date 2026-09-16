<?php

namespace App\Modules\Core\Domain\Events;

/**
 * `ADR-045 §4.8`, `RMOD-010`. Simétrico de `ModuleContracted`: se emite
 * aunque el módulo quede apagado, comprobación de que el evento
 * pertenece a `REQ-CORE` y no al módulo afectado (`CA-BO-038`).
 */
final class ModuleDecontracted
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $moduleCode,
        public readonly ?int $actorPlatformAdminId,
    ) {}
}
