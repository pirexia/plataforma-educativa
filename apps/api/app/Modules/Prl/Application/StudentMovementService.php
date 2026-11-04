<?php

namespace App\Modules\Prl\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * REQ-PRL/funcional.md §21.5.5, §21.5.6, §21.7 (`RN-PRL-01` a `-11`), api.md §8.4 (1.14b-b, `REQ-PRL-004`, `ADR-068`): registro
 * y consulta de los movimientos de alumnado en la puerta. **Toda la validación de negocio es de servidor** (`INV-010`); la
 * forma del cuerpo la valida la petición. La auditoría la hace el ciclo de vida del modelo (`INV-003`, `Selective`).
 *
 * **`prl` solo consume la lista** (`RN-PRL-01`): quién puede recoger lo decide `AuthorizedPickupList` (`INV-007`); aquí solo
 * se guarda el hecho, con la base y la referencia de evidencia que la interfaz dijo. Una salida con persona que recoge
 * **recomprueba la autorización dentro de la transacción de escritura** (`RN-PRL-02`, `CA-PRL-003`): aunque la persona
 * figurara al abrir la lista, si ahora no figura (retirada, revocada, caducada) ⇒ `422 prl.movement.person_not_authorized` y
 * no se escribe nada. «No figura», «excluida por revocación» y «la persona no existe» responden **con el mismo cuerpo**
 * (`OPEN-FAM-UNIT-34`, `CA-PRL-002`). No hay forma de registrar una salida con una persona fuera de la lista (`RN-PRL-03`).
 *
 * Un tutor sin fotografía figura pero solo se verifica por documento (`RN-PRL-06`). Los movimientos son de solo anexar
 * (`RN-PRL-04`): una corrección es un movimiento nuevo que referencia al anterior, del mismo alumno, con motivo.
 *
 * Idempotencia (`RN-PRL-09`): la misma `Idempotency-Key` del mismo usuario con el mismo cuerpo devuelve el movimiento original
 * sin duplicar (aunque la persona haya dejado de figurar entre medias: es el resultado original); con otro cuerpo,
 * `409 prl.idempotency_mismatch`. Dos peticiones simultáneas con la misma clave las cierra el índice único, nunca un `500`.
 */
final class StudentMovementService
{
    private const IDEMPOTENCY_INDEX = 'student_movements_idempotency_unique';

    public function __construct(
        private readonly AuthorizedPickupList $pickupList,
        private readonly PersonDirectory $people,
        private readonly UserDirectory $users,
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenant,
        private readonly MovementCursor $cursor,
        private readonly LockedTransaction $transaction,
    ) {}

