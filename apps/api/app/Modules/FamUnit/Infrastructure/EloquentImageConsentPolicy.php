<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Domain\ImageConsentLevel;
use App\Modules\FamUnit\Domain\ImageConsentPolicy;
use App\Support\Modules\ModuleAvailability;
use InvalidArgumentException;

/**
 * REQ-FAM-UNIT/funcional.md §7.3 (1.14-b, `INV-014`, `RN-FAM-UNIT-46`, `ADR-066 §5.3`). Una consulta por llamada y
 * **ninguna memoización** (`RN-FAM-UNIT-34`): retirar un consentimiento surte efecto en la llamada siguiente. Deniega
 * por defecto: lo que no está en `autorizo` bloquea —también el alumno sin decisiones, sin quien decida, inexistente o
 * de otro centro— y, con el módulo descontratado, todos los alumnos bloquean (`funcional.md §9`). Una lista vacía es
 * un error de programación y lanza una excepción: nunca «publicable».
 */
final class EloquentImageConsentPolicy implements ImageConsentPolicy
{
    public function __construct(
        private readonly ImageConsentService $consents,
        private readonly ModuleAvailability $modules,
    ) {}

    public function isPublishable(ImageConsentLevel $level, array $studentPersonIds): bool
    {
        return $this->blockingAmong($level, $studentPersonIds) === [];
    }

    public function blockingAmong(ImageConsentLevel $level, array $studentPersonIds): array
    {
        if ($studentPersonIds === []) {
            throw new InvalidArgumentException('ImageConsentPolicy: la lista de alumnos está vacía; una publicación sin alumnos identificables no consulta (RN-FAM-UNIT-46).');
        }

        $ids = array_values(array_unique($studentPersonIds));
        sort($ids);

        // Fallo en cerrado: con el módulo descontratado, nadie es publicable.
        if (! $this->modules->isEnabled('fam_unit')) {
            return $ids;
        }

        return array_values(array_diff($ids, $this->consents->authorizedAmong($level->value, $ids)));
    }
}
