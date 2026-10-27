<?php

namespace App\Modules\FamUnit\Application;

use App\Modules\Core\Domain\PersonDirectory;

/**
 * REQ-FAM-UNIT/api.md §3, §4 (1.14-b): los filtros de `GET /image-consents` y del informe (`POST /image-consents/exports`),
 * con la **misma** semántica (paridad de filtros, `ADR-054 §8.2`). El alumno se nombra por el `public_id` de su persona y
 * se resuelve a id interno aquí; un `public_id` desconocido o de otro centro no coincide con nada (no es error).
 */
final class ImageConsentFilters
{
    /**
     * @param  array{family_unit?: list<string>, student_person?: list<string>, level?: list<string>, state?: list<string>}  $raw
     * @return array{family_unit: list<string>, student_person: ?list<int>, level: list<string>, state: list<string>}
     */
    public static function resolve(array $raw, PersonDirectory $people): array
    {
        $students = null;

        if (($raw['student_person'] ?? []) !== []) {
            $students = array_values(array_filter(array_map(
                static fn (string $id): ?int => $people->findByPublicId($id)?->id,
                $raw['student_person'],
            ), static fn (?int $id): bool => $id !== null));
        }

        return [
            'family_unit' => $raw['family_unit'] ?? [],
            'student_person' => $students,
            'level' => $raw['level'] ?? [],
            'state' => $raw['state'] ?? [],
        ];
    }
}