    /**
     * @param  array{student_person: string, direction: string, picked_up_by: ?string, verification_method: ?string, delivered_by: ?string, off_schedule: bool, off_schedule_reason: ?string, notes: ?string, corrects: ?string, correction_reason: ?string}  $input
     * @return array{movement: StudentMovement, replayed: bool}
     */
    public function record(array $input, User $actor, ?string $idempotencyKey): array
    {
        $key = $idempotencyKey === null ? null : new IdempotencyKey($idempotencyKey, IdempotencyKey::hashOf($input));

        if ($key !== null && ($existing = $this->existing($actor, $key)) !== null) {
            return ['movement' => $existing, 'replayed' => true];
        }

        try {
            $movement = $this->transaction->run(fn (): StudentMovement => $this->write($input, $actor, $key));
        } catch (QueryException $e) {
            // Dos peticiones simultáneas con la misma clave: pierde la que insertó segunda y relee la ganadora.
            if ($key !== null && ($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                $existing = $this->existing($actor, $key);

                if ($existing !== null) {
                    return ['movement' => $existing, 'replayed' => true];
                }
            }

            throw $e;
        }

        return ['movement' => $movement, 'replayed' => false];
    }

    /**
     * @param  array{student_person: string, direction: string, picked_up_by: ?string, verification_method: ?string, delivered_by: ?string, off_schedule: bool, off_schedule_reason: ?string, notes: ?string, corrects: ?string, correction_reason: ?string}  $input
     */
    private function write(array $input, User $actor, ?IdempotencyKey $key): StudentMovement
    {
        // `RN-PRL-12`: bloqueo compartido de las personas (alumno y, en una salida, quien recoge) en una consulta y por id ascendente,
        // antes de decidir nada; después, en `attachPickup`, las filas de `fam_unit`. Un borrado lógico concurrente espera o se ve.
        $locked = $this->people->lockLiveByPublicIds(array_values(array_filter([$input['student_person'], $input['picked_up_by']])));
        $student = $locked[$input['student_person']] ?? null;

        if ($student === null) {
            throw PrlErrors::invalid('student_person', 'prl.validation.student_not_found');
        }

        $corrected = null;

        if ($input['corrects'] !== null) {
            $corrected = StudentMovement::query()->where('public_id', $input['corrects'])->where('student_person_id', $student->id)->first()
                ?? throw PrlErrors::invalid('corrects', 'prl.validation.reference_not_found');
        }

        $deliveredBy = $actor->id;

        if ($input['delivered_by'] !== null) {
            $user = $this->users->findActiveByPublicId($input['delivered_by']);

            if ($user === null || $user->removed) {
                throw PrlErrors::invalid('delivered_by', 'prl.validation.user_not_found');
            }

            $deliveredBy = $user->id;
        }

        $movement = new StudentMovement;
        $movement->student_person_id = $student->id;
        $movement->direction = $input['direction'];
        $movement->off_schedule = $input['off_schedule'];
        $movement->off_schedule_reason = $input['off_schedule_reason'];
        $movement->notes = $input['notes'];
        $movement->delivered_by = $deliveredBy;
        $movement->recorded_by = $actor->id;
        $movement->corrects_movement_id = $corrected?->id;
        $movement->correction_reason = $input['correction_reason'];
        $movement->idempotency_key = $key?->key;
        $movement->idempotency_body_hash = $key?->bodyHash;
        $movement->occurred_at = now();

        if ($input['picked_up_by'] !== null) {
            $this->attachPickup($movement, $locked[$input['picked_up_by']] ?? null, $input['verification_method'], $student->id);
        }

        $movement->save();

        return $movement;
    }

    /**
     * `RN-PRL-02`, `RN-PRL-05`, `RN-PRL-06`, `RN-PRL-12`: recomprueba en esta transacción, con bloqueo, que la persona figura y fija
     * la base, la referencia y el método de verificación. `$person` es la persona **viva ya bloqueada**, o `null` si no existe.
     */
    private function attachPickup(StudentMovement $movement, ?PersonRef $person, ?string $method, int $studentId): void
    {
        // Una persona que no existe, de otro centro o borrada: el mismo cuerpo que «no figura» (`OPEN-FAM-UNIT-34`) y el mismo
        // camino (la misma llamada bloqueante de la interfaz, con el id `0` que nunca existe) para no distinguirla por el tiempo
        // de respuesta (`RN-PRL-13`). `grantForLocked` bloquea las filas de `fam_unit` que sostienen la decisión (`RN-PRL-12`, `-15`).
        $grant = $this->pickupList->grantForLocked($person === null ? 0 : $person->id, $studentId);

        if ($person === null || $grant === null) {
            throw PrlErrors::personNotAuthorized();
        }

        if (! $grant->hasPhoto && $method !== StudentMovement::VERIFICATION_DOCUMENT) {
            throw PrlErrors::invalid('verification_method', 'prl.movement.verification_requires_document');
        }

        $basis = $grant->primaryBasis();

        if ($basis->referenceId === null) {
            // La interfaz siempre informa la referencia; sin ella no hay evidencia que guardar: fallo en cerrado.
            throw PrlErrors::personNotAuthorized();
        }

        $movement->picked_up_by_person_id = $person->id;
        $movement->basis = $basis->basis;
        $movement->verification_method = $method;

        if ($basis->basis === PickupBasis::TUTOR) {
            $movement->guardian_student_link_id = $basis->referenceId;
        } else {
            $movement->pickup_authorization_id = $basis->referenceId;
        }
    }

    private function existing(User $actor, IdempotencyKey $key): ?StudentMovement
    {
        $movement = StudentMovement::query()->where('recorded_by', $actor->id)->where('idempotency_key', $key->key)->first();

        if ($movement === null) {
            return null;
        }

        if ($movement->idempotency_body_hash !== $key->bodyHash) {
            throw PrlErrors::conflict('idempotency_key', 'prl.idempotency_mismatch');
        }

        return $movement;
    }

    // ─── Consulta ───

    /** `404` si no existe o es de otro centro. */
    public function find(string $publicId): StudentMovement
    {
        return StudentMovement::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Movimientos por cursor `(occurred_at DESC, id DESC)` (`ADR-038 §4.4`).
     *
     * @param  array{student_person: ?string, date: ?string, direction: ?string, off_schedule: ?bool}  $filters
     * @return array{rows: list<StudentMovement>, next_cursor: ?string, has_more: bool}
     */
    public function history(array $filters, ?string $cursor, int $limit): array
    {
        $fingerprint = $this->cursor->fingerprint($filters);
        $query = StudentMovement::query();

        if ($filters['student_person'] !== null) {
            // Un alumno desconocido o de otro centro no coincide con nada.
            $student = $this->people->findByPublicId($filters['student_person']);
            $query->where('student_person_id', $student->id ?? 0);
        }

        if ($filters['date'] !== null) {
            $zone = $this->settings->timezone();
            $start = Carbon::createFromFormat('Y-m-d', $filters['date'], $zone)->startOfDay();
            $query->where('occurred_at', '>=', $start->copy()->utc())->where('occurred_at', '<', $start->copy()->addDay()->utc());
        }

        if ($filters['direction'] !== null) {
            $query->where('direction', $filters['direction']);
        }

        if ($filters['off_schedule'] !== null) {
            $query->where('off_schedule', $filters['off_schedule']);
        }

        if ($cursor !== null) {
            [$occurredAt, $id] = $this->cursor->decode($cursor, $fingerprint, $this->tenant->tenantId());

            $query->where(function (Builder $q) use ($occurredAt, $id): void {
                $q->where('occurred_at', '<', $occurredAt)
                    ->orWhere(fn (Builder $same) => $same->where('occurred_at', '=', $occurredAt)->where('id', '<', $id));
            });
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'rows' => $page->all(),
            'next_cursor' => $hasMore && $last instanceof StudentMovement
                ? $this->cursor->encode($last->occurred_at->toIso8601String(), (int) $last->id, $fingerprint, $this->tenant->tenantId())
                : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Para la representación: el `public_id` de los movimientos que corrigen otros.
     *
     * @param  list<StudentMovement>  $rows
     * @return array<int, string> id interno => `public_id` del movimiento corregido
     */
    public function correctedPublicIds(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (StudentMovement $m): ?int => $m->corrects_movement_id === null ? null : (int) $m->corrects_movement_id, $rows))));

        if ($ids === []) {
            return [];
        }

        return StudentMovement::query()->whereIn('id', $ids)->pluck('public_id', 'id')->all();
    }
}
