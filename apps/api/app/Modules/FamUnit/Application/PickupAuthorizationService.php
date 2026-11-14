<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §21.5.1 a §21.5.3, §21.6 (`RN-FAM-UNIT-82`, `-85` a `-88`), api.md §8.3 (1.14b-a, `ADR-068`):
 * alta, cambio de vigencia y retirada de las autorizaciones de tercero y puntuales. **Toda la validación de negocio es de
 * servidor** (`INV-010`); la forma del cuerpo la valida la petición. Todo el acceso a `PickupAuthorization` va por
 * `PickupListService` (`AR-10`). La auditoría la hace el ciclo de vida del modelo (`INV-003`, `Selective`).
 *
 * Alta, por orden: canal (`telefono` solo con `puntual`, `OPEN-FAM-UNIT-35`), la persona (no el propio alumno ni una con el
 * acceso revocado a ese alumno: mismo cuerpo en ambos casos, `RN-FAM-UNIT-86`), el tutor que autoriza (el principal de la
 * unidad del alumno **y** con vínculo activo con representación legal sobre él, `RN-FAM-UNIT-85`), el texto
 * `autorizacion_recogida` (hay uno publicado y el indicado es publicado o retirado, `RN-FAM-UNIT-85`/`-69`), y que no haya ya
 * una viva idéntica (`RN-FAM-UNIT-88`, cerrado por el índice único). Nada se escribe si falla algo (fallo en cerrado).
 */
final class PickupAuthorizationService
{
    public function __construct(
        private readonly PickupListService $list,
        private readonly PersonDirectory $people,
        private readonly GuardianService $guardians,
        private readonly GuardianLinkService $links,
        private readonly FamilyUnitStudentService $students,
        private readonly TermsVersionService $terms,
    ) {}

