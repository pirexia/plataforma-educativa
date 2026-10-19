<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Domain\Models\FacilityBooking;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.6 (`RN-ACAD-140` a `-143`), 1.12-b: reservas de
 * espacios (aulas y recursos) en una fecha y un intervalo `[inicio, fin)`.
 *
 * **Único servicio que consulta y escribe `FacilityBooking`** (`AR-10`: el
 * recurso `reservas` tiene ámbito `propios` en `eliminar`, `OPEN-ACAD-42`, así
 * que toda consulta del modelo pasa por aquí y por `ScopedQuery`).
 *
 * Alta, en una transacción y en el orden de `datos.md §9.5`: (1) el curso con
 * `AcademicYearSharedLock` (`FOR SHARE`, fechas leídas bajo el bloqueo,
 * `RN-ACAD-140`); (2) `FOR SHARE` del espacio (su borrado concurrente espera o
 * pierde, `RN-ACAD-102`); (3) `FOR UPDATE` del ancla `timetables`
 * (`RN-ACAD-125`); (4) conflictos y escritura. Nunca `FOR UPDATE` sobre la fila
 * del curso.
 *
 * Conflicto (`RN-ACAD-141`): otra reserva viva del mismo espacio, fecha e
 * intervalo que se corta, y, si es un aula, una sesión viva en ella con el
 * `weekday` de la fecha, intervalo que se corta y marco vigente ese día
 * (`FrameCoexistence::constrainInForceOn`). Una sesión creada o movida después
 * de una reserva no se valida contra reservas (`RN-ACAD-143`).
 */
