<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.2, §4.2, §5.1 (`RN-FAM-UNIT-10` a `-14`), 1.14-a: tutores (faceta de `people`).
 *
 * Servicio sancionado por `AR-10` para consultar `Guardian` (recurso `familia`): listado y detalle por `ScopedQuery`.
 * Este módulo no crea personas, cuentas, invitaciones ni asignaciones de rol (`RN-FAM-UNIT-10`): parte de un usuario
 * que ya existe en `Core`, resuelto por `PersonDirectory`.
 */
final class GuardianService
{
    /** `RN-FAM-UNIT-10`: estados de cuenta con los que un usuario puede ser tutor. */
    private const ELIGIBLE_STATUSES = ['pendiente', 'activo'];

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly FamilyUnitService $units,
        private readonly PersonDirectory $people,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras): `404` si no existe o es de otro centro. */
    public function find(string $publicId): Guardian
    {
        return Guardian::query()->with('familyUnit')->where('public_id', $publicId)->firstOrFail();
    }

    public function findOrNull(string $publicId): ?Guardian
    {
        return Guardian::query()->where('public_id', $publicId)->first();
    }

    /** `404` si no existe, es de otro centro o el sujeto no lo alcanza (`ADR-044 §4.2`). */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): Guardian
    {
        return $this->scopedQuery->constrain(Guardian::query()->with('familyUnit'), 'familia', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * @param  array{family_unit: list<string>, user: list<string>, sort: string}  $filters
     * @return Builder<Guardian>
     */
    public function listing(PermissionDecision $decision, User $subject, array $filters): Builder
    {
        $query = $this->scopedQuery->constrain(Guardian::query()->with('familyUnit'), 'familia', $decision, $subject);

        if ($filters['family_unit'] !== []) {
            $query->whereHas('familyUnit', fn (Builder $q) => $q->whereIn('public_id', $filters['family_unit']));
        }

        if ($filters['user'] !== []) {
            // Un `public_id` de usuario inexistente o de otro centro no coincide con nada (`api.md §3`).
            $personIds = [];

            foreach ($filters['user'] as $userPublicId) {
                $ref = $this->people->findUserByPublicId($userPublicId);

                if ($ref !== null) {
                    $personIds[] = $ref->personId;
                }
            }

            $query->whereIn('person_id', $personIds);
        }

        $direction = str_starts_with($filters['sort'], '-') ? 'desc' : 'asc';

        return $query->orderBy('created_at', $direction)->orderBy('id');
    }

    /** El id del tutor vivo de esa persona; `null` si la persona no es tutora. Sin memoización. */
    public function guardianIdForPerson(int $personId): ?int
    {
        $id = Guardian::query()->where('person_id', $personId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Ids de persona de los tutores con esos ids internos (vivos).
     *
     * @param  list<int>  $guardianIds
     * @return array<int, int> id de tutor => id de persona
     */
    public function personIdsByGuardianIds(array $guardianIds): array
    {
        if ($guardianIds === []) {
            return [];
        }

        $map = [];

        foreach (Guardian::query()->whereIn('id', array_values(array_unique($guardianIds)))->get(['id', 'person_id']) as $guardian) {
            $map[(int) $guardian->id] = (int) $guardian->person_id;
        }

        return $map;
    }

    /**
     * `RN-FAM-UNIT-10`, `-11`: alta del tutor a partir de un usuario del centro vivo, `pendiente` o `activo`, en una
     * unidad viva. No crea ni modifica la persona ni el usuario.
     */
    public function create(string $userPublicId, string $familyUnitPublicId): Guardian
    {
        try {
            return DB::transaction(function () use ($userPublicId, $familyUnitPublicId): Guardian {
                // `FOR SHARE`: una baja concurrente de la unidad espera (`RN-FAM-UNIT-03`).
                $unit = $this->units->findOrNullForShare($familyUnitPublicId)
                    ?? throw FamUnitErrors::invalid('family_unit', 'fam_unit.validation.family_unit_not_found');

                $user = $this->people->findUserByPublicId($userPublicId)
                    ?? throw FamUnitErrors::invalid('user', 'fam_unit.validation.user_not_found');

                if (! in_array($user->status, self::ELIGIBLE_STATUSES, true)) {
                    throw FamUnitErrors::invalid('user', 'fam_unit.guardian.user_not_eligible');
                }

                if (Guardian::query()->where('person_id', $user->personId)->exists()) {
                    throw FamUnitErrors::invalid('user', 'fam_unit.guardian.already_exists');
                }

                $guardian = new Guardian(['person_id' => $user->personId, 'family_unit_id' => $unit->id]);
                $guardian->save();

                return $guardian->load('familyUnit');
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw FamUnitErrors::invalid('user', 'fam_unit.guardian.already_exists');
            }

            throw $e;
        }
    }

    /**
     * `RN-FAM-UNIT-14` (decisión del usuario, 2026-10-26): un tutor no cambia de unidad y no tiene ningún otro campo
     * editable en `1.14-a`, así que no hay nada que escribir. Devuelve el tutor tal cual.
     */
    public function update(string $publicId): Guardian
    {
        return $this->find($publicId);
    }

    /**
     * `RN-FAM-UNIT-12`, `-39`: la baja del tutor da de baja, en la misma transacción, sus vínculos vivos; si era el principal
     * de su unidad, la unidad queda sin principal.
     *
     * @return array{removed_links: int, principal_cleared: bool}
     */
    public function delete(string $publicId): array
    {
        return DB::transaction(function () use ($publicId): array {
            $guardian = Guardian::query()->where('public_id', $publicId)->firstOrFail();

            // Bloquea la unidad: la designación concurrente de este tutor como principal espera o ve la baja.
            $unit = FamilyUnit::query()->whereKey($guardian->family_unit_id)->lockForUpdate()->first();

            $links = $guardian->links()->lockForUpdate()->get();

            // `RN-FAM-UNIT-39`: un vínculo revocado bloquea la baja del tutor (la revocación se levanta antes).
            if ($links->contains(static fn ($link): bool => $link->status === 'revocado')) {
                throw FamUnitErrors::conflict('status', 'fam_unit.link.revoked_not_deletable');
            }

            $removed = 0;

            foreach ($links as $link) {
                $link->delete();
                $removed++;
            }

            $cleared = false;

            if ($unit !== null && (int) $unit->principal_guardian_id === (int) $guardian->id) {
                $unit->principal_guardian_id = null;
                $unit->save();
                $cleared = true;
            }

            $guardian->delete();

            return ['removed_links' => $removed, 'principal_cleared' => $cleared];
        });
    }
}
