<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.3, §3.5, §4.4, §5.3 (`RN-FAM-UNIT-30` a `-38`), 1.14-a: vínculos tutor ↔ alumno y
 * revocación por custodia.
 *
 * **Único servicio sancionado por `AR-10` para consultar `GuardianStudentLink`** (recurso `vinculo_tutor`, ámbito
 * `unidad_familiar`): listado y detalle por `ScopedQuery`, y los fragmentos de consulta que necesitan el resolutor y
 * `FamilyMembership`. Un vínculo **revocado** conserva su fila, su representación legal y su historial (`RN-FAM-UNIT-37`):
 * revocar no borra nada, solo cambia el estado.
 */
final class GuardianLinkService
{
    public const RELATIONSHIPS = ['padre', 'madre', 'tutor_legal', 'abuelo', 'apoderado', 'otro'];

    public const ACCESS_LEVELS = ['lectura', 'lectura_escritura'];

    /** `OPEN-FAM-UNIT-07`: por ahora solo `salud`, el único ejemplo del requisito. */
    public const RESTRICTED_CATEGORIES = ['salud'];

    public const REVOCATION_REASONS = ['resolucion_judicial'];

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly GuardianService $guardians,
        private readonly FamilyUnitService $units,
        private readonly PersonDirectory $people,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras): `404` si no existe o es de otro centro. */
    public function find(string $publicId): GuardianStudentLink
    {
        return GuardianStudentLink::query()->with('guardian')->where('public_id', $publicId)->firstOrFail();
    }

    /** `404` si no existe, es de otro centro o el sujeto no lo alcanza (un vínculo revocado nunca, `RN-FAM-UNIT-36`). */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): GuardianStudentLink
    {
        return $this->hideRevokedWithoutPermission(
            $this->scopedQuery->constrain(GuardianStudentLink::query()->with('guardian'), 'vinculo_tutor', $decision, $subject),
            $subject,
        )->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @param  array{family_unit?: list<string>, guardian?: list<string>, student_person?: list<int>, status?: list<string>, is_emergency_contact?: ?bool, is_legal_guardian?: ?bool}  $filters
     *                                                                                                                                                                                           `student_person` ya resuelto a ids internos de persona (un `public_id` desconocido no está en la lista)
     * @return Builder<GuardianStudentLink>
     */
    public function listing(PermissionDecision $decision, User $subject, array $filters): Builder
    {
        $query = $this->hideRevokedWithoutPermission(
            $this->scopedQuery->constrain(GuardianStudentLink::query()->with('guardian'), 'vinculo_tutor', $decision, $subject),
            $subject,
        );

        if ($filters['guardian'] !== []) {
            $query->whereHas('guardian', fn (Builder $q) => $q->whereIn('public_id', $filters['guardian']));
        }

        if ($filters['family_unit'] !== []) {
            $query->whereHas('guardian.familyUnit', fn (Builder $q) => $q->whereIn('public_id', $filters['family_unit']));
        }

        if (array_key_exists('student_person', $filters)) {
            $query->whereIn('student_person_id', $filters['student_person']);
        }

        if ($filters['status'] !== []) {
            $query->whereIn('status', $filters['status']);
        }

        foreach (['is_emergency_contact', 'is_legal_guardian'] as $flag) {
            if ($filters[$flag] !== null) {
                $query->where($flag, $filters[$flag]);
            }
        }

        return $query;
    }

    /**
     * `RN-FAM-UNIT-40`: quien no tiene `revocacion_tutor.leer` no ve los vínculos `revocado` —ni en el listado ni en el
     * detalle (`404`, sin distinguirlos de un id inexistente)—: el estado `revocado` revela una resolución judicial.
     *
     * @param  Builder<GuardianStudentLink>  $query
     * @return Builder<GuardianStudentLink>
     */
    private function hideRevokedWithoutPermission(Builder $query, User $subject): Builder
    {
        return $this->permissions->decide($subject, 'revocacion_tutor.leer')->permitted
            ? $query
            : $query->where('status', 'activo');
    }

    /**
     * `RN-FAM-UNIT-30`, `-31`, `-32`, `-21`: alta del vínculo. Siempre `activo`. Marcar `is_legal_guardian` rellena la
     * verificación con el instante y el usuario de la petición (`api.md §2.4`).
     *
     * @param  array{guardian: string, student_person: string, relationship: string, is_legal_guardian: bool, access_level: string, restricted_categories: list<string>, is_emergency_contact: bool}  $input
     */
    public function create(array $input, User $actor): GuardianStudentLink
    {
        return $this->inTransaction(function () use ($input, $actor): GuardianStudentLink {
            $guardian = $this->guardians->findOrNull($input['guardian'])
                ?? throw FamUnitErrors::invalid('guardian', 'fam_unit.validation.guardian_not_found');

            $student = $this->people->findByPublicId($input['student_person'])
                ?? throw FamUnitErrors::invalid('student_person', 'fam_unit.validation.student_not_found');

            if ((int) $guardian->person_id === $student->id) {
                throw FamUnitErrors::invalid('student_person', 'fam_unit.link.self_link');
            }

            if (GuardianStudentLink::query()->where('guardian_id', $guardian->id)->where('student_person_id', $student->id)->exists()) {
                throw FamUnitErrors::invalid('guardian', 'fam_unit.link.already_exists');
            }

            if ($input['is_emergency_contact']) {
                $this->assertNoOtherEmergencyContact($student->id, null);
            }

            // Tutor, alumno y estado no son asignables en masa (B2): se asignan por instancia.
            $link = new GuardianStudentLink([
                'relationship' => $input['relationship'],
                'is_legal_guardian' => $input['is_legal_guardian'],
                'access_level' => $input['access_level'],
                'restricted_categories' => $input['restricted_categories'],
                'is_emergency_contact' => $input['is_emergency_contact'],
            ]);
            $link->guardian_id = $guardian->id;
            $link->student_person_id = $student->id;
            $link->status = 'activo';

            if ($input['is_legal_guardian']) {
                $link->legal_guardian_verified_at = now();
                $link->legal_guardian_verified_by = $actor->id;
            }

            $link->save();

            return $link->load('guardian');
        });
    }

    /**
     * Edición de los atributos del vínculo (no de `guardian`, `student_person` ni el estado: `not_editable` lo exige
     * la petición). Desmarcar la representación legal vacía la verificación; marcarla (si no lo estaba) la rellena.
     *
     * @param  array{relationship?: string, is_legal_guardian?: bool, access_level?: string, restricted_categories?: list<string>, is_emergency_contact?: bool}  $input
     */
    public function update(string $publicId, array $input, User $actor): GuardianStudentLink
    {
        return $this->inTransaction(function () use ($publicId, $input, $actor): GuardianStudentLink {
            $link = GuardianStudentLink::query()->with('guardian')->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            foreach (['relationship', 'access_level'] as $field) {
                if (array_key_exists($field, $input)) {
                    $link->{$field} = $input[$field];
                }
            }

            if (array_key_exists('restricted_categories', $input)) {
                $link->restricted_categories = $input['restricted_categories'];
            }

            if (array_key_exists('is_legal_guardian', $input) && $input['is_legal_guardian'] !== (bool) $link->is_legal_guardian) {
                $link->is_legal_guardian = $input['is_legal_guardian'];
                $link->legal_guardian_verified_at = $input['is_legal_guardian'] ? now() : null;
                $link->legal_guardian_verified_by = $input['is_legal_guardian'] ? $actor->id : null;
            }

            if (array_key_exists('is_emergency_contact', $input) && $input['is_emergency_contact'] !== (bool) $link->is_emergency_contact) {
                if ($input['is_emergency_contact']) {
                    if ($link->status !== 'activo') {
                        throw FamUnitErrors::invalid('is_emergency_contact', 'fam_unit.link.emergency_contact_revoked');
                    }

                    $this->assertNoOtherEmergencyContact((int) $link->student_person_id, (int) $link->id);
                }

                $link->is_emergency_contact = $input['is_emergency_contact'];
            }

            $link->save();

            return $link;
        });
    }

    /** `RN-FAM-UNIT-04`: baja lógica del vínculo (no judicial); `409 revoked_not_deletable` si está revocado (`RN-FAM-UNIT-39`). */
    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $link = GuardianStudentLink::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            // `RN-FAM-UNIT-39`: un vínculo revocado solo se levanta por su recurso, nunca se da de baja.
            if ($link->status === 'revocado') {
                throw FamUnitErrors::conflict('status', 'fam_unit.link.revoked_not_deletable');
            }

            $link->delete();
        });
    }

    /**
     * `RN-FAM-UNIT-33`, `-31`, `-38`: revoca un vínculo `activo` por resolución judicial. Efecto inmediato. Quita la
     * marca de contacto de emergencia en la misma transacción y, si era el último vínculo activo del tutor principal
     * con alumnos de su unidad, deja la unidad sin principal. No toca la representación legal (`RN-FAM-UNIT-37`).
     *
     * @return array{link: GuardianStudentLink, principal_cleared: bool}
     */
    public function revoke(string $publicId, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($publicId, $reason, $actor): array {
            $link = GuardianStudentLink::query()->with('guardian')->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($link->status !== 'activo') {
                throw FamUnitErrors::conflict('status', 'fam_unit.link.invalid_transition');
            }

            $link->status = 'revocado';
            $link->revocation_reason = $reason;
            $link->revoked_at = now();
            $link->revoked_by = $actor->id;
            $link->is_emergency_contact = false;
            $link->save();

            $cleared = $this->units->clearPrincipalIfOrphaned((int) $link->guardian_id, (int) $link->student_person_id);

            return ['link' => $link, 'principal_cleared' => $cleared];
        });
    }

    /** `RN-FAM-UNIT-33`, `-37`: levanta la revocación de un vínculo `revocado`; vuelve a `activo`. */
    public function lift(string $publicId): GuardianStudentLink
    {
        return DB::transaction(function () use ($publicId): GuardianStudentLink {
            $link = GuardianStudentLink::query()->with('guardian')->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($link->status !== 'revocado') {
                throw FamUnitErrors::conflict('status', 'fam_unit.link.invalid_transition');
            }

            $link->status = 'activo';
            $link->revocation_reason = null;
            $link->revoked_at = null;
            $link->revoked_by = null;
            $link->save();

            return $link;
        });
    }

    // ─── Fragmentos de consulta y lecturas para el resolutor y las interfaces públicas (`funcional.md §7`) ───

    /**
     * Subconsulta de los ids de persona de los alumnos con vínculo **activo** (y vivo) con el tutor `$guardianId`
     * (`funcional.md §3.6`). Con `$category`, excluye los vínculos que la restringen.
     *
     * @return Builder<GuardianStudentLink>
     */
    public function reachableStudentIds(int $guardianId, ?string $category = null): Builder
    {
        return GuardianStudentLink::query()
            ->select('student_person_id')
            ->where('guardian_id', $guardianId)
            ->where('status', 'activo')
            ->when($category !== null, fn (Builder $q) => $q->whereJsonDoesntContain('restricted_categories', $category));
    }

    /**
     * Subconsulta de los ids de tutor con vínculo activo con alguno de esos alumnos.
     *
     * @param  Builder<*>  $studentPersonIds  subconsulta de ids de persona
     * @return Builder<GuardianStudentLink>
     */
    public function guardianIdsOfStudents(Builder $studentPersonIds): Builder
    {
        return GuardianStudentLink::query()->select('guardian_id')->where('status', 'activo')->whereIn('student_person_id', $studentPersonIds);
    }

    /**
     * Ids de persona de los alumnos alcanzados por el tutor, ordenados y sin repetidos.
     *
     * @return list<int>
     */
    public function reachableStudentIdList(int $guardianId, ?string $category = null): array
    {
        return array_map('intval', $this->reachableStudentIds($guardianId, $category)->distinct()->orderBy('student_person_id')->pluck('student_person_id')->all());
    }

    /**
     * Vínculos activos del alumno, con su tutor.
     *
     * @return list<GuardianStudentLink>
     */
    public function activeLinksOfStudent(int $studentPersonId): array
    {
        return GuardianStudentLink::query()->with('guardian')->where('student_person_id', $studentPersonId)->where('status', 'activo')->orderBy('id')->get()->all();
    }

    /**
     * Vínculos activos con esos alumnos (para `GuardianRecipients`), con su tutor; sin los que restringen `$category`.
     *
     * @param  list<int>  $studentPersonIds
     * @return list<GuardianStudentLink>
     */
    public function activeLinksOfStudents(array $studentPersonIds, ?string $category): array
    {
        if ($studentPersonIds === []) {
            return [];
        }

        return GuardianStudentLink::query()
            ->with('guardian')
            ->whereIn('student_person_id', array_values(array_unique($studentPersonIds)))
            ->where('status', 'activo')
            ->when($category !== null, fn (Builder $q) => $q->whereJsonDoesntContain('restricted_categories', $category))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** ¿Existe un vínculo vivo `revocado` entre el tutor y el alumno? (`FamilyMembership::isAccessRevoked()`.) */
    public function hasRevokedLink(int $guardianId, int $studentPersonId): bool
    {
        return GuardianStudentLink::query()->where('guardian_id', $guardianId)->where('student_person_id', $studentPersonId)->where('status', 'revocado')->exists();
    }

    /** Vínculos vivos del alumno, de cualquier estado (`RN-FAM-UNIT-22`). */
    public function liveCountForStudent(int $studentPersonId): int
    {
        return GuardianStudentLink::query()->where('student_person_id', $studentPersonId)->count();
    }

    /** `RN-FAM-UNIT-31`: no hay otro contacto de emergencia activo del alumno (el índice único cierra la carrera). */
    private function assertNoOtherEmergencyContact(int $studentPersonId, ?int $exceptLinkId): void
    {
        $taken = GuardianStudentLink::query()
            ->where('student_person_id', $studentPersonId)
            ->where('is_emergency_contact', true)
            ->where('status', 'activo')
            ->when($exceptLinkId !== null, fn (Builder $q) => $q->whereKeyNot($exceptLinkId))
            ->exists();

        if ($taken) {
            throw FamUnitErrors::invalid('is_emergency_contact', 'fam_unit.link.emergency_contact_taken');
        }
    }

    /**
     * Las violaciones de unicidad de una carrera (SQLSTATE 23505) se traducen al mismo `422` que la comprobación
     * previa, nunca a `500`: el índice del contacto de emergencia y el del par (tutor, alumno).
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
                if (str_contains($e->getMessage(), 'guardian_student_links_tenant_student_emergency_unique')) {
                    throw FamUnitErrors::invalid('is_emergency_contact', 'fam_unit.link.emergency_contact_taken');
                }

                if (str_contains($e->getMessage(), 'guardian_student_links_tenant_guardian_student_unique')) {
                    throw FamUnitErrors::invalid('guardian', 'fam_unit.link.already_exists');
                }
            }

            throw $e;
        }
    }
}