final class FacilityBookingService
{
    public function __construct(
        private readonly StructureYears $years,
        private readonly TimetableAnchor $anchor,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly ScopedQuery $scopedQuery,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-90`). */
    public function find(string $publicId, User $actor): FacilityBooking
    {
        $booking = FacilityBooking::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($booking->academic_year_id, $actor);

        return $booking;
    }

    /**
     * Consulta de un listado: reservas del curso, con los filtros ya resueltos
     * (espacios por id interno, fechas `AAAA-MM-DD`).
     *
     * @param  list<int>|null  $facilityIds  `null` = sin filtro; `[]` = ningún espacio coincide (colección vacía)
     * @return Builder<FacilityBooking>
     */
    public function listing(int $academicYearId, ?array $facilityIds, ?string $bookedFrom, ?string $bookedTo): Builder
    {
        return FacilityBooking::query()
            ->with('facility')
            ->where('academic_year_id', $academicYearId)
            ->when($facilityIds !== null, fn (Builder $q) => $q->whereIn('facility_id', $facilityIds ?? []))
            ->when($bookedFrom !== null, fn (Builder $q) => $q->where('booked_on', '>=', $bookedFrom))
            ->when($bookedTo !== null, fn (Builder $q) => $q->where('booked_on', '<=', $bookedTo))
            ->orderBy('booked_on')
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    /**
     * `RN-ACAD-102`: ids de los cursos académicos con reservas vivas del espacio.
     *
     * @return list<int>
     */
    public function yearIdsFor(int $facilityId): array
    {
        return array_map('intval', FacilityBooking::query()->where('facility_id', $facilityId)->distinct()->pluck('academic_year_id')->all());
    }

    /**
     * @param  array{academic_year: string, facility: string, booked_on: string, starts_at: string, ends_at: string}  $input
     */
    public function create(array $input, User $actor): FacilityBooking
    {
        $year = StructureFields::isSafeText($input['academic_year']) ? $this->years->fromBody($input['academic_year'], $actor) : null;

        if ($year === null) {
            throw StructureErrors::invalid('academic_year', 'acad.validation.academic_year_not_found');
        }

        return DB::transaction(function () use ($input, $year, $actor): FacilityBooking {
            // (1) El curso, con FOR SHARE, antes de bloquear ninguna fila propia.
            $locked = $this->sharedLock->lockForShareById($year->id)
                ?? throw StructureErrors::invalid('academic_year', 'acad.validation.academic_year_not_found');

            $yearStart = $locked->startsOn->toDateString();
            $yearEnd = $locked->endsOn->toDateString();

            if ($input['booked_on'] < $yearStart || $input['booked_on'] > $yearEnd) {
                throw StructureErrors::invalidWith('booked_on', 'acad.validation.booking_outside_academic_year', [
                    'academic_year_starts_on' => $yearStart,
                    'academic_year_ends_on' => $yearEnd,
                ]);
            }

            // (2) El espacio (aula o recurso), vivo, con FOR SHARE.
            $facility = Facility::query()->where('public_id', $input['facility'])->sharedLock()->first();

            if ($facility === null) {
                throw StructureErrors::invalid('facility', 'acad.validation.facility_not_found');
            }

            // (3) El ancla del horario y (4) los conflictos.
            $this->anchor->lock($locked->id);
            $this->assertNoConflict($locked->id, $facility, $input['booked_on'], $input['starts_at'], $input['ends_at'], $actor);

            return FacilityBooking::create([
                'academic_year_id' => $locked->id,
                'facility_id' => $facility->id,
                'booked_on' => $input['booked_on'],
                'starts_at' => $input['starts_at'],
                'ends_at' => $input['ends_at'],
            ]);
        });
    }

    /**
     * `RN-ACAD-142`: cancelar. Con ámbito restringido (`propios`) solo las
     * reservas que el resolutor acepta; una ajena es `404`, igual que una
     * inexistente (`RN-PERM-14`). Una reserva se borra y se vuelve a crear, no se edita.
     */
    public function delete(string $publicId, User $actor, PermissionDecision $decision): void
    {
        $found = $this->find($publicId, $actor);

        // Falla en cerrado: la decisión es obligatoria (la deja `RequirePermission`).
        if (! $this->scopedQuery->satisfies($found, 'reservas', $decision, $actor)) {
            throw ApiException::notFound();
        }

        DB::transaction(function () use ($found): void {
            FacilityBooking::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * `RN-ACAD-141`: otra reserva del espacio que se corta y, si es un aula,
     * sesiones del marco vigente ese día que se cortan. Lista ordenada: primero
     * las reservas (por hora e id), luego las sesiones (por hora e id). Cada
     * conflicto lleva su identificador solo si el solicitante puede leer ese
     * recurso (`reservas.leer` / `horarios.leer`); si no, solo `kind`.
     */
    private function assertNoConflict(int $academicYearId, Facility $facility, string $date, string $startsAt, string $endsAt, User $actor): void
    {
        $conflicts = [];

        $bookings = FacilityBooking::query()
            ->where('facility_id', $facility->id)
            ->where('booked_on', $date)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->pluck('public_id');

        foreach ($bookings as $publicId) {
            $conflicts[] = ['kind' => 'booking', 'booking' => (string) $publicId];
        }

        if ($facility->facility_type === 'aula') {
            $query = TimetableSession::query()
                ->select('timetable_sessions.public_id')
                ->join('time_slots as ts', 'ts.id', '=', 'timetable_sessions.time_slot_id')
                ->join('timetable_frames as f', 'f.id', '=', 'ts.timetable_frame_id')
                ->whereColumn('ts.tenant_id', 'timetable_sessions.tenant_id')
                ->whereNull('ts.deleted_at')
                ->whereColumn('f.tenant_id', 'timetable_sessions.tenant_id')
                ->whereNull('f.deleted_at')
                ->where('timetable_sessions.academic_year_id', $academicYearId)
                ->where('timetable_sessions.facility_id', $facility->id)
                ->where('ts.weekday', CarbonImmutable::parse($date)->dayOfWeekIso)
                ->where('ts.starts_at', '<', $endsAt)
                ->where('ts.ends_at', '>', $startsAt);

            FrameCoexistence::constrainInForceOn($query, $date);

            foreach ($query->orderBy('ts.starts_at')->orderBy('timetable_sessions.id')->pluck('timetable_sessions.public_id') as $publicId) {
                $conflicts[] = ['kind' => 'session', 'session' => (string) $publicId];
            }
        }

        if ($conflicts !== []) {
            // Sin el permiso de lectura del otro recurso no se revelan sus identificadores: solo el tipo.
            $canReadBookings = $this->permissions->decide($actor, 'reservas.leer')->permitted;
            $canReadSessions = $this->permissions->decide($actor, 'horarios.leer')->permitted;
            $conflicts = array_map(static function (array $conflict) use ($canReadBookings, $canReadSessions): array {
                $allowed = $conflict['kind'] === 'booking' ? $canReadBookings : $canReadSessions;

                return $allowed ? $conflict : ['kind' => $conflict['kind']];
            }, $conflicts);

            $code = 'acad.validation.booking_conflict';

            throw ApiException::validation(['booked_on' => [[
                'code' => $code,
                'message' => __($code),
                'params' => ['conflicts' => $conflicts],
            ]]]);
        }
    }
}
