<?php

namespace App\Modules\Prl\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\Prl\Domain\Models\PickupAlert;
use App\Support\Api\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRL/funcional.md §21.5.5, `RN-PRL-08` a `-10`, api.md §8.4 (1.14b-b, `REQ-PRL-004`): alta, consulta y resolución de las
 * alertas por persona no autorizada. **Toda la validación de negocio es de servidor** (`INV-010`). La auditoría la hace el
 * ciclo de vida del modelo (`INV-003`, `Selective`).
 *
 * Una alerta se **abre** con el alumno y la descripción de quien se presenta, y **no envía nada** automáticamente
 * (`OPEN-FAM-UNIT-33`, hasta `1.19`). Se **cierra una sola vez**: la resolución (vocabulario cerrado, `RN-PRL-08`) la fija y
 * después cualquier `PATCH` es `409 prl.alert.closed` (`CA-PRL-008`); no se reabre ni se borra (es evidencia). La llamada al
 * tutor principal se registra con `principal_contacted_at`, mientras está abierta o al cerrarla. El cierre lo hace quien
 * tenga `alerta_entrega.actualizar` (dirección, por defecto). Idempotencia (`RN-PRL-09`) como los movimientos.
 */
final class PickupAlertService
{
    private const IDEMPOTENCY_INDEX = 'pickup_alerts_idempotency_unique';

    public function __construct(
        private readonly PersonDirectory $people,
        private readonly TenantSettingsReader $settings,
        private readonly LockedTransaction $transaction,
    ) {}

    /**
     * @param  array{student_person: string, kind: string, presenter_description: string}  $input
     * @return array{alert: PickupAlert, replayed: bool}
     */
    public function raise(array $input, User $actor, ?string $idempotencyKey): array
    {
        $key = $idempotencyKey === null ? null : new IdempotencyKey($idempotencyKey, IdempotencyKey::hashOf($input));

        if ($key !== null && ($existing = $this->existing($actor, $key)) !== null) {
            return ['alert' => $existing, 'replayed' => true];
        }

        try {
            $alert = $this->transaction->run(function () use ($input, $actor, $key): PickupAlert {
                // El alumno de la alerta, con bloqueo compartido (`RN-PRL-12`, `OPEN-FAM-UNIT-51`): `404` si no existe, es de otro
                // centro o está borrado, también si lo borran concurrentemente (`api.md §8.4`).
                $student = $this->people->lockLiveByPublicIds([$input['student_person']])[$input['student_person']] ?? null;

                if ($student === null) {
                    throw ApiException::notFound();
                }

                $alert = new PickupAlert;
                $alert->student_person_id = $student->id;
                $alert->kind = $input['kind'];
                $alert->presenter_description = $input['presenter_description'];
                $alert->status = PickupAlert::STATUS_OPEN;
                $alert->raised_by = $actor->id;
                $alert->raised_at = now();
                $alert->idempotency_key = $key?->key;
                $alert->idempotency_body_hash = $key?->bodyHash;
                $alert->save();

                return $alert;
            });
        } catch (QueryException $e) {
            if ($key !== null && ($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                $existing = $this->existing($actor, $key);

                if ($existing !== null) {
                    return ['alert' => $existing, 'replayed' => true];
                }
            }

            throw $e;
        }

        return ['alert' => $alert, 'replayed' => false];
    }

    /**
     * Registra la llamada al principal y/o cierra la alerta. `409 prl.alert.closed` si ya está cerrada (no reabrible).
     *
     * @param  array{principal_contacted_at?: ?CarbonImmutable, resolution?: string, resolution_notes?: ?string}  $changes
     */
    public function update(string $publicId, array $changes, User $actor): PickupAlert
    {
        return DB::transaction(function () use ($publicId, $changes, $actor): PickupAlert {
            $alert = PickupAlert::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($alert->status === PickupAlert::STATUS_CLOSED) {
                throw PrlErrors::conflict('status', 'prl.alert.closed');
            }

            $now = now();

            if (array_key_exists('principal_contacted_at', $changes)) {
                $contacted = $changes['principal_contacted_at'];

                if ($contacted !== null && ($contacted->greaterThan($now) || $contacted->lessThan($alert->raised_at))) {
                    throw PrlErrors::invalid('principal_contacted_at', 'prl.validation.contact_time_out_of_range');
                }

                $alert->principal_contacted_at = $contacted === null ? null : Carbon::instance($contacted);
            }

            if (array_key_exists('resolution', $changes)) {
                $alert->status = PickupAlert::STATUS_CLOSED;
                $alert->resolution = $changes['resolution'];
                $alert->resolution_notes = $changes['resolution_notes'] ?? null;
                $alert->resolved_by = $actor->id;
                $alert->resolved_at = $now;
            }

            $alert->save();

            return $alert;
        });
    }

    /** `404` si no existe o es de otro centro. */
    public function find(string $publicId): PickupAlert
    {
        return PickupAlert::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @param  array{status: ?string, date: ?string, student_person: ?string}  $filters
     * @return LengthAwarePaginator<int, PickupAlert>
     */
    public function listing(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = PickupAlert::query();

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['student_person'] !== null) {
            // Un alumno desconocido o de otro centro no coincide con nada.
            $query->where('student_person_id', $this->people->findByPublicId($filters['student_person'])->id ?? 0);
        }

        if ($filters['date'] !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $filters['date'], $this->settings->timezone())->startOfDay();
            $query->where(fn (Builder $q) => $q->where('raised_at', '>=', $start->copy()->utc())->where('raised_at', '<', $start->copy()->addDay()->utc()));
        }

        return $query->orderByDesc('raised_at')->orderByDesc('id')->paginate($perPage);
    }

    private function existing(User $actor, IdempotencyKey $key): ?PickupAlert
    {
        $alert = PickupAlert::query()->where('raised_by', $actor->id)->where('idempotency_key', $key->key)->first();

        if ($alert === null) {
            return null;
        }

        if ($alert->idempotency_body_hash !== $key->bodyHash) {
            throw PrlErrors::conflict('idempotency_key', 'prl.idempotency_mismatch');
        }

        return $alert;
    }
}
