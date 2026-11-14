<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.4 (`RN-ACAD-110` a `-115`), 1.12-a y 1.12-b:
 * marcos horarios de una etapa (`ordinaria` y `especial`) y sus franjas.
 *
 * Las franjas solo se escriben dentro del marco (`RN-ACAD-113`): el alta y el
 * `PATCH` reciben el conjunto completo y se aplican en **una** transacción, por
 * instancia (`AR-15`). El `PATCH` toma `FOR UPDATE` del marco y de **todas sus
 * franjas** antes de comprobar si tienen sesiones (`RN-ACAD-114`): una sesión
 * concurrente toma `FOR SHARE` de su franja, así que o el cambio ve la sesión
 * (`409`) o la sesión ve la franja borrada (`422`), nunca una sesión viva en
 * una franja borrada. La detección de conflictos no vive aquí: como una franja
 * con sesiones no cambia de hora, solo la escritura de sesiones puede crear un
 * conflicto (`RN-ACAD-114`).
 *
 * **Marco especial** (`1.12-b`, `RN-ACAD-111`, `-125`): el alta y el cambio de
 * sus fechas leen las del curso con `AcademicYearSharedLock` (`FOR SHARE`, antes
 * de bloquear filas propias: orden curso → ancla, `RN-ACAD-54`), toman `FOR
 * UPDATE` del ancla `timetables` y comprueban que las fechas caen dentro del
 * curso, que no se solapan con otro especial vivo de la misma etapa y, si el
 * marco tiene sesiones, que el cambio no las pone en conflicto con las de
 * marcos que pasan a coexistir (`TimetableSessionService::assertFrameDatesFree`).
 * En el `PATCH` el ancla se toma **después** de `FOR UPDATE` del marco y sus
 * franjas: una sesión concurrente toma `FOR SHARE` de su franja **antes** que el
 * ancla, así que el orden contrario (ancla y luego franja) sería un interbloqueo.
 *
 * Las escrituras sobre un curso de solo lectura las rechaza el disparador
 * (`409`, `RN-ACAD-90`).
 */
final class TimetableFrameService
{
    /** `RN-ACAD-110`: vocabulario cerrado de `frame_type`. */
    public const TYPES = ['ordinaria', 'especial'];