    /**
     * @param  array{student_person: string, person: string, relationship: string, kind: string, valid_from: string, valid_until: ?string, reason: ?string, authorized_by: string, terms_version: string, channel: string, authorized_at: ?CarbonImmutable}  $input
     * @param  array{ip: ?string, user_agent: ?string}  $client
     */
    public function create(array $input, User $actor, array $client): PickupAuthorization
    {
        $isPunctual = $input['kind'] === PickupAuthorization::KIND_PUNCTUAL;

        // `OPEN-FAM-UNIT-35`: el teléfono solo autoriza una recogida no prevista.
        if ($input['channel'] === 'telefono' && ! $isPunctual) {
            throw FamUnitErrors::invalid('channel', 'fam_unit.pickup.channel_requires_puntual');
        }

        $student = $this->people->findByPublicId($input['student_person'])
            ?? throw FamUnitErrors::invalid('student_person', 'fam_unit.validation.student_not_found');
        $person = $this->people->findByPublicId($input['person'])
            ?? throw FamUnitErrors::invalid('person', 'fam_unit.validation.person_not_found');

        // `RN-FAM-UNIT-86`: el propio alumno y quien tiene el acceso revocado no son elegibles, con el mismo cuerpo.
        $guardianId = $this->guardians->guardianIdForPerson($person->id);

        if ($person->id === $student->id || ($guardianId !== null && $this->links->hasRevokedLink($guardianId, $student->id))) {
            throw FamUnitErrors::invalid('person', 'fam_unit.pickup.person_not_eligible');
        }

        $authorizedBy = $this->principalGuardianId($input['authorized_by'], $student->id);
        $version = $this->textVersion($input['terms_version']);

        $now = now()->toImmutable();
        $authorizedAt = $input['authorized_at'] ?? $now;

        if ($authorizedAt->greaterThan($now)) {
            throw FamUnitErrors::invalid('authorized_at', 'fam_unit.validation.authorized_at_future');
        }

        $validUntil = $isPunctual ? $input['valid_from'] : $input['valid_until'];

        try {
            return DB::transaction(function () use ($input, $actor, $client, $student, $person, $authorizedBy, $version, $authorizedAt, $validUntil, $isPunctual): PickupAuthorization {
                if ($this->list->liveDuplicateExists($student->id, $person->id, $input['kind'], $input['valid_from'])) {
                    throw FamUnitErrors::invalid('person', 'fam_unit.pickup.already_exists');
                }

                return $this->list->create([
                    'student_person_id' => $student->id,
                    'person_id' => $person->id,
                    'relationship' => $input['relationship'],
                    'kind' => $input['kind'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $validUntil,
                    'reason' => $isPunctual ? $input['reason'] : ($input['reason'] ?? null),
                    'authorized_by_guardian_id' => $authorizedBy,
                    'terms_version_id' => (int) $version->id,
                    'channel' => $input['channel'],
                    'authorized_at' => Carbon::instance($authorizedAt),
                ], $actor, $client);
            });
        } catch (QueryException $e) {
            // Dos altas simultáneas idénticas: las cierra el índice único parcial (`RN-FAM-UNIT-88`), nunca un 500.
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'pickup_authorizations_')) {
                throw FamUnitErrors::invalid('person', 'fam_unit.pickup.already_exists');
            }

            throw $e;
        }
    }

    /**
     * Cambia el fin de vigencia de una `permanente` (`api.md §8.3`). Adelantarla es fallo en cerrado y siempre se admite;
     * `null` quita la caducidad. `409 pickup.withdrawn` si está retirada; una `puntual` no se edita.
     */
    public function updateValidUntil(string $publicId, ?string $validUntil): PickupAuthorization
    {
        return DB::transaction(function () use ($publicId, $validUntil): PickupAuthorization {
            $authorization = $this->list->lockedForUpdate($publicId);

            if ($authorization->withdrawn_at !== null) {
                throw FamUnitErrors::conflict('status', 'fam_unit.pickup.withdrawn');
            }

            if ($authorization->kind !== PickupAuthorization::KIND_PERMANENT) {
                throw FamUnitErrors::invalid('valid_until', 'fam_unit.pickup.not_editable');
            }

            if ($validUntil !== null && $validUntil < $authorization->valid_from->toDateString()) {
                throw FamUnitErrors::invalid('valid_until', 'fam_unit.validation.date_range_invalid');
            }

            $authorization->valid_until = $validUntil === null ? null : Carbon::parse($validUntil);
            $authorization->save();

            return $authorization;
        });
    }

    /**
     * `RN-FAM-UNIT-87`: retirada inmediata e irreversible (se crea otra); no borra la fila. La segunda es
     * `409 pickup.invalid_transition`.
     */
    public function withdraw(string $publicId, User $actor): PickupAuthorization
    {
        return DB::transaction(function () use ($publicId, $actor): PickupAuthorization {
            $authorization = $this->list->lockedForUpdate($publicId);

            if ($authorization->withdrawn_at !== null) {
                throw FamUnitErrors::conflict('status', 'fam_unit.pickup.invalid_transition');
            }

            $authorization->withdrawn_at = now();
            $authorization->withdrawn_by = $actor->id;
            $authorization->save();

            return $authorization;
        });
    }

    /**
     * `RN-FAM-UNIT-85`: el tutor que autoriza es el principal de la unidad del alumno **y** tiene vínculo `activo` con
     * representación legal sobre él. Alumno sin unidad o unidad sin principal: el mismo error (nadie puede autorizar
     * terceros hasta que el centro designe principal).
     */
    private function principalGuardianId(string $guardianPublicId, int $studentPersonId): int
    {
        $guardian = $this->guardians->findOrNull($guardianPublicId);
        $unit = $this->students->unitOfStudent($studentPersonId);
        $link = $guardian === null ? null : $this->links->liveLink((int) $guardian->id, $studentPersonId);

        if ($guardian === null
            || $unit === null
            || $unit['principal_person_id'] === null
            || $unit['principal_person_id'] !== (int) $guardian->person_id
            || $link === null
            || $link->status !== 'activo'
            || ! (bool) $link->is_legal_guardian) {
            throw FamUnitErrors::invalid('authorized_by', 'fam_unit.pickup.principal_required');
        }

        return (int) $guardian->id;
    }

    /**
     * `RN-FAM-UNIT-85`, `-69`: sin texto publicado del tipo ⇒ `text_not_published` (fallo en cerrado, precedente
     * `RN-FAM-UNIT-68`); la versión indicada tiene que ser del tipo `autorizacion_recogida` y estar publicada o retirada
     * (la que firmó la familia en papel), nunca un borrador.
     */
    private function textVersion(string $publicId): TermsVersion
    {
        if ($this->terms->published(TermsVersion::KIND_PICKUP_AUTHORIZATION) === null) {
            throw FamUnitErrors::invalid('terms_version', 'fam_unit.pickup.text_not_published');
        }

        $version = $this->terms->findOrNull($publicId);

        if ($version === null
            || $version->kind !== TermsVersion::KIND_PICKUP_AUTHORIZATION
            || ! in_array($version->status, [TermsVersion::STATUS_PUBLISHED, TermsVersion::STATUS_RETIRED], true)) {
            throw FamUnitErrors::invalid('terms_version', 'fam_unit.pickup.text_version_invalid');
        }

        return $version;
    }
}
