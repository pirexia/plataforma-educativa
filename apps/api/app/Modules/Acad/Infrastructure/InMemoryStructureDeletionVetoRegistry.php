<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\StructureDeletionVeto;
use App\Modules\Acad\Domain\StructureDeletionVetoRegistry;

/**
 * RN-ACAD-21: singleton del contenedor; cada módulo registra su veto desde su
 * `ServiceProvider`.
 */
final class InMemoryStructureDeletionVetoRegistry implements StructureDeletionVetoRegistry
{
    /** @var list<StructureDeletionVeto> */
    private array $vetoes = [];

    public function register(StructureDeletionVeto $veto): void
    {
        $this->vetoes[] = $veto;
    }

    public function all(): array
    {
        return $this->vetoes;
    }
}
