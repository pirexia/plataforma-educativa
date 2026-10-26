<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\FamilyUnitStudents;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.1, §4.3, §5.2 (`RN-FAM-UNIT-20` a `-22`), §7.4, 1.14-a: alumnado de la unidad. El alumno
 * se identifica por su persona (`OPEN-FAM-UNIT-01`).
 *
 * Implementa `FamilyUnitStudents` —la puerta de escritura de `REQ-ALUM` (1.15)— y es la misma clase que usa el
 * *endpoint*: reglas y auditoría idénticas (`CA-FAM-UNIT-011`). Servicio sancionado por `AR-10` para consultar
 * `FamilyUnitStudent` (recurso `familia`).
 */
final class FamilyUnitStudentService implements FamilyUnitStudents
{
    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly FamilyUnitService $units,
        private readonly GuardianLinkService $links,
        private readonly PersonDirectory $people,
    ) {}

    /**
     * Pertenencias de la unidad `$familyUnitPublicId` que el sujeto alcanza (`404` si no alcanza la unidad).
     *
     * @return list<FamilyUnitStudent>
     */
    public function listForUnit(string $familyUnitPublicId, PermissionDecision $decision, User $subject): array
    {
        $unit = $this->units->findScoped($familyUnitPublicId, $decision, $subject);

        return $this->scopedQuery->constrain(FamilyUnitStudent::query(), 'familia', $decision, $subject)
            ->where('family_unit_id', $unit->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Pertenencia de la unidad indicada (sin acotar por ámbito: escrituras); `404` si no existe o es de otra unidad. */
    public function findInUnit(string $familyUnitPublicId, string $membershipPublicId): FamilyUnitStudent
    {
        $unit = $this->units->find($familyUnitPublicId);

        return FamilyUnitStudent::query()
            ->where('public_id', $membershipPublicId)
            ->where('family_unit_id', $unit->id)
            ->firstOrFail();
    }

    public function add(string $familyUnitPublicId, int $studentPersonId): string
    {
        try {
            return DB::transaction(function () use ($familyUnitPublicId, $studentPersonId): string {
                // `FOR SHARE`: una baja concurrente de la unidad espera (`RN-FAM-UNIT-03`). `404`: la unidad es de la ruta.
                $unit = $this->units->findOrNullForShare($familyUnitPublicId)
                    ?? throw new ModelNotFoundException;

                $person = $this->people->refsByIds([$studentPersonId])[$studentPersonId] ?? null;

                if ($person === null || $person->removed) {
                    throw FamUnitErrors::invalid('student_person', 'fam_unit.validation.student_not_found');
                }

                if (FamilyUnitStudent::query()->where('student_person_id', $studentPersonId)->exists()) {
                    throw FamUnitErrors::invalid('student_person', 'fam_unit.student.already_in_unit');
                }

                $membership = new FamilyUnitStudent(['family_unit_id' => $unit->id, 'student_person_id' => $studentPersonId]);
                $membership->save();

                return (string) $membership->public_id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw FamUnitErrors::invalid('student_person', 'fam_unit.student.already_in_unit');
            }

            throw $e;
        }
    }

    public function remove(string $membershipPublicId): int
    {
        return DB::transaction(function () use ($membershipPublicId): int {
            $membership = FamilyUnitStudent::query()->where('public_id', $membershipPublicId)->lockForUpdate()->firstOrFail();
            $membership->delete();

            // `RN-FAM-UNIT-22`: los vínculos del alumno no se tocan (pueden cruzar unidades).
            return $this->links->liveCountForStudent((int) $membership->student_person_id);
        });
    }

    /**
     * Subconsulta de las unidades que contienen a alguno de esos alumnos, para el resolutor `unidad_familiar`.
     *
     * @param  Builder<*>  $studentPersonIds  subconsulta de ids de persona
     * @return Builder<FamilyUnitStudent>
     */
    public function unitIdsOfStudents(Builder $studentPersonIds): Builder
    {
        return FamilyUnitStudent::query()->select('family_unit_id')->whereIn('student_person_id', $studentPersonIds);
    }

    /**
     * La unidad del alumno y el id de persona de su tutor principal (`FamilyMembership::unitOfStudent()`).
     *
     * @return array{public_id: string, principal_person_id: ?int}|null
     */
    public function unitOfStudent(int $studentPersonId): ?array
    {
        $membership = FamilyUnitStudent::query()->with('familyUnit.principalGuardian')->where('student_person_id', $studentPersonId)->first();
        $unit = $membership?->familyUnit;

        if ($unit === null) {
            return null;
        }

        /** @var Guardian|null $principal */
        $principal = $unit->principalGuardian;

        return ['public_id' => (string) $unit->public_id, 'principal_person_id' => $principal === null ? null : (int) $principal->person_id];
    }
}
