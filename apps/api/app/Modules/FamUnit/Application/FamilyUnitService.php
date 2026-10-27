<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.1, §4.1, §5.1 (`RN-FAM-UNIT-01` a `-04`), 1.14-a: unidades familiares.
 *
 * Uno de los tres servicios sancionados por `AR-10` para consultar `FamilyUnit`, `Guardian` y `FamilyUnitStudent`
 * (recurso `familia`, ámbito `unidad_familiar`): el listado y el detalle pasan por `ScopedQuery`. Las dependientes
 * de otros recursos (los vínculos) se leen por relación, nunca por consulta estática (`permisos.md §3.1`).
 */
final class FamilyUnitService
{
    public const CODE_MAX_LENGTH = 40;

    /** Prefijo y anchura del código que genera el sistema (`funcional.md §15`, D-1). */
    private const GENERATED_PREFIX = 'UF-';

    private const GENERATED_WIDTH = 6;

    public function __construct(private readonly ScopedQuery $scopedQuery) {}

    /** Detalle sin acotar por ámbito (escrituras, que exigen ámbito `todos`): `404` si no existe o es de otro centro. */
    public function find(string $publicId): FamilyUnit
    {
        return FamilyUnit::query()->where('public_id', $publicId)->firstOrFail();
    }

    public function findOrNull(string $publicId): ?FamilyUnit
    {
        return FamilyUnit::query()->where('public_id', $publicId)->first();
    }

    /** `FOR SHARE` de la unidad viva: una baja concurrente espera o pierde (`RN-FAM-UNIT-03`). */
    public function findOrNullForShare(string $publicId): ?FamilyUnit
    {
        return FamilyUnit::query()->where('public_id', $publicId)->sharedLock()->first();
    }