    private const ORDINARY_UNIQUE = 'timetable_frames_tenant_level_ordinary_unique';

    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
        private readonly TimeSlotsValidator $slotsValidator,
        private readonly TimetableAnchor $anchor,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly TimetableSessionService $sessions,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-90`). */
    public function find(string $publicId, User $actor): TimetableFrame
    {
        $frame = TimetableFrame::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($frame->academic_year_id, $actor);

        return $frame;
    }

    /**
     * @param  array{education_level: string, frame_type: string, name_translations: array<string, string>, slots: mixed, valid_from?: ?string, valid_to?: ?string}  $input
     */
    public function create(array $input, User $actor): TimetableFrame
    {
        return DB::transaction(function () use ($input, $actor): TimetableFrame {
            $found = EducationLevel::query()->where('public_id', $input['education_level'])->first();

            if ($found === null) {
                throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($found->academic_year_id, $actor, 'education_level');

            $special = $input['frame_type'] === 'especial';

            // `RN-ACAD-111`: el curso, con FOR SHARE, antes de bloquear ninguna fila propia.
            $year = $special
                ? ($this->sharedLock->lockForShareById($found->academic_year_id) ?? throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found'))
                : null;

            // `FOR SHARE`: el borrado concurrente de la etapa espera o pierde (RN-ACAD-96).
            $level = EducationLevel::query()->whereKey($found->getKey())->sharedLock()->first();

            if ($level === null) {
                throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');
            }

            // RN-ACAD-110: un `ordinaria` vivo por etapa.
            if (! $special && TimetableFrame::query()->where('education_level_id', $level->id)->where('frame_type', 'ordinaria')->exists()) {
                throw StructureErrors::invalid('frame_type', 'acad.validation.frame_ordinary_exists');
            }

            $errors = new ValidationErrorBag;
            $slots = $this->slotsValidator->resolve($input['slots'], [], $errors);
            $errors->throwIfAny();

            $dates = ['valid_from' => null, 'valid_to' => null];

            if ($special) {
                $dates = ['valid_from' => (string) ($input['valid_from'] ?? ''), 'valid_to' => (string) ($input['valid_to'] ?? '')];

                // RN-ACAD-111: dentro del curso (fechas leídas bajo FOR SHARE), ancla FOR UPDATE, sin solape.
                $this->assertWithinYear($dates['valid_from'], $dates['valid_to'], $year ?? throw ApiException::notFound());
                $this->anchor->lock($level->academic_year_id);
                $this->assertNoOverlap($level->id, $dates['valid_from'], $dates['valid_to'], null);
            } else {
                $this->anchor->ensure($level->academic_year_id);
            }

            try {
                $frame = DB::transaction(fn (): TimetableFrame => TimetableFrame::create([
                    'academic_year_id' => $level->academic_year_id,
                    'education_level_id' => $level->id,
                    'frame_type' => $input['frame_type'],
                    'name_translations' => $input['name_translations'],
                    'valid_from' => $dates['valid_from'],
                    'valid_to' => $dates['valid_to'],
                ]));
            } catch (QueryException $e) {
                // Carrera entre dos altas del ordinario de la misma etapa (savepoint: la transacción sigue viva).
                if (($e->errorInfo[0] ?? null) === '23505' && str_contains((string) $e->getMessage(), self::ORDINARY_UNIQUE)) {
                    throw StructureErrors::invalid('frame_type', 'acad.validation.frame_ordinary_exists');
                }

                throw $e;
            }

            foreach ((array) $slots as $slot) {
                $this->createSlot($frame, $slot);
            }

            return $frame;
        });
    }

    /**
     * @param  array<string, mixed>  $input  solo las claves presentes en el `PATCH`; `slots` es el conjunto completo
     */
    public function update(string $publicId, array $input, User $actor): TimetableFrame
    {
        $found = $this->find($publicId, $actor);

        // `valid_from`/`valid_to` solo existen en un `especial` (RN-ACAD-111); `null` en un `ordinaria` no cambia nada.
        $datesInBody = array_key_exists('valid_from', $input) || array_key_exists('valid_to', $input);

        if ($datesInBody && $found->frame_type === 'ordinaria' && (($input['valid_from'] ?? null) !== null || ($input['valid_to'] ?? null) !== null)) {
            throw StructureErrors::invalid(($input['valid_from'] ?? null) !== null ? 'valid_from' : 'valid_to', 'acad.validation.frame_dates_invalid');
        }

        $changesDates = $datesInBody && $found->frame_type === 'especial';

        return DB::transaction(function () use ($found, $input, $changesDates): TimetableFrame {
            // RN-ACAD-111: el curso (inmutable, así que leerlo del marco sin bloqueo es seguro), con FOR SHARE, primero.
            $year = $changesDates
                ? ($this->sharedLock->lockForShareById($found->academic_year_id) ?? throw ApiException::notFound())
                : null;

            // FOR UPDATE del marco y de todas sus franjas; se relee por si otra escritura (o un borrado) confirmó antes.
            $frame = TimetableFrame::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
            $stored = TimeSlot::query()->where('timetable_frame_id', $frame->id)->lockForUpdate()->get()->keyBy('public_id');

            $errors = new ValidationErrorBag;
            $names = null;

            if (array_key_exists('name_translations', $input)) {
                $names = $this->translations->validateForPatch($input['name_translations'], $frame->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
            }

            $slots = array_key_exists('slots', $input)
                ? $this->slotsValidator->resolve($input['slots'], $stored->keys()->all(), $errors)
                : null;

            $dates = $changesDates ? $this->resolveDates($frame, $input, $errors) : null;

            $errors->throwIfAny();

            if ($dates !== null && $year !== null) {
                $this->changeDates($frame, $dates['valid_from'], $dates['valid_to'], $year);
            }

            if ($names !== null) {
                $frame->fill(['name_translations' => $names])->save();
            }

            if ($slots !== null) {
                $this->syncSlots($frame, $stored->all(), $slots);
            }

            return $frame;
        });
    }

    /**
     * `RN-ACAD-111`: las fechas resultantes de un `PATCH` (las del cuerpo, o las
     * actuales de la que falte). `null` en un `especial` no quita la vigencia:
     * `422 frame_dates_invalid`; `valid_to < valid_from`, también.
     *
     * @param  array<string, mixed>  $input
     * @return array{valid_from: string, valid_to: string}|null
     */
    private function resolveDates(TimetableFrame $frame, array $input, ValidationErrorBag $errors): ?array
    {
        foreach (['valid_from', 'valid_to'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === null) {
                $errors->add($field, 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');
            }
        }

        $from = array_key_exists('valid_from', $input) ? $input['valid_from'] : $frame->valid_from?->toDateString();
        $to = array_key_exists('valid_to', $input) ? $input['valid_to'] : $frame->valid_to?->toDateString();

        if (! is_string($from) || ! is_string($to)) {
            return null;
        }

        if ($to < $from) {
            $errors->add('valid_to', 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');

            return null;
        }

        return ['valid_from' => $from, 'valid_to' => $to];
    }

    /**
     * `RN-ACAD-111`, `-125`: dentro del curso, ancla `FOR UPDATE`, sin solape con
     * otro especial de la etapa y sin dejar en conflicto las sesiones del marco.
     * Sin cambio real de fechas no escribe ni comprueba nada más que el curso.
     */
    private function changeDates(TimetableFrame $frame, string $from, string $to, AcademicYearSummary $year): void
    {
        $this->assertWithinYear($from, $to, $year);

        if ($frame->valid_from?->toDateString() === $from && $frame->valid_to?->toDateString() === $to) {
            return;
        }

        $this->anchor->lock($frame->academic_year_id);
        $this->assertNoOverlap($frame->education_level_id, $from, $to, $frame->id);
        $this->sessions->assertFrameDatesFree($frame, $from, $to);

        $frame->fill(['valid_from' => $from, 'valid_to' => $to])->save();
    }

    /** `RN-ACAD-111`: la vigencia cae dentro del curso académico (fechas leídas bajo `FOR SHARE`). */
    private function assertWithinYear(string $from, string $to, AcademicYearSummary $year): void
    {
        $yearStart = $year->startsOn->toDateString();
        $yearEnd = $year->endsOn->toDateString();

        if ($from >= $yearStart && $to <= $yearEnd) {
            return;
        }

        $errors = new ValidationErrorBag;
        $params = ['academic_year_starts_on' => $yearStart, 'academic_year_ends_on' => $yearEnd];

        if ($from < $yearStart || $from > $yearEnd) {
            $errors->add('valid_from', 'acad.validation.frame_outside_academic_year', 'acad.validation.frame_outside_academic_year', $params);
        }

        if ($to > $yearEnd || $to < $yearStart) {
            $errors->add('valid_to', 'acad.validation.frame_outside_academic_year', 'acad.validation.frame_outside_academic_year', $params);
        }

        $errors->throwIfAny();
    }

    /** `RN-ACAD-111`: sin solape (intervalos cerrados) con otro `especial` vivo de la misma etapa. */
    private function assertNoOverlap(int $levelId, string $from, string $to, ?int $exceptFrameId): void
    {
        $overlapping = TimetableFrame::query()
            ->where('education_level_id', $levelId)
            ->where('frame_type', 'especial')
            ->when($exceptFrameId !== null, fn ($q) => $q->whereKeyNot($exceptFrameId))
            ->where('valid_from', '<=', $to)
            ->where('valid_to', '>=', $from)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->first();

        if ($overlapping !== null) {
            $errors = new ValidationErrorBag;
            $errors->add('valid_from', 'acad.validation.frame_overlap', 'acad.validation.frame_overlap', ['frame' => $overlapping->public_id]);
            $errors->throwIfAny();
        }
    }

    /** `RN-ACAD-114`: un marco con sesiones vivas no se borra; si no, se borran lógicamente sus franjas y el marco, por instancia. */
    public function delete(string $publicId, User $actor): void
    {
        $found = $this->find($publicId, $actor);

        DB::transaction(function () use ($found): void {
            $frame = TimetableFrame::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
            $slots = TimeSlot::query()->where('timetable_frame_id', $frame->id)->lockForUpdate()->get();

            $this->assertNoSessions($frame, $slots->modelKeys());

            foreach ($slots as $slot) {
                $slot->delete();
            }

            $frame->delete();
        });
    }

    /**
     * `RN-ACAD-113`: borrados, actualizaciones, altas. Una franja sin cambios no
     * escribe ni audita. `RN-ACAD-114`: borrar o cambiar de hora una franja con
     * sesiones vivas es `409 has_dependents`.
     *
     * @param  array<string, TimeSlot>  $stored  franjas vivas del marco por `public_id`
     * @param  list<array{public_id: ?string, weekday: int, starts_at: string, ends_at: string}>  $slots
     */
    private function syncSlots(TimetableFrame $frame, array $stored, array $slots): void
    {
        $kept = [];
        $touched = [];

        foreach ($slots as $slot) {
            if ($slot['public_id'] === null) {
                continue;
            }

            $kept[$slot['public_id']] = true;
            $existing = $stored[$slot['public_id']];

            if ($existing->weekday !== $slot['weekday'] || self::hhmm($existing->starts_at) !== $slot['starts_at'] || self::hhmm($existing->ends_at) !== $slot['ends_at']) {
                $touched[] = $existing->getKey();
            }
        }

        foreach ($stored as $publicId => $existing) {
            if (! isset($kept[$publicId])) {
                $touched[] = $existing->getKey();
            }
        }

        $this->assertNoSessions($frame, $touched);

        foreach ($stored as $publicId => $existing) {
            if (! isset($kept[$publicId])) {
                $existing->delete();
            }
        }

        foreach ($slots as $slot) {
            if ($slot['public_id'] !== null) {
                $existing = $stored[$slot['public_id']];

                if ($existing->weekday !== $slot['weekday'] || self::hhmm($existing->starts_at) !== $slot['starts_at'] || self::hhmm($existing->ends_at) !== $slot['ends_at']) {
                    $existing->fill(['weekday' => $slot['weekday'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']])->save();
                }
            }
        }

        foreach ($slots as $slot) {
            if ($slot['public_id'] === null) {
                $this->createSlot($frame, $slot);
            }
        }
    }

    /**
     * @param  array{weekday: int, starts_at: string, ends_at: string}  $slot
     */
    private function createSlot(TimetableFrame $frame, array $slot): void
    {
        TimeSlot::create([
            'academic_year_id' => $frame->academic_year_id,
            'timetable_frame_id' => $frame->id,
            'weekday' => $slot['weekday'],
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
        ]);
    }

    /**
     * @param  list<int|string>  $slotIds
     */
    private function assertNoSessions(TimetableFrame $frame, array $slotIds): void
    {
        if ($slotIds !== [] && TimetableSession::query()->whereIn('time_slot_id', $slotIds)->exists()) {
            throw StructureErrors::hasDependents('timetable_frame', (string) $frame->public_id, ['timetable_sessions']);
        }
    }

    /** `HH:MM:SS` de PostgreSQL → `HH:MM`. */
    public static function hhmm(string $time): string
    {
        return substr($time, 0, 5);
    }
}
