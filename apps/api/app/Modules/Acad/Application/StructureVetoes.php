<?php

namespace App\Modules\Acad\Application;

use App\Modules\Acad\Domain\StructureDeletionVetoRegistry;
use App\Modules\Acad\Domain\StructureEntity;
use App\Support\Modules\ModuleAvailability;

/**
 * RN-ACAD-21: único sitio de `acad` que ejecuta los vetos de borrado. Solo los
 * de módulos utilizables en el centro (`ModuleAvailability::isEnabled()`, la
 * misma definición que usa `curso`, `RN-CURSO-60`).
 */
final class StructureVetoes
{
    public function __construct(
        private readonly StructureDeletionVetoRegistry $registry,
        private readonly ModuleAvailability $modules,
    ) {}

    /**
     * Claves de dependiente de los vetos que se cumplen (sin repetir).
     *
     * @return list<string>
     */
    public function dependents(StructureEntity $entity, int $internalId): array
    {
        $dependents = [];

        foreach ($this->registry->all() as $veto) {
            if ($veto->appliesTo() !== $entity || ! $this->modules->isEnabled($veto->moduleCode())) {
                continue;
            }

            $failure = $veto->check($internalId);

            if ($failure !== null && ! in_array($failure->dependent, $dependents, true)) {
                $dependents[] = $failure->dependent;
            }
        }

        return $dependents;
    }
}
