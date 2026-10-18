<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.4 (`RN-ACAD-110` a `-115`), 1.12-a: marcos
 * horarios ordinarios de una etapa y sus franjas.
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
 * Las escrituras sobre un curso de solo lectura las rechaza el disparador
 * (`409`, `RN-ACAD-90`).
 */
final class TimetableFrameService
{
    /** `RN-ACAD-110`: en `1.12-a` solo `ordinaria`; `especial` llega con `1.12-b`. */
    public const TYPES = ['ordinaria', 'especial'];

    private const ORDINARY_UNIQUE = 'timetable_frames_tenant_level_ordinary_unique';

    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
        private readonly TimeSlotsValidator $slotsValidator,
        private readonly TimetableAnchor $anchor,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-90`). */
    public function find(string $publicId, User $actor): TimetableFrame
    {
        $frame = TimetableFrame::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($frame->academic_year_id, $actor);

        return $frame;
    }

    /**
     * @param  array{education_level: string, frame_type: string, name_translations: array<string, string>, slots: mixed}  $input
     */
    public function create(array $input, User $actor): TimetableFrame
    {
        return DB::transaction(function () use ($input, $actor): TimetableFrame {
            // `FOR SHARE`: el borrado concurrente de la etapa espera o pierde (RN-ACAD-96).
            $level = EducationLevel::query()->where('public_id', $input['education_level'])->sharedLock()->first();

            if ($level === null) {
                throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($level->academic_year_id, $actor, 'education_level');

            // RN-ACAD-110: en 1.12-a solo se admite `ordinaria`.
            if ($input['frame_type'] !== 'ordinaria') {
                throw StructureErrors::invalid('frame_type', 'acad.validation.frame_type_invalid');
            }

            if (TimetableFrame::query()->where('education_level_id', $level->id)->where('frame_type', 'ordinaria')->exists()) {
                throw StructureErrors::invalid('frame_type', 'acad.validation.frame_ordinary_exists');
            }

            $errors = new ValidationErrorBag;
            $slots = $this->slotsValidator->resolve($input['slots'], [], $errors);
            $errors->throwIfAny();

            $this->anchor->ensure($level->academic_year_id);

            try {
                $frame = DB::transaction(fn (): TimetableFrame => TimetableFrame::create([
                    'academic_year_id' => $level->academic_year_id,
                    'education_level_id' => $level->id,
                    'frame_type' => 'ordinaria',
                    'name_translations' => $input['name_translations'],
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

        return DB::transaction(function () use ($found, $input): TimetableFrame {
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

            $errors->throwIfAny();

            if ($names !== null) {
                $frame->fill(['name_translations' => $names])->save();
            }

            if ($slots !== null) {
                $this->syncSlots($frame, $stored->all(), $slots);
            }

            return $frame;
        });
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
