<?php

namespace App\Modules\FamUnit\Application;

use App\Modules\Core\Domain\PersonCorrection;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonNotCorrectableException;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\PersonRegistrar;
use App\Modules\Core\Domain\PersonRegistration;
use App\Support\Api\ApiException;

/**
 * REQ-FAM-UNIT/api.md §8.2, funcional.md §21.6 `RN-FAM-UNIT-89` (1.14b-a, `ADR-068 §5.2`): las personas de recogida (un
 * tutor o un tercero) vistas por `/pickup-persons`. Nunca escribe en `people`: crea o reutiliza por `PersonRegistrar`, que
 * **no modifica** a una persona existente, y corrige solo a una persona **sin usuario** por
 * `PersonRegistrar::correctUnaccounted` (auditado por `Core`). Lee por `PersonDirectory` (`INV-007`).
 *
 * **Alcance** (`api.md §8.2`, «no se convierten en un directorio de personas del centro»): una persona es alcanzable por
 * estos *endpoints* si es **tutor del centro** o **figura en alguna autorización viva**; cualquier otra persona de `Core`
 * es `404`. Una persona recién creada por `POST` y sin autorización todavía no lo es: el flujo es crearla, registrar su
 * autorización (no vigente hasta que tenga fotografía, `ineffective_reason = sin_foto`) y subir la fotografía.
 */
final class PickupPersonService
{
    public function __construct(
        private readonly PersonRegistrar $registrar,
        private readonly PersonDirectory $people,
        private readonly GuardianService $guardians,
        private readonly PickupListService $list,
    ) {}

    /**
     * Crea la persona o reutiliza la viva del centro con ese documento, **sin modificarla** (`RN-FAM-UNIT-89`). Una persona
     * reutilizada que estos *endpoints* no alcanzan (p. ej. un empleado) se devuelve sin datos: solo su identificador y los
     * indicadores `reused` y `data_mismatch`.
     */
    public function register(PersonRegistration $data): PickupPersonView
    {
        $outcome = $this->registrar->registerOrReuse($data);
        $person = $this->people->refsByIds([$outcome->personId])[$outcome->personId]
            ?? throw ApiException::notFound();

        if ($outcome->reused && ! $this->isReachable($outcome->personId)) {
            return new PickupPersonView($person, null, null, true, $outcome->dataMismatch, false);
        }

        return $this->view($person, $outcome->reused, $outcome->dataMismatch);
    }

    /** `404` si la persona no existe en el centro o no es alcanzable por estos *endpoints*. */
    public function find(string $publicId): PersonRef
    {
        $person = $this->people->findByPublicId($publicId);

        if ($person === null || ! $this->isReachable($person->id)) {
            throw ApiException::notFound();
        }

        return $person;
    }

    /** La persona alcanzable con ese documento; vacía si no hay o no es alcanzable. */
    public function findByDocument(string $documentType, string $documentNumber): ?PersonRef
    {
        $person = $this->people->findByDocument($documentType, $documentNumber);

        return $person !== null && $this->isReachable($person->id) ? $person : null;
    }

    /**
     * `OPEN-068-07`: corrige nombre, apellidos o teléfono. `404` si la persona no es alcanzable o **tiene usuario** (se
     * corrige por `/users`), sin revelar cuál de los dos.
     */
    public function correct(string $publicId, PersonCorrection $correction): PersonRef
    {
        $person = $this->find($publicId);

        try {
            $this->registrar->correctUnaccounted($person->id, $correction);
        } catch (PersonNotCorrectableException) {
            throw ApiException::notFound();
        }

        return $this->find($publicId);
    }

    public function isReachable(int $personId): bool
    {
        return $this->guardians->guardianIdForPerson($personId) !== null || $this->list->personHasLiveAuthorization($personId);
    }

    /** Teléfono, documento completo y fotografía de una persona, para `PickupPerson` (`api.md §8.1`). */
    public function view(PersonRef $person, ?bool $reused = null, ?bool $dataMismatch = null): PickupPersonView
    {
        return new PickupPersonView(
            $person,
            $this->people->contactDetailsByIds([$person->id])[$person->id] ?? null,
            $this->list->photosAmong([$person->id])[$person->id] ?? null,
            $reused,
            $dataMismatch,
        );
    }
}
