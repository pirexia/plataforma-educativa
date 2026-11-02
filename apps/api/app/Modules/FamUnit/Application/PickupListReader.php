<?php

namespace App\Modules\FamUnit\Application;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\FamUnit\Domain\PickupEntry;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditRecorder;
use App\Support\Files\PrivateObjectStore;
use App\Support\Modules\ModuleAvailability;
use InvalidArgumentException;
use Throwable;

/**
 * REQ-FAM-UNIT/funcional.md §21.5.5, §21.8, `RN-FAM-UNIT-90` a `-93` (1.14b-a, `ADR-068 §5.4`/`§5.5`): la **lectura**
 * auditada de la lista de un alumno, con fotografía firmada, teléfono y documento enmascarado. La implementan igual la
 * interfaz pública (`EloquentAuthorizedPickupList`, para los consumidores) y `GET /pickup-lists/{student_person}`.
 *
 * Orden, para que «sin rastro no haya datos» (`ADR-068 §5.4`, fallo en cerrado): (1) límite de lecturas, (2) composición
 * de la lista, (3) nombres, contactos y URLs firmadas **en memoria**, (4) la fila `read` de auditoría y (5) solo entonces
 * se devuelve. Si la fila falla, la excepción sale y no se devuelve ni una URL.
 *
 * `$restricted` (ámbito `unidad_familiar`, `RN-FAM-UNIT-91`): la familia ve la lista sin el documento de los terceros y
 * **sin URLs de fotografía** (que la familia vea la foto de un tercero es `OPEN-FAM-UNIT-43`, aún abierta: fallo en
 * cerrado). Un consumidor de la interfaz nunca es `restricted` y nunca recibe el número de documento completo.
 */
final class PickupListReader
{
    public function __construct(
        private readonly PickupListService $list,
        private readonly PersonDirectory $people,
        private readonly PickupReadRateLimit $rateLimit,
        private readonly PrivateObjectStore $objects,
        private readonly AuditRecorder $audit,
        private readonly ModuleAvailability $modules,
    ) {}

    /**
     * @param  string  $purpose  `AuthorizedPickupList::PURPOSE_MANAGEMENT` o `PURPOSE_DOOR`; otra cosa es un error de programación
     * @return list<PickupEntry>
     */
    public function read(int $studentPersonId, string $purpose, bool $restricted = false): array
    {
        if (! in_array($purpose, [AuthorizedPickupList::PURPOSE_MANAGEMENT, AuthorizedPickupList::PURPOSE_DOOR], true)) {
            throw new InvalidArgumentException('Finalidad de lectura desconocida.');
        }

        // Fallo en cerrado (`funcional.md §21.8` punto 4): módulo no contratado o alumno desconocido ⇒ nada, sin auditar
        // (no se devuelve ningún dato personal).
        $student = $this->modules->isEnabled('fam_unit') ? ($this->people->refsByIds([$studentPersonId])[$studentPersonId] ?? null) : null;

        if ($student === null || $student->removed) {
            return [];
        }

        $this->rateLimit->hit(AuditActor::resolveUserId());

        $composed = $this->list->compose($studentPersonId);
        $personIds = array_values(array_unique(array_column($composed, 'person_id')));
        $refs = $this->people->refsByIds($personIds);
        $contacts = $this->people->contactDetailsByIds($personIds);
        $photos = $this->list->photosAmong($personIds);
        $ttl = max(1, (int) config('fam_unit.pickup.photo.signed_url_ttl_minutes', 5));
        $expiresAt = now()->addMinutes($ttl);

        // Agrupa las bases por persona respetando el orden de la composición (tutores primero).
        $grouped = [];

        foreach ($composed as $row) {
            $grouped[$row['person_id']][] = new PickupBasis($row['basis'], $row['reference'], $row['relationship'], $row['valid_from'], $row['valid_until']);
        }

        $entries = [];
        $signed = 0;

        foreach ($grouped as $personId => $bases) {
            $ref = $refs[$personId] ?? null;

            // Una persona borrada en `Core` deja de figurar (`funcional.md §21.9`).
            if ($ref === null || $ref->removed) {
                continue;
            }

            $contact = $contacts[$personId] ?? null;
            $isThirdParty = $bases[0]->basis !== PickupBasis::TUTOR;
            $hideDocument = $restricted && $isThirdParty;
            $url = null;
            $urlExpiresAt = null;

            // La fotografía se firma para quien la ve; la familia no la ve (`OPEN-FAM-UNIT-43`). Si no se puede firmar, la
            // entrada sale sin URL y el consumidor exige el documento (`OPEN-FAM-UNIT-47`).
            if (! $restricted && isset($photos[$personId])) {
                try {
                    $url = $this->objects->temporaryUrl((string) $photos[$personId]->object_key, $expiresAt);
                    $urlExpiresAt = $expiresAt->toIso8601ZuluString();
                    $signed++;
                } catch (Throwable) {
                    $url = null;
                    $urlExpiresAt = null;
                }
            }

            $entries[] = new PickupEntry(
                $personId,
                $ref->publicId,
                $ref->displayName,
                $bases,
                isset($photos[$personId]),
                $url,
                $urlExpiresAt,
                $contact?->contactPhone,
                $hideDocument ? null : $contact?->documentType,
                $hideDocument ? null : self::mask($contact?->documentNumber),
            );
        }

        usort($entries, static function (PickupEntry $a, PickupEntry $b): int {
            $rank = static fn (PickupEntry $e): int => $e->bases[0]->basis === PickupBasis::TUTOR ? 0 : 1;

            return [$rank($a), mb_strtolower($a->displayName), $a->personId] <=> [$rank($b), mb_strtolower($b->displayName), $b->personId];
        });

        // `ADR-068 §5.4`: la fila de lectura se escribe ANTES de devolver los datos. Sin nombres, documentos, teléfonos ni
        // claves de objeto: solo la finalidad y los recuentos.
        $this->audit->recordRead('person', $studentPersonId, $student->publicId, ['purpose' => $purpose, 'entries' => count($entries), 'photos' => $signed]);

        return $entries;
    }

    /** `RN-FAM-UNIT-90`: solo los últimos cuatro caracteres a la vista; un número de cuatro o menos, entero oculto. */
    public static function mask(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $length = mb_strlen($number);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4).mb_substr($number, -4);
    }
}