    /**
     * Detalle acotado por ámbito (`ADR-044 §4.2`): `404` si no existe, es de otro centro o el sujeto no lo alcanza.
     */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): FamilyUnit
    {
        return $this->scopedQuery->constrain(FamilyUnit::query(), 'familia', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * Consulta del listado, acotada por ámbito.
     *
     * @param  array{q?: ?string, has_principal?: ?bool, include_deleted?: bool}  $filters
     * @return Builder<FamilyUnit>
     */
    public function listing(PermissionDecision $decision, User $subject, array $filters): Builder
    {
        $query = $this->scopedQuery->constrain(FamilyUnit::query(), 'familia', $decision, $subject)->with('principalGuardian');

        if (($filters['include_deleted'] ?? false) === true) {
            $query->withTrashed();
        }

        $prefix = $filters['q'] ?? null;

        if ($prefix !== null && $prefix !== '') {
            // Prefijo literal: se escapan los comodines de LIKE (`family_units_tenant_code_prefix_idx`).
            $query->where('code', 'like', addcslashes($prefix, '\\%_').'%');
        }

        $hasPrincipal = $filters['has_principal'] ?? null;

        if ($hasPrincipal !== null) {
            $hasPrincipal ? $query->whereNotNull('principal_guardian_id') : $query->whereNull('principal_guardian_id');
        }

        return $query;
    }

    /**
     * `guardians_count` y `students_count` de cada unidad, como atributos efímeros (`api.md §1.1`). Con
     * `$reachableStudents` (sujeto con ámbito `unidad_familiar`), cuentan solo los alumnos que alcanza y los tutores
     * con vínculo activo con ellos (`RN-FAM-UNIT-36`).
     *
     * @param  iterable<FamilyUnit>  $units
     * @param  Builder<*>|null  $reachableStudents  subconsulta de ids de persona de alumnos alcanzados
     */
    public function attachCounts(iterable $units, ?Builder $reachableStudents): void
    {
        $byId = [];

        foreach ($units as $unit) {
            $byId[(int) $unit->id] = $unit;
        }

        if ($byId === []) {
            return;
        }

        $ids = array_keys($byId);

        $students = FamilyUnitStudent::query()
            ->whereIn('family_unit_id', $ids)
            ->when($reachableStudents !== null, fn (Builder $q) => $q->whereIn('student_person_id', $reachableStudents))
            ->selectRaw('family_unit_id, count(*) as total')
            ->groupBy('family_unit_id')
            ->pluck('total', 'family_unit_id');

        $guardians = Guardian::query()
            ->whereIn('family_unit_id', $ids)
            ->when($reachableStudents !== null, fn (Builder $q) => $q->whereHas('links', fn (Builder $l) => $l
                ->where('status', 'activo')
                ->whereIn('student_person_id', $reachableStudents)))
            ->selectRaw('family_unit_id, count(*) as total')
            ->groupBy('family_unit_id')
            ->pluck('total', 'family_unit_id');

        foreach ($byId as $id => $unit) {
            $unit->setAttribute('students_count', (int) ($students[$id] ?? 0));
            $unit->setAttribute('guardians_count', (int) ($guardians[$id] ?? 0));
        }
    }

    /**
     * `public_id` y código de las unidades con esos ids internos (también las dadas de baja), para las
     * representaciones que nombran la unidad de un alumno (`ImageConsent`, `api.md §1.5`).
     *
     * @param  list<int>  $ids
     * @return array<int, array{public_id: string, code: string}>
     */
    public function refsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $refs = [];

        foreach (FamilyUnit::withTrashed()->whereIn('id', array_values(array_unique($ids)))->get(['id', 'public_id', 'code']) as $unit) {
            $refs[(int) $unit->id] = ['public_id' => (string) $unit->public_id, 'code' => (string) $unit->code];
        }

        return $refs;
    }

    /**
     * `RN-FAM-UNIT-01`, `-04` y `funcional.md §15` D-1. Sin `$code`, lo genera el sistema.
     */
    public function create(?string $code): FamilyUnit
    {
        return $this->inTransaction(function () use ($code): FamilyUnit {
            if ($code === null) {
                $code = $this->nextGeneratedCode();
            } else {
                $this->assertCodeFree($code);
            }

            $unit = new FamilyUnit(['code' => $code]);
            $unit->save();

            return $unit;
        });
    }

    /**
     * `RN-FAM-UNIT-01`, `-02`: `code` y/o `principal_guardian` (`public_id` de un tutor de esta unidad, o `null`).
     * Solo se escribe lo que cambia.
     *
     * @param  array{code?: string, principal_guardian?: ?string}  $input
     */
    public function update(string $publicId, array $input): FamilyUnit
    {
        return $this->inTransaction(function () use ($publicId, $input): FamilyUnit {
            $unit = FamilyUnit::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if (array_key_exists('code', $input)) {
                $this->assertCodeFree($input['code'], $unit->id);
                $unit->code = $input['code'];
            }

            if (array_key_exists('principal_guardian', $input)) {
                $unit->principal_guardian_id = $input['principal_guardian'] === null
                    ? null
                    : $this->principalCandidate($unit, $input['principal_guardian'])->id;
            }

            $unit->save();

            return $unit;
        });
    }

    /**
     * `RN-FAM-UNIT-03`, `-04`: baja lógica; `409 has_members` si tiene tutores o alumnado vivos. `FOR UPDATE` de la
     * unidad: el alta concurrente de un tutor o alumno la toma `FOR SHARE`.
     */
    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $unit = FamilyUnit::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            $hasMembers = Guardian::query()->where('family_unit_id', $unit->id)->exists()
                || FamilyUnitStudent::query()->where('family_unit_id', $unit->id)->exists();

            if ($hasMembers) {
                throw FamUnitErrors::conflict('family_unit', 'fam_unit.family_unit.has_members');
            }

            $unit->delete();
        });
    }

    /** `RN-FAM-UNIT-04`: la restauración reaplica la unicidad del código del alta. */
    public function restore(string $publicId): FamilyUnit
    {
        return $this->inTransaction(function () use ($publicId): FamilyUnit {
            $unit = FamilyUnit::onlyTrashed()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            $this->assertCodeFree($unit->code, $unit->id);
            $unit->restore();

            return $unit;
        });
    }

    /**
     * `RN-FAM-UNIT-38`: si el tutor `$guardianId` es el principal de su unidad y, tras revocar el vínculo con el
     * alumno `$studentPersonId`, ya no le queda ningún vínculo activo con alumnos de esa unidad, la unidad queda sin
     * principal. Devuelve si se limpió. Los vínculos se leen por la relación del tutor, no por consulta estática
     * (`permisos.md §3.1`).
     */
    public function clearPrincipalIfOrphaned(int $guardianId, int $studentPersonId): bool
    {
        $unit = FamilyUnit::query()->where('principal_guardian_id', $guardianId)->lockForUpdate()->first();
        $guardian = $unit === null ? null : Guardian::query()->find($guardianId);

        if ($unit === null || $guardian === null) {
            return false;
        }

        $studentIds = array_map('intval', FamilyUnitStudent::query()->where('family_unit_id', $unit->id)->pluck('student_person_id')->all());

        // El vínculo revocado debe ser con un alumno de esta unidad, y no quedar otro activo con alumnos de ella.
        if (! in_array($studentPersonId, $studentIds, true)
            || $guardian->links()->where('status', 'activo')->whereIn('student_person_id', $studentIds)->exists()) {
            return false;
        }

        $unit->principal_guardian_id = null;
        $unit->save();

        return true;
    }

    /** Tutor vivo de esta unidad que se propone como principal (`RN-FAM-UNIT-02`). */
    private function principalCandidate(FamilyUnit $unit, string $guardianPublicId): Guardian
    {
        $guardian = Guardian::query()->where('public_id', $guardianPublicId)->first();

        if ($guardian === null) {
            throw FamUnitErrors::invalid('principal_guardian', 'fam_unit.validation.principal_guardian_not_found');
        }

        if ((int) $guardian->family_unit_id !== (int) $unit->id) {
            throw FamUnitErrors::invalid('principal_guardian', 'fam_unit.family_unit.principal_not_member');
        }

        return $guardian;
    }

    /** `RN-FAM-UNIT-01`: código único entre las unidades vivas (comprobación previa; el índice cierra la carrera). */
    private function assertCodeFree(string $code, ?int $exceptId = null): void
    {
        $taken = FamilyUnit::query()
            ->where('code', $code)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw FamUnitErrors::invalid('code', 'fam_unit.family_unit.code_taken');
        }
    }

    /**
     * `UF-` + número de seis cifras secuencial por centro: el máximo existente (vivas y borradas, para no reutilizar
     * un número) + 1, bajo un bloqueo consultivo de la transacción por centro (`funcional.md §15`, D-1).
     */
    private function nextGeneratedCode(): string
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['fam_unit_code:'.app(TenantContext::class)->tenantId()]);

        $max = FamilyUnit::withTrashed()
            ->whereRaw("code ~ '^UF-[0-9]{1,9}$'")
            ->selectRaw('max(substr(code, 4)::bigint) as max_number')
            ->value('max_number');

        return self::GENERATED_PREFIX.str_pad((string) (((int) $max) + 1), self::GENERATED_WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * La escritura en una transacción. Una violación de la unicidad parcial del código (SQLSTATE 23505), la de una
     * carrera entre dos altas, se traduce al mismo `422 code_taken` que la comprobación previa, nunca a `500`.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function inTransaction(Closure $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw FamUnitErrors::invalid('code', 'fam_unit.family_unit.code_taken');
            }

            throw $e;
        }
    }
}
