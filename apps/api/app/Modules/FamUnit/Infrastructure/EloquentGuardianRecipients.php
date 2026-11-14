<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Domain\GuardianRecipient;
use App\Modules\FamUnit\Domain\GuardianRecipients;
use App\Support\Modules\ModuleAvailability;

/**
 * REQ-FAM-UNIT/funcional.md §7.2 (1.14-a). Vínculos activos y vivos de los alumnos, sin los que restringen la
 * categoría pedida; los usuarios, de `PersonDirectory`, solo los vivos. Sin memoización (`RN-FAM-UNIT-34`); vacío con
 * el módulo descontratado (`§9`).
 */
final class EloquentGuardianRecipients implements GuardianRecipients
{
    public function __construct(
        private readonly GuardianLinkService $links,
        private readonly PersonDirectory $people,
        private readonly ModuleAvailability $modules,
    ) {}

    public function recipientsFor(array $studentPersonIds, ?string $category = null): array
    {
        if ($studentPersonIds === [] || ! $this->modules->isEnabled('fam_unit')) {
            return [];
        }

        /** @var array<int, list<int>> $studentsByPerson id de persona del tutor => alumnos */
        $studentsByPerson = [];

        foreach ($this->links->activeLinksOfStudents($studentPersonIds, $category) as $link) {
            if ($link->guardian === null) {
                continue;
            }

            $studentsByPerson[(int) $link->guardian->person_id][] = (int) $link->student_person_id;
        }

        $recipients = [];

        foreach ($this->people->usersByPersonIds(array_keys($studentsByPerson)) as $personId => $user) {
            if ($user->removed) {
                continue;
            }

            $recipients[] = new GuardianRecipient($user->userId, $user->userPublicId, $personId, array_values(array_unique($studentsByPerson[$personId])));
        }

        usort($recipients, static fn (GuardianRecipient $a, GuardianRecipient $b): int => $a->userId <=> $b->userId);

        return $recipients;
    }
}
