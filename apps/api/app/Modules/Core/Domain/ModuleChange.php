<?php

namespace App\Modules\Core\Domain;

/**
 * funcional.md §5.8.2, `OPEN-BO-18`. Objeto de valor que describe la
 * operación pedida sobre un módulo de un tenant: contratar o
 * descontratar, con su motivo y si el operador confirma el arrastre de
 * dependencias (`RN-BO-67`).
 */
final class ModuleChange
{
    public function __construct(
        public readonly string $moduleCode,
        public readonly bool $enabled,
        public readonly string $reason,
        public readonly bool $cascade = false,
    ) {}
}
